# NHK V3 Media Ingest + Image SEO Design

> NON-NORMATIVE. If this document conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Scope and ownership

The implementation uses the existing NHK Core Media foundation. `Media` is
semantic identity, `MediaAsset` is binary/derivative storage metadata, and
`MediaUsage` is a contextual placement. None of these is an Authority entity
or a substitute for Graph truth. WordPress `wp_posts` remains the owner of
title, body, featured image selection, content blocks and editorial order.

The canonical write path is `MediaIngestGateway` → `MediaService` → the
existing repository boundary. Governed semantic intake reaches that boundary
through `AuthorityProposalExecutor`; MCP and the Admin proposal composer are
adapters, not alternate business-rule implementations. Article coordination
uses the same `MediaService`. No Media usage creates a Graph edge.

## Controlled vocabularies

`MediaUsageRoleRegistry` retains the five pre-existing roles and adds the
mandatory Article roles `featured_primary`, `inline_primary` and
`inline_supporting`. `MediaDetailTypeRegistry` owns the approved view/part
vocabulary, including whole views, dial/case/movement details, serial,
model-mark, stamp, label, engraving and component detail. These values are
metadata, never Authority entities.

`SeoKeywordGroupRegistry` provides the reusable groups `subject`,
`brand_context`, `model_variant_context`, `view`, `part`, `content_intent` and
`evidence_type`, each with a Vietnamese label and bounded synonyms.
`MediaSeoStateRegistry` owns the controlled completeness/quality states, and
`MediaDiagnosticCodeRegistry` owns the Article/media reason codes. Unknown
values fail at the domain boundary.

## Article media contract

`ArticleMediaCoordinator::ensureForPost()` receives a WordPress Post ID,
optional editorial context, selected Media IDs and supporting Media IDs. It
resolves each mandatory role in this order: explicit usable selection, current
usable usage, reusable active/ready Media with assets, then a system
placeholder. It never searches by checksum as identity and never creates a
Graph relation.

The coordinator reconciles one `featured_primary` and one `inline_primary`
usage for endpoint `wp_post:<blog_id>:<post_id>` and rejects reuse of the same
Media identity for both roles by selecting another candidate or the role's
placeholder. Existing usages are reused; the mutable WordPress repository
adapter can remove an old usage before a deliberate replacement. Repeated
calls are idempotent. Supporting images use `inline_supporting` and remain
zero-to-many.

The WordPress `wp_after_insert_post` adapter invokes this coordinator for
ordinary Posts after native WordPress writes. It skips revisions/autosaves,
reports infrastructure failures through the `nhk_v3_article_media_failure`
diagnostic action, and does not copy or reorder `post_content`. Article Ingest
reconciliation can invoke the same coordinator with `media_context` and
selected/supporting Media IDs. Its receipt carries the resulting `media`
diagnostics; `nhk.article.preflight` performs the read-only preview.

## Placeholder behavior

The coordinator creates or reuses two system Media identities:
`system:placeholder:featured_primary` and
`system:placeholder:inline_primary`. Their provenance marks them as system
placeholders. They have no real binary asset, cannot be Evidence, cannot be a
`depicts` relation, cannot be selected for preferred structured data and are
not image-sitemap candidates. Their presence yields `MEDIA_PLACEHOLDER` plus
slot-specific missing diagnostics.

## SEO Blueprint and contextual usage

`MediaSeoBlueprint` is persisted in `nhk_article_media_blueprints`, a
projection/control table, one row per Post and mandatory slot. It records
subject context, preferred view, keyword groups, planned title, filename stem,
alt intent, aspect, minimum dimensions, focal-point expectation, state and
revision. It exists even when a placeholder is bound. The repository is
`ArticleMediaBlueprintRepository` with the WordPress implementation
`WpdbArticleMediaBlueprintRepository`.

`MediaUsage` stores contextual `altText`, `caption` and keyword groups. The same
Media and assets can therefore have different Article, Product and Specimen
usage text without binary duplication. Keyword groups may feed a Blueprint or
carefully selected title/alt/caption/filename output; they never become
Knowledge, Graph, Classification, Authority or HTML `meta keywords`.

