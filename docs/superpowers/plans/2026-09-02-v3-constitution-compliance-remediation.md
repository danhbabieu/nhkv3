# NHK V3 Constitution Compliance Remediation Plan

> **REQUIRED:** Read `docs/constitution/NHK_V3_CONSTITUTION.md` first.
> This plan is **NON-NORMATIVE**; the Constitution is the sole normative
> source.

**Goal:** Resolve the documented Constitution conflicts and prove the
high-risk public and semantic invariants without mutating existing semantic
data.

**Architecture:** WordPress owns editorial truth; Authority owns canonical
entities; Knowledge owns atomic claims; Source/Evidence owns provenance;
Graph owns relations; Governance owns semantic mutation; public services own
projection. Structure and relationships precede any data repair.

**Tech Stack:** PHP, WordPress, NHK Core, Symfony UID, PHPUnit, MySQL and the
existing Composer/preflight tooling.

**Spec:** The audit and this plan cite only the Constitution. The Article
Ingest and MCP documents are implementation contracts and evidence, not law.

## Global constraints

- Documentation and tests may be added, but no task below may import legacy
  article bodies, seed entities, backfill Graph edges, assign public slugs,
  merge identities or mutate V2, production or staging data.
- `nhk_v3` permits only safe inspection and UP migrations; destructive
  integration work requires exact `nhk_v3_test` and `TestDatabaseGuard`.
- Every semantic create/update/delete/merge/relation write must be governed by
  proposal, approval, eligibility, Controlled Apply and durable audit.
- Normal WordPress editorial publication remains editorial and is not routed
  through semantic Governance.
- The concurrent MCP plan and Article Ingest implementation are user-owned
  changes. Reconcile them explicitly before staging; never overwrite them.
- Each task is a commit boundary. Run focused tests, PHP lint, migration
  checks where applicable, `git diff --check` and a secret review before
  committing.

## Dependency order

```text
Phase 0 P0 integrity
    ├── Phase 1 durable public identity
    ├── Phase 2 Graph-canonical structural context
    └── Phase 3 eligibility / route convergence
             └── Phase 4 Article Ingest runtime contract
                      └── Phase 5 lower-priority projections
                               └── Phase 6 governed data-repair preparation
```

## Phase 0 — P0 integrity fixes

### Task 0.1 — Make Graph-derived readers fail loudly

**Constitution basis:** §3, §5, §16 and §26 acceptance invariants on single
Graph truth and distinguishable infrastructure failure.

**Current owners:**
`public/wp-content/plugins/nhk-core/src/Application/Query/RelatedContentQuery.php`,
`BrandAggregationQuery.php`, `StructuralContextQuery.php`, their existing unit
tests and Graph integration tests.

1. Write failing PHPUnit tests that make the Graph repository throw a
   `RuntimeException` and assert the query returns an explicit infrastructure
   failure/result, never an empty successful group. Add a separate empty-Graph
   test that remains an honest empty result.
2. Remove only the broad catches that collapse infrastructure/programming
   failure. Preserve narrow row-hydration handling where the contract says a
   malformed row can be skipped and reported.
3. Define the smallest existing application error/result boundary needed by the
   callers; do not add a new semantic type or endpoint.
4. Run the focused unit suite, a guarded Graph repository integration test on
   exact `nhk_v3_test`, PHP lint and preflight failure-path checks.
5. Commit only the reader/error contract and its tests.

**Expected red failure:** a repository exception is currently converted to an
empty result. **Passing proof:** exception and empty-data cases are observably
different in application and public callers.

### Task 0.2 — Close semantic write bypasses

**Constitution basis:** §3, §12 and §26 Governance invariants.

**Current owners:**
`PostKnowledgeLinkService.php`, `V2MigrationService.php`,
`ControlledApplyService.php`, `AuthorityProposalExecutor.php` and existing
Governance tests.

1. Write failing tests proving a direct Post→Knowledge relation attempt cannot
   write Graph outside the governed executor, while a valid proposal/apply path
   still writes exactly once and records durable audit.
2. Replace or retire the direct application mutation boundary so callers must
   submit a governed operation. Do not route normal editorial Post saves
   through semantic Governance.
3. Mark `V2MigrationService` legacy-body and direct semantic writers as
   explicitly unavailable to current Article Ingest and current Constitution
   scope. Do not execute or broaden the migration.
4. Verify proposal lock, approval, eligibility, idempotency, audit and rollback
   tests, then run PHP lint and guarded integration tests.
5. Commit the boundary closure separately from any later migration work.

