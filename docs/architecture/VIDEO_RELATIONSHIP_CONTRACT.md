# Video Relationship Contract

> **NON-NORMATIVE CURRENT IMPLEMENTATION CONTRACT.** Constitution and current
> executable Graph/Governance boundaries control.

## Registered relation boundary

Video relation proposals use the canonical Graph only. The current executable
registry permits Video outbound `about`. `depicts` is Media-only. No Hub,
thumbnail, CTA, album, UI section or keyword creates a predicate.

Each relation preserves:

- `source_type=video`;
- real canonical `source_uuid`;
- registered `predicate=about`;
- real canonical `target_type` and `target_uuid`;
- canonical evidence references when the Video relation contract requires them;
- explicit/inferred provenance/origin and bounded reason/confidence where
  applicable.

Unknown/ambiguous target, unsupported endpoint or predicate fails closed.

The historical proposal-hydration defect that could expose an entity-type string
as relation `subject_id` is **RESOLVED**. Current relation packets/hydration
preserve the Video UUID as the canonical source. Do not treat source binding as a
current global Graph blocker.

## Evidence reference contract

`evidence_refs` is a non-empty list of exact objects:

`{"evidence_id":"<canonical Evidence UUID>"}`.

The referenced Evidence must resolve canonically and remain active with its
canonical Claim and Source dependencies. Public visibility is a separate
question: active PRIVATE/HIDDEN Source/Evidence may be verified through the
governed/internal owner boundary without being rewritten PUBLIC or exposed by a
public `evidence.get` response.

Therefore the older wording “Evidence must be publicly usable before the
relation can exist” is superseded. What is required for the governed relation is
canonical validity, dependency/provenance binding and the owning relation policy;
public serialization has its own eligibility/privacy gate.

A Proposal UUID is a Governance command identity only. It is never an
`evidence_id`, Video UUID, target UUID or Graph edge identity.

## Guided provenance orchestration

The current guided Admin Video relation flow accepts canonical Video + canonical
Authority target. It resolves the latest Video proposal/provenance and
resolve/reuses or deterministically creates the private canonical YouTube
Source, provenance-scoped Claim and Evidence needed by the relation. The chain is
read back before relation proposal creation.

Normal operators do not manually type Video proposal UUID or Evidence UUID. The
application service builds canonical `evidence_refs`, stable dependency/content
fingerprints and idempotency. Wrong provenance fails closed rather than silently
reusing an unrelated Source/Claim/Evidence.

This orchestration does not promote arbitrary Video metadata, transcript or
editorial prose into Knowledge. It creates/reuses only the bounded provenance
objects required by the explicit relation workflow.

## Governance and completion

Relation lifecycle:

`resolve/reconcile Video + target + provenance → relation proposal create →
submit → review → approval with binding fingerprints → eligibility → Controlled
Apply → canonical Graph read-back → idempotency verification`.

Apply/approved state is not completion. The active Graph edge must be read back
with the expected source type/UUID, predicate and target type/UUID. Replay of the
same durable intent must not create another active relation or duplicate
provenance chain.

## Registry gaps are not `about`

Do not use Video `about` or broad `about` elsewhere to fake:

- `classified_as` membership;
- Model/Variant classification;
- structural parentage;
- configuration or `uses_movement` not permitted by endpoint registry;
- Product–Specimen ownership.

Those remain their own `REGISTRY_GAP`/contract decisions.

## Public relation and semantic neighborhood

Public relation rendering occurs only after canonical Graph/public eligibility.
Public-safe Knowledge projection does not promote a relation and raw PRIVATE
Source/Evidence must not leak through relation payloads.

The current Graph application/MCP boundary includes bounded
`nhk.entity.neighborhood` plus operational Graph inventory. Related/dossier
queries distinguish direct, incoming/outgoing direction and derived paths, with
derived traversal bounded by the current Graph law.

If a Video/entity frontend does not yet consume every eligible neighborhood/path
profile, record `PARTIAL_FRONTEND_GAP`. Do not describe the missing frontend
consumer as “Graph neighborhood unavailable”, and do not keyword-search a fake
relation to fill the UI.
