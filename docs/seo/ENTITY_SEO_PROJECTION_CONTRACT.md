# Entity SEO Projection Contract

> **SUBORDINATE TO THE CONSTITUTION.** This is a read-only, projection-only
> contract.

This contract applies only to the nine registered Authority types: `brand`,
`model`, `variant`, `movement`, `music`, `component`, `classification`,
`specimen` and `product`. Authority existence does not imply public index
eligibility.

Every projection reuses canonical identity, current Public Identity, eligible
public content, representative Media and bounded Graph-derived related content.
It never derives a durable slug or infers semantic facts from prose, payload,
taxonomy, postmeta, filename, alt, caption or generated text.

## Type profiles

- Brand: identity, aliases, supported history, Models, eligible Articles,
  Videos, Media and relation-backed Movement/Music context.
- Model: parent Brand, bounded Variants and documented technical context.
- Variant: parent Model, registered Movement/Music relations, technical and
  evidence-backed context.
- Movement and Music: canonical identity and registered relationships only.
- Component and Classification: indexable only when their own public content
  profile is sufficient.
- Specimen: one physical identity and scoped observation/evidence context; it
  does not inherit Product copy as physical truth.
- Product: one commercial listing/offer/context. It may show Specimen context
  only through a separately registered dedicated relation; broad `about`,
  payload, taxonomy and postmeta are not substitutes.

## Eligibility

Indexability requires active canonical identity, resolvable Public Identity and
route, unambiguous canonical subject, sufficient differentiated visible content,
compliance and no canonical conflict. Failure returns a shared status and
deterministic reason codes; no SEO fallback writer is permitted.
