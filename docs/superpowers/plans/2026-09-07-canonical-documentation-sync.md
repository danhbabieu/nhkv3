# NHK V3 Canonical Documentation Synchronization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Synchronize the canonical NHK V3 Constitution, domain contracts, Admin/MCP guidance, frontend route contracts, and execution state with the implemented Unified Admin Workbench and first-party Video projection behavior.

**Architecture:** Keep one semantic ownership chain: Authority → Graph → canonical projection → frontend. Documentation changes only clarify existing boundaries, route/read-model contracts, privacy projection rules, and verified implementation status; they do not add runtime vocabulary, writers, routes, migrations, or data operations.

**Tech Stack:** Markdown architecture contracts, Constitution, execution ledger, repository search, `git diff --check`.

**Spec:** User-provided request in `/Users/imac24-2125d/.codex/attachments/a583350d-1a96-4f39-8e32-587d6c52edd9/pasted-text.txt`.

## Global Constraints

- Do not modify production code, runtime data, migrations, seeds, Graph rows, or semantic records.
- WordPress remains the editorial presentation/runtime layer; semantic truth remains in canonical V3 owners.
- All semantic mutation follows `Proposal → Submit → Review/Approve → Eligibility → Controlled Apply → canonical read-back`.
- Video’s canonical first-party route is `/video/{slug}/`; external URLs are source/provenance/embed references only.
- Standalone public Image/Media detail routing is not approved by the current Constitution.
- Source/Evidence privacy is enforced at raw payload boundaries; approved public-safe projections may be rendered when their allowlist and eligibility pass.
- Preserve historical execution evidence; add a current status entry rather than rewriting old checkpoints.

### Task 1: Update Constitution and canonical status router

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`

- [x] Add a dated amendment covering the Authority → Graph → canonical projection → frontend chain, Public Identity prerequisites, first-party Video route, Media/Video ownership, Admin control-plane rules, public-safe knowledge projection, and explicit frontend outcome states.
- [x] Align the relevant Constitution sections and acceptance invariants without inventing a new type, predicate, operation, field, or route.
- [x] Update the status index so downstream agents resolve the new current rules before dated evidence.

### Task 2: Update domain contracts and public-route/frontend guidance

**Files:**
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/05_VIDEO_MODEL.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/11_GRAPH_CORE_CONTRACT.md`
- Modify: `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`
- Modify: `docs/architecture/V3_FRONTEND_DESIGN_CONTRACT.md`
- Modify: `docs/architecture/V3_FRONTEND_ROUTE_INVENTORY.md`
- Modify: `docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`
- Modify: `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`
- Modify: `docs/architecture/MEDIA_PUBLIC_ROUTE_DECISION_2026-09-03.md`

- [x] Add concise current-law sections for canonical first-party Video pages, Public Identity allocation/read-back, normalized `metadata.source` compatibility, Graph/public eligibility, privacy-safe knowledge projection, and frontend outcome semantics.
- [x] Add Admin Workbench menu/workspace and guided-form rules, keeping raw IDs/fingerprints/revisions/raw JSON in Advanced/Technical only.
- [x] State explicitly that WordPress attachments/posts are presentation/storage integrations and never semantic authority for Media/Video.
- [x] Reconcile image route wording so Admin Image management is implemented while standalone public Image/Media routing remains unapproved.

### Task 3: Update MCP/Admin control-plane guidance and execution state

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] Record the current Admin menu, guided Video/Media workflow, separate “Xem trên web” and “Mở nguồn gốc” actions, and Governance-only mutation path.
- [x] Record the Video query contract from canonical record through Public Identity, projection, public relations, public-safe knowledge, provenance, and frontend.
- [x] Add the requested implementation status table: Admin Workbench, Admin Video, Admin Image, Video first-party route, Public Identity, public-safe knowledge fallback, private Source/Evidence protection, and Video relation rendering.
- [x] Preserve the existing historical checkpoint entries and label the new status as a current snapshot.

### Task 4: Verify documentation-only change

**Files:**
- Verify: all files modified in Tasks 1–3

- [x] Search for contradictory external-URL destination, WordPress-authority, standalone Image route, private-projection, or direct-writer wording.
- [x] Run `git diff --check`.
- [x] Run a secret scan over the diff and confirm no runtime data/code/migration changes are present.
- [x] Report exact modified files and verification results; do not claim live runtime acceptance beyond the evidence already recorded.
