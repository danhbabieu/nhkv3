# P6 Media + Video Foundation

> **NON-NORMATIVE.** This is implementation evidence. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Current status

The domain boundary and schema migration are implemented and migration 004 is
applied to `nhk_v3`. Persistence services and Media/Video Graph endpoint
resolvers are now implemented and covered by focused unit evidence; WordPress
database integration and legacy data mapping remain in progress.

## Media contract

`Media` is semantic identity. `MediaAsset` owns a binary storage key, checksum,
MIME and technical dimensions. `MediaUsage` owns placement and role. A checksum
is indexed as a duplicate candidate only; it never merges semantic Media
identities. The schema keeps Media, asset and usage in separate tables and does
not make an attachment or asset a Graph endpoint by default.

## Video contract

`Video` is an external reference. The initial adapter normalizes YouTube watch,
short, embed and `youtu.be` URLs to platform plus external ID and canonical URL.
It stores metadata and an optional thumbnail Media reference; it does not
download MP4 files or create a local binary implicitly.

## Migration

Migration `004` creates `nhk_media`, `nhk_media_assets`, `nhk_media_usages` and
`nhk_videos`. It is idempotent, UP-only on `nhk_v3`, and DOWN-guarded to
`nhk_v3_test`. No V2 data has been migrated.

## Persistence slice evidence

`MediaService` owns creation, idempotent identity lookup, optimistic updates,
lifecycle changes, asset registration and usage registration. `VideoService`
owns canonical external-reference ingestion, deduplication by platform/id,
optimistic updates and lifecycle changes. `Wpdb*Repository` implementations
persist the four P6 tables without treating checksum matches as merges.
`MediaEndpointResolver` and `VideoEndpointResolver` make canonical UUIDs
available to the shared Graph endpoint registry while retaining retired
records as resolvable identities.

Focused P6 evidence: 8 tests, 24 assertions; all unit evidence: 46 tests, 106
assertions; PHP lint and `git diff --check` pass. WordPress integration tests
require `NHK_WP_TEST_PATH` and were not runnable in the current shell.

## Media Ingest + Article Image SEO checkpoint — 2026-09-02

The approved Constitution amendment is implemented by the controlled registries
`MediaUsageRoleRegistry`, `MediaDetailTypeRegistry`, `SeoKeywordGroupRegistry`,
`MediaSeoStateRegistry` and `MediaDiagnosticCodeRegistry`. `MediaUsage` now
stores contextual alt, caption and keyword groups without collapsing Media
identity into placement.

`MediaIngestGateway` is the shared application boundary above `MediaService`.
Governed MCP/Admin Media proposals use the same `AuthorityProposalExecutor`,
and `ArticleMediaCoordinator` uses the same service for mandatory Article
roles. `MediaBatchIngestService` gives bulk packets one workflow batch context
while keeping each Media independently reviewable. No batch context or Media
usage creates a Graph edge.

New Posts receive one `featured_primary` and one `inline_primary` usage through
the WordPress `wp_after_insert_post` adapter. The coordinator reuses active,
ready Media with assets, otherwise creates distinct system placeholders and
persists one SEO Blueprint per mandatory slot in migration 011's
`nhk_article_media_blueprints` table. Reconciliation is idempotent and reports
placeholder, missing-slot and low-resolution diagnostics. The Article Ingest
receipt and MCP Article response expose the media diagnostics; preflight uses a
read-only preview.

`MediaFilenameNormalizer` replaces camera-style names before durable asset
persistence when packet context is available. `ArticleMediaSeoProjection`
excludes placeholders/private assets from preferred image and image-sitemap
eligibility. WordPress remains the editorial owner of featured/content image
selection and block ordering; an attachment-to-canonical-Media selection
adapter and byte-upload transport remain explicit implementation gaps.

Focused checkpoint evidence: `ArticleMediaPolicyTest` covers placeholders,
reuse, distinct slots, replacement, contextual usage, filename normalization,
keyword validation, batch context and sitemap eligibility. Legacy data and
Post 55 remain read-only audit work; no repair, rename, Graph edge or backfill
was executed.

### Phase R runtime proof — 2026-09-02

Migration 011 was rerun on `nhk_v3` at current/target `11/11` and its
`nhk_article_media_blueprints` schema was verified without duplicate mutation.
On `nhk_v3_test`, an isolated Article created two distinct placeholder usages
and Blueprints; two real local JPEGs were ingested with normalized canonical
asset storage keys and replacement reached `MEDIA_COMPLETE`. The persistence
round-trip, batch ingest and Product/Specimen Usage reuse checks passed.

The WordPress adapter gap is now runtime-proven: it does not set native
`featured_media` or insert/reconcile inline image placement in `post_content`.
The normalized canonical asset key is distinct from the WordPress attachment
basename, so public filename/attachment selection is not yet connected.
`ArticleMediaSeoProjection` returns eligibility for a real public asset but
`image_url` remains null; actual theme SEO image, responsive markup and image
sitemap integration are therefore still open. These are explicit runtime
failures, not silently counted as parity.
