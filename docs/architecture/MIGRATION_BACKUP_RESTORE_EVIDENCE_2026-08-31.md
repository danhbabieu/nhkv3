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
SHA-256 `96814e84ae55e30b78d399fefbc32ed403101263ba171a639528bf9260a8d736`.
The V2 restore was attempted only against `nhk_v3_test`, never `nhk_v3` or
production. The temporary V2 tables were then removed, and the original test
snapshot was restored after omitting only incompatible `GTID_PURGED` metadata.
Post-restore checks reported zero `nhkv2_*` tables and 16 V3 NHK tables; the
guarded suite then passed with 88 tests and 351 assertions.

The reproducible restore input is generated with:

`php tools/v2-restore-normalize.php
--input=/Users/imac24-2125d/Downloads/nhk-db-full-backup.sql
--quiet > /private/tmp/nhk-v2-normalized-20260831.sql`

This proves a reviewed staging restore and recovery of the local V3
integration database. Live migration remains blocked until field-level
URL/media reconciliation, approval, external transport QA and Cutover
Readiness evidence are complete.

## Local-dev apply evidence

The exported JSON is `/private/tmp/nhk-v3-v2-full-export.json` (SHA-256
`105f09b5b400f0e15844a5b0d97c8f0ce988d644aefb86393fcbb2578dfef580`). Its
no-write dry-run is `/private/tmp/nhk-v3-v2-full-dry-run.json` (SHA-256
`862f469d22e6f9485c1b4798242f79b5bf0fd7b2e7da0e6b90498b134b42c1ff`). The
apply result is `/private/tmp/nhk-v3-v2-apply-result.json` (SHA-256
`2dcec0a043d7e97de8f56c7967ba251db20536ca15f65c77277934ad936890b8`). The
runner wrote 1,545 `migrated` ledger rows and 3,388 explicit skips to
`nhk_v3`.
