# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Reference audit pending | HomePageQuery-driven responsive WordPress editorial discovery surface | N/A | PARTIAL | IN PROGRESS | Featured/latest/category/topics plus real Authority/Media/Video modules; desktop visual inspection passes, responsive visual QA and V2 reference audit remain |
| Posts | 800 rows in restored read-only backup; 34 `nhk_article` rows plus native/system rows | Native `wp_posts` boundary | DEV ONLY: 36 | PARTIAL | IN PROGRESS | 36 safe native post/page rows imported; 764 domain-targeted rows remain explicit skips |
| Categories | 2 taxonomy rows in restored backup | Native WP categories | DEV ONLY: 1 | PARTIAL | IN PROGRESS | One native category imported; non-category taxonomy skipped; URL ledger pending |
| Tri thức / Góc chia sẻ / Tin tức | Reference audit pending | WP category contract | NO | PARTIAL | IN PROGRESS | Category-aware sections scaffolded; route audit pending |
| Brand / Model / Variant / Movement / Music / Component / Classification | 4 / 30 / 42 / 18 / 11 / 91 / 174 entity rows | Authority registry/core + canonical entity read API + frontend routes | DEV ONLY: exact counts | PARTIAL | IN PROGRESS | Exact UUID/stable-key rows imported into local dev; semantic field review remains |
| Specimen / Product | No rows found in selected full V2 backup | Registry-backed Authority contract + frontend routes | NO | PARTIAL | IN PROGRESS | Absence is recorded from this backup; source/API confirmation and retirement reason remain pending |
| Media / Video | 242 media entities, 3 assets, 0 usage and 0 visual-video rows | P6 domain/persistence + public archive/detail routes + fail-closed asset delivery | DEV ONLY: 242 Media + 3 assets | PASS | IN PROGRESS | Media identities and field-level asset metadata/PRIVATE visibility imported; public reads and asset delivery suppress all three assets until delivery/privacy policy and source-file availability are approved; delivery verifies allowlisted MIME, storage-root containment, size and SHA-256; V2 usage inventory is exactly zero; video delivery remains pending |
| Knowledge Claim / Source / Evidence | 655 knowledge entities, 19 evidence, 40 citations, 242 Knowledge relations | P7 contracts, schema and service | DEV ONLY: 655 claims + 19 sources + 40 evidence | PASS | IN PROGRESS | Claims, sources and citation evidence imported with endpoint, verification and citation metadata; all source/evidence rows retain V2 PRIVATE state pending public provenance policy |
| Relations / Post Graph | 185 Graph relation rows plus 242 Knowledge relations | Graph Core + Post/Knowledge endpoint + governed relation proposals | DEV ONLY: 241 | PARTIAL | IN PROGRESS | 241 explicit `about` relations imported; Post single now consumes Graph-derived related entities/articles/Media/Video through a query boundary; 186 legacy/invalid relation rows remain skipped |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + grouped semantic search + Governance core + NHK Admin/read API | NO | PARTIAL | IN PROGRESS | Search combines native Posts with active semantic groups; REST/MCP detail reads suppress retired Authority/Media/Video records; guarded lifecycle integration passes, but V2 behavior reconciliation remains pending |
| MCP | Legacy behavior pending | Tool catalog + governed read/mutation handlers + local Streamable HTTP endpoint | NO | PARTIAL | IN PROGRESS | Runtime registration and local MCP POST expose 11 protocol tool definitions (5 governed); modern header/body validation, invalid Origin rejection and unauthenticated governed-call rejection are tested; external client/deployment interoperability and V2 behavior reconciliation remain pending |
| SEO / URLs / Sitemap / RSS | 800 source URL candidates; apply has 772 mapped, 28 skipped | WordPress boundary + native postmeta/entity-registry 301 redirects + V2 archive/detail/search aliases + theme metadata/JSON-LD | DEV ONLY: 772 | PARTIAL | IN PROGRESS | 292 active Knowledge, 75 archived-to-active Knowledge and 370 active Authority projection links now redirect to canonical routes; `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/` compatibility archives emit canonical V3 links; unique active V2 detail slugs such as `/odo/` and `/odo/odo-39/` resolve to canonical stable-key routes, while native posts and ambiguous names fail closed; `/tim-kiem/?q=...` preserves `q` when redirecting to native `s`; 34 legacy article redirects and one safe no-op verified; all 28 residual URLs have bounded reasons: 5 `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE` and 2 `RETIRED_LEGACY_GARBAGE`; field-level review is in `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md`, final retirement/target policy remains open |
| Images / Related content / entity pages / galleries | Legacy inventory pending | Entity pages, Graph-derived related sections and Media gallery surface | NO | PARTIAL | IN PROGRESS | Entity archive/detail routes, related groups, media archive/detail and readiness-aware asset states exist; V2 inventory and runtime gallery QA remain |

