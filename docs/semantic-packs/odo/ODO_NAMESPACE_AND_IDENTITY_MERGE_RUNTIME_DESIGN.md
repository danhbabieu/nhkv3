# Generic Namespace and Identity-Merge Runtime Design Review

**Status:** `IMPLEMENTED_LOCALLY_RUNTIME_BINDING_BLOCKED` — governed `rekey`
and `merge` operation vocabulary/executor are implemented locally and exposed
in the live schema; no semantic data mutation has been applied. Pinned-dial is
blocked by `LIVE_MERGE_SUBJECT_BINDING_INVALID` because live proposal binding
persisted `subject_id="component"` instead of the supplied source UUID.

**Scope:** generic NHK V3 capability for registered semantic endpoint types.
This is not an Odo-specific operation and must not be implemented as an Odo
adapter, a manifest shortcut or a direct SQL path.

## 1. Decision boundary

The current runtime has governed stable-key `rekey` and semantic `merge`
capability in the operation allowlist and local executor. This document
records the contract and the remaining live binding gate:

1. a generic Authority identity rekey capability that preserves canonical UUID;
2. a generic same-type semantic merge capability that moves/deduplicates all
   registered references through Governance / Controlled Apply; and
3. a read-back and audit contract proving the postcondition before source
   deprecation.

No Odo row, including the human-confirmed pinned-dial pair, may use this
design as authorization to mutate data.

## 2. Existing boundaries observed in code

These are `CODE_OBSERVED` facts from the current worktree, not runtime facts:

