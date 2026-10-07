# Knowledge Identity V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Knowledge pre-create, enrichment, duplicate audit and reconciliation planning share one fail-closed identity law for ordinary Claims and Video provenance Claims.

**Architecture:** Preserve `KnowledgeClaimIdentity` as the Knowledge-owner boundary, adding an explicit resolution result with status, canonical packet, policy version and fingerprint. A read-only Video lookup adapter resolves canonical Video UUIDs to the stable `platform + external_video_id` referent; Source locator remains Source identity. Audit and reconciliation consume the same result and cannot promote uncertain candidates to executable mutation.

**Tech Stack:** PHP 8+, PHPUnit 11, existing NHK V3 domain repositories, WordPress/WPDB audit readers, existing Governance proposal/reconciliation boundaries.

**Spec:** `docs/superpowers/specs/2026-10-07-knowledge-identity-v2-design.md`

## Global Constraints

- Knowledge remains the sole owner of Claim identity; do not create a global duplicate matcher or new semantic owner.
- `UNRESOLVED` and `CONFLICTING` never compare equivalent and always produce `REVIEW_REQUIRED`.
- Video identity is `platform + external_video_id`; `canonical_video_id` is only a read-only owner reference.
- Do not rekey existing Claims; reuse legacy equivalent Claims with their existing stable keys.
- Audit remains read-only with `apply=false`; no staging/production mutation, incident recovery, Côn hoa thị work or 52-cluster reconciliation.
- Do not bypass `KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` by manually decomposing Evidence and Claim lifecycle commands.
- Preserve unrelated existing Capture worktree changes and never include them in Knowledge Identity commits.

## Review Focus

- Missing Video referents must remain separate review items rather than sharing an unresolved sentinel; covered by the identity-core regression.
- A canonical Video UUID must resolve to the same external referent before and after Apply; covered by the Video lookup regression.
- Legacy provenance stable keys must be reused without rekeying; covered by the pre-create/planner regression.
- A stale dependency topology must block reconciliation even when Claim identity still matches; covered by the reconciliation safety regression.
- Audit pagination must not turn uncertain rows into executable plans; covered by the audit projection regression.

---

### Task 1: Add the canonical Knowledge identity resolution boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentityResolution.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/VideoRepositoryIdentityReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Video/VideoIdentityReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentity.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeClaimIdentityTest.php`

**Interfaces:**
- `VideoIdentityReader::findVideoIdentity(string $canonicalVideoId): ?array` returns `['canonical_video_id' => string, 'platform' => string, 'external_video_id' => string]` or `null` and performs no writes.
- `KnowledgeClaimIdentity::resolveInput(string $claimType, array $provenance, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution`.
- `KnowledgeClaimIdentity::resolveClaim(KnowledgeClaim $claim, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution`.
- `KnowledgeClaimIdentity::resolveAuditRow(array $row, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution`.
- `KnowledgeClaimIdentityResolution::status(): string`, `packet(): array`, `policyVersion(): string`, `fingerprint(): string`, `reasonCodes(): array`, and `equivalentTo(KnowledgeClaimIdentityResolution $other): bool`.
- Existing `contextForInput`, `contextForClaim`, `contextForAuditRow`, and `key` remain as compatibility helpers but delegate to the resolved packet only when status is `RESOLVED`.

- [ ] **Step 1: Write failing tests for identity status and packets.** Test ordinary resolved identity; ordinary missing subject/facet/scope/proposition; two missing Video referents; canonical UUID lookup; lookup failure; conflicting UUID versus external identity; and deterministic policy/fingerprint output.
- [ ] **Step 2: Run the focused test file and verify the new tests fail for the missing resolution API/status behavior.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeClaimIdentityTest.php`

Expected: FAIL because the explicit resolution result and Video reader contract do not yet exist.

- [ ] **Step 3: Implement `KnowledgeClaimIdentityResolution` and `VideoIdentityReader` with immutable status, packet, reasons and fingerprint fields.** Use the existing canonicalization conventions and keep unresolved packets non-equivalent even when their diagnostics are structurally identical.
- [ ] **Step 4: Implement `VideoRepositoryIdentityReader` over the existing `VideoRepository`.** `findVideoIdentity` reads the canonical Video by UUID and returns only its canonical UUID, platform and external ID; it never creates, updates or retires a Video.
- [ ] **Step 5: Implement `KnowledgeClaimIdentity::resolveInput`, `resolveClaim` and `resolveAuditRow`.** Ordinary Claims use subject/facet/scope/type/proposition; Video provenance uses subject/proposition class/platform/external ID; canonical UUID-only input uses the injected read-only reader; locator and Source metadata never enter Claim identity.
- [ ] **Step 6: Run the focused identity tests and verify they pass.**
- [ ] **Step 7: Commit the identity boundary and tests.**

Commit: `feat: add fail-closed Knowledge identity resolution`

### Task 2: Align pre-create, enrichment and Video provenance stable keys

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgePreCreateResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgePreCreateResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php`

