# NHK V3 Frontend Route Inventory — 2026-08-31

This is a source-level route inventory. It records the intended public
contracts and implementation evidence; it is not a browser smoke pass or a
V2 URL-parity claim.

| Surface | Public route contract | Query/template owner | Current evidence | Runtime status |
|---|---|---|---|---|
| Homepage | `/` | WordPress theme `front-page.php` and native WP queries | Real editorial query loop, no fixture content | Pending browser smoke |
| Tri thức / Góc chia sẻ | `/tri-thuc/`, `/goc-chia-se/` plus paginated aliases | Native WP category query via `PublicEditorialRoutes` | Category-aware archive and empty states; no editorial body projection | Pending rewrite/browser smoke and V2 URL reconciliation |
| Authority archive | `/{type}/`, `/{type}/page/{n}/` for nine registered types | `EntityPageQuery` → `PublicEntityRoutes` → `entity.php` | Active-only pagination and type catalog | Pending WP rewrite smoke |
| Authority detail | `/{type}/{stable-key}/` and UUID detail | `EntityPageQuery` → `entity.php` | Stable-key/UUID lookup, semantic facts, Graph-related groups | Pending WP rewrite smoke |
| Search | Native `/?s={term}` plus `/wp-json/nhk/v1/search` | WordPress search + `SearchApi` | Posts and active semantic domains are grouped | Pending REST/runtime smoke |
| Media archive | `/thu-vien/`, `/media/`, `/media/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `media.php` | Active-only archive, honest empty state | Pending WP rewrite/gallery smoke |
| Media detail | `/media/{uuid}/` | `MediaVideoPageQuery` → `media.php` | Readiness-aware asset metadata and usage facts | Pending asset delivery/runtime smoke |
| Video archive | `/video/`, `/video/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `video.php` | Active-only external references and empty state | Pending WP rewrite smoke |
| Video detail | `/video/{uuid}/` | `MediaVideoPageQuery` → `video.php` | YouTube privacy embed only for validated 11-char IDs | Pending embed/browser smoke |
| 404 / pagination | Theme 404 and route-level page links | Theme templates | Empty states and bounded pagination are implemented | Pending browser smoke |

Admin semantic lookup covers Media, Video, Knowledge Claim, Source and Graph
endpoints (`wp_post` included) through the existing read APIs. Governed proposal
composer covers entity and Graph relation commands; lifecycle application still
requires capability, approval, eligibility and Controlled Apply.

## Guardrails

- Templates consume application contexts; they do not query database tables.
- Media binary storage keys are displayed as metadata until a public delivery
  policy and migrated asset mapping are verified.
- Video remains a canonical external-reference entity. The public template
  does not introduce local MP4 storage or download behavior.
- Rewrite registration is conditional on a usable WordPress `$wpdb`; activation
  flushes rewrite rules. The route table must be verified in a live WordPress
  runtime before any parity status is upgraded.

## Pending evidence

The full WordPress test command was attempted with
`NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public`; the local WordPress
bootstrap stopped at “Error establishing a database connection”. A real V2
read-only export/API source is also absent. Therefore browser smoke, rewrite
resolution, asset delivery, V2 URL redirects, counts and migration mappings
remain open gates.
