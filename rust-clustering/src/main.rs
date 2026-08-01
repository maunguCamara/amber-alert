// main.rs — sole crate root (no [lib] target).
//
// With only a [[bin]] target, `crate::` always refers to this file.
// build.rs runs before compilation and writes the generated proto code
// to OUT_DIR. tonic::include_proto! reads from OUT_DIR — this works
// because Cargo sets OUT_DIR for every build script invocation before
// any source file is compiled.

use tonic::transport::Server;
use tracing::info;
use tracing_subscriber::{EnvFilter, FmtSubscriber};

// Proto types generated from proto/clustering.proto by build.rs.
mod proto {
    tonic::include_proto!("clustering");
}

// Business logic — geo-clustering algorithm and Haversine distance.
mod clustering;

// gRPC service implementation (uses crate::proto and crate::clustering).
mod service;

use proto::geo_cluster_service_server::GeoClusterServiceServer;
use service::ClusterServiceImpl;

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
        .add_service(GeoClusterServiceServer::new(ClusterServiceImpl))
        .serve(addr)
        .await?;

    Ok(())
}