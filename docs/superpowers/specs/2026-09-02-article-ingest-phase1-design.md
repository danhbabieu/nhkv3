# Article Ingest Phase 1 Design

**Status:** HUMAN ARCHITECTURAL APPROVAL received 2026-09-02.

## Goal

Deliver a reconcile-only Article Ingest boundary for an existing WordPress Post,
with Post 55 as the acceptance fixture. The operation may mutate semantic
records only through Governance and Controlled Apply; it must not mutate
editorial WordPress state in Phase 1.

## Constitutional boundary

WordPress native `wp_posts` remains the source of truth for editorial title,
body, excerpt, status, revisions and permalink. There is no Article Authority
entity, Article body projection, Article Graph endpoint or new semantic
operation. The existing `wp_post` endpoint uses `<blog_id>:<post_id>`.

Phase 1 accepts only `intent=reconcile`. `create`, editorial `update`, draft
creation and publish are explicit `UNSUPPORTED_OPERATION` outcomes. They must
not fall back to generic WordPress writes or `V2MigrationService`.

## Operation flow

```text
reserve receipt
  → validate/replay idempotency
  → read WordPress target and EditorialStateToken
  → semantic registry/precondition preflight
  → build deterministic governed proposal bundle
  → submit proposals / report approval pending
  → apply only eligible proposals through ControlledApplyService
  → read back semantic state and Graph relations
  → read back WordPress preservation state
  → persist outcome and return receipt
```

For reconcile, the editorial stages are preservation checks. The coordinator
must not transition a published Post to draft or publish it again.

## Components

The coordinator is a thin state orchestrator. It does not own semantic data.

- `ArticleIngestCoordinator`: dispatches Phase 1 intent, receipt state and
  bounded stage transitions.
- `ArticleIngestPreflight`: resolves `wp_post`, Authority types, Graph
  endpoints/predicates, semantic targets and Source/Evidence prerequisites.
- `EditorialStateReader`: reads a Post and calculates a deterministic opaque
  state token. Phase 1 uses it read-only.
- `SemanticProposalPlanner`: creates deterministic child Proposal commands and
  dependencies; it never calls semantic repositories directly.
- `ControlledApplyAdapter`: delegates each child to existing Governance,
  Eligibility and Controlled Apply services.
- `ArticleVerificationReader`: compares expected receipt bindings with semantic
  and WordPress read-back state.
- `ArticleOperationReceiptRepository`: durable orchestration/recovery state,
  separate from canonical Article, WordPress and semantic stores.
- `ArticleDiagnosticReader`: composes receipt, proposal, apply-attempt and
  failure state for authenticated diagnostics.

## Receipt contract

The receipt stores operation UUID, idempotency key, request fingerprint, intent,
WordPress endpoint/post reference, editorial state fingerprints, child proposal
IDs, dependency ordering, stage, outcome, retry state, failure details and
timestamps. It never stores the full Article body.

The receipt table must have a unique idempotency key and a request fingerprint.
The receipt transaction is independent from WordPress and from each semantic
Controlled Apply transaction; no distributed transaction is assumed.

## Idempotency

- Same key and same canonical request fingerprint replays the terminal receipt
  or resumes the retryable stage.
- Same key and a different fingerprint returns `IDEMPOTENCY_CONFLICT` without
  changing the old receipt.
- Child proposal keys are deterministic from parent operation identity and
  semantic slot identity.
- Existing Governance proposal idempotency, stable-key claim/source reuse and
  exact active Graph triple idempotency remain in force.
- Evidence creation is allowed only when exact duplicate protection is proven;
  otherwise preflight returns a non-success and creates no Evidence.

Phase 1 does not solve the WordPress create crash window. Since create is
unsupported, no WP Post is created by this operation.

## Input contract

```json
{
  "operation_id": "optional UUID",
  "idempotency_key": "required stable string",
  "intent": "reconcile",
  "target_wp_post": {
    "endpoint_type": "wp_post",
    "endpoint_key": "<runtime_blog_id>:55"
  },
  "expected_editorial_state": {
    "state_token": "optional prior opaque token"
  },
  "semantic_bundle": {
    "commands": [
      {
        "slot": "stable semantic slot",
        "operation": "create | ingest | update | retire | reactivate | relation_create | relation_retire | relation_reactivate",
        "entity_type": "registered type",
        "subject_id": "existing subject identifier",
        "target_uuid": "optional canonical UUID",
        "expected_revision": 1,
        "payload": {},
        "dependency_slots": []
      }
    ]
  }
}
```

