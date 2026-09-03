# Odo Semantic Pack Apply Report

**Date:** 2026-09-03  
**Result:** `BLOCKED` — `CONTRACT_EXTENSION_REQUIRED`  
**Mutation status:** none

## 1. Initial HEAD

`02d7f3012e0b88ec011c66d130bd412fd059125a`

The worktree contained unrelated concurrent Video changes. They were preserved
and were not staged by this task.

## 2. Runtime inventory

Pack installation and static validation completed. The read-only runtime
inventory is [ODO_RUNTIME_INVENTORY.md](ODO_RUNTIME_INVENTORY.md).

Runtime preflight failed closed at `WORDPRESS_BOOTSTRAP_FAILED`; the health
probe also could not connect to MySQL at `127.0.0.1:3306`. Consequently runtime
counts, revisions, active states, Graph edges and inbound/outbound references
are not claimed.

## 3. Old key → canonical key map

The complete explicit 33-row map from the approved pack is recorded in the
inventory. It includes only design inputs until runtime read-back is possible.
No new `o-do` key was created. Legacy occurrences in the pack are migration,
review or forbidden-namespace references only.

## 4. UUID preservation

No UUID was changed. No rekey was applied. The intended UUID-preserving map is
unverified against runtime.

## 5. Collisions/duplicates

- Confirmed duplicate: `32f43d4b-d6c8-4223-a89b-cc47f30cda77` →
  `48311ccd-9d45-4985-a620-ca579499f02c`, pinned dial; not applied.
- Glued dial pair remains `MERGE_CANDIDATE`; not merged.
- All other target collisions are unverified because runtime is unavailable.

## 6. Merge result

None. Current runtime has no generic governed merge/reference-move operation.
Applying the confirmed merge would require `CONTRACT_EXTENSION_REQUIRED`.

## 7. Odo 35 result

Not retired. Model, movement and variant reference audit is unavailable, so
retirement remains `RETIREMENT_REVIEW`; no record was deleted, retired or
replaced.

## 8. Relations created/reused

None. No relation proposal or Controlled Apply was created. Registry review
identified valid predicates in code, but endpoint existence, existing triples,
provenance, cardinality and duplicate state require runtime read-back. Model →
Movement and Variant → Component/Classification have no specific registered
predicate and remain unresolved; broad `about` was not silently substituted.

## 9. Knowledge created/reused

None. The six domestic shells and community/recognition shells remain a
governed creation backlog. No owner statement was promoted to Fact and no
Source/Evidence was fabricated.

## 10. Media/Video result

No Media or Video placeholder was created. The current runtime exposes no
governed placeholder operation, so the manifest requirements remain
requirements-only. No fake URL, file or asset was introduced.

## 11. Post 38/39/40/55 reconciliation

Not performed because WordPress is unavailable. No Post was created or changed.
In particular, Post 55 identity, body, excerpt, status, slug, permalink and
revisions remain untouched; no duplicate Post was created.

## 12. Unresolved/research-required

- Restore the existing WordPress/MySQL runtime and rerun the full read-only
  inventory.
- Obtain a reviewed generic V3 Authority `rekey` capability that preserves
  UUID, checks expected revision/idempotency and fails closed on collision.
- Obtain a reviewed generic merge/reference-move/deprecation capability before
  the confirmed pinned-dial merge.
- Audit Odo 35 references before any retirement decision.
- Resolve every relation intent from actual registered predicate/endpoint
  contracts and evidence; do not invent predicates.
- Reconcile Posts only through the existing Article Ingest path and stop at
  its Human Approval/Controlled Apply boundary.

No Constitution conflict was introduced. Proceeding with any of the blocked
semantic operations now would violate the runtime Governance/Registry boundary
and is therefore explicitly stopped with `CONTRACT_EXTENSION_REQUIRED`.

## 12a. Local generic merge follow-up — 2026-09-03

The local code now includes an append-only durable receipt repository backed by
the existing Governance audit event table, an explicit adapter `verify`
contract, plan-bound receipt state (`applying`, `partial`, `completed`) and
attempt metadata. Focused merge tests pass (`3 tests / 8 assertions`), but the
runtime is intentionally not wired or deployed because only the Graph adapter
exists; the required Knowledge, Source, Evidence, MediaUsage, Video and
WordPress reference surfaces still need contract-audited adapters. This does
not authorize or constitute an Odo data mutation.

## 13. Test results

- YAML parse: PASS (`YAML_VALID`).
- New canonical manifest-key scan: PASS (`NEW_CANONICAL_TARGETS_NO_O_DO`).
- Unit suite: PASS — 277 tests, 1,402 assertions.
- PHP lint: PASS.
- `git diff --check`: PASS.
- Deployment preflight: FAIL CLOSED — 5/10 checks failed, all dependent on
  WordPress/database bootstrap.
- Full suite on current concurrent worktree: blocked by environment — 8
  integration errors, 12 mandatory integration failures, 74 skips; no test
  failure was hidden or downgraded.

## 14. Commit hashes

- `6fd6cc3` — `docs: add Odo semantic reference pack`
- `621e59b` — `docs: record Odo runtime inventory gate`
- `a10d265` — `docs: record Odo apply boundary`.
- The earlier transient shared-index lock was not deleted or bypassed.

## 15. Final HEAD

At report creation: `a10d265`.

All unrelated Video changes remain uncommitted and untouched.