**Expected red failure:** a direct service call reaches `GraphService`. **Passing
proof:** only the governed executor reaches semantic mutation and unauthorized
calls fail closed.

### Task 0.3 — Resolve Product/Specimen ownership before implementation

**Constitution basis:** §8 and §26 identity/lifecycle invariants.

**Current owners:** `Domain/Authority/Model/Product.php`,
`Specimen.php`, canonical payload catalog, Graph predicate registry and
`P6PersistenceTest`.

1. Write a decision test matrix, initially failing, for one physical Specimen
   with multiple historical Product listings; Product without Specimen;
   Specimen without Product; condition; sale state; price; inventory;
   serial; technical observation; media; provenance; listing title/body.
2. Review the matrix at the human architecture gate. Do not choose ownership
   by copying V2, fixtures or current payload fields.
3. After the decision, encode only the approved boundary in domain contracts,
   registry cardinality and Governance operations. Do not migrate existing rows
   in this phase.
4. Run unit and exact guarded integration tests; commit the decision-contract
   change independently.

**Stop condition:** no implementation or data operation proceeds while the
physical identity versus commercial listing decision remains ambiguous.

**Decision resolved — 2026-09-02:** Human approval fixes Specimen as the
canonical identity of one physical object and Product as the canonical identity
of one commercial listing/offer/context. Cardinality is Specimen `0..N`
Products over time and Product `0..1` Specimen. Specific-object Product without
exactly one Specimen is incomplete/blocked; generic/pre-specimen Product may
remain unlinked only where the current contract permits it. Product owns
commerce fields, Specimen owns physical observations/provenance/condition, and
commercial copy is not Knowledge. The implementation adds the negative
ownership/completeness tests and removes the unapproved `specimen_uuid` Product
payload field. No relation predicate or persistence field is added.

**Remaining follow-up:** Product–Specimen persistence is an explicit
`REGISTRY_GAP`/`CODE_GAP`. A later task must first specify semantics, endpoints,
direction, cardinality, provenance, Governance and direct/derived behavior;
that task must not include implicit backfill or inferred identity repair.

For the Phase 0 gate, the former Product/Specimen `CONSTITUTION_CONFLICT` is
resolved: the runtime now fails closed on the unapproved payload linkage and
keeps the relationship gap explicit. The safe relationship gap does not
authorize Phase 1 or any data repair; Phase 1 remains the next single phase
only after this report and its remaining runtime gates are reviewed.

## Phase 0 execution record — 2026-09-02

The Phase 0 implementation slice is **PARTIAL** and stops at the required
Product/Specimen human gate.

- Task 0.1 is implemented: Graph-derived readers fail loudly on
  infrastructure/programming failure and retain honest empty results.
- Task 0.2 is implemented: Post→Knowledge uses a governed Draft proposal
  request, direct mutation fails closed, and the historical V2 mutation entry
  point is retired. No migration or semantic apply was executed.
- Task 0.3 decision is resolved: the constitutional Product/Specimen boundary,
  cardinality, semantic completeness and lifecycle ownership are approved and
  covered by focused tests. The dedicated relationship persistence contract
  remains a later `REGISTRY_GAP`/`CODE_GAP`; no semantic type, relation, row or
  inferred payload link was added.
- The structural ownership portion of the approved Phase 0 slice is
  implemented as a read-only Graph-canonical context with explicitly labelled
  compatibility fallback and fail-closed conflict handling.
- The current public projection portion is implemented for Authority, Media,
  Knowledge, Evidence and related REST projections: internal UUID/stable-key
  fields and UUID relationship payloads are omitted. Durable public identity
  storage/history remains a later P1 boundary.

Evidence: isolated unit suite `217` tests / `1163` assertions, Composer PHP
lint and `git diff --check` pass. Database-backed integration, live preflight
and HTTP evidence remain unavailable. The concurrent Article Ingest files and
untracked MCP plan were not included.

## Phase 1 — Durable public identity foundations

### Task 1.1 — Persist public identity and historic slug lifecycle

**Constitution basis:** §9, §10, §11 and §26 public identity invariants.

**Current owners:** `PublicIdentityContract.php`, `PublicRouteResolver.php`,
`PublicEntityCollectionQuery.php`, the Authority repository layer and the
existing UP-migration/guard conventions. Discover exact repository names with:
`rg -n "PublicIdentity|historic|slug|stable_key" public/wp-content/plugins/nhk-core`.

1. Write failing tests for display rename preserving public slug; governed
   explicit slug change creating a historic redirect; collision refusal;
   missing/ineligible identity; and stable key/UUID exclusion from public
   serializers.
