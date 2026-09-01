# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Read-only route/behavior inventory recorded | HomePageQuery-driven responsive WordPress editorial discovery surface | N/A | PARTIAL | IN PROGRESS | Featured/latest/category/topics plus real Authority/Media/Video modules; visitor-facing homepage document/OpenGraph title and description are branded for NHK, custom Authority/Knowledge/Media/Video/Comparison archives retain route-specific title/description/canonical metadata without technical-description leakage, the theme has one warm NHK token source with legacy CSS rules removed, homepage sections/topics now hide when their destination is missing or malformed, default `Uncategorized` presentation is localized to `Chưa phân loại`, public editorial dates use Vietnamese month labels with ISO machine timestamps, a fresh desktop sweep across 14 public routes found expected H1/title, no overflow and no internal terminology, 390px/768px route metrics are overflow-free, and field-level V2 behavior reconciliation remains |
| Posts | 800 rows in restored read-only backup; 34 `nhk_article` rows plus native/system rows | Native `wp_posts` boundary | DEV ONLY: 36 | PARTIAL | IN PROGRESS | 36 safe native post/page rows imported; 764 domain-targeted rows remain explicit skips, now itemized as 742 domain records, 21 attachments and one global-styles record in `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md`; the read-only candidate audit finds one same-domain title/slug candidate for all 742 domain posts, but deterministic legacy-post identity binding and governed target decisions are still absent |
| Categories | 2 taxonomy rows in restored backup | Native WP categories | DEV ONLY: 1 | PARTIAL | IN PROGRESS | One native category imported; non-category taxonomy skipped; URL ledger pending |
| Tri thức / Góc chia sẻ / Tin tức | Read-only route/behavior inventory recorded | WP category contract | NO | PARTIAL | IN PROGRESS | Category-aware sections and honest empty states are implemented; field-level route/content reconciliation remains |
| Brand / Model / Variant / Movement / Music / Component / Classification | 4 / 30 / 42 / 18 / 11 / 91 / 174 entity rows | Authority registry/core + canonical entity read API + frontend routes | DEV ONLY: exact counts | PARTIAL | IN PROGRESS | Exact UUID/stable-key rows imported into local dev; Authority domain objects reject malformed canonical UUIDs before repository/Graph use; Authority repository creation now preflights canonical UUID and stable key and rejects changed schema/payload/state/revision packets before persistence, while identical packets remain race-idempotent; Authority repository reads omit malformed/non-array payload rows fail-closed from canonical lookup and type collections; public Entity REST list/detail, MCP reads and theme archive explicitly filter retired records at the public boundary, public payloads are allowlisted by registered canonical fields and lifecycle fields are omitted from reader-safe responses; fresh 41-route HTTP sweep returned 200 for active Brand/Model/Movement/Music/Component/Variant/Classification stable-key details, with all nine archive/page-two route types covered 34/34; model/variant/specimen relation UUID and Product HTTP(S) URL payload formats are now schema-declared and fail-closed; semantic field review remains |
| Specimen / Product | No rows found in selected full V2 backup | Registry-backed Authority contract + frontend routes | NO | PARTIAL | IN PROGRESS | Absence is recorded from this backup; source/API confirmation and retirement reason remain pending |
| Media / Video | 242 media entities, 3 assets, 0 usage and 0 visual-video rows | P6 domain/persistence + public archive/detail routes + fail-closed asset delivery | DEV ONLY: 242 Media + 3 assets | PASS | IN PROGRESS | Media identities and field-level asset metadata/PRIVATE visibility imported; governed MCP `nhk.media.ingest`, direct Media service asset creation, MediaAsset domain construction and missing-visibility WPDB hydration are PRIVATE-by-default, and Migration008 creation/upgrade normalizes the schema default to PRIVATE without rewriting existing values; malformed/non-array metadata is omitted fail-closed by repository reads for MediaAsset and Media, while Video malformed metadata rows are similarly omitted; visibility requires an explicit publication decision; direct Media identity creation and WPDB repository create are idempotent only when stable key, name, readiness, provenance, active state and revision match, rejecting state conflicts before persistence; direct Media asset creation and WPDB repository create are idempotent for identical storage/content and reject conflicting reuse before persistence, including strict MIME/size/dimension/visibility/metadata comparison; direct Media usage creation and WPDB repository create are idempotent for identical endpoint/role/sort-order packets and reject sort-order conflicts before persistence; malformed V2 MediaAsset records with missing MIME are skipped with `INVALID_IDENTITY` and ledgered; public Media discovery now requires active + `readiness=ready` across REST/theme/home/search/Graph-related paths, while internal governance/MCP reads retain drafts; public reads and asset delivery suppress all three imported assets until delivery/privacy policy and source-file availability are approved; binary delivery additionally requires the parent Media to be active and ready, then verifies allowlisted MIME, storage-root containment, size and SHA-256; public Media REST/MCP/theme serializers reuse this binary delivery boundary so missing/corrupt PUBLIC assets do not become broken URLs; read-only V2 recovery audit found 18/21 exact upload paths HTTP 200 with allowlisted MIME/size and three `wp1-thumbnail-*` paths HTTP 404; three available attachments already have explicit canonical Media/asset provenance (818, 849, 852), while 15 remain unmapped, and none has an approved recovery/publication decision; public Media serializers omit lifecycle fields, while public Video REST/MCP/theme/query/archive/search surfaces require the shared validated YouTube external-reference predicate, fail-closing unsupported platforms, malformed IDs and URL/ID mismatches; REST/MCP Video serializers expose only reader-safe external-reference fields and omit thumbnail/media identity plus lifecycle fields; public Video REST/theme detail uses only validated external-reference display fields; V2 usage inventory is exactly zero; active Video public detail data remains unavailable; details are recorded in `V2_MEDIA_SOURCE_RECOVERY_AUDIT_2026-09-01.md` |
| Knowledge Claim / Source / Evidence | 655 knowledge entities, 19 evidence, 40 citations, 242 Knowledge relations | P7 contracts, schema, service and governed lifecycle | DEV ONLY: 655 claims + 19 sources + 40 evidence | PASS | IN PROGRESS | Claims, sources and citation evidence imported with endpoint, verification and citation metadata; governed MCP ingest proves claim/source/evidence create → submit → approve → apply with optimistic repository resolution; public claim/source/evidence detail reads now share the active/public claim-source gate across REST and MCP, explicit unverified/non-public claim/source/evidence states are fail-closed, Source/Evidence default PRIVATE when visibility is omitted, explicit publication uses `visibility=PUBLIC`, Knowledge claim and Source repository creation are idempotent only for complete identity/content/state matches and reject changed provenance/metadata before persistence, Evidence repository creation now preflights canonical UUID and rejects changed claim/source/relation/excerpt/locator/metadata/state/revision packets before persistence, Source/Evidence repository hydration omits malformed or non-array metadata rows from single and collection reads, Evidence metadata now persists through governed ingest, V2 Source migration preserves top-level visibility/verification/review-state/legacy-id metadata and canonical normalized `source_type` from top-level or metadata fields, archived/retired replay states are inactive regardless of normalized field placement, public metadata/provenance blobs and lifecycle fields are omitted, and reader-facing evidence fields remain; all V2 source/evidence rows retain PRIVATE state pending public provenance policy |
| Relations / Post Graph | 185 Graph relation rows plus 242 Knowledge relations | Graph Core + Post/Knowledge endpoint + governed relation proposals | DEV ONLY: 241 | PARTIAL | IN PROGRESS | 241 explicit `about` relations imported; PredicateDefinition now fail-closes empty/malformed endpoint type lists, GraphEdge validates relation UUID/predicate/revision and WPDB hydration normalizes database HEX UUIDs; Post single now consumes Graph-derived related entities/articles/Media/Video through a reader-safe query boundary; raw Graph REST reads are administrator-only and retain operational endpoint-key/state/revision fields; 186 legacy/invalid relation rows remain skipped |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + grouped semantic search + Governance core + NHK Admin/read API | NO | PARTIAL | IN PROGRESS | Search combines native Posts with active semantic groups, bounds each group per page and exposes totals for pagination even when native posts are exhausted; public REST/theme/MCP Knowledge search applies the same active/public-ready claim gate, while REST/theme/MCP Media discovery requires active `readiness=ready` and REST Media/Video search excludes retired records; public entity search indexes only canonical `allowedFields` in grouped/search and Entity archive query paths, so private/legacy payload values cannot alter result membership or totals; REST/theme/MCP reads suppress retired Authority/Media/Video records; public templates avoid internal Authority/Proposal/Knowledge Claim terminology; guarded lifecycle integration passes including governed MCP Media, Video, Knowledge, Source and Evidence ingest; Proposal repository idempotency-key preflight returns identical command packets without duplicate SQL warnings and rejects changed payload/fingerprint/target/revision bindings; Proposal domain validation rejects malformed optional target UUIDs while preserving semantic subject IDs; ApplyAttempt now validates durable identity/state/number fields before writes; Admin operational forms expose explicit label/id associations, semantic lookup for Media/Video/Knowledge Claim/Source/Evidence and a read-only migration-ledger summary grouped by source/status/reason code and safe review action; actual apply persists structured review metadata and Admin exposes explicit mapping, source recovery and retirement-only dispositions, while V2 behavior reconciliation remains pending |
| MCP | Legacy behavior pending | Tool catalog + governed read/mutation handlers + local Streamable HTTP endpoint | NO | PARTIAL | IN PROGRESS | Runtime registration and local MCP POST expose 18 protocol tool definitions (10 governed, including governed Media, Video, Knowledge, Source and Evidence ingest); raw HTTP probes confirm standard modern `initialize`, header-only follow-up `tools/list` and bounded `nhk.search` page 2; WordPress REST CORS now allowlists MCP protocol assertion headers for browser preflight; semantic search is bounded per page with totals and filters non-ready Media plus non-public Knowledge; Authority entity MCP reads now allowlist payloads by canonical definition and omit lifecycle fields, Media reads expose only active-ready reader-safe fields without lifecycle fields, Video reads expose only validated external-reference display fields, Knowledge reads expose reader-safe claim/evidence fields without persisted provenance/metadata or lifecycle fields, and Source/Evidence reads now expose the same active/public reader-safe boundary without lifecycle fields, matching REST/theme; modern header/body/Accept validation, invalid Origin rejection, unauthenticated governed-call rejection and authenticated semantic create→submit→approve→apply are tested; bounded read-only external Source/Media/Video abilities are reachable but use a richer adapter schema with draft/mixed-visibility records and zero Video rows, as recorded in `MCP_EXTERNAL_INTEROPERABILITY_EVIDENCE_2026-09-01.md`; external mapping/deployment interoperability and V2 behavior reconciliation remain pending |
| SEO / URLs / Sitemap / RSS | 800 source URL candidates; policy-normalized apply has 773 mapped, 27 skipped | WordPress boundary + native postmeta/entity-registry 301 redirects + V2 archive/detail/search aliases + theme metadata/JSON-LD | DEV ONLY: 773 | PARTIAL | IN PROGRESS | 292 active Knowledge, 75 archived-to-active Knowledge and 370 active Authority projection links now redirect to canonical routes; `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/` compatibility archives emit canonical V3 links; unique active V2 detail slugs such as `/odo/` and `/odo/odo-39/` resolve to canonical stable-key routes, while native posts and ambiguous names fail closed; `/tim-kiem/?q=...` preserves `q` when redirecting to native `s`; Search title and standard/OpenGraph descriptions are now Vietnamese and visitor-facing while canonical remains `/`; category archives now localize the default category label and derive archive canonical/description from the queried term, with `/category/uncategorized/` covered by route smoke; Knowledge URL targets now also require an active public claim, preventing redirects to hidden/unverified public 404s; the no-write dry-run now mirrors apply's structural path/typed-target validation; 34 legacy article redirects and two safe no-ops (including native homepage `/`) verified; all 27 residual URLs have bounded reasons: 5 `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE` and 1 `RETIRED_LEGACY_GARBAGE`; the five domain-targeted rows now have exact read-only Knowledge candidates documented in `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md`, but UUID/revision/provenance and governed redirect-or-retire approval remain open |
| Images / Related content / entity pages / galleries | Legacy inventory pending | Entity pages, Graph-derived related sections and Media gallery surface | NO | PARTIAL | IN PROGRESS | Entity archive/detail routes, related groups, media archive/detail and readiness-aware asset states exist; Media, Video and Knowledge templates now render bounded pagination from query totals; public templates, REST/MCP serializers, theme-facing Media detail query and Comparison payload presentation use reader-facing fields without operational identifiers or internal domain terminology, Media provenance/storage metadata or Graph usage endpoint identifiers; PUBLIC image assets receive the same reader-safe `/media/asset/{uuid}/` URL across REST/MCP/theme and lazy public rendering while binary delivery remains fail-closed; active Media detail 200 and desktop empty-state visual QA pass, while V2 inventory, approved asset delivery/privacy policy and runtime gallery coverage remain |

