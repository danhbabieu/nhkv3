# Visual Support Requirement Design

**Status:** approved architectural design, 2026-09-11.

## Goal

Make a semantic feature's need for a suitable illustration durable,
reusable, bounded and reconcilable across Article, Knowledge/Claim, Video,
Media annotation and public projections without creating a second semantic
truth system.

## Architectural decision

`VisualSupportRequirement` is an application-level persistent requirement
ledger. It is not an Authority entity, Knowledge Claim, Source, Evidence,
Graph edge, Media identity, Article entity or public page.

The ledger records that a canonical semantic subject in a precise scope,
facet and registered feature/detail context needs a visual with a registered
intent. `MediaUsage` remains the owner of contextual Media placement/binding
after a canonical Media has been selected. The existing Projection Dependency
Index remains the consumer invalidation index. Governance remains the only
semantic mutation boundary; a visual requirement or MediaUsage never creates
Evidence, Claim or Graph truth.

This owner is the smallest sufficient boundary because the existing owners do
not represent a missing requirement. `MediaUsage` requires a Media identity,
Knowledge metadata is not an indexed requirement ledger, and projection
dependencies do not store requirement state or suitability. A dedicated
application ledger therefore avoids both an invalid null-Media usage and a
parallel semantic owner.

## Vocabulary and registries

The requirement uses the existing canonical subject resolver and
`KnowledgeFacetProfile` facet/scope vocabulary. The feature/detail key must be
allowlisted by the existing Media detail registry; an unknown feature is a
`REGISTRY_GAP`, not a free-form semantic key. Visual intent is a new small
application registry because no existing registry expresses this distinction:

- `representative` — an image suitable to represent the subject as a whole;
- `technical_detail` — an image showing the requested technical feature;
- `evidence_like_illustration` — a contextual illustration that looks
  evidentiary but is not Source/Evidence;
- `contextual_illustration` — an image that explains the feature in its
  surrounding context.

The visual-intent registry does not expand MediaUsage's role registry and does
not authorize a Graph predicate. Existing `representative` node coverage and
feature-level visual support are separate diagnostics and projections.

## Requirement identity and persistence

Each requirement has a UUIDv7 application identity, optimistic revision,
idempotency key/fingerprint and a deterministic semantic identity fingerprint
over:

`subject_type + subject_id + scope + facet + feature_key + visual_intent`.

Consumer/context is excluded from this identity unless the consuming contract
proves that two different requirements are semantically necessary. Consumers
are indexed separately through the existing Projection Dependency Index. This
allows one requirement to serve multiple Articles, Knowledge fragments, Video
pages, Entity dossiers, technical sections and related projections.

The additive ledger stores the canonical subject, scope, facet, feature/detail
key, visual intent, state, selected Media UUID (nullable), selected Media
revision, provenance/context packet, unresolved reason, idempotency fingerprint
and timestamps/revision. The table is namespaced as
`{$wpdb->prefix}nhk_visual_support_requirements` and uses unique semantic and
idempotency keys plus bounded lookup indexes for state/subject/facet/feature/
intent and selected Media. No legacy rows are backfilled.

Requirement state is independent from Media public eligibility:

- `MISSING`: no exact, suitable canonical Media is bound;
- `RESOLVED`: an exact suitable canonical Media is bound at semantic/internal
  level;
- `REVIEW_REQUIRED`: a candidate or binding needs human review, or a previous
  binding became unsuitable.

The consumer projection separately reports `READY`, `INCOMPLETE`, `BLOCKED`,
`UNAVAILABLE` or `NOT_APPLICABLE` using the existing projection vocabulary.
`RESOLVED` never implies `PUBLIC`, and a private/review/ineligible Media is
never serialized to a public consumer.

## Creation and normal intake

When Article, Knowledge/Claim, Video, Media annotation or a public projection
identifies a visually explainable feature, its owner emits or reconciles a
requirement through the application service. This is not a new MCP intake
operation. New operator input still enters through `nhk.capture.ingest`; the
Capture subject-resolution packet is the immutable handoff to requirement
planning and Media reconciliation.

The normal ordered path is:

1. resolve the canonical subject;
2. validate the exact scope, facet and registered feature/detail key;
3. compute the semantic identity/idempotency fingerprint;
4. search the indexed requirement ledger and reuse the existing requirement;
5. search canonical Media before creating or requesting anything new;
6. validate exact subject scope, feature/detail, visual intent, asset/readiness,
   provenance/context and public policy as applicable;
7. bind/reconcile a suitable existing Media through `MediaUsage`, or retain
   `MISSING`/`REVIEW_REQUIRED` with a reason;
8. register the requirement as a projection dependency for each consumer;
9. invalidate/rebuild affected projections when the binding or revision changes;
10. read back the requirement, MediaUsage and affected projection state.

