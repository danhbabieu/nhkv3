# Migration Backup / Restore Evidence — 2026-08-31

> **NON-NORMATIVE.** Đây là migration evidence; không phải authorization.
> Nếu mâu thuẫn với `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp
> kiểm soát.

Decision: **NOT READY for live/production data migration**. A local-dev apply
checkpoint is complete and separately ledgered.

## Existing artifacts

| Artifact | Size | SHA-256 | Status |
|---|---:|---|---|
| `/Users/imac24-2125d/Downloads/nhk-db-full-backup.sql` | 36 MB | `c36e024a5280b0bbbd3cb13ba76ecc5b5c64cc6f288d09b8fa9c5fac17c58a4a` | V2 source candidate; restore rehearsal blocked |
| `/Users/imac24-2125d/backup.sql` | 2.0 MB | `fedfea44d698e521551bd074d7893a2946efeef0629aa2c35623bb316ae814cf` | Secondary V2 artifact; not selected for inventory |
| `/private/tmp/nhk-v2-normalized-20260831.sql` | 37,767,727 bytes | `ed469494e3af71b5dc1b26eafa5876ce1430454668a98c2768e2dd288a8765b7` | Reproducible staging input after narrow MariaDB conversion |

The active read-only source used for inventory is the restored dump in
`nhk_v3_test`. The original V2 dump stopped at line 1565 because MariaDB
9.7.1 rejects a `TEXT`/`BLOB` column default in the legacy `proposal`
definition. The versioned `tools/v2-restore-normalize.php` conversion removes
only the two invalid `LONGTEXT NOT NULL DEFAULT` clauses (`proposal` and
`definition`) plus GTID metadata; the resulting restore completed with no SQL
errors and produced
800 posts, 1,301 entities, 185 Graph relations, 3 media assets and 1,581
semantic projections. The expanded no-write exporter additionally observed
242 Knowledge relations, 19 evidence rows, 40 citations and 2 taxonomy rows.
This is staging inventory evidence, not permission to apply V2 data into V3.

## Guarded V3 test snapshot rehearsal

Before the latest rehearsal, `nhk_v3_test` was dumped to a temporary file with
SHA-256 `13537f9d523a5dff8587fdfa7d9c07961f242cfb5401c6a310274d57bd4be8b4`.
The V2 restore was attempted only against `nhk_v3_test`, never `nhk_v3` or
production. The temporary V2 tables were then removed, and the original test
snapshot was restored after omitting only incompatible `GTID_PURGED` metadata.
Post-restore checks reported zero `nhkv2_*` tables and 17 V3 NHK tables; the
guarded suite then passed with 91 tests and 373 assertions.

The reproducible restore input is generated with:

`php tools/v2-restore-normalize.php
--input=/Users/imac24-2125d/Downloads/nhk-db-full-backup.sql
--quiet > /private/tmp/nhk-v2-normalized-20260831.sql`

This proves a reviewed staging restore and recovery of the local V3
integration database. Live migration remains blocked until field-level
URL/media reconciliation, approval, external transport QA and Cutover
Readiness evidence are complete.

## Local-dev apply evidence

The latest full restored V2 export is `/private/tmp/nhk-v3-v2-full-export-url-6.14.json`
(SHA-256 `061b2b647407c888de890b3f34bc3be7c80803f3c1e923372de409d278e5deac`).
Its Migration009 no-write dry-run is
`/private/tmp/nhk-v3-v2-full-dry-run-projection-009-final.json` (SHA-256
`5f4b7afca5386dd7a7ea1c9a3f733a901b2f0e93b388db18b33f50b6db9f3c7c`).
The latest governed apply result is
`/private/tmp/nhk-v3-v2-apply-result-projection-009.json` (SHA-256
`8a3d08f608a03551bd988b025722b5c7b75e70bf338b01c623368cb2e3cb91a3`).
The runner wrote 3,960 `migrated` ledger rows and 1,013 explicit skips to
`nhk_v3`, including all 1,581 semantic projections in the non-canonical
context sink, 19 Source and 40 Evidence rows retained as inactive
because the V2 records were PRIVATE. Migration008 persisted field-level media
metadata and the three MediaAsset rows were reconciled to PRIVATE; Mapper 6.14
retained 367 Knowledge claim UUID redirects (including 75 archived-to-active
consolidations) and 370 entity-registry redirects alongside the 34 native-post
aliases and one `READY_NOOP` URL, with 0 conflicts. A second full apply
produced the same 3,960/1,013/0 counts,
confirming idempotency.

The Migration009 dry-run reported 4,973 source records, 3,960 mapped, 1,013
skipped and 772 URL mappings. Its explicit skip buckets were 747
`DOMAIN_TARGETED`, 42 `UNSUPPORTED_MEDIA_REFERENCE`, 3
`RETIRED_LEGACY_GARBAGE`, 1 `INVALID_RELATION` and 220
`UNSUPPORTED_LEGACY_TYPE`. The staging database was then cleaned of exactly
the 53 `nhkv2_*` tables and restored from the clean V3 snapshot; post-restore
checks reported 0 `nhkv2_*` tables and 17 V3 tables in `nhk_v3_test`.
After the full guarded suite, staging was restored once more and migrations
005–009 were applied UP-only; final local checks report
`nhk_core_migration_current/target` `9/9` in `nhk_v3`. The guarded suite
passed 117 tests and 475 assertions. Read-only checks report 1,581
projection-context rows, all with `body_migrated=false`, and zero
projection-derived Authority entities.
