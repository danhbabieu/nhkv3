# NHK V3 Shared Feed Ordering Contract

## Scope

This contract applies to public feed-like collections for Articles, Video,
Media/Image, Knowledge, Specimen, Product, and related-content modules. It is
implemented by the shared `LatestFirstOrder` application service and is applied
before pagination.

Structural and hierarchy lists are outside this contract and retain their
registered deterministic traversal order.

## Default feed

The default feed order is:

1. `published_at` descending;
2. `created_at` descending;
3. the registered canonical stable tie-breaker descending.

Missing publication timestamps sort after published records and then use the
remaining keys. A missing or unavailable domain branch remains distinguishable
from an empty feed.

## “Mới cập nhật”

The explicit updated view orders by `updated_at` descending, then
`created_at` descending, then the same stable tie-breaker descending.

## Invariants

- Sorting is shared and happens before page slicing.
- The stable tie-breaker is canonical identity, never display text.
- The public projection does not expose internal ordering fields.
- Ordering does not create, deactivate, rekey, or otherwise mutate semantic
  records.
