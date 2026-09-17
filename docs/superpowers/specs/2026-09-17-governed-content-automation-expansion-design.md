# Governed Content Automation Expansion — Architectural Spec

**Date:** 2026-09-17
**Status:** Approved architectural specification; implementation checkpoint completed 2026-09-17
**Scope:** MediaUsage representative, Knowledge, Classification, Brand, Model,
Movement generation/type, and registered Relationships
**Baseline:** Cúc cu Media binding case 567
**Regression cases:** Đồng hồ 400 ngày and Đồng hồ công cộng

## 1. Decision summary

NHK V3 will extend the existing Governance Automation pipeline. It will not
create an approval subsystem, approval table, queue, state machine, direct
writer, or publication authority for any newly covered domain.

The single governed path remains:

```text
Capture / internal MCP / Admin compatibility input
  → canonical Proposal
  → GovernanceService::submit
  → review or automated approval
  → ProposalEligibilityService::check
  → ControlledApplyService::apply
  → domain owner
  → canonical read-back
  → projection/readiness
  → ArticlePublicationGate + OwnerPublicationApplicationService when editorial
```

The policy changes only the review stop and whether the already-approved
pipeline continues. It never weakens subject binding, revision/CAS,
dependency closure, provenance, registry validation, idempotency, canonical
read-back, public identity, projection, or publication gates.

The existing owners remain authoritative:

| Concern | Owner | Automation adapter |
|---|---|---|
| Proposal, approval, eligibility, apply, audit | Governance | `GovernanceAutomationPolicyResolver`, `GovernedSemanticIngestOrchestrator` |
| Authority identity and lifecycle | Authority | `AuthorityProposalExecutor` |
| Knowledge claims | Knowledge | existing Knowledge executor/service |
| Source and Evidence | Source/Evidence | existing governed executor and dependency validator |
| Relations | Graph | `GraphService`, `PredicateRegistry`, `RelationRevisionBinder` |
| Media placement | MediaUsage | `MediaBindingService` delegated through Governance when this operation is governed |
| Editorial Post publication | WordPress/Article owner | `ArticlePublicationGate`, `OwnerPublicationApplicationService` |
| Admin review surface | Admin adapter | existing Governance Proposal queue |

## 2. Canonical constraints

This spec is subordinate to:

- `docs/constitution/NHK_V3_CONSTITUTION.md`;
- `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`;
- `docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md`;
- `docs/superpowers/specs/2026-09-07-governance-automation-policy-design.md`;
- `docs/superpowers/specs/2026-09-10-governance-admin-queue-design.md`;
- `docs/architecture/04_MEDIA_MODEL.md`;
- `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`;
- `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`;
- `docs/architecture/13_AUTHORITY_CORE_CONTRACT.md`;
- `docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`;
- `docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`;
- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`.

If a proposed expansion conflicts with the Constitution or a registered
contract, it is a `CONSTITUTION_CONFLICT` or registry gap and must stop before
implementation.

The normal new-content entry point remains `nhk.capture.ingest`. Direct
Knowledge, Source, Evidence, Media, relation, or Authority intake remains an
internal/Admin compatibility boundary. Automation does not promote those
compatibility tools to a new operator entry point.

## 3. Reuse of the existing Governance pipeline

### 3.1 Policy resolution

[`GovernanceAutomationPolicyResolver`](../../public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceAutomationPolicyResolver.php)
remains the only application decision point for automation mode.

The resolver currently returns:

- `REVIEW_REQUIRED` — submit and leave the Proposal for human review;
- `AUTO_APPROVE` — approve using the existing system actor, run Eligibility,
  then stop before Apply;
- `AUTO_PUBLISH` — continue through Apply, canonical read-back and the valid
  projection/publication boundary for that owner.

Missing configuration remains `REVIEW_REQUIRED`. Unknown types, operations,
profiles, predicates, and capability contexts are rejected or fail closed;
they never inherit an automatic mode merely because a similar type is enabled.

The implementation must replace the current type-only decision where the
contract requires a narrower scope. The policy lookup context must be derived
from registered vocabulary, not arbitrary UI strings:

```text
registered owner type
  + registered operation
  + registered profile/family where applicable
  + registered predicate where applicable
  + applicable capability context
