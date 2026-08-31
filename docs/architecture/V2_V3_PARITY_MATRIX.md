# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Reference audit pending | HomePageQuery-driven responsive WordPress editorial discovery surface | N/A | PARTIAL | IN PROGRESS | Featured/latest/category/topics plus real Authority/Media/Video modules; V2 reference audit and browser smoke pending |
| Posts | 800 rows in restored read-only backup; 34 `nhk_article` rows plus native/system rows | Native `wp_posts` boundary | DEV ONLY: 36 | PARTIAL | IN PROGRESS | 36 safe native post/page rows imported; 764 domain-targeted rows remain explicit skips |
| Categories | 2 taxonomy rows in restored backup | Native WP categories | DEV ONLY: 1 | PARTIAL | IN PROGRESS | One native category imported; non-category taxonomy skipped; URL ledger pending |
| Tri thức / Góc chia sẻ / Tin tức | Reference audit pending | WP category contract | NO | PARTIAL | IN PROGRESS | Category-aware sections scaffolded; route audit pending |
| Brand / Model / Variant / Movement / Music / Component / Classification | 4 / 30 / 42 / 18 / 11 / 91 / 174 entity rows | Authority registry/core + canonical entity read API + frontend routes | DEV ONLY: exact counts | PARTIAL | IN PROGRESS | Exact UUID/stable-key rows imported into local dev; semantic field review remains |
| Specimen / Product | No rows found in selected full V2 backup | Registry-backed Authority contract + frontend routes | NO | PARTIAL | IN PROGRESS | Absence is recorded from this backup; source/API confirmation and retirement reason remain pending |
| Media / Video | 242 media entities, 3 assets, 0 usage and 0 visual-video rows | P6 domain/persistence + public archive/detail routes | DEV ONLY: 242 Media + 3 assets | PASS | IN PROGRESS | Media identities and asset metadata imported; delivery/usages and video delivery remain pending |
| Knowledge Claim / Source / Evidence | 655 knowledge entities, 19 evidence, 40 citations, 242 Knowledge relations | P7 contracts, schema and service | DEV ONLY: 655 claims + 19 sources + 40 evidence | PASS | IN PROGRESS | Claims, sources and citation evidence imported; all source/evidence rows retain V2 PRIVATE state pending public provenance policy |
| Relations / Post Graph | 185 Graph relation rows plus 242 Knowledge relations | Graph Core + Post/Knowledge endpoint + governed relation proposals | DEV ONLY: 241 | PARTIAL | IN PROGRESS | 241 explicit `about` relations imported; 186 legacy/invalid relation rows remain skipped |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + grouped semantic search + Governance core + NHK Admin/read API | NO | PARTIAL | IN PROGRESS | Search combines native Posts with active semantic groups; guarded lifecycle integration passes, but V2 behavior reconciliation remains pending |
| MCP | Legacy behavior pending | Tool catalog + governed read/mutation handlers | NO | PARTIAL | IN PROGRESS | Read adapters and governed mutation bridge are available; external MCP transport remains pending |
| SEO / URLs / Sitemap / RSS | 800 source URL candidates; 1 ready, 799 unmapped | WordPress boundary + theme metadata/JSON-LD | NO | PARTIAL | IN PROGRESS | Canonical, description, OpenGraph, Article and BreadcrumbList hooks added; URL ledger and sitemap/RSS audit pending |
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
| Post / Tri thức / Góc chia sẻ | Pending audit | Native WordPress editorial routes | Enriched by Graph/Knowledge without body duplication | NO |
| Brand / Model / Movement / Music / Component | Pending audit | Domain-specific archive/single pages | Graph-backed sections and pagination | PARTIAL |
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
| Media ingestion / usage | Media identity, asset and placement separation | IN PROGRESS |
| Video linking | Canonical external-reference entity | IN PROGRESS |
| Knowledge / Source / Evidence | Atomic claims with provenance | IN PROGRESS |
| Search | Unified SearchService abstraction and native WP post query | IN PROGRESS |
| Admin / MCP | Governed operational workflows and MCP tool contract | IN PROGRESS |

## Data parity inventory

The restored read-only V2 backup contains 800 posts, 1,301 entities, 2
taxonomy rows, 427 relations, 19 evidence rows, 40 citations, 3 media assets
and 1,581 semantic projections. The expanded no-write dry-run processes 4,933
records: 2,559 mapped candidates and 2,414 skipped candidates (799 invalid URL
maps and 1,615 unsupported legacy types). The local-dev governed apply
recorded 1,607 migrated rows and 3,366 explicit skips (764 domain-targeted, 1
invalid relation, 2,482 unsupported legacy type) with zero conflicts. This
does not constitute production parity. Every delta must be explained by a
ledger reason code; identity merges require explicit evidence and name-only
matching is forbidden.
