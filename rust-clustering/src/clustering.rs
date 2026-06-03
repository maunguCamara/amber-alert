/// Geo-clustering engine.
///
/// Algorithm: grid-cell clustering (same approach used by supercluster.js)
/// 1. Project each WGS-84 point to a Mercator pixel coordinate at the
///    target zoom level.
/// 2. Snap each pixel to a grid cell whose size = `radius_px`.
/// 3. Group all points sharing the same cell.
/// 4. If a cell has >1 point → emit a Cluster (centroid + count).
///    If a cell has 1 point  → emit the raw CasePoint.
///
/// Complexity: O(n) average, O(n log n) worst case.
/// Parallelism: cell grouping is done on rayon's thread pool.

use std::collections::HashMap;

use rayon::prelude::*;

use crate::proto::{CasePoint, Cluster, ClusterItem, cluster_item::Item};

// ── Mercator projection helpers ───────────────────────────────────────────────

/// Latitude → Mercator Y in [0, 1]
#[inline]
fn lat_to_y(lat: f64) -> f64 {
    let sin = lat.to_radians().sin();
    let y = 0.5 - 0.25 * ((1.0 + sin) / (1.0 - sin)).ln() / std::f64::consts::PI;
    y.clamp(0.0, 1.0)
}

/// Longitude → Mercator X in [0, 1]
#[inline]
fn lng_to_x(lng: f64) -> f64 {
    (lng / 360.0 + 0.5).clamp(0.0, 1.0)
}

/// Convert zoom level + radius_km to a pixel radius.
/// At zoom 0 the whole world is 256 px; each zoom doubles it.
fn zoom_to_radius_px(zoom: f64, radius_km: f64) -> f64 {
    // Earth circumference ≈ 40_075 km
    let world_px = 256.0 * 2f64.powf(zoom);
    let km_per_px = 40_075.0 / world_px;
    // Default fallback: 60 km at zoom 6 (reasonable for Kenya county level)
    let km = if radius_km > 0.0 { radius_km } else { 60.0 / 2f64.powf(zoom - 6.0) };
    (km / km_per_px).max(1.0)
}

// ── Cell key ─────────────────────────────────────────────────────────────────

#[derive(Eq, PartialEq, Hash, Clone, Debug)]
struct CellKey(i64, i64);

fn point_to_cell(lat: f64, lng: f64, world_px: f64, radius_px: f64) -> CellKey {
    let x = lng_to_x(lng) * world_px;
    let y = lat_to_y(lat) * world_px;
    CellKey(
        (x / radius_px).floor() as i64,
        (y / radius_px).floor() as i64,
    )
}

// ── Public API ───────────────────────────────────────────────────────────────

pub struct ClusteredResult {
    pub items:    Vec<ClusterItem>,
    pub total_in: usize,
}

/// Cluster a slice of `CasePoint`s at the given zoom / radius.
pub fn cluster(points: &[CasePoint], zoom: f64, radius_km: f64) -> ClusteredResult {
    let total_in = points.len();

    if total_in == 0 {
        return ClusteredResult { items: vec![], total_in: 0 };
    }

    let world_px  = 256.0 * 2f64.powf(zoom);
    let radius_px = zoom_to_radius_px(zoom, radius_km);

    // ── Group points into cells (parallel) ────────────────────────────────────
    // We collect (CellKey, index) pairs in parallel then merge into a HashMap.
    let keyed: Vec<(CellKey, usize)> = points
        .par_iter()
        .enumerate()
        .map(|(i, p)| (point_to_cell(p.lat, p.lng, world_px, radius_px), i))
        .collect();

    let mut cells: HashMap<CellKey, Vec<usize>> = HashMap::with_capacity(total_in);
    for (key, idx) in keyed {
        cells.entry(key).or_default().push(idx);
    }

    // ── Build output items ────────────────────────────────────────────────────
    let items: Vec<ClusterItem> = cells
        .par_iter()
        .map(|(_key, indices)| {
            if indices.len() == 1 {
                // Lone point
                ClusterItem {
                    item: Some(Item::Point(points[indices[0]].clone())),
                }
            } else {
                // Cluster — compute centroid and dominant county
                let mut sum_lat = 0.0f64;
                let mut sum_lng = 0.0f64;
                let mut active  = 0i32;
                let mut county_freq: HashMap<&str, usize> = HashMap::new();
                let mut case_ids = Vec::with_capacity(indices.len());

                for &i in indices {
                    let p = &points[i];
                    sum_lat += p.lat;
                    sum_lng += p.lng;
                    if p.status == "active" { active += 1; }
                    *county_freq.entry(p.county.as_str()).or_insert(0) += 1;
                    case_ids.push(p.id.clone());
                }

                let n = indices.len() as f64;
                let dominant_county = county_freq
                    .into_iter()
                    .max_by_key(|&(_, c)| c)
                    .map(|(k, _)| k.to_string())
                    .unwrap_or_default();

                let cluster_id = cluster_id_from(&case_ids);

                ClusterItem {
                    item: Some(Item::Cluster(Cluster {
                        id:           cluster_id,
                        lat:          sum_lat / n,
                        lng:          sum_lng / n,
                        count:        indices.len() as i32,
                        active_count: active,
                        county:       dominant_county,
                        case_ids,
                    })),
                }
            }
        })
        .collect();

    ClusteredResult { items, total_in }
}

