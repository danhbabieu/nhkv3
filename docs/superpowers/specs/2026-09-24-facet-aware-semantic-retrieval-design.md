# Facet-Aware Semantic Retrieval Design

**Date:** 2026-09-24  
**Status:** Design approved in conversation; spec review pending  
**Scope:** Local, transient semantic enrichment before the existing Adaptive Knowledge Selection boundary

## 1. Context and objective

The current shared editorial path interprets input, resolves one authoritative
subject, retrieves a bounded Claim pool, and then applies `KnowledgeUnit`,
coverage and adaptive marginal-gain selection. The selector is intentionally
not a retrieval engine. By the time it runs, multiple concepts in the input
may already have been collapsed into one topic string and one subject query.

This slice adds the missing upstream transition:

`input → understanding → semantic needs → subject/facet resolution →
facet-aware retrieval → bounded relaxation → existing KnowledgeUnit/Coverage/
adaptive selection`

The objective is a reusable read/planning kernel for Article, Video,
Media/Image and generic text/source input. It does not create a second semantic
owner, persist a transient packet, replace the existing selector, or mutate
canonical Knowledge, Graph, Media, Video or Article state.

The success condition is that every meaningful semantic need receives a bounded
retrieval opportunity before global pruning, while sparse knowledge remains
explicitly sparse and broader knowledge cannot become a narrower assertion.

## 2. Constitutional and contract constraints

The implementation follows the Constitution and the current Knowledge, Living
Knowledge, Article, Video and Media contracts:

- Authority owns canonical identity; Knowledge owns atomic Claims; Source and
  Evidence own provenance/support; Graph owns typed relations; Governance owns
  durable semantic mutation.
- Canonical UUID/stable key and the existing Capture subject packet remain
  authoritative. Weaker lexical, title, source or machine-derived hints do not
  reopen or replace the selected subject.
- Graph reachability discovers candidates only. Applicability, scope,
  provenance, evidence and relevance remain independent checks.
- User/source/specimen/machine observations are planning input. They do not
  become canonical Knowledge or Evidence automatically.
- Article, Video and Media remain separate owners. WordPress remains the
  editorial Article owner; Media/MediaAsset/MediaUsage and Video boundaries are
  not merged.
- No new entity type, endpoint, predicate, relation type, canonical field or
  migration is introduced by this design.
- No staging, production, V2 or live data mutation is included.

## 3. Design alternatives

### Recommended: shared transient semantic-needs kernel

Add a small `SemanticInputEnvelope`, `SemanticNeed` and decomposition service,
then extend the existing `ClaimRetrievalEngine`/`EditorialClaimRetrievalService`
with a multi-need read path. Preserve the existing single-subject API as a
compatibility adapter. Candidate allocation and relaxation are per need; the
existing `KnowledgeUnitBuilder` and `EditorialKnowledgeSelector` consume the
merged, traced candidate set.

This is recommended because it fixes the exact information-loss boundary,
keeps current downstream behavior, avoids persistence, and lets all surfaces
share one deterministic kernel.

### Rejected: rebuild the downstream selector around facets

This would make `EditorialKnowledgeSelector` compensate for candidates that
were never retrieved. It would duplicate retrieval policy, blur retrieval and
reader coverage, and risk changing the already-verified adaptive behavior.

### Rejected: introduce a universal enrichment orchestrator

This would create a large cross-domain gatekeeper with Article, Video, Media and
Knowledge ownership concerns. The current `SharedEnrichmentBoundary` is enough
as orchestration; the new behavior belongs in small semantic services and
surface adapters.

## 4. Shared input representation

Add a transient `SemanticInputEnvelope` application read model, or an adapter
with equivalent behavior if the existing runtime package convention makes a
new class unnecessary. It contains:

- input/source type;
- raw user text and optional title;
- the authoritative subject packet or canonical subject hints;
- structured observations and source metadata;
- content intent and target surface;
- machine-derived observations;
- per-component origin/provenance.

The envelope is not a canonical store and is not serialized as semantic truth.
Capture remains the preferred source. Article, Video and Media adapters map
their existing context into it without copying owners or raw private payloads.

