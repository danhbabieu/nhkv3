# Governance Automation Policy — NHK V3

## Status

Approved design for implementation planning on 2026-09-07.

This document is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`
and the current domain contracts. It does not create a new semantic type,
operation, predicate, relation, writer, or publication authority.

## Goal and invariant

Provide an Admin configuration for how registered ingestible data types move
through the existing Governance lifecycle:

```text
ingest/create → proposal → submit → review → approve → eligibility
→ controlled apply → canonical read-back → projection/publication
```

The policy changes only whether the review stop is human or automated and,
when explicitly configured, whether the existing controlled apply/publication
steps continue. It never weakens validation, evidence, relation eligibility,
public identity, controlled apply, canonical read-back, or frontend
verification.

The governing rule is: **Human review is configurable; Governance gates are
not.**

## Scope and type resolution

The resolver accepts a canonical type and returns exactly one of:

- `REVIEW_REQUIRED`
- `AUTO_APPROVE`
- `AUTO_PUBLISH`

Type validity is resolved against executable registries and current workflow
contracts. The initial Admin presentation must support Video, Media, Article /
Content, and Knowledge where their canonical ingest boundary exists. Note is
rendered only if a canonical Note type is already registered and ingestible.
Other registered ingestible types may be rendered dynamically. Unknown or
non-ingestible types are rejected by the write path and never create policy
entries. Missing entries resolve to `REVIEW_REQUIRED`.

Article / Content remains subject to the native WordPress editorial owner and
the Article Ingest/publication contracts; the policy is not permission to
publish an editorial Post outside those gates.

## Components and boundaries

### Policy domain/application boundary

Add a typed mode value object or enum and a
`GovernanceAutomationPolicyResolver` (final naming follows repository
convention). The resolver depends on a registry/catalog type source and a
storage port. It is the only application decision point for the mode.

The resolver must expose deterministic default behavior, reject invalid modes,
and distinguish unknown types from a missing configured value. It must not
perform semantic mutation or inspect Admin request state.

### WordPress storage adapter

Use one existing WordPress option/config abstraction if one is found during
implementation. If no reusable settings abstraction exists, add one narrowly
scoped option adapter storing a type-to-mode map. It must sanitize to the
registered type/mode allowlists and preserve the default behavior for absent
keys. No migration is needed for an option that does not already exist.

If the existing settings layer provides versioning/audit, reuse it. Otherwise,
the policy save must emit the existing shared audit event through its available
port; it must not invent a second audit model merely for the UI.

### Shared governed orchestration

Refactor the existing `GovernedSemanticIngestOrchestrator` or the current
canonical orchestration seam so all supported MCP ingest flows can resolve the
policy at the application boundary. The orchestration must retain the existing
Governance service, eligibility service, controlled apply service, canonical
read-back verifier, projection/publication gate and idempotency behavior.

The pipeline returns a structured result rather than conflating a stopped or
blocked result with success:

```text
status, mode, proposal_id, proposal_state, gate_reached,
blockers, audit, canonical_readback, projection, frontend
```

`REVIEW_REQUIRED` creates/submits/reviews as currently required and stops with
the submitted proposal. `AUTO_APPROVE` performs automated review/approval,
checks eligibility, and stops before apply. `AUTO_PUBLISH` continues through
controlled apply, canonical read-back, projection, and frontend/publication
verification. A successful publication result is impossible unless every
required gate returns success.

The implementation must use the existing Governance lifecycle methods and
authorized application services. No direct repository/DB approval, direct
canonical write, duplicate writer, or UI-controlled workflow is allowed.

### Automated actor and audit

Use the existing actor field and audit sink. The current runtime has no
separate persisted actor model, so the implementation will represent the
automated actor with the existing non-human actor convention (`0`/system
actor) and add explicit `actor_kind=system` context to automated approval,
apply, and publication audit events. Human actions retain their current user
actor. If the audit sink cannot record the required event, the automated
action fails closed at the relevant gate and reports a blocker. It must not
impersonate a human or silently omit audit data.

### Admin adapter

Add the policy form beneath the existing NHK V3 → Hệ thống area (or the
repository's equivalent system settings destination). The UI is Vietnamese
first and contains only:

```text
Loại dữ liệu | Chế độ | Giải thích
```

Each supported row has the three human-readable modes and a Save action. The
AUTO_PUBLISH warning explicitly states that valid MCP data is automatically
approved, applied and published without manual action. The page shows honest
success/failure notices.

Read and write capability follows the current Admin convention; the initial
implementation uses the existing administrator/settings capability unless the
current capability registry provides a narrower approved capability. Save
requests require the existing nonce/CSRF mechanism, validate the complete
submitted map server-side, and reject arbitrary modes/types.

### MCP adapter

Wire one resolver/orchestrator instance through the existing `Plugin` service
composition so the common ingest boundary is shared by MCP and Admin. Do not
duplicate policy branches in individual MCP endpoints. MCP responses must
return the same truthful structured outcomes: submitted/manual review,
approved/ready-to-apply, published/frontend available, or blocked with the
specific gate and blocker.

Existing endpoint-specific contracts remain authoritative. A handler without a
valid controlled apply or publication/read-back boundary cannot claim
`AUTO_PUBLISH`; it returns a blocked/unavailable result at that boundary.

## Failure semantics

The first failing gate ends the run. Validation, evidence, relation/public
eligibility, public identity, apply, canonical read-back, projection, and
frontend failures must:

1. preserve the actual canonical/proposal state;
2. record an audit/blocker through the current boundary;
3. return a machine-readable blocker and human-readable explanation;
4. leave the item available to the existing Governance/Admin review path when
   human remediation is applicable;
5. avoid unbounded retry and false publication success.

`Apply PASS` is never equivalent to `Frontend PASS`. Private Source/Evidence
remains private and no automation policy changes visibility.

## Idempotency

Replay uses the existing proposal binding, canonical identity, idempotency key,
revision, and domain-level create-or-resolve behavior. A replay of the same
request must reuse the existing governed result/read-back and must not create a
duplicate canonical object, relation, Source, Claim, Evidence, Media, Video,
proposal, or public identity. A changed binding under an existing idempotency
key remains a conflict.

## Test strategy and acceptance

Tests are written RED before production implementation and cover:

- resolver defaults, independent per-type save/read, invalid mode, unknown
  type, and dynamic registry behavior;
- review-required stop before approval/apply;
- auto-approve approval/audit/readiness with no apply/publication;
- auto-publish full gate order, canonical read-back, projection and frontend
  status;
- validation/evidence/eligibility/apply/projection/read-back blockers;
- idempotent replay and binding conflict;
- Admin capability, nonce, rendering, save notices, and safe defaults;
- MCP response integration through the shared orchestration seam;
- existing Governance regression tests.

Verification includes focused Unit/Admin/MCP tests, full Unit, available
integration tests with exact database guards, PHP lint, JavaScript syntax,
Composer validation, `git diff --check`, and a secret review. Runtime Admin
acceptance may use only a safe governed fixture; shared/production data is not
mutated. If no safe fixture/runtime is available, runtime acceptance is
reported blocked rather than simulated.

## Documentation updates after implementation

After implementation and verification, update only the relevant current
contracts and execution ledger. The Constitution may receive a concise
invariant clarification that automation is not a Governance bypass. Update
`AGENTS.md`, `READ_FIRST.md`, or other architecture docs only if the new rule
is an agent/contract routing rule; do not duplicate detailed implementation
specification across normative documents.
