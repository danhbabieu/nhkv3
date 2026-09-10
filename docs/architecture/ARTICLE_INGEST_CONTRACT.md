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

## Single canonical entry point for new content

New Article/Post submissions do not begin at a draft writer, Article writer or
publication writer. The only normal entry point is `nhk.capture.ingest`, which
accepts text-only, knowledge-only text, images and the registered Video adapter
and creates one Capture plus one native draft by default. Capture then runs the
shared semantic core before Article composition and publication.

`nhk.article.ingest` and typed native draft/publication operations remain
internal/admin lifecycle boundaries for existing records or Capture-controlled
continuations. They require `nhk_internal_content_operations` at the MCP/Admin
surface; without it they fail closed with `DIRECT_WRITE_BLOCKED` and
`USE_CANONICAL_CAPTURE_FLOW`.

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
7. Run the bounded post-ingest semantic reconciliation: canonical search,
   neighborhood/Graph inspection, duplicate/reuse analysis, relation candidate
   discovery, evidence/provenance validation, governed application of every
   justified useful registered relation and final read-back. This stage must
   not maximize edge count or create weak/speculative relations.
8. Before publication of promotional/commercial copy, run the public-claim
   compliance gate over the rendered Article and its public projections. An
   unsupported objective or superiority/uniqueness/absolute claim must be
   evidence-bound, genuinely narrowed by rewrite, or blocked for human review;
   synonym substitution alone is not a compliant rewrite.
9. Publish the WordPress Post only when all required stages have satisfied this
   contract. Generic WordPress publication remains an owner maintenance
   capability for existing editorial records, but it is not a new-submission
   entry point or a completed V3 knowledge Article workflow without these
   stages.

## Completion and failure

A completion claim requires success of every required editorial, semantic,
post-ingest reconciliation, representative-media reconciliation and
verification/public-claim compliance stage. Ingest success, draft creation,
proposal creation or one owner read-back alone never authorizes `COMPLETE`. A
required semantic, compliance or unavailable dependency failure must remain an explicit
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
under the same Media identity. The normalized public image uses a 1200px
maximum long edge: dimensions at or below that bound are retained, larger
images are proportionally downscaled, and no upscale/crop/stretch is allowed.
Featured/inline selection remains WordPress editorial state; `MediaUsage`
records contextual role/SEO metadata and does not itself create a Graph edge or
Knowledge/Evidence.

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

## Existing-Capture continuation addendum

## Canonical subject handoff — 2026-09-10

Each Capture revision resolves Authority once and carries one immutable typed
resolution packet (`status`, canonical ID/type, stable key, canonical name,
revision, match reason/source and ambiguity/missing diagnostics) to Claim
retrieval, Video enrichment, Media reconciliation, Article composition and
Governance planning. An explicit canonical UUID takes precedence over prose;
child coordinators consume the packet and must not reinterpret it into a
broader Model or another subject. This preserves a resolved Variant as the
primary Capture subject even when a Video title also matches a Model.

The packet is established before historical Media reuse. Existing Media is
eligible only when persisted semantic subject scope proves a match; attachment
filename, title similarity, stale Article plans and global/unscoped fallback
are not scope proof. Missing scoped Media is an incomplete/placeholder
readiness state, while an actually invalid/corrupt blueprint remains a
system/contract failure. Existing Capture continuation uses `capture_id` at
this same boundary and never creates a second Capture or Article.

The same `nhk.capture.ingest` boundary may receive a text addendum targeted by
`capture_id`. The original Capture request and fingerprint remain immutable;
the addendum has its own idempotency key and fingerprint, is recorded as an
audit/revision event whose `capture_revision` is the resulting Capture
revision after the append, and reuses the existing Capture and native Post. A
changed payload under the same addendum key conflicts, while an unchanged
retry is idempotent. Addenda do not accept files, create a second Post or
re-adopt Media; text-only continuation may reuse an existing Media only when
its persisted subject scope matches the resolved canonical subject and cannot
use unscoped/global Media as a readiness fallback. If no eligible Media exists,
the wrong-variant usage is removed and the Article remains missing/placeholder.
Rejected addenda retain only a sanitized audit payload (`text`,
`subject_hints`, `observations` and `metadata`); file metadata and paths are
never persisted.
The bounded semantic resolver, Claim retrieval, Capture-owned Governance
proposal/apply/read-back and Article/MediaUsage reconciliation run again for
the existing Capture, followed by the same final read-back.

## Capture composition and current-state reconciliation — 2026-09-09

Một Capture mặc định tạo một Article draft; N assets tạo N canonical Media/
MediaAsset identities nhưng không tạo N Article. Mỗi asset giữ caption, alt,
description, observation và relation context riêng. Composition chỉ dùng ba
nguồn đã phân loại: user input, observation từ Media và canonical Claims đã
được chọn; không dump raw claim payload vào body. Trace máy đọc được phải giữ
Claim ID, revision, subject, relation path/role và evidence/provenance status.

Claim research đi từ input → resolved subjects → bounded Graph neighborhood →
related Claims → scope/provenance/evidence/relevance → editorial selection.
Không giới hạn ở exact Variant, nhưng cũng không nhảy qua far path. User input
phải giữ loại `OBSERVED_FROM_MEDIA`, `EXPLICIT_USER_KNOWLEDGE` hoặc
`CANONICAL_CLAIM`; reuse claim hiện hữu trước khi đề xuất claim mới. Related
Article chỉ là candidate/update khi semantic relation đã được chứng minh, không
được tạo từ keyword overlap đơn thuần.

MediaUsage và native WordPress attachment/content phải reconcile theo thứ tự ưu
tiên: usage hiện tại đã reconcile, attachment state read-back hiện tại, Media
plan của Capture hiện tại, rồi mới đến historical planning (chỉ diagnostic).
Stale inline image hoặc representative không được replay để ghi đè state mới.
Sau mỗi native write phải đọc lại token; nếu token đổi thì refresh và chạy lại
bounded preflight/reconcile một lần, không replay vô hạn. Article và Media
selected được coi là một publication unit; không publish khi còn placeholder,
inline stale/unrelated hoặc representative sai scope.

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
