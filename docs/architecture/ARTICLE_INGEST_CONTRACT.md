# V3 Article Ingest Contract

> **NON-NORMATIVE.** Đây là contract/guidance triển khai được phê duyệt dưới
> Hiến pháp. Nếu mâu thuẫn với `docs/constitution/NHK_V3_CONSTITUTION.md`,
> Hiến pháp kiểm soát.

> **APPROVED DOCUMENTATION CONTRACT — 2026-09-02.** This contract implements
> the Article Ingest boundary approved by
> `docs/constitution/NHK_V3_CONSTITUTION.md`. It does not create an Article
> Authority entity, a second editorial body, a Graph `article` endpoint, a new
> status enum or a new operation name.

## Purpose and ownership

This is an operation-level contract for a request that intends to create,
update or publish a V3 knowledge Article with semantic claims or relations.
WordPress native `wp_posts` remains the sole owner of the editorial title,
body, metadata and public editorial URL. Authority owns registered canonical
entities; Knowledge owns atomic claims; Source/Evidence owns provenance and
support; Graph owns typed relations; Governance owns durable semantic mutation.

No body is copied into Knowledge or Graph. Article, FAQ, Search and hub surfaces
reuse registered records and do not become semantic owners.

All public promotional/commercial Article copy is additionally subject to
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md`. That policy
applies to title, excerpt, body, generated summary, contextual image copy and
SEO/meta projection where those surfaces make promotional claims. Compliance
review does not transfer editorial ownership away from WordPress and does not
turn generated copy into Evidence.

## Required stage order

1. Resolve all semantic references through the runtime registries. Subject
   identity precedence is canonical UUID, stable key, then exact canonical
   name/alias. Ambiguous, unknown or unsupported type, endpoint, field,
   predicate, target or identity fails closed; a valid explicit UUID is never
   ignored.
2. Run semantic preflight for required claims, sources/evidence, relation
   direction, readiness, provenance, authorization and expected revisions.
   Generic Article preflight resolves subjects only through the shared
   canonical resolver; it never hard-codes a WordPress Post ID. A concrete
   `wp_post` stable key may identify the editorial target only where the
   operation contract explicitly requires an existing Post.
3. Reconcile Article Media through the governed Media V3 flow. Every file
   ingest/adoption validates the actual image payload, fails closed for
   corrupt/fake/unreadable bytes, retains the source-original PRIVATE and
   exposes only eligible optimized derivatives PUBLIC under the same canonical
   Media identity. Attachment state is projection/storage only. The result
   must expose representative/evidence according to the Media projection
   contract and remain idempotent.
4. Create or update the native WordPress Post as a draft. The Post identity is
   the registered `wp_post` endpoint with stable key `<blog_id>:<post_id>`.
5. Submit and apply semantic mutations through the existing
   Proposal → Human Approval → Eligibility → Controlled Apply → repository →
   audit boundary. Direct Graph or semantic repository writes are not a
   substitute.
6. Read back the semantic records, Graph relations, Media assets/usages,
   representative/evidence projection and WordPress Post. Verify
   canonical identity, revisions, visibility, relation direction, provenance
   and public projection eligibility.
7. Before publication of promotional/commercial copy, run the public-claim
   compliance gate over the rendered Article and its public projections. An
   unsupported objective or superiority/uniqueness/absolute claim must be
   evidence-bound, genuinely narrowed by rewrite, or blocked for human review;
   synonym substitution alone is not a compliant rewrite.
8. Publish the WordPress Post only when all required stages have satisfied this
   contract. Generic WordPress publication remains independently valid, but it
   is not a completed V3 knowledge Article workflow without these stages.

## Completion and failure

A completion claim requires success of every required editorial, semantic,
verification and applicable public-claim compliance stage. A required semantic,
compliance or unavailable dependency failure must remain an explicit
non-success, retryable, unavailable, conflict or equivalent outcome defined by
the eventual approved runtime contract. This document does not reserve or
invent a closed outcome vocabulary.

The runtime implementation must preserve canonical UUID/stable-key identity,
optimistic revision, typed relation, provenance, readiness, idempotency, public
identity and fail-closed invariants. Cross-boundary idempotency, WordPress
revision binding, durable outcome recording, public-claim policy versioning and
observability are follow-up implementation requirements, not claims of current
runtime support.

## Explicit exclusions

- No Article Authority type, Article body projection, FAQ entity or `article`
  Graph endpoint.
- No identity derived from prose, title, body, URL, slug, checksum or display
  name.
- No legacy article-body migration, import, parse or population.
- No call from Article Ingest to `V2MigrationService.php`.
- No direct `PostKnowledgeLinkService` Graph mutation outside
  Governance/Controlled Apply; if such a reachable path exists, record
  `CONSTITUTION_CONFLICT` and close it in a reviewed implementation slice.
- No post-55 delete, replacement, slug/URL change, body-copy change or duplicate
  semantic identity as part of this contract.
- No generated ranking, award, uniqueness, market-leadership or other strong
  promotional assertion may be treated as Evidence merely because it appears
  in an Article draft or AI-generated copy.

## Current implementation status

Phase 1 implements the reconcile-only coordinator, durable operation receipt,
deterministic child proposal planning, read-only editorial fingerprinting,
semantic/editorial verification, diagnostics and the coordinated MCP surface.
The receipt is orchestration/recovery state and never stores the full Article
body. Semantic writes remain behind Governance and Controlled Apply.

`nhk.article.preflight` is read-only; `nhk.article.ingest` remains the governed
execute/resume surface for reconciliation. The separate typed draft gateway
supports draft-only create/update with receipt idempotency and native
state-token CAS; it does not publish, trash, apply semantic proposals, ingest
Media/Video or copy body into semantic storage. Draft results remain blocked
for publication until later semantic, media, compliance, rendered-public
verification and read-back gates complete. Rendered verification preserves
stored-state, rendered-state, public-route-state and unavailable-runtime
evidence; a stored DTO pass is not a public pass. Publication receipts record
body-free cross-boundary evidence, and uncertain native transitions are resolved
by Post read-back before a retry may act.
Production Post 55 execution remains outside this implementation and requires
the separate human-reviewed reconciliation packet.

The public-claim compliance law is documentation-approved, but automated claim
classification/evidence validation across every output channel is not claimed
implemented by this file. Until runtime support is verified, publication uses
human review and the shared compliance contract rather than silently assuming a
pass.

Subject resolution is shared by search/inventory and Article preflight. The
generic preflight has no WordPress Post-ID exception; concrete Post IDs may
appear only as test fixtures.


## Media, image and Living Knowledge reuse boundary — 2026-09-04

Article editorial storage and semantic storage remain deliberately separate.
The WordPress Post owns title/body/excerpt, editorial image ordering and public
editorial URL. Article Ingest receipts, Governance records and Knowledge
repositories must not persist a duplicate copy of the Article body as semantic
truth.

Article media must reuse canonical `Media` where available. A new upload enters
the governed Media boundary, retains the source-original as a private/protected
MediaAsset and projects normalized WebP/responsive/WordPress attachment outputs
under the same Media identity. Featured/inline selection remains WordPress
editorial state; `MediaUsage` records contextual role/SEO metadata and does not
itself create a Graph edge or Knowledge/Evidence.

An Article that repeats an existing fact should resolve and reuse the canonical
Knowledge/Source/Evidence chain rather than minting a duplicate claim from its
prose. New factual observations extracted from Article research/body context are
planning inputs only until they pass the shared Living Knowledge planner and
normal Governance lifecycle. A Knowledge update may produce an Article update
suggestion, but it never rewrites a published WordPress body automatically.

Video and Media references embedded in an Article retain their own bounded
identity and storage. A Video `about` relation or Media `depicts` relation does
not make the Article body Evidence. Likewise, alt/caption/OCR/generated copy is
not Evidence merely because WordPress renders it publicly.

Downstream systems must therefore reuse canonical IDs plus revisions and verify
read-back across the owning boundary: WordPress for Article editorial state,
Media/MediaAsset/MediaUsage for image state, Video for external-reference state,
Knowledge/Source/Evidence for factual state, and Graph only for registered typed
relations.

## Runtime acceptance boundary

Media and semantic identity operations remain separate inside this chain:
Article subject resolution or semantic rekey must not mutate WordPress
attachment metadata, physical filenames, derivatives or inline URLs. Any
basename normalization requires the independent governed Media operation and
its complete checksum/HTTP read-back evidence.

The acceptance path is one evidence chain, not a collection of isolated unit
passes:

`real image file → governed file ingest/adoption → WordPress attachment → one
canonical Media identity → source-original PRIVATE + PUBLIC derivatives →
MediaAsset/MediaUsage read-back → representative/evidence entity projection →
Article preflight subject resolution`.

The chain is accepted only when each boundary is proven with the real
integration runtime. Focused unit tests may prove local behavior but cannot be
reported as runtime acceptance or as completion of an unrun WordPress test.
