# Public Identity Cutover Readiness Report

## Verification checkpoint — 2026-09-04

### Blocker resolution

The live migration blocker was a code regression in `Plugin::boot()`: every
normal WordPress request attempted pending migrations, including migration 014,
before registering public routes. Migration 014 correctly failed closed because
the live database is not one of the explicitly permitted `nhk_v3` or
`nhk_v3_test` databases. Boot now leaves migration execution untouched unless
the explicit `NHK_RUN_MIGRATIONS=true` gate is defined; plugin activation remains
the explicit migration entrypoint and the database safety guard is unchanged.

Required deployment configuration: leave `NHK_RUN_MIGRATIONS` unset or false
for production/read-only traffic. A separately authorized maintenance/activation
operation must provision and verify the governed schema before canary traffic;
production/read-only request traffic must not be used to execute migration-up.
No production migration was run by this checkpoint.

### Integration result

The requested command was rerun with `NHK_WP_TEST_PATH=public` and the exact
guarded database selector `NHK_WP_TEST_DB=nhk_v3_test`:

```text
NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration'
```

The run did not enter PHPUnit test execution. WordPress bootstrap terminated
with `Error establishing a database connection`; the configured values are
`DB_HOST=127.0.0.1`, `DB_NAME=nhk_v3_test`, `DB_USER=root`, and an empty
`DB_PASSWORD`. Both TCP port 3306 and the default local socket were unavailable,
so database existence/reachability remains unverified. `NHK_WP_TEST_PATH=public`
is correct and resolves to the repository WordPress bootstrap. The suite is
therefore `ENVIRONMENT_BLOCKED` at bootstrap. The executable Unit suite passes:
485 tests, 2,252 assertions, 1 warning and 5 PHPUnit deprecations.

### Live canary read-back

Read-only GETs were re-attempted in the connected Chrome browser for the
expected canonical and historic routes on `https://demo.1945.vn`. Both requests
confirmed the deployed old code still terminates during WordPress plugin boot
with:

```text
PUBLIC_IDENTITY_MIGRATION_UP_REQUIRES_NHK_V3_OR_TEST
```

The corrected code is not deployed, so live route resolution and identity
read-back remain `UNVERIFIED`. The requests were GETs and stopped before route
resolution; no semantic projection/cutover/push/merge occurred. A strict
zero-write claim is not available because the old boot path may update
WordPress options before the fatal.

`READY_FOR_OWNER_CUTOVER = NO` pending a usable exact `nhk_v3_test` runtime and
a live deployment whose migration state is safely brought to the governed
target before repeating the read-only canary.

Status: `PENDING_OWNER_APPROVAL` / `PRE_CUTOVER_ONLY` — 2026-09-04

Task 12 was completed as a read-only evidence checkpoint. No production,
staging, V2 or development semantic data was projected or mutated. No
canonical URL, Video UUID, YouTube identity, relation, MediaAsset filename or
WordPress content was changed.

## Bounded canary

| Item | Expected |
|---|---|
| Video UUID | `01a06815-1e51-7964-b004-1ba79e488ad1` |
| YouTube ID | `P4KaHX3LBOw` |
| Canonical URL | `/video/odo-36-10-gai-carillon-p4kahx3lbow/` |
| Historic route | one direct `301` from the malformed mixed-case YouTube suffix |

The read-only canary boundary checks UUID and external-ID preservation, the
canonical path, zero duplicate Video rows, unchanged semantic relations, no
duplicate `200` route, one-hop history and non-`200` historic resolution. Its
receipt always reports `mutation_count=0` and `live_projection_performed=false`.

Live read-back is `ENVIRONMENT_BLOCKED` when `NHK_WP_TEST_PATH` is unavailable;
therefore no live canary pass is claimed. Owner approval and a separately
authorized governed execution remain required before any projection/cutover.
The full guarded Integration run recorded 12 environment failures and 81
skips for the unavailable WordPress runtime; all 12 are classified
`ENVIRONMENT_BLOCKED`, not test passes.

## Final review classification

- Public Identity persistence and Authority URL projection: implemented locally;
  target-runtime allocation/read-back remains environment-gated.
- Historic one-hop `301` and Video semantic URL policy: focused/unit covered;
  live route read-back remains pending.
- Media route ruling: standalone Media detail is a `CODE_GAP` and fails closed;
  asset delivery remains delivery-only/noindex.
- Knowledge route ruling: atomic Claim/Source/Evidence HTML detail is not
  indexable; Album/Gallery remains `REGISTRY_GAP`.
- SEO/internal URL consumers: shared canonical projection is wired locally;
  native WordPress editorial URLs remain independent.
- Collision behavior and identity safety: fail-closed checks are covered;
  no runtime slug derivation from `canonical_name` is authorized for durable
  identity, and no generic WordPress semantic fallback writer exists.

## Open items

- `ENVIRONMENT_BLOCKED`: guarded integration/live canary read-back while
  `NHK_WP_TEST_PATH` is unavailable.
- `CODE_GAP`: standalone Media detail/public identity route remains retired.
- `REGISTRY_GAP`: Album/Gallery public projection.
- `REGISTRY_GAP`: dedicated Product–Specimen canonical relation.
- `CONSTITUTION_CONFLICT`: none unresolved in this checkpoint; the Media
  constitutional ruling is recorded and obeyed.

Production canary/cutover is explicitly **PENDING OWNER APPROVAL**.
