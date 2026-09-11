# Governed Conversational Authority Design

**Date:** 2026-09-11
**Status:** Owner-approved design; written-spec review pending
**Scope:** Capture, Authority, Graph, Governance, MCP/Admin and semantic read models

## 1. Goal

Enable MCP/ChatGPT to manage canonical Authority through natural-language
conversation while preserving reuse-first identity resolution, explicit owner
approval, Governance, Graph ownership, atomic apply, canonical read-back and
fail-closed behavior.

The first acceptance vocabulary is the existing Authority catalogue:

`brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen`, `product`.

Clock types remain `classification` records with `family=clock-type`. No
`clock_type` Authority type, taxonomy, postmeta store or generic WordPress
writer is introduced.

## 2. Constitutional extension

The current Constitution and runtime classify Classification membership as a
`REGISTRY_GAP`. This feature explicitly extends the approved semantic
vocabulary through an architectural amendment and the corresponding current
contracts. The extension does not weaken any existing invariant.

### 2.1 `subtype_of`

Register the predicate:

```text
subtype_of
source: classification
target: classification
outbound cardinality: ONE
inbound cardinality: MANY
scope: structural classification hierarchy
```

The relation is valid only when both endpoints are ACTIVE classifications in
the same compatible family. A relation cannot point to itself, introduce a
cycle, or be duplicated. The relation is stored only in Graph, is revision
bound, is governed, and is read back canonically. Retired edges are not
silently resurrected.

`subtype_of` is structural hierarchy only. An origin, geography, case style,
mechanism, material or other facet is not converted to a subtype merely because
the UI displays it below a classification.

### 2.2 `classified_as`

Register the predicate for bounded classification membership:

```text
classified_as
source: model | variant | specimen | product
target: classification
outbound cardinality: MANY
inbound cardinality: MANY
scope: documented classification membership
```

Brand is excluded. Movement is deferred until a separate scope contract
approves its classification semantics. Until then a movement request returns
`CONTRACT_EXTENSION_REQUIRED`; it is never rewritten as `about`.

The relation must carry the evidence/provenance required by the owning
contract, and the narrowest source scope remains authoritative. A specimen
classification cannot be promoted to a Brand, Model or Variant fact.

## 3. Capture entry point and modes

`nhk.capture.ingest` remains the only normal MCP entry point for a new
conversational Authority request. Authority-only Capture is a valid operator
mode and does not create a WordPress Post; Capture remains the durable operator
record and no alternate semantic writer is introduced.

### 3.1 Planning turn

The request carries raw text and an optional structured intent packet:

```json
{
  "idempotency_key": "authority-turn-1",
  "text": "Tạo thương hiệu Hermle.",
  "authority_intent": {"mode": "PLAN"}
}
```

The server creates one Capture, runs the planning-only service and returns
`capture_id` plus the machine-readable plan. Planning performs no Authority,
Graph, Proposal or Knowledge mutation.

### 3.2 Approval/apply turn

The continuation uses the same Capture:

```json
{
  "capture_id": "01...",
  "idempotency_key": "authority-turn-2",
  "text": "Đồng ý tạo.",
  "authority_intent": {
    "mode": "APPLY_APPROVED_PLAN",
    "approved_plan_fingerprint": "sha256...",
    "approved_candidate_ids": ["candidate-1", "candidate-2"]
  }
}
```

The structured packet is the authorization boundary. Natural-language approval
phrases are interpreted by ChatGPT into this packet; free text alone is not
treated as an unbounded approval. A partial approval such as “Không tạo mục số
3” is represented by the exact remaining candidate IDs.

The Capture continuation ledger is idempotent by continuation key and payload
fingerprint. A same-key/same-payload retry returns the durable result; a changed
payload fails with `CAPTURE_ADDENDUM_IDEMPOTENCY_CONFLICT`.

## 4. AuthorityIntentPlanner

`AuthorityIntentPlanner` is a pure planning application service. It consumes:

- raw user text;
- resolved Capture context;
- structured subject/authority hints;
- current canonical Authority inventory;
- bounded Graph context and revisions;
- user authorization state;
- registered EntityType, EndpointType and Predicate definitions.

It returns:

