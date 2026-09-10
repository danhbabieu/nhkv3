# NHK V3 Governance Admin Queue — Design

**Date:** 2026-09-10  
**Status:** Approved design for implementation

## Goal

Provide a practical Vietnamese-first WordPress Admin queue for existing NHK
V3 Governance proposals. The queue is a control-plane adapter: it reads
canonical Proposal records through a bounded server-side query and executes
existing Governance lifecycle services for single or bulk operations. It does
not become a new content-ingest or semantic persistence owner.

## Architectural decision

The queue record is `Proposal`, because Governance owns Proposal binding,
approval, eligibility, Controlled Apply, idempotency and audit. Claims are
not listed as independently actionable queue records; a Claim is only shown as
proposal context when present in the proposal payload or canonical read model.

The implementation extends the existing `nhk-v3-governance` Admin Workbench
route and changes its human-facing label to **Duyệt dữ liệu**. It uses a
WordPress-native GET table for search/filter/sort/pagination and POST forms for
mutations. JavaScript is progressive enhancement only; a mutation remains
valid and reportable through the server-rendered response.

## Components and boundaries

### Bounded queue query

Add a read-only `GovernanceQueueQuery` contract and a WordPress database
implementation. The query accepts:

- `search`: exact/partial Proposal UUID, target UUID, subject/entity/name text;
- `status`: one of the existing `ProposalState` values or the explicit all
  option;
- `type`: an allowlisted persisted entity type or all;
- `order_by`: `created`, `updated`, `name`, `id`, or `status`;
- `order`: `asc` or `desc`;
- `page` and `per_page` within bounded limits.

The SQL path builds predicates and order expressions only from allowlists,
uses `HEX(proposal_uuid)` for UUID search, and applies `LIMIT/OFFSET` in the
database. `COUNT(*)` uses the same predicates. A deterministic secondary
ordering by the internal proposal row ID is always appended. The query reads
`nhk_proposals` and parses its governed JSON payload for presentation fields;
it never updates Governance tables. It does not perform per-row canonical
lookups. If a related display value is not persisted in the proposal packet,
the UI shows the canonical identifier or an honest unavailable label rather
than issuing N+1 queries or guessing a name.

The result contains `items`, `total_items`, `total_pages`, `page`,
`per_page`, normalized filters and an explicit availability state. Malformed
or unreadable rows remain diagnostics/blocked rows rather than silently
disappearing from the queue.

### Lifecycle action adapter

Add a focused Admin action service that receives the existing
`GovernanceService`, `ProposalEligibilityService` and `ControlledApplyService`
through dependency injection. It exposes only the canonical operations:

- `submit` for a draft proposal when the current lifecycle permits it;
- `approve` for draft/submitted proposals, passing the posted content and
  dependency fingerprints;
- `reject` for draft/submitted proposals;
- `apply` only through `ControlledApplyService`, which rechecks approval,
  eligibility, target revision, dependencies, transaction, audit and
  canonical read-back.

Before each mutation, the adapter reloads the proposal and compares the
submitted proposal revision plus the relevant fingerprints with the current
record. A mismatch returns a typed stale/conflict result and does not call a
mutation service. The action service never calls repository `save`, never
issues status SQL, and never synthesizes an approved/applied state.

The existing Governance dependency graph is constructed through one reusable
runtime factory so Admin and the existing REST adapter use the same application
service composition. The factory is infrastructure wiring only; it adds no
domain operation or alternate writer.

### Admin page and handlers

Add a focused `GovernanceQueueAdminPage`/renderer under the existing Admin
Infrastructure boundary and wire it into `AdminWorkbenchPage` without changing
the Capture workspace. The page provides:

- the human label “Duyệt dữ liệu”;
- a table with current-page checkbox, proposal ID, item type, subject/name,
  summary, status, provenance/source summary when present, created and updated
  dates, and available actions;
- server-side search, status/type filters, allowlisted sort controls and
  bounded per-page selection;
- select-all for the current page only;
- per-item forms and a bulk form using POST;
- result notices with selected/succeeded/failed counts and each failed ID plus
  reason.

The row action policy is derived from the actual `ProposalState` and the
operator capabilities. Terminal proposals expose detail/review only. Apply
is available only for approved proposals; the action still relies on the
eligibility service and reports blocked reasons. Reject does not ask for a
reason because the current canonical `GovernanceService::reject` contract has
no required reason parameter. A future contract requiring one must add it at
the domain boundary before the UI exposes it.

Bulk operations iterate independently over the selected row snapshots. A
missing, malformed, stale or ineligible item produces a failure entry while
other items continue. Apply is never optimized into SQL or a batch repository
write. Successful responses are reported only after the canonical service
returns; uncertain exceptions remain explicit failures/uncertain diagnostics.

## Security

The page entry requires `nhk_view_governance`. Every POST handler checks the
operation capability again (`nhk_submit_proposals`, `nhk_approve_proposals` or
`nhk_apply_proposals`), verifies a WordPress nonce, accepts POST only, and
sanitizes IDs, filters, sort values, action names and bulk arrays. UUIDs are
validated with `UuidCodec`; unsupported types/statuses/actions fail closed.
All output is escaped. GET only reads and never mutates. The existing REST
Governance routes and their capability guards remain unchanged.

## Single-entry-point and MCP invariants

The queue is an owner/admin maintenance surface for existing Governance
proposals. It does not add a Capture substitute, direct Article/Media/
Knowledge/Source/Evidence/Graph writer, WordPress generic mutation tool or
MCP Ability. `nhk.capture.ingest` remains the only normal new-content entry
point. A regression test compares the current MCP catalog/operator allowlist
before and after the Admin queue wiring and proves no new operator mutation
surface is registered.

## Data flow

```text
GET nhk-v3-governance
  -> normalize allowlisted filters
  -> bounded SQL query on nhk_proposals
  -> render current page + lifecycle-derived actions

POST admin-post.php
  -> capability + nonce + input validation
  -> reload and compare revision/fingerprint snapshot
  -> GovernanceService / Eligibility / ControlledApplyService
  -> canonical audit/read-back
  -> aggregate per-item result
  -> redirect with escaped summary and failed-item diagnostics
```

No semantic data is created, seeded, backfilled, merged, rekeyed or published
by rendering the queue. The only durable effects are the same governed
Proposal transitions and apply attempts already exposed by the canonical
application services.

## Testing strategy

Focused unit tests cover query normalization, exact/partial UUID search,
subject/name search, status/type filters, every allowed sort direction,
invalid sort fallback, stable secondary ordering, page boundaries and totals.

Action tests use fakes/spies for the canonical services and cover capability
and nonce rejection, GET non-mutation, invalid identifiers/actions, every
single lifecycle operation, eligibility and stale binding failures,
idempotent apply behavior, mixed bulk eligibility, missing IDs, partial
failure and result counts. An architecture test rejects direct SQL mutation or
repository writes in the Admin queue and proves the canonical service is
called.

Admin rendering/handler tests assert the table, checkbox/select-all markup,
Vietnamese labels, escaped output, POST-only forms and clear success/failure
reporting. MCP tests assert the Capture catalog and operator exposure are
unchanged.

## Scope exclusions

- no new Proposal/Claim lifecycle or status;
- no new REST/MCP write surface;
- no direct proposal creation form in the queue;
- no article/media/knowledge ingest changes;
- no new database migration unless the existing query proves a schema change
  is strictly required;
- no remote runtime, production/staging/V2 data, deployment or cutover work.

