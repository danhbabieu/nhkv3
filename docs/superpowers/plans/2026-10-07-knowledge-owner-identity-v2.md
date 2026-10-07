# Knowledge Owner Identity V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the current Knowledge-owner identity implementation so pre-create, enrichment, Video provenance planning, duplicate audit and future reconciliation all obey one fail-closed identity law.

**Architecture:** Finish the existing `KnowledgeClaimIdentity`/`KnowledgeClaimIdentityResolution` boundary instead of introducing a global matcher. Route all Knowledge identity consumers through resolved owner-local packets, then add a Knowledge-specific reconciliation planner gate that can only emit a bounded plan after fresh identity, revision, dependency and lifecycle checks.

**Tech Stack:** PHP 8+, WordPress plugin runtime, PHPUnit, existing Knowledge/Video repositories, `CommandCanonicalizer`, Graph read services and HMAC/canonical fingerprint patterns already present in NHK V3.

**Spec:** `docs/superpowers/specs/2026-10-07-knowledge-owner-identity-v2-design.md`

## Global Constraints

- Knowledge remains the sole owner of Claim identity; no global matcher or new semantic owner.
- `UNRESOLVED` and `CONFLICTING` identities never compare equivalent and always remain `REVIEW_REQUIRED`.
- Video provenance identity is `canonical subject + proposition class + platform + external_video_id`.
- `canonical_video_id` is only a read-only lookup aid and never a second Claim identity.
- Source locator belongs to Source identity and is excluded from new Knowledge provenance Claim stable keys.
- Existing Claims are never rekeyed.
- Audit is read-only with `apply=false`; no staging/production mutation, recovery, Côn hoa thị work or 52-cluster reconciliation.
- Do not bypass `KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` through decomposed create/retire operations.

## Review Focus

- A legacy Claim has a locator-derived stable key but matches the V2 Video identity: reuse the legacy key without rekeying (Task 2).
- Two Video provenance rows omit Video identity: produce separate review findings, never one equivalent group (Task 1).
- A canonical Video UUID is supplied without a readable platform/external ID mapping: block as unresolved (Task 1).
- A Knowledge audit cluster is definite-looking but lacks fresh revisions/dependency topology: return no executable plan (Task 3).
- A retired equivalent Claim is found: return review/reactivation path, never `CREATE_NEW` (Task 2).

## File Map

- Modify `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentity.php`: make the public identity/context helpers delegate to one resolved packet law and normalize ordinary propositions consistently.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php`: use the resolved Video provenance packet for new Claim stable keys and preserve Source locator only in Source identity.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgePreCreateResolver.php` and `KnowledgeEnrichmentPlanner.php`: ensure reuse/review decisions consume the same identity resolution and preserve legacy stable keys.
- Create `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeDuplicateReconciliationPlanner.php`: owner-specific, read-only, revision/dependency-bound reconciliation planning gate.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`: use the canonical identity reader consistently and pass Knowledge candidates through the owner-specific gate without enabling mutation.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php` and `KnowledgeRepairPreviewService.php`: reject stale or incomplete Knowledge reconciliation bindings before any future retire/update proposal can become eligible.
- Modify `public/wp-content/plugins/nhk-core/src/Plugin.php`: inject the Video identity reader and Knowledge reconciliation planner into the existing production composition where required; do not expose a direct writer.
- Modify focused tests under `public/wp-content/plugins/nhk-core/tests/Unit/`: identity, pre-create, enrichment, Video planner, audit, repair preview and proposal eligibility regressions.
- Update `docs/architecture/V3_EXECUTION_STATE.md` only after verification/live read-only audit, recording no data mutation and any runtime access limitation.

### Task 1: Canonical Knowledge identity boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentity.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentityResolution.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeClaimIdentityTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SystemWideDuplicateAuditTest.php`

**Interfaces:**
- Consumes: `KnowledgeClaimIdentity::resolveInput(string, array, ?VideoIdentityReader): KnowledgeClaimIdentityResolution`, `resolveClaim`, `resolveAuditRow`.
- Produces: stable `policyVersion()`, `fingerprint()`, `equivalentTo()` semantics and context helpers that cannot create equivalent unresolved packets.

