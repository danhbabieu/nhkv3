# Scoped Single-Owner Public URL Reproject

## Goal

Extend the existing governed `nhk.public-url.audit` and
`nhk.public-url.reproject` lifecycle with an exact `owner_id` selector for
Easy MCP, while retaining any global maintenance behavior only behind the
existing service boundary.

## Constraints

- Reuse `PublicUrlMaintenanceService` and `PublicIdentityService`.
- Require `owner_id` in the exposed reproject schema; omission never means a
  global batch.
- Preserve `nhk_internal_content_operations` and `nhk_manage_public_urls` as
  independent guards.
- Do not create, rewrite or apply any live Public Identity or semantic data.
- Preserve unrelated worktree changes and stage only this task's files.

## Implementation checkpoints

- [ ] Add failing scoped service and MCP contract tests.
- [ ] Add exact-owner filtering, decision fingerprint/stale guard, scoped
  read-back evidence and fail-closed validation.
- [ ] Add optional scoped audit and required scoped reproject catalog/transport
  schemas without changing read-tool gates or internal allowlists.
- [ ] Pass profile-driven Clock Type route intent through the existing slug and
  Public Identity path services.
- [ ] Update active MCP contracts and execution state.
- [ ] Run focused, Unit, Contract, Integration (when available), lint,
  diff-check and deterministic documentation verification.
- [ ] Commit and push `feat: scope public url reproject by owner` if all gates
  pass.
