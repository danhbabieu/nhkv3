# NHK V3 Governance Core Contract

> **NON-NORMATIVE IMPLEMENTATION CONTRACT.** The current Constitution and
> executable Governance boundaries control. Early P4 migration/surface notes are
> historical implementation evidence and must not be used as current capability
> truth.

## Current Governance boundary — 2026-09-07

Governance is the durable semantic mutation control plane. MCP/Admin are adapters
into the same application boundary; they are not alternate writers.

The current lifecycle is:

`proposal create/ingest → submit → review → approval with content/dependency
binding fingerprints → eligibility → Controlled Apply → canonical owner
read-back → idempotency verification`.

A Proposal binds subject/operation, canonical command payload/content
fingerprint, expected revision where applicable, dependency-closure fingerprint
and idempotency key. Review returns the exact bindings required for a valid
approval. Approval is valid only while those bindings match. Eligibility checks
proposal state, target/dependency existence/revisions and operation-specific
requirements. Controlled Apply performs the owning mutation transaction and
audit.

Proposal state is not canonical success. `DRAFT`, `SUBMITTED`, `APPROVED`,
`ready=true` and an Apply response are intermediate control-plane states.
`COMPLETED` requires the canonical owner to read back the intended mutation and
a replay/idempotency check to prove no duplicate side effect.

## Current semantic write coverage

Registered Governance paths cover current Authority operations, Knowledge,
Source, Evidence, Media, Video and Graph relation operations exposed by the
runtime catalog. A historical statement that semantic writers or Graph mutation
are generally unavailable is superseded by the current executable boundary and
verified relation runtime evidence.

Graph `relation_create` must carry canonical typed endpoints:
`source_type/source_uuid`, registered predicate,
`target_type/target_uuid`. The historical relation proposal hydration bug that
could expose an entity-type string as `subject_id` is resolved at the proposal
repository boundary. Do not treat it as a current global Graph blocker.

## Authority create status

Authority-create runtime probe proposal
`01a07c4e-14b2-734e-8264-3f04b37e5fe4` for a Classification passed create,
submit, review/fingerprint binding, approval and eligibility with
`ready=true`, `reasons=[]`. It was intentionally not Applied to avoid creating a
junk canonical node.

Therefore current evidence proves Authority create through eligibility only.
The actual new-node chain `Controlled Apply → generated canonical UUID →
Authority/entity resolver read-back → immediate relation use` remains intentionally
unproven until a genuine node is required.

A pre-create entity-type subject marker is not the same as the old relation
source-binding defect because the new node has no canonical UUID yet.

## Reconcile-before-create and no-orphan boundary

Governance does not turn a create command into permission to skip canonical
research. Before a new Authority node or Knowledge claim is proposed, the
caller/orchestrator must resolve current canonical data and reconcile the intent
as exact-existing, merge-candidate, related-but-distinct, genuinely-new or
uncertain.

Uncertain identity is not converted into a create Proposal. A Knowledge claim
must have its intended canonical subject/context resolved before creation. If a
new Authority node is required, it must be applied and read back first. Detached
“attach later” claims are non-compliant and must be deferred.

## Automation policy

Human review is configurable; Governance gates are not. Supported automation
modes are `REVIEW_REQUIRED`, `AUTO_APPROVE` and `AUTO_PUBLISH`; missing policy
defaults to `REVIEW_REQUIRED`.

Automation uses the same proposal, review/binding, approval, eligibility,
Controlled Apply and canonical read-back boundaries. `AUTO_APPROVE` stops before
Apply. `AUTO_PUBLISH` must additionally prove applicable projection/frontend
availability before publication success is reported. Automated actions use the
system actor/audit convention and do not bypass capability, registry or owner
checks.

## Retry, failure and idempotency

Controlled Apply locks/reloads the proposal and re-evaluates eligibility.
Deterministic failure rolls back the semantic mutation/proposal transition;
bounded failed-attempt audit is recorded according to the retry contract.
Revision/dependency drift blocks retry fail-closed.

An APPLIED proposal replay returns/reuses its durable result and creates no
second mutation. When execution is interrupted by rate limit/runtime failure
after a proposal exists, the same proposal/idempotency binding is reused if the
intent is unchanged; retry must not mint a duplicate proposal just to continue.

## Historical merge incident clarification

The September 4 pinned-dial merge diagnostic that persisted
`subject_id="component"` instead of the supplied source UUID is retained as
**HISTORICAL, SCOPE-SPECIFIC EVIDENCE**. The diagnostic was rejected and no merge
occurred. It is not evidence that current `relation_create` source binding or all
Graph mutation remains blocked.

Identity-risking merge/rekey remains a separately governed high-impact
operation and still requires current source/target revision and canonical
read-back proof before use.

## Canonical owner read-back map

After mutation, verify the owning boundary:

- Authority → entity/resolver read-back;
- Knowledge → canonical Knowledge read;
- Source → canonical Source read;
- Evidence → canonical Evidence/internal evidence-chain read;
- Graph → edge/outbound/inbound/neighborhood read-back as appropriate;
- Video → canonical Video read;
- Media → canonical Media/asset/usage read;
- WordPress Article → native WordPress read/rendered verification for editorial
  state.

Apply success alone does not prove frontend/publication success. Projection and
frontend availability remain separate post-canonical gates.
