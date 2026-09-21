# NHK V3 Public Identity Cutover Analysis

> Read-only analysis of the supplied staging audit. No owner-level route
> mutation was performed. The aggregate receipt did not include the complete
> 503-item roster, so this document does not invent per-owner IDs.

## Audit receipt

```text
AUDIT_FINGERPRINT=20001d5a12fb574e08fec515ac9b7a29152ae9a5717b9943a2270c011a623498
TOTAL=503
KEEP=62
ALLOCATE=329
CHANGE=34
BLOCKED=78
OWNER_KINDS=authority:406;media_asset:37;video:17;wp_term:3;wp_post:40
BLOCKED=PUBLIC_URL_INVENTORY_INVALID:74;COLLISION_REQUIRES_RECONCILIATION:4
```

## Proven root-cause map

| Group | Count | Source condition | Missing requirement | Auto-fix | Semantic decision | Safe to defer |
|---|---:|---|---|---|---|---|
| `ROUTE_INPUT_INCOMPLETE` | 74 | `PublicUrlReprojectionPlanner` blocks when `route_type`, `scope` or `name` is empty | Valid route profile, collision scope and owner display name | No | Yes when identity/route profile is ambiguous | Yes |
| `COLLISION` | 4 | `CanonicalPublicSlugPolicy` exhausts base/meaningful-qualifier candidates or external/native route is occupied | Owner-scoped route ownership reconciliation | No | Yes | Yes, separately |

The 74 invalid records must be further classified from the missing field in
the staging item roster as `MISSING_ROUTE_TYPE`, `MISSING_COLLISION_SCOPE` or
`MISSING_ROUTE_NAME`. The supplied aggregate receipt does not identify which
owner has which missing field.

The four collision records must remain separate manual cases. The supplied
examples include Authority `cac17334-1fa2-4abf-a139-a985f155243e` and two
distinct MediaAsset owners sharing a candidate image path. No merge or rename
is safe without owner-specific identity and asset semantics.

## Change-owner analysis

The 34 `CHANGE` recommendations mean the planner's desired deterministic slug
differs from the current slug. That is not itself a route defect. Existing
durable public identity must be preserved unless an explicit owner-scoped
reprojection decision passes the Public Identity contract. Therefore:

```text
CURRENT_STABLE_IDENTITY_MUST_BE_PRESERVED=YES
LEGITIMATE_CORRECTION_REQUIRES_REVIEW=YES
DERIVED_DESIRED_SLUG_DIFF_ONLY=UNRESOLVED_PER_OWNER
ACTUAL_ROUTE_DEFECT=NOT_PROVEN
AUTO_CHANGE_AUTHORIZED=0
```

Published WordPress post slugs are editorial URLs owned by WordPress and must
not be rewritten merely because title-derived normalization differs.

## Allocate-owner analysis

The 329 `ALLOCATE` recommendations are not a public-route authorization. The
runtime inventory includes active registered Authority types, valid active
Videos, published WordPress posts/pages/terms and public-ready MediaAssets;
route eligibility remains profile- and consumer-dependent.

```text
PUBLIC_ROUTE_REQUIRED=OWNER_SPECIFIC_REVIEW_REQUIRED
PUBLIC_ROUTE_OPTIONAL=OWNER_SPECIFIC_REVIEW_REQUIRED
NOT_PUBLICLY_ROUTED=NOT_DETERMINED_FROM_AGGREGATE_RECEIPT
NOT_READY=NOT_DETERMINED_FROM_AGGREGATE_RECEIPT
ELIGIBLE_PUBLIC_OWNERS=NOT_COMPUTED_FROM_SUPPLIED_ROSTER
IDENTITY_PRESENT=62_KEEP_PLUS_CURRENT_UNVERIFIED_ROWS
IDENTITY_MISSING=329_RECOMMENDATION_NOT_AUTHORIZATION
SAFE_ALLOCATE=0
REVIEW_REQUIRED=34_CHANGE_PLUS_78_BLOCKED
COLLISION=4
NOT_PUBLICLY_ELIGIBLE=NOT_DETERMINED
INVALID_INVENTORY=74
```

Coverage must not use all canonical objects or all 503 candidates as the
denominator. A valid denominator requires the complete owner roster plus the
current route-profile eligibility/readiness result for each owner.

## Machine-readable owner classification requirement

The exact 78-item table required for Wave 1/2 review is not reconstructible
from the aggregate receipt alone. The next read-only audit must export one row
per owner with:

```text
OWNER_ID,OWNER_KIND,OWNER_TYPE,NAME,CURRENT_IDENTITY,CURRENT_PATH,
DESIRED_SLUG,BLOCKER,ROOT_CAUSE_CLASS,MISSING_REQUIREMENT,CAN_AUTO_FIX,
REQUIRES_SEMANTIC_DECISION,SAFE_TO_DEFER,RECOMMENDED_ACTION
```

Until that export exists, collision owners and all allocate/change candidates
remain outside mutation waves.
