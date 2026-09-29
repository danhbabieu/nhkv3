# Issue #21 Dictionary Lexical System Design

**Status:** Approved design, implementation pending.

**Goal:** Complete the existing NHK V3 Dictionary lexical slice with a
runtime-confirmed harvester, bounded MCP/Admin curation, lifecycle controls,
dry-run/backfill evidence and explicit handoff packets to existing semantic
owners.

## Ownership law

Dictionary owns only curated lexical concepts, labels, candidates and mentions.
It never owns Authority identity, Knowledge claims, Source/Evidence, Graph
relations, Media identity, Video identity, Article body or public identity.
Detection from Article, Knowledge, Media/Image or Video is an observation;
OCR, filename, caption, transcript and model output remain candidate signals.

Resolution is `SEARCH FIRST → RESOLVE → REUSE → CREATE CANDIDATE ONLY IF
UNRESOLVED`. Ambiguity fails closed for auto-linking. Approved labels may
project links/search expansion; stored WordPress bodies remain unchanged.

## Runtime design

Reuse migration 015 and the existing Concept/Label/Candidate/Mention
repositories. Add only the smallest additive storage needed for durable
idempotency/audit if an existing operation-receipt boundary cannot satisfy the
Dictionary contract. Curated writes use optimistic revision, capability checks,
idempotency keys, explicit actor/audit metadata and canonical read-back.
Concept and label lifecycle is soft-retire/reactivate; normal MCP never hard
deletes curated data.

The harvester is a deterministic application service over registered source
adapters. It may persist mentions and private candidates after the owning
content operation or explicit curator action. Dry-run scans Article, Knowledge,
Media and Video inventories with source counts and no-write proof; replay of
unchanged input is idempotent.

## MCP/Admin surface

MCP uses the existing catalog, transport, Ability mapping and capability model.
Read tools expose bounded concept/label lookup, concept detail and candidate
queue. Mutation tools are dedicated lexical operations for concept create/update,
label add/update/retire/reactivate, concept approve/retire/reactivate and
candidate review. Each returns an explicit status (`available`, `conflict`,
`unavailable`, `not_found` or `applied`) and read-back payload.

Admin reuses the Dictionary curator workspace and adds edit/lifecycle controls,
candidate review provenance and a relation-handoff preview. Public copy stays
Vietnamese-first and never exposes private candidate state, UUIDs, revisions or
review notes.

## Relation handoff

Dictionary may emit a reviewable handoff packet containing the lexical concept,
resolved owner candidate, canonical UUID/stable key/revision, registered target
owner, predicate/operation requested by the owning contract, provenance and
idempotency binding. The packet is not a Graph edge and does not write Authority,
Knowledge or Graph. Any semantic change continues through the existing
Proposal → Human Approval → Eligibility → Controlled Apply lifecycle; unresolved
owner/predicate/scope is returned as a typed gap/conflict.

## Verification and rollout

Add focused unit/contract tests for all MCP schemas and dispatch parity, harvester
provenance and replay, lifecycle/CAS/idempotency, no-hard-delete behavior,
owner-vs-knowledge-vs-graph separation, handoff fail-closed behavior and
dry-run no-write counts. Run available integration tests, PHP lint, diff check
and secret review. Update `V3_EXECUTION_STATE.md` with evidence and blockers;
do not seed, backfill live data, deploy or push.
