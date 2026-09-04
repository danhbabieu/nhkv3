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
