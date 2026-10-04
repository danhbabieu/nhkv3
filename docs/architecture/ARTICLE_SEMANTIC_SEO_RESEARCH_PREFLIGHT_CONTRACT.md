# NHK V3 Article Semantic + SEO Research Preflight Contract

> **NON-NORMATIVE.** This contract is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md` and does not authorize a new
> entity, endpoint, predicate, field, operation or data mutation.

Status: approved contract with an initial read-only runtime slice, updated
2026-10-04 for Dictionary lexical planning and lexical-semantic editorial enrichment. Post references, bounded Knowledge
→ Evidence → Source inventory, shared semantic traversal, route-gated link
planning and Dictionary preview are wired. Full target-runtime acceptance still
requires guarded integration evidence.

## Purpose

An Article request managed by MCP is an operation over native WordPress
editorial content. Before a draft, editorial update or publication gate is
reached, the application must research the current NHK runtime and produce a
deterministic, read-only planning packet. WordPress remains the sole owner of
the Article title, body, category, dates, author, media ordering and URL.

The required order is:

```text
capability discovery → semantic resolution → site inventory → overlap check
→ Knowledge/Source/Evidence research → relation plan → Dictionary preview
→ internal-link/SEO blueprint → Media/Video plan → claim compliance
→ draft/apply/read-back/publish
```

## Scope and fail-closed rules

The preflight applies to MCP intents that create, materially update, enrich,
relate, optimize or publish a V3 knowledge Article. It is read-only and must
distinguish an honest empty result from an unavailable dependency.

Identity resolution is ordered as canonical UUID, stable key, exact registered
name/alias, then ambiguity. Titles, bodies, slugs, taxonomy, postmeta,
keywords, visual similarity and AI memory are not semantic identity.

Unknown registry values, ambiguous identities, unavailable runtime reads,
unsupported predicates, private/retired targets and missing public routes
block the dependent stage; the application must not guess, fall back to
taxonomy or fabricate a relation/content result.

Dictionary detection follows `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`.
Preflight may preview known terms, ambiguous terms, candidate terms and canonical
internal-link destinations, but it must not persist Dictionary Candidate or
Mention rows. Unknown/review-pending lexical terms are not by themselves an
Article publication blocker. Ambiguous terms simply remain unlinked.

## Lexical-semantic editorial enrichment law — 2026-10-04

Article enrichment must join natural language, keyword/query planning,
Dictionary and Knowledge without collapsing their ownership boundaries.

The Article composer works from truth toward wording:

```text
resolved subject
  → eligible Knowledge Claims
  → scope/provenance/evidence filtering
  → Dictionary Sense/Form map for the selected concepts
  → bounded keyword/query-intent map
  → audience/register choice
  → natural prose + headings + internal-link/SEO projection
```

A keyword is never a fact source. A Dictionary definition is never a substitute
for a canonical Claim. Graph reachability only finds candidates. The final prose
may vary naturally while the selected Claim IDs/revisions and lexical Sense
resolution remain traceable in the planning/read-back packet.

The enrichment planner should diagnose different gaps separately:

- lexical gap: missing/weak accepted wording for an already understood concept;
- Knowledge gap: missing or insufficiently supported factual content;
- relation gap: missing independently valid semantic connection;
- editorial coverage gap: valid facts exist but the reader explanation is thin;
- query/keyword gap: reader vocabulary/search intent is not adequately covered.

A query/keyword gap must be repaired through lexical/query planning, not by
inventing Knowledge. Likewise a lexical gap must not trigger duplicate Claims.

Natural-language quality is a first-class projection concern: use the preferred
term where clarity requires it, then vary only through approved/applicable Forms
or clearly attributed community wording. Do not stuff exact keywords or repeat
aliases mechanically. SEO/query coverage should emerge from concept coverage,
not drive semantic truth.

After an Article save, the existing Dictionary observation boundary may record
idempotent Mention/Candidate state. Generated Article/SEO wording retains
derived lineage and cannot count as independent corroboration or independently
raise lexical authority.

## Research packet

The packet is an application result, not canonical storage. Its controlled
sections are:

- subject resolution and assertion scope;
- WordPress Post/category/hub inventory;
- overlap classification: `NO_OVERLAP`, `COMPLEMENTARY_CONTENT`,
  `SUBSTANTIAL_OVERLAP`, `LIKELY_DUPLICATE_INTENT` or
  `EXISTING_CANONICAL_ARTICLE`;
- Knowledge, Source and Evidence inventory;
- relation candidates classified as `EXISTING_DIRECT`, `EXISTING_DERIVED`,
  `PROPOSED_DIRECT`, `EDITORIAL_RELATED`, `AMBIGUOUS` or `UNSUPPORTED`;
- Dictionary plan containing `resolved_terms`, `ambiguous_terms`,
  `candidate_terms`, `internal_link_candidates`, warnings and availability;
- internal-link candidates with public canonical route and reason;
- category, Media and Video plans;
- Article SEO Blueprint;
- public-claim compliance state;
- blockers, warnings and `ready_for_draft`.

When the Media plan includes a public image, its normalized derivative follows
the canonical 1920px maximum-long-edge rule: dimensions at or below 1920px are
retained, larger images are proportionally downscaled, and no upscale, crop or
aspect-ratio change is permitted. This is a projection/readiness constraint,
not a new Media identity or an Article semantic mutation.

`EXISTING_DERIVED` is query-only, bounded to an approved maximum of two hops,
and is never persisted as a shortcut. `EDITORIAL_RELATED` does not become a
Graph edge. Only a registry-valid, evidence/provenance-ready
`PROPOSED_DIRECT` candidate may enter the existing Governance proposal flow.
A Dictionary mention/candidate is never evidence for such a relation.

## Article gate

`ready_for_draft` is true only when required runtime inventory, subject
resolution, overlap decision, relation/claim policy and applicable media/SEO
checks are available. Dictionary review-pending candidates do not change that
gate. Dictionary runtime failure must be exposed as `UNAVAILABLE`/warning; it
must not be fabricated as an honest empty lexical result.

A preflight success does not mean a Post was written, semantic mutation was
applied or publication is allowed. Draft, Governance, read-back, rendered
SEO/public verification and publication remain separate gates defined by
`ARTICLE_INGEST_CONTRACT.md`.

## Media-aware Claim retrieval — 2026-09-09

Khi Article có Media, preflight nghiên cứu theo bounded chain
`input → subjects → Graph neighborhood → related Claims → scope/provenance/
evidence/relevance → selected editorial claims`. Exact Variant là tín hiệu
chính nhưng không phải giới hạn duy nhất: Model, Movement, Classification,
Component và direct Knowledge có thể được xem xét khi path, scope và evidence
phù hợp; far-path và weak keyword matches bị loại. Candidate phải giữ
`subject_id`, scope, provenance, evidence status, relation path và relevance để
composition chọn có kiểm soát.

Mọi input được phân loại trước khi dùng: `OBSERVED_FROM_MEDIA`,
`EXPLICIT_USER_KNOWLEDGE` hoặc `CANONICAL_CLAIM`. Claim canonical đã có phải
được reuse theo UUID/stable key/revision trước khi tạo proposal mới. MediaUsage,
caption, OCR, filename và visual recognition chỉ là contextual observation;
chúng không tự trở thành Claim, Evidence hoặc Graph relation. Related Article
chỉ được đưa vào candidate/update khi có semantic relation và public-route
eligibility, không dựa trên keyword-only matching.

## Update semantics

An update reruns research against the current Post revision, semantic links,
Knowledge references, MediaUsage, category, Dictionary lexical state, SEO
projection and related content. Plans are reconciled; they are never blindly
appended.

The reconciliation manifest distinguishes user-authored content from managed
derived sections. Derived sections use deterministic semantic identity and are
replaced or removed from the current composition; the same input does not
append another copy. Reader-facing Article and SEO text pass the shared
fail-closed public-copy boundary before native WordPress persistence, while
Claim IDs, provenance, scope and resolution diagnostics stay in the research
trace.

After an actual Article save/update, the Dictionary observation boundary may
persist lexical Mention/Candidate state idempotently. That post-write lexical
observation is non-semantic and must not rewrite the stored Article body.

### Visual dependency preflight — 2026-09-11

Preflight may report a semantic feature's VisualSupportRequirement separately
from missing node representative media. Composition may consume a resolved
binding dynamically, while an optional missing visual does not corrupt the
Article media blueprint or turn the content into an infrastructure failure.
The requirement remains subject/scope/facet/feature exact and does not become a
Claim, Evidence or factual Article field.
