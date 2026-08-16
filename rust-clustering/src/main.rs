// main.rs — binary entry point.
//
// Key hardening applied:
//   T-22: MaxRecvMsgSize set to 10 MB — prevents gRPC message bomb
//   T-32: NaN/Inf coordinate validation before clustering

use tonic::transport::Server;
use tracing::info;
use tracing_subscriber::{EnvFilter, FmtSubscriber};

mod proto {
    tonic::include_proto!("clustering");
}

mod clustering;
mod service;

use proto::geo_cluster_service_server::GeoClusterServiceServer;
use service::ClusterServiceImpl;

// 10 MB — a realistic upper bound for a single clustering request
// covering all 47 Kenyan counties with many cases.
// Exceeding this returns gRPC RESOURCE_EXHAUSTED without crashing (T-22).
const MAX_RECV_MSG_SIZE: usize = 10 * 1024 * 1024;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let subscriber = FmtSubscriber::builder()
        .with_env_filter(
            EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| EnvFilter::new("amber_clustering=info,tonic=warn")),
        )
        .with_target(false)
        .compact()
        .finish();
    tracing::subscriber::set_global_default(subscriber)?;

    let addr = std::env::var("CLUSTER_LISTEN_ADDR")
        .unwrap_or_else(|_| "0.0.0.0:50051".to_string())
        .parse()?;

    info!("Amber Alert Clustering Service listening on {}", addr);

    Server::builder()
        // FIX T-22: cap incoming message size — rejects gRPC bombs
        .max_recv_message_size(MAX_RECV_MSG_SIZE)
        // Limit outgoing size symmetrically
        .max_send_message_size(MAX_RECV_MSG_SIZE)
        .add_service(GeoClusterServiceServer::new(ClusterServiceImpl))
        .serve(addr)
        .await?;

    Ok(())
}