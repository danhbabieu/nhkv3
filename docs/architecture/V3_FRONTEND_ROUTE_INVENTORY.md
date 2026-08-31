# NHK V3 Frontend Route Inventory — 2026-08-31

This is a source-level route inventory. It records the intended public
contracts and implementation evidence; it is not a browser smoke pass or a
V2 URL-parity claim.

| Surface | Public route contract | Query/template owner | Current evidence | Runtime status |
|---|---|---|---|---|
| Homepage | `/` | WordPress theme `front-page.php` and native WP queries | Real editorial query loop, no fixture content | HTTP and desktop/mobile visual smoke pass; 390px/768px route metrics and tablet visual check pass; homepage canonical `/` and `index,follow` verified |
| Tri thức / Góc chia sẻ | `/tri-thuc/`, `/goc-chia-se/` plus paginated aliases | Native WP category query via `PublicEditorialRoutes` | Category-aware archive and empty states; no editorial body projection | HTTP rewrite smoke, 390px/768px route metrics and mobile empty-state visual check pass; V2 URL reconciliation remains |
| Authority archive | `/{type}/`, `/{type}/page/{n}/` for nine registered types | `EntityPageQuery` → `PublicEntityRoutes` → `entity.php` | Active-only pagination and type catalog | HTTP smoke and desktop archive visual QA pass; all declared page-2 routes have 390px/768px metrics with no overflow, long title/key wrapping guards are in the theme, and `/model/page/2/` plus `/component/page/2/` visual checks pass at mobile/tablet; broader route-specific screenshots remain |
| Authority detail | `/{type}/{stable-key}/` and UUID detail | `EntityPageQuery` → `entity.php` | Stable-key/UUID lookup, semantic facts, Graph-related groups | HTTP smoke and desktop/mobile detail visual QA pass for a real active Brand; 390px/768px route metrics pass |
| V2 archive/detail aliases | `/thuong-hieu/`, `/hien-vat/`, `/am-nhac/`; `/{brand-slug}/`, `/{brand-slug}/{model-slug}/` | `PublicEntityRoutes` → canonical Authority type context or fail-closed slug resolver | Archive aliases preserve discoverability while canonical links remain `/brand/`, `/specimen/`, `/music/`; a detail slug redirects only for one active match and never overrides a native WP route | Archive HTTP smoke and detail 301 runtime checks pass; canonical links remain fail-closed |
| Search | Native `/?s={term}` with `paged` semantic pagination, V2 alias `/tim-kiem/?q={term}`, plus `/wp-json/nhk/v1/search?page={n}` | `NHK_V3_Search_Page_Query` + `SearchSemanticQuery` + `SearchApi` + `PublicEditorialRoutes` compatibility redirect | Post results remain native; V2 `q` is preserved as native `s`; active Authority/Media/Video/Knowledge results are grouped, bounded per page and linked; semantic totals drive navigation even when native posts are exhausted | Native route/REST, alias 301, semantic page-2 HTTP/browser smoke and desktop browser smoke pass; 390px/768px route metrics pass |
| Comparison | `/comparison/?a={type/stable-key}&b={type/stable-key}` | `ComparisonPageQuery` → `PublicComparisonRoutes` → `comparison.php` | Read-only side-by-side semantic facts from two active Authority references; no comparison table or editorial body is persisted | HTTP 200, dedicated metadata and desktop/mobile visual smoke pass; 390px/768px route metrics pass |
| Post single | `/{post-slug}/` | Native WordPress single + `nhk_v3_post_related_content` | Editorial body remains native; Graph-derived related groups render only when available | HTTP and desktop/mobile visual smoke pass for a real native post; Graph-related fixture coverage is unit-tested |
| Knowledge archive/detail | `/knowledge/`, `/knowledge/page/{n}/`, `/knowledge/claim/{uuid}/` | `KnowledgePageQuery` → `PublicKnowledgeRoutes` → `knowledge.php` | Public-ready active claims only; explicit unverified/non-public provenance is hidden; evidence is shown only when its source is active/public-ready, with reader-facing source title/type and locator fallback | HTTP smoke and Knowledge pagination visual checks pass at 390px/768px without overflow; claim readiness, source filtering and source presentation are unit-tested; final public provenance policy remains open |
| Media archive | `/thu-vien/`, `/media/`, `/media/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `media.php` | Active-only archive, honest empty state and bounded page links from query totals | Template contract now covers archive pagination; HTTP smoke and desktop archive visual QA pass; 390px/768px route metrics and `/media/page/2/` mobile screenshot pass without overflow; asset policy remains open |
| Media detail | `/media/{uuid}/` | `MediaVideoPageQuery` → `media.php` | Readiness-aware asset metadata and usage facts | Active local Media detail 200 and desktop/mobile visual smoke pass; PRIVATE/draft asset state renders an honest empty state; 390px/768px route metrics pass; asset policy remains open |
| Video archive | `/video/`, `/video/page/{n}/` | `MediaVideoPageQuery` → `PublicMediaVideoRoutes` → `video.php` | Active-only external references, empty state and bounded page links from query totals | Template contract now covers archive pagination; HTTP smoke and desktop empty-state visual QA pass; 390px/768px route metrics pass and `/video/page/2/` mobile empty-state screenshot pass; read-only local query confirms no active Video row, so active Video detail remains unavailable for visual QA |
| Video detail | `/video/{uuid}/` | `MediaVideoPageQuery` → `video.php` | YouTube privacy embed only for validated 11-char IDs | Route contract and source-level embed validation pass; active-record browser coverage pending |
| 404 / pagination | Theme 404 and route-level page links | Theme templates | Empty states and bounded pagination are implemented across Authority, Media, Video, Knowledge and Search | Core 404/route smoke and desktop 404 visual QA pass; all declared page-two routes and empty/404 states have 390px/768px metrics with no overflow, custom archive page-two states emit `noindex,follow`, current pagination links expose `aria-current="page"`, and `/model/page/2/`, `/component/page/2/`, `/media/page/2/`, `/video/page/2/`, `/knowledge/page/2/` and 404 visual checks pass; broader screenshot QA pending |

Admin semantic lookup covers Media, Video, Knowledge Claim, Source and Graph
endpoints (`wp_post` included) through the existing read APIs. Governed proposal
composer covers entity and Graph relation commands; lifecycle application still
requires capability, approval, eligibility and Controlled Apply.

The read-only smoke harness is `php tools/frontend-route-smoke.php
--base-url=http://localhost`. Representative concrete routes can be added
without fixture assumptions, for example
`--post-url=/a-real-post/ --brand-url=/brand/a-real-brand/
--model-url=/model/a-real-model/`. It also accepts data-gated detail-alias
checks in the form `--brand-alias=/legacy/|/brand/canonical/` or
`--model-alias=/legacy/model/|/model/canonical/`; these expect HTTP 301 and
verify the `Location` target. It expects 200 for the core public routes and
404 for a deliberately unknown route; it reports connection failures instead
of turning an unavailable runtime into a false pass.

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

The guarded WordPress suite and local route/runtime smoke have a recorded healthy
pass. Remaining evidence is intentionally bounded to
responsive/tablet/mobile visual QA,
active-record Video detail coverage, public asset/source policy, V2 URL and
field reconciliation, and production/deployment gates. A real V2 live
read-only API source remains absent; the recorded restored-backup artifacts
are the authoritative local inventory evidence.
