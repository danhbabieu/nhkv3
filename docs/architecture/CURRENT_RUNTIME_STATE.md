# NHK V3 Current Runtime State

Status: RC_BLOCKED_ENVIRONMENT
Verified code SHA: a7e52659bfae538c6caefef7159a0a01dae15d37
Verified at: 2026-10-03T07:54:00+07:00

Source:
- Branch `main`; working tree was clean at verification.
- Runtime version `0.1.0`; source revision above.
- Documentation/build identity was read from the canonical registry before this state snapshot.

Schema:
- Expected target: `23` from `PresentationNavigationMigration023` and the executable runner.
- Local `nhk_v3`: current `23`, target `23`; `nhk_presentation_navigation` exists.
- UP migration 023 passed and a second invocation passed as an idempotency check.

Local runtime:
- WordPress, Apache/PHP and MySQL reachable; `/wp-json/nhk/v1/health` returned HTTP 200.
- Health: storage, runtime, hydration, application and REST all ready; no PHP warning after the RC runtime fix.

Tests:
- Contract: PASS, 6 tests / 48 assertions.
- Unit: 2,913 tests / 17,433 assertions; 1 pre-existing error and 3 pre-existing failures, reproduced at `HEAD^`.
- Full suite: 3,095 tests / 17,504 assertions; integration errors/failures are environment-gated by the required `NHK_WP_TEST_PATH=public` boundary.
- PHP lint, diff check and changed-scope secret review pass for the RC fix.

Browser and public routes:
- Local route/SEO smoke and browser structural smoke pass for the core matrix, Clock Type archive/query boundary, search, Article, Brand and 404.
- Viewport override, DOM image metrics and `currentSrc`/`naturalWidth` were not available: `STRUCTURAL_ONLY`.
- Two legacy category aliases are `DATA_EMPTY/ENVIRONMENT_GATED`: `tri-thuc-dong-ho` is not present locally; no seed was added.

Dictionary:
- `READY_EMPTY`: migration/table ready, `/tu-dien/` available, 0 concepts and 0 APPROVED terms; no seed performed.

Staging:
- Read-only staging health is 200/current=target=23, but it still emits the old `$homeSemanticQuery` warning.
- `STAGING_DEPLOY_GATE=BLOCKED_BY_POLICY`: exact staging publish was not authorized by the deployment policy gate; no remote write occurred.
- Staging read-back for the verified RC SHA is therefore not available.

Rollback:
- Code/package rollback procedure is documented and previous verified staging revision is recorded in the cutover plan.
- No rollback dry-run was authorized; migration 023 has no safe operational DOWN path. DB rollback is not claimed safe.

Known blockers:
- Explicit human authorization is required before publishing the exact RC SHA to `demo.1945.vn`.
- Existing baseline Unit failures and integration environment gate remain unresolved; no RC-owned regression remains after the capture fix.

Next allowed action:
- Obtain explicit staging deployment authorization, then run the existing verifier with this exact RC SHA and require identity/read-back parity before staging browser smoke.
