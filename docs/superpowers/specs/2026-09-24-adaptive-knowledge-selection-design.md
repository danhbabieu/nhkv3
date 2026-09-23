# Adaptive Knowledge Selection Design

## Status

Approved conversational design on 2026-09-24. This document is an implementation design, not a new semantic authority or canonical schema.

## Goal

Make shared Article/Video/Image/Media editorial enrichment choose reader-useful knowledge adaptively across sparse, duplicate-heavy, provenance-heavy and very rich candidate pools without treating retrieval size, popularity or graph reachability as editorial truth.

## Constitutional boundaries

- KnowledgeClaim, Source, Evidence, Authority and Graph remain canonical owners.
- Graph reachability discovers candidates only; applicability, scope, evidence and public-role policy authorize reuse.
- Editorial models are transient read models. No canonical KnowledgeUnit table, claim rewrite, graph backfill or schema migration is introduced by this change.
- Provenance and grounding remain traceable but never become reader coverage, public prose or SEO material.
- Usage memory is a secondary diversity signal and never changes truth, confidence, eligibility, applicability or authority.
- No new LLM, embedding or network call is introduced in the selector loop.

## Boundary and data flow

The shared flow becomes:

`bounded retrieval → discovered → eligible → applicable → semantic role → KnowledgeUnit/dedup → reader coverage → adaptive selection → publicly composable → journey → compose → quality → SEO → governed usage record`

`EditorialKnowledgeSelector` remains the orchestration boundary. It consumes retrieval candidates and produces `EditorialContextPack`. New transient value objects/helpers live beside the current semantic classes:

- `KnowledgeUnit`: representative proposition, semantic role, subject/scope, supporting claim IDs/revisions, evidence/source references, provenance trace, coverage aspects, reader utility, public composability and deterministic redundancy fingerprint.
- `CoverageAspect`: generic derived reader aspect key/label, required/optional state and covered unit IDs. It is derived from existing topic/facet/intent/knowledge metadata; no clock-only taxonomy is added.
- `EditorialUsageMemoryReader`/`EditorialUsageMemoryWriter` only if an existing compatible dependency/telemetry store can be reused. Otherwise selection exposes a no-memory diagnostic and does not create persistence.

`EditorialContextPack` gains transient units, coverage and retrieval/selection diagnostics while retaining selected Claim arrays for compatibility. A selected unit carries its representative claim fields plus `knowledge_unit` metadata and complete supporting trace.

## Deduplication

Deduplication is deterministic and order-stable. Normalize Unicode/case/whitespace/punctuation, remove non-semantic stop words, preserve numbers and domain tokens, and combine:

1. canonical subject/scope/facet/semantic role;
2. normalized proposition token signature;
3. bounded token containment/Jaccard similarity for near-duplicates.

The first deterministic representative wins ties; the unit retains every supporting Claim ID/revision and all provenance/evidence references. Exact string equality alone is insufficient. Grounding/provenance units are kept in trace but cannot occupy reader coverage.

## Coverage and adaptive selection

Coverage is derived from content intent, canonical subject, surface policy, topic/input context and applicable candidate metadata. Each unit covers zero or more generic aspects. A unit is selectable only when eligible, applicable, evidence-compatible and publicly composable; a provenance/control unit can never cover a reader aspect.

The greedy loop repeatedly scores only remaining selectable units by:

`applicability → uncovered aspect gain → evidence/support → reader utility → diversity → contextual/recent usage novelty`

It selects a unit only when marginal gain is meaningful, updates covered aspects, and stops when the surface intent is covered, the marginal gain threshold is reached, the context/token budget is reached, or no useful unit remains. There is no fixed final N. Surface policies provide soft aspect targets and context ceilings only; retrieval safety limits remain retrieval limits.

Expansion starts with direct subject candidates. Only uncovered gaps can trigger bounded registered relation/parent/neighbor expansion. Each round re-runs applicability and deduplication and records its reason, depth, budget, candidates, units and stop reason. No full-graph scan is permitted.

Sparse results are explicit: `THIN` when no or minimal reader coverage exists, `PARTIAL` when some target coverage exists, and `SUFFICIENT` when the surface/intent minimum is met. Thin is not automatically a hard block; `EditorialQualityGate` decides using the existing surface contract. No provenance padding or fabricated claim is allowed.

## Journey, composition and SEO

`ReaderJourneyPlanner` consumes selected units and coverage state. It creates a short journey from reader aspects, allowing one section to synthesize multiple supporting claims in one unit/aspect. It never creates a section from grounding/control material or repeats a subject to fill space.

`SharedEditorialComposer` receives only public unit propositions and allowed observations for reader copy. Claim IDs/revisions, evidence, source and provenance remain in trace. `SemanticSeoPlanner` consumes the final validated public draft/plan package and selected public units, never raw high-score retrieval candidates.

## Quality diagnostics

Quality reports generic failures/warnings for provenance domination, duplicate domination, insufficient coverage, inapplicable neighbors, discarded useful knowledge, public-role violations, non-public SEO knowledge, low marginal gain and context-budget exhaustion. Raw Claim count is never used as coverage quality.

## Usage memory

Before adding persistence, inspect content dependency, Claim usage, owner relation, editorial dependency/index and publication telemetry repositories. If compatible, use an adapter with idempotent identity `(content owner, subject/context, surface, unit/claim, editorial role, publication operation)`. Selection reads lifetime/recent/same-subject/same-surface context only as a secondary tie-break. Published usage is written after the final accepted/governed boundary; selected drafts, failed attempts and retries do not increment published usage.

## Verification requirements

Synthetic unit tests cover 0/1/3 useful claims, 1000 claims, 500 duplicates, provenance-only candidates, inapplicable/applicable neighbors, expansion/stop behavior, usage tie-break/idempotency, shared Article/Video policies, journey/composer/SEO public boundaries and bounded runtime. Existing enrichment, Video, Article, Unit, Contract and environment-gated Integration suites are run. A special-case scan must find no regression UUID/Capture ID/YouTube ID/Odo/exact provenance phrase/language blacklist in production code.
