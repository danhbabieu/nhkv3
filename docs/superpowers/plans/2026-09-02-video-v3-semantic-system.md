# Video V3 Semantic System — TDD Plan

## Goal

Complete the YouTube-first Video workflow while preserving the sole
Constitution, WordPress editorial boundary, Graph ownership and Governance.
No real video import, legacy repair, production write or automatic publish is
part of this plan.

## Phases and gates

0. Constitution/runtime audit — read the Constitution, registries, Video,
   Media/Image, Knowledge/Source, MCP, SEO, frontend and execution-state
   contracts; record `IMPLEMENTATION_GAP` and `CONSTITUTION_CONFLICT`.
1. Video identity/source contract — failing tests for watch/short/embed/shortlink,
   playlist IDs, tracking removal, malformed/non-YouTube rejection and duplicate
   external identity.
2. YouTube adapter — isolate URL parsing and official API client; bound text,
   timeout and host; test available/private/deleted/embed-disabled/API failure.
3. Snapshot/transcript/rights — test deterministic snapshot hashing and the
   three transcript policies; absence remains a warning.
4. Intake pipeline — test duplicate update/reconcile, hint provenance,
   idempotency packet and no direct Authority write.
5. Internal lookup/relation planner — test canonical UUID resolution,
   ambiguity, registered predicate/evidence, direct deduplication and no fake
   entity creation.
6. Hub classifier — test one primary, bounded secondary values and unresolved
   classification without WordPress taxonomy fallback.
7. Editorial package — test NHK synthesis differs from source description and
   preserves source/user/NHK provenance.
8. Completeness gate — test source/embed/editorial/category/provenance and
   semantic attachment blockers; transcript is not a blocker.
9. SEO projection — test canonical metadata, VideoObject mapping, safe
   thumbnail/duration and no hallucinated Clip; add video sitemap projection.
10. Watch page/related projection — use the shared query boundary, direct before
    derived and two-hop cap; unavailable external source keeps NHK page honest.
11. MCP workflow — extend existing `nhk.video.ingest`, capability gate,
    one-shot preview, Proposal-only behavior and retry-safe idempotency.
12. Sync/reconciliation — compare snapshots and create review signal/proposal;
    never overwrite NHK fields or erase history.
13. Security/performance — reject SSRF/arbitrary hosts, bound payloads, avoid
    page-time API/research fetches and keep local projection reads bounded.
14. Integration/wire smoke — verify actual WordPress/MCP wire, permissions,
    schema, execution and governed apply when DB/server are available.
15. Constitutional regression — unit/integration/lint/diff/secret review,
    parity read-through, execution-state update and `READY_FOR_RUNTIME_PROBE`.

## Current checkpoint

Phases 1–3, 4 (deterministic core), 6–9, 11 (MCP packet seam), 12 (read-only
comparison) and part of 10 are implemented in the current working tree with
unit evidence. Remaining gaps are the shared two-hop direction-aware related
engine, fully normalized persistent Video source/editorial fields, a dedicated
MCP sync-preview method, populated live Video fixture, and live wire/SEO/sitemap
verification. These gaps are not hidden by fallback behavior.
