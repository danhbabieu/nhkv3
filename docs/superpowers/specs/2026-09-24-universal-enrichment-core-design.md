# Universal Enrichment Core Design

**Status:** Draft for architectural review  
**Date:** 2026-09-24  
**Scope:** Shared transient enrichment for all NHK knowledge-system owners and sources

## 1. Purpose

NHK V3 needs one domain-neutral enrichment capability for Article, editorial
Article, Video, Media/Image, image description/caption/alt/dossier, text or
note Capture, external URL/source, Brand, Model, Variant, Item/specimen,
Knowledge, Music, Classification/Clock Type, Entity/Dossier, descriptive
content and future canonical owner/source types.

Video Odo 24/1957 is runtime regression evidence only. It is not the owner,
namespace or architectural center of this capability.

The target is a shared transient semantic pipeline:

```text
Source/Input
  -> Universal Input Envelope
  -> Understand
  -> Subject/Entity Resolution
  -> Semantic Decomposition
  -> Semantic Need Graph
  -> Facet-aware Retrieval
  -> Applicability
  -> Progressive Relaxation
  -> Knowledge Unit / Dedup
  -> Coverage
  -> Adaptive Selection
  -> Relation Discovery
  -> Knowledge Candidate Discovery
  -> Enrichment Pack
  -> Owner-specific Consumption
  -> Compose / Describe / Update / Propose
  -> Quality / Governance
  -> Projection
```

The result is shared. Owner-specific behavior begins after shared
understanding and enrichment wherever possible.

## 2. Constitutional boundaries

- WordPress `wp_posts` remains the sole source of truth for Article editorial
  title, body, author, dates, categories, archives, homepage, search, RSS,
  sitemap and editorial URLs.
- Authority owns canonical semantic identity; Knowledge owns atomic Claims;
  Source/Evidence owns provenance/support; Graph owns typed relations;
  Governance owns durable semantic mutation.
- Media, MediaAsset, MediaUsage and Video retain distinct boundaries.
- A Specimen is one concrete physical object. A Product is a listing/offer
  and never the physical object's identity.
- The core may discover, classify, retrieve and propose. It never silently
  creates owners, asserts canonical truth, applies Governance, writes Article
  editorial state, or publishes.
- Existing canonical UUID/stable-key, revision, typed relation, provenance,
  readiness, idempotency, public identity and fail-closed invariants remain
  authoritative.
- No new entity type, endpoint type, predicate, relation type, canonical
  field, migration, schema owner or compatibility writer is introduced by
  this design.
- Captions, OCR, transcripts, generated prose, MediaUsage metadata, titles and
  user hints remain scoped inputs. They do not become Source, Evidence or
  Knowledge automatically.
- No staging/production mutation, V2 migration, article-body import,
  deployment or cutover is included.

## 3. Design alternatives

### Recommended: domain-neutral shared semantic kernel

Keep `SharedEnrichmentBoundary` as a thin coordinator and evolve the existing
`SemanticInputEnvelope`, `SemanticNeed`, facet-aware retrieval and adaptive
selection into a domain-neutral kernel. Introduce a shared `EnrichmentPack`
read model with independent content, relation and Knowledge-candidate
branches. Existing Article, Video and future Media/entity adapters consume the
pack without owning its semantic intelligence.

This preserves the working retrieval/selection contracts, fixes the current
editorial naming and input-shape bias, and provides one safe extension point
for future owners.

### Rejected: one enrichment brain per owner

`VideoEnrichmentBrain`, `ArticleEnrichmentBrain`, `MediaEnrichmentBrain` and
similar classes would duplicate understanding, need decomposition, retrieval,
applicability and safety rules. They would make cross-owner behavior drift and
incorrectly assign architecture to the current regression surface.

### Rejected: one large owner-aware orchestrator

A coordinator containing Article, Video, Media, Knowledge and entity policy
would become a new semantic owner and governance bypass risk. The shared core
must coordinate typed capabilities, not absorb owner-specific lifecycle or
publication decisions.

