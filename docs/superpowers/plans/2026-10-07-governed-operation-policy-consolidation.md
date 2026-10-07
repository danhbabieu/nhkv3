# Governed Operation Policy Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make one explicit, read-only Governance operation policy the source for generic operation metadata while preserving owner-specific semantic gates and production behavior.

**Architecture:** Add a policy value object/registry keyed by exact `(entity_type, operation)` pairs. Use it behind compatibility, descriptor, staging scope, generic Capture admission, eligibility and production checks; leave Video, Media, Relation and Authority semantic admission in their existing owner services. Record the complete matrix and evidence without performing any apply or data mutation.

**Tech Stack:** PHP 8.x, PHPUnit, PSR-4 plugin autoloading, WordPress plugin source, Markdown architecture evidence.

**Spec:** `docs/superpowers/specs/2026-10-07-governed-operation-policy-consolidation-design.md`

## Global Constraints

- No semantic data mutation, proposal apply, incident recovery, deployment or production/staging write.
- Do not touch incident Proposal `01a114af-f861-7837-9441-1effb25699f7` except read-only inspection.
- Do not touch Côn hoa thị or broaden owner semantics.
- Staging and production are separate admission boundaries; production never consumes staging packets.
- Unknown operations fail closed; executor compatibility is not staging authorization.
- Owner-specific semantic checks remain outside generic policy.
- Read `docs/architecture/V3_EXECUTION_STATE.md` before each checkpoint and update it after the implementation checkpoint.

## Review Focus

- An executor-supported operation with no Capture owner flow must be explicitly staging-denied; test every such entry, especially Video retire/reactivate and Authority merge.
- `create`/`ingest` must use revision ZERO while existing-object lifecycle operations require the current revision; test issuance and verification against the same descriptor.
- Relation and MediaUsage special operations must not inherit ordinary entity revision rules; test their explicit special policies.
- Unknown entity/operation pairs must fail closed in compatibility, scope issuance, eligibility and production admission.
- Production policy must remain unchanged when staging policy changes; test production admission independently from signed-scope paths.

### Task 1: Capture the current operation matrix and lock the canonical policy contract

**Files:**
- Create: `docs/architecture/GOVERNANCE_OPERATION_POLICY_MATRIX_2026-10-07.md`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedOperationPolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedOperationPolicyRegistry.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedOperationPolicyRegistryTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyOperationRegistry.php`

**Interfaces:**
- `GovernedOperationPolicy` exposes exact metadata fields: `entityType`, `operation`, `operationFamily`, `lifecycleClass`, `revisionPolicy`, `targetBinding`, `captureStagingAllowed`, `productionAllowed`, and `requiredCapabilities`.
- `GovernedOperationPolicyRegistry::find(string $entityType, string $operation): ?GovernedOperationPolicy` returns null for unknown pairs.
- `GovernedOperationPolicyRegistry::all(): array` returns every explicit supported policy entry.
- `GovernedOperationPolicyRegistry::supports(string $entityType, string $operation): bool` is the compatibility implementation used by `ControlledApplyOperationRegistry`.

- [ ] **Step 1: Write failing registry tests** for every requested owner group: Authority, Knowledge, Source, Evidence, Video, Media/MediaUsage, Relation/Graph and `wp_post`; assert explicit families, lifecycle classes, revision policies, staging decisions and production decisions.
- [ ] **Step 2: Run the focused test**

  Run: `vendor/bin/phpunit --filter GovernedOperationPolicyRegistryTest`

  Expected: FAIL because the policy classes and entries do not yet exist.

- [ ] **Step 3: Implement the immutable policy model and explicit registry** using one entry per executor-supported pair. Preserve current compatibility vocabulary, represent staging denial explicitly, and avoid a fallback that treats unknown entities as Authority.
- [ ] **Step 4: Make `ControlledApplyOperationRegistry` delegate to the canonical registry** without changing its public `OperationCompatibility` interface.
- [ ] **Step 5: Write the matrix document** from the inspected executor, registry and admission behavior. For each operation include support, family, lifecycle, revision rule, scope issuance, staging/production admission, capabilities and owner preconditions; mark every mismatch as drift.
- [ ] **Step 6: Run the focused tests and lint**

  Run: `vendor/bin/phpunit --filter 'GovernedOperationPolicyRegistryTest|GovernanceApplyContractTest'` and `php -l` on the two new/changed PHP files.

  Expected: PASS and no syntax errors.

- [ ] **Step 7: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedOperationPolicy.php public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedOperationPolicyRegistry.php public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyOperationRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedOperationPolicyRegistryTest.php docs/architecture/GOVERNANCE_OPERATION_POLICY_MATRIX_2026-10-07.md
  git commit -m "feat: add canonical governed operation policy"
  ```

