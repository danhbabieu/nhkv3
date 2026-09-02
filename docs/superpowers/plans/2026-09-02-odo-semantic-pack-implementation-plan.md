# Odo Semantic Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Normalize Odo semantic identity to the `odo` namespace, safely merge confirmed duplicates, retire Odo 35 from the active pack, complete Registry-valid relationships and Knowledge shells, prepare Media/Video requirements, and reconcile existing Odo WordPress posts without breaking UUIDs, URLs, revisions or existing governed data.

**Architecture:** Treat `ODO_SEMANTIC_REFERENCE_PACK.md` and `ODO_INGEST_MANIFEST.yaml` as a data/reference pack subordinate to the NHK V3 Constitution and runtime Registry. Execute changes only through the existing Governance → Proposal → Controlled Apply boundary, with read-only inventory first, revision/idempotency checks, and read-back verification after each phase.

**Tech Stack:** NHK V3 / WordPress / PHP / MySQL / MCP abilities / Authority / Knowledge / Graph / Governance / Controlled Apply.

**Spec:** `docs/semantic-packs/odo/ODO_SEMANTIC_REFERENCE_PACK.md`

## Global Constraints

- Read `AGENTS.md`, `docs/constitution/READ_FIRST.md`, canonical V3 Constitution and relevant runtime contracts before mutation.
- Canonical display name is `Odo`.
- New Odo-owned stable keys must use `odo`; no new legacy-form stable key may be created.
- Existing UUIDs are preserved during rekey when runtime supports it.
- No hard delete merely for namespace normalization.
- WordPress remains Editorial Authority for Article title/body/revisions/permalink.
- No new entity type or predicate may be invented outside runtime Registry.
- All semantic mutations must pass Governance / Controlled Apply.
- No direct SQL, taxonomy, ACF or postmeta semantic fallback.
- Odo 35 is excluded from the active pack but requires reference audit before retirement.
- Post 55 must keep its current WordPress identity, body, slug and URL.
- Media/Video placeholders are created only if their current contracts explicitly support placeholders without fake files/URLs.

---

### Task 1: Install and validate the Odo reference pack

**Files:**
- Create: `docs/semantic-packs/odo/ODO_SEMANTIC_REFERENCE_PACK.md`
- Create: `docs/semantic-packs/odo/ODO_INGEST_MANIFEST.yaml`
- Test/validation: repository documentation/manifest validation commands discovered from `AGENTS.md` and project scripts.

**Interfaces:**
- Consumes: V3 Constitution + runtime registries/contracts.
- Produces: approved Odo reference pack and machine-readable manifest.

- [ ] **Step 1:** Copy the approved reference pack and manifest into the exact paths above without rewriting semantic decisions.
- [ ] **Step 2:** Validate YAML parses successfully.
- [ ] **Step 3:** Search the two pack files for forbidden newly-authored legacy-form stable keys.
- [ ] **Step 4:** Confirm legacy-form occurrences are only `from/source` references being migrated.
- [ ] **Step 5:** Run `git diff --check`.
- [ ] **Step 6:** Commit:
  ```bash
  git add docs/semantic-packs/odo
  git commit -m "docs: add Odo semantic reference pack"
  ```

### Task 2: Build a read-only Odo runtime inventory

**Files:**
- Create: `docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`
- Create or modify tests only if an existing inventory command/service already has tests; do not build a new subsystem solely for this report.

**Interfaces:**
- Consumes: runtime entity/search/relation/source/evidence/media/video reads.
- Produces: UUID/stable-key/revision/relation inventory and collision report.

- [ ] **Step 1:** Enumerate every active entity whose stable key begins with any Odo legacy or canonical prefix.
- [ ] **Step 2:** Enumerate inbound/outbound relations for every such UUID.
- [ ] **Step 3:** Enumerate Sources, Evidence, Media, Videos and `wp_post` endpoints referencing them.
- [ ] **Step 4:** Mark every legacy→canonical target as `NON_COLLIDING`, `COLLISION`, `CONFIRMED_MERGE`, or `REVIEW`.
- [ ] **Step 5:** Explicitly inventory Odo 35 model/movement/variant references.
- [ ] **Step 6:** Explicitly inventory the confirmed pinned-dial duplicate pair and the glued-dial candidate pair.
- [ ] **Step 7:** Save results to `ODO_RUNTIME_INVENTORY.md`.
- [ ] **Step 8:** Run read-only regression checks and `git diff --check`.
- [ ] **Step 9:** Commit:
  ```bash
  git add docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md
  git commit -m "docs: inventory Odo runtime graph"
  ```

### Task 3: Add fail-closed validation for the Odo manifest

**Files:**
- Modify only the existing governed import/proposal preflight component that already validates stable keys and record identity.
- Test: the corresponding existing Governance/preflight test file.

**Interfaces:**
- Consumes: manifest row with UUID/current key/target key/action.
- Produces: validated preflight result; no mutation.

