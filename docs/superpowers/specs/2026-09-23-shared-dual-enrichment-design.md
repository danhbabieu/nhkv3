# NHK V3 Shared Dual Enrichment Design

**Status:** Draft for architectural review  
**Checkpoint:** 4 — Shared Dual Enrichment  
**Date:** 2026-09-23

## 1. Purpose

Checkpoint 4 introduces a small shared application seam for two existing
enrichment directions:

```text
Knowledge → Content
Source / Evidence / Observation → Knowledge Candidate → Governance
```

The seam coordinates existing domain services. It does not create new
semantic intelligence, semantic owners, persistence, publication authority or
Governance behavior.

The central rule is:

```text
Enrichment is a capability, not a global gate.
```

Enrichment may improve an admitted Article, Video or Media artifact while
remaining partial. A Knowledge Delta remains strict because its intended
canonical outcome is governed Knowledge.

## 2. Constitutional and scope constraints

- WordPress `wp_posts` remains the sole Article editorial truth.
- Authority, Knowledge, Source, Evidence, Graph, Media, Video and Governance
  retain their existing owners and writers.
- Graph reachability discovers candidates only; it never authorizes Claim
  reuse or Evidence.
- Only eligible canonical Claims may support factual editorial output.
- Generated Article or Video prose is never Source or Evidence.
- User hints, titles, captions, OCR, transcripts and observations remain
  scoped inputs unless an existing contract promotes them through provenance,
  Evidence and Governance.
- Working enrichment context is transient application context. It is not a
  semantic record, Graph edge, canonical subject, Evidence record or approval.
- Checkpoints 1–3 remain intact: dependency findings, continuation decisions,
  minimum owner admission, exact-subject phase boundaries, idempotency and
  authoritative subject precedence.
- No schema migration, data migration, backfill, production/staging mutation,
  deployment, publication or push is in scope.
- Final publication and migration acceptance remain outside this checkpoint.

## 3. Existing enrichment map

### 3.1 Knowledge → Content

Article currently provides the strongest reusable chain:

```text
resolved/bounded subject + topic
  → EditorialClaimRetrievalService
  → ClaimRetrievalEngine
  → EditorialKnowledgeSelector
  → EditorialContextPack
  → ReaderJourneyPlanner / SemanticSeoPlanner / EditorialQualityGate
  → ArticleEditorialAdapter composition boundary
```

`ClaimRetrievalEngine` applies subject applicability, scope, provenance and
Evidence checks. `EditorialKnowledgeSelector` applies selection budgets,
topic fulfillment and redundancy handling. `EditorialContextPack` carries
selected Claims and diagnostics without becoming a canonical store.

Video already has a separate profile through `VideoEditorialAdapter` that
reuses the retrieval and selection primitives while retaining Video-specific
editorial output. Media does not use Article composition; its typed binding,
MediaUsage and representative paths remain authoritative.

### 3.2 Source / Evidence / Observation → Knowledge

`KnowledgeEnrichmentPlanner` compares a scoped observation against canonical
Knowledge and returns existing candidate classifications:

- `same_claim`;
- `add_evidence`;
- `qualify`;
- `contradict`;
- `new_claim`;
- `ambiguous`;
- `unsupported`.

`KnowledgeEnrichmentProposalFactory` converts only validated candidate classes
into the existing generic Proposal envelope. Candidate provenance carries
subject, facet, scope, Source/Evidence references, revisions and locators.
Governance, Eligibility and Controlled Apply remain the only semantic mutation
path.

`VideoKnowledgeEnrichmentPlanner` already adapts transcript/source observations
to `KnowledgeEnrichmentPlanner`. Its output is planning/candidate state and
must not be treated as applied Knowledge.

### 3.3 Relations and Living Knowledge

`RelationProposalReconciliationService` remains the relation candidate and
reconciliation boundary. Relation readiness is returned as a local finding;
optional relations do not globally block an artifact.

