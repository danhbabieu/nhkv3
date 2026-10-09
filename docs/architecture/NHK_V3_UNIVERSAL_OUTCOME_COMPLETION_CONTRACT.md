# NHK V3 Universal Outcome Completion Contract

STATUS: `ACTIVE`

This document is the active implementation contract for universal MCP intake
outcomes. It is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md` and
must be read with the owning domain contracts, the universal structured intake
contract, the MCP content-operations contract and the current execution state.
It does not create an entity type, field, predicate, relation, endpoint,
writer, authorization or production permission.

## 1. One completion law

Every accepted Capture intent compiles one immutable outcome plan through
`OutcomeObligationCompiler`. The plan is bound to the Capture UUID, request
fingerprint, idempotency key, selected owner types, owner revisions,
dependency closure, capability and expiry whenever those values exist in the
canonical packet.

The plan is the shared vocabulary for admission, recovery, Governance,
canonical persistence, public projection, frontend read-back and the final
aggregate. A Capture is complete only when every `REQUIRED` obligation is
verified. A proposal, an approval, a successful HTTP response, a local
fixture, a generated URL or a connector-visible tool is not canonical
completion by itself.

Each obligation uses exactly one state:

| State | Meaning |
|---|---|
| `REQUIRED` | The requested outcome must reach verified read-back before completion. |
| `CONDITIONAL` | The outcome is applicable only when the bound request or policy activates it; once activated it becomes required. |
| `OPTIONAL` | The outcome may be produced but is not a completion gate for this intent. |
| `NOT_APPLICABLE` | The owner/dependency cannot produce that surface; the plan must include `NOT_APPLICABLE_REASON`. |

The canonical plan records at least `canonical`, `governance`, `relations`,
`public`, `frontend`, `homepage` and `publication` dimensions. It also records
the owner types, dependency owner types, public request, recipe, Capture
binding and a deterministic fingerprint. Missing applicability, missing
capability, changed binding, stale revision, failed read-back or an unknown
registry value is fail-closed; it is never silently downgraded to optional.

## 2. Universal lifecycle

The only valid completion sequence is:

1. `nhk.capture.ingest` admits and fingerprints the request after the current
   documentation/bootstrap checkpoint.
2. The compiler derives the domain recipe and persists the outcome plan with
   the Capture context and diagnostics.
3. The registered Governance lifecycle performs proposal, approval/eligibility
   and controlled apply where the owner contract requires it.
4. The canonical owner writes through its governed repository/service and is
   read back by canonical identity plus current revision.
5. Required relations are read back through Graph's registered relation
   contract; a relation preview or proposal is not a committed edge.
6. Required public and frontend projections are read back through their
   canonical query/reconciliation services. A required homepage obligation is
   separately verified; it is never inferred from an entity URL.
7. The reducer exposes the aggregate with each obligation state and reason.

Recovery reuses the original Capture identity and idempotency binding. A retry
may re-evaluate a bounded, current dependency state, but it cannot change the
requested outcome plan, owner identity or semantic operation family. A changed
fingerprint, stale packet, duplicate identity or CAS conflict remains
fail-closed. This contract does not authorize a generic WordPress writer,
direct database write, hard delete, semantic bypass or live fixture mutation.
The canonical direct-writer guard remains `DIRECT_WRITE_BLOCKED`.

## 3. Domain recipe coverage

The compiler uses only registered owners and existing contracts. The following
matrix describes obligation shape, not permission to invent a type:

| Domain | Canonical owner | Governed dependency | Public/frontend rule |
|---|---|---|---|
| Dictionary Entry/Sense | registered Dictionary owner | Knowledge/Source/Evidence as declared | public is conditional; no dictionary truth is created by projection alone |
| Media/MediaAsset/MediaUsage | registered Media owner | Authority and Graph when explicitly bound | public/frontend is conditional unless requested by the Capture |
| Video/VideoSource | registered Video owner | Governance, Media and Graph where declared | required when the request asks for public/player completion |
| Editorial Article | native `wp_post` | registered Authority, Media, Knowledge and Graph dependencies | public editorial URL and frontend read-back are required for publish/public requests |
| Authority | registered Authority owner | Governance and dependency closure | public/frontend is conditional unless the profile contract requests it |
| Knowledge/Source/Evidence | registered Knowledge/Source/Evidence owner | Governance and provenance closure | private semantic dependencies are `NOT_APPLICABLE` to public/frontend with an explicit reason |
| Graph relation | registered Graph edge | both canonical endpoint owners | relation completion is independent from endpoint completion |

Native `wp_posts` remains the sole editorial source of truth. Authority owns
canonical semantic entities; Knowledge owns atomic claims; Source/Evidence
owns provenance; Graph owns relations; Media and Video keep their distinct
boundaries. A Product is never substituted for a physical Specimen.

## 4. Public, frontend and homepage truth

When a request explicitly asks to publish, expose publicly, render in the
frontend, update a public URL or place content on the homepage, the compiler
must emit `REQUIRED` obligations for the requested surfaces. The reducer must
report a blocker until exact canonical read-back proves identity, revision,
route/URL, projection status and the applicable owner policy. A public-capable
owner without a public request remains `CONDITIONAL`; a private semantic
dependency is `NOT_APPLICABLE` with a concrete `NOT_APPLICABLE_REASON` such as
`SEMANTIC_DEPENDENCY_NO_PUBLIC_OWNER`.

Public success is never inferred from MCP tools/list, WordPress ability
registration, connector discovery, a proposal state or a local mock. The
runtime reports these separately: registered, exposed, discoverable,
dispatchable and actually successful.

## 5. Video verifier and client/server parity

`nhk.video.frontend.reconcile` is the canonical read/reconciliation verifier
for a required Video frontend outcome. Server evidence must prove catalog
registration, ability mapping, dispatch mapping and callable handler parity;
client evidence must prove the client can expose and invoke the same
capability. If server parity is true but the client reports `Unknown`, the
result is `CLIENT_EXPOSURE_GAP`, not missing server capability and not a reason
to create a second endpoint. A failed actual call remains a failed actual
call.

## 6. Missing proposal and real-fixture law

The proposal revision advertised for this implementation was not available in
the local checkout and was not fetched, merged or cherry-picked:
`DOCUMENT_UNAVAILABLE` for commit `e4352d280a17410fd5e97b7acf591c332b233e08`.
This contract and the local implementation are therefore evidence for the
current checkout only; they do not claim proposal parity, deployment or
runtime acceptance.

Any future real Video fixture acceptance is read-only until a separately
approved, bounded staging packet exists. Supplied real IDs may be used only for
duplicate/read-only audit and exact canonical read-back. No repository policy
may contain a fixture UUID, stable key, target allowlist or replay packet. No
Video create, replay, replacement, source mutation, Media binding or public
publication is authorized by this document.

## 7. Related active contracts

- `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- `docs/architecture/04_MEDIA_MODEL.md`
- `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- `docs/architecture/11_GRAPH_CORE_CONTRACT.md`
- `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`
- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
