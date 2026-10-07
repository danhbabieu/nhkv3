# Governed Operation Policy Consolidation

## Status

Approved design for implementation on 2026-10-07. Scope is code, tests and
execution documentation only. No semantic data mutation, deployment, apply,
incident recovery or change to Côn hoa thị is included.

## Problem and intent

Governance currently has several generic operation decisions: the Controlled
Apply compatibility registry, staging descriptor family/revision logic,
Capture scope issuance, Capture staging admissions, eligibility checks and
production admission. These decisions can drift even when the executor and
registry support the same operation. The known example is lifecycle
reactivation, but the repair must cover the complete supported vocabulary.

The goal is one operation law with many owner semantic gates:

- executor compatibility is not staging authorization;
- staging authorization is explicit policy, including explicit denial;
- production does not consume staging packets;
- owner-specific validation remains in the owner admission/service;
- adding an operation requires one policy entry plus owner-specific rules,
  rather than edits to disconnected generic lists.

## Design

Introduce a read-only `GovernedOperationPolicyRegistry` (name may follow the
repository's final naming convention) as the canonical source for generic
metadata. Each registered `(entity_type, operation)` entry declares:

- operation family;
- lifecycle class (`CREATE`, `MUTATE_EXISTING`, `RETIRE`, `REACTIVATE`,
  `RELATION_MUTATION`, or `SPECIAL`);
- revision policy (`ZERO`, `CURRENT_REQUIRED`, or `NONE`);
- target binding;
- explicit `capture_staging_allowed` and `production_allowed` decisions;
- generic required capabilities/profile metadata.

The registry must distinguish an unknown owner/operation from an explicitly
registered operation whose staging route is denied. It must not become a
permissive global matcher or replace owner semantic validation.

`ControlledApplyOperationRegistry` and the executor continue to provide the
compatibility boundary, but their supported combinations must be represented
by explicit policy entries. If compatibility is kept as a separate interface,
the canonical policy registry is the implementation behind it rather than a
second operation vocabulary.

## Boundaries and data flow

1. Executor dispatch and compatibility query the canonical policy for
   registered support.
2. `StagingOperationDescriptor` derives family and generic expected revision
   from the policy; special relation/media operations use their explicit
   `NONE` or contract-specific rule.
3. `StagingAcceptanceScopeVerifier` issues only policies marked staging
   allowed. It verifies the same family, revision and binding metadata.
4. Generic Capture dependency/child-relation and other staging admissions
   query the policy for support and staging permission, then apply their
   existing owner-specific predicates, endpoint, identity, dependency and
   readiness checks.
5. `ProposalEligibilityService` consumes the policy for generic registry and
   revision checks, while retaining semantic dependency, target, relation,
   evidence, Media and Authority gates.
6. `ProductionGovernanceAdmission` consumes only the production decision and
   generic capability metadata. It never accepts or issues a staging packet.

Video identity/source checks, Media binding eligibility, relation endpoint and
predicate validation, Authority semantic rules, and other owner-specific
preconditions remain outside the generic registry.

## Matrix and explicit outcomes

Before implementation changes, generate a repository-local current operation
matrix covering Authority, Knowledge, Source, Evidence, Video, Media,
MediaUsage, Relation/Graph and `wp_post`. Every operation must record executor
support, registry support, family, lifecycle class, revision policy, staging
scope issuance, staging admission, production admission, capabilities and
owner preconditions. The matrix is evidence for the refactor and is updated
with the execution-state checkpoint; it must not authorize data mutation.

Every executor-supported operation receives an explicit policy outcome. In
particular, Knowledge/Source/Evidence reactivation must be represented, while
Video operations without a valid Capture owner flow remain explicitly
staging-denied rather than being opened for symmetry.

## Invariants and tests

Add executable contract tests over the complete registry:

- staging-allowed entries are supported, family-defined, scope-issuable,
  scope-verifiable, understood by admission and executor-supported;
- issuance and verification expose identical family and revision policy;
- staging-denied entries cannot receive a staging scope or route;
- unknown operations fail closed at every layer;
- the historical Knowledge reactivation omission is a regression test;
- owner matrices cover all requested lifecycle and special operations;
- production authorization remains unchanged unless a concrete contradiction
  is proven and documented.

Tests use in-memory/unit boundaries and read-only incident inspection only.
They must not apply proposals, mutate staging records or recover incident
records.

## Documentation and delivery

Update `docs/architecture/V3_EXECUTION_STATE.md` with the matrix, every drift
finding, policy model, owner-specific exceptions, verification evidence,
production-behavior result, no-mutation result, commit and deployed-revision
status. Deployment is not performed by this task; the final report records
`DEPLOYED_REVISION` as not deployed unless independently verified.

The existing incident Proposal
`01a114af-f861-7837-9441-1effb25699f7` is inspected read-only after the change
for approved state, `knowledge:reactivate`, eligibility and bound target
revision. No apply or recovery is permitted.