## Required parity inventory

Latest P11 persistence evidence also covers fail-closed KnowledgeClaim
provenance hydration; guarded integration is 75 tests / 449 assertions.

The persistence hydration boundary now also covers malformed Authority,
Knowledge Source/Claim/Evidence and MediaUsage domain rows; current combined
verification is 228 tests / 1,328 assertions. This hardening does not change
the separate V2 data/publication gates below.

Governance Proposal and ApplyAttempt reads also omit out-of-range persisted
numeric states instead of coercing them to defaults; current combined
 verification is 235 tests / 1,351 assertions.

Public REST, MCP, theme detail queries and asset delivery now share strict
canonical UUID validation; UUID-shaped but invalid inputs fail closed before
WPDB conversion, with the boundary covered by Unit and guarded integration
tests.

Graph endpoint resolvers also use the shared strict codec for canonical
Authority, Media, Video, Knowledge, Source and Evidence keys, rejecting nil
UUIDs before endpoint resolution.

MCP `tools/list` now declares canonical UUID patterns for public read,
evidence-ingest and Proposal ID fields, with runtime nil/malformed validation
remaining authoritative.

Raw persisted state validation is strict across Authority, Media, Video and
Knowledge hydrators; malformed values are omitted before public or governed
domain consumption.

The latest read-only revalidation still reports MCP wire smoke PASS, frontend
route smoke 34/34, dry-run 3,961 mapped / 1,012 skipped / 0 conflicts and 742
domain-target candidates requiring explicit mapping evidence.