- [ ] **Step 1: Write failing identity tests** for ordinary proposition normalization, two missing Video referents, canonical UUID read-only resolution, conflicting Video signals, and audit rows carrying equivalent URL/source fields.
- [ ] **Step 2: Run the focused tests and verify RED** with failures showing the legacy context/helper or grouping behavior.
- [ ] **Step 3: Implement the minimal boundary fix**: make `contextForInput`, `contextForClaim` and `contextForAuditRow` derive from the same resolved packet; use the contract proposition class for Video; ensure unresolved packets include a unique non-equivalence state/fingerprint rather than a shared `unresolved` value; pass the configured `VideoIdentityReader` into audit identity resolution.
- [ ] **Step 4: Run the focused identity/audit tests and verify GREEN**; assert unresolved/conflicting rows are `REVIEW_REQUIRED` and never `DEFINITE_DUPLICATE`.
- [ ] **Step 5: Commit** with `git add` of the identity/audit source and tests, then `git commit -m "fix: unify knowledge identity resolution"`.

### Task 2: Pre-create, enrichment and Video provenance stable-key alignment

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgePreCreateResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` if constructor wiring is needed
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgePreCreateResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php`

**Interfaces:**
- Consumes: Task 1 identity resolution and the existing `VideoIdentityReader` injection.
- Produces: pre-create/enrichment decisions that reuse the same Video+subject identity regardless of wording, request key or equivalent locator; new planner Claim keys derived only from the resolved identity packet.

- [ ] **Step 1: Write failing regression tests** for ordinary Claim reuse across Source/request-key changes; same Video/same subject with different wording; equivalent locator; different Video; different subject; missing/conflicting Video identity; retired equivalent; and legacy stable-key reuse without rekeying.
- [ ] **Step 2: Run the focused Knowledge/Video tests and verify RED** on wording/locator reuse and stable-key assertions.
- [ ] **Step 3: Implement the minimal changes**: use resolved identity equivalence for Video provenance in pre-create and enrichment; keep retired matches review-only; have `CaptureVideoProvenancePlanner` derive new Claim stable keys from the canonical identity packet while leaving Source key locator-based; preserve the existing legacy-key reuse path.
- [ ] **Step 4: Run all three focused test files and verify GREEN**, including proof that same Video/different wording reuses and different Video/subject does not.
- [ ] **Step 5: Commit** with `git add` of the three production files and tests, then `git commit -m "fix: align knowledge video provenance identity"`.

