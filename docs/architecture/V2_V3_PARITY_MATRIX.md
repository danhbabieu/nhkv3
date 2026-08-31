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
| Media / Video | Legacy data pending | Contract only | NO | NO | NOT ASSESSED | P6 |
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
