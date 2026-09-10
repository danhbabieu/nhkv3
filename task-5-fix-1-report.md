# Task 5 Fix 1 Report — MCP exposure invariants

**Date:** 2026-09-10

Replaced the duplicated full catalog, operator allowlist and pattern snapshots
in `McpGovernanceQueueExposureTest` with runtime-derived invariants. The test
now checks catalog surface/governed metadata against `SingleEntryPointPolicy`
and `McpToolCatalog`, derives catalog-to-Ability-map consistency from the
executable registries, excludes internal writers from operator exposure, and
independently pins `SingleEntryPointPolicy::CANONICAL_TOOL` to the literal
`nhk.capture.ingest` and its `canonical` surface. Queue source writer/MCP
registration regression coverage remains unchanged. No writer or runtime
implementation was added.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit --filter McpGovernanceQueueExposureTest` | 3 tests / 276 assertions; pass |
| focused contract filter (`McpGovernanceQueueExposureTest`, `McpContractTest`, `McpCapabilityManifestTest`, `AdminWorkbenchArchitectureTest`) | 44 tests / 883 assertions; pass |
| `composer lint` | exit 0 |
| `composer validate --no-check-publish` | exit 0; existing missing-license warning |
| `php -l` focused test | pass |
| `git diff --check` | exit 0 |
| `composer generate:mcp-docs` twice + manifest `cmp` | pass; identical snapshots; SHA-256 `6e0bcc4744fb9a1605fd3ba2d878a0d02c514d891491dd9133c08f44550b0cba` |

No report-count update was needed for `task-5-report.md`; this fix changes
assertion strategy only. Unrelated `docs/agent-handoff/` and
`task-3-fix-2-report.md` were preserved and not staged. No remote, database,
deployment or publish action was attempted.
