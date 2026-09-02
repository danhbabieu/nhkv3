# NHK V3 Autonomous Engineering Rules

## Constitution — mandatory first read

Before any NHK V3 architectural or implementation work, every Codex session MUST
read directly:

docs/constitution/NHK_V3_CONSTITUTION.md

This is the only normative Constitution. Specs, plans, audits, execution state,
parity matrices, READMEs and historical V2 material are subordinate evidence or
implementation guidance. If any source conflicts with the Constitution, mark it
CONSTITUTION_CONFLICT; do not weaken the Constitution to legalize code.

## Scope and workspace

- Official workspace: /Users/imac24-2125d/Developer/nhk-v3
- Repository: https://github.com/danhbabieu/nhkv3.git
- Default branch: main
- This repository is the only implementation workspace for NHK V3.

## Non-negotiable architecture

- Structure first. Relationships first. Data later.
- WordPress native wp_posts is the sole source of truth for editorial title,
  body, author, dates, categories, archives, homepage, search, RSS, sitemap and
  editorial URLs.
- Authority owns canonical semantic entities; Knowledge owns atomic claims;
  Source/Evidence owns provenance/support; Graph is the single relation system;
  Governance owns durable semantic mutations; Media, MediaAsset, MediaUsage and
  Video retain distinct boundaries.
- Specimen means one concrete physical object. Product means a listing/offer and
  is never the physical object's identity.
- Runtime registries/contracts are executable boundaries. Never invent an entity
  type, endpoint type, predicate, relation type, canonical field, attribute,
  operation or knowledge profile from fixtures, UI, V2 or legacy structures.
- Preserve canonical UUID/stable-key, optimistic revision, typed relation,
  provenance, readiness, idempotency, public identity and fail-closed
  invariants.

## Data and database safety

- Do not migrate, import, parse or populate legacy article bodies under the
  current Constitution scope.
- Do not mutate V2, production or staging data.
- Development database is nhk_v3; integration database is nhk_v3_test.
- nhk_v3 permits health, smoke checks, schema inspection, non-destructive
  additions and UP migrations only. Never run DOWN, DROP, TRUNCATE or reset
  there.
- Destructive integration operations are allowed only on exact nhk_v3_test,
  guarded by TestDatabaseGuard.
- Never seed entities, backfill Graph edges, repair identity, assign public
  slugs or alter semantic records unless the separately governed contract and
  user-authorized task explicitly allow it.

## Workflow and quality gates

- Inspect git status before work and preserve existing changes. Never use
  git reset --hard, git clean -fd, git checkout -- ., or destructive restore.
- Read docs/architecture/V3_EXECUTION_STATE.md before each checkpoint and update
  it after the checkpoint. Read docs/architecture/V2_V3_PARITY_MATRIX.md before
  claiming parity.
- Use the smallest constitution-compliant vertical slice. Add relevant tests,
  run PHP lint, migration checks for schema changes, git diff --check and a
  secret review before a checkpoint commit.
- Do not downgrade tests or hide failures with broad catches that report
  success. Empty data, unavailable runtime, hydration loss and infrastructure
  failure must remain distinguishable.
- Do not commit local env files, credentials, dumps containing secrets, private
  keys, tokens or API secrets.
- Frontend work must follow the frontend law in the single Constitution:
  Vietnamese-first public copy, controlled typography/tokens, accessible
  semantic HTML, responsive layouts, real query services and honest empty/error
  states.

## Stop conditions

Stop and ask the user before irreversible real-data deletion, destructive
production migration, modifying V2 production, changing a locked architectural
invariant, resolving severe identity ambiguity, merging identities, proceeding
through unresolved CONSTITUTION_CONFLICT, or continuing without required
external credentials/infrastructure.

Never perform final production cutover autonomously; produce a Cutover Readiness
Report first. Pushes and merges remain subject to platform policy and quality
gates. Do not claim an external publish occurred unless verified.

V2 and demo.1945.vn may be read-only behavioral, structural, route and sample
references. They are not schema authority, normative architecture or
authorization to migrate article bodies. Tinhte may inform information
architecture and interaction patterns only; do not copy its branding, assets,
markup, styles or proprietary content.

## Autonomous execution

The repository may proceed through approved phases automatically only while the
work remains inside the Constitution. Continue through coherent vertical slices
with evidence, tests, lint, diff checks, secret review, execution-state updates
and logical commits. Human gates remain binding, including final production
cutover, destructive real-data operations, identity-risking merges, missing
credentials and unresolved constitutional conflicts.

The public experience is an editorial NHK discovery surface: Posts remain
editorial truth while Authority, Knowledge, Graph, Media and Video are queried
through application services. Public UI must not expose internal terms, fixtures,
unavailable modules or invented metrics/content. Projection work must not create
semantic types, relations or fields outside the runtime registry/contract.
