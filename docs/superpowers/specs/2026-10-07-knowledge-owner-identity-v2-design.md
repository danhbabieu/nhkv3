# Knowledge Owner Identity V2

## Status and scope

This design completes the current Knowledge duplicate-prevention, audit and
future reconciliation architecture. It extends the current implementation
baseline; it does not revert the system-wide duplicate-prevention work.

The change is code- and test-only. It does not migrate, seed, repair, merge,
retire or otherwise mutate staging/production data. It does not recover the
eight incident records, touch Côn hoa thị, or reconcile the 52
`POSSIBLE_DUPLICATE` clusters.

Knowledge remains the sole owner of Claim identity. Source and Evidence remain
support/provenance owners. No global semantic matcher or new semantic owner is
introduced.

## Invariants

The implementation must preserve these invariants:

1. `NEW SOURCE != NEW CLAIM`.
2. A new request key does not imply a new Claim.
3. Different wording does not imply a new identity when the contract-defined
   proposition is equivalent.
4. A different canonical referent cannot reuse the same Claim.
5. Ambiguity is never treated as `CREATE_NEW`.
6. Absence of an exact match is not proof of a new Claim.
7. Retired equivalent Claims enter review/reactivation handling and never
   silently become a new Claim.
8. Audit, pre-create and enrichment use the same Knowledge identity boundary.
9. Audit remains read-only and always returns `apply=false`.

## Canonical identity boundary

`KnowledgeClaimIdentity` becomes the canonical owner-local identity resolver.
It exposes a structured resolution result containing:

- `status`: exactly `RESOLVED`, `UNRESOLVED` or `CONFLICTING`;
- `profile`: the owner-local identity profile;
- `identity`: the canonical identity components when resolved;
- `fingerprint`: deterministic SHA-256 of the canonical identity packet;
- `diagnostics`: bounded reasons and conflicting values.

The same boundary is consumed by:

- `KnowledgePreCreateResolver` before Claim creation;
- `KnowledgeEnrichmentPlanner` when deciding `same_claim` versus a new/review
  candidate;
- `SystemWideDuplicateAuditCoordinator` when grouping Knowledge rows;
- the owner-specific future reconciliation planner before it can emit any
  executable retirement/move command.

No consumer may compare an identity packet whose status is not `RESOLVED`.
`UNRESOLVED` and `CONFLICTING` packets are never equivalent and always produce
`REVIEW_REQUIRED` in a decision path.

### Ordinary atomic Knowledge profile

The resolved identity is:

`canonical subject + facet + scope + claim type + deterministic proposition`.

The proposition is the deterministic normalized proposition already required by
the Knowledge contract. Fuzzy or lexical similarity can produce a review
candidate, but cannot establish identity.

### Video provenance profile

For `CAPTURE_VIDEO_SOURCE_PROVENANCE`, the resolved identity is:

`canonical subject + provenance proposition class + Video referent`.

The proposition class is contract-defined as: the canonical Video is identified
as concerning the canonical subject. Generated sentence wording is not part of
this identity.

The Video referent is resolved using the current Video identity law:

`platform + external_video_id`.

`canonical_video_id` may be retained as a resolved owner reference, but it is
not a second identity form. If only `canonical_video_id` is supplied, the
implementation must resolve it read-only to the canonical platform and
external video ID. Failure to prove that mapping is `UNRESOLVED` and
`REVIEW_REQUIRED`.

Missing platform/external ID, contradictory values, or contradictory canonical
Video mappings are `UNRESOLVED` or `CONFLICTING` as appropriate. Two missing
Video referents must not collapse merely because both have a serialized
`{"unresolved":true}` value.

## Stable-key policy

New Video provenance Claim stable keys are derived from the resolved canonical
Knowledge identity packet. Source locator and URL are excluded because they
belong to Source identity.

Existing Claims are never rekeyed. If a legacy Claim with a locator-derived
stable key is proven equivalent by the V2 identity boundary, it is reused with
its existing stable key. A changed wording or equivalent source locator must
not create a second Claim.

If the identity packet is not resolved, no new Claim stable key is generated
for creation; the result is review-only.

## Audit behavior

Knowledge audit grouping calls the same identity boundary as pre-create and
enrichment. Resolved equivalent identity plus equal deterministic proposition
may produce a definite duplicate signal. Resolved identity with non-identical
proposition remains a semantic-equivalence review candidate, not proof of
identity.

Unresolved or conflicting identity rows are excluded from equivalence groups
and receive explicit `REVIEW_REQUIRED` diagnostics. They must not generate a
definite duplicate cluster.

The system-wide audit remains bounded, cursor-based, HMAC-protected and
read-only. Pagination uniqueness and existing owner boundaries remain
unchanged.

## Reconciliation safety

The audit projection remains planning-only. A Knowledge reconciliation planner
may only produce an executable candidate after freshly verifying:

- both records have current identity status `RESOLVED`;
- both canonical Knowledge identity packets are equal;
- current Claim revisions match the plan;
- dependency topology is unchanged and fingerprinted;
- Claim, Source and Evidence lifecycle/dependency transfer is valid;
- the plan is bound to the identity policy/version and identity fingerprint.

The executable plan must carry the policy/version, identity fingerprint,
record revisions and dependency fingerprint. Any stale value fails closed.

`POSSIBLE_DUPLICATE`, `UNRESOLVED` and `CONFLICTING` can never produce an
automatic retire plan. The planner must not bypass
`KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` by decomposing a repair into
manual create/retire operations.

## Required regression coverage

Tests must cover:

- ordinary Claim reuse across different Source records and request keys;
- same Video + same subject reuse with same and different wording;
- equivalent Video URL/source locator reuse;
- different Video + same subject remains distinct;
- same Video + different subject is distinct or review-only;
- missing and conflicting Video identity return `REVIEW_REQUIRED`;
- legacy locator-derived stable key is reused without rekeying;
- retired equivalent Claim follows review/reactivation and never creates;
- audit, pre-create and enrichment produce identical identity results;
- stale identity-policy, revision or dependency fingerprints block planning;
- `POSSIBLE_DUPLICATE` produces no executable mutation plan;
- audit pagination remains unique and read-only.

## Deployment and live verification

No migration is expected. The implementation must pass focused Knowledge,
Capture and audit tests, PHP lint, the relevant full unit suite, `git diff
--check` and secret review.

After deployment, run the 1,176-row Knowledge audit read-only. Record whether
the eight false definite duplicates remain absent, pagination cluster counts
remain unique/correct, and `mutated=false`. If the target runtime cannot be
accessed or does not match the authorized TEST identity, report the live audit
as not executed; do not substitute a mutation or an unverified claim.

The task ends with a fresh read-only recovery plan for the eight incident
records only after deployment and live audit verification. Recovery is not part
of this task.
