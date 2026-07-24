// main.rs — binary entry point.
// The [lib] and [[bin]] in Cargo.toml share the same source tree.
// Modules declared in lib.rs are accessible here via `amber_clustering::` but
// the simplest approach in a single-crate setup is to just re-declare the
// module path — Rust deduplicates the actual compilation unit.

mod service;

// Declare proto as a module sourced from src/proto.rs (same file lib.rs uses).
// Cargo compiles each crate target separately so this is safe — there is no
// "duplicate symbol" issue because bin and lib are distinct compilation units.
pub mod proto {
    // Forward to the shared proto.rs file
    include!(concat!(env!("OUT_DIR"), "/clustering.rs"));
}

// clustering.rs uses `crate::proto` — wire it up
pub mod clustering;

use proto::geo_cluster_service_server::GeoClusterServiceServer;
use service::ClusterServiceImpl;
use tonic::transport::Server;
use tracing::info;
use tracing_subscriber::{EnvFilter, FmtSubscriber};

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