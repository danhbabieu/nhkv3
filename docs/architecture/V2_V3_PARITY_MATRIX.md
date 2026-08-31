# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Read-only route/behavior inventory recorded | HomePageQuery-driven responsive WordPress editorial discovery surface | N/A | PARTIAL | IN PROGRESS | Featured/latest/category/topics plus real Authority/Media/Video modules; visitor-facing homepage document/OpenGraph title and description are branded for NHK, custom Authority/Knowledge/Media/Video/Comparison archives retain route-specific title/description/canonical metadata without technical-description leakage, the theme has one warm NHK token source with legacy CSS rules removed, homepage sections/topics now hide when their destination is missing or malformed, a fresh desktop sweep across 14 public routes found expected H1/title, no overflow and no internal terminology, 390px/768px route metrics are overflow-free, and field-level V2 behavior reconciliation remains |
| Posts | 800 rows in restored read-only backup; 34 `nhk_article` rows plus native/system rows | Native `wp_posts` boundary | DEV ONLY: 36 | PARTIAL | IN PROGRESS | 36 safe native post/page rows imported; 764 domain-targeted rows remain explicit skips, now itemized as 742 domain records, 21 attachments and one global-styles record in `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md`; deterministic target mapping is still absent |
| Categories | 2 taxonomy rows in restored backup | Native WP categories | DEV ONLY: 1 | PARTIAL | IN PROGRESS | One native category imported; non-category taxonomy skipped; URL ledger pending |
| Tri thức / Góc chia sẻ / Tin tức | Read-only route/behavior inventory recorded | WP category contract | NO | PARTIAL | IN PROGRESS | Category-aware sections and honest empty states are implemented; field-level route/content reconciliation remains |
| Brand / Model / Variant / Movement / Music / Component / Classification | 4 / 30 / 42 / 18 / 11 / 91 / 174 entity rows | Authority registry/core + canonical entity read API + frontend routes | DEV ONLY: exact counts | PARTIAL | IN PROGRESS | Exact UUID/stable-key rows imported into local dev; public Entity REST/theme payloads are allowlisted by registered canonical fields; semantic field review remains |
| Specimen / Product | No rows found in selected full V2 backup | Registry-backed Authority contract + frontend routes | NO | PARTIAL | IN PROGRESS | Absence is recorded from this backup; source/API confirmation and retirement reason remain pending |
| Media / Video | 242 media entities, 3 assets, 0 usage and 0 visual-video rows | P6 domain/persistence + public archive/detail routes + fail-closed asset delivery | DEV ONLY: 242 Media + 3 assets | PASS | IN PROGRESS | Media identities and field-level asset metadata/PRIVATE visibility imported; governed MCP `nhk.media.ingest` persists a complete identity/asset/usage packet with PRIVATE-by-default assets and governed `nhk.video.ingest` persists validated YouTube external references; public Media discovery now requires active + `readiness=ready` across REST/theme/home/search/Graph-related paths, while internal governance/MCP reads retain drafts; public reads and asset delivery suppress all three imported assets until delivery/privacy policy and source-file availability are approved; binary delivery additionally requires the parent Media to be active and ready, then verifies allowlisted MIME, storage-root containment, size and SHA-256; read-only recovery audit confirms the referenced V2 root and exact local legacy filenames are absent, so no asset mapping is inferred; public Video REST/theme detail uses only validated external-reference display fields; V2 usage inventory is exactly zero; active Video public detail data remains unavailable |
| Knowledge Claim / Source / Evidence | 655 knowledge entities, 19 evidence, 40 citations, 242 Knowledge relations | P7 contracts, schema, service and governed lifecycle | DEV ONLY: 655 claims + 19 sources + 40 evidence | PASS | IN PROGRESS | Claims, sources and citation evidence imported with endpoint, verification and citation metadata; governed MCP ingest proves claim/source/evidence create → submit → approve → apply with optimistic repository resolution; public claim reads now require active/public-ready readiness, explicit unverified/non-public claim/source/evidence states are fail-closed, Source/Evidence default PRIVATE when visibility is omitted, explicit publication uses `visibility=PUBLIC`, Evidence metadata now persists through governed ingest, V2 Source migration preserves top-level visibility/verification/review-state/legacy-id metadata and canonical normalized `source_type` from top-level or metadata fields, archived/retired replay states are inactive regardless of normalized field placement, public metadata/provenance blobs are omitted, and reader-facing evidence fields remain; all V2 source/evidence rows retain PRIVATE state pending public provenance policy |
| Relations / Post Graph | 185 Graph relation rows plus 242 Knowledge relations | Graph Core + Post/Knowledge endpoint + governed relation proposals | DEV ONLY: 241 | PARTIAL | IN PROGRESS | 241 explicit `about` relations imported; Post single now consumes Graph-derived related entities/articles/Media/Video through a reader-safe query boundary; raw Graph REST reads are administrator-only and retain operational endpoint-key/state/revision fields; 186 legacy/invalid relation rows remain skipped |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + grouped semantic search + Governance core + NHK Admin/read API | NO | PARTIAL | IN PROGRESS | Search combines native Posts with active semantic groups, bounds each group per page and exposes totals for pagination even when native Posts are exhausted; public REST/theme/MCP Knowledge search applies the same active/public-ready claim gate, while REST/theme/MCP Media discovery requires active `readiness=ready` and REST Media/Video search excludes retired records; public entity search indexes only canonical `allowedFields`, so private/legacy payload values cannot alter result membership or totals; REST/theme/MCP reads suppress retired Authority/Media/Video records; public templates avoid internal Authority/Proposal/Knowledge Claim terminology; guarded lifecycle integration passes including governed MCP Media, Video, Knowledge, Source and Evidence ingest; Admin operational forms expose explicit label/id associations, but V2 behavior reconciliation remains pending |
| MCP | Legacy behavior pending | Tool catalog + governed read/mutation handlers + local Streamable HTTP endpoint | NO | PARTIAL | IN PROGRESS | Runtime registration and local MCP POST expose 18 protocol tool definitions (10 governed, including governed Media, Video, Knowledge, Source and Evidence ingest); raw HTTP probes confirm `200 application/json` modern `tools/list` and bounded `nhk.search` page 2; semantic search is bounded per page with totals and filters non-ready Media plus non-public Knowledge; Authority entity MCP reads now allowlist payloads by canonical definition, Media reads expose only active-ready reader-safe fields, Video reads expose only validated external-reference display fields, Knowledge reads expose reader-safe claim/evidence fields without persisted provenance/metadata, and Source/Evidence reads now expose the same active/public reader-safe boundary, matching REST/theme; modern header/body/Accept validation, invalid Origin rejection, unauthenticated governed-call rejection and authenticated semantic create→submit→approve→apply are tested; bounded read-only external Source/Media/Video abilities are reachable but use a richer adapter schema with draft/mixed-visibility records and zero Video rows, as recorded in `MCP_EXTERNAL_INTEROPERABILITY_EVIDENCE_2026-09-01.md`; wire-level client/deployment interoperability and V2 behavior reconciliation remain pending |
| SEO / URLs / Sitemap / RSS | 800 source URL candidates; policy-normalized apply has 773 mapped, 27 skipped | WordPress boundary + native postmeta/entity-registry 301 redirects + V2 archive/detail/search aliases + theme metadata/JSON-LD | DEV ONLY: 773 | PARTIAL | IN PROGRESS | 292 active Knowledge, 75 archived-to-active Knowledge and 370 active Authority projection links now redirect to canonical routes; `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/` compatibility archives emit canonical V3 links; unique active V2 detail slugs such as `/odo/` and `/odo/odo-39/` resolve to canonical stable-key routes, while native posts and ambiguous names fail closed; `/tim-kiem/?q=...` preserves `q` when redirecting to native `s`; Knowledge URL targets now also require an active public claim, preventing redirects to hidden/unverified public 404s; the no-write dry-run now mirrors apply's structural path/typed-target validation; 34 legacy article redirects and two safe no-ops (including native homepage `/`) verified; all 27 residual URLs have bounded reasons: 5 `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE` and 1 `RETIRED_LEGACY_GARBAGE`; the five domain-targeted rows now have exact read-only Knowledge candidates documented in `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md`, but UUID/revision/provenance and governed redirect-or-retire approval remain open |
| Images / Related content / entity pages / galleries | Legacy inventory pending | Entity pages, Graph-derived related sections and Media gallery surface | NO | PARTIAL | IN PROGRESS | Entity archive/detail routes, related groups, media archive/detail and readiness-aware asset states exist; Media, Video and Knowledge templates now render bounded pagination from query totals; public templates, REST serializers, theme-facing Media detail query and Comparison payload presentation use reader-facing fields without operational identifiers or internal domain terminology, Media provenance/storage metadata or Graph usage endpoint identifiers; active Media detail 200 and desktop empty-state visual QA pass, while V2 inventory, approved asset delivery/privacy policy and runtime gallery coverage remain |

