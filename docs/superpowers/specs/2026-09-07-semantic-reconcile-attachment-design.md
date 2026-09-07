# NHK V3 Semantic Reconcile and Canonical Attachment Design

**Status:** Approved in chat, 2026-09-07.

## Goal

Merge one enforceable lifecycle into the existing NHK V3 canonical documents:
reconcile existing semantic records before creation, require canonical
attachment for every new semantic node and Knowledge claim, and fail closed
with a complete deferred ledger entry when identity or relation resolution is
not available.

## Design

Authority remains the owner of canonical semantic node identity; Knowledge owns
atomic claims; Source/Evidence owns provenance and support; Graph owns every
typed relation; Governance owns durable semantic mutation. The new rule is a
cross-domain lifecycle clarification, not a new owner, type, predicate, field,
operation or storage boundary.

Before any semantic node or claim create, the workflow must reconcile the
candidate against canonical data using this ordered outcome: exact existing
record → update/enrich; merge candidate → governed merge/rekey/update; related
but distinct → retain both and create an approved relation; no existing
canonical record → create; uncertain → defer for research/review. Lexical,
keyword, fuzzy or similarity signals may assist discovery only and never prove
canonical identity.

If a suitable canonical subject is absent, the workflow creates the registered
canonical node through its governed writer, reads that node back, creates the
claim, creates the valid registered Graph relation that attaches the claim to
the canonical subject, and reads the relation back. A claim is not completed
without that semantic attachment. If the required node, predicate or relation
contract is unavailable, the workflow does not create a fake node, detached
claim or `about` workaround; it records the required deferred outcome and
reason using the existing ledger statuses.

All mutations use the existing Governance sequence and canonical owner
read-back. Replays use the existing idempotency binding and must not duplicate
nodes, claims or active edges, create dangling relations or silently leave
orphans. Existing valid claims and sources/evidence are reused or enriched;
Source/Evidence provenance and scope requirements remain unchanged.

## Documentation merge surface

The Constitution receives the invariant and acceptance rules. `READ_FIRST.md`,
`AGENTS.md` and the current status index route future sessions to that law.
Knowledge, Graph, Governance, Living Knowledge, Article Ingest, Video ingest,
MCP content operations and the control-plane contracts are updated only where
their current wording needs to reflect the lifecycle. Classification remains a
registry-governed relation; `about` cannot substitute for `classified_as`.

## Validation

Validation is documentation-only: inspect the complete diff, check Markdown
references and contradictions with repository search/tools, run `git diff
--check`, confirm the required wording and ledger statuses are present, and
confirm no runtime code, data, migration, seed, push or deployment changes are
included.