// ── Nearby query ──────────────────────────────────────────────────────────────

/// Return up to `limit` points within `radius_km` of (lat, lng),
/// sorted by ascending distance.
pub fn nearby(
    lat: f64,
    lng: f64,
    radius_km: f64,
    limit: usize,
    points: &[CasePoint],
) -> Vec<CasePoint> {
    // Approximate degree-per-km conversion (good enough for Kenya's extent).
    // 1° lat ≈ 111 km; 1° lng ≈ 111 km × cos(lat)
    let dlat = radius_km / 111.0;
    let dlng = radius_km / (111.0 * lat.to_radians().cos().abs().max(0.001));

    let mut candidates: Vec<(f64, &CasePoint)> = points
        .par_iter()
        .filter_map(|p| {
            // Quick bounding-box filter before haversine
            if (p.lat - lat).abs() > dlat * 1.1 || (p.lng - lng).abs() > dlng * 1.1 {
                return None;
            }
            let d = haversine_km(lat, lng, p.lat, p.lng);
            if d <= radius_km { Some((d, p)) } else { None }
        })
        .collect();

    candidates.sort_by(|a, b| a.0.partial_cmp(&b.0).unwrap());
    candidates.into_iter().take(limit).map(|(_, p)| p.clone()).collect()
}

// ── Haversine distance ────────────────────────────────────────────────────────

pub fn haversine_km(lat1: f64, lng1: f64, lat2: f64, lng2: f64) -> f64 {
    const R: f64 = 6_371.0; // Earth radius km
    let dlat = (lat2 - lat1).to_radians();
    let dlng = (lng2 - lng1).to_radians();
    let a = (dlat / 2.0).sin().powi(2)
        + lat1.to_radians().cos() * lat2.to_radians().cos() * (dlng / 2.0).sin().powi(2);
    2.0 * R * a.sqrt().asin()
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/// Deterministic cluster ID: XOR-fold of FNV hashes of sorted case IDs.
fn cluster_id_from(ids: &[String]) -> String {
    let mut sorted = ids.to_vec();
    sorted.sort_unstable();
    let hash = sorted.iter().fold(0u64, |acc, id| {
        acc ^ fnv1a(id.as_bytes())
    });
    format!("cluster-{:016x}", hash)
}

fn fnv1a(data: &[u8]) -> u64 {
    const FNV_PRIME:  u64 = 0x00000100000001B3;
    const FNV_OFFSET: u64 = 0xcbf29ce484222325;
    data.iter().fold(FNV_OFFSET, |h, &b| (h ^ b as u64).wrapping_mul(FNV_PRIME))
}

// ── Tests ─────────────────────────────────────────────────────────────────────

#[cfg(test)]
mod tests {
    use super::*;

    fn pt(id: &str, lat: f64, lng: f64) -> CasePoint {
        CasePoint {
            id:           id.to_string(),
            reference_no: id.to_string(),
            lat,
            lng,
            status:  "active".to_string(),
            county:  "Nairobi".to_string(),
        }
    }

    #[test]
    fn no_points_returns_empty() {
        let r = cluster(&[], 6.0, 0.0);
        assert!(r.items.is_empty());
    }

    #[test]
    fn single_point_not_clustered() {
        let pts = vec![pt("a", -1.286, 36.817)];
        let r = cluster(&pts, 6.0, 0.0);
        assert_eq!(r.items.len(), 1);
        match &r.items[0].item {
            Some(crate::proto::cluster_item::Item::Point(_)) => {}
            _ => panic!("expected a lone point"),
        }
    }

    #[test]
    fn nearby_points_within_radius() {
        let pts = vec![
            pt("near",  -1.287, 36.818),
            pt("far",    0.000, 35.000),
        ];
        let result = nearby(-1.286, 36.817, 5.0, 10, &pts);
        assert_eq!(result.len(), 1);
        assert_eq!(result[0].id, "near");
    }

    #[test]
    fn haversine_nairobi_to_mombasa() {
        // ~441 km by road; straight line ≈ 400 km
        let d = haversine_km(-1.286, 36.817, -4.043, 39.668);
        assert!((380.0..=430.0).contains(&d), "got {}", d);
    }

    #[test]
    fn two_close_points_merge_into_cluster() {
        // Both within Nairobi — should cluster at zoom 6
        let pts = vec![
            pt("a", -1.286, 36.817),
            pt("b", -1.290, 36.820),
        ];
        let r = cluster(&pts, 6.0, 0.0);
        assert_eq!(r.items.len(), 1);
        match &r.items[0].item {
            Some(crate::proto::cluster_item::Item::Cluster(c)) => {
                assert_eq!(c.count, 2);
            }
            _ => panic!("expected cluster"),
        }
    }
}