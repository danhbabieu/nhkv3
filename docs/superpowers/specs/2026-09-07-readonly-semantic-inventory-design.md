# Read-Only Semantic Inventory and Relation Dry-Run

## Goal

Add three backend-only, read-only capabilities for complete canonical and Graph
review, plus a deterministic relation backfill dry-run. No demo mutation,
Governance apply, new registry vocabulary, frontend work, or legacy article-body
migration is included.

## Design

The existing repository interfaces remain the source of truth. A canonical
inventory reader composes the registered Authority types with Media, Video,
Knowledge, Source and Evidence repositories and emits a normalized row with
identity, revision, lifecycle and safe provenance/visibility fields. Filtering
is applied before page slicing and an opaque numeric cursor is returned.

Graph inventory reads the existing Graph repository in both directions using a
registered endpoint resolver. It emits typed source/target references,
predicate, lifecycle and revision, and diagnostics for missing/unsupported
endpoints and duplicate logical edges. It never creates Graph nodes or edges.

Relation dry-run scans the normalized canonical rows and graph rows through an
injected resolver. Resolver precedence is explicit UUID, structured metadata,
stable key, intended relations, deterministic identity, then reviewed mapping.
Only registered predicates/endpoints and evidence-backed candidates can become
`EXISTING` or `MISSING_DETERMINISTIC`; all other outcomes are explicit
fail-closed statuses. The report contains stable machine-readable counters and
candidate records and has no apply method.

## Exposure and safety

The three operations are registered as read tools in the current MCP catalog
and delegated through the existing read handler. The transport rejects mutation
flags and unknown arguments. `classified_as` and `Model → uses_movement` are
not added; unsupported vocabulary is reported as `REGISTRY_GAP` or
`NOT_APPLICABLE`.

## Verification

TDD focused unit tests cover pagination/filter ordering, both Graph directions,
invalid/dangling/duplicate diagnostics, resolver precedence and zero mutation.
Then run the repository's Unit and guarded Integration suites, PHP lint,
Composer validation, diff check and secret review. If deployment credentials
and the project procedure permit, deploy and read back the three tools on
`https://demo.1945.vn`; stop before governed apply and report live totals.
