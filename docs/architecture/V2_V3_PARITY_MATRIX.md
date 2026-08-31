# V2 → V3 Parity Matrix

`NOT ASSESSED` is intentionally not a parity claim. Mandatory red items block
the final parity declaration.

| AREA | V2 | V3 | MIGRATED? | TESTED? | PARITY STATUS | NOTES |
|---|---|---|---|---|---|---|
| Homepage | Reference audit pending | WordPress editorial surface | N/A | NO | NOT ASSESSED | Inventory in P9/P10 |
| Posts | Reference audit pending | Native `wp_posts` boundary | NO | PARTIAL | IN PROGRESS | Body remains WordPress-owned |
| Categories | Reference audit pending | Native WP categories | NO | PARTIAL | IN PROGRESS | Editorial intent only |
| Tri thức / Góc chia sẻ / Tin tức | Reference audit pending | WP category contract | NO | NO | NOT ASSESSED | P9 |
| Brand / Model / Variant / Movement / Music / Component / Classification | Legacy data pending | Authority registry/core | NO | PASS | IN PROGRESS | Nine-type registry/catalog and generic persistence tested in P5; legacy data pending |
| Specimen / Product | Legacy data pending | Registry-backed Authority contract | NO | PASS | IN PROGRESS | Physical specimen vs commercial product distinction tested; legacy data pending |
| Media / Video | Legacy data pending | P6 domain contracts + migration 004 | NO | PASS | IN PROGRESS | Identity/asset/usage separation, checksum candidate semantics, schema readiness and YouTube external reference tested; repositories/relations/data migration pending |
| Knowledge Claim / Source / Evidence | Legacy data pending | Contract only | NO | NO | NOT ASSESSED | P7 |
| Relations / Post Graph | Legacy data pending | Graph Core + Post endpoint | NO | PARTIAL | IN PROGRESS | P2/P3 graph evidence; domain coverage pending |
| Search / Admin / Proposal / Approval / Controlled Apply | Legacy behavior pending | Governance core; UI/API pending | NO | PARTIAL | IN PROGRESS | P4 then P8 |
| MCP | Legacy behavior pending | Not implemented | NO | NO | NOT ASSESSED | P8 |
| SEO / URLs / Sitemap / RSS | Legacy inventory pending | WordPress boundary | NO | NO | NOT ASSESSED | P9/P10 |
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
