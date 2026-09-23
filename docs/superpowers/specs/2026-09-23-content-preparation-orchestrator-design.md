# Content Preparation Orchestrator Design

**Date:** 2026-09-23  
**Status:** Amended after written-spec review; approved clarifications incorporated

## Goal

Move the normal new-content path from draft-first reconciliation to a
preparation-first flow:

```text
Capture input
→ discover candidates
→ classify gaps
→ governed enrichment when justified
→ re-resolve canonical state
→ lock one SubjectResolutionPacket
→ create relations from the prepared plan
→ compose Article/Video copy
→ reconcile Media usage
→ publication gate
```

The first implementation is one small, generic vertical slice. It reuses the
existing Authority, Knowledge, Graph, Governance, Media, Video and Article
owners and does not redesign persistence.

## Constraints and ownership

- WordPress native `wp_posts` remains the sole owner of Article title, body,
  excerpt, dates, categories, editorial URL and publication state.
- Authority remains the owner of canonical semantic entities; Knowledge owns
  claims; Source/Evidence owns provenance/support; Graph owns typed relations;
  Governance owns durable semantic mutation.
- `ContentPreparationOrchestrator` is application orchestration only. It does
  not become a semantic owner, Graph writer, Evidence writer or Article store.
- Existing `SubjectResolutionService`, Claim retrieval, Governance callback,
  Article composer, Media and Video services remain the owners of their
  respective operations.
- No legacy Article body migration, V2/production mutation, direct database
  write, generic WordPress writer or Governance bypass is introduced.
- `PREPARED`, `REVIEW_REQUIRED` and `BLOCKED` are transient preparation-result
  meanings only. They must not be added as a new persisted CaptureStage or
  invented runtime registry vocabulary.
- Preparation remains resumable through the existing durable Capture boundary.
  Capture context/diagnostics/checkpoints must retain enough phase, plan,
  governed-enrichment read-back and packet state to resume after interruption
  on the same Capture and idempotency key. A retry must not create a duplicate
  Authority, Knowledge, Graph, Article or Media owner.

## Proposed design

### 1. Preparation result

Add one small immutable application value object representing the output of
preparation. It contains:

- transient status: `PREPARED`, `REVIEW_REQUIRED` or `BLOCKED`;
- one immutable final `SubjectResolutionPacket` when canonical resolution is
  available;
- candidate concepts discovered from input, explicitly marked as candidates;
- gap analysis: `EXISTING`, `MISSING`, `AMBIGUOUS` or `UNSUPPORTED`;
- missing entities, missing Knowledge, missing relations and claims needing
  review;
- governed-enrichment read-back diagnostics, without copying request bodies;
- a prepared semantic plan describing only registered/reviewable relation
  candidates;
- bounded blockers and warnings.

The value object must expose a body-free array representation for Capture
diagnostics and downstream handoff. It must not be persisted as a second
semantic record.

### 2. ContentPreparationOrchestrator

Add one application service with injected existing collaborators:

```php
prepare(array $input, array $interpretation, array $assets = [], array $context = []): ContentPreparationResult
```

The service performs the following bounded sequence:

1. Extract candidate concepts from the existing interpretation, subject hints,
   title/topic and user observations. These are locator/planning inputs only.
2. Resolve candidates through the existing canonical resolver in the existing
   precedence order: explicit UUID, stable key, ordered hints, title/topic,
   then content mentions.
3. Classify each relevant candidate as existing, missing, ambiguous or
   unsupported. An ambiguous primary subject is review-required.
4. Retrieve existing Knowledge and registered relation candidates through the
   existing read/query services. A prose mention alone never authorizes a
   relation, Claim or Evidence.
5. If a missing semantic item has sufficient, explicitly supported evidence,
   invoke the existing Capture-bound governed semantic write-back callback.
   The callback remains responsible for Proposal → approval/policy →
   eligibility → Controlled Apply → canonical read-back. Insufficient
   evidence leaves the item review-required. A semantic Graph relation between
   existing canonical semantic owners may be created in this governed path
   before final preparation, when it is required by the enrichment plan; this
   is never a direct Graph write and is followed by canonical read-back and
   re-resolution.
6. Re-resolve against fresh canonical state after enrichment.
7. Build exactly one final `SubjectResolutionPacket` and return it with the
   prepared semantic plan. Downstream code must consume this packet instead of
   resolving the primary subject from prose again.

