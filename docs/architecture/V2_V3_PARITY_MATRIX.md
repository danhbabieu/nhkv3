# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Reference audit pending | Responsive WordPress editorial discovery surface | N/A | PARTIAL | IN PROGRESS | Theme scaffold; V2 reference audit and browser smoke pending |
| Posts | Route behavior partially inventoried | Native `wp_posts` boundary | NO | PARTIAL | IN PROGRESS | Body remains WordPress-owned; source counts pending |
| Categories | Tri thức/Góc chia sẻ routes observed | Native WP categories | NO | PARTIAL | IN PROGRESS | Editorial intent only; complete V2 taxonomy inventory pending |
| Tri thức / Góc chia sẻ / Tin tức | Reference audit pending | WP category contract | NO | PARTIAL | IN PROGRESS | Category-aware sections scaffolded; route audit pending |
| Brand / Model / Variant / Movement / Music / Component / Classification | Legacy data pending | Authority registry/core + canonical entity read API | NO | PASS | IN PROGRESS | Nine-type registry/catalog, persistence and list/detail API tested in P5/P8; legacy data pending |
| Specimen / Product | Legacy data pending | Registry-backed Authority contract | NO | PASS | IN PROGRESS | Physical specimen vs commercial product distinction tested; legacy data pending |
| Media / Video | Legacy data pending | P6 domain contracts + migration 004 | NO | PASS | IN PROGRESS | Identity/asset/usage separation, checksum candidate semantics, schema readiness and YouTube external reference tested; repositories/relations/data migration pending |
| Knowledge Claim / Source / Evidence | Legacy data pending | P7 contracts, schema and service | NO | PASS | IN PROGRESS | WPDB migration and legacy data pending |
| Relations / Post Graph | Legacy data pending | Graph Core + Post/Knowledge endpoint | NO | PARTIAL | IN PROGRESS | P2/P3 graph evidence; P7 Post link service added |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Unified Search API + Governance core + Admin/read API | NO | PARTIAL | IN PROGRESS | Controlled Apply/Admin detail UI pending |
| MCP | Legacy behavior pending | Tool catalog + governed handler contract | NO | PARTIAL | IN PROGRESS | External MCP transport and read adapters pending |
| SEO / URLs / Sitemap / RSS | Route inventory partial | WordPress boundary | NO | NO | NOT ASSESSED | URL/API inventory pending in P10 |
| Images / Related content / entity pages / galleries | Legacy inventory pending | Not implemented | NO | NO | NOT ASSESSED | P6/P9 |

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
| Search / empty search | Pending audit | Unified semantic search | Posts plus canonical entities and videos | NO |
| Post / Tri thức / Góc chia sẻ | Pending audit | Native WordPress editorial routes | Enriched by Graph/Knowledge without body duplication | NO |
| Brand / Model / Movement / Music / Component | Pending audit | Domain-specific archive/single pages | Graph-backed sections and pagination | NO |
| Specimen / Product | Pending audit | Separate physical-object/listing pages | Explicit identity distinction | NO |
| Media gallery / Video | Pending audit | First-class query modules | Responsive assets; external embeds only | NO |
| Pagination / 404 / empty states | Pending audit | Route-level accessible states | No dead ends or fixture leakage | NO |
| Desktop / tablet / mobile | Pending audit | Responsive one-to-many-column layouts | Reading comfort and Core Web Vitals considered | NO |

## Logic parity inventory

| Workflow | V3 contract | Status |
|---|---|---|
| WordPress Post publish | Native WP editorial lifecycle | IN PROGRESS |
| Entity create/update/retire/reactivate | Authority + optimistic revision | IN PROGRESS |
| Proposal/submit/approval/eligibility/apply | Governance-controlled mutation | IN PROGRESS |
| Relations / Post semantic links | One typed Graph | IN PROGRESS |
| Media ingestion / usage | Media identity, asset and placement separation | IN PROGRESS |
| Video linking | Canonical external-reference entity | IN PROGRESS |
| Knowledge / Source / Evidence | Atomic claims with provenance | NOT ASSESSED |
| Search | Unified SearchService abstraction | NOT ASSESSED |
| Admin / MCP | Governed operational workflows | NOT ASSESSED |

## Data parity inventory

Counts and mappings remain `—` until the read-only V2 inventory and dry-run
produce evidence. Every delta must be explained by a ledger reason code;
identity merges require explicit evidence and name-only matching is forbidden.
