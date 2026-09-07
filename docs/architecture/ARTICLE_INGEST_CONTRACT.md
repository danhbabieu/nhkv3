# V3 Article Ingest Contract

> **NON-NORMATIVE.** Đây là contract/guidance triển khai được phê duyệt dưới
> Hiến pháp. Nếu mâu thuẫn với `docs/constitution/NHK_V3_CONSTITUTION.md`,
> Hiến pháp kiểm soát.

> **APPROVED DOCUMENTATION CONTRACT — reconciled 2026-09-07.** This contract
> implements the Article Ingest boundary. It does not create an Article
> Authority entity, a second editorial body, a Graph `article` endpoint, a new
> status enum or a new semantic owner.

## Purpose and ownership

This is an operation-level contract for a request that intends to create,
update or publish a V3 knowledge Article with semantic claims or relations.
WordPress native `wp_posts` remains the sole owner of editorial title, body,
metadata and public editorial URL. Authority owns registered canonical entities;
Knowledge owns atomic claims; Source/Evidence owns provenance/support; Graph owns
typed relations; Governance owns durable semantic mutation.

No body is copied into Knowledge or Graph. Article, FAQ, Search, hub, editorial
Note and workspace annotations do not become semantic owners by containing facts.
A note/research annotation remains editorial context unless its fact is promoted
through canonical subject resolution, reconcile, Source/Evidence/Knowledge and
Governance.

