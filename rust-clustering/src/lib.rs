// lib.rs — library crate root.
// Used by: integration tests in tests/
//          (and indirectly shares source files with the binary)

pub mod clustering;

pub mod proto {
    // tonic::include_proto! expands to include!(concat!(env!("OUT_DIR"), "/clustering.rs"))
    // OUT_DIR is always defined during `cargo build` / `cargo test`.
    // It is NOT defined when rustc is invoked directly outside of Cargo — but
    // you should only ever build with `cargo build` or `cargo run`.
    tonic::include_proto!("clustering");
}