## Required parity inventory

Homepage, archives, posts, all canonical entity pages, search, navigation,
images, related content, mobile/desktop behavior, create/edit/relations/media/
publish/proposal/approval/apply/search/MCP workflows, URL redirects, canonical
metadata, sitemap and RSS must be reconciled before P11 can close.

## UI / route parity inventory

| Surface | V2 reference | V3 target | Improvement / contract | Tested |
|---|---|---|---|---|
| Homepage | Pending read-only audit | Editorial discovery homepage | Real query services; no public fixtures or fake metrics | NO |
| Header / footer / navigation | Pending audit | NHK responsive shell | Domain language for visitors; keyboard/mobile usable | NO |
| Search / empty search | Pending audit | Unified semantic search | Posts plus canonical entities, media, videos and knowledge | PARTIAL |
| Comparison | V2 navigation contract / behavior reconciliation pending | Read-only `/comparison/` over two active Authority references | Native Authority query boundary; no duplicate comparison persistence or editorial body projection | PARTIAL |
| Post / Tri thức / Góc chia sẻ | Pending audit | Native WordPress editorial routes | Post single now enriches from Graph-derived entities, articles, Media and Video without body duplication; desktop Post smoke passes | PARTIAL |
| Brand / Model / Movement / Music / Component | Pending audit | Domain-specific archive/single pages | Graph-backed sections and pagination; Authority archive type/title/link boundary verified on desktop | PARTIAL |
| Specimen / Product | Pending audit | Separate physical-object/listing pages | Explicit identity distinction | PARTIAL |
| Media gallery / Video | Pending audit | First-class archive/detail query modules and theme templates | Responsive assets; external embeds only | PARTIAL |
| Pagination / 404 / empty states | Pending audit | Route-level accessible states | No dead ends or fixture leakage | NO |
| Desktop / tablet / mobile | Pending audit | Responsive one-to-many-column layouts | Reading comfort and Core Web Vitals considered | NO |
| SEO metadata / structured data | Pending audit | WordPress/theme metadata hooks | Canonical, OpenGraph, Article and BreadcrumbList | PARTIAL |

## Logic parity inventory

| Workflow | V3 contract | Status |
|---|---|---|
| WordPress Post publish | Native WP editorial lifecycle | IN PROGRESS |
| Entity create/update/retire/reactivate | Authority + optimistic revision | IN PROGRESS |
| Proposal/submit/approval/eligibility/apply | Governance-controlled mutation | IN PROGRESS |
| Relations / Post semantic links | One typed Graph | IN PROGRESS |
| Media ingestion / usage | Media identity, asset and placement separation | PASS — V2 usage inventory is exactly zero; V3 usage contract and fail-closed public asset delivery are covered, while publication policy remains open |
| Video linking | Canonical external-reference entity | IN PROGRESS |
| Knowledge / Source / Evidence | Atomic claims with provenance | IN PROGRESS |
| Search | Unified SearchService abstraction and native WP post query | IN PROGRESS |
| Admin / MCP | Governed operational workflows and MCP tool contract | IN PROGRESS |

## Data parity inventory

The restored read-only V2 backup contains 800 posts, 1,301 entities, 2
taxonomy rows, 427 relations, 19 evidence rows, 40 citations, 3 media assets
and 1,581 semantic projections. The expanded no-write dry-run processes 4,973
records: 2,379 mapped candidates and 2,594 skipped candidates (747
`DOMAIN_TARGETED`, 42 `UNSUPPORTED_MEDIA_REFERENCE`, 3
`RETIRED_LEGACY_GARBAGE`, 1 `INVALID_RELATION` and 1,801 unsupported legacy
types).
The local-dev governed apply recorded 2,379 migrated rows and 2,594 explicit
skips (747 domain-targeted rows, 1 invalid relation, 42 unsupported media
references, 3 retired legacy garbage and 1,801 unsupported
legacy type) with zero conflicts. Thirty-four native-post
redirects, one identical source/target URL, 370 canonical Authority entity
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
`DOMAIN_TARGETED`; the 1,581 semantic projection rows remain unmigrated until
a governed read-only context sink and provenance policy exist, without copying
projection bodies into canonical domain records.
