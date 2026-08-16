// service.rs — gRPC service implementation.
//
// Hardening applied:
//   T-22: Point count capped at 50,000 before Rayon processing
//   T-32: NaN/Inf coordinates rejected with INVALID_ARGUMENT before
//         passing to clustering — prevents panics in spawn_blocking

use tonic::{Request, Response, Status};

use crate::clustering;
use crate::proto::{
    geo_cluster_service_server::GeoClusterService,
    ClusterRequest, ClusterResponse,
    NearbyRequest, NearbyResponse,
    CasePoint,
};

/// Maximum number of points accepted in a single clustering request.
/// Enforced before Rayon processing to prevent CPU exhaustion (T-22).
const MAX_POINTS: usize = 50_000;

pub struct ClusterServiceImpl;

#[tonic::async_trait]
impl GeoClusterService for ClusterServiceImpl {
    async fn cluster(
        &self,
        req: Request<ClusterRequest>,
    ) -> Result<Response<ClusterResponse>, Status> {
        let inner = req.into_inner();

        // FIX T-22: reject oversized requests before spawning any work
        if inner.points.len() > MAX_POINTS {
            return Err(Status::resource_exhausted(format!(
                "request contains {} points; maximum is {}",
                inner.points.len(),
                MAX_POINTS
            )));
        }

        // FIX T-32: validate all coordinates before entering Rayon
        validate_points(&inner.points)?;

        let zoom      = inner.zoom.clamp(1.0, 18.0);
        let radius_km = inner.radius_km;

        if inner.points.is_empty() {
            return Ok(Response::new(ClusterResponse {
                items:    vec![],
                total_in: 0,
            }));
        }

        let points = inner.points;
        let result = tokio::task::spawn_blocking(move || {
            clustering::cluster(&points, zoom, radius_km)
        })
        .await
        .map_err(|e| {
            // spawn_blocking panics are caught here — log and return INTERNAL
            // rather than crashing the whole server (T-32)
            tracing::error!("clustering task panicked: {}", e);
            Status::internal("clustering computation failed unexpectedly")
        })?;

        Ok(Response::new(ClusterResponse {
            items:    result.items,
            total_in: result.total_in as i32,
        }))
    }

    async fn nearby_points(
        &self,
        req: Request<NearbyRequest>,
    ) -> Result<Response<NearbyResponse>, Status> {
        let inner = req.into_inner();

        if inner.points.len() > MAX_POINTS {
            return Err(Status::resource_exhausted(format!(
                "request contains {} points; maximum is {}",
                inner.points.len(),
                MAX_POINTS
            )));
        }

        // Validate the query coordinate as well as all candidate points
        validate_coord(inner.lat, inner.lng, "query")?;
        validate_points(&inner.points)?;

        if inner.points.is_empty() {
            return Ok(Response::new(NearbyResponse { points: vec![] }));
        }

        let limit     = (inner.limit as usize).clamp(1, 100);
        let radius_km = inner.radius_km.max(0.1);
        let points    = inner.points;
        let lat       = inner.lat;
        let lng       = inner.lng;

        let result = tokio::task::spawn_blocking(move || {
            clustering::nearby(lat, lng, radius_km, limit, &points)
        })
        .await
        .map_err(|e| {
            tracing::error!("nearby task panicked: {}", e);
            Status::internal("nearby computation failed unexpectedly")
        })?;

        Ok(Response::new(NearbyResponse { points: result }))
    }
}

// ── Validation helpers ────────────────────────────────────────────────────────

/// Validate all points in a slice for finite, in-range coordinates.
/// FIX T-32: NaN or Inf coordinates would cause panics inside Rayon.
fn validate_points(points: &[CasePoint]) -> Result<(), Status> {
    for (i, p) in points.iter().enumerate() {
        validate_coord(p.lat, p.lng, &format!("point[{}] id={}", i, p.id))?;
    }
    Ok(())
}

/// Validate a single lat/lng pair.
fn validate_coord(lat: f64, lng: f64, context: &str) -> Result<(), Status> {
    if !lat.is_finite() || !lng.is_finite() {
        return Err(Status::invalid_argument(format!(
            "{context}: coordinates must be finite numbers (got lat={lat}, lng={lng})"
        )));
    }
    // World bounds — not Kenya-specific since the clustering service is
    // intentionally generic. Kenya bounds are enforced at the Go API layer.
    if lat < -90.0 || lat > 90.0 {
        return Err(Status::invalid_argument(format!(
            "{context}: lat {lat} is outside valid range [-90, 90]"
        )));
    }
    if lng < -180.0 || lng > 180.0 {
        return Err(Status::invalid_argument(format!(
            "{context}: lng {lng} is outside valid range [-180, 180]"
        )));
    }
    Ok(())
}