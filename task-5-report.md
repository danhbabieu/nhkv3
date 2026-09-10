# Task 5 Report — MCP invariants and Governance Admin Queue

**Date:** 2026-09-10

## Scope

Task 5 added the MCP/operator exposure regression test and recorded the Admin
Queue as an existing-Proposal lifecycle workspace. No UI, action, query or
writer implementation was changed. The unrelated `docs/agent-handoff/` and
`task-3-fix-2-report.md` paths were preserved and not staged.

## Evidence

- `McpGovernanceQueueExposureTest`: exact catalog names, operator Ability
  allowlist and Easy MCP patterns remain unchanged; Capture remains
  `nhk.capture.ingest`; no queue/Admin tool is cataloged; proposal apply stays
  internal-only.
- Three `GovernanceQueue*.php` sources contain no MCP registration and no
  generic WordPress/database writer.
- Control-plane and execution-state docs record query/action boundaries,
  capability + nonce + UUID/CAS security, canonical Governance/Eligibility/
  Controlled Apply delegation, per-item partial failure and unchanged Capture/
  MCP exposure.
- Canonical MCP docs were generated twice. Final manifest hash:
  `07a8b2ed7d24a7f9fd08286df603a06eb8992f0730aa29b8ff5986ab4b3d61da`.
  Normalized snapshots were deterministic; only `generated_at` differs.
  Generated output is ignored and was not deployed.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit --filter McpGovernanceQueueExposureTest` | 3 tests / 47 assertions; pass |
| focused relevant filter | 150 tests / 1,040 assertions; pass |
| `vendor/bin/phpunit --testsuite 'NHK Unit'` | 1,019 tests / 4,975 assertions; pass |
| `vendor/bin/phpunit --testsuite 'NHK Contract'` | 4 tests / 31 assertions; pass |
| `composer lint` | exit 0 |
| `composer validate --no-check-publish` | exit 0; existing missing-license warning |
| `git diff --check` | exit 0 |
| secret scan | no credential; one self-referential regex line in the plan |

No remote DB, SSH, deployment, publish or data mutation was attempted.