All public promotional/commercial Article copy is additionally subject to
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md`.

## Required stage order

1. **Resolve semantic owners and subject.** Resolve by canonical UUID → stable
   key → exact canonical name/alias. Unknown/ambiguous type, endpoint, predicate,
   field or target fails closed. A valid explicit UUID is never ignored.
2. **Reconcile before create.** Inventory current Authority, Knowledge,
   Source/Evidence and relevant Graph relations. Classify each intended new
   semantic object as exact existing, merge/update candidate, related-but-
   distinct, genuinely new or uncertain. Fuzzy/keyword/prose similarity is not
   identity proof. Uncertain candidates are deferred rather than created.
3. **Enforce no-orphan Knowledge.** Resolve the canonical subject/context before
   a new claim is created. If a genuinely missing Authority node is required,
   create/apply/read it back first. If the subject cannot be resolved or safely
   created, defer the claim; do not “create now, attach later”.
4. **Run semantic preflight.** Verify required claims, sources/evidence,
   registered relation direction, readiness, provenance, authorization,
   revisions, idempotency and public/compliance planning. Generic preflight does
   not hard-code a WordPress Post ID.
5. **Reconcile Article Media.** Use the governed Media V3 flow: validate actual
   bytes, reconcile/reuse canonical Media, retain source-original PRIVATE/
   protected, create eligible derivatives under the same Media identity, create
   contextual usages, and read Media/Asset/Usage/attachment projection back.
6. **Create/update native WordPress draft.** The Post remains the editorial
   owner. Registered `wp_post` endpoint stable key is `<blog_id>:<post_id>`.
7. **Run semantic mutations through full Governance.** For Authority,
   Knowledge, Source, Evidence and Graph mutations use `proposal create/ingest →
   submit → review → approval with binding fingerprints → eligibility →
   Controlled Apply → canonical owner read-back → idempotency verification`.
   Direct Graph or semantic repository writes are not substitutes.
8. **Verify cross-owner read-back.** Read Authority/Knowledge/Source/Evidence,
   Graph, Media and WordPress through their canonical owners. Verify identities,
   revisions, visibility, relation direction, provenance, Media usage and
   projection eligibility. Proposal/apply state alone is not completion.
9. **Run public-claim compliance** over rendered/public projections when
   applicable. Unsupported objective or superiority/uniqueness/absolute claims
   are evidence-bound, genuinely narrowed, or blocked for review.
10. **Publish WordPress only after required gates.** Generic WordPress
   publication remains independently valid, but it is not a completed V3
   knowledge Article workflow without required semantic/read-back stages.

## Semantic research and promotion

Article research/body text can discover candidate facts, but the Article payload
is never a semantic writer. A repeated fact resolves/reuses existing canonical
Knowledge. A genuinely new fact must leave the editorial workspace and enter the
normal Source/Evidence/Knowledge/Governance path before it can be called
canonical.

Dictionary lexical matches, keyword overlap, title/body similarity, AI synthesis
and research notes may help discovery; they do not prove canonical identity.
New relation candidates must use registered Graph vocabulary and canonical typed
endpoints. Missing predicate remains `REGISTRY_GAP`; broad `about` is not a
workaround for classification membership, structural/configuration relations or
Product–Specimen ownership.

## Completion and failure

A completed V3 Article operation requires every required editorial, semantic,
media, verification and compliance stage. `DRAFT`, `SUBMITTED`, `APPROVED`,
`ready=true`, HTTP success or Controlled Apply response is not enough. Each
semantic mutation must be confirmed by canonical owner read-back.

Second-run/idempotency acceptance requires no duplicate Authority node,
Knowledge claim, Source, Evidence, Media, Video, active relation or publication
side effect for unchanged durable intent.

Required failure remains explicit: retryable, unavailable, identity/revision
conflict, registry/evidence gap, review-required or another contract-defined
non-success. Rate-limit/runtime interruption reuses an existing proposal/
idempotency binding; it does not mint a replacement proposal for the same
intent.

## Explicit exclusions

- No Article Authority type, Article body projection, FAQ/Note semantic entity or
  `article` Graph endpoint.
- No identity derived from prose, title, body, URL, slug, checksum or display
  name.
- No legacy article-body migration/import/parse/population.
- No call from Article Ingest to `V2MigrationService.php`.
- No direct `PostKnowledgeLinkService` Graph mutation outside
  Governance/Controlled Apply.
- No Article payload/frontend/template mutation that creates semantic truth.
- No generated ranking/award/uniqueness/market-leadership claim treated as
  Evidence merely because it appears in draft/generated copy.
- No automatic Article body rewrite from a later Knowledge change; use
  suggestion/governed editorial flow.

## Current implementation status

`nhk.article.preflight` is read-only and includes reconciliation/research
planning. `nhk.article.ingest` is the governed execute/resume coordinator for
its supported Article reconciliation boundary. Native Article draft/create/
update and publication operations remain WordPress editorial operations with
state-token/CAS and publication gates; semantic child changes remain separate
Governance operations.

Article receipts are orchestration/recovery state and never store the full body.
Rendered public verification remains distinct from stored DTO verification;
uncertain native publication transitions require WordPress read-back before a
retry acts.

Subject resolution is shared with current semantic resolver/inventory. Concrete
Post IDs may be fixtures/operation targets but are never hard-coded semantic
resolution exceptions.

## Media/Image reuse boundary

Article media reuses canonical Media where available. A new upload enters the
shared governed Media boundary, retains source-original as private/protected
MediaAsset and keeps optimized/WordPress derivatives under the same Media.
WordPress owns featured/inline ordering; MediaUsage stores contextual role/SEO
metadata and does not itself create Graph or Knowledge/Evidence truth.

Media view/detail concepts and semantic relations remain separate. Alt/caption/
OCR/filename/recognition may feed research only. If the Article asserts a Media
semantic relation, canonical subject resolution and registered Graph/Governance
are required.

## Video reuse boundary

Video referenced in Article retains its own canonical external identity and
provenance. Article body text does not become Video Evidence. A guided Video
relation may have its own canonical Source→Claim→Evidence provenance chain; the
Article only projects/references that canonical truth and does not duplicate it.

## Runtime acceptance boundary

Acceptance is a chain, not isolated unit passes:

`real image file → governed ingest/adoption → WP attachment projection → one
canonical Media → source/derivatives/usages read-back → Article preflight →
canonical subject/Knowledge/Source/Evidence reconciliation → governed semantic
apply/read-back → rendered/publication verification`.

Runtime acceptance is claimed only for stages actually observed in the exact
authorized environment. Historical environment blockers remain dated evidence,
not timeless proof that current semantic writers or Graph read-back are
unavailable.
