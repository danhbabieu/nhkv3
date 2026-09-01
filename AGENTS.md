# NHK V3 Autonomous Engineering Rules

## Constitution — mandatory first read

Before every task, every Codex session MUST read:

`docs/constitution/READ_FIRST.md`

The governing order is:

1. Structure first.
2. Relationships first.
3. Data later.

Do not plan, design, implement or activate migration/import/parsing of legacy article body content under the current constitution scope.

Do not invent any entity type, endpoint type, predicate, relation type, canonical field, attribute definition, operation or knowledge profile outside the active runtime registry/contract.

Brand is the semantic backbone. Preserve Brand context and the correct semantic level of Model, Variant, Movement, Music, Component, Classification, Specimen and related domains without creating fake ancestor relations or duplicate identity.

If implementation, an older architecture document, a migration path or a proposed change conflicts with the constitution, mark it explicitly as `CONSTITUTION_CONFLICT`. Do not silently preserve the conflicting behavior and do not rewrite the constitution merely to legalize existing implementation.

## Scope and workspace

- Official workspace: `/Users/imac24-2125d/Developer/nhk-v3`
- Repository: `https://github.com/danhbabieu/nhkv3.git`
- Default branch: `main`
- This repository is the only implementation workspace for NHK V3.

## Architecture invariants

- WordPress native `wp_posts` is the sole source of truth for editorial title,
  body, author, dates, categories, archives, homepage, search, RSS, sitemap and
  URLs. Never reintroduce Article Authority body or an Article Projection path.
- Authority owns canonical semantic entities; Knowledge owns atomic claims;
  Graph is the single relation system; Governance owns durable semantic
  mutations; Media is a first-class semantic entity; Video is a canonical
  external-reference entity.
- Do not copy V2 architecture, fragmented relation persistence, duplicate
  domain truth, dead compatibility code, or legacy God services.
- Specimen means a concrete physical object. Product means a listing/offer and
  is not an identity for the physical object.
- Canonical UUID/stable-key, optimistic revision, typed relation, provenance,
  readiness, idempotency and fail-closed invariants must remain explicit.
- Runtime registries/contracts are executable boundaries. Existing data,
  fixtures, UI or legacy structures never authorize a new type, predicate,
  relation or field by themselves.

## Database policy

- Development database is `nhk_v3`; integration database is `nhk_v3_test`.
- `nhk_v3` permits health, smoke checks, non-destructive schema additions,
  indexes, and UP migrations only. Never run DOWN, DROP, TRUNCATE, or a full
  reset there.
- Destructive integration operations are allowed only on exact database
  `nhk_v3_test`, guarded by `TestDatabaseGuard`.
- Schema migrations must be versioned, idempotent and safely resumable.
- The current constitution does not authorize planning or execution of legacy
  article-body migration/import/population. V2 may be read to understand
  structure, relationships, identity, UI/UX and representative examples only.

## Git and quality policy

- Read `docs/constitution/READ_FIRST.md` before every task.
- Read `docs/architecture/V3_EXECUTION_STATE.md` before every task and update it
  after every checkpoint. Read `V2_V3_PARITY_MATRIX.md` before claiming parity.
- Preserve existing working-tree changes. Never use `git reset --hard`,
  `git clean -fd`, `git checkout -- .`, or destructive restore operations.
- Use logical checkpoint commits (normally 1–3 per milestone), run the relevant
  tests, PHP lint, migration checks when schema changes, `git diff --check`, and
  a secret review before commit/push.
- Never commit local env files, credentials, dumps containing secrets, private
  keys, tokens, or API secrets. Do not downgrade tests or hide failures with a
  broad catch that reports success.

## Autonomy and stop conditions

Implementation, refactoring, tests, schema migrations, local DB checks, commits
and quality-gated pushes to `origin/main` are authorized only within the locked
V3 architecture and the constitution. Do not ask the user to repeat decisions
recorded here, in `docs/constitution/`, or in the architecture journal.

Stop and ask the user before irreversible real-data deletion, destructive
production migration, modifying V2 production, changing a locked architectural
invariant, unresolved severe V2 data contradiction, identity-risking merge,
missing external credentials or an unresolvable external infrastructure blocker.
Never perform final production cutover autonomously; produce a Cutover Readiness
Report first.

A `CONSTITUTION_CONFLICT` is also a stop condition for the conflicting semantic
change: document the conflict and do not treat the conflicting implementation as
approved architecture until the conflict is resolved.

## Parity goal

The end state is V2 functional, UI, logic, administration, media, video,
knowledge, MCP, SEO and URL parity or better where parity is compatible with the
NHK V3 constitution. Intentional differences and retired legacy behavior must be
documented; parity never overrides structure, relationship or identity
invariants.

## Autonomous execution addendum

The repository is authorized to proceed through P6, P7, P8, P9, P10 and P11
without per-step confirmation only where work remains constitution-compliant.
Work must continue through coherent vertical slices, with tests, lint, diff
checks, secret review, execution-state updates, logical checkpoint commits and
quality-gated pushes. The human gates are the stop conditions above, including
V2/live modification, destructive real-data operations, identity-risking merges,
missing credentials, final production cutover and unresolved
`CONSTITUTION_CONFLICT` for the affected semantic change.

V2 and `demo.1945.vn` are read-only behavioral, route, structural and sample-data
references. They are not schema authority and are not authorization to migrate
or import legacy article body content. `tinhte.vn` may inform information
architecture and interaction patterns only; its branding, assets, markup, styles
and proprietary content must not be copied.

The public experience must be an editorial NHK discovery surface: WordPress
Posts remain the editorial body and URL truth, while Authority, Knowledge,
Graph, Media and Video are queried through application services. Public UI must
not expose internal terms such as Authority, Proposal or Knowledge Claim, must
not expose fixtures, and must hide unavailable modules rather than inventing
metrics or content.

Frontend work may proceed in parallel once contracts are stable enough. Use a
clean custom or controlled block theme, reusable accessible components,
responsive layouts, semantic HTML, performance-aware media, and real V3 query
services. Projection work must not create semantic types, relations or fields
outside the runtime registry/contract.