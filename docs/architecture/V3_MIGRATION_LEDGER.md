# V2 → V3 Migration Ledger

The local development apply checkpoint has run after the read-only inventory,
backup/restore rehearsal and dry-run. It is not a live or production
migration, and unresolved rows remain explicit in the ledger.

| Data type | Source count | Mapped | Migrated | Skipped | Duplicate | Conflict | Verified | Status / reason codes |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| WordPress Posts | 800 | 36 | 36 | 764 | 0 | 0 | 36 | DEV ONLY; 764 domain-targeted custom/system posts |
| Categories | 2 | 1 | 1 | 1 | 0 | 0 | 1 | DEV ONLY; non-category taxonomy is explicit skip |
| Media entities | 242 | 242 | 242 | 0 | 0 | 0 | 242 | DEV ONLY; asset metadata is ledgered separately |
| Authority entities | 370 | 370 | 370 | 0 | 0 | 0 | 370 | DEV ONLY; exact UUID/stable-key mapping |
| Knowledge claims | 655 | 655 | 655 | 0 | 0 | 0 | 655 | DEV ONLY; Source/Evidence joins verified; rows retain V2 private visibility |
| Relations | 427 | 241 | 241 | 186 | 0 | 0 | 241 | DEV ONLY; governed `about` relations only |
| Videos | 0 | 0 | 0 | 0 | 0 | 0 | 0 | No source rows in selected backup |
| URLs | 800 | 1 candidate | 1 | 799 | 0 | 0 | 1 | One identical source/target path is a safe `READY_NOOP`; 799 redirects remain unmapped |
| Media assets | 3 | 3 | 3 | 0 | 0 | 0 | 3 | DEV ONLY; checksum, dimensions, field-level metadata and PRIVATE visibility imported; public delivery/usages remain open |
| Sources | 19 | 19 | 19 | 0 | 0 | 0 | 19 | DEV ONLY; imported inactive because V2 visibility is PRIVATE |
| Evidence | 40 | 40 | 40 | 0 | 0 | 0 | 40 | DEV ONLY; citation endpoints and metadata verified; imported inactive because V2 visibility is PRIVATE |
| Semantic projections | 1,581 | 0 | 0 | 1,581 | 0 | 0 | 0 | Explicitly unsupported until target mapping/provenance is governed |

## Read-only reference checkpoint

`V2_REFERENCE_INVENTORY_2026-08-31.md` records the first route/UX audit of
`demo.1945.vn`. It observed 12 visible cards on `/tri-thuc/` and 15 visible
cards on `/thuong-hieu/`; these are page samples, not source counts. The
sharing, video, media and specimen routes exposed honest empty states at audit
time. V2 REST access was blocked by the browser client, so the database export
is the authoritative source for the counts and identity mappings recorded in
this ledger.

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
stable keys, reports per-type source/mapped counts and skipped-reason counts,
and emits bounded reason codes including `DUPLICATE_CANDIDATE`,
`INVALID_RELATION`, `MISSING_ENDPOINT`, `INVALID_IDENTITY`, `INVALID_RECORD`,
`CONFLICT_REQUIRES_REVIEW` and `UNSUPPORTED_LEGACY_TYPE`. Invalid checksums
and malformed records are not silently mapped. A repeated media checksum is
evidence for review only; it never merges identities. The tool is ready for a
read-only V2 export, while actual migration remains gated by backup,
readability/restore evidence, field-level reconciliation, approval and
Cutover Readiness. The apply runner is `tools/v2-migrate.php`; `--offset`
selects the next source window and Migration006 stores the durable checkpoint;
Migration007 stores governed Evidence citation metadata and Migration008 stores
MediaAsset visibility/metadata. Public Media reads suppress non-PUBLIC assets.