`target_wp_post` is required for reconcile and must resolve to Post ID 55. The
semantic bundle is explicit. Each command has a unique `slot`; `dependency_slots`
are resolved to child proposal IDs before persistence. The remaining command
fields map to the existing Proposal model. Payloads reuse current Governance,
Knowledge, Source/Evidence and Graph fields: Authority/Knowledge/Source/Evidence
commands use their existing payload shapes, while `relation_create` uses
`source_type`, `source_key`, `predicate`, `target_type` and `target_key`. No
prose parsing or title/slug identity is allowed. Phase 1 rejects commands that
would create an Article entity, editorial Post, or unsupported semantic type.

Post 55 candidate stable keys are runtime-resolved only:

```text
nhk:brand:o-do
nhk:model:o-do.24
nhk:movement:o-do.24
nhk:variant:o-do.24.54
nhk:component:o-do.gong-block.111
```

UUIDs are not hard-coded. `111` is not treated as an alias or model, and no
canonical `thùng nan` entity is invented.

## Outcome contract

The application outcome vocabulary is:

`COMPLETED`, `SEMANTIC_PREFLIGHT_REJECTED`, `GOVERNANCE_PENDING`,
`GOVERNANCE_REJECTED`, `SEMANTIC_APPLY_FAILED`, `VERIFICATION_FAILED`,
`STALE_SEMANTIC_REVISION`, `IDEMPOTENCY_CONFLICT`,
`DEPENDENCY_UNAVAILABLE`, `RECONCILIATION_CONFLICT`, `UNSUPPORTED_OPERATION`.

Existing lower-level Governance, Graph, Knowledge and infrastructure error
codes are preserved in failure details. `EDITORIAL_DRAFT_FAILED`,
`PUBLISH_FAILED` and `STALE_WORDPRESS_STATE` are reserved for future editorial
write support and are not generated by Phase 1.

## Reconciliation safety

Before the semantic operation, the reader fingerprints Post ID, title, body,
excerpt, status, slug/permalink and revision state. After semantic work it
must verify that all those values are unchanged. A changed expected state,
unreadable Post, unresolved endpoint, unknown registry item, missing provenance,
duplicate-risk Evidence or semantic revision mismatch fails closed.

All Graph mutations use governed `relation_create`, `relation_retire` or
`relation_reactivate` proposals. The coordinator never calls
`PostKnowledgeLinkService` directly and never depends on `V2MigrationService`.

## Multi-proposal policy

Child proposals are ordered by explicit dependencies. A partial apply is
recorded in the receipt; already-applied children are not compensated or
deleted. A retry continues only missing children whose prerequisites and
revisions remain valid. Otherwise the operation returns a non-success outcome
and requires re-preflight or human reconciliation.

## Phase 1 semantic bundle atomicity

The semantic bundle is preflight-atomic but apply-non-atomic. Every command,
registry reference, dependency and provenance prerequisite must pass before
the first proposal is created. Controlled Apply then executes children as
independent governed transactions in dependency order. A later child failure
does not compensate an earlier applied child; the receipt records the partial
state and a retry continues only the missing children. A changed prerequisite
or revision fails closed and requires a new preflight or human reconciliation.

## MCP surface

Phase 1 exposes two coordinated logical abilities using the current `nhk.*`
MCP catalog naming convention:

- `nhk.article.preflight`: read-only Article preflight;
- `nhk.article.ingest`: execute/resume using the same idempotency key.

No low-level Article sequence is exposed as a completion shortcut. The execute
ability accepts only reconcile in Phase 1. Article-specific permission checks
must not bypass Proposal approval or Controlled Apply.

## Diagnostics

Authenticated diagnostics report WP target existence, preservation token,
preflight resolution, proposal IDs/states, approval and eligibility, apply
attempts, verification, outcome, last failure and retry eligibility. They do
not become an Article CMS or canonical Article store.

## Acceptance

Acceptance requires a guarded fixture/integration test for an existing published
Post 55 equivalent: no editorial mutation, no new Post, no duplicate semantic
records or edges, registry resolution, governed semantic writes, correct receipt
replay and fail-closed create/editorial-update requests. Production Post 55 is
not touched by tests or implementation.

## Explicit extensions

- `CONTRACT_EXTENSION_REQUIRED`: receipt persistence, editorial state token,
  coordinator ports, semantic bundle wrapper and evidence duplicate protection.
- `REGISTRY_EXTENSION_REQUIRED`: only if the existing MCP catalog naming and
  capability vocabulary cannot express the approved Article abilities/outcomes.
- No Article entity, endpoint, predicate, body field or generic Governance
  operation is added.