Existing Living Knowledge dependency fingerprints, revision invalidation,
regeneration, preview and governed/public update behavior remain unchanged.

## 4. Shared enrichment seam

Introduce one small application-level coordinator with composed ports. The
implementation plan must choose final repository names after inspecting the
current constructor wiring, but the contract is:

```php
interface SharedEnrichmentBoundary
{
    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function enrich(array $request): array;
}
```

The request is a transient, server-assembled working context containing:

- resolved Content Intent;
- owner reference and idempotency context;
- bounded topic and editorial constraints;
- authoritative SubjectResolutionPacket when available;
- source/observation context with provenance;
- existing Claim/Evidence context;
- profile name and bounded budgets.

The seam returns a bounded result with three independent branches:

```text
content:
  status, selected eligible Claims, EditorialContextPack or equivalent,
  gaps, diagnostics

knowledge:
  status, candidate list, duplicate/conflict/novelty classifications,
  proposal readiness, governance-required diagnostics

relations:
  candidate list, readiness findings, reconciliation diagnostics
```

The result must preserve branch-level incompleteness. A missing Knowledge
candidate cannot erase usable content context; a missing relation cannot be
silently converted to a completed relation.

The seam does not call publication, final composition, direct semantic
writers, Controlled Apply, or owner creation. It supplies bounded context to
those existing consumers.

## 5. Intent profiles

Profiles select capabilities and budgets; they do not create independent
enrichment engines.

### Article profile

- bounded topic and authoritative subject context;
- eligible Claim retrieval and `EditorialContextPack`;
- richer reader-journey and internal-link context;
- optional source/observation candidate planning;
- Article composition, SEO and quality remain downstream.

### Video profile

- external source context and exact external identity;
- bounded eligible Claim retrieval using the same Claim machinery;
- Video-specific context and related-entity candidates;
- optional Source → Knowledge candidate planning from transcript/source
  provenance only;
- no Article owner or Article-specific composition.

### Media profile

- specimen/media observation context;
- bounded subject and relation discovery where justified;
- eligible Claim context for caption/alt/description support where existing
  contracts allow it;
- optional specimen-scoped Knowledge candidate planning;
- no Article composition and no automatic universal Variant Claim.

### Knowledge Delta profile

- Source/Evidence/provenance and scope validation;
- duplicate, add-evidence, qualify, conflict and novel classification;
- Proposal readiness and Governance dependency diagnostics;
- canonical Knowledge remains unavailable until the existing Proposal →
  Approval → Eligibility → Controlled Apply → read-back lifecycle completes;
- no Article owner and no canonical placeholder.

## 6. Direction A: Knowledge → Content

The shared seam delegates to existing retrieval and selection services. It must
not duplicate their eligibility logic.

Rules:

1. Graph paths are candidate-discovery metadata only.
2. The selected Claim retains canonical ID, revision, original subject,
   applicability path, provenance and Evidence references.
3. Ineligible, out-of-scope, unsupported or provenance-free Claims remain
   excluded or review-only.
4. Sparse Knowledge produces conservative output, narrower topic coverage or
   omitted facts.
5. If a valid authoritative subject packet exists, it scopes retrieval and
   weaker hints cannot replace it.
6. Optional retrieval or selection gaps produce local incomplete diagnostics;
   they do not revoke an already admitted owner.

## 7. Direction B: Source / Evidence / Observation → Knowledge Candidate

The shared seam delegates candidate comparison to `KnowledgeEnrichmentPlanner`
and proposal shaping to `KnowledgeEnrichmentProposalFactory`.

Every candidate must preserve:

- original provenance origin;
- source/evidence canonical identifiers and revisions where applicable;
- subject and scope;
- observation/source locator;
- candidate classification and comparison trace.

Generated editorial prose, generated Video descriptions, generated Article
bodies and shared composition output are explicitly rejected as candidate
provenance. A specimen observation remains specimen-scoped. Scope promotion
requires the existing Evidence and Governance path.

