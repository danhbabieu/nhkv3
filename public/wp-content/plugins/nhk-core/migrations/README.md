# Migration framework

Phiên bản khởi đầu của project là `0`. Các migration hiện có: Graph `001`,
Authority `002`, Governance `003` và Media/Video `004`.

Migration 009 thêm `nhk_legacy_projection_contexts` như một sink metadata
không canonical cho semantic projection V2. Sink giữ source identity,
provenance và cờ chất lượng nhưng không có cột body; mapper từ chối projection
có body bằng `PROJECTION_BODY_FORBIDDEN`.

Mọi migration tương lai phải có version riêng, idempotent, có status current/target,
transaction khi phù hợp và không thực hiện thao tác phá huỷ ngầm.

## Operator migration-up

The canonical operator entrypoint is the versioned maintenance script:

```sh
cd /home/erourxcg/apps/nhkv3/public
php wp-content/plugins/nhk-core/bin/nhk-core-maintenance.php \
  --operation=migration-up \
  --pack=claim-projection-016 \
  --run-id="$(date -u +%Y%m%dT%H%M%SZ)-claim-projection-016" \
  --source-revision="$(git -C /home/erourxcg/apps/nhkv3 rev-parse HEAD)" \
  --json
```

`migration-up` delegates to `Plugin::runPendingMigrations()`, which executes
the pending sequence in order through Migration 017. It is an explicit
maintenance operation; ordinary frontend requests do not run migrations.

Every UP run first passes `MigrationDatabaseGuard`. Canonical development and
integration databases are `nhk_v3` and `nhk_v3_test`. A demo staging run also
requires `WP_ENVIRONMENT_TYPE=staging`, `NHK_MIGRATION_RUNTIME=demo` and an
exact `NHK_AUTHORIZED_MIGRATION_DATABASE` match. The command must report
`current=17` and `target=17`; a second invocation is an idempotent no-op. The
additive Migration 017 creates the durable editorial Capture checkpoint table;
it does not migrate legacy article bodies or populate semantic records.