2. Add the smallest durable identity and historic-slug storage contract
   required by the Constitution, with revision/idempotency and Governance
   ownership. A schema migration is allowed only after the contract and
   `nhk_v3_test` migration test are reviewed.
3. Change route and identity readers to read persisted identity, never derive a
   public slug from display name or payload parent UUID.
4. Verify unit, guarded migration, repository and public projection tests;
   inspect SQL/schema diff and run `git diff --check`.
5. Commit identity storage and readers as one reversible boundary.

### Task 1.2 — Remove internal identity leakage

**Constitution basis:** §9–§11.

**Current owners:** public collection/detail serializers, `EntityPageQuery`,
REST/MCP public handlers, card/breadcrumb/SEO projections.

1. Add failing response-contract tests asserting public objects contain only
   approved public identity and editorial fields, never UUID or stable key.
2. Remove those fields from public serializers while retaining internal keys
   only at private application boundaries that need them.
3. Add route, redirect and canonical metadata assertions for no UUID/stable-key
   leakage.
4. Run all public projection tests and a static response-key review; commit
   separately from storage migration.

## Phase 2 — Structural compatibility and diagnostics

### Task 2.1 — Make Graph the structural authority

**Constitution basis:** §5, §10 and §26.

**Current owners:** `StructuralContextQuery.php`, `PublicRouteResolver.php`,
`PublicEntityEligibilityPolicy.php`, `BrandAggregationQuery.php`.

1. Write failing tests for Graph parent versus payload parent mismatch,
   missing parent, inactive parent, ambiguous compatibility candidate and
   unique compatibility candidate. Each case must identify its reason without
   writing data.
2. Route all canonical structural reads through Graph. Keep payload fields only
   as explicitly labelled transitional compatibility input; a conflict must
   fail closed rather than become canonical truth.
3. Add a read-only diagnostic result with the required classifications:
   `SAFE_UNIQUE_COMPATIBILITY_PARENT`, `MISSING_PARENT`,
   `AMBIGUOUS_PARENT`, `CONFLICTING_PARENT`, `PARENT_ENTITY_MISSING`,
   `PARENT_INACTIVE`, `MALFORMED_REFERENCE`, `GRAPH_ALREADY_PRESENT` and
   `GRAPH_CONFLICT`.
4. Recalculate current candidates against read-only runtime when available.
   Do not create `model_of`/`variant_of` edges, update payloads or invoke
   Controlled Apply.
5. Run unit and guarded integration diagnostics, then commit code/tests only.

### Task 2.2 — Prove predicate scope and no shortcuts

**Constitution basis:** §5–§7.

1. Add failing tests for every eight currently registered predicates: endpoint,
   cardinality, scope, unknown predicate and invalid endpoint.
2. Add explicit negative tests that no Variant→Brand, Movement→Brand,
   Music→Brand, Component→Brand, Classification→Brand, Media→Brand or
   Video→Brand shortcut is emitted unless a declared valid path exists.
3. Add movement/music tests distinguishing `supports_music`,
   `configured_with_music` and `observed_playing_music`; reject inference from
   rod/gong/hammer count, case style, Brand or visual similarity alone.
4. Run Graph unit/integration tests and commit validators/readers separately
   from any data diagnostics.

## Phase 3 — Eligibility and public read convergence

### Task 3.1 — One eligibility policy for all public surfaces

**Constitution basis:** §10–§11 and §26.

**Current owners:** `PublicEntityEligibilityPolicy.php`,
`PublicEntityCollectionQuery.php`, `EntityPageQuery.php`, Home/Search queries,
REST handlers and MCP public reads.

1. Write a cross-surface matrix test for homepage, hub, detail, search, REST,
   cards, breadcrumbs, SEO, sitemap and preview. For each eligible/ineligible
   entity, assert the same reason and projection decision.
2. Remove detail fallback paths that bypass the policy; make unavailable
   runtime/error distinct from ineligible/empty.
3. Verify route, identity and eligibility are called in one predictable order.
4. Run unit, guarded integration and HTTP route smoke checks when runtime is
   available; commit the convergence boundary.

### Task 3.2 — Verify route, menu and SEO parity

**Constitution basis:** §10–§11 and frontend law in §20–§25.

1. Add failing HTTP/route tests for the Vietnamese canonical roots, hierarchy,
   legacy compatibility redirects, reserved-root collisions, canonical URLs,
   OpenGraph URLs, breadcrumbs, pagination, search links, sitemap and historic
   slug redirects.
2. Verify whether menu content comes from a stored WordPress menu or theme
   fallback; ensure canonical navigation has no legacy technical routes.