## 4. Universal Input Envelope

Evolve the current transient `SemanticInputEnvelope` into the single typed
`UniversalInputEnvelope` contract. The old class name may remain only as a
compatibility adapter during migration; it is not a second peer contract. The
universal envelope may carry:

- `owner_or_source_type` and source identity;
- canonical subject/entity resolution packet when already resolved;
- title, body/text and structured observations;
- source metadata and media-derived observations;
- existing typed relations and existing canonical Knowledge references;
- user hints and requested intent;
- publication, semantic and reader context;
- provenance and bounded confidence;
- constraints, capabilities and target surface.

No producer is required to populate every field. Missing fields produce an
explicit sparse/unavailable diagnostic and do not become invented defaults.
The envelope is transient and must not be persisted as canonical semantic
truth.

The authoritative subject packet remains authoritative. Lexical, title,
filename, source and machine-derived hints may add candidate needs but may not
replace or broaden the selected subject. Media-derived observations remain
specimen/media scoped unless a normal governed workflow promotes them.

## 5. Universal Semantic Need

`SemanticNeed` becomes an owner-neutral request for an aspect that should be
understood or enriched for an input/entity. It retains deterministic identity,
canonical subject reference, registered facet/concept, scope, intent, origin,
confidence, evidence requirement, retrieval policy and relaxation policy.

Facet families are vocabulary-driven rather than hardcoded to one owner. The
registry may express concepts such as identity, classification, chronology,
origin, maker/brand, model/variant, configuration, form/design, material,
mechanism, function, sound/music, dimensions, markings, condition,
provenance, relation, comparison, historical/technical context,
specimen observation and reader/use context. The core must not create a
concept merely because a fixture, UI, legacy structure or regression title
contains it.

Decomposition must:

- preserve the canonical subject on every need;
- deduplicate equivalent needs deterministically;
- retain origin and narrowest known scope;
- record accepted, merged and unresolved diagnostics;
- avoid owner-specific phrase lists and regression-title logic;
- allow domain vocabulary extensions through registered contracts.

## 6. Shared enrichment pipeline

The core runs the existing understanding, decomposition, retrieval,
applicability, relaxation, KnowledgeUnit, coverage and adaptive-selection
services in a domain-neutral context.

Facet-aware retrieval allocates bounded opportunity per meaningful need before
global pruning. It merges one canonical Claim with multiple need links rather
than duplicating the Claim. Each candidate retains Claim ID/revision, original
subject, scope, facet/concept, applicability, provenance, evidence, retrieval
tier, semantic distance and treatment.

Progressive relaxation is per uncovered need and only uses registered safe
tiers. Broader or contextual material may enrich a description as context but
cannot satisfy a narrower direct fact without explicit applicability/scope
authorization. Sparse facets remain visibly sparse; empty data, unavailable
runtime, hydration loss and infrastructure failure remain distinguishable.

The shared pack contains bounded diagnostics sufficient to trace:

```text
input component -> need -> subject/facet/concept -> retrieval tier
-> candidate/rejection -> KnowledgeUnit -> coverage -> treatment -> use
```

Diagnostics contain stable identifiers, revisions, counts and reasons, not
unbounded raw payloads or secrets.

## 7. Two enrichment directions

### Content enrichment

```text
Canonical Knowledge -> shared understanding/retrieval -> EnrichmentPack
  -> Article/Video/Media/description-specific consumption
```

Consumers may compose reader journeys, factual/contextual explanations, SEO,
captions, alt text, dossiers or relation views according to their own owner
contracts. The shared core does not compose or publish those artifacts.

### Knowledge enrichment

```text
Source/Evidence/observation -> understanding -> decomposition
  -> candidate discovery -> evidence evaluation
  -> Knowledge proposal -> Governance -> canonical Knowledge
```

Generated Article/Video prose is explicitly rejected as a Knowledge source.
Candidate classifications such as new claim, add evidence, qualify,
contradict, duplicate or ambiguous remain proposals/read models until the
normal Governance lifecycle authorizes a mutation.

