# Migration Backup / Restore Evidence — 2026-08-31

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

The latest exported JSON is `/private/tmp/nhk-v3-v2-full-export-media-6.7.json`
(SHA-256 `1683e21e31dcb3b3d300a3acdbc9941d4cb58c420a8e68a93d4c25b31d504a11`).
Its no-write dry-run is `/private/tmp/nhk-v3-v2-full-dry-run-media-6.7.json`
(SHA-256 `6e46c2d2cc9c1b1e34b70c7560ba6bf7129d06f5bdf236851af825a30cdc704d`).
The latest idempotent apply result is
`/private/tmp/nhk-v3-v2-apply-result-media-6.7-rerun.json` (SHA-256
`b28223aa874528cb9e47fd97032df1a04ce2e75bf0d292ba0c7d8d6016005229`).
The runner wrote 1,608 `migrated` ledger rows and 3,365 explicit skips to
`nhk_v3`, including 19 Source and 40 Evidence rows retained as inactive
because the V2 records were PRIVATE. Migration008 persisted field-level media
metadata and the three MediaAsset rows were reconciled to PRIVATE; the latest
ledger has 3 `STATE_RECONCILED` media rows, one `READY_NOOP` URL and 0
conflicts.