Candidate outcomes remain governed:

- `same_claim` reuses the canonical Claim;
- `add_evidence` proposes Evidence attachment through Governance;
- `qualify` and `contradict` remain review/governed operations;
- `new_claim` is proposal-ready only when its provenance and profile permit;
- `ambiguous` and `unsupported` remain incomplete or blocked diagnostics.

## 8. Relations and readiness

Relation candidates are returned as a separate branch. Their classification
must feed the existing Checkpoint 1–3 dependency vocabulary:

- required identity relation → critical identity;
- factual dependency relation → required factual dependency;
- optional contextual relation → optional enrichment;
- public projection relation → publication-only.

The seam never applies a relation directly. Existing Graph and Governance
reconciliation services retain proposal, approval, eligibility, apply and
read-back authority.

## 9. Composition and quality boundaries

Shared enrichment returns context; it does not compose final public prose.
Existing downstream flow remains:

```text
enriched context
  → intent-specific editorial planning
  → SharedEditorialComposer or Video/Media projection
  → SemanticSeoPlanner where applicable
  → EditorialQualityGate
  → existing owner/publication gate
```

`EditorialQualityGate` remains authoritative for public factual grounding,
scope, Evidence, knowledge utilization, visual support, SEO and public claim
compliance. Partial enrichment must cause omission, narrowing or qualification,
not unsupported assertion.

## 10. Idempotency and retries

Repeated shared enrichment with the same Capture, owner, profile, input and
dependency fingerprint must return deterministic diagnostics and must not:

- duplicate Claims;
- duplicate proposals where the existing idempotency contract applies;
- duplicate relations;
- mutate owner identity;
- replace a newer canonical revision with stale context.

Changed input or dependency fingerprints use existing conflict/stale behavior.
The shared seam remains read/planning-oriented unless it delegates to an
already governed, explicitly requested proposal boundary.

## 11. Acceptance tests

The implementation plan must add focused tests proving:

1. Article receives eligible selected Claims through the shared seam.
2. Sparse Article Knowledge remains conservative and incomplete.
3. Video consumes the same eligible Claim machinery without an Article owner.
4. Video source observations produce provenance-bearing candidates only.
5. Incomplete optional Video candidates do not kill Video enrichment.
6. Media observation remains specimen-scoped and does not create universal
   Variant truth.
7. Media typed fast path remains unchanged.
8. Knowledge Delta performs duplicate/conflict/novelty comparison and remains
   Governance-bound.
9. Generated prose cannot become Source/Evidence.
10. Optional relation gaps remain local; required relation gaps remain strict.
11. Authoritative subject packets scope shared retrieval.
12. Repeated enrichment is deterministic and idempotent.
13. Living Knowledge revision invalidation remains visible and does not silently
   rewrite public projections.
14. Checkpoints 1–3 continuation, minimum owner admission and partial-state
   tests remain green.

## 12. Explicit non-goals

This checkpoint does not:

- implement final publication or migration acceptance;
- create new semantic entities, tables, fields or relations;
- rewrite Article, Video, Media or Knowledge owners;
- replace Claim retrieval, selection, composition, SEO, quality, Governance or
  Living Knowledge services;
- create a universal Graph traversal;
- auto-apply Knowledge or relations;
- create a canonical Knowledge placeholder;
- treat generated content as Evidence;
- implement a second coordinator;
- implement Checkpoint 5.

## 13. Success criteria

Checkpoint 4 is complete when:

- both enrichment directions use one compositional application seam;
- Article, Video, Media and Knowledge use intent profiles over shared lower-level
  capabilities;
- provenance, Claim eligibility, Evidence, Governance and subject authority
  remain fail-closed;
- optional enrichment remains local and honest;
- Knowledge Delta remains strict;
- relation readiness feeds existing dependency classes;
- Checkpoints 1–3 remain green;
- no schema/data/live mutation occurs;
- focused tests, full Unit, changed-file lint and `git diff --check` pass.
