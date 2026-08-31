# V2 → V3 Migration Ledger

No real V2 data migration has run. Counts remain explicitly unpopulated until
the read-only legacy inventory and dry-run exist.

| Data type | Source count | Mapped | Migrated | Skipped | Duplicate | Conflict | Verified | Status / reason codes |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| WordPress Posts | — | — | — | — | — | — | — | NOT STARTED |
| Categories | — | — | — | — | — | — | — | NOT STARTED |
| Media | — | — | — | — | — | — | — | NOT STARTED; checksum is candidate evidence only |
| Authority entities | — | — | — | — | — | — | — | NOT STARTED |
| Brands / Models / Variants | — | — | — | — | — | — | — | NOT STARTED |
| Movements / Music / Components | — | — | — | — | — | — | — | NOT STARTED |
| Classifications / Specimens / Products | — | — | — | — | — | — | — | NOT STARTED |
| Sources / Knowledge / Relations | — | — | — | — | — | — | — | NOT STARTED |
| Videos | — | — | — | — | — | — | — | NOT STARTED |
| URLs | — | — | — | — | — | — | — | NOT STARTED; 301 mapping required for changes |

## Reason-code policy

Every skipped or conflicted item in a future dry-run/actual run must record a
bounded reason code, such as `UNSUPPORTED_LEGACY_TYPE`, `INVALID_IDENTITY`,
`AMBIGUOUS_MATCH`, `MISSING_ENDPOINT`, `INVALID_RELATION`, `DUPLICATE_CANDIDATE`,
`RETIRED_LEGACY_GARBAGE`, or `MANUAL_REVIEW_REQUIRED`. No silent skips.

## Migration gates

Read-only inventory → identity mapping → dry-run report → backup/readability/
restore evidence → resumable checkpointed migration → count and semantic
reconciliation. Production/live data migration and final cutover require the
separate stop conditions in `AGENTS.md`.
