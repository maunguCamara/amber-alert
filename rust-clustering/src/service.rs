// service.rs — gRPC service implementation.
// Uses crate::proto (tonic-generated) and crate::clustering.

use tonic::{Request, Response, Status};

use crate::clustering;
use crate::proto::{
    geo_cluster_service_server::GeoClusterService,
    ClusterRequest, ClusterResponse,
    NearbyRequest, NearbyResponse,
};

pub struct ClusterServiceImpl;

#[tonic::async_trait]
impl GeoClusterService for ClusterServiceImpl {
    async fn cluster(
        &self,
        req: Request<ClusterRequest>,
    ) -> Result<Response<ClusterResponse>, Status> {
        let inner = req.into_inner();
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
        .map_err(|e| Status::internal(format!("clustering task panicked: {e}")))?;

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
        .map_err(|e| Status::internal(format!("nearby task panicked: {e}")))?;

        Ok(Response::new(NearbyResponse { points: result }))
    }
}