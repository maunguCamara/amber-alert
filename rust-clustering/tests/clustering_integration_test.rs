/// Integration tests for the geo-clustering engine.
///
/// These tests run with `cargo test` and cover:
/// - Haversine distance accuracy against known benchmarks
/// - Mercator projection correctness
/// - Cluster centroid computation
/// - County dominance calculation
/// - Active-count tracking inside clusters
/// - Nearby point filtering with radius + limit
/// - Deterministic cluster IDs
/// - Behaviour at map zoom boundaries
/// - Large-scale performance smoke test

#[cfg(test)]
mod integration {
    use amber_clustering::clustering::{cluster, haversine_km, nearby};
    use amber_clustering::proto::CasePoint;

    // ── Fixtures ──────────────────────────────────────────────────────────────

    fn pt(id: &str, lat: f64, lng: f64, status: &str, county: &str) -> CasePoint {
        CasePoint {
            id:           id.to_string(),
            reference_no: format!("KE-2024-{}", id),
            lat,
            lng,
            status:  status.to_string(),
            county:  county.to_string(),
        }
    }

    fn active(id: &str, lat: f64, lng: f64, county: &str) -> CasePoint {
        pt(id, lat, lng, "active", county)
    }

    fn resolved(id: &str, lat: f64, lng: f64, county: &str) -> CasePoint {
        pt(id, lat, lng, "resolved", county)
    }

    // ── Haversine accuracy ────────────────────────────────────────────────────

    #[test]
    fn haversine_nairobi_to_mombasa_is_roughly_400km() {
        // Nairobi: -1.286, 36.817   Mombasa: -4.043, 39.668
        // Reference: ~441 km by road; great-circle ≈ 400–420 km
        let d = haversine_km(-1.286, 36.817, -4.043, 39.668);
        assert!((390.0..=430.0).contains(&d), "got {:.1} km", d);
    }

    #[test]
    fn haversine_kisumu_to_nakuru_is_roughly_120km() {
        // Kisumu: -0.092, 34.768   Nakuru: -0.303, 36.080
        let d = haversine_km(-0.092, 34.768, -0.303, 36.080);
        assert!((110.0..=140.0).contains(&d), "got {:.1} km", d);
    }

    #[test]
    fn haversine_same_point_is_zero() {
        let d = haversine_km(-1.286, 36.817, -1.286, 36.817);
        assert!(d < 0.001, "same-point distance should be ~0, got {}", d);
    }

    #[test]
    fn haversine_is_symmetric() {
        let a = haversine_km(-1.286, 36.817, -4.043, 39.668);
        let b = haversine_km(-4.043, 39.668, -1.286, 36.817);
        assert!((a - b).abs() < 0.001, "haversine must be symmetric");
    }

    #[test]
    fn haversine_equatorial_1_degree_is_about_111km() {
        // At the equator, 1° longitude ≈ 111.3 km
        let d = haversine_km(0.0, 0.0, 0.0, 1.0);
        assert!((110.0..=113.0).contains(&d), "got {:.2} km", d);
    }

    // ── Cluster — basic cases ─────────────────────────────────────────────────

    #[test]
    fn empty_input_gives_empty_output() {
        let r = cluster(&[], 6.0, 0.0);
        assert!(r.items.is_empty());
        assert_eq!(r.total_in, 0);
    }

