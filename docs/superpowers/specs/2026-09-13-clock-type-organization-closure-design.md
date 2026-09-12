# Clock Type Organization Closure Design

**Date:** 2026-09-13  
**Status:** Approved by user for implementation  
**Scope:** PR6.1 closure, central Entity Profile organization, root route read/collision foundation, admin/operator read projection, and canonical individual Clock-Type creation lifecycle.

## Goal

Complete the organizational boundaries for Brand and Clock Type without adding
ontology, without creating semantic data, without legacy bulk apply/backfill,
and without starting PR7.

## Locked architecture

Brand and Clock Type remain independent profiles on the existing Semantic Core:

```text
Brand      = entity_type: brand          = profile: brand
Clock Type = entity_type: classification = family: clock_type = profile: clock_type
```

There is no `ClockType` entity type and no intersection entity. Model, Variant,
Specimen and Product may use `classified_as → Classification(family=clock_type)`.
Brand and Movement may not use `classified_as` for Clock-Type membership.
Brand ↔ Clock Type is derived query output only.

`family=clock_type` is the only canonical family. The existing stable-key
convention `nhk:classification:clock-type.*` may remain; stable key and family
are separate fields and family is never inferred from stable key, title, slug or
name. Existing `family=clock-type` records remain compatibility-read only and
are never normalized by this work.

## A. PR6.1 audit closure

`ClockTypeClassificationAudit` will depend only on narrow read-only contracts:

- `AuthorityInventoryReader` for bounded source pages;
- `ClassificationTargetInventoryReader` for bounded Classification target pages;
- `GraphReader` for bounded outgoing relation reads;
- `ClockTypeAuditEvidenceReader` for safe canonical evidence summaries.

The audit will not import or type-hint `GraphService`, Proposal, Governance,
Controlled Apply, or any writer. Existing production adapters may implement the
read ports, but the audit sees only those interfaces.

Evidence resolution is explicit and canonical:

```text
Claim → Evidence → Source
```

The adapter must validate canonical IDs, exact subject/type/scope, active state,
revision, `supports` relation, provenance and dependency state. It emits only
safe references/revisions/status summaries; raw claim text, excerpts, private
metadata and hidden Source/Evidence payloads never enter the report.

Authority source inventory is processed page-by-page in deterministic order:
`model`, `variant`, `specimen`, `product`, ordered by canonical UUID and bounded
cursor. Each report records `batch_size`, `cursor`, `next_cursor`,
`records_read`, `completed`, surface status and typed reason. Unavailable reads
remain unavailable and are not reported as empty completion.

Classification target inventory keeps these exact buckets:

```text
CANONICAL_CLOCK_TYPE
LEGACY_CLOCK_TYPE
OTHER_CLASSIFICATION_FAMILY
FAMILY_MISSING
FAMILY_UNRESOLVED
INACTIVE
```

Legacy `clock-type` is compatibility-read only. Stable-key prefixes cannot
prove a family. The sanctioned maintenance callable remains read-only and
returns `LIVE_AUDIT_SURFACE_NOT_EXPOSED` when the target surface is absent;
there is no mutation workaround.

## B. Central Entity Profile organization

`EntityProfileRegistry` is the only application seam for resolving Brand and
Clock-Type profiles. `EntityProfileDefinition` exposes the minimum profile
contract without becoming persistence truth:

- `profile_key`;
- canonical entity-type/family matcher;
- visitor and admin display labels/badge;
- capability matrix;
- dossier recipe;
- related-query recipe;
- public-route intent;
- archive intent;
- presentation sections/order/labels.

The existing `key`/legacy field shape remains compatible where needed, but the
serialized profile contract uses the explicit profile key. Only an exact
`classification + family=clock_type` resolves to `clock_type`. Other
Classification families remain unresolved for Clock-Type capabilities and keep
their own canonical identity.

The registry decorates existing dossier/read models; it does not duplicate
Authority, Graph, Knowledge, Source/Evidence, Media, Video, Public Identity or
SEO truth. Graph predicate legality stays in the Graph registry, Public Identity
stays with its owner, and domain owners remain unchanged.

## C. Root Public Route Registry foundation