3. Run the live matrix only against the configured local runtime and record
   unavailable runtime as failure, never as pass. Commit route/SEO tests and
   narrowly scoped projection fixes.

## Phase 4 — Article Ingest runtime contracts

### Task 4.1 — Reconcile concurrent receipt implementation

**Constitution basis:** §3, §12, §15 and §26.

Read the concurrent Article files and untracked MCP plan before staging. Compare
their interfaces and migration assumptions with
`docs/architecture/ARTICLE_INGEST_CONTRACT.md`. Preserve all unrelated user
changes. The reconciliation output must explicitly cover cross-boundary
idempotency, WordPress revision binding, semantic proposal identity and final
outcome states. Commit only after tests and migration guards agree.

### Task 4.2 — Implement reconcile-only coordination

**Current owners:** Article receipt/coordinator files present in the concurrent
worktree, plus the Article contract and focused tests.

1. Write failing tests for a read-only reconcile operation that distinguishes
   missing Post, duplicate idempotency key, revision mismatch, semantic
   preflight failure, read-back mismatch and valid reconciliation.
2. Define the smallest interfaces at current owners:
   `ArticleIngestCoordinator::reconcile(array $input): ArticleOperationReceipt`,
   `ArticleEditorialGateway` for reading post/revision state,
   `ArticleSemanticPreflight` with no mutation, and
   `ArticleReadBackVerifier`.
3. Implement reconcile-only behavior. It must not create/update/publish a Post,
   create an Article entity, turn Post body into Knowledge, call
   `V2MigrationService` or call direct `PostKnowledgeLinkService` Graph writes.
4. Run focused unit tests, PHP lint and guarded integration tests; commit only
   the coordinator contract and its proof.

### Task 4.3 — Controlled write/publish only after CAS approval

**Constitution basis:** §3, §12, §15 and §26.

1. Obtain the separately governed approval for WordPress write idempotency,
   optimistic revision/CAS binding and publish refusal rules.
2. Write failing tests for draft-before-semantic-apply ordering, race/revision
   mismatch, retry idempotency, semantic apply rollback, read-back mismatch and
   publish refusal.
3. Implement the smallest adapters that execute the approved sequence:
   semantic preflight → WordPress draft → governed semantic apply → read-back
   → WordPress publish.
4. Run guarded integration tests only on exact `nhk_v3_test`; never test against
   production/staging or import legacy bodies. Commit the write boundary only
   after all failure states remain durable and distinguishable.

## Phase 5 — Lower-priority semantic/public completeness

### Task 5.1 — Source/Evidence support and public visibility proof

Add failing tests separating source existence from evidence support for a claim
or relation, then implement only the missing query/serializer boundary. Verify
public evidence-chain visibility and private/ineligible behavior. No generic
source attachment may prove unrelated claims.

### Task 5.2 — Media publication and native WordPress image boundary

Add tests for Media/MediaAsset/MediaUsage identity, binary/derivative
lifecycle, reuse, checksum behavior and WordPress featured/content image
projection. Reject checksum-based semantic auto-merge. Verify public delivery
readiness and MIME/storage checks.

### Task 5.3 — Video and frontend runtime evidence

Add external-reference normalization and no-default-download tests for Video.
Run the Vietnamese-first responsive, empty/error, route and SEO smoke matrix
against local runtime. Treat styling improvements as P2 unless they create a
semantic public failure.

Album remains out of scope: it is not a registered or constitutional semantic
type. If a future requirement needs grouping, first specify whether it is
MediaUsage or WordPress editorial grouping and obtain a separate contract.

## Phase 6 — Governed data-repair preparation

This phase is evidence-only until every P0 prerequisite is complete.

1. Run read-only structural and Graph distribution diagnostics with predicate,
   endpoint, cardinality and dangling-reference labels.
2. Produce candidate evidence packages for Model/Variant parent classes,
   including identifiers, classification, provenance and conflict reason.
3. Validate candidates against current identity, eligibility, revision and
   provenance contracts; do not infer from target counts alone.
4. Define a proposal contract and human approval gate for any later repair.
5. Stop. This plan does not authorize Graph backfill, payload repair,
   Controlled Apply, seeding or identity merges.

## Verification and commit gates

Every phase must leave a fresh record of `git status -sb`, intended diff names,
test commands/results, PHP lint, migration checks where relevant, `git
diff --check`, secret scan and runtime availability. A failure must remain a
failure in the result contract. Before any implementation commit, re-read the
Constitution and confirm each changed interface cites a specific constitutional
law. The final production cutover remains a human-gated operation and requires
a separate Cutover Readiness Report.