Governance proposal reads likewise omit malformed/non-array `command_json`
rows; latest guarded integration is 76 tests / 451 assertions.

Governance proposal reads also omit rows with invalid durable fields such as
non-positive revisions; latest guarded integration is 77 tests / 452 assertions.

NHK Admin entity/proposal lookup forms now fail closed on malformed UUID input;
latest Unit coverage is 140 tests / 849 assertions.

V2 migration semantic and URL target UUIDs now use shared codec plus strict
RFC 4122 validation; malformed UUID-shaped records are explicitly ledgered as
`INVALID_IDENTITY`, covered by 78 guarded integration tests / 456 assertions.

Dry-run relation and URL target candidates apply the same strict UUID boundary,
with nil/malformed UUIDs rejected before mapping; latest Unit coverage is 141
tests / 852 assertions.

Fresh MCP wire smoke and complete frontend route smoke pass on canonical
`http://localhost`; the domain candidate audit remains 742 explicit review
items, with no automatic identity mapping.

ApplyAttempt reads now fail closed on malformed durable rows; latest guarded
integration coverage is 79 tests / 457 assertions.

Governance dependency reads now fail closed on invalid UUID rows before cycle
evaluation; latest guarded integration coverage is 80 tests / 458 assertions.

Graph edge reads now omit malformed persisted rows from single/paginated
results; latest guarded integration coverage is 81 tests / 460 assertions.

