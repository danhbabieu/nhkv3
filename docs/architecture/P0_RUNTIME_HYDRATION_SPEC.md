# P0 Runtime Hydration and Deployment Reliability Specification

**Status:** Approved for implementation 2026-09-02

## Goal

Make valid persisted Authority data observable again without changing database
records, while ensuring malformed individual rows fail closed and runtime or
programming failures surface explicitly instead of becoming an empty result.

## Scope

- The root Composer project is the only dependency installation boundary. The
  release contains `composer.lock`, root `vendor/autoload.php`, and the locked
  Symfony UID runtime.
- Authority row hydration separates row-data failures from infrastructure and
  programming failures. Row-data failures are recorded with a precise reason
  and omitted; `Error`, `TypeError`, missing-class failures, autoload failures
  and unexpected exceptions propagate.
- A registry-driven Authority parity audit reports every registered entity
  type's physical row count, hydrated row count, query row count and status.
- Existing `HealthCheck` gains storage, runtime, hydration, application and
  REST layer results. Zero rows are valid; hydration smoke verifies capability
  with a valid persisted row rather than a volume threshold.
- A read-only deployment preflight verifies release files, runtime classes,
  WordPress/plugin bootstrap, migration state, Authority hydration capability
  and REST initialization. Any failed check exits non-zero.
- No SQL import, restore, migration, seed, Graph repair, or semantic data
  mutation is in scope. Brand, Music, Model, Variant and Movement are observed
  through their existing physical rows only.

## Contracts

`WpdbAuthorityRepository::listByType()` continues to return only valid active
domain objects (or valid retired objects when requested). It delegates row
decoding to an explicit hydrator. The hydrator accepts a row and returns either
an `AuthorityEntity` or a row-level failure result; it never catches
`Throwable` broadly.

The parity audit consumes `EntityTypeRegistry::all()` and returns a list of
records shaped as:

```text
type, physical_rows, hydrated_rows, query_rows, status, reason
```

Health results retain the existing migration/storage fields and add named
layer objects with `ok`, `status` and bounded `reason_code` values. The REST
health route remains the existing `/nhk/v1/health` route.

## Acceptance evidence

The implementation must include tests proving valid binary UUID hydration,
neighbor preservation after malformed-row omission, propagation of runtime /
programming failures, registry-wide parity, and continued Brand/Music/
Model/Variant read availability. The preflight must be executable from the
repository root and fail non-zero when the runtime dependency or bootstrap is
not usable.

The Graph Backbone issue is separately recorded as a `CONSTITUTION_GAP` or
`CONSTITUTION_CONFLICT` only if evidence requires it; this P0 does not mutate
Graph edges.
