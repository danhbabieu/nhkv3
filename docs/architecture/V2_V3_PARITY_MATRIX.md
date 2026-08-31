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
| Media / Video | 242 media entities, 3 assets, 0 usage and 0 visual-video rows | P6 domain/persistence + public archive/detail routes | DEV ONLY: 242 Media + 3 assets | PASS | IN PROGRESS | Media identities and field-level asset metadata/PRIVATE visibility imported; public boundaries suppress all three assets until delivery/privacy policy is approved; V2 usage inventory is exactly zero; video delivery remains pending |
| Knowledge Claim / Source / Evidence | 655 knowledge entities, 19 evidence, 40 citations, 242 Knowledge relations | P7 contracts, schema and service | DEV ONLY: 655 claims + 19 sources + 40 evidence | PASS | IN PROGRESS | Claims, sources and citation evidence imported with endpoint, verification and citation metadata; all source/evidence rows retain V2 PRIVATE state pending public provenance policy |
| Relations / Post Graph | 185 Graph relation rows plus 242 Knowledge relations | Graph Core + Post/Knowledge endpoint + governed relation proposals | DEV ONLY: 241 | PARTIAL | IN PROGRESS | 241 explicit `about` relations imported; 186 legacy/invalid relation rows remain skipped |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + grouped semantic search + Governance core + NHK Admin/read API | NO | PARTIAL | IN PROGRESS | Search combines native Posts with active semantic groups; guarded lifecycle integration passes, but V2 behavior reconciliation remains pending |
| MCP | Legacy behavior pending | Tool catalog + governed read/mutation handlers | NO | PARTIAL | IN PROGRESS | Read adapters and governed mutation bridge are available; external MCP transport remains pending |
| SEO / URLs / Sitemap / RSS | 800 source URL candidates; apply has 772 mapped, 28 skipped | WordPress boundary + native postmeta/entity-registry 301 redirects + theme metadata/JSON-LD | DEV ONLY: 772 | PARTIAL | IN PROGRESS | 292 active Knowledge, 75 archived-to-active Knowledge and 370 active Authority projection links now redirect to canonical routes; 34 legacy article redirects and one safe no-op verified; 28 residual URLs remain explicitly skipped pending route/retirement reconciliation |
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
| Media ingestion / usage | Media identity, asset and placement separation | PASS — V2 usage inventory is exactly zero; V3 usage contract is covered |
| Video linking | Canonical external-reference entity | IN PROGRESS |
| Knowledge / Source / Evidence | Atomic claims with provenance | IN PROGRESS |
| Search | Unified SearchService abstraction and native WP post query | IN PROGRESS |
| Admin / MCP | Governed operational workflows and MCP tool contract | IN PROGRESS |

## Data parity inventory

The restored read-only V2 backup contains 800 posts, 1,301 entities, 2
taxonomy rows, 427 relations, 19 evidence rows, 40 citations, 3 media assets
and 1,581 semantic projections. The expanded no-write dry-run processes 4,973
records: 3,330 mapped candidates and 1,643 skipped candidates (5
`DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE`, 1
`RETIRED_LEGACY_GARBAGE`, 1 invalid URL map and 1,615 unsupported legacy
types).
The local-dev governed apply recorded 2,379 migrated rows and 2,594 explicit
skips (769 domain-targeted rows, 1 invalid relation, 21 unsupported media
references, 1 retired legacy garbage, 1 invalid URL mapping, 1,682 unsupported
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
