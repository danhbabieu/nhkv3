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
3. Create or update the native WordPress Post as a draft. The Post identity is
   the registered `wp_post` endpoint with stable key `<blog_id>:<post_id>`.
4. Submit and apply semantic mutations through the existing
   Proposal → Human Approval → Eligibility → Controlled Apply → repository →
   audit boundary. Direct Graph or semantic repository writes are not a
   substitute.
5. Read back the semantic records, Graph relations and WordPress Post. Verify
   canonical identity, revisions, visibility, relation direction, provenance
   and public projection eligibility.
6. Before publication of promotional/commercial copy, run the public-claim
   compliance gate over the rendered Article and its public projections. An
   unsupported objective or superiority/uniqueness/absolute claim must be
   evidence-bound, genuinely narrowed by rewrite, or blocked for human review;
   synonym substitution alone is not a compliant rewrite.
7. Publish the WordPress Post only when all required stages have satisfied this
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