MediaAsset reads now omit malformed persisted domain rows before public
delivery/query use; latest guarded integration coverage is 82 tests / 462
assertions.

Media identity reads now omit malformed persisted domain rows before public
query use; latest guarded integration coverage is 83 tests / 464 assertions.

Video identity reads now omit malformed persisted domain rows before public
query use; latest guarded integration coverage is 84 tests / 466 assertions.

Homepage, archives, posts, all canonical entity pages, search, navigation,
images, related content, mobile/desktop behavior, create/edit/relations/media/
publish/proposal/approval/apply/search/MCP workflows, URL redirects, canonical
metadata, sitemap and RSS must be reconciled before P11 can close.

## UI / route parity inventory

| Surface | V2 reference | V3 target | Improvement / contract | Tested |
|---|---|---|---|---|
| Homepage | Read-only route/behavior inventory recorded | Editorial discovery homepage | Real query services; no public fixtures or fake metrics | PARTIAL |
| Header / footer / navigation | Read-only shell/navigation inventory recorded | NHK responsive shell | Domain language for visitors; keyboard/mobile usable | PARTIAL — skip link, explicit main target, focus-visible menu control and synchronized ARIA state; 390px/768px shell checks and ten-link menu state pass; footer links now use readable light text with accent-secondary focus/hover states; complete V2 behavior reconciliation remains |
| Search / empty search | Read-only route/behavior inventory recorded | Unified semantic search | Posts plus canonical entities, media, videos and knowledge | PARTIAL — blank/whitespace search now fails closed with zero semantic cards, avoiding empty-term enumeration; result type labels are mapped to visitor-facing language |
| Comparison | V2 navigation contract / behavior reconciliation pending | Read-only `/comparison/` over two active Authority references | Native Authority query boundary; no duplicate comparison persistence or editorial body projection | PARTIAL — HTTP 200 and desktop visual smoke pass |
| Post / Tri thức / Góc chia sẻ | Read-only route/behavior inventory recorded | Native WordPress editorial routes | Post single now enriches from Graph-derived entities, articles, Media and Video without body duplication; desktop Post smoke passes | PARTIAL |
| Brand / Model / Variant / Movement / Music / Component / Classification | Read-only route/behavior inventory recorded | Domain-specific archive/single pages | Graph-backed sections and pagination; all nine Authority archive/page-two route types plus real Variant/Classification detail routes are covered by HTTP smoke and responsive checks | PARTIAL |
| Specimen / Product | Read-only specimen route inventory recorded; Product behavior pending | Separate physical-object/listing pages | Explicit identity distinction | PARTIAL — public entity payload labels now translate listing and specimen linkage fields (`vendor`, `price`, `url`, `availability`, `specimen_uuid`); source/API confirmation and populated V2 behavior remain pending |
| Media gallery / Video | Read-only route/behavior inventory recorded | First-class archive/detail query modules and theme templates | Responsive assets; external embeds only | PARTIAL — card and footer links use the NHK palette with visible focus/hover states; active Video detail and asset publication policy remain open |
| Pagination / 404 / empty states | Read-only route inventory recorded | Route-level accessible states | No dead ends or fixture leakage | PARTIAL — all declared page-2 routes plus empty/404 states have 390px/768px metrics with no overflow; `/model/page/2/`, `/component/page/2/`, `/media/page/2/`, `/video/page/2/`, `/knowledge/page/2/` and 404 received mobile screenshots; public data-derived links use a shared HTTP(S) validator and fail closed on missing/malformed URLs, while a nine-route screenshot sweep found no empty/`#` links; active Video detail remains data-gated |
| Desktop / tablet / mobile | Pending audit | Responsive one-to-many-column layouts | Reading comfort and Core Web Vitals considered | PARTIAL — a 32-combination browser sweep across page, archive pagination and empty/404 states passes 390px/768px overflow checks; nine additional mobile route screenshots pass with expected H1/title, no overflow/broken images, and NHK link colors; active Video detail remains data-gated |
| SEO metadata / structured data | Pending audit | WordPress/theme metadata hooks | Canonical, OpenGraph, Article, BreadcrumbList and explicit archive index/noindex policy | PARTIAL — metadata surfaces, homepage and custom archive title/description/canonical ordering plus robots policy are browser-verified/contract-tested; native `/wp-sitemap.xml` and `/feed/` return the expected sitemap/RSS payload markers; theme token source and cache-busting overflow check are also verified; runtime/V2 reconciliation remains |

## Logic parity inventory

| Workflow | V3 contract | Status |
|---|---|---|
| WordPress Post publish | Native WP editorial lifecycle | IN PROGRESS |
| Entity create/update/retire/reactivate | Authority + optimistic revision | IN PROGRESS |
| Proposal/submit/approval/eligibility/apply | Governance-controlled mutation | IN PROGRESS |
| Relations / Post semantic links | One typed Graph | IN PROGRESS |
| Media ingestion / usage | Media identity, asset and placement separation | PASS — V2 usage inventory is exactly zero; V3 usage contract and fail-closed public asset delivery are covered, while publication policy remains open |
| Video linking | Canonical external-reference entity | PASS — validated YouTube identity, strict idempotent external-reference persistence across UUID races with changed-content rejection, and governed lifecycle are covered; active public-record/data parity remains open |
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