## 8. Owner/source adapters

Adapters map existing owner context into the universal envelope and consume a
shared pack. They may define lifecycle, output shape, quality and projection
policy, but not duplicate semantic intelligence or silently mutate truth.

- **Article/editorial Article:** retains WordPress editorial ownership;
  composition, SEO and publication remain Article-specific.
- **Video:** retains source/Video identity and VideoObject projection;
  Odo 24/1957 remains regression evidence only.
- **Media/Image:** consumes visual observations, subject relation, caption,
  alt, evidence role and related Knowledge when connected. Until its lifecycle
  is connected, the adapter must return `MEDIA_ADAPTER_NOT_YET_CONNECTED` and
  may not fake completion.
- **Brand/Model/Variant/Item/Music/Classification/Entity/Dossier:** consumes
  applicable facts, missing-facet diagnostics, relation candidates, evidence
  gaps and descriptive context. It does not turn enrichment into prose-only
  behavior or canonical mutation.
- **Generic text/note Capture and external URL/source:** may use the same core
  without requiring an Article or Video owner.

Owner adapters remain thin and may be absent where no lifecycle exists.

## 9. Error handling and readiness

The shared pack reports independent statuses for understanding, content,
relations and Knowledge candidates. Optional enrichment gaps remain local;
required owner/governance gaps remain strict. A missing adapter is an honest
unavailable state, not an inferred success.

The core is fail-closed for:

- missing or ambiguous canonical subject where the contract requires one;
- unregistered facet/concept/relation;
- ineligible, inapplicable or unsupported Claim use;
- generated prose presented as Source/Evidence;
- stale revision, missing provenance or failed canonical read-back;
- attempted persistence or Governance bypass.

It is fail-soft only for explicitly optional branches, where it returns a
bounded diagnostic and leaves the owning workflow to decide whether the
artifact can continue.

## 10. Testing and verification

The implementation plan must add or update tests proving:

1. The same envelope and semantic core serve Article, Video, Media/Image and
   generic source/text paths.
2. Multiple independent concepts produce deterministic deduplicated needs.
3. Authoritative subject packets defeat conflicting lexical/source hints.
4. Sparse/missing fields degrade explicitly without invented truth.
5. Every meaningful need receives bounded retrieval opportunity before global
   pruning.
6. Relaxation is per-need, safe, traced and cannot narrow a broader Claim.
7. One canonical Claim may support multiple needs without duplication.
8. Existing Knowledge remains eligible for content enrichment only when its
   evidence/applicability/public-composition contract allows it.
9. Source observations create candidate proposals only; generated prose cannot
   become Knowledge or Evidence.
10. Media remains honest with `MEDIA_ADAPTER_NOT_YET_CONNECTED` until connected.
11. Relations are discovered as typed candidates and never applied by the
    shared core.
12. Repeated enrichment is deterministic and idempotent.
13. Owner-specific adapters preserve their existing output, quality,
    publication and governance contracts.
14. Existing Capture continuation, owner admission, Media, Video, Article and
    Knowledge regression suites remain green.

Verification must include PHP lint, focused tests, full Unit tests where the
documented environment permits, migration checks only if schema changes are
actually introduced, `git diff --check`, and secret review. No staging or
production semantic mutation is part of this design.

## 11. Implementation boundaries and sequencing

The implementation plan should be decomposed into independently verifiable
slices:

1. Rename/normalize the transient shared contracts without changing owner
   behavior or persistence.
2. Extend the universal envelope and shared pack with explicit branch status,
   provenance and bounded diagnostics.
3. Move shared semantic decomposition/retrieval/coverage metadata behind the
   domain-neutral core while retaining compatibility adapters.
4. Route Article and Video through the compatibility-safe shared core and add
   generic/source and Media adapter seams without claiming Media completion.
5. Add relation discovery and Knowledge candidate branches as read/planning
   outputs only.
6. Verify the full matrix and update execution state with exact evidence.

No step authorizes new canonical writes, migrations, deployment or cutover.
