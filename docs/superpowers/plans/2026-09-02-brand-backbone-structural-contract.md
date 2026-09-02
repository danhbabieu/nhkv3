# Brand Backbone Structural Contract Implementation Plan

> **NON-NORMATIVE.** This plan is subordinate implementation guidance. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Establish Graph-owned `model_of` and `variant_of` structural contracts and derived Brand context without mutating semantic data in the contract checkpoint.

**Architecture:** Add only typed child→parent predicates to the existing Graph registry, then move structural reads behind a Graph-backed context boundary. Keep reverse navigation derived, and keep physical edge repair as a separately governed operation.

**Tech Stack:** PHP 8+, WordPress plugin runtime, PHPUnit, existing Authority/Graph registries and guarded `nhk_v3_test` integration harness.

**Spec:** `docs/architecture/BRAND_BACKBONE_STRUCTURAL_DESIGN_SPEC_2026-09-02.md`

## Global Constraints

- Persist only `Model → Brand` `model_of` and `Variant → Model` `variant_of`; do not persist reverse or Variant→Brand shortcuts.
- Do not invent entity types, predicates, fields, relation tables, or article-body migration paths outside the approved registry/contract.
- Do not repair or populate physical semantic edges in the contract checkpoint.
- A Model and Variant without their required active direct parent are structurally incomplete and must fail closed for public completeness.
- Keep `nhk_v3` non-destructive; destructive integration operations target only `nhk_v3_test` with the existing guard.
- Run focused tests, relevant PHPUnit, PHP lint, `git diff --check`, and secret review at each checkpoint.

---

## Scope

Implement the approved `model_of`/`variant_of` contract and Graph-backed reads
in separate milestones. This plan does not authorize physical edge repair,
bulk relation population, article-body migration, V2/live mutation, or cutover.

## File map

- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php`
  — register the two approved predicate definitions in the contract milestone.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
  and its query seam — stop treating Authority payload parent fields as final
  structural truth once the Graph read boundary is available.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php`
  and focused structural query tests — prove endpoint, cardinality, traversal,
  orphan, retirement and no-shortcut behavior.
- Update: `docs/architecture/V3_EXECUTION_STATE.md` — record each accepted
  checkpoint and keep registry/code/data gaps distinct.

## Milestone 1 — Contract tests first

- [ ] Add Graph unit tests for exact endpoints, `ONE/MANY` cardinality, invalid
   endpoint rejection, self-relation rejection and absence of reverse predicates.
- [ ] Add structural-read tests for direct parents, two-hop Brand derivation,
   orphan/incomplete state and retired-edge behavior.
- [ ] Run focused tests and confirm they fail against the current registry/read path.

## Milestone 2 — Registry contract

- [ ] Update `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php`
   with only the two approved definitions.
- [ ] Reuse `PredicateDefinition` and `GraphService`; add no special storage path.
- [ ] Run registry/Graph tests, PHP lint and the focused suite.

## Milestone 3 — Graph-backed structural query boundary

- [ ] Identify the Authority/Graph seam used by
   `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
   and related entity queries.
- [ ] Add the smallest registry-backed structural context reader with explicit
   direct-parent and two-hop results.
- [ ] Make missing/retired/ambiguous parents unavailable through existing
   eligibility machinery. If no reason exists, record `CODE_GAP` rather than
   inventing a field.
- [ ] Prove payload-only parent fields cannot masquerade as complete Graph structure.

## Milestone 4 — Compatibility conflict resolution

- [ ] Separately decide whether `brand_uuid`/`model_uuid` are transitional input or
   retired under an approved additive contract.
- [ ] Remove duplicate structural writes and make Graph authoritative for routes/reads.
- [ ] Document any versioned compatibility migration; do not perform data repair here.

## Milestone 5 — Governed data-operation design

- [ ] Build a case-level evidence matrix from exact UUID/stable-key, provenance and revision.
- [ ] Classify candidates as deterministic, evidence-resolvable, ambiguous, deferred or retire-only.
- [ ] Add dry-run/governance operations only after contract acceptance; require a
   separately approved data gate before creating any physical backbone edge.

## Verification

- [ ] At each milestone run relevant PHPUnit, PHP lint, migration checks when needed,
`git diff --check`, and secret review. Update `V3_EXECUTION_STATE.md` after each
accepted checkpoint. Keep `nhk_v3` non-destructive and use only guarded
`nhk_v3_test` for destructive tests. Do not claim parity before the parity matrix
reflects evidence.

## Completion definition

The contract milestone completes only when BB-01 through BB-16 and BB-20 pass,
the payload/route conflict is resolved or explicitly blocked as documented
`CODE_GAP`/`CONSTITUTION_CONFLICT`, and zero physical semantic edges were created.
Data compatibility remains a separate gate.
