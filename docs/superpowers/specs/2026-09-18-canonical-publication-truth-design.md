# NHK V3 — Canonical Publication Truth and Retry Convergence

**Status:** approved design, 2026-09-18  
**Scope:** generic Article compliance scope, fresh publication evidence, and
current Capture retry outcome semantics  
**Deployment/SSH/direct DB write:** none

This design is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md` and
the active Article, Capture, Knowledge, Media, Publication and Compliance
contracts. It does not authorize staging/live mutation, deployment, a new
semantic owner, a new Graph vocabulary, or a generic WordPress writer.

## Goal

Make the vertical slice `Capture → semantic resolution → Article
reconciliation → MediaUsage → compliance → publication gate → retry/final
readback` derive public truth from the correct current owner state while
retaining historical evidence.

## Root-cause findings

### A — Compliance scope

Graph neighborhood retrieval is being treated as the publication claim set
when an Article lacks an explicit claim trace/selection. Discovery candidates
therefore reach compliance even when they are not selected, asserted, or
projected by the Article. The correction is a publication-unit selection
boundary, not a Claim-ID or subject exception.

### B — Publication current state

The publication gate receives an evidence snapshot assembled by orchestration,
but the snapshot can retain planning/receipt values after Article, subject, or
MediaUsage reconciliation has changed the canonical state. The gate must
consume a bounded fresh readback assembled from the owning repositories after
the state token changes. Historical plans are diagnostic context only.

### C — Retry current outcome

Phase receipts and Capture diagnostics preserve failure information but do not
separate historical attempts from the latest authoritative attempt. Retry
response aggregation consequently re-emits an old failure code and status even
after exact reuse and final readback succeed. The correction keeps immutable
attempt history and derives current status from the latest required owner
outcomes.

## Design

### 1. Publication-unit claim scope

`ArticleResearchPreflight` and the Article composition/readback path will
distinguish:

- Graph/Knowledge candidates: discovery input only;
- selected editorial claims: canonical IDs/revisions retained in the
  body-free Article claim trace;
- asserted/projected claims: selected claims mapped to rendered public copy,
  MediaUsage public copy, SEO/public projection, or another registered public
  surface.

Only the latter two categories enter public claim compliance. An empty
selection is an empty compliance set, not an implicit neighborhood selection.
Selected unsupported claims still produce the existing human-review/blocking
decision. Selected supported claims continue to be evaluated normally.

### 2. Fresh canonical publication evidence

Before publication review, the orchestration path will perform one bounded
refresh after native write/reconciliation when the editorial state token has
changed. The refresh reads the current WordPress Post, subject persistence,
MediaUsage slots, public identity/category/SEO/route state, and semantic
readback through their existing owner boundaries. It may invoke one registered
bounded preflight/reconcile when the contract permits it; it must not replay a
stale historical plan or create a second owner.

`ArticlePublicationGate` remains a pure decision boundary over verified
evidence. It will not become a repository, infer persistence from a planning
candidate, or treat representative MediaUsage as Article slot usage. Current
canonical evidence takes precedence over historical Capture planning. Missing
current evidence remains fail-closed.

### 3. Current retry outcome and durable receipts

Phase receipt persistence will represent each attempt as audit evidence and
also expose the latest authoritative outcome for the phase. A successful
retry can mark an earlier retryable failure as resolved/superseded without
deleting or mutating the historical attempt. Capture status and retry code
will be derived from the latest outcomes of all required owners:

- all required owners complete → current success and no historical error code;
- a current required owner remains retryable/failed → current PARTIAL/FAILED;
- a current owner is unresolved or unavailable → remain fail-closed;
- repeated same-key successful retry → idempotent, with no duplicate durable
  owner, receipt, Media, Article, or MediaUsage.

## Invariants preserved

WordPress remains editorial truth; Authority, Knowledge, Source/Evidence,
Graph, Governance, Media, MediaAsset, MediaUsage and Video retain their
existing ownership. Governance remains mandatory for genuine semantic deltas;
`NONE` remains `NOT_REQUIRED`. Exact canonical Media reuse, representative
usage preservation, optimistic revision/CAS, idempotency, provenance,
fail-closed behavior, and the public claim compliance contract remain intact.

## Verification design

TDD coverage will include four neighboring claims with two selected; unselected
unsupported claims; selected unsupported claims; selected supported claims; no
selection; stale media/subject planning versus complete current readback;
current missing state; one refresh under concurrent native write; old failure
followed by retry success; unresolved current child failure; multiple old
failures followed by success; repeated successful retry; complete Article
from the start; media recovery with one canonical Media in featured and
inline slots; and a Post-573-like regression.

Verification will report focused tests, Contract tests, full Unit with exact
baseline comparison, Integration as PASS or SKIPPED/blocked truthfully,
changed-file PHP lint, `git diff --check`, secret review, execution-state
evidence, and worktree/commit status. No deployment, push, SSH or live
mutation is in scope.
