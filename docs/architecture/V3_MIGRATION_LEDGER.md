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

## Read-only reference checkpoint

`V2_REFERENCE_INVENTORY_2026-08-31.md` records the first route/UX audit of
`demo.1945.vn`. It observed 12 visible cards on `/tri-thuc/` and 15 visible
cards on `/thuong-hieu/`; these are page samples, not source counts. The
sharing, video, media and specimen routes exposed honest empty states at audit
time. V2 REST access was blocked by the browser client, so all data counts and
identity mappings remain pending a read-only export/API/database source.

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

## Inventory and dry-run deliverables

Before any mutation, the migration work must record read-only counts and
mapping coverage for Posts, categories, attachments/media, all Authority
types, Knowledge, Sources, Evidence, relations, Videos and URLs. The dry-run
must emit source count, mapped, skipped, conflicts, duplicate candidates,
invalid relations, missing endpoints and URL mappings. It must not write to V2
or production. Media checksum matches are duplicate candidates only and never
automatic identity merges.

## No-write dry-run tooling

`tools/v2-dry-run.php --input=/path/to/read-only-inventory.json` accepts a JSON
object containing a `records` array and emits a machine-readable report. The
`DryRunService` performs no database or filesystem writes. It classifies
supported records, URL mappings and relations, validates canonical UUIDs and
stable keys, and reports bounded reason codes including
`DUPLICATE_CANDIDATE`, `INVALID_RELATION`, `MISSING_ENDPOINT`,
`INVALID_IDENTITY` and `UNSUPPORTED_LEGACY_TYPE`. A repeated media checksum is
evidence for review only; it never merges identities. The tool is ready for a
read-only V2 export, while actual migration remains gated by backup,
readability/restore evidence and resumable checkpoint design.
