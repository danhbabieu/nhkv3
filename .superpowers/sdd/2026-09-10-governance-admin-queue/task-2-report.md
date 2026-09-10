# Task 2 report — canonical Governance runtime port

Date: 2026-09-10

## Outcome

Implemented the reusable canonical Governance action seam and extracted the
existing full Governance runtime composition from Plugin bootstrap.

## Changes

- Added `GovernanceActionPort` with the six required read/lifecycle methods.
- Added `CanonicalGovernanceActionPort`, a thin delegator to the existing
  `ProposalRepository`, `GovernanceService`, `ProposalEligibilityService` and
  `ControlledApplyService`. It has no SQL and makes no lifecycle decisions.
- Added readonly-property `GovernanceRuntime` containing the canonical
  repository, Governance service, eligibility service and Controlled Apply
  service.
- Added `GovernanceRuntimeFactory::fromWordPress(object $wpdb)`, preserving the
  existing Authority, Graph, Media, Video, Knowledge, dependency validator,
  operation executor, collector facet executor, historical evidence,
  Governance audit, transaction manager, eligibility and canonical read-back
  wiring.
- Replaced the duplicated Governance construction in the Plugin REST bootstrap
  with the factory result. Existing REST routes, operation vocabulary, state
  token/CAS/idempotency behavior and canonical apply semantics remain owned by
  their existing services.
- Added TDD-focused unit coverage for delegation, canonical apply read-back,
  runtime construction and the no-SQL/no-direct-persistence port boundary.

## Verification

- RED observed first: `GovernanceActionPortTest` failed with the expected
  missing class/interface errors.
- `vendor/bin/phpunit --filter GovernanceActionPortTest`: PASS, 6 tests / 19
  assertions.
- `vendor/bin/phpunit --filter
  'GovernanceActionPortTest|GovernanceCoreTest|GovernanceApplyContractTest'`:
  PASS, 38 tests / 114 assertions.
- Governance/Application/Infrastructure PHP lint: PASS.
- `composer lint`: PASS.
- `git diff --check`: PASS.
- Task 2 source secret scan: no matches.

The PHPUnit run retains the repository's existing warnings/deprecations; no
new test failure was introduced. Integration/database, UI, queue action
service, MCP and deployment work were intentionally not performed.

## Scope protection

Only Task 2 implementation/test/report/checkpoint changes are staged for the
commit. Existing concurrent changes, including the pre-existing untracked
`docs/agent-handoff/` content, remain untouched and unstaged.
