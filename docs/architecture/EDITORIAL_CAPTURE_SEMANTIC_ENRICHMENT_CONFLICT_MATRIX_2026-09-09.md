# Editorial Capture & Semantic Enrichment — Conflict Matrix

**Date:** 2026-09-09
**Purpose:** implementation preflight for the owner-approved handoff.
**Authority split:** the handoff supplies product intent and locked invariants;
the Constitution, current contracts, executable registries and fresh runtime
evidence control implementation detail and capability claims.

This is an evidence matrix, not a new policy or vocabulary source. `MATCH` and
`PARTIAL` describe the current checkout; they do not authorize data mutation or
publication. `CONFLICT` is reserved for a product invariant that cannot be
preserved by a compatible implementation boundary.

| Handoff requirement | Current canonical owner | Current code/runtime evidence | Status | Action |
|---|---|---|---|---|
| One submission is one Capture and defaults to one new Article | `ARTICLE_INGEST_CONTRACT.md`, `01_EDITORIAL_CONTENT_BOUNDARY.md` | `nhk.article.ingest` currently reconciles an existing `wp_post`; typed draft creation is a separate native writer | PARTIAL | Add a separate Capture orchestration boundary; preserve existing Article Ingest semantics and do not overload its operation. |
| N assets produce N attachments/Media and one Article | `04_MEDIA_MODEL.md`, `22_P6_MEDIA_VIDEO_FOUNDATION.md` | `nhk.media.upload-batch` and `MediaBatchUploadService` support ordered 1..N attachment transport; no Capture/Article binding exists | PARTIAL | Reuse batch upload and governed Media adoption, then bind one Capture to one draft and N media references. |
| Physical storage/read-back precedes deep research | Media contracts and Article boundary | Native file-aware attachment ingest/read-back exists; current Article preflight is existing-Post oriented | PARTIAL | Keep physical transport independent and make Capture sequencing explicit; do not weaken existing preflight contract. |
| Text-only input still uses the semantic core | Handoff; `ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md` | Article research accepts text, but no generic Capture semantic package exists | PARTIAL | Implement input adapter/core package with zero-asset support. |
| Text is editorial intent, claim candidates and relation hints | `06_KNOWLEDGE_SOURCE_MODEL.md`, `GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` | Knowledge planner handles bounded candidates; no generic interpreter DTO/adapter exists | PARTIAL | Add candidate-only interpretation with explicit provenance/scope; route writes through Governance. |
| Search/reuse before create | `ARTICLE_INGEST_CONTRACT.md`, `GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`, runtime registries | `McpSemanticContextResolver`, canonical inventory and current-truth services exist | MATCH for existing seams | Compose Capture resolution from those read-only boundaries; no duplicate writer. |
| Graph is bounded discovery, not automatic permission to use Claims | `11_GRAPH_CORE_CONTRACT.md`, claim projection contracts | `SemanticNeighborhoodQuery` and two-hop projection policy exist; no Capture Claim Retrieval Engine exists | PARTIAL | Add deterministic retrieval over approved neighborhood/read ports with scope/evidence/relevance decisions. |
| Claim trace stores ID/revision/role/context/snapshot | `06_KNOWLEDGE_SOURCE_MODEL.md`, Article contracts | `ArticleOperationReceipt` is body-free and has diagnostics; no Article Claim Usage/snapshot boundary exists | MISSING | Add a body-free trace contract owned by Capture/article orchestration; do not copy Article body or create semantic truth. |
| Article composition combines user input, observations and selected Claims | Article contracts; WordPress editorial boundary | Typed draft gateway writes native drafts; no reusable composer exists | PARTIAL | Add deterministic composer returning editorial fields plus machine-readable trace; publication remains native/gated. |
| Semantic writes use Proposal → Apply → read-back | Governance contracts and `McpGovernanceHandler` | Governance lifecycle and controlled apply are wired | MATCH for existing writes | Capture may only emit/execute registered governed proposals; no direct DB writes. |
| MediaUsage/representative reconciliation uses existing registry roles | Media model/SEO contracts and runtime registries | `MediaUsageRoleRegistry`, `ArticleMediaCoordinator`, representative policy exist | PARTIAL | Reuse role registry and coordinator; add Capture-level candidate reconciliation without inventing roles. |
| Retry resumes verified checkpoints without duplicate state | `18_GOVERNANCE_FAILURE_AND_RETRY.md`, Article receipt contract | Article/media idempotency exists; no Capture receipt/state persistence exists | PARTIAL | Add a durable Capture receipt/checkpoint boundary, with deterministic fingerprint conflict and per-phase diagnostics. |
| File-aware upload is primary; base64 is fallback | `04_MEDIA_MODEL.md`, `22_P6_MEDIA_VIDEO_FOUNDATION.md`, MCP content operations | Multipart `nhk.media.upload-batch` is catalogued and code-side/runtime-auth boundary is present | MATCH | Keep multipart primary; expose Capture using native file parts and never encode large files into JSON. |
| Public Article/image resources stay separate and publication is fail-closed | Article/Media SEO and URL contracts | Native draft/publication gate and canonical `/anh/` delivery boundary exist; live public read-back is runtime-gated | MATCH | Reuse publication/SEO gates; never equate upload or draft success with COMPLETE. |
| Future Video/text/Product/etc. reuse the semantic core | Handoff; current Authority/Video/Knowledge contracts | Existing Video and Knowledge planners are vertical seams; no shared package | PARTIAL | Keep adapters at interpretation/observation edge and make resolution/retrieval/composition ports generic. |
| MCP canonical documentation describes the implemented architecture and examples | `MCP_V3_CONTENT_OPERATIONS.md`, control plane, status index | MCP docs already describe universal post-ingest reconciliation and multipart Media, but not Capture lifecycle | PARTIAL | Reconcile owning MCP docs, index and execution state after each accepted implementation slice. |

## Conflict decision

No unresolved product-invariant conflict is required to begin a compatible
implementation. The current Article contract is intentionally preserved by
making Capture a distinct orchestration boundary. If implementation would need
to change the existing `nhk.article.ingest` meaning, introduce an unregistered
predicate/type/role, bypass Governance, or persist a second Article body, stop
and request owner direction.

## Runtime caveat

Focused unit evidence for the preflight baseline is green (`15 tests / 48
assertions`). This matrix does not claim fresh authenticated multipart,
Capture, end-to-end WordPress, public-route or deployment acceptance. Those are
implementation and runtime gates for later checkpoints.
