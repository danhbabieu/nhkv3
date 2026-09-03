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

### MCP direct image attachment checkpoint — 2026-09-03

`nhk.media.ingest` now has a direct WordPress binary adapter for a multipart
`file` parameter. It sanitizes the explicit filename and processes a temporary
copy with EXIF auto-orientation, aspect-preserving `max_width`/`max_height`
resize and requested encoder `quality` before the processed bytes enter the
WordPress Media Library. The original camera upload is never copied to uploads
and temporary workfiles are removed after the request. The result includes the
attachment ID, canonical URL, final filename, MIME, dimensions, filesize and
WordPress derivatives. `nhk.media.attachment.get` provides the same reader-safe
read-back shape.

This path is adapter-only: it does not infer or create NHK semantic Media,
Knowledge, Evidence or Graph relation from image content. The native attachment
adoption hook is guarded for this path. Metadata-only `nhk.media.ingest` remains
the governed Media/MediaAsset/MediaUsage proposal workflow.

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
selection and block ordering. The WordPress bridge now owns the one-to-one
Media/Asset-to-attachment mapping, native Featured/inline synchronization,
managed Gutenberg inline replacement, attachment adoption and responsive
attachment representation. Byte upload is supported for controlled packets
that provide a local file path; metadata-only MCP ingest remains metadata-only.

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

### Phase R2 bridge implementation — 2026-09-02

The approved R2 bridge is implemented in the working tree. Migration 012 adds
`nhk_media_wordpress_attachments` with unique Media and attachment identities;
it is an infrastructure mapping table and does not create a semantic Graph
relation. `WordPressMediaAttachmentBridge` reuses that mapping or the native
`_wp_attached_file` identity before creating a new attachment. Controlled
packets apply `MediaFilenameNormalizer` to the actual WordPress upload name;
native `add_attachment` adoption uses the same canonical Media service and an
instance-scoped controlled-write guard prevents double ingestion.

Native `wp_after_insert_post` and `rest_after_insert_post` both invoke the
Article coordinator. Native attachment creation, admin edits and REST edits
all enter the same adoption boundary. The bridge writes `featured_media`,
appends or repoints only the marked `nhk-managed-inline-primary` Gutenberg
block, preserves human inline images, reads the editorial state back and
rejects a changed state token. Article Ingest refreshes its editorial token
after this authorized media write, so its normal unchanged path does not
self-report `EDITORIAL_STATE_CHANGED`.

The same attachment representation now supplies public URL, `src`, `srcset`,
`sizes`, dimensions and contextual alt to Article SEO. Theme `og:image` and
Article structured data use that same projection. WordPress registers a
projection image sitemap provider that emits only eligible real public
featured assets, once per URL; placeholders/private/unmapped assets are
excluded. The historical checkpoint's MCP wire smoke checked the then-approved
21-tool catalog; the current catalog additionally exposes the direct attachment
read-back tool and is verified as 22 tools in the 2026-09-03 checkpoint below.

Static evidence: full Unit suite `265` tests / `1,355` assertions, Composer
validation, full PHP lint and `git diff --check` pass. Live WordPress,
migration, Article/MCP REST, sitemap and HTTP evidence is blocked in this
shell by the unavailable MySQL bootstrap. No legacy Post, attachment, V2,
staging or production data was repaired, renamed, backfilled or published.

### Phase R2 local runtime recovery — 2026-09-02

The earlier database-bootstrap block was revalidated after preserving the R2
worktree. The primary outage classification is **MYSQL_DAEMON_DOWN**: the
server log records a user-signal shutdown followed by a clean Homebrew
`mysqld_safe` restart, with duplicate-wrapper messages immediately before the
shutdown. Direct TCP/socket, authentication, database-existence, PHP mysqli,
WordPress bootstrap and HTTP health probes now pass. MySQL is PID `64761` on
`127.0.0.1:3306`, socket `/tmp/mysql.sock`, datadir `/opt/homebrew/var/mysql`.

Fresh proof is `composer preflight` 10/10, MCP wire smoke pass, R2-focused
tests `110 / 871`, and Unit suite `265 / 1,355`. Migration 012 mapping and
Blueprint tables were read back successfully. The guarded full suite reaches
the database but remains non-green on the existing retired V2-writer,
malformed-asset and route/identity contracts; frontend route smoke is 44/46.
This recovery performed no R2 source change, legacy repair, attachment write,
semantic write, Graph write or migration against development `nhk_v3`.
