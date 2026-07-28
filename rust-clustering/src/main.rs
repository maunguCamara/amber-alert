// main.rs — binary entry point.
//
// The [[bin]] target uses the real tonic-generated proto types.
// build.rs compiles proto/clustering.proto → OUT_DIR/clustering.rs
// before this file is compiled, so include_proto! works here.
//
// Do NOT use tonic::include_proto! in lib.rs — the [lib] target
// does not run build.rs and OUT_DIR is undefined there.

use tonic::transport::Server;
use tracing::info;
use tracing_subscriber::{EnvFilter, FmtSubscriber};

// Pull in the tonic-generated code for the binary target only.
pub mod proto {
    tonic::include_proto!("clustering");
}

// clustering.rs references `crate::proto` — when compiled as part of
// the binary crate root (this file), it sees the tonic-generated proto
// module above.
pub mod clustering;

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