## Required parity inventory

Homepage, archives, posts, all canonical entity pages, search, navigation,
images, related content, mobile/desktop behavior, create/edit/relations/media/
publish/proposal/approval/apply/search/MCP workflows, URL redirects, canonical
metadata, sitemap and RSS must be reconciled before P11 can close.

## UI / route parity inventory

| Surface | V2 reference | V3 target | Improvement / contract | Tested |
|---|---|---|---|---|
| Homepage | Read-only route/behavior inventory recorded | Editorial discovery homepage | Real query services; no public fixtures or fake metrics | PARTIAL |
| Header / footer / navigation | Read-only shell/navigation inventory recorded | NHK responsive shell | Domain language for visitors; keyboard/mobile usable | PARTIAL — skip link, explicit main target, focus-visible menu control and synchronized ARIA state; 390px/768px shell checks and ten-link menu state pass; complete V2 behavior reconciliation remains |
| Search / empty search | Read-only route/behavior inventory recorded | Unified semantic search | Posts plus canonical entities, media, videos and knowledge | PARTIAL — blank/whitespace search now fails closed with zero semantic cards, avoiding empty-term enumeration; result type labels are mapped to visitor-facing language |
| Comparison | V2 navigation contract / behavior reconciliation pending | Read-only `/comparison/` over two active Authority references | Native Authority query boundary; no duplicate comparison persistence or editorial body projection | PARTIAL — HTTP 200 and desktop visual smoke pass |
| Post / Tri thức / Góc chia sẻ | Read-only route/behavior inventory recorded | Native WordPress editorial routes | Post single now enriches from Graph-derived entities, articles, Media and Video without body duplication; desktop Post smoke passes | PARTIAL |
| Brand / Model / Movement / Music / Component | Read-only route/behavior inventory recorded | Domain-specific archive/single pages | Graph-backed sections and pagination; Authority archive type/title/link boundary verified on desktop | PARTIAL |
| Specimen / Product | Read-only specimen route inventory recorded; Product behavior pending | Separate physical-object/listing pages | Explicit identity distinction | PARTIAL — public entity payload labels now translate listing and specimen linkage fields (`vendor`, `price`, `url`, `availability`, `specimen_uuid`); source/API confirmation and populated V2 behavior remain pending |
| Media gallery / Video | Read-only route/behavior inventory recorded | First-class archive/detail query modules and theme templates | Responsive assets; external embeds only | PARTIAL |
| Pagination / 404 / empty states | Read-only route inventory recorded | Route-level accessible states | No dead ends or fixture leakage | PARTIAL — all declared page-2 routes plus empty/404 states have 390px/768px metrics with no overflow; `/model/page/2/`, `/component/page/2/`, `/media/page/2/`, `/video/page/2/`, `/knowledge/page/2/` and 404 received visual inspection; public data-derived links use a shared HTTP(S) validator and fail closed on missing/malformed URLs, while a nine-route browser sweep found no empty/`#` links; broader screenshot coverage remains |
| Desktop / tablet / mobile | Pending audit | Responsive one-to-many-column layouts | Reading comfort and Core Web Vitals considered | PARTIAL — a 32-combination browser sweep across page, archive pagination and empty/404 states passes 390px/768px overflow checks; route-specific screenshot coverage and active Video detail remain |
| SEO metadata / structured data | Pending audit | WordPress/theme metadata hooks | Canonical, OpenGraph, Article, BreadcrumbList and explicit archive index/noindex policy | PARTIAL — metadata surfaces, homepage and custom archive title/description/canonical ordering plus robots policy are browser-verified/contract-tested; native `/wp-sitemap.xml` and `/feed/` return the expected sitemap/RSS payload markers; theme token source and cache-busting overflow check are also verified; runtime/V2 reconciliation remains |

## Logic parity inventory

| Workflow | V3 contract | Status |
|---|---|---|
| WordPress Post publish | Native WP editorial lifecycle | IN PROGRESS |
| Entity create/update/retire/reactivate | Authority + optimistic revision | IN PROGRESS |
| Proposal/submit/approval/eligibility/apply | Governance-controlled mutation | IN PROGRESS |
| Relations / Post semantic links | One typed Graph | IN PROGRESS |
| Media ingestion / usage | Media identity, asset and placement separation | PASS — V2 usage inventory is exactly zero; V3 usage contract and fail-closed public asset delivery are covered, while publication policy remains open |
| Video linking | Canonical external-reference entity | PASS — validated YouTube identity, idempotent external-reference persistence and governed lifecycle are covered; active public-record/data parity remains open |
| Knowledge / Source / Evidence | Atomic claims with provenance | PASS for V3 lifecycle; IN PROGRESS for V2/public policy |
| Search | Unified SearchService abstraction and native WP post query | IN PROGRESS |
| Admin / MCP | Governed operational workflows and MCP tool contract | PASS for local contract/lifecycle; IN PROGRESS for external/V2 parity |

## Data parity inventory

The restored read-only V2 backup contains 800 posts, 1,301 entities, 2
taxonomy rows, 427 relations, 19 evidence rows, 40 citations, 3 media assets
and 1,581 semantic projections. The baseline expanded no-write dry-run
processes 4,973 records: 3,960 mapped candidates and 1,013 skipped candidates
(747
`DOMAIN_TARGETED`, 42 `UNSUPPORTED_MEDIA_REFERENCE`, 3
`RETIRED_LEGACY_GARBAGE`, 1 `INVALID_RELATION` and 220 unsupported legacy
types).
The latest policy-normalized local-dev governed apply recorded 3,961 migrated
rows and 1,012 explicit skips with zero conflicts. Migration009 mapped all 1,581 semantic
projections into the non-canonical context sink with body migration disabled.
Thirty-four native-post
redirects, two identical source/target URL no-ops, 370 canonical Authority entity
redirects and 367 Knowledge claim redirects are migrated; three media assets
were metadata-reconciled to
PRIVATE and are not publicly delivered.
This
does not constitute production parity. Every delta must be explained by a
ledger reason code; identity merges require explicit evidence and name-only
matching is forbidden. The normalized backup also proves 776 explicit
projection-to-entity UUID links; 370 active Authority links, 292 active
Knowledge links and 75 archived-to-active Knowledge links now have deterministic
canonical redirect targets. Five archived/no-target Knowledge links remain
`DOMAIN_TARGETED`; the 1,581 semantic projection rows are now preserved as
non-canonical, provenance-bearing context metadata without copying projection
bodies into canonical domain records.