The orchestrator must not call Article draft creation, Article composition,
MediaUsage placement, publication or a direct Graph writer. Those remain later
phases owned by the existing Capture coordinator and domain services. The
preparation gate specifically prohibits Article-owned semantic relations,
especially `wp_post → about → subject`, until `PREPARED`.

Physical/canonical Video or Media intake may occur before preparation when it
is required to inspect or validate the supplied source. Video and Media remain
their own owners and candidate inputs; before the final packet is locked they
must not automatically create an `about` relation, select the primary subject,
create Knowledge truth or create Evidence truth.

### 3. Preparation durability and Capture integration seam

The existing `EditorialCaptureCoordinator` will call the orchestrator after
interpretation/physical input preparation and before the existing
`draftCreator` branch. The first slice will:

- resume an already-started preparation from the same Capture when the durable
  phase/plan/read-back state proves the request fingerprint and idempotency
  binding are unchanged;
- stop before draft creation when preparation is review-required or blocked;
- store preparation phase, plan, governed-enrichment read-back and the final
  packet in the existing Capture context/diagnostics boundary, without adding
  a new preparation table;
- pass the immutable packet and prepared plan to draft/composition/semantic
  relation contexts when preparation is prepared;
- ensure Article-owned relation planning uses explicit prepared candidates
  rather than word occurrence in Article/video prose;
- allow governed semantic-owner enrichment relations to use their existing
  Proposal → Apply → read-back path before final preparation;
- preserve existing retry/idempotency behavior and legacy Article
  reconciliation paths.

The change must not make Video, Knowledge-only or Media-enrichment intents
implicitly create an Article. The existing Content Intent rules remain in
force.

### 4. Failure semantics

- Ambiguous primary subject: `REVIEW_REQUIRED`; no Article, relation or
  composition side effect.
- Unsupported registry/entity/predicate/evidence input: `BLOCKED` when the
  required operation cannot proceed safely; otherwise `REVIEW_REQUIRED` for a
  bounded human decision.
- Missing data with insufficient evidence: `REVIEW_REQUIRED`; never fabricate
  an Authority record, Claim, Evidence or relation.
- Governance unavailable, stale or failed read-back: preserve the existing
  non-success diagnostic and do not report preparation as prepared.
- Fresh canonical re-resolution mismatch: fail closed and do not hand an old
  resolution packet to downstream writers.

## First vertical-slice acceptance criteria

1. A generic input with one explicit canonical subject resolves to
   `PREPARED`, creates one immutable packet, and permits the downstream draft
   callback only after preparation returns.
2. An ambiguous primary subject returns `REVIEW_REQUIRED`; draft creation,
   composition, relation creation and MediaUsage placement are not called.
3. A missing candidate without sufficient evidence remains review-required or
   blocked and does not create semantic truth.
4. A word mentioned in input does not create a relation unless the prepared
   semantic plan contains a registered, explicitly justified relation.
5. Enrichment is verified by fresh canonical read-back and subsequent
   resolution consumes the read-back state.
6. Existing tests for the current Capture and Article reconciliation flows
   remain green; no existing legacy recovery behavior is removed.
7. An interruption after governed enrichment but before draft creation resumes
   on the same durable Capture and does not duplicate any owner or relation.
8. Physical Video/Media intake may be read before preparation, but no automatic
   primary subject, Knowledge, Evidence or `about` relation is produced from
   that intake before packet lock.

## Files expected in the implementation slice

- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
  for the same-Capture resume/idempotency case, reusing the repository's
  existing Capture orchestration fixture family.
- Modify only if required by the existing composition root/tests to inject the
  new application service; no new persistence or migration files.

## Verification requirements

- Run the new unit test through a red-green cycle.
- Run the complete plugin Unit suite and the repository's applicable test
  command.
- Run PHP lint for changed PHP files.
- Run migration/schema checks only if a schema change unexpectedly becomes
  necessary; this design explicitly forbids one.
- Run `git diff --check` and a secret review before the checkpoint.
- Read `docs/architecture/V3_EXECUTION_STATE.md` before and after the
  implementation checkpoint and record the vertical-slice evidence without
  claiming staging/production acceptance.
