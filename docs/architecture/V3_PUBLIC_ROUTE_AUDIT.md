# NHK V3 Public Route / SEO Audit

Date: 2026-09-01  
Status: canonical Authority routing implemented; non-Authority public identity
surfaces remain explicitly open gates.

## Constitution conflict register

`CONSTITUTION_CONFLICT`: the previous implementation exposed technical entity
types and stable keys in canonical links (`/brand/{stable-key}/`,
`/model/{stable-key}/`, `/media/{uuid}/`, `/video/{uuid}/` and
`/knowledge/claim/{uuid}/`). This violated the public URL law's no-identity
leak invariant. Authority Brand/Model/Variant routing now uses the central
resolver and public slugs; Media, Video and Knowledge require a follow-up
slug-contract slice before their UUID routes can be retired safely.

## Route inventory

| Current source/route | Page type | Problem | Canonical target | Redirect | Owner |
|---|---|---|---|---|---|
| `/{brand-slug}/` | Brand detail | New root route | `/{brand-slug}/` | No | `PublicRouteResolver`, `PublicEntityRoutes` |
| `/{brand}/{model}/` | Model detail | New hierarchy | `/{brand}/{model}/` | No | `PublicRouteResolver`, `PublicEntityRoutes` |
| `/{brand}/{model}/{variant}/` | Variant detail | New hierarchy | `/{brand}/{model}/{variant}/` | No | `PublicRouteResolver`, `PublicEntityRoutes` |
| `/brand/{stable-key}/` | Authority detail | Technical type and stable key leak | resolved public path | 301 | `PublicEntityRoutes::legacyIdentityRedirect` |
| `/model/{stable-key}/` | Authority detail | Technical type and stable key leak | resolved public path | 301 | `PublicEntityRoutes::legacyIdentityRedirect` |
| `/movement/{stable-key}/` | Movement detail | English namespace and stable key | `/bo-may/{slug}/` | compatibility pending | `PublicRouteResolver` |
| `/music/{stable-key}/` | Music detail | English namespace and stable key | `/ban-nhac/{slug}/` | compatibility pending | `PublicRouteResolver` |
| `/component/{stable-key}/` | Component detail | English namespace and stable key | `/linh-kien/{slug}/` | compatibility pending | `PublicRouteResolver` |
| `/specimen/{stable-key}/` | Specimen detail | English namespace and stable key | `/hien-vat/{slug}/` | compatibility pending | `PublicRouteResolver` |
| `/product/{stable-key}/` | Product detail | English namespace and stable key | `/san-pham/{slug}/` | compatibility pending | `PublicRouteResolver` |
| `/comparison/` | Comparison surface | English namespace | `/so-sanh/` | pending route alias | `PublicComparisonRoutes` |
| `/knowledge/claim/{uuid}/` | Knowledge detail | UUID leak | `/tri-thuc/{slug}/` | pending slug source | `PublicKnowledgeRoutes` |
| `/media/{uuid}/` | Media detail | UUID leak | `/thu-vien/{slug}/` | pending slug source | `PublicMediaVideoRoutes` |
| `/video/{uuid}/` | Video detail | UUID leak | `/video/{slug}/` | pending slug source | `PublicMediaVideoRoutes` |
| `/tim-kiem/?q=` | Search compatibility | Legacy query shape | `/?s=` | 301 | `PublicEditorialRoutes` |

## Implemented invariants

- Public slugs are lowercase ASCII, derived from canonical display names, and
  never from stable keys or UUIDs.
- Reserved roots include WordPress/system paths plus all approved Vietnamese
  namespaces; root Brand matching excludes them and fails closed on collision.
- Brand-scoped Model and Variant resolution requires an active, exact parent
  identity. Duplicate names or missing parents produce no public route.
- Authority archive payloads and search/home Authority cards expose the route
  resolver's URL instead of constructing stable-key links.
- WordPress editorial permalink/body/category authority is unchanged.

## Open acceptance gates

1. Add slug-bearing public serializers for Media, Video and Knowledge, then
   redirect UUID routes in one hop.
2. Add `/so-sanh/` and canonical collection aliases without overriding native
   WordPress routes.
3. Verify sitemap output contains only final canonical URLs and no private or
   hidden semantic records.
4. Re-run live rewrite/browser checks after deployment; current execution state
   records staging LiteSpeed rewrite failure and no server access.
