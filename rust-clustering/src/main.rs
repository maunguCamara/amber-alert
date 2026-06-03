mod clustering;
mod service;

// Include the tonic-generated proto code.
pub mod proto {
    tonic::include_proto!("clustering");
}

use proto::geo_cluster_service_server::GeoClusterServiceServer;
use service::ClusterServiceImpl;
use tonic::transport::Server;
use tracing::info;
use tracing_subscriber::{EnvFilter, FmtSubscriber};

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    // Initialise structured logging.
    // Set RUST_LOG=amber_clustering=debug for verbose output.
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
        .add_service(GeoClusterServiceServer::new(ClusterServiceImpl))
        .serve(addr)
        .await?;

    Ok(())
}