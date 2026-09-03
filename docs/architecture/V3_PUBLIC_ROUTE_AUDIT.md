# NHK V3 Public Route / SEO Audit

> **NON-NORMATIVE.** This is route/SEO evidence, not public-law authority. If
> it conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the
> Constitution controls.

Date: 2026-09-01
Status: canonical Authority and Video routing implemented; Media and atomic
Knowledge remain non-indexable where no governed public projection exists.

## Odo token correction — 2026-09-03

The former `o-do` public slug was produced by Vietnamese transliteration of
`Ô Đô`. Canonical public output is now `odo`; resolved legacy paths using
`o-do` redirect once with HTTP 301 to the corresponding `odo` path. This is a
route compatibility correction only; UUIDs, stable-key identity records and
immutable audit/source text are not rewritten by the route layer.

## Constitution conflict register

`CONSTITUTION_CONFLICT`: legacy technical links existed for Media, Video and
Knowledge. Authority and Video now have presentation routes. Media UUID detail
routes are retired because Media has no legitimate standalone public entity
projection; MediaAsset delivery remains separate. Atomic Knowledge Claims are
also non-indexable and consumed through semantic/Post projections. The only
remaining conflict is historical legacy URLs still present in external data;
they require read-only inventory and governed redirect/retirement review.

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
| `/knowledge/claim/{uuid}/` | Atomic Claim | No public entity projection | none; consume in related projections | 404/non-indexable | `PublicKnowledgeRoutes` |
| `/media/{uuid}/` | Media identity | No standalone public entity page | none; use related entity/Post and asset URL | 404/non-indexable | `PublicMediaVideoRoutes` |
| `/video/{uuid}/` | Video detail | Legacy internal identity | `/video/{title-slug}-{external-id}/` | 301 one hop | `PublicMediaVideoRoutes` |
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

## Coverage matrix

| Object | Public page | Canonical pattern | Slug source | Indexable | Legacy | Resolver/tests |
|---|---|---|---|---|---|---|
| Brand | Yes | `/{brand}/` | canonical name | Yes | 301 | Yes / unit |
| Model | Yes | `/{brand}/{model}/` | canonical name + parent | Yes | 301 | Yes / unit |
| Variant | Yes | `/{brand}/{model}/{variant}/` | canonical name + parents | Yes | 301 | Yes / unit |
| Movement | Yes when active | `/bo-may/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Music | Yes when active | `/ban-nhac/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Component | Yes when active | `/linh-kien/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Classification | Yes when active | `/phan-loai/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Specimen | Yes when active | `/hien-vat/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Product | Yes when active | `/san-pham/{slug}/` | canonical name | Yes | legacy detail | Yes / route smoke |
| Video | Yes when valid/public | `/video/{title}-{external-id}/` | title + external ID | Yes | 301 UUID | Yes / unit + smoke |
| Knowledge Claim | No atomic page | none | none | No | 404 | No public resolver |
| Media | No standalone page | none | none | No | 404 | Asset route only |
| Post | Yes | native WordPress permalink | WordPress editorial slug | Yes by WP status | native WP | WordPress |

## Open acceptance gates

1. Add `/so-sanh/` and canonical collection aliases without overriding native
   WordPress routes.
3. Verify sitemap output contains only final canonical URLs and no private or
   hidden semantic records.
4. Re-run live rewrite/browser checks after deployment; current execution state
   records staging LiteSpeed rewrite failure and no server access.