The controlled origin vocabulary is reused where available and otherwise
preserved as equivalent values: `USER_EXPLICIT`, `SOURCE_EXPLICIT`,
`CANONICAL`, `MACHINE_DERIVED`, `EDITORIAL_CONTEXT` and
`SPECIMEN_OBSERVATION`. Unknown origin values do not silently become canonical.

## 5. Semantic decomposition and SemanticNeed

Add a transient typed packet equivalent to `SemanticNeed` with:

- deterministic `need_id`;
- authoritative `canonical_subject` reference;
- normalized `concept_key` and registered `facet_key`;
- scope and content intent;
- origin and bounded confidence;
- evidence requirement;
- retrieval policy;
- relaxation policy.

`SemanticNeedDecomposer` consumes the envelope and existing interpreter,
resolver, facet registry, dictionary/lexical and structured-observation
boundaries. Lexical matches assist candidate detection only; they do not prove
semantic truth or create a canonical entity.

The decomposer emits one need for each meaningful independent concept, removes
deterministic duplicates, and records why a candidate was accepted, merged or
left unresolved. It does not contain Odo/clock-specific phrase lists, a
Vietnamese blacklist, a YouTube ID, a Capture UUID or regression-title logic.

An authoritative subject is copied into every need. A weaker hint may add a
facet or concept candidate, but it cannot change the subject. Specimen and
machine observations retain their origin and narrowest known scope.

## 6. Facet-aware retrieval

Extend the existing retrieval path rather than adding a competing engine. The
multi-need operation performs these bounded steps:

1. Normalize and validate the needs against the authoritative subject and
   registered facet/concept vocabulary.
2. Allocate a bounded opportunity budget across needs. Allocation is
   deterministic and weighted by explicitness, specificity, intent, reader
   relevance, canonical relation and evidence requirement. Every meaningful
   need receives an opportunity before any global pool is pruned.
3. Query direct subject Claims and registered bounded neighborhood candidates
   with the active need context. Existing callback compatibility remains
   available for callers that only provide subject/neighborhood retrieval.
4. Score and filter each candidate against its need, preserving Claim ID,
   revision, original subject, scope, facet, concept, applicability, path,
   provenance and evidence.
5. Merge candidates deterministically by canonical Claim identity and revision
   while retaining all applicable `need_id` associations.
6. Apply the final bounded candidate limit only after per-need opportunities
   and mandatory facet representation have been established.

The result includes per-need diagnostics: opportunity count, initial count,
eligible count, rejected reasons, allocation, relaxation rounds and stop
reason. A Claim that supports multiple needs is one canonical candidate with
multiple need links, not a duplicated Claim.

## 7. Progressive relaxation and specificity

Relaxation is evaluated per uncovered or insufficient need, never globally and
never from the beginning. The default registered tiers are:

- `EXACT`: exact subject plus exact facet/concept;
- `SUBJECT_BROADENED`: applicable parent/family subject plus exact concept;
- `CONCEPT_BROADENED`: exact subject plus registered broader/related concept;
- `APPLICABLE_RELATED`: registered applicable related entity plus related
  concept;
- `BACKGROUND_CONTEXT`: broader domain context with reader utility but no exact
  factual coverage.

If a tier is not proven safe by the runtime relation/facet registry, it is
skipped. Each tier stops when the need is sufficiently covered, marginal gain
is exhausted, a bounded budget is consumed, or the next tier is unsafe.

Every candidate carries treatment and semantic distance. At minimum the
implementation reuses or introduces equivalent values for `DIRECT_FACT`,
`SPECIMEN_OBSERVATION`, `SUPPORTING_CONTEXT`, `COMPARATIVE_CONTEXT` and
`BACKGROUND_CONTEXT`.

The composer and SEO planner receive this treatment through the existing
publicly composable material. A broader Claim may enrich a narrower article as
context, but it cannot be rendered as a narrower direct fact without explicit
scope/applicability authorization.

## 8. Existing selection and coverage integration

`SemanticNeed` maps to the existing `CoverageAspect`/`KnowledgeUnit` boundary;
the new layer does not rebuild either component. Candidate metadata adds the
need/facet/concept/tier trace needed for coverage and treatment.

Coverage is classified independently as exact, applicable-relaxed,
contextual/background or uncovered. Background context does not satisfy exact
coverage. Final `THIN`, `PARTIAL` and `SUFFICIENT` remain surface-policy
decisions over semantic needs and selected units, not raw Claim counts.

