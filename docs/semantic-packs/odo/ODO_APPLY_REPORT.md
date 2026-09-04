# Odo Semantic Pack Apply Report

**Date:** 2026-09-03  
**Result:** `BLOCKED` — `DEMO_ADMIN_SEMANTIC_CREDENTIAL_REQUIRED`
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

No demo merge was attempted. The current worktree contains the reviewed
generic same-type merge coordinator, Graph inbound/outbound adapter, durable
receipt, read-back verification and Controlled Apply dispatch. The approved
reference-surface matrix confirms Knowledge, Source, Evidence, MediaUsage and
Video are `NOT_APPLICABLE` for direct Authority merge movement, and `wp_post`
is `GRAPH_ONLY`; their absence is not a merge blocker. Demo execution still
requires authenticated administrator Graph reads and fresh runtime revisions.

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

- Provide a demo WordPress administrator credential/session with
  `manage_options` so the restricted Graph inbound/outbound read can complete.
- Rerun the full read-only inventory and capture all runtime revisions before
  creating any Odo proposals.
- Audit Odo 35 references before any retirement decision.
- Resolve every relation intent from actual registered predicate/endpoint
  contracts and evidence; do not invent predicates.
- Reconcile Posts only through the existing Article Ingest path and stop at
  its Human Approval/Controlled Apply boundary.

No Constitution conflict was introduced. The remaining stop is the missing
authenticated demo read capability, recorded as
`DEMO_ADMIN_SEMANTIC_CREDENTIAL_REQUIRED`; no proposal IDs can be honestly
created without the runtime snapshot and revisions.

## 12a. Local generic merge follow-up — 2026-09-03

The local code includes an append-only durable receipt repository backed by the
existing Governance audit event table, an explicit adapter `verify` contract,
plan-bound receipt state (`applying`, `partial`, `completed`) and attempt
metadata. Focused generic rekey/merge/Graph/Controlled Apply tests pass. The
runtime is wired locally with the Graph-only reference surface; no separate
Knowledge, Source, Evidence, MediaUsage or Video Authority adapter is required
by the approved matrix. This does not authorize or constitute an Odo mutation.

## 13. Test results

- YAML parse: PASS (`YAML_VALID`).
- New canonical manifest-key scan: PASS (`NEW_CANONICAL_TARGETS_NO_O_DO`).
- Unit suite: PASS — 310 tests, 1,528 assertions.
- PHP lint: PASS.
- `git diff --check`: PASS.
- Deployment preflight: FAIL CLOSED — 5/10 checks failed, all dependent on
  WordPress/database bootstrap.
- Full suite: environment-blocked outside the Unit suite — 8 integration
  errors, 12 mandatory integration failures, 74 skips; no test failure was
  hidden or downgraded.

## 14. Commit hashes

- `6fd6cc3` — `docs: add Odo semantic reference pack`
- `621e59b` — `docs: record Odo runtime inventory gate`
- `a10d265` — `docs: record Odo apply boundary`.
- The earlier transient shared-index lock was not deleted or bypassed.

## 15. Final HEAD

At report creation: `a10d265`.

All unrelated Video changes remain uncommitted and untouched.

## 15b. Current live governance blocker — 2026-09-04

The merge operation is now exposed in the live proposal schema, so
`MERGE_OPERATION_NOT_EXPOSED` is historical and stale as a current status. A
proposal-create diagnostic for pinned-dial source UUID
`32f43d4b-d6c8-4223-a89b-cc47f30cda77` persisted `subject_id="component"`
instead of the source UUID. The diagnostic was rejected; no merge/apply or
semantic mutation occurred. Current status is
`PINNED_DIAL_MERGE=BLOCKED` with reason
`LIVE_MERGE_SUBJECT_BINDING_INVALID`.

## 15a. Integrity-repair continuation — 2026-09-03

The live read-only Authority scan identified exactly two active collisions,
both revision 1 and active: the owner-confirmed pinned pair
`32f43d4b-d6c8-4223-a89b-cc47f30cda77` →
`48311ccd-9d45-4985-a620-ca579499f02c`, and the unconfirmed glued pair
`01bead27-1308-48c1-af99-c68318e2b577` →
`e326a326-ae8c-447f-a2a4-a83a3cf168d4`. Live Graph read-back found no
component nodes or active inbound/outbound references for these keys. The
pinned merge was not applied because this execution did not receive a trusted
explicit approval for the external database mutation; no workaround was used.

Repository prevention now exposes an explicit semantic/media isolation guard,
and `tools/odo-media-integrity-audit.php` is a default read-only WordPress
attachment/filesystem auditor. Unit regression coverage proves canonical DB /
legacy filesystem, reverse mismatch, both-variant collision, missing
derivative, orphan-file and inline legacy URL diagnostics. No media path is
changed by semantic rekey code.