- [ ] **Step 1:** Write a failing test proving a new Odo target key using the forbidden legacy namespace is rejected.
- [ ] **Step 2:** Run the focused test and confirm failure.
- [ ] **Step 3:** Add minimal validation at the existing preflight boundary; do not create an Odo-specific storage layer.
- [ ] **Step 4:** Write a failing test for target stable-key collision.
- [ ] **Step 5:** Implement fail-closed collision behavior.
- [ ] **Step 6:** Run focused tests.
- [ ] **Step 7:** Run Governance/preflight suite.
- [ ] **Step 8:** Commit the validation change.

### Task 4: Governed namespace rekey

**Files:**
- Reuse the existing Authority governed operation/executor for identity updates/rekey.
- Modify only if current operation contract cannot preserve UUID while changing stable key; any extension must remain generic for all brands.
- Tests: existing Authority/Governance executor tests.

**Interfaces:**
- Consumes: `REKEY` rows from manifest + expected revision + idempotency key.
- Produces: same UUID with canonical `odo` stable key and preserved relations.

- [ ] **Step 1:** Write a failing test: rekey preserves UUID.
- [ ] **Step 2:** Write a failing test: stale expected revision is rejected.
- [ ] **Step 3:** Write a failing test: same idempotency key/same payload does not reapply.
- [ ] **Step 4:** Write a failing test: target collision fails closed.
- [ ] **Step 5:** Implement/reuse minimal generic governed rekey support.
- [ ] **Step 6:** Run focused tests.
- [ ] **Step 7:** Apply rekey proposals in small batches, excluding confirmed/candidate collisions and Odo 35 retirement rows.
- [ ] **Step 8:** Read back every changed UUID and relation set.
- [ ] **Step 9:** Verify no new forbidden namespace key was created.
- [ ] **Step 10:** Commit code separately from data receipts/evidence documentation if repository policy distinguishes them.

### Task 5: Merge the confirmed pinned-dial duplicate

**Files:**
- Reuse existing governed merge/deprecation/relation operations if present.
- If no generic merge contract exists, stop and produce `CONTRACT_EXTENSION_REQUIRED`; do not improvise direct SQL.

**Interfaces:**
- Consumes:
  - source UUID `32f43d4b-d6c8-4223-a89b-cc47f30cda77`
  - target UUID `48311ccd-9d45-4985-a620-ca579499f02c`
- Produces: one canonical active component, with source superseded/deprecated and zero lost relations/evidence/media references.

- [ ] **Step 1:** Snapshot source/target payloads, revisions and all references.
- [ ] **Step 2:** Write tests for relation dedupe and no dangling references using the generic merge path.
- [ ] **Step 3:** Preflight the merge.
- [ ] **Step 4:** Apply relation/reference moves through Governance.
- [ ] **Step 5:** Read back target and all former source references.
- [ ] **Step 6:** Mark source superseded/deprecated only after verification.
- [ ] **Step 7:** Do not merge the glued-dial candidate in this task.
- [ ] **Step 8:** Record receipt and commit evidence/docs.

### Task 6: Odo 35 retirement review

**Files:**
- Update: `docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`
- Reuse generic Authority deprecation/retirement workflow if available.

**Interfaces:**
- Consumes: Odo 35 model/movement/variant reference inventory.
- Produces: either safe retirement proposal or explicit blocked status.

- [ ] **Step 1:** List every inbound/outbound reference to Odo 35 records.
- [ ] **Step 2:** Classify each reference as active-required, historical, duplicate, or orphan.
- [ ] **Step 3:** If any active-required reference exists, do not retire; document blocker.
- [ ] **Step 4:** If safe, create governed deprecation/retirement proposal.
- [ ] **Step 5:** Apply only after proposal eligibility passes.
- [ ] **Step 6:** Verify Odo 35 no longer appears in active Odo pack search while historical audit remains available.
- [ ] **Step 7:** Record receipt.

### Task 7: Complete Registry-valid core relationships

**Files:**
- Reuse Relation Registry and governed relation executor.
- Tests: relation endpoint/predicate validation tests.

**Interfaces:**
- Consumes: logical relationship intents from Reference Pack.
- Produces: allowed active graph edges only.

- [ ] **Step 1:** Resolve actual predicate for each required intent; record unresolved intents instead of inventing predicates.
- [ ] **Step 2:** Write/confirm tests that invalid endpoint pairs fail closed.
- [ ] **Step 3:** Create missing Brand↔Model relations.
- [ ] **Step 4:** Create missing Model↔Movement relations.
- [ ] **Step 5:** Create missing Model/Movement↔Variant relations.
- [ ] **Step 6:** Create evidence-backed Variant↔Component and Variant↔Classification relations.
- [ ] **Step 7:** Create evidence-backed Variant↔Music relations.
- [ ] **Step 8:** Read back all edges and dedupe active triples.
- [ ] **Step 9:** Record unresolved relation intents in inventory, not as invented graph data.

