# Migration Backup / Restore Evidence — 2026-08-31

Decision: **NOT READY for real-data migration**.

## Existing artifacts

| Artifact | Size | SHA-256 | Status |
|---|---:|---|---|
| `/Users/imac24-2125d/Downloads/nhk-db-full-backup.sql` | 36 MB | `c36e024a5280b0bbbd3cb13ba76ecc5b5c64cc6f288d09b8fa9c5fac17c58a4a` | V2 source candidate; restore rehearsal blocked |
| `/Users/imac24-2125d/backup.sql` | 2.0 MB | `fedfea44d698e521551bd074d7893a2946efeef0629aa2c35623bb316ae814cf` | Secondary V2 artifact; not selected for inventory |

The active read-only source used for inventory is the restored dump in
`nhk_v3_test`. The original V2 dump stopped at line 1565 because MariaDB
9.7.1 rejects a `TEXT`/`BLOB` column default in the legacy `proposal`
definition. A deterministic staging conversion removed only the two invalid
`LONGTEXT NOT NULL DEFAULT` clauses (`proposal` and `definition`) plus GTID
metadata; the resulting restore completed with no SQL errors and produced
800 posts, 1,301 entities, 185 relations, 3 media assets and 1,581 semantic
projections. This is staging inventory evidence, not permission to apply
V2 data into V3.

## Guarded V3 test snapshot rehearsal

Before the final rehearsal, `nhk_v3_test` was dumped to a temporary file with
SHA-256 `cc1fa70296e032ccdafefa1f8bdbceff54ec2fcd1d7ba001311e124ac897d3c1`.
The V2 restore was attempted only against `nhk_v3_test`, never `nhk_v3` or
production. The temporary V2 tables were then removed, and the original test
snapshot was restored after omitting only incompatible `GTID_PURGED` metadata.
Post-restore checks reported zero `nhkv2_*` tables and 16 V3 NHK tables; the
guarded suite then passed with 88 tests and 351 assertions.

This proves a reviewed staging restore and recovery of the local V3
integration database. A real migration remains blocked until field-level
mapping, URL/media reconciliation, approval and migration-ledger evidence are
complete.