```json
{
  "reuse": [],
  "create_candidates": [],
  "update_candidates": [],
  "relation_candidates": [],
  "rejected_or_composed_facets": [],
  "ambiguities": [],
  "blockers": [],
  "plan_fingerprint": "sha256..."
}
```

Every candidate has a deterministic candidate ID, action, entity type, family
when applicable, canonical identity/revision when reused, proposed name and
server-owned stable-key preview when creation is eligible. Candidate IDs are
not database IDs and are bound to the plan fingerprint.

The planner does not create a Proposal, approve a Proposal, apply a mutation,
create a WordPress taxonomy term, or write semantic data.

## 5. Reuse-first resolution

Each requested semantic item is resolved in this order:

1. canonical UUID;
2. scoped stable key;
3. exact canonical name after deterministic Vietnamese/case normalization;
4. exact registered alias;
5. bounded lexical candidates for human review only.

An active match is `REUSE`. A retired match is not silently reactivated. More
than one match produces `IDENTITY_CONFLICT` and a review candidate. Unknown or
ambiguous brands never produce fuzzy auto-create.

Stable keys for new Authority candidates are generated by a server-owned
`CanonicalAuthorityStableKeyPolicy`. It may reuse the shared pure text
normalizer but owns the Authority namespace, type/family prefix, collision
check and deterministic output. The MCP client cannot provide or override the
canonical stable key.

## 6. Facet composition rules

The planner recognizes composition only when each facet resolves to an
independent canonical item or remains an explicit review candidate.

For “Đồng hồ để bàn Pháp” the plan is:

```text
REUSE classification clock-type.table-clock
REUSE facet origin.france when the canonical inventory contains it
REJECT composed entity French Table Clock
```

If `origin.france` is unavailable, the planner reports an unresolved facet or a
separately requested candidate; it does not mint a combined classification.

For “Hermle để bàn có ly úp” the plan may reuse Hermle, Table Clock and an
existing glass-dome component/classification. It does not create a Model named
Hermle ly úp unless the user explicitly identifies that text as a Model or
approved Source/Evidence proves an official model/reference identity.

## 7. Governance binding and atomic apply

The apply continuation must re-run planning against the current canonical
inventory and Graph snapshot. It compares the resulting fingerprint with
`approved_plan_fingerprint`. A mismatch returns
`PLAN_REAPPROVAL_REQUIRED` before any Proposal is created.

Only `approved_candidate_ids` in the exact plan may be materialized. Unknown,
new or omitted dependency candidates cannot be added implicitly. Dependency
closure is deterministic; if a selected relation requires an unapproved
endpoint, the plan is blocked and must be approved again.

Selected candidates are translated into the existing typed Proposal model and
processed through:

```text
Proposal → Submit → approval policy → Eligibility → Controlled Apply →
Authority/Graph owner → canonical read-back → duplicate verification
```

Create candidates use `expected_revision=null`; updates, existing-target
relations and lifecycle changes bind the observed revision. Proposal
idempotency keys include Capture ID, plan fingerprint and candidate ID.

The plan-level apply uses one transaction for all selected Authority and Graph
mutations. A failure rolls back every semantic mutation and Proposal state
change in that plan. Durable failed-attempt diagnostics are written after the
rollback in a separate transaction. A retry is safe and cannot create a
duplicate Authority or Graph edge.

## 8. Automation policy

Add a separate Authority-conversation policy, independent from generic
per-domain ingestion automation:

```text
OFF
REVIEW_REQUIRED
AUTO_APPROVE_AFTER_OWNER_CONFIRMATION
```

The default is `REVIEW_REQUIRED`.

- `OFF`: planning may explain the request, but apply is blocked.
- `REVIEW_REQUIRED`: owner confirmation creates/submits exact Proposals and
  leaves them waiting for Governance review.
- `AUTO_APPROVE_AFTER_OWNER_CONFIRMATION`: owner confirmation permits the
  Capture-owned orchestrator to submit, approve, check eligibility, apply and
  read back the exact approved plan.

No mode permits AI creation without explicit confirmation. Owner confirmation
never bypasses authentication, authorization, registry validation, identity
ambiguity, eligibility, transaction safety or canonical read-back.

## 9. Graph and read-model behavior

`subtype_of` is the only semantic parent relation for Classification. The
hierarchy policy checks active endpoints, compatible family, self-relation,
cycles, duplicate edges and revision binding through Graph.