### Task 8: Create atomic Knowledge shells and domestic branches

**Files:**
- Reuse governed Knowledge create/update contracts.
- Update: `docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md` with created/reused Knowledge UUIDs.

**Interfaces:**
- Consumes: `knowledge_shells` manifest rows.
- Produces: atomic Knowledge records at the correct runtime status/type.

- [ ] **Step 1:** Resolve actual runtime statement/status vocabulary.
- [ ] **Step 2:** Preflight each proposed stable key for collision/reuse.
- [ ] **Step 3:** Create/reuse the “đồng hồ nội địa” definition Knowledge.
- [ ] **Step 4:** Create/reuse Odo domestic overview.
- [ ] **Step 5:** Create/reuse Odo 36/54/57/62 domestic Knowledge shells.
- [ ] **Step 6:** Create/reuse community-name Knowledge for 54/57/62.
- [ ] **Step 7:** Create the 54-origin statement only at Claim strength.
- [ ] **Step 8:** Create/reuse Odo 57 and Odo 62 recognition Knowledge shells.
- [ ] **Step 9:** Do not manufacture Source/Evidence for owner statements that do not yet have attributable evidence.
- [ ] **Step 10:** Read back and record UUIDs.

### Task 9: Build Media and Video requirement placeholders

**Files:**
- Reuse Media/Video placeholder operation if present.
- Update: `docs/semantic-packs/odo/ODO_RUNTIME_INVENTORY.md`.

**Interfaces:**
- Consumes: Media/Video requirement groups.
- Produces: governed placeholders or read-only requirement records depending on runtime support.

- [ ] **Step 1:** Discover whether current runtime exposes `media.create_placeholder` or equivalent governed placeholder operation.
- [ ] **Step 2:** If supported, write focused validation proving placeholder has no fake file URL.
- [ ] **Step 3:** Create Media placeholders grouped by subject.
- [ ] **Step 4:** Link placeholders to existing canonical subjects only with allowed predicates.
- [ ] **Step 5:** Discover Video placeholder support separately.
- [ ] **Step 6:** If unsupported, do not create Video entities; keep requirement list in docs.
- [ ] **Step 7:** Record placeholder UUIDs/requirements.

### Task 10: Reconcile Odo WordPress posts

**Files:**
- Reuse approved Article Ingest/Reconciliation runtime.
- Tests: Article reconciliation tests/fixtures.

**Interfaces:**
- Consumes existing Post IDs 38, 39, 40, 55.
- Produces governed semantic links without changing editorial ownership.

- [ ] **Step 1:** Read and fingerprint each existing Post.
- [ ] **Step 2:** Confirm `wp_post:<blog_id>:<post_id>` endpoint resolution.
- [ ] **Step 3:** Preflight semantic delta.
- [ ] **Step 4:** Reconcile Post 38 to Odo historical Knowledge.
- [ ] **Step 5:** Reconcile Post 39 to Odo factory Knowledge.
- [ ] **Step 6:** Reconcile Post 40 to domestic-clock Knowledge.
- [ ] **Step 7:** Reconcile Post 55 to Odo, Odo 24, Odo 54 and naming/domestic Knowledge.
- [ ] **Step 8:** Verify Post 55 title/body/excerpt/status/slug/permalink/revisions remain unchanged by semantic reconciliation.
- [ ] **Step 9:** Verify no duplicate WordPress Post was created.

### Task 11: Full verification and reusable Brand Pack report

**Files:**
- Create: `docs/semantic-packs/odo/ODO_APPLY_REPORT.md`

**Interfaces:**
- Consumes: all receipts and read-back results.
- Produces: final verification report and template lessons for future brands.

- [ ] **Step 1:** Search for active Odo-owned stable keys in the forbidden legacy namespace; expected zero after successful normalization except historical audit/deprecated references.
- [ ] **Step 2:** Search for duplicate pinned-dial canonical identities; expected one active target.
- [ ] **Step 3:** Verify Odo 35 active-pack exclusion.
- [ ] **Step 4:** Verify Odo 24/30/36/39 and all retained UUIDs.
- [ ] **Step 5:** Verify graph edges and no duplicate active triples.
- [ ] **Step 6:** Verify Knowledge shells and evidence strength.
- [ ] **Step 7:** Verify Media/Video requirements/placeholders.
- [ ] **Step 8:** Verify Posts 38/39/40/55 unchanged editorially.
- [ ] **Step 9:** Run focused suites, full PHPUnit suite, PHP lint, other project linters, and `git diff --check`.
- [ ] **Step 10:** Write `ODO_APPLY_REPORT.md` with root cause, actions, receipts, unresolved research, and generic Brand Pack lessons.
- [ ] **Step 11:** Commit final report.
