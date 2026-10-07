# Knowledge Identity V2 Design

**Status:** Approved design; implementation pending plan review.

**Goal:** Complete the current Knowledge-owner identity design so duplicate
prevention, enrichment, audit and future reconciliation use one deterministic,
fail-closed identity law without rekeying or mutating existing data.

## Scope and non-goals

This design changes application-level identity resolution and planning only.
Knowledge remains the sole owner of Claim identity. Source and Evidence remain
provenance/support owners; Video remains the owner of Video identity; no global
duplicate owner is introduced.

This work does not migrate or rekey existing Claims, mutate staging or
production data, recover the eight incident records, touch Côn hoa thị, or
reconcile the 52 `POSSIBLE_DUPLICATE` clusters. The live 1,176-row audit is a
later read-only verification step after deployment and runtime verification.

## Canonical identity boundary

`KnowledgeClaimIdentity` becomes the shared Knowledge-owner boundary consumed by
pre-create, enrichment, duplicate audit and reconciliation planning. It returns
an explicit resolution status:

- `RESOLVED`: the canonical identity packet is complete and deterministic.
- `UNRESOLVED`: required identity evidence is missing or cannot be proven.
- `CONFLICTING`: independently supplied identity signals disagree.

Only `RESOLVED` packets may compare equivalent. `UNRESOLVED` and `CONFLICTING`
packets never compare equivalent and always surface `REVIEW_REQUIRED`.
Identity packets expose a deterministic policy/version and fingerprint so plans
can be bound to the exact policy used during resolution.

## Owner-local identity profiles

Ordinary atomic Knowledge uses:

`canonical subject + facet + scope + claim type + deterministic proposition`

Source identity, request idempotency keys, wording changes and locator changes
are not Claim identity. A new Source may support an existing Claim, and a new
request key must still reuse an equivalent Claim.

`CAPTURE_VIDEO_SOURCE_PROVENANCE` uses:

`canonical subject + provenance proposition class + Video referent`

The proposition class is contract-defined: the canonical Video is identified as
concerning the canonical subject. Generated sentence wording is not part of the
identity packet. Same Video plus same subject reuses the Claim even when wording
changes; a different Video or subject remains distinct or requires review.

## Video referent policy

The stable semantic Video referent is always:

`platform + external_video_id`

This is the identity used both before and after Video Apply. A
`canonical_video_id` may be retained as a resolved owner reference, but it must
not create a second Claim identity. If only `canonical_video_id` is supplied,
the resolver performs a read-only canonical Video lookup and extracts the
platform/external ID. Missing or contradictory lookup evidence produces
`UNRESOLVED` or `CONFLICTING` and therefore `REVIEW_REQUIRED`.

Two missing Video referents must not collapse into one identity merely because
both serialize as an unresolved marker.

## Stable-key policy

New provenance Claim stable keys are derived from the resolved canonical
Knowledge identity packet. Source locator is excluded because it belongs to
Source identity. Existing Claims retain their stable keys permanently for this
change. If a legacy provenance stable key maps to the same resolved V2 identity,
the resolver reuses the legacy Claim and never rekeys it.

`NEW SOURCE != NEW CLAIM`, `DIFFERENT WORDING != NEW IDENTITY`,
`DIFFERENT REFERENT != SAME CLAIM`, `AMBIGUOUS != CREATE_NEW`, and
`NO EXACT MATCH != PROVEN NEW` remain explicit invariants.

## Audit and reconciliation safety

The duplicate audit remains read-only and returns `apply=false`. Audit grouping
uses the shared owner-specific identity boundary and preserves unresolved or
conflicting status rather than converting uncertainty into equivalence.

An owner-specific reconciliation planner may only create a candidate plan after
a fresh read-only verification proves:

1. identity status is `RESOLVED`;
2. both records have equal canonical Knowledge identity;
3. current Claim revisions still match;
4. dependency topology and dependency fingerprint still match; and
5. Evidence transfer/lifecycle validation succeeds.

The executable plan is bound to identity policy/version, identity fingerprint,
record revisions and dependency fingerprint. Any stale or changed value fails
closed. `POSSIBLE_DUPLICATE`, `UNRESOLVED` and `CONFLICTING` can never produce an
automatic retire/move plan. The implementation must not bypass
`KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` by manually decomposing Evidence
creation/retirement and Claim retirement.

Retired equivalent Claims remain on a review/reactivation path and never cause
creation of a replacement Claim.

## Verification requirements

Focused tests must cover ordinary Claim reuse across Sources and request keys;
same/different Video and subject combinations; wording and equivalent locator
changes; missing and conflicting Video identity; legacy stable-key reuse without
rekey; retired equivalent handling; equality of pre-create, enrichment and
audit identity; stale identity-policy/revision/dependency rejection; and the
absence of executable mutation plans for `POSSIBLE_DUPLICATE`.

Before claiming deployment readiness, run PHP lint, focused tests, the full
applicable test suite, `git diff --check`, and secret review. After deployment,
run the 1,176-row Knowledge audit read-only and record pagination uniqueness,
false-definite-duplicate regressions and the absence of data mutation.

## Data and migration decision

No database migration is required. No existing semantic record is changed,
rekeyed, merged, retired or deleted by this design or its implementation.