The adaptive selector continues to provide semantic deduplication, provenance
trace, diversity, marginal-gain selection and context-budget stopping. No
provenance-only material is promoted into reader coverage.

## 9. Observation and candidate-discovery boundary

The shared path may pass observations to `KnowledgeEnrichmentPlanner` and the
existing proposal factory for candidate discovery. It may classify a finding
as a possible new claim, add-evidence, qualify, contradict or specimen-only
observation. It never applies a canonical mutation and never bypasses
Governance.

Generated Article/Video prose, captions, OCR, transcript text and MediaUsage
metadata remain non-Evidence unless a normal governed Source/Evidence workflow
supports them. A specimen observation cannot silently become Variant, Model or
Brand Knowledge.

## 10. Surface adapters

The shared kernel is integrated at the existing `SharedEnrichmentBoundary`.

- **Article:** maps Capture/article text, title, explicit observations and
  editorial intent; composition/publication remain Article-owned.
- **Video:** maps the resolved Capture subject, source metadata and scoped user
  observations; YouTube identity remains Video-owned and source adapter rules
  remain unchanged.
- **Media/Image:** maps caption/input and visual observations; Media identity,
  MediaAsset and MediaUsage remain separate owners and visual observations do
  not become Evidence.
- **Generic text/source:** maps explicit text/source assertions and target
  intent without requiring an Article owner.

Surface profiles can set selection limits and reader policies, but cannot
  change the shared subject, Claim identity or relaxation safety rules.

## 11. Observability and bounded performance

The final transient trace is deterministic and bounded and can answer:

`input component → need → subject/facet/concept → retrieval tier → Claim →
applicability → rejection/selection reason → KnowledgeUnit → coverage →
treatment → public use`

Diagnostics contain counts and stable IDs/revisions only. Raw secrets and
unbounded payloads are excluded. No LLM or network call is added inside the
retrieval/selection loop. The benchmark covers 1000+ Claims, dominant facets,
500 semantic duplicates and sparse facets without unbounded N² behavior.

## 12. Test and verification design

Implementation follows FAIL-BEFORE → PASS for each behavior:

- five independent concepts produce multiple needs;
- equivalent wording deduplicates needs;
- canonical subject defeats conflicting lexical hints;
- specimen observations remain non-canonical;
- sparse facets receive retrieval opportunities despite a dominant facet;
- candidate pools remain bounded and preserve ID/revision/provenance;
- one Claim may support multiple needs without canonical duplication;
- exact coverage does not trigger relaxation;
- relaxation advances only for the uncovered need;
- applicable parent/context is retained with treatment;
- reachable but inapplicable neighbors are rejected;
- broader/background material cannot become a direct narrower assertion;
- no safe candidate leaves the need explicitly uncovered;
- rich/sparse and duplicate-heavy pools remain deterministic;
- Article, Video, Media/Image and generic text reuse the same kernel;
- existing provenance, generated-prose, identity, idempotency and Governance
  regressions remain green.

Run focused semantic/adapters/selector tests, the complete Unit suite,
Contract suite, PHP lint and `git diff --check`. Run Integration with the
documented WordPress test environment when available. Missing
`NHK_WP_TEST_PATH=public` or `NHK_WP_TEST_DB=nhk_v3_test` remains an explicit
environment gate and cannot be reported as a pass.

## 13. Documentation and delivery boundaries

Update the Knowledge contract and `V3_EXECUTION_STATE.md` with the invariants:

- understanding precedes retrieval;
- one input may produce many needs;
- retrieval opportunity is facet-aware before global selection;
- coverage selection cannot recover knowledge never retrieved;
- relax only uncovered needs;
- broader Knowledge cannot silently become narrower truth;
- sparse Knowledge causes controlled relaxation, not fabrication;
- rich Knowledge requires diversity, not raw top-score accumulation;
- observations are not canonical Knowledge automatically;
- shared intelligence core and surface-specific projection.

No deployment, push, schema migration, live/staging mutation or final
production readiness claim is part of this slice. The final status may only be
`LOCAL_UNIVERSAL_SEMANTIC_ENRICHMENT_READY_FOR_USER_DEPLOY` if all required
local executable gates pass and Integration is not environment-gated.