### Task 3: Owner-specific reconciliation planning safety

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeDuplicateReconciliationPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeRepairPreviewService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeDuplicateReconciliationPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeRepairPreviewServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`

**Interfaces:**
- Consumes: `KnowledgeRepository`, `EvidenceRepository`, optional Graph read service, `KnowledgeClaimIdentity`, audit candidate fields, current Claim revisions and dependency snapshots.
- Produces: `KnowledgeDuplicateReconciliationPlanner::plan(array $candidate): array` returning a read-only plan with `status`, `apply`, `commands`, `identity_policy`, `identity_fingerprint`, `record_revisions`, `dependency_fingerprint` and blockers. `commands` must be empty unless all fresh checks pass; no executor is added.

- [ ] **Step 1: Write failing planner tests** proving `POSSIBLE_DUPLICATE`, `UNRESOLVED`, `CONFLICTING`, stale policy, stale identity fingerprint, stale revisions, changed dependency topology, inactive/invalid Evidence and unequal identities all return `REVIEW_REQUIRED`, `apply=false`, and no commands.
- [ ] **Step 2: Run the planner/repair/eligibility tests and verify RED** because the owner-specific planner and binding checks are incomplete.
- [ ] **Step 3: Implement the planner** to require exactly two Knowledge IDs, freshly hydrate both Claims, resolve both identities with the same policy, require `RESOLVED` plus equal fingerprints, collect current revisions and Graph/Evidence dependency topology, compute a canonical dependency fingerprint, and emit only descriptive revision-bound retire/move candidates after all checks pass. Never call a writer.
- [ ] **Step 4: Strengthen repair preview and proposal eligibility** so future retire/update proposals reject absent or stale policy, identity, revision, dependency and classification bindings, and retain `KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` as a hard blocker.
- [ ] **Step 5: Run the focused planner, repair and eligibility tests and verify GREEN**; confirm the valid path remains read-only and no manual decomposition is accepted.
- [ ] **Step 6: Commit** with `git add` of planner, governance/repair source and tests, then `git commit -m "fix: gate knowledge reconciliation plans"`.

### Task 4: Production composition and audit output contract

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/SystemWideDuplicateAuditHandler.php` only if response fields need propagation
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SystemWideDuplicateAuditTest.php`
- Test: relevant plugin/composition or MCP contract test discovered during implementation

**Interfaces:**
- Consumes: Task 1 identity boundary and Task 3 owner-specific planner.
- Produces: audit responses whose Knowledge reconciliation candidates are explicitly read-only and cannot be mistaken for executable mutation commands; unchanged cursor/pagination semantics.

- [ ] **Step 1: Write failing audit tests** for owner-specific candidate blocking, stable identity-policy/fingerprint propagation, `apply=false`, and unique multi-page clusters.
- [ ] **Step 2: Run the focused audit/composition tests and verify RED**.
- [ ] **Step 3: Wire the existing production composition** with the canonical Video reader and planner, preserve optional test constructors where established, and make audit candidates carry planner status/blockers without adding an apply endpoint or direct writer.
- [ ] **Step 4: Run focused audit/MCP tests and verify GREEN**, including cursor continuation and `mutated=false`.
- [ ] **Step 5: Commit** with `git add` of composition/audit source and tests, then `git commit -m "fix: bind knowledge audit to reconciliation safety"`.

### Task 5: Full verification, execution-state evidence and live read-only audit

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Read: `docs/architecture/V2_V3_PARITY_MATRIX.md`
- Read/verify: Constitution and all relevant active contracts already named in `AGENTS.md`

- [ ] **Step 1: Run PHP lint** over every changed PHP file; expected: no syntax errors.
- [ ] **Step 2: Run focused unit tests** for identity, pre-create, enrichment, Video planner, audit, planner, repair and eligibility; expected: all pass.
- [ ] **Step 3: Run the full NHK unit suite using the repository-supported memory/runtime command**; record pre-existing failures separately and do not hide them.
- [ ] **Step 4: Run `git diff --check` and a secret review**; expected: clean diff and no credentials/secrets added.
- [ ] **Step 5: If the authorized TEST runtime is reachable and documentation/build identity matches, run the 1,176-row Knowledge duplicate audit read-only** with `apply=false`; verify no mutation, no reappearance of the eight false definite duplicates, and correct unique pagination cluster count. Otherwise record the exact fail-closed runtime limitation.
- [ ] **Step 6: Update `V3_EXECUTION_STATE.md`** with commit range, tests, live audit result, migration status (`none`), staging mutation (`none`) and next recovery-plan gate; do not claim deployment or live verification without evidence.
- [ ] **Step 7: Commit** the execution-state evidence with `git add docs/architecture/V3_EXECUTION_STATE.md && git commit -m "docs: record knowledge identity v2 verification"`.

## Completion Evidence

Before claiming completion, report exactly:

- `STATUS`
- `CURRENT_REQUIREMENTS_SYNTHESIS`
- `IDENTITY_MODEL`
- `VIDEO_REFERENT_POLICY`
- `UNRESOLVED_POLICY`
- `PROPOSITION_POLICY`
- `STABLE_KEY_POLICY`
- `RECONCILIATION_SAFETY`
- `FILES_CHANGED`
- `TEST_RESULTS`
- `LIVE_READONLY_AUDIT`
- `MIGRATION_REQUIRED`
- `STAGING_DATA_MUTATION`
- `COMMIT`
- `NEXT`

The next step after a verified deployment and live audit is a fresh read-only
recovery plan for the eight incident records. Do not apply that plan in this
work item.