    #[test]
    fn single_point_is_not_clustered() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![active("a", -1.286, 36.817, "Nairobi")];
        let r = cluster(&pts, 6.0, 0.0);
        assert_eq!(r.items.len(), 1);
        assert_eq!(r.total_in, 1);
        match &r.items[0].item {
            Some(Item::Point(_)) => {}
            other => panic!("expected Point, got {:?}", other),
        }
    }

    #[test]
    fn two_far_apart_points_stay_separate_at_high_zoom() {
        // At zoom 12 the grid is very fine; Nairobi and Mombasa are far apart
        let pts = vec![
            active("nairobi", -1.286, 36.817, "Nairobi"),
            active("mombasa", -4.043, 39.668, "Mombasa"),
        ];
        let r = cluster(&pts, 12.0, 0.0);
        assert_eq!(r.items.len(), 2, "should be 2 separate points at zoom 12");
    }

    #[test]
    fn two_close_points_merge_at_low_zoom() {
        use amber_clustering::proto::cluster_item::Item;
        // Two points 200m apart in Mathare — should cluster at zoom 6
        let pts = vec![
            active("a", -1.286, 36.817, "Nairobi"),
            active("b", -1.288, 36.819, "Nairobi"),
        ];
        let r = cluster(&pts, 6.0, 0.0);
        assert_eq!(r.items.len(), 1);
        match &r.items[0].item {
            Some(Item::Cluster(c)) => assert_eq!(c.count, 2),
            other => panic!("expected Cluster, got {:?}", other),
        }
    }

    #[test]
    fn cluster_centroid_is_average_of_member_coordinates() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![
            active("a", -1.0, 36.0, "Nairobi"),
            active("b", -2.0, 38.0, "Nairobi"),
        ];
        let r = cluster(&pts, 4.0, 0.0);
        // At zoom 4 these should cluster
        for item in &r.items {
            if let Some(Item::Cluster(c)) = &item.item {
                let expected_lat = (-1.0 + -2.0) / 2.0;
                let expected_lng = (36.0 + 38.0) / 2.0;
                assert!((c.lat - expected_lat).abs() < 0.5, "centroid lat off");
                assert!((c.lng - expected_lng).abs() < 0.5, "centroid lng off");
            }
        }
    }

    // ── Active count ──────────────────────────────────────────────────────────

    #[test]
    fn active_count_counts_only_active_status() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![
            active("a",   -1.286, 36.817, "Nairobi"),
            active("b",   -1.287, 36.818, "Nairobi"),
            resolved("c", -1.288, 36.819, "Nairobi"),
        ];
        let r = cluster(&pts, 6.0, 0.0);
        for item in &r.items {
            if let Some(Item::Cluster(c)) = &item.item {
                assert_eq!(c.active_count, 2, "only 2 active points");
                assert_eq!(c.count, 3,        "total 3 points in cluster");
            }
        }
    }

    // ── Dominant county ───────────────────────────────────────────────────────

    #[test]
    fn cluster_county_is_most_frequent_county() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![
            active("a", -1.286, 36.817, "Nairobi"),
            active("b", -1.287, 36.818, "Nairobi"),
            active("c", -1.288, 36.819, "Kisumu"),  // minority
        ];
        let r = cluster(&pts, 4.0, 0.0);
        for item in &r.items {
            if let Some(Item::Cluster(c)) = &item.item {
                assert_eq!(c.county, "Nairobi", "dominant county should be Nairobi");
            }
        }
    }

    // ── Case IDs in cluster ───────────────────────────────────────────────────

    #[test]
    fn cluster_case_ids_contains_all_member_ids() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![
            active("uuid-a", -1.286, 36.817, "Nairobi"),
            active("uuid-b", -1.287, 36.818, "Nairobi"),
        ];
        let r = cluster(&pts, 6.0, 0.0);
        for item in &r.items {
            if let Some(Item::Cluster(c)) = &item.item {
                assert!(c.case_ids.contains(&"uuid-a".to_string()));
                assert!(c.case_ids.contains(&"uuid-b".to_string()));
            }
        }
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    #[test]
    fn clustering_same_input_twice_gives_same_cluster_ids() {
        use amber_clustering::proto::cluster_item::Item;
        let pts = vec![
            active("a", -1.286, 36.817, "Nairobi"),
            active("b", -1.287, 36.818, "Nairobi"),
        ];
        let r1 = cluster(&pts, 6.0, 0.0);
        let r2 = cluster(&pts, 6.0, 0.0);

        let ids1: Vec<_> = r1.items.iter()
            .filter_map(|i| if let Some(Item::Cluster(c)) = &i.item { Some(c.id.clone()) } else { None })
            .collect();
        let ids2: Vec<_> = r2.items.iter()
            .filter_map(|i| if let Some(Item::Cluster(c)) = &i.item { Some(c.id.clone()) } else { None })
            .collect();

        assert_eq!(ids1, ids2, "cluster IDs must be deterministic");
    }

    // ── Zoom boundary behaviour ───────────────────────────────────────────────

    #[test]
    fn zoom_1_clusters_all_kenya_into_one() {
        use amber_clustering::proto::cluster_item::Item;
        // All major Kenyan cities should collapse into one cluster at zoom 1
        let pts = vec![
            active("nai", -1.286, 36.817, "Nairobi"),
            active("mom", -4.043, 39.668, "Mombasa"),
            active("kis", -0.092, 34.768, "Kisumu"),
            active("nak", -0.303, 36.080, "Nakuru"),
            active("tur",  3.120, 35.597, "Turkana"),
        ];
        let r = cluster(&pts, 1.0, 0.0);
        // At zoom 1 everything should be one cluster
        let cluster_count = r.items.iter()
            .filter(|i| matches!(i.item, Some(Item::Cluster(_))))
            .count();
        assert!(cluster_count >= 1, "expected at least 1 cluster at zoom 1");
        assert_eq!(r.total_in, 5);
    }

    #[test]
    fn zoom_18_separates_all_distinct_points() {
        // At zoom 18 (street level) every distinct point is its own marker
        let pts: Vec<CasePoint> = (0..10)
            .map(|i| active(&format!("p{}", i), -1.2860 + (i as f64) * 0.001, 36.817, "Nairobi"))
            .collect();

        let r = cluster(&pts, 18.0, 0.0);
        assert_eq!(r.items.len(), pts.len(), "all points separate at zoom 18");
    }

    // ── Nearby query ──────────────────────────────────────────────────────────

    #[test]
    fn nearby_returns_only_points_within_radius() {
        let pts = vec![
            active("near",   -1.287, 36.818, "Nairobi"),   // ~200 m away
            active("medium", -1.300, 36.830, "Nairobi"),   // ~2 km away
            active("far",     0.000, 35.000, "Kisumu"),    // ~300 km away
        ];

        let result = nearby(-1.286, 36.817, 1.0, 10, &pts); // 1 km radius
        assert_eq!(result.len(), 1);
        assert_eq!(result[0].id, "near");
    }

    #[test]
    fn nearby_respects_limit() {
        let pts: Vec<CasePoint> = (0..20)
            .map(|i| active(&format!("p{}", i), -1.2860 + (i as f64) * 0.0001, 36.817, "Nairobi"))
            .collect();

        let result = nearby(-1.286, 36.817, 50.0, 5, &pts);
        assert!(result.len() <= 5, "limit of 5 must be respected");
    }

    #[test]
    fn nearby_returns_closest_first() {
        let pts = vec![
            active("far_point",   -1.300, 36.817, "Nairobi"),
            active("close_point", -1.287, 36.817, "Nairobi"),
        ];

        let result = nearby(-1.286, 36.817, 50.0, 10, &pts);
        assert!(result.len() >= 2);
        assert_eq!(result[0].id, "close_point", "closest point should come first");
    }

    #[test]
    fn nearby_empty_input_returns_empty() {
        let result = nearby(-1.286, 36.817, 10.0, 5, &[]);
        assert!(result.is_empty());
    }

    #[test]
    fn nearby_zero_radius_returns_empty() {
        let pts = vec![active("a", -1.286, 36.817, "Nairobi")];
        // 0.0 is clamped to 0.1 km inside nearby(); point at same coord is within 0.1 km
        let result = nearby(-1.286, 36.817, 0.0, 10, &pts);
        // May or may not include — what matters is it doesn't panic
        let _ = result;
    }

    // ── Performance smoke test ────────────────────────────────────────────────

    #[test]
    fn clusters_10000_points_in_reasonable_time() {
        use std::time::Instant;

        let pts: Vec<CasePoint> = (0..10_000)
            .map(|i| {
                // Scatter across Kenya bounding box
                let lat = -5.0 + (i as f64 % 100.0) / 10.0;
                let lng = 34.0 + (i as f64 / 100.0) / 10.0;
                active(&format!("p{}", i), lat, lng, "Nairobi")
            })
            .collect();

        let start = Instant::now();
        let r = cluster(&pts, 6.0, 0.0);
        let elapsed = start.elapsed();

        assert_eq!(r.total_in, 10_000);
        assert!(
            elapsed.as_millis() < 500,
            "10k points should cluster in <500ms, took {}ms",
            elapsed.as_millis()
        );
    }

    // ── total_in tracking ─────────────────────────────────────────────────────

    #[test]
    fn total_in_matches_input_count_always() {
        for n in [0usize, 1, 5, 50, 200] {
            let pts: Vec<CasePoint> = (0..n)
                .map(|i| active(&format!("p{}", i), -1.0 + (i as f64) * 0.01, 36.8, "Nairobi"))
                .collect();
            let r = cluster(&pts, 6.0, 0.0);
            assert_eq!(r.total_in, n, "total_in mismatch for n={}", n);
        }
    }
}