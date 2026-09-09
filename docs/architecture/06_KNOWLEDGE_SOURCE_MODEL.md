# Knowledge và Source

> **NON-NORMATIVE.** Đây là evidence mô hình và runtime. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Knowledge là claim/fact/research statement có thể được nhiều Post sử dụng.
Source là thực thể authority để truy nguyên claim và quan hệ nghiên cứu. Một
Post có thể liên hệ nhiều Knowledge; một Knowledge có thể liên hệ nhiều Post.

P7 persists claims, sources and evidence as separate canonical records with
UUID identity, stable keys, state, optimistic revision and provenance/metadata.
Evidence requires existing claim and source endpoints and records whether the
source supports, contradicts or qualifies the claim. `PostKnowledgeLinkService`
connects a WordPress Post to a Knowledge claim through the single Graph using
the `about` predicate; it does not copy claim text into the Post body and does
not create an Article Authority.

Article Ingest may reuse these records, but completion is coordinated at the
operation boundary: semantic preflight, WordPress draft, governed semantic
mutation, read-back verification and WordPress publish. A direct link write
outside Governance/Controlled Apply is a `CONSTITUTION_CONFLICT`; future Article
implementation must route the link through the approved governed boundary.

Public read boundaries require active records and fail closed when persisted
Source or Evidence metadata explicitly declares a non-`PUBLIC` visibility
(including `PRIVATE` and `HIDDEN`). Public serializers omit the persisted
Source/Evidence metadata blobs and Knowledge claim provenance blob. A missing
visibility value preserves the existing V3-compatible default, but does not
constitute approval of imported V2 provenance; the final publication policy
remains a cutover gate.

## Current enrichment and reuse boundary — 2026-09-04

Knowledge remains atomic and canonical. Article body text, Video transcript,
Video editorial copy, Media alt/caption, OCR output and generated AI prose are
not themselves Knowledge or Evidence. They may only act as bounded input to a
read-only enrichment/extraction planner. Any resulting semantic mutation still
uses `Proposal → Human Approval → Eligibility → Controlled Apply → repository →
audit → read-back`.

Video `USER_HINT` and approved transcript observations may create scoped
Knowledge candidates only after canonical subject resolution. The explicit
validated Video `about` target, when supplied, is preserved as the enrichment
subject; text matching must not silently broaden a Variant observation to Model
or Brand. Transcript text is never promoted wholesale into one claim. At the
current Video phase no canonical Source is created implicitly; `add_evidence`
requires resolved `source_id` plus `source_revision` and otherwise remains
review-only/diagnostic.

Article enrichment is suggestion-only until it re-enters the approved Article
workflow. Knowledge changes do not rewrite an existing WordPress body directly.
MediaUsage, `depicts`, image recognition and technical annotations do not become
Evidence by themselves. A future Media → Living Knowledge adapter must preserve
this same separation and must not create a second writer.

Downstream reuse must resolve canonical Knowledge/Source/Evidence UUIDs and
revisions and attach/refer to those records; it must not copy canonical claim
text into a parallel semantic store or create duplicate claims merely because a
new Post, Video or Media item repeats the same observation.

## Proposal identity versus canonical identity

Governance `proposal_id` identifies a draft/review/apply command only. It is
never a Source, Knowledge Claim or Evidence `canonical_id`, and must never be
copied into `claim_id`, `source_id`, `evidence_id` or another entity reference.
Create/ingest responses expose `proposal_id`, `proposal_state`, `target_uuid`
and `canonical_id` separately; `canonical_id` remains null until controlled
apply has produced and verified the canonical record.

Evidence dependency validation is lifecycle-aware and visibility-independent:
Claim, Source and Evidence must resolve to canonical records and be active.
PRIVATE/HIDDEN Evidence is valid for governed internal verification and is not
promoted to PUBLIC. Public readers continue to omit it.

## Public-safe knowledge projection — current law 2026-09-07

Raw Source/Evidence privacy is a boundary on raw payload serialization, not a
blanket ban on public knowledge. When the canonical pipeline has produced an
eligible public-safe projection, frontend queries may render that projection
without reading private payloads at render time. The current allowlist is:
`text`, `type`, `facet`, `scope` (or the exact registered field equivalents if
the runtime serializer names them differently).

The projection must not include raw Source, private Evidence excerpts/private
metadata, canonical private IDs, or a reconstructed private payload. Public
knowledge does not make a relation public: relation display independently
requires Graph/public eligibility and must not leak private provenance.
# Projection boundary — 2026-09-08

Knowledge remains the canonical owner of atomic claims. The Semantic Claim
Projection Layer may read approved Knowledge plus public Source/Evidence to
materialize a Ledger or SEO candidate, but it never copies claim payload into
WordPress editorial content, Authority payload or a canonical Knowledge row.
Projection filtering preserves subject, scope, provenance eligibility and
claim lifecycle; generated prose is never Evidence. Published SEO prose stays
on its prior revision until the separate candidate passes validation and an
explicit publish transition.

## Universal MCP post-ingest reconciliation — 2026-09-09

Knowledge, Source and Evidence MCP ingest is not complete at proposal/apply or
single-owner read-back. After ingest, the bounded sequence is mandatory:
canonical read-back → canonical search → neighborhood/Graph inspection →
duplicate/reuse analysis → relation candidate discovery → evidence/provenance
validation → governed application of every useful registered relation → final
read-back. The full operation sequence begins with ingest as defined by
Constitution §20.1.

Relation candidates must be reconciled with registered Brand, Model, Variant,
Movement, Component, Classification, Media, Source, Evidence and related
Knowledge nodes where evidence supports the association. “Maximize relations”
means maximize justified useful relations, not edge count; weak or speculative
edges remain unapplied.

Every claim, observation and relation candidate retains one controlled
provenance class: `OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE`,
`CATALOG_SUPPORTED`, `EXTERNAL_RESEARCH` or `SYSTEM_INFERENCE`. User input and
image observation stay scoped to their subject/facet and cannot become a
universal fact without supporting Source/Evidence of the same scope.

## Editorial Capture Claim reuse — 2026-09-09

Editorial Capture reuses the same canonical Knowledge owner rather than creating
an Article-specific fact store. The sequence is resolve subject → inspect bounded
Graph context → retrieve candidate Claims → verify canonical Claim identity and
revision → verify original subject/scope → verify provenance/Evidence → verify
relevance → select or reject. Graph reachability is only a discovery signal; it
never authorizes the Claim by itself.

A selected Claim keeps its canonical ID/revision and original semantic subject.
The Article may store a body-free `claim_trace` or research snapshot containing
that identity, an explainable relation path and editorial usage reason. The
Article body may synthesize the selected fact in new wording, but the resulting
prose is WordPress editorial state, not a second Knowledge record and not new
Evidence.

If a candidate is specimen-scoped, unsupported, provenance-incomplete,
evidence-incomplete or irrelevant to the current Capture, it must be excluded or
kept review-only. The system must not broaden the Claim because a Model/Brand or
other broader node is reachable in Graph. Before any new Claim proposal, the
current canonical Knowledge set must be searched for reuse/add-Evidence first;
repeated prose or repeated visual observation never justifies a duplicate Claim.