**Interfaces:**
- `KnowledgePreCreateResolver` and `KnowledgeEnrichmentPlanner` consume the same `KnowledgeClaimIdentity` resolution boundary and return `REVIEW_REQUIRED` for `UNRESOLVED`/`CONFLICTING` before candidate comparison.
- `CaptureVideoProvenancePlanner::plan(...)` derives a new provenance Claim key from `KnowledgeClaimIdentityResolution::packet()` and excludes `$locator`; Source and Evidence keys continue to include their own Source/support identity.
- Existing Claim stable keys remain accepted as legacy candidates and are never rewritten.

- [ ] **Step 1: Add failing regressions for ordinary Claim reuse across different Sources and request keys.** Assert one canonical Claim candidate and no create-new result.
- [ ] **Step 2: Add failing regressions for Video provenance.** Cover same Video/same subject with same wording, changed wording, equivalent locator; different Video; different subject; missing Video; conflicting Video; legacy stable-key reuse; and retired equivalent review.
- [ ] **Step 3: Run the focused resolver/planner tests and verify the new cases fail against wording-based and locator-based behavior.**
- [ ] **Step 4: Inject the shared identity boundary into pre-create/enrichment and replace direct context/text equality with status-aware identity comparison.** Keep lexical overlap as review-only candidate discovery and preserve Source/Evidence identity semantics.
- [ ] **Step 5: Update `CaptureVideoProvenancePlanner` to derive only new Claim keys from the resolved Knowledge identity packet.** Keep the source locator in Source and Evidence payloads; if the identity is not resolved, return `REVIEW_REQUIRED` without creating a candidate key that could collide.
- [ ] **Step 6: Run the focused resolver/planner tests and verify all required reuse/distinct/review cases pass.**
- [ ] **Step 7: Commit the pre-create, enrichment and planner changes with tests.**

Commit: `feat: align Knowledge reuse with canonical identity`

### Task 3: Make duplicate audit use owner-local Knowledge identity

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SystemWideDuplicateAuditTest.php`

**Interfaces:**
- Knowledge audit grouping calls `KnowledgeClaimIdentity::resolveAuditRow(...)` and includes status, policy version and fingerprint in `identity_signals`.
- Only `RESOLVED` rows can produce an equivalence group. Unresolved/conflicting rows become `REVIEW_REQUIRED` diagnostics and never share a duplicate cluster through a generic unresolved key.
- `reconciliation_candidates` remains read-only and contains `apply=false` for every item.

- [ ] **Step 1: Add failing audit tests for missing/conflicting Video identity and wording variation.** Assert no false definite duplicate, explicit review status, and no unresolved-sentinel collision.
- [ ] **Step 2: Add a failing audit test proving ordinary Claim identity is independent of Source locator/request identity while different deterministic propositions remain distinct or review-only.**
- [ ] **Step 3: Run the focused audit tests and verify they fail under current grouping.**
- [ ] **Step 4: Replace Knowledge grouping keys with the shared resolution result and preserve existing owner boundaries, cursor behavior and 5,000-row safety bound.** Do not alter other owner rules.
- [ ] **Step 5: Run focused audit, cursor and MCP projection tests; verify the output remains read-only and pagination remains unique.**
- [ ] **Step 6: Commit the audit integration and tests.**

Commit: `fix: make Knowledge duplicate audit fail closed`

### Task 4: Add guarded reconciliation planning preconditions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeRepairPreviewService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeRepairPreviewServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`