| Boundary | Existing capability | Reusable artifact |
|---|---|---|
| Authority identity | canonical UUID, `(entity_type, stable_key)` lookup, optimistic revision, create/rename/update/retire/reactivate | `Application/Authority/AuthorityService.php`, `Contracts/Authority/AuthorityRepository.php`, `Infrastructure/Authority/WpdbAuthorityRepository.php` |
| Governance envelope | proposal subject/target, operation, payload fingerprint, expected revision, dependency fingerprint, idempotency key and lifecycle | `Domain/Governance/Proposal.php`, `Contracts/Governance/ProposalRepository.php`, `Application/Governance/ControlledApplyService.php` |
| Apply failure semantics | transaction-owned apply; failed attempt/audit is persisted separately after rollback | `Application/Governance/ControlledApplyService.php`, `Contracts/Shared/TransactionManager.php`, `Contracts/Governance/ApplyAttemptRepository.php`, `docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md` |
| Graph reads/writes | registered endpoint resolution; incoming/outgoing reads; create/retire/reactivate; active-triple dedupe; edge revisions | `Application/Graph/GraphService.php`, `Contracts/Graph/GraphRepository.php`, `Infrastructure/Graph/WpdbGraphRepository.php` |
| Graph registry | 15 endpoint types and current predicates are code-registered; invalid endpoint/predicate/type/cardinality fails closed | `Domain/Graph/EndpointTypeRegistry.php`, `Domain/Graph/PredicateRegistry.php`, `docs/architecture/11_GRAPH_CORE_CONTRACT.md` |
| Knowledge references | Evidence stores Claim UUID + Source UUID and supports read/update/lifecycle | `Domain/Knowledge/Evidence.php`, `Contracts/Knowledge/EvidenceRepository.php`, `Application/Knowledge/KnowledgeService.php` |
| Media references | MediaUsage stores endpoint type/key; Video stores optional thumbnail Media UUID; both have read boundaries | `Contracts/Media/MediaUsageRepository.php`, `Contracts/Video/VideoRepository.php`, `Domain/Video/Video.php` |
| Editorial boundary | existing Post reconciliation is read/fingerprint-first and WordPress remains editorial authority | `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `Application/Article/ArticleIngestPreflight.php`, `Application/Article/ArticleVerificationReader.php` |
| Audit | Authority, Graph and Governance audit interfaces already exist | `Contracts/Authority/AuthorityAuditSink.php`, `Contracts/Graph/AuditSink.php`, `Contracts/Governance/GovernanceAuditSink.php` |

The current `AuthorityRepository::update()` updates name/payload/state and
increments revision, but does not change stable key. The current executor and
MCP allowlist contain no rekey or merge branch. The current repositories also
do not expose a generic reference-move method. These are capability gaps, not
permission to bypass the boundaries.

## 3. Governed stable-key rekey

### 3.1 Command contract (proposed, not registered)

The future generic command must bind:

| Field | Requirement |
|---|---|
| `entity_type` | Existing registered Authority type only |
| `subject_uuid` | Canonical UUID whose identity is preserved exactly |
| `old_stable_key` | Exact key read from the locked current row; mismatch fails closed |
| `new_stable_key` | Canonical validated key; no forbidden namespace or unregistered scope |
| `expected_revision` | Positive revision read by the proposer; stale value is a typed conflict |
| `idempotency_key` | Durable Governance idempotency binding |
| `dependency_fingerprint` | Fingerprint of the identity and collision preflight inputs |
| `reason/provenance` | Human-reviewed reason and attributable audit context |

The exact public operation identifier is not yet approved. If the architecture
approves the proposed generic `rekey` identifier, it must be added to the
Governance allowlist and schema as a contract change; this document does not
add it.

### 3.2 Preflight and apply invariants

Preflight must, through repository/application interfaces:

1. resolve `subject_uuid` and assert its registered type;
2. assert the current stable key equals `old_stable_key`;
3. assert `new_stable_key` is valid and scoped to the same entity type;
4. check the target key for collision, including a concurrent target-key
   insert/update serialized by the apply transaction;
5. capture current revision, active/retired state, payload fingerprint and
   reference-set fingerprint; and
6. refuse ambiguous identity, missing endpoint, target collision or unavailable
   dependency with a reason-coded non-success result.

Apply must update the same Authority row with the same UUID and the new key,
increment exactly one Authority revision, preserve payload/lifecycle fields,
write a durable audit event containing old/new keys and the proposal binding,
and read the row back before commit. It must not create a second Authority
entity, alter a Graph predicate, create a public slug, or rewrite editorial
content.

### 3.3 Idempotency and retry

The proposal binding must include entity type, UUID, old key, new key, expected
revision and dependency fingerprint. A retry with the same idempotency key and
identical binding returns the existing successful receipt without a second
mutation. The same key with changed content is a typed idempotency conflict.

After an uncertain transport result, retry first performs the existing proposal
and read-back lookup. It must not submit altered content under the old key. A
stale revision requires a new preflight and a new human decision.

### 3.4 Failure and rollback semantics

The Authority row update, proposal state, apply attempt and success audit belong
to the existing Controlled Apply transaction boundary. A collision, revision
conflict, audit failure, read-back mismatch or commit failure rolls back the
semantic mutation and proposal transition. A separate failure transaction may
record the bounded failed attempt and `ApplyFailed` audit, following
`docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md`.

No partial “key changed but receipt missing” or “receipt succeeded but key not
readable” result is accepted. If key-history persistence is approved, it must
participate in the same transaction; otherwise the audit receipt is the only
durable historical record and this limitation must be explicit.

## 4. Governed semantic merge

### 4.1 Command contract (proposed, not registered)

The future generic merge command must bind:

| Field | Requirement |
|---|---|
| `entity_type` | Source and target are the same registered entity type |
| `source_uuid` | Identity to be deprecated only after verification |
| `target_uuid` | Canonical surviving identity; distinct from source |
| `source_revision` / `target_revision` | Optimistic locks for both identities |
| `source_fingerprint` / `target_fingerprint` | Payload and lifecycle snapshots used in approval |
| `reference_set_fingerprint` | Complete inbound/outbound and domain-reference snapshot |
| `idempotency_key` | Durable merge-plan binding |
| `reason/provenance` | Human decision, evidence and audit context |

The exact operation identifier is not approved. A future generic `merge`
identifier, if selected by Human Approval, must not be confused with an Odo
operation or with an ordinary `retire`.

### 4.2 Preflight reference closure

Preflight must enumerate every reference using registered application read
boundaries and produce a deterministic plan. The plan includes:

- inbound Graph edges `A -predicate-> source`, rewritten to
  `A -predicate-> target`;
- outbound Graph edges `source -predicate-> B`, rewritten to
  `target -predicate-> B`;
- source/target self-cycles and edges between source and target, handled as
  explicit conflict cases rather than silently created self-relations;
- duplicate active triples, where the existing target triple is retained and
  the source duplicate is retired through Graph governance;
- Source/Evidence/Knowledge references: direct Claim/Source/Evidence UUID
  fields are catalogued, and any endpoint references are moved only through a
  registered adapter; no raw JSON or unregistered field is rewritten;
- Media references: Graph `about`/`depicts` edges and `MediaUsage` endpoint
  contexts are separately enumerated; placement/context rows are not treated
  as semantic Graph truth;
- Video references: Graph attachments are enumerated separately from the
  typed `thumbnail_media_uuid` reference, which points to Media and must not
  be repurposed as an Authority merge link; and
- WordPress/Post references: `wp_post` Graph edges and Article reconciliation
  fingerprints are read-only dependencies. No Post body, title, slug, status,
  revision or permalink is rewritten by a merge.

The plan must list zero unresolved references before source deprecation. A
missing adapter, unavailable runtime, malformed reference, unsupported endpoint,
unknown predicate, cardinality conflict or ambiguous target blocks the merge.

### 4.3 Move/dedupe rules

All relation/reference movement must execute through Governance / Controlled
Apply. The future coordinator may call existing `GraphService` semantics, but
the current `GraphService` alone cannot rebind an existing edge from one node to
another; a reviewed generic reference-movement contract is therefore required.

For each planned Graph triple:

1. validate both canonical endpoints and predicate via the registries;
2. create the target triple only when the target triple is absent;
3. if the target triple already exists, verify predicate, direction, state and
   provenance compatibility and retain exactly one active triple;
4. retire the source triple only after the target equivalent is verified; and
5. preserve edge UUID/revision/audit semantics for retained rows and record
   source-to-target mapping for moved rows.

For each non-Graph reference, the owning application service/repository must
provide an atomic, idempotent move-or-dedupe operation. If it cannot join the
same transaction, the merge is ineligible. No direct SQL, payload string
replacement, endpoint-key guessing or broad `about` substitution is allowed.

### 4.4 Source deprecation and zero-reference gate

The source remains active and untouched until all planned moves are read back.
Only then may a governed lifecycle transition mark it retired/superseded, with
an immutable audit record linking source UUID, target UUID, plan fingerprint and
verification receipt. Hard-delete is forbidden before zero-reference
verification and is not part of namespace normalization.

The current Authority lifecycle has `ACTIVE`/`RETIRED`, not a generic
`SUPERSEDED` state or `superseded_by_uuid` field. The design therefore treats a
durable superseded marker as an explicit contract extension, not as an existing
fact. Until that extension is approved, a merge cannot claim a complete source
deprecation result.

## 5. Transaction boundary and partial failure

The minimum atomic unit is:

`lock proposal → lock source/target identities → lock/verify reference plan →
move/dedupe all participating references → verify zero source references →
transition source lifecycle → persist attempt/audit/read-back → commit`.

Every participating repository must use the same WordPress database connection
and transaction manager. A reference store that cannot participate makes the
proposal ineligible; there is no best-effort partial apply.

On deterministic failure, all semantic writes and the proposal transition roll
back. A separate transaction records the failed attempt, bounded error code,
plan fingerprint and audit event while leaving the approved proposal available
for a fresh eligibility check. On crash/timeout, the retry path reconciles the
proposal, attempt and post-commit read-back before deciding whether to retry;
it never blindly repeats a partially observed plan.

## 6. Retry/idempotency contract

The merge plan is immutable for one idempotency key. Its fingerprint covers:
source/target UUIDs and revisions, payload fingerprints, all reference IDs and
edge revisions, target collision decisions, operation version and provenance.

- Same key + same plan: return the prior receipt/read-back; no second mutation.
- Same key + changed plan: fail with idempotency conflict.
- New key + changed source/target revision: require new preflight and approval.
- Missing or unavailable read-back: return non-success and keep source active.
- A prior failed attempt: retry only after eligibility is re-evaluated.

## 7. Exact extensions proposed for human review

These are proposals, not current Registry facts and not implementation tasks in
this checkpoint.

### Governance / operation contract

1. Approve a generic Authority identity operation for stable-key rekeying,
   tentatively named `rekey`, with the command fields in §3.1.
2. Approve a generic same-type semantic merge operation, tentatively named
   `merge`, with the command fields in §4.1.
3. Extend the proposal schema/binding validator and executor dispatch only
   after approval; both operations must be capability-gated and unavailable to
   unapproved callers.
4. Extend apply receipts/attempt context with plan fingerprint, moved,
   deduplicated, blocked and read-back counts.

### Authority identity/lifecycle contract

1. Add a repository/application preflight and atomic rekey method that checks
   old-key equality, target collision, expected revision and UUID preservation.
2. Define whether historic stable keys are persisted in a generic identity
   history boundary. If yes, add its schema/repository/audit contract in the
   same transaction; do not add an Odo-specific field.
3. Define a generic superseded/deprecated representation and source→target
   linkage. Adding `SUPERSEDED` or `superseded_by_uuid` requires an explicit
   domain/schema amendment; the current `RETIRED` state is not silently
   reinterpreted.

### Reference and Registry contracts

1. Add a generic `ReferenceInventory`/plan contract with adapters for Graph,
   Knowledge Evidence, MediaUsage, Video typed references and WordPress Post
   reconciliation dependencies. It must report unknown/unavailable references
   as blockers.
2. Add a governed reference-movement/dedupe contract to Graph and each owning
   non-Graph repository that needs to move endpoint identity. This is a
   capability extension, not a new predicate.
3. Keep `EntityTypeRegistry`, `EndpointTypeRegistry` and `PredicateRegistry`
   as the authority. No new entity type, endpoint type, predicate, relation
   type, canonical field, public route or placeholder operation is proposed.
4. Define audit event schemas for rekey, merge planned, reference moved,
   reference deduplicated, verification completed, source superseded and
   apply failed.
5. Define a read-back contract that proves UUID/key/lifecycle/revision,
   reference closure, Graph triple uniqueness and no source references remain.

## 8. Acceptance tests required before implementation approval

The future implementation review must include tests for:

- UUID preservation and exactly-one revision increment on rekey;
- old-key mismatch, target collision, stale revision and malformed identity;
- same-key idempotent replay and changed-content conflict;
- same-type enforcement and source/target distinctness for merge;
- inbound/outbound Graph movement, duplicate-triple dedupe and cardinality
  conflict;
- Source/Evidence/Knowledge, Media/MediaUsage, Video and Post reference
  closure through their owning boundaries;
- zero-reference verification before source deprecation;
- atomic rollback when any participating adapter, audit or read-back fails;
- retry after failed/uncertain apply without duplicate mutation; and
- refusal of direct SQL, unregistered predicates/types/fields and unavailable
  runtime.

No implementation, migration, runtime inventory apply or Odo retirement is
authorized by this design review alone.