`classified_as` is direct membership. A Brand page's “Clock types
encountered” section is a projection from classified Models, Variants,
Specimens or Products reachable through approved paths; it is not a persisted
Brand → Classification shortcut.

The Classification read model labels results explicitly:

- `CHILD_TYPE`: an active Classification reached through `subtype_of`;
- `FACET_FILTER`: an independent facet such as origin/geography, composed for
  navigation only.

Frontend/Admin may render both in one tree-like view, but Graph and Authority
must preserve the distinction. Missing Graph/read-model dependencies return an
unavailable state, not an empty success.

## 10. Knowledge continuation

User-provided Knowledge is planned separately from Authority identity. A claim
candidate retains its narrow subject, scope and provenance class
`EXPLICIT_USER_KNOWLEDGE`. A claim about a Specimen remains specimen-scoped;
the planner never promotes it to Brand fact.

After a Brand is canonically created/read back, a dependent Knowledge Proposal
may be created and governed. The claim may use an existing registered `about`
relation only when its subject/scope/evidence contract allows it. Article prose,
ChatGPT output, captions, OCR and transcripts do not become Evidence merely by
being present in Capture.

## 11. MCP and Admin contract

The existing `capture_id` property remains optional in tools/list and is
explicitly exposed by the connector. The Capture schema adds a bounded,
closed `authority_intent` object with:

- `mode`: `PLAN` or `APPLY_APPROVED_PLAN`;
- `approved_plan_fingerprint`: a SHA-256 string required for apply;
- `approved_candidate_ids`: a non-empty list required for apply.

MCP output exposes plan fingerprint, candidate IDs, reuse/create/relation
details, rejected facets, ambiguities, blockers, Capture ID, Governance status,
canonical read-back and idempotent result flags. It never exposes raw SQL,
taxonomy IDs or internal fallback writers.

Admin adds a guarded “Conversational Authority Creation” setting with the three
policy modes above. The existing Governance queue remains the review surface;
the Admin UI does not introduce a second semantic mutation path.

## 12. Required acceptance cases

The implementation must test and demonstrate:

1. Hermle absent → one Brand create candidate.
2. Hermle existing → reuse and zero create candidates.
3. Existing Cuckoo Classification → reuse.
4. Existing Table Clock Classification → reuse.
5. Table Clock + France → independent facets; no combined entity.
6. Public Clock absent → one `classification`, `family=clock-type` candidate.
7. Glass-dome Classification under Table Clock → governed `subtype_of` only
   after both endpoints are approved and family/cycle rules pass.
8. Approval applies only the exact previewed candidate IDs.
9. Changed fingerprint blocks apply and requires reapproval.
10. Unknown/ambiguous Brand blocks fuzzy auto-create.
11. Specimen claim does not become a Brand fact.
12. No WordPress taxonomy/postmeta/direct-SQL fallback.
13. Capture/approval replay is idempotent.
14. Authority retry does not create duplicates.
15. Multi-candidate apply failure leaves no partial Authority or Graph state.

The final E2E evidence must show:

```text
natural language → Capture → plan → preview → explicit approval →
same Capture continuation → Governance → Controlled Apply →
canonical read-back → Graph/read-model verification → duplicate check
```

## 13. Non-goals and stop conditions

- No new Authority type `clock_type`.
- No automatic Model inference from descriptive phrases.
- No combined classification entities for facet intersections.
- No Product–Specimen relation is invented.
- No Article body migration, Evidence fabrication or V2/production/staging
  mutation.
- No deployment or production cutover is performed by this feature.
- A constitutional conflict, unresolved identity ambiguity, unavailable
  required registry/contract or missing infrastructure blocks the affected
  operation and is reported with a typed diagnostic.

## 14. Affected boundaries

The implementation plan will modify only the following bounded areas:

- Authority planning, reuse and server-owned stable-key policy;
- Capture Authority-only mode, continuation and plan persistence;
- Graph predicate/policy/query support for the approved extensions;
- Governance plan-level exact-set atomic apply;
- MCP tool schema/transport and runtime wiring;
- Admin policy presentation and guarded persistence;
- Classification/Brand read-model projections;
- focused unit, contract, integration and E2E acceptance tests;
- current architecture/contract status and execution-state documentation.

Existing unrelated worktree changes remain untouched.