```

Resolution must have an explicit deterministic precedence. A narrower rule can
only make the effective mode stricter than its broader fallback. Any missing
narrow rule defaults to `REVIEW_REQUIRED` unless the contract explicitly
declares a safe inherited mode.

The exact persisted key shape is an implementation detail to be finalized with
the registry contract. Free-form policy keys must not be accepted.

### 3.2 Proposal and approval

[`GovernanceService`](../../public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceService.php)
remains responsible for `create`, `submit`, `review`, `approve`, `reject`,
`cancel`, `supersede`, and applied-state validation.

Every Proposal must bind:

- exact subject or typed relation endpoints;
- registered operation and owner type;
- canonical payload fingerprint;
- expected target revision where applicable;
- dependency IDs and dependency-closure fingerprint;
- idempotency key;
- actor and decision actor.

Automated approval uses the existing system convention (`actor=0` with
`actor_kind=system` in audit context). It must not impersonate a human. Human
Admin approval retains the current capability and actor.

### 3.3 Eligibility

[`ProposalEligibilityService`](../../public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php)
remains the single Eligibility facade. Domain evaluators are composed behind
it; they do not become separate approval gates or queues.

The facade must preserve the current checks and add registered evaluators for:

- representative MediaUsage command validity and target/media revisions;
- Knowledge claim scope, provenance and Evidence/Source dependency closure;
- Classification family/profile and relation constraints;
- Authority profile-specific fields and dependent relation readiness;
- relation predicate, endpoint, cardinality and evidence rules;
- public projection readiness where `AUTO_PUBLISH` claims that boundary.

Every evaluator returns deterministic machine-readable reason codes. It must
not mutate canonical data, create a Proposal, or approve anything.

### 3.4 Controlled Apply

[`ControlledApplyService`](../../public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php)
remains the transaction owner and the only path from approved semantic intent
to a semantic write.

It must continue to own:

1. Proposal row locking and state check;
2. Eligibility recheck;
3. ApplyAttempt creation and retry history;
4. domain executor invocation;
5. canonical read-back verification;
6. atomic success transition and audit;
7. durable failure recording after rollback;
8. idempotent replay.

`ControlledApplyOperationRegistry` must be extended only after the operation is
registered in the relevant canonical contract. Registry support is necessary
but not sufficient: the executor, Eligibility, read-back, capability mapping,
and tests must all agree on the same operation descriptor.

## 4. Domain expansion

### 4.1 MediaUsage representative

`MediaUsage` is contextual placement, not a new semantic entity type. The
canonical owner remains [`MediaBindingService`](../../public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php).

The existing service continues to own:

- exact Media and target resolution;
- `representative` role validation;
- `USER_EXPLICIT/PINNED` and `SYSTEM_AUTO/AUTO` selection semantics;
- one active representative slot;
- replacement demotion without deleting Media or MediaAsset;
- binding receipt, idempotency, race handling and final read-back;
- bounded candidate scoring through `RepresentativeEligibilityRegistry`.

For an operation that the contract designates as Governance-controlled, the
flow is:

```text
representative intent/candidate selection
  → Proposal with registered owner/operation descriptor
  → Governance approval and Eligibility
  → ControlledApplyService
  → executor delegates to MediaBindingService
  → MediaBindingOperation receipt
  → MediaUsage final read-back
```

`MediaBindingOperation` remains an operational receipt. It must not gain
approval states or replace `Proposal`, `ProposalApproval`, `ApplyAttempt`, or
Governance audit.

The exact explicit binding contract may continue to execute immediately when
it is classified as a placement operation that does not require human review.
If policy requires review for that binding, the same Proposal pipeline wraps
the service call; no second approval model is introduced.

Auto-discovery remains bounded and candidate-driven. It cannot use Graph
reachability, NLP, filename similarity, or a MediaUsage row as semantic proof.
The current eligibility recipes cover specimen, variant, model, classification
and product. Brand, movement and Knowledge are not auto-representative targets
until a contract explicitly registers their scope and suitability recipe.

### 4.2 Knowledge, Source and Evidence

Knowledge remains atomic claims. Source and Evidence remain separate canonical
owners. Generated prose, OCR, caption, transcript, image observation and
MediaUsage are not Evidence merely because they entered the same Capture.

Existing operations remain governed through the common path:

```text
Knowledge/Source/Evidence proposal
  → submit/review/approve
  → dependency and provenance Eligibility
  → Controlled Apply through the existing executor
  → canonical read-back