**Interfaces:**
- The planner accepts the candidate IDs, expected Claim revisions, expected identity policy/version/fingerprint and dependency fingerprint, then returns either a non-executable review result or a governed plan bound to those exact values.
- A governed plan is executable only when a fresh read-only verification returns `RESOLVED`, equal canonical identity, unchanged revisions, unchanged dependency topology/fingerprint and valid Evidence lifecycle.
- `POSSIBLE_DUPLICATE`, `UNRESOLVED`, `CONFLICTING`, stale revisions, stale policy and stale dependencies never return an automatic retire/move command.

- [ ] **Step 1: Write failing tests against `KnowledgeRepairPreviewService::preview` and `ProposalEligibilityService::check`.** Pin the exact existing review-required code path and the no-decomposition invariant; do not add a parallel writer.
- [ ] **Step 2: Run the focused reconciliation tests and verify they fail because the current preview/eligibility path does not yet bind the shared identity policy, identity fingerprint and dependency fingerprint.**
- [ ] **Step 3: Implement fresh identity, revision, dependency topology and Evidence lifecycle verification.** Bind policy/version, identity fingerprint, revisions and dependency fingerprint into the plan fingerprint; reject drift before any executable command is emitted.
- [ ] **Step 4: Run the focused reconciliation tests and verify all stale/uncertain cases fail closed while a fully resolved unchanged pair remains planning-eligible only through the existing governed lifecycle.**
- [ ] **Step 5: Commit the reconciliation safety changes and tests.**

Commit: `fix: bind Knowledge reconciliation plans to fresh identity`

### Task 5: Contract documentation, execution-state checkpoint and verification

**Files:**
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/SYSTEM_WIDE_EXISTING_DUPLICATE_AUDIT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test/verification artifacts: no production data files; preserve read-only audit output outside the repository unless explicitly approved for evidence

- [ ] **Step 1: Run the focused Knowledge identity, pre-create, enrichment, Video planner, audit and reconciliation tests together.** Record test counts and failures.
- [ ] **Step 2: Run `composer lint`, `git diff --check`, and the applicable secret review.** Confirm no local env, token, key or unrelated Capture changes are staged.
- [ ] **Step 3: Run the full configured PHPUnit suite and report existing unrelated baseline failures separately; do not hide or downgrade them.**
- [ ] **Step 4: Update the two ACTIVE contracts with the implemented identity status/fingerprint and read-only reconciliation preconditions, without introducing new semantic vocabulary or mutation authority.**
- [ ] **Step 5: Update `V3_EXECUTION_STATE.md` with the implementation checkpoint, test evidence, no-migration decision and no-data-mutation statement.**
- [ ] **Step 6: Commit the documentation/checkpoint changes separately.**
- [ ] **Step 7: Only after deployment identity and documentation checkpoint verification, run the 1,176-row Knowledge audit through the existing read-only MCP boundary.** Record unique pagination/clusters, absence of the eight false definite duplicates and `data_mutation=false`; do not apply recovery or reconciliation.

Commit: `docs: record Knowledge identity v2 verification`

## Plan self-review

- **Spec coverage:** The identity boundary is Task 1; owner-local profiles and
  stable keys are Task 2; audit behavior is Task 3; reconciliation safety is
  Task 4; verification and live read-only audit are Task 5. Scope exclusions and
  no-migration policy are global constraints and the final checkpoint.
- **Step scan:** Each implementation task follows failing test → red run →
  minimal implementation → green run → commit. The reconciliation planner is
  deliberately discovered from the existing owner boundary before editing so
  the plan does not invent a second writer.
- **Type consistency:** Task 1 produces `KnowledgeClaimIdentityResolution` and
  `VideoIdentityReader`; Tasks 2–4 consume that result and preserve the existing
  repositories and Governance lifecycle.
- **Review focus coverage:** Every listed focus condition is pinned to a test
  in Tasks 1–4; Task 5 verifies the complete suite and live read-only behavior.
- **Proportion:** The plan introduces one value object and one read-only reader
  contract, then integrates them at the four existing consumers. It does not
  prescribe unrelated refactors or data migration.
