pub mod clustering;

/// Re-export the generated proto types so integration tests
/// can use `amber_clustering::proto::CasePoint` etc.
pub mod proto {
    tonic::include_proto!("clustering");
}