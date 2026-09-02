# NHK V3 Public Routing and SEO Design

> **NON-NORMATIVE.** This design is subordinate implementation guidance. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

## Goal

Make public semantic URLs Vietnamese, stable and identity-safe without changing
WordPress editorial data or semantic population.

## Decisions

- A single `PublicRouteResolver` owns public slugs, reserved-root validation,
  canonical URLs and public labels.
- Brand, Model and Variant use `/brand-slug/`,
  `/brand-slug/model-slug/` and `/brand-slug/model-slug/variant-slug/`.
- Cross-brand types use Vietnamese namespaces: `bo-may`, `ban-nhac`,
  `linh-kien`, `hien-vat`, `san-pham`, `video`, `tri-thuc`, `so-sanh` and
  `goc-chia-se` where those public surfaces exist.
- UUIDs, stable keys, graph keys and technical entity types remain internal
  lookup values and never appear in generated public links or canonical tags.
- Ambiguous slugs, missing Brand/Model parents and reserved-root collisions
  return no public route and therefore fail closed.
- Existing English, stable-key and encoded identity paths remain compatibility
  inputs handled by one-hop 301 redirects; no redirect chain is introduced.
- WordPress Posts remain the editorial URL/body authority. No post content,
  category assignment or semantic record is migrated or populated here.

## Affected boundaries

- Core application: route resolver and parent-aware entity lookup.
- Public HTTP: rewrite rules, canonical route handling and compatibility
  redirects.
- Theme: internal links, breadcrumbs, SEO/canonical metadata and Vietnamese
  labels.
- Tests and documentation: route contracts, inventory and execution state.

## Non-goals

No production cutover, destructive migration, article-body import, data
population, or case-level ambiguous slug decision is included.
