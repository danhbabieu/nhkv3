# NHK V3 Frontend Route Inventory — 2026-08-31

This is a source-level route inventory. It records the intended public
contracts and implementation evidence; it is not a browser smoke pass or a
V2 URL-parity claim.

| Surface | Public route contract | Query/template owner | Current evidence | Runtime status |
|---|---|---|---|---|
| Homepage | `/` | WordPress theme `front-page.php` and native WP queries | Real editorial query loop, no fixture content | HTTP and desktop visual smoke pass; responsive QA pending |
| Tri thức / Góc chia sẻ | `/tri-thuc/`, `/goc-chia-se/` plus paginated aliases | Native WP category query via `PublicEditorialRoutes` | Category-aware archive and empty states; no editorial body projection | HTTP rewrite smoke passes; responsive visual QA and V2 URL reconciliation remain |
| Authority archive | `/{type}/`, `/{type}/page/{n}/` for nine registered types | `EntityPageQuery` → `PublicEntityRoutes` → `entity.php` | Active-only pagination and type catalog | HTTP smoke and desktop archive visual QA pass; responsive QA pending |
| Authority detail | `/{type}/{stable-key}/` and UUID detail | `EntityPageQuery` → `entity.php` | Stable-key/UUID lookup, semantic facts, Graph-related groups | HTTP smoke and desktop detail visual QA pass; responsive QA pending |
| V2 archive/detail aliases | `/thuong-hieu/`, `/hien-vat/`, `/am-nhac/`; `/{brand-slug}/`, `/{brand-slug}/{model-slug}/` | `PublicEntityRoutes` → canonical Authority type context or fail-closed slug resolver | Archive aliases preserve discoverability while canonical links remain `/brand/`, `/specimen/`, `/music/`; a detail slug redirects only for one active match and never overrides a native WP route | Archive HTTP smoke passes; detail compatibility is unit-tested and runtime evidence remains dependent on local WordPress availability |
| Search | Native `/?s={term}`, V2 alias `/tim-kiem/?q={term}`, plus `/wp-json/nhk/v1/search` | `NHK_V3_Search_Page_Query` + `SearchSemanticQuery` + `SearchApi` + `PublicEditorialRoutes` compatibility redirect | Post results remain native; V2 `q` is preserved as native `s`; active Authority/Media/Video/Knowledge results are grouped and linked | Native route/REST, alias 301 and desktop browser smoke pass; responsive QA pending |
| Comparison | `/comparison/?a={type/stable-key}&b={type/stable-key}` | `ComparisonPageQuery` → `PublicComparisonRoutes` → `comparison.php` | Read-only side-by-side semantic facts from two active Authority references; no comparison table or editorial body is persisted | HTTP 200, dedicated metadata and desktop visual smoke pass; responsive QA pending |
| Post single | `/{post-slug}/` | Native WordPress single + `nhk_v3_post_related_content` | Editorial body remains native; Graph-derived related groups render only when available | HTTP and desktop visual smoke pass; Graph-related fixture coverage is unit-tested |
| Media archive | `/thu-vien/`, `/media/`, `/media/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `media.php` | Active-only archive, honest empty state | HTTP smoke and desktop archive visual QA pass; responsive/gallery QA pending |
| Media detail | `/media/{uuid}/` | `MediaVideoPageQuery` → `media.php` | Readiness-aware asset metadata and usage facts | Active local Media detail 200 and desktop visual smoke pass; PRIVATE/draft asset state renders an honest empty state; pending asset delivery/privacy policy and responsive visual QA |
| Video archive | `/video/`, `/video/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `video.php` | Active-only external references and empty state | HTTP smoke and desktop empty-state visual QA pass; responsive QA pending |
| Video detail | `/video/{uuid}/` | `MediaVideoPageQuery` → `video.php` | YouTube privacy embed only for validated 11-char IDs | Route contract and source-level embed validation pass; active-record browser coverage pending |
| 404 / pagination | Theme 404 and route-level page links | Theme templates | Empty states and bounded pagination are implemented | Core 404/route smoke and desktop 404 visual QA pass; pagination/responsive QA pending |

Admin semantic lookup covers Media, Video, Knowledge Claim, Source and Graph
endpoints (`wp_post` included) through the existing read APIs. Governed proposal
composer covers entity and Graph relation commands; lifecycle application still
requires capability, approval, eligibility and Controlled Apply.

The read-only smoke harness is `php tools/frontend-route-smoke.php
--base-url=http://localhost`. Representative concrete routes can be added
without fixture assumptions, for example
`--post-url=/a-real-post/ --brand-url=/brand/a-real-brand/
--model-url=/model/a-real-model/`. It expects 200 for the core public routes
and 404 for a deliberately unknown route; it reports connection failures
instead of turning an unavailable runtime into a false pass.

An initial attempt on 2026-08-31 against `http://localhost` occurred before
the local WordPress rewrite file was present and returned Apache 404. After
the rewrite file, empty-editorial handling and V2 archive aliases were added,
the current smoke covers homepage, editorial aliases, all Authority archives,
V2 archive aliases, Video, Media, Knowledge and deliberate 404. Concrete
post/brand/model detail paths remain opt-in to the smoke command because no
fixture URLs are claimed; `/hello-world/` is used as the native Post smoke. V2
detail slugs are resolved only from active Authority names and remain
fail-closed when a native WordPress route or ambiguous identity is present.

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

The guarded WordPress suite and local route/runtime smoke now pass. Remaining
evidence is intentionally bounded to responsive/tablet/mobile visual QA,
active-record Video detail coverage, public asset/source policy, V2 URL and
field reconciliation, and production/deployment gates. A real V2 live
read-only API source remains absent; the recorded restored-backup artifacts
are the authoritative local inventory evidence.
