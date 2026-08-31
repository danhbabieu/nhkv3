# NHK V3 Autonomous Engineering Rules

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

## Database and migration policy

- Development database is `nhk_v3`; integration database is `nhk_v3_test`.
- `nhk_v3` permits health, smoke checks, non-destructive schema additions,
  indexes, and UP migrations only. Never run DOWN, DROP, TRUNCATE, or a full
  reset there.
- Destructive integration operations are allowed only on exact database
  `nhk_v3_test`, guarded by `TestDatabaseGuard`.
- Before real V2 data migration: backup V2, verify readability, document and
  test restore, preserve required Media mapping/state, and complete a dry-run.
- Migrations must be versioned, idempotent, safely resumable, and ledgered;
  skipped/conflicted records require reason codes.

## Git and quality policy

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

Implementation, refactoring, tests, migrations, local DB checks, commits and
quality-gated pushes to `origin/main` are authorized within the locked V3
architecture. Do not ask the user to repeat decisions recorded here or in the
architecture journal.

Stop and ask the user only before irreversible real-data deletion, destructive
production migration, modifying V2 production, changing a locked architectural
invariant, unresolved severe V2 data contradiction, identity-risking merge,
real migration without backup/restore evidence, missing external credentials or
an unresolvable external infrastructure blocker. Never perform final
production cutover autonomously; produce a Cutover Readiness Report first.

## Parity goal

The end state is V2 functional, data, UI, logic, administration, media, video,
knowledge, MCP, SEO and URL parity or better, represented in the parity matrix.
Intentional differences and retired legacy data must be documented; parity is
not declared while mandatory matrix items are red.