Root resolution is read-only and separate from route allocation:

```text
root slug → existing Public Identity → canonical UUID → entity type/profile → Entity Dossier
```

The central ownership reader composes existing owners for:

- persisted Public Identity entities;
- WordPress Page/Post routes;
- registered frontend routes;
- `/video/`, `/anh/`, `/thuong-hieu/`, `/loai-dong-ho/` and other approved
  namespaces;
- `wp-json`, `wp-admin`, feed/search/sitemap and system-reserved roots.

One root slug maps to one canonical destination. Any entity/Page/Post/video/
registered/reserved collision is `PUBLIC_SLUG_CONFLICT`; missing owner registry
is `ROOT_ROUTE_REGISTRY_UNAVAILABLE` and ultimately `ORGANIZATION_BLOCKED`.
There is no `foo-2` fallback, last-write-wins behavior, route allocation,
Public Identity reprojection, 301 migration, sitemap mutation or live route
change in this scope. Existing namespaced Classification routes remain intact.

## D. Admin/operator projection

Add a read-only profile projection over canonical Authority plus existing dossier
read owners. For a Clock Type it exposes:

```text
name, profile, entity_type, family, UUID, stable_key, revision, state,
aliases, description,
knowledge, media, video, articles, models, variants, specimens, products,
related brands, subtypes, parent type, public identity, SEO/readiness,
diagnostics
```

The badge is registry-owned (`[LOẠI ĐỒNG HỒ]`) and never title-derived. Each
section retains one of:

```text
AVAILABLE_WITH_ITEMS
AVAILABLE_EMPTY
UNAVAILABLE_IMPLEMENTATION_GAP
BLOCKED
```

No projection writes, parallel datastore, semantic truth, or generic writer is
introduced.

## E. Individual Clock-Type creation lifecycle

The operator phrase “Thêm loại …” uses the existing Authority Capture/planning
boundary and one governed lifecycle:

```text
search/reuse canonical
→ exact canonical reuse, if present
→ Authority PLAN, if absent
→ owner review
→ Proposal → approval → eligibility → Controlled Apply
→ canonical Classification read-back
→ duplicate verification
→ optional Knowledge/Media/Video enrichment
→ separate Public Identity handling
```

New candidates are bound to:

```text
name
entity_type=classification
family=clock_type
aliases
description
proposed stable key
provenance
ambiguities
blockers
```

The existing stable-key policy may propose
`nhk:classification:clock-type.*`; no old key is rewritten. Exact reuse must
resolve an active canonical Clock-Type profile. Legacy-family matches remain a
typed compatibility/review condition, not a canonical new-data target.

`subtype_of` is allowed only from Classification to Classification when both
profiles resolve to active `clock_type`, the relation is cycle-free, and the
candidate is a real hierarchy. It is not used for origin, material, case form,
music, feature, rod count or dial form.

## F. Regression and evidence closure

Tests cover:

1. Brand versus canonical Clock Type;
2. Clock Type versus Case Form with equal-looking names;
3. brandless Specimen/Product validity;
4. Brand + Model/Variant + Clock-Type membership and derived Brand ↔ Type;
5. unchanged Video `about`;
6. unchanged Media `depicts`;
7. unchanged Knowledge subject/promotion boundary;
8. entity/Page/Post/video/entity/reserved route collisions;
9. audit read-only dependency purity and unavailable-vs-empty behavior;
10. search/reuse-first creation, governed plan/apply/read-back and duplicate
    prevention.

Final verification includes full Unit, Contract and relevant Integration
 suites, PHP lint, `git diff --check`, secret review, canonical documentation
snapshot regeneration/parity and fresh read-only runtime verification. A
database/runtime gap is reported as typed `ORGANIZATION_BLOCKED`; no SQL,
writer, metadata heuristic or live semantic mutation is used as a workaround.

## Explicit non-goals

- No `ClockType` Authority/entity type;
- no intersection identity such as `Odo vai bò`;
- no legacy bulk apply/backfill or family normalization;
- no route allocation/reprojection/redirect migration;
- no Video, Media or Knowledge semantic rewrite;
- no automatic Clock-Type creation or enrichment;
- no PR7 implementation or execution.
