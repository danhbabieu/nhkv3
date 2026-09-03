# Odo Demo Governed Apply Plan

**Target:** `demo.1945.vn`
**Status:** future human-controlled cutover packet; no demo mutation performed
on 2026-09-03.

## Constitutional boundary

The Constitution states in §1 that it does not authorize semantic merge,
Graph writes, or changes to V2, staging, or production; those actions require
a separate contract, Governance and gate. §19 requires
`Proposal → Human Approval → Eligibility → Controlled Apply → repository →
 audit`, and §12.6/§19.3 classifies merge/reassignment as a high-impact
semantic mutation requiring durable audit. The Constitution also says final
production cutover is never autonomous.

Classification: **B. SEPARATE_CUTOVER_APPROVAL_REQUIRED**. A future apply is
legal only after the generic rekey/merge contract is accepted, authenticated
demo preflight is complete, each operation has a reviewed proposal and human
approval, eligibility passes, and a separately approved human-executed
cutover is performed through Controlled Apply. This document does not itself
approve or execute that cutover.

## Ordered operations

### A. Deployment capability gate

1. Deploy a reviewed build containing generic governed `rekey`, same-type
   `merge`, Graph reference movement, durable receipt, read-back verification,
   and the reference-surface matrix. Verify the deployed MCP catalog exposes
   the approved operations and does not expose an ungoverned write path.
2. If the deployed catalog is missing any capability, stop with
   `CONTRACT_EXTENSION_REQUIRED`; do not emulate it through REST or SQL.

### B–C. Authenticated preflight and complete inventory

1. Authenticate to the demo administrator read path; record actor identity and
   deployment version without printing credentials.
2. Run health, migration, registry, Authority, Graph inbound/outbound,
   Knowledge, Source, Evidence, Media, MediaUsage, Video and Post read checks.
3. Snapshot every affected UUID, entity type, stable key, lifecycle, payload
   fingerprint and revision. Record Graph edge UUID/revision/predicate,
   Source/Evidence ownership, MediaUsage endpoint contexts, Video thumbnail
   references and Post editorial fingerprints.
4. Every revision used below is
   `RUNTIME_REVISION_REQUIRED_AT_PREFLIGHT`; never copy a revision from the
   manifest or this plan. Resolve all collisions and unresolved references
   before proposal creation.

### D. UUID-preserving `o-do → odo` rekeys

For each non-colliding manifest row, in deterministic type/key order, create a
proposal with: operation `rekey`; the existing UUID; exact old key; canonical
new key; `expected_revision=RUNTIME_REVISION_REQUIRED_AT_PREFLIGHT`; collision
rule `fail closed`; evidence = authenticated preflight snapshot and owner
decision; verification = UUID, key, payload, lifecycle, Graph set and revision
read-back. Submit → approve → eligibility → Controlled Apply. A failed or
uncertain apply is retried only by proposal/idempotency read-back.

### E–F. Confirmed pinned-dial merge and review

1. Source `32f43d4b-d6c8-4223-a89b-cc47f30cda77`, target
   `48311ccd-9d45-4985-a620-ca579499f02c`. Both source and target revisions
   are `RUNTIME_REVISION_REQUIRED_AT_PREFLIGHT`.
2. Create a same-type merge proposal only after complete reference closure.
   Move inbound/outbound Graph edges through the generic adapter; retain one
   compatible target triple; reject self-edge/cardinality/provenance conflicts.
3. Evidence requirement: owner-confirmed identity decision plus complete
   authenticated reference inventory. Verify zero active source references,
   target read-back, all adapter checks PASS, durable receipt and source
   lifecycle transition. No hard delete.
4. The glued pair remains a candidate and is not merged:
   `01bead27-1308-48c1-af99-c68318e2b577` →
   `e326a326-ae8c-447f-a2a4-a83a3cf168d4`.

### G–N. Retirement, relations, Knowledge, Media/Video and Posts

For Odo 35, classify every reference first; retire only if zero references and
all required verification passes. For relations, resolve only registered
predicates and endpoint pairs; unresolved intents remain blocked. For Knowledge,
create only evidence-scoped governed claims and do not fabricate Source or
Evidence. Media/Video placeholders are requirements-only unless the deployed
contracts explicitly support governed placeholders without fake files/URLs.
For Posts 38/39/40/55, reconcile only through Article Governance; preserve
title, body, excerpt, status, slug, permalink and revisions. Every operation
uses a fresh runtime revision, explicit collision/evidence rule, read-back and
fail-closed rollback semantics. No direct WordPress semantic shortcut is
permitted.

## Final gate

Publish a cutover readiness report containing proposal IDs, receipts, apply
attempts, zero-dangling-reference result, unchanged Post fingerprints and
unresolved gaps. Human cutover owner executes or rejects the approved apply;
the agent must not perform final production/demo cutover autonomously.