Filename, title, same Brand, broad Model, Article co-occurrence, keyword,
checksum, gallery membership and visual similarity alone never satisfy a
requirement. A Media must be exact for the canonical subject/scope/facet/
feature; a Variant/Specimen image cannot broaden a requirement to Model/Brand.

## Reverse reconciliation after Media ingest

After a Media has completed canonical ingest and read-back, the existing Media
post-ingest reconciliation pipeline invokes a bounded reverse lookup of
`MISSING` and `REVIEW_REQUIRED` requirements. The query is indexed by the
registered subject/scope/facet/feature/intent keys and is bounded by the
runtime candidate budget; it never scans the entire database.

For each candidate requirement, reconciliation verifies the canonical Media
read-back, exact subject and scope, feature/detail coverage, visual intent,
MediaAsset/readiness, provenance/context and any review/public policy. It ranks
only candidates that pass the suitability contract. An exact unambiguous match
becomes `RESOLVED` and receives a contextual MediaUsage/binding. A plausible
but insufficient or ambiguous match remains `REVIEW_REQUIRED`; a wrong
variant/model/scope is rejected and remains `MISSING` with a typed reason.

The sequence is:

`Media ingest → Media read-back → bounded missing-requirement lookup → exact
validation → suitability ranking → governed binding/reconciliation → final
Media/requirement/usage read-back → affected projection invalidation/rebuild`.

Replays are idempotent. A better candidate may replace a previous suitable
candidate only through deterministic comparison; the old MediaUsage/provenance
history is retained and the old Media is not deleted. A single canonical Media
may satisfy many independent requirements and consumers when each exact context
passes validation. No Media duplicate is created.

## Evidence and Claim separation

Visual support proves only that a Media is suitable to illustrate a semantic
detail in a context. It does not prove that the detail is true, does not
create a Knowledge Claim, does not create Source/Evidence and does not create
or modify a Graph edge. Image recognition, OCR, caption, alt, filename and
visual matching remain observations/candidates only.

If the Media or an observation is to support a Claim, it must separately pass
the Source/Evidence contract, exact subject/scope, provenance, eligibility and
Governance chain. Visual support cannot broaden a Specimen observation to a
Variant, Model or Brand.

## Consumers, public projection and admin read model

Consumers resolve visual support dynamically from the ledger/binding rather
than copying image URLs into Article bodies. WordPress `wp_posts` remains the
editorial owner of title/body and editorial image ordering. A binding/revision
change marks only dependent projection sections dirty and causes the next
rebuild/read to see the new Media without editing every Article.

Public projection selects only a public-safe derivative after the existing
MediaAsset/public eligibility policy. It omits `MISSING`, private, review,
placeholder, unavailable and ineligible Media. Internal `RESOLVED` state may
remain visible to Admin as a diagnostic, but public output must not claim that
an image is displayed merely because a semantic binding exists.

The future/admin read model exposes missing/review requirements, subject,
scope, facet, feature/detail, visual intent, candidate Media, resolved Media,
reason and affected consumers. Normal Admin input remains guided; raw UUID,
fingerprint and JSON are not required. A large Admin UI is out of scope for
this slice, but the read model and diagnostics must be stable enough for a
later workspace.

## Governance, safety and rollout

Requirement creation/reconciliation is application orchestration. It may
persist the ledger and MediaUsage binding through the existing owner services,
but it never bypasses Governance when the selected action changes semantic
truth or registered relation state. No direct writer is exposed in MCP.

The schema change is UP-only and additive. Development `nhk_v3` may receive
only a health/schema/UP migration when explicitly run; destructive operations
are forbidden there. Integration teardown is allowed only on exact
`nhk_v3_test` through the existing test guard. No existing or production data
is backfilled, repaired, merged, re-slugged or published.

## Acceptance scenarios

The implementation must cover:

1. A Knowledge note identifies a registered feature with no suitable Media:
   one idempotent `MISSING` requirement is retained.
2. Exact subject + scope + facet + feature Media resolves the requirement.
3. Same Brand but wrong Variant does not resolve it.
4. Same Model but no exact feature coverage does not resolve it.
5. A later Capture Media ingest reverse-reconciles the old requirement.
6. One Media satisfies three valid consumers without duplicate Media identity.
7. Replay creates no duplicate requirement, usage or binding.
8. A better candidate replaces the binding while preserving old provenance.
9. PRIVATE Media may resolve internally but is excluded from public output.
10. Visual support creates no Claim, Evidence or Graph edge.
11. Specimen scope never broadens to Variant/Model/Brand.
12. Missing visual remains an honest incomplete visual state, not infrastructure
    or blueprint corruption.
13. Documentation bootstrap/list/get exposes this active law and stale/tampered
    snapshots fail closed.

## Non-goals

This design does not add an Authority type, Knowledge type, Graph predicate,
MCP writer, standalone Media page, automatic Evidence promotion, legacy
backfill, Article body rewrite, placeholder-as-real-image behavior or a global
unbounded Media/requirement scan.