```

Evidence proposals must retain active canonical Claim and Source IDs and their
revisions. Public projection must separately enforce visibility and public-safe
claim support. A Knowledge relation candidate is not automatically a valid
claim or Evidence record.

`AUTO_APPROVE` may stop at `ready_to_apply`; `AUTO_PUBLISH` may continue only
when the owner has a valid canonical apply, read-back, public projection and
frontend verification boundary. Otherwise the result is blocked at the first
unavailable gate.

### 4.3 Classification

Classification remains the Authority entity type `classification`. Clock Type
remains the registered profile `family=clock_type`; it is not a new entity
type, operation, or approval queue.

Classification automation must use:

- Authority registry/schema for create/update/lifecycle;
- profile/family validation before Apply;
- [`ClassifiedAsPolicy`](../../public/wp-content/plugins/nhk-core/src/Application/Graph/ClassifiedAsPolicy.php)
  for scope and provenance;
- [`ClassificationHierarchyPolicy`](../../public/wp-content/plugins/nhk-core/src/Application/Graph/ClassificationHierarchyPolicy.php)
  for same-family, active, cycle-free `subtype_of`;
- Graph-owned `classified_as` and `subtype_of` relations;
- canonical route/public identity and dossier readiness only at projection.

`classified_as` remains limited to the registered source types Model, Variant,
Specimen and Product. Brand, Movement and arbitrary reachable nodes cannot be
added by automation unless a new canonical predicate contract is approved.

### 4.4 Brand and Model

Brand and Model use the existing Authority registry and generic governed
operations. No new approval path is required.

Model-to-Brand structure is represented by the registered Graph predicate
`model_of`. The compatibility payload field `brand_uuid` is not a substitute
for canonical Graph truth. Variant-to-Model similarly remains `variant_of`.

For Authority automation:

- identity reuse and ambiguity must complete before Proposal creation;
- create/update/rename/rekey/retire/reactivate preserve canonical UUID;
- expected revisions and stable-key collision rules remain mandatory;
- relation candidates are separate Graph Proposals with endpoint revisions;
- derived Brand context is never persisted as an unregistered shortcut.

The policy may be configured for Brand and Model, but automatic mode does not
remove identity, dependency, relation-cardinality, public identity, or
read-back requirements.

### 4.5 Movement generation/type — contract gap must close first

The contract decision is to retain Movement as the canonical Authority owner
and register optional `generation` and `movement_type` string attributes. The
decision and registry change are recorded in
`docs/architecture/MOVEMENT_GENERATION_TYPE_CONTRACT.md`.

Therefore implementation must not:

- add a UI-only field;
- infer a new Authority type;
- encode generation/type in an unregistered payload key;
- create a new predicate or relation;
- reuse `classification` without an approved profile/scope contract;
- let automation accept the value because it appears in a fixture or legacy
  record.

The registered attributes use the existing Authority create/update operation,
optimistic revision/read-back and `movement` policy owner. They remain optional;
no vocabulary, relation cardinality or public route is implied. Unknown or
empty values remain fail-closed at the existing Authority boundary.

### 4.6 Relationships

Graph remains the sole relation owner. The existing registered predicates and
their endpoint/cardinality rules remain the only vocabulary:

`about`, `depicts`, `model_of`, `variant_of`, `uses_movement`,
`supports_music`, `configured_with_music`, `observed_playing_music`,
`subtype_of`, and `classified_as`.

Relationship automation uses:

- `relation_create`, `relation_retire`, `relation_reactivate`;
- `PredicateRegistry` for endpoint validation;
- `RelationRevisionBinder` for current endpoint revisions;
- relation-specific Eligibility and provenance/evidence checks;
- `GraphService` as the only writer;
- `CanonicalApplyReadBackVerifier` with Graph as relation owner.

Each relation Proposal binds source type/UUID/revision, target type/UUID/revision,
predicate, provenance/evidence context, dependency closure and idempotency.
Changing either endpoint after approval invalidates Eligibility.

The automation policy may select a relation mode only for a registered
predicate/operation context. A generic `relation` fallback must default to
review when a narrower predicate rule is absent. No edge is created from a
derived path, MediaUsage, claim text, or UI selection alone.

## 5. Article AUTO_PUBLISH boundary

Semantic `AUTO_PUBLISH` must not report native Article publication merely
because Controlled Apply and frontend projection succeeded.

When the owner is an editorial Post, the final path is:

```text
semantic Governance Apply/read-back
  → ArticlePublicationGate::check
  → PASS, OWNER_REVIEW_REQUIRED, or SYSTEM_BLOCKED
  → OwnerPublicationApplicationService
  → native WordPress publish
  → post read-back and publication receipt