### Task 2: Route descriptor, scope issuance and generic Capture admissions through policy

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingOperationDescriptor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/CaptureDependencyStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/CaptureChildRelationStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/OperationScopedStagingGuard.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaMetadataStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityStagingAdmission.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StagingOperationDescriptorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureDependencyStagingAdmissionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityStagingAdmissionTest.php`

**Interfaces:**
- Descriptor family and expected revision are read from `GovernedOperationPolicyRegistry`; `StagingOperationDescriptor::family()` remains a compatibility method delegating to policy and returns an empty string for unknown pairs.
- Scope issuance rejects policy entries with `captureStagingAllowed=false` and unknown pairs with a fail-closed reason.
- Generic admissions query policy support/permission, then retain existing owner-specific checks.

- [ ] **Step 1: Add failing regressions** for Knowledge/Source/Evidence `reactivate`, explicit staging denial, unknown operations, and identical issuance/verification revision metadata.
- [ ] **Step 2: Run focused staging tests** and confirm the new regressions fail before implementation.
- [ ] **Step 3: Refactor descriptor and scope verifier** to use policy family, lifecycle and revision metadata; remove duplicated generic operation/family/revision arrays while leaving payload normalization intact.
- [ ] **Step 4: Refactor Capture admissions and the scoped guard** to consult explicit policy decisions; do not remove Video identity, Media binding, relation predicate/endpoint or Authority plan checks.
- [ ] **Step 5: Run focused staging tests**

  Run: `vendor/bin/phpunit --filter 'StagingOperationDescriptorTest|StagingAcceptanceScopeVerifierTest|CaptureDependencyStagingAdmissionTest|VideoStagingAdmissionTest|AuthorityStagingAdmissionTest|MediaBindingStagingAdmissionTest'`

  Expected: PASS with no staging-denied operation receiving a usable scope.

- [ ] **Step 6: Commit** with message `refactor: use canonical policy for staging admission`.

### Task 3: Align eligibility and production generic checks without changing owner semantics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ProductionGovernanceAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityProposalExecutor.php` only where a compatibility lookup or generic revision check is duplicated
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceApplyContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceOperationPolicyContractTest.php`

**Interfaces:**
- Eligibility consumes the registry for generic support, creation classification and revision policy; owner-specific checks remain in their current branches.
- Production admission consumes `productionAllowed` and generic required capabilities; it never checks or accepts staging scope packets.
- The executor continues to reject unregistered pairs before dispatch and does not gain new owner operations from this refactor.

- [ ] **Step 1: Write the full cross-layer invariant test**: every staging-allowed registry entry is scope-issuable, verifiable, understood by admission and executor-supported; every staging-denied entry fails scope issuance; every unknown entry fails closed.
- [ ] **Step 2: Add owner matrix regression providers** for Knowledge, Source, Evidence lifecycle operations; all registered Video operations; Relation create/retire/reactivate/replace; Media/MediaUsage special operations; `wp_post` binding operations; and current Authority lifecycle operations.
- [ ] **Step 3: Run the contract tests** and verify they fail on remaining duplicated generic logic.
- [ ] **Step 4: Refactor eligibility, production admission and any executor-only generic decisions** to query the canonical registry while preserving owner-specific semantic gates and existing production capability behavior.
- [ ] **Step 5: Run focused Governance tests**

  Run: `vendor/bin/phpunit --filter 'GovernanceOperationPolicyContractTest|ProposalEligibilityServiceTest|GovernanceApplyContractTest|AuthorityProposalExecutor'`

  Expected: PASS, including the historical Knowledge reactivation regression.

- [ ] **Step 6: Commit** with message `test: enforce governed operation policy invariants`.

### Task 4: Verify the incident state, update execution evidence and complete quality gates

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/GOVERNANCE_OPERATION_POLICY_MATRIX_2026-10-07.md` if final evidence changes
- Test: relevant Governance Unit tests and existing guarded integration tests only if their documented bootstrap is available

**Interfaces:**
- Read-only incident inspection reports Proposal `01a114af-f861-7837-9441-1effb25699f7` state, entity/operation, eligibility and target revision binding without calling apply.
- Execution state records `POLICY_DRIFT_FOUND`, canonical model, owner-specific exceptions, changed files, tests, `PRODUCTION_BEHAVIOR_CHANGED: NO` unless proven otherwise, `SEMANTIC_DATA_MUTATION: NONE`, commit and `DEPLOYED_REVISION: NOT DEPLOYED`.

- [ ] **Step 1: Read current execution state again** and append a dated checkpoint with the completed matrix and exact drift findings.
- [ ] **Step 2: Perform read-only incident verification** through repository/runtime inspection available locally; do not submit, approve, apply, recover or mutate the Proposal.
- [ ] **Step 3: Run the complete relevant Unit suite**

  Run: `vendor/bin/phpunit --testsuite Unit` (or the repository's configured equivalent).

  Expected: all relevant tests pass; unrelated pre-existing failures are recorded distinctly.

- [ ] **Step 4: Run quality gates**

  Run: `git diff --check`; PHP lint for every changed PHP file; repository secret review; and the documented guarded integration command only if the required test runtime/bootstrap is present.

  Expected: no whitespace errors, syntax errors or newly introduced secret findings.

- [ ] **Step 5: Commit** with message `docs: record governed operation policy consolidation`.
- [ ] **Step 6: Report final status** with matrix location, every mismatch, policy model, owner-specific exceptions, files, tests, production behavior, semantic mutation, commits, deployment status and incident state.

## Execution order

Execute Tasks 1–4 sequentially. Each task ends with its focused tests and a
logical commit. Do not start a later task when the preceding contract test is
red unless the failure is explicitly documented as pre-existing and unrelated.
