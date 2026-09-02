# V3 Autonomous Execution

> **NON-NORMATIVE.** This is an operating protocol, not architectural law. If
> it conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the
> Constitution controls.

This document is the operating protocol for future Codex sessions.

1. Read `AGENTS.md`, `V3_EXECUTION_STATE.md`, the relevant architecture
   contracts, and `V2_V3_PARITY_MATRIX.md` before changing code.
2. Inspect Git state and preserve pre-existing changes. Assess the current phase
   from actual code/tests.
3. Implement the smallest vertical slice that satisfies the locked contract;
   add unit, integration, migration, regression and concurrency coverage where
   the semantics require it.
4. Run the relevant quality gate, update the execution state, plan and ledgers,
   commit a logical checkpoint, and push only after the gate passes.
5. Continue to the next phase automatically while no stop condition applies.
6. Before actual V2 data migration, require backup/restore evidence and a
   dry-run. Before final production cutover, generate the readiness report and
request human approval.

## Persistent directive

This protocol also covers the NHK frontend and parity surfaces. Inspect the
current theme before fixing design tokens; build the design system and route
scaffolding as soon as domain contracts are stable enough, in parallel with
data work. The public header, homepage, archives, entity pages, Post pages,
search, media/video modules, mobile navigation, accessibility, performance,
SEO and empty/error states are first-class acceptance scope. V2 and Tinhte are
reference inputs only, never implementation sources.

Do not stop after closing Governance, Domain, Admin or Homepage. Continue until
the required logic, data and frontend parity matrices are reconciled, critical
route smoke tests pass, migration ledger entries are explained, and a Cutover
Readiness Report exists. Stop immediately before production cutover or other
listed human-gated operations.

## Required quality gate

Run dependency/autoload setup only when needed, PHP lint, relevant PHPUnit
 suites, migration tests for schema changes, prior-phase regression,
`git diff --check`, secret review and final `git status`. Main DB checks must be
non-destructive; destructive tests target only `nhk_v3_test`.

## Checkpoint record

Every accepted checkpoint updates `V3_EXECUTION_STATE.md`, `V3_MASTER_PLAN.md`
and any affected parity/migration records before commit.