## Reuse, filenames, storage and derivatives

`ArticleMediaCoordinator` scores reusable Media by active/ready state, real
asset presence, subject/name evidence, detail type and minimum dimensions.
`MediaService` retains stable-key idempotency and checksum candidate behavior.
`MediaFilenameNormalizer` converts camera names such as `DSCF8291.JPG` into a
short ASCII-safe descriptive name with a deterministic suffix. It is applied
before durable asset persistence when the packet exposes a camera filename or
camera-style storage basename. A public asset storage key is not renamed when
semantic display names or SEO keywords change.

Originals and derivatives remain `MediaAsset` records under one Media. The
current runtime does not invent an AVIF/WebP generator or replace WordPress's
storage pipeline. Delivery continues through `PublicMediaAssetDelivery`, which
requires active/ready parent Media, allowlisted MIME, containment, size and
checksum. Only real public assets may be preferred for structured data or an
image sitemap; a derivative is not a new semantic Media.

## Batch, Evidence and Product/Specimen boundaries

`MediaIngestBatch` and `MediaBatchIngestService` provide a workflow context with
batch ID, source, uploader, default context, count and status. Each packet is
sent independently through `MediaIngestGateway`; per-image context overrides
the default. Batch membership is provenance/workflow data only and does not
create `depicts`, Product–Specimen linkage or any other Graph edge.

Detail types such as `SERIAL`, `LOGO`, `MODEL_MARK`, `STAMP`, `LABEL` and
`ENGRAVING` are candidate signals for an existing Evidence workflow. OCR,
recognition and visual matching are not implemented as canonical promotion;
promotion remains Evidence → Proposal → Human Approval → Eligibility →
Controlled Apply.

Specimen remains physical identity and Product remains listing/offer identity.
Both may use the same Media through separate usages. Product deletion does not
delete Media or Specimen. The current runtime has no approved Product–Specimen
Graph predicate, so linkage remains a documented REGISTRY_GAP/CODE_GAP and is
not inferred by this slice.

## MCP, Admin and Article Ingest

`nhk.media.ingest` already creates a governed proposal and now accepts the
controlled Article roles plus optional original filename. Controlled Apply
delegates to `MediaIngestGateway` when wired by Plugin. Admin's proposal
composer reaches the same Governance executor; it has no direct Media
repository write. Article Ingest receives the same Article coordinator and
returns normalized media diagnostics. Product, Specimen and future bulk
adapters must call `MediaIngestGateway`; no module-specific Media service is
authorized.

The existing WordPress Abilities allowlist remains read-only. No new MCP tool,
Authority type, Graph predicate, Article endpoint or Album entity is created.

## Public output and audit boundary

`ArticleMediaSeoProjection` selects exactly one real public featured asset for
preferred image output. It returns ineligible for missing, placeholder,
private or unavailable assets and exposes no UUID/stable key in public URL
construction. Theme/REST integration must continue to use semantic image
elements with width, height, `srcset`, `sizes` and contextual alt. A read-only
legacy audit may count missing slots, camera filenames, incomplete metadata,
low resolution, checksum candidates, orphan assets and Post 55 state; it must
not repair, rename, backfill, infer or publish anything.

## Enforcement matrix

| Law | Admin | MCP | Article Ingest | Bulk | Product | Specimen | Runtime | Test |
|---|---|---|---|---|---|---|---|---|
| Media/Asset/Usage separation | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
| Three Article roles, two distinct mandatory Media | ENFORCED | ENFORCED | ENFORCED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | ENFORCED | ENFORCED |
| Placeholder is incomplete/non-public | ENFORCED | ENFORCED | ENFORCED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | ENFORCED | ENFORCED |
| Reuse before duplicate | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
| Contextual usage SEO | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
| No automatic Graph/Evidence promotion | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
| Governance for semantic writes | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
| Product–Specimen linkage contract | GAP | GAP | NOT APPLICABLE | NOT APPLICABLE | GAP | GAP | GAP | ENFORCED |
| Legacy audit without repair | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED | ENFORCED |