```

[`ArticlePublicationGate`](../../public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php)
remains the deterministic diagnostic boundary. Only diagnostics classified as
`OWNER_REVIEW_REQUIRED` can be confirmed by the Owner. `SYSTEM_BLOCKED` cannot
be overridden by automation or Owner confirmation.

[`OwnerPublicationApplicationService`](../../public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php)
must validate post ID, editorial state token, blocker fingerprint, policy
version, principal, expiry, idempotency and native publish read-back.

Any handler without this valid Article publication boundary must return an
honest applied/projection or blocked result, never `AUTO_PUBLISH` success.

## 6. Admin review queue reuse

The existing Admin queue remains the only review queue:

- [`WpdbGovernanceQueueQuery`](../../public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbGovernanceQueueQuery.php)
  reads canonical Proposal rows and payload summaries;
- [`GovernanceQueueActionService`](../../public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php)
  validates capability, state snapshot and fingerprints;
- [`GovernanceQueueAdminPage`](../../public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueAdminPage.php)
  owns HTTP/nonce/POST handling;
- [`GovernanceQueueRenderer`](../../public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueRenderer.php)
  owns Vietnamese-first presentation;
- [`CanonicalGovernanceActionPort`](../../public/wp-content/plugins/nhk-core/src/Application/Governance/CanonicalGovernanceActionPort.php)
  delegates to Governance, Eligibility and Controlled Apply.

MediaBindingOperation receipts are not independently actionable queue records.
If a representative decision must be reviewed, it appears as a canonical
Proposal row whose payload carries the exact Media, target, role, selection
source/policy and candidate fingerprint. The queue then invokes the same
Governance actions; it does not write MediaUsage or receipt tables directly.

Claims remain Proposal context, not a second Claim queue. Relation rows remain
Proposal context, not a second Graph queue.

The current Admin automation settings must be brought into parity with the
runtime registry. In particular, `relation` must not be runtime-resolvable but
absent from the policy presentation. MediaUsage is not added as an entity type;
its policy row, if required, is represented by its registered Governance
operation/context.

## 7. Operation and capability-scoped staging guardrails

The current exact-ID staging packages remain bounded transitional evidence and
are not broadened by this spec. Future acceptance must use operation/capability
scope as the primary guardrail instead of maintaining a growing whitelist of
individual object IDs.

The implementation contract should introduce one guarded decision at the
staging execution boundary (proposed name: `GovernanceStagingGuard`; final
name follows the contract registry). It evaluates a signed, immutable context:

```text
environment = staging
verified build/documentation manifest
registered owner type + operation descriptor
operation family
required actor capability set
effective automation mode
subject/target endpoint types
dependency/revision closure fingerprint
bounded cardinality and payload limits
required read-back/projection obligations
```

The guard must enforce:

1. no production/V2 target;
2. no unregistered type, operation, predicate or profile;
3. no generic WordPress writer or direct table writer;
4. no operation outside the approved operation-family capability map;
5. no Apply without Proposal approval and current Eligibility;
6. no auto mode when the required capability or owner boundary is unavailable;
7. no cross-scope dependency or identity merge;
8. no mutation when documentation/build fingerprint differs from the approved
   context;
9. bounded batch size, candidate count, payload size and retry count;
10. mandatory canonical read-back and durable audit before success;
11. fail-closed diagnostics for unavailable, malformed, ambiguous or stale
    runtime state.

Existing capabilities are reused, including `nhk_view_governance`,
`nhk_create_proposals`, `nhk_submit_proposals`, `nhk_approve_proposals`,
`nhk_apply_proposals`, `nhk_internal_content_operations`,
`nhk_ingest_articles`, and `publish_posts` where already authorized. The guard
must not grant capabilities; it intersects the operation requirement with the
actor's existing capabilities.

The current explicit-ID packages remain valid only as a stricter legacy layer
until the operation/capability guard is deployed and verified. This spec does
not authorize any live staging mutation, ID expansion, deployment, or
production cutover.

## 8. Baseline and regression requirements

### 8.1 Cúc cu baseline — MediaUsage representative

The first acceptance fixture is the existing exact Media binding case:

- Media: `01a0ab0c-fde0-7c01-a89d-fc5eef832c89`;
- attachment: `567`;
- target type: `classification`;
- target: `01a07614-832d-7f27-959c-74eb0cd63f3e`;
- stable key: `nhk:classification:clock-type.cuckoo-clock`;
- target name: `Đồng hồ chim cúc cu`;
- role: `representative`;
- explicit selection: `USER_EXPLICIT/PINNED`.

Required assertions:

- exact target/media resolution only;
- no Authority, Media, Asset, Graph or Knowledge creation;
- no duplicate active representative slot;
- old usage is demoted, never deleted, on replacement;
- same idempotency/fingerprint replays the binding receipt;
- changed payload under the same key is a conflict;
- final MediaUsage read-back is exact;
- if wrapped in Governance, the Proposal and ApplyAttempt are the only
  approval/apply records and the executor delegates to MediaBindingService.

### 8.2 Đồng hồ 400 ngày regression

The existing continuation case must prove that the expansion does not break
Capture continuation, Article draft state, MediaUsage reconciliation or native
publication ordering:

- Capture: `01a0ae4c-0fe7-72b1-8222-ece526ce0faa`;
- owner: `01a0a868-2918-7dac-81dc-bfc25e710068`;
- Post: `575`;
- Media: `01a0ae48-1008-7213-a91f-dde21d36e66b`;
- attachment: `574`.

The test must verify idempotent continuation and that semantic Apply/read-back,
MediaUsage readiness, ArticlePublicationGate and native Post publication remain
separate gates. A stale capture/post/usage revision must stop without partial
success.

### 8.3 Đồng hồ công cộng regression

The existing Public Clock cases must prove that:

- the canonical object is `classification` with `family=clock_type`;
- the stable key is `nhk:classification:clock-type.dong-ho-cong-cong` when
  the approved identity plan selects it;
- reuse/ambiguity/legacy-family checks remain fail-closed;
- `subtype_of` is same-family, cycle-free Graph truth;
- `classified_as` remains scope-bound and does not admit Brand or Movement;
- representative MediaUsage is presentation context, not Claim/Evidence/Graph;
- public route/readiness is a projection gate, not semantic approval;
- no hard-coded Public Clock entity or shortcut relation is introduced.

The existing Public Clock UUID/owner and any staging IDs must be read from the
current approved runtime package at acceptance time; this spec does not create
or expand a new data scope.

## 9. Contract decisions and implementation status

The following contract decisions are recorded and registered for implementation:

1. **Policy context contract:** owner policy remains keyed by registered owner
   type; operation/capability strictness is enforced at apply by the shared
   operation registry and staging guard. Future narrower persisted rules must
   use registered keys and default missing narrow rules to review.
2. **Media representative operation contract:** `media:representative_bind`
   governs `SYSTEM_AUTO/AUTO` selection; explicit `USER_EXPLICIT/PINNED`
   remains the existing immediate placement operation. The Proposal carries
   Media and target revisions; apply delegates to MediaBindingService and its
   receipt/read-back remains authoritative for MediaUsage.
3. **Movement generation/type contract:** use registered optional Authority
   attributes `generation` and `movement_type`; see
   `MOVEMENT_GENERATION_TYPE_CONTRACT.md`. No new owner, predicate or queue.
4. **Relation policy contract:** relation operations remain Graph-owned and
   registered; predicate/endpoint eligibility remains mandatory, with missing
   narrow policy defaulting to review.
5. **Article automation contract:** semantic `wp_post` AUTO_PUBLISH now requires
   the injected publication boundary, which calls
   `OwnerPublicationApplicationService::request` and therefore
   `ArticlePublicationGate` before native publish.
6. **Staging guard contract:** `OperationScopedStagingGuard` is the shared
   operation/capability guard with bounded payload/dependency checks and
   fail-closed diagnostics; exact-ID packages remain historical evidence.
7. **Policy persistence/audit contract:** retain the existing option adapter if
   appropriate, but emit policy changes through shared append-only Governance
   audit; do not create a second policy audit model.
8. **Registry/runtime parity contract:** make Plugin, GovernanceRuntimeFactory,
   Admin settings, queue query and MCP use one registered policy/operation
   source. `relation` and all newly registered contexts must not drift between
   runtime and UI.

## 10. Implementation sequence and checkpoint

The approved implementation checkpoint covers the registry, shared executor,
Eligibility, Media representative delegation, Article publication boundary,
Admin/queue parity and staging guard. Further production/staging acceptance is
still separately gated.

1. Update canonical operation/profile/policy registries and generated contract
   snapshots.
2. Unify resolver/runtime wiring and add shared policy audit.
3. Extend Eligibility with domain evaluators and reason-code contracts.
4. Add the registered Media representative executor delegation.
5. Add relation predicate-scoped resolution while keeping Graph as owner.
6. Add the Movement generation/type owner model only after registry approval.
7. Integrate Article AUTO_PUBLISH with the native publication service/gate.
8. Extend the existing Admin queue presentation from Proposal payload/read model
   only; do not add a queue.
9. Deploy the staging guard and verify Cúc cu, 400-day and Public Clock
   regressions using read-only checks first.
10. Run focused unit/contract/integration tests, full regression, PHP lint,
    documentation generation, `git diff --check` and secret review before any
    later implementation checkpoint.

## 11. Required test matrix

### Governance core

- resolver default, strict fallback and registered-context validation;
- shared runtime parity across Plugin, Admin, MCP and reconciliation;
- review-required stop;
- auto-approve stops after Eligibility;
- auto-publish cannot bypass Apply/read-back/projection/publication;
- stale revision, dependency fingerprint, idempotency and replay;
- system actor/audit failure fail-closed;
- apply rollback, retry and durable failure history.

### MediaUsage

- exact bind, pinned protection, replacement demotion and receipt replay;
- representative Proposal delegation to MediaBindingService;
- no direct Usage repository calls from Governance/Admin/MCP adapter;
- candidate scope, tie, missing target, readiness and race conflicts;
- Cúc cu case 567 regression.

### Knowledge and Classification

- atomic claim and Evidence dependency validation;
- private Source/Evidence never becomes public through automation;
- classification family/profile, `classified_as` scope and provenance;
- `subtype_of` same-family/cycle/cardinality checks;
- Public Clock and Cúc cu regressions.

### Authority and Relationships

- Brand/Model identity reuse, collision, revision and public read-back;
- Model `model_of` and Variant `variant_of` Graph ownership;
- every registered relation predicate endpoint/cardinality/evidence rule;
- changed endpoint revision invalidates Eligibility;
- unknown predicate/type/operation returns registry gap;
- Movement generation/type uses the registered Movement Authority attributes;
  unknown fields still fail closed.

### Admin and staging

- one existing queue handles every governed Proposal type;
- no MediaBindingOperation/Claim/relation parallel queue;
- capability, nonce, snapshot and fingerprint checks;
- operation/capability staging guard bounds and fail-closed behavior;
- 400-day continuation and Article publication ordering;
- no new MCP operator writer or generic WordPress writer.

## 12. Non-goals and stop conditions

This spec does not authorize:

- code or schema changes;
- new entity types, unregistered fields, predicates or operations;
- direct MediaUsage, Authority, Knowledge, Evidence or Graph writes;
- migration, import, V2/production mutation or physical relation backfill;
- a second approval table, approval state machine, audit model or Admin queue;
- native Article publication outside `OwnerPublicationApplicationService`;
- automatic identity merge or ambiguous subject resolution;
- staging mutation before the new guard and contract evidence are verified.

Any unresolved Movement generation/type decision, registry drift, unavailable
owner service, missing capability, stale dependency, ambiguous identity or
unverified read-back is a fail-closed blocker.
