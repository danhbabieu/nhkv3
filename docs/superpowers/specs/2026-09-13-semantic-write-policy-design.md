# NHK V3 Semantic Write Policy / Project Build Mode

## Status and scope

This design adds a runtime semantic-write policy. It does not add an Authority
type, a second Governance owner, a generic writer, a data migration, a seed,
or a live environment enablement. It is code-side only until an operator sets
the deployment configuration and verifies runtime read-back.

The policy values are `READ_ONLY`, `PROJECT_BUILD`, and
`LOCKED_OPERATIONAL`. Configuration is resolved at runtime from
`NHK_SEMANTIC_WRITE_POLICY` (case-insensitive values `read_only`,
`project_build`, and `locked_operational`). Missing, malformed, or unsupported
configuration resolves to `READ_ONLY`. Runtime environment is resolved from
the deployment environment identity, not from `site_url`, `home_url`, or any
URL string. Production always resolves to a fail-closed decision for
`PROJECT_BUILD`, returning `PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION`.

## Policy boundary

Authentication and runtime identity resolution happen first. The semantic
write gate runs next, before any semantic plan, Proposal, repository writer,
or Controlled Apply call. It returns one of:

| Decision | Conditions |
|---|---|
| `SEMANTIC_WRITE_POLICY_READ_ONLY` | Policy is `READ_ONLY` and a semantic write/plan is requested |
| `PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION` | `PROJECT_BUILD` is configured while environment is production |
| `PROJECT_BUILD_CAPABILITY_REQUIRED` | `PROJECT_BUILD` is active in an allowed non-production environment but the actor lacks `nhk_project_build_semantic` |
| allow | `PROJECT_BUILD` is active, non-production and the actor has the capability; or `LOCKED_OPERATIONAL` reaches an existing operational Governance path |

`nhk_project_build_semantic` only authorizes entry into Project Build. It does
not imply proposal creation, approval, eligibility, Controlled Apply,
publication, upload, or internal lifecycle capabilities. Existing capability
checks remain in force.

Project Build opens only the canonical `nhk.capture.ingest` Authority path and
the already-governed lifecycle. Internal compatibility writers remain
internal-only and continue to return `DIRECT_WRITE_BLOCKED` without
`nhk_internal_content_operations`.

## Runtime identity

The same resolver is injected into MCP runtime identity output. `server/discover`,
`initialize`, and documentation bootstrap expose:

- `environment`;
- `semantic_write_policy`;
- `project_build_enabled` (true only for an allowed, active Project Build
  decision, not merely a config string);
- runtime/build identity;
- `documentation_version`;
- `manifest_hash`.

An existing sanctioned opaque database-binding identity may be exposed if the
runtime already has one. No secret, credential, token, or invented identity is
added.

## Authority and Clock Type

The policy gate is generic across all registered Authority types:
`brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen`, and `product`. No type-specific Project Build
allowlist is introduced.

Authority planning remains reuse-first: search canonical UUID/stable key/name/
alias, inspect bounded candidates, and return `REVIEW_REQUIRED` for ambiguity.
New stable keys remain server-owned. Existing `AuthorityIntentPlanner` and
`EntityTypeRegistry` remain the vocabulary authority.

Clock Type remains a Classification profile:

```text
name: Đồng hồ công cộng
entity_type: classification
family: clock_type
stable_key candidate: nhk:classification:clock-type.dong-ho-cong-cong
```

The exact `clock_type` family is accepted. Legacy `clock-type` is read-only
compatibility data and is rejected as a new write target. No separate Clock
Type create rule is added beyond generic Classification family validation.

## Governed data flow

Project Build Authority requests use:

```text
authentication/runtime identity
→ semantic write policy gate
→ documentation bootstrap/checkpoint
→ Search / Reuse
→ nhk.capture.ingest
→ Authority PLAN
→ owner confirmation
→ Proposal
→ existing approval policy
→ Eligibility
→ Controlled Apply
→ canonical read-back
```

The implementation does not call Authority repositories, Graph writers, SQL,
WordPress generic writers, or direct UUID/stable-key allocation from the MCP
operator path. Relations use only registered predicates and Governance.
Public Identity remains a separate governed step with collision checking; it
is never allocated during Authority Apply. Project Build does not trigger
legacy migration, normalization, PR7, or bulk Graph backfill.

`LOCKED_OPERATIONAL` is a config-only transition. It preserves existing UUIDs,
stable keys, revisions, Graph edges, Public Identity, and Knowledge records;
it only removes Project Build convenience behavior while preserving the
existing operational Governance checks.

## Audit

The implementation reuses the existing Governance audit sink and
`nhk_audit_events` owner. Project Build mutation lifecycle events include, when
available, actor, timestamp, Capture ID, Proposal ID, target UUID, entity type,
operation, plan fingerprint, approval mode, previous revision, resulting
revision, and canonical read-back result. No parallel Project Build audit table
is created.

## Safety and non-goals

- No runtime config is changed by this implementation.
- No staging, production, V2, or live MCP mutation is performed.
- `READ_ONLY` is the safe default and immediately blocks new semantic plans.
- Local unit configuration proves resolver behavior only; it is not live enablement.
- Existing Media/Image worktree changes are outside scope and must remain untouched.

## Acceptance matrix

| Runtime | Capability | Authority PLAN | Direct writer |
|---|---|---|---|
| `READ_ONLY` | any | block with `SEMANTIC_WRITE_POLICY_READ_ONLY` | block |
| `PROJECT_BUILD` allowed environment | absent | block with `PROJECT_BUILD_CAPABILITY_REQUIRED` | block |
| `PROJECT_BUILD` allowed environment | present | allow for every registered Authority type | block |
| `PROJECT_BUILD` production | present/absent | block with `PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION` | block |
| `LOCKED_OPERATIONAL` | existing operational caps | preserve existing Governance behavior | block unless internal boundary |

The test suite covers the requested 20 behavior groups, with focused matrix
tests for Brand, Model, Clock Type, missing capability, production, direct
writer, duplicate/ambiguity, read-back, audit, and policy switching, plus the
existing Authority/Capture/Governance/Clock Type/Media/Video/Knowledge
regressions.
