# Knowledge Repair Constitutional Amendment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record and implement the approved constitutional addition of `KNOWLEDGE_REPAIR` as a distinct Capture Content Intent for existing-owner Knowledge/Source/Evidence repair, then verify the bounded staging acceptance gates.

**Architecture:** Preserve `KNOWLEDGE_DELTA` semantics for ordinary semantic delta input. Use the existing repair-plan composer, single server signer, Proposal/Approval/Eligibility/Controlled Apply lifecycle and canonical read-back; add only the missing fail-closed guard that prevents repair payloads from being relabeled as `KNOWLEDGE_DELTA`.

**Tech Stack:** PHP 8.5, PHPUnit 11, NHK V3 WordPress plugin, canonical Markdown contracts, existing deployment/operator gates.

**Spec:** Approved amendment proposal in the preceding task and the Constitutional Amendment Record added by Task 1.

## Global Constraints

- `KNOWLEDGE_REPAIR` is distinct from `KNOWLEDGE_DELTA`.
- Repair is limited to existing canonical Knowledge, Source and Evidence owners.
- No generic writer, direct SQL, Governance bypass, Capture retry, hard delete or production operation.
- Staging mutation requires fresh documentation/build parity, exact target read-back, duplicate audit, server-issued signed packet, capability checks and canonical post-apply read-back.
- Public completion remains false until every required Score/Audio/Westminster obligation is verified.

## Review Focus

- A repair plan presented with `KNOWLEDGE_DELTA` must fail closed; test in Task 2.
- The catalog and documentation must expose the same six intents; test in Task 3.
- Five Knowledge retire nodes and Source/Evidence reconciliation must retain separate operation families and dependency closure; existing synthetic coverage is rerun in Task 4.
- Stale revision, expired HMAC packet, missing capability and changed retry payload must remain denied; verify in Task 4.
- Runtime packet absence, target drift or missing public obligations must stop before mutation; verify in Task 5.

### Task 1: Record the approved amendment and align active contracts

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`

- [x] Add the dated approval record, approver role, scope, effective date, compatibility and no-bypass law.
- [x] Add `KNOWLEDGE_REPAIR` to normative Content Intent lists and explicitly retain `KNOWLEDGE_DELTA` meaning.
- [x] Document existing-owner repair, no Article/assets, Capture provenance, operation-family split and signed-packet requirements.
- [x] Update the non-normative status index without treating it as authority.

### Task 2: Add the relabeling guard with TDD

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php`

- [x] Add and run failing tests proving non-repair and heuristic routing reject `repair_plan`/`knowledge_repair` payloads.
- [x] Implement the minimal fail-closed validation.
- [x] Run the router and repair-focused tests.

### Task 3: Regenerate catalog documentation and verify schema parity

**Files:**
- Generated: `public/wp-content/plugins/nhk-core/resources/canonical-docs/**` through the official generator only.
- Test: existing MCP contract/schema parity tests.

- [x] Run `composer generate:mcp-docs`.
- [x] Verify the catalog exposes the six intents and the repair-plan schema.
- [x] Run MCP contract and documentation registry tests.

### Task 4: Run local governed synthetic acceptance

- [x] Run repair composition, dependency admission, router, Governance and Controlled Apply tests.
- [x] Confirm existing synthetic coverage preserves `knowledge_delta` versus `source_evidence_reconciliation` families.
- [x] Confirm revision drift, dependency drift, capability, HMAC expiry, idempotency and retry prohibition remain fail-closed.

### Task 5: Release gate and bounded runtime acceptance

- [x] Run lint, full PHPUnit baseline capture, diff check and secret review.
- [ ] Run the approved staging release gate and verify exact source/build/documentation identity (not run: local-only execution; no server changes authorized).
- [ ] Read-only duplicate audit and exact owner/Capture read-back must pass before packet issuance (not run against server in this local-only execution).
- [ ] Request a fresh server-issued packet; if unavailable or stale, stop with the exact blocker (not requested; server boundary applies).
- [ ] Only with a fresh packet and explicit owner approval run Eligibility and Controlled Apply, then canonical read-back (not run; mutation is outside scope).
- [x] Do not claim Score/Audio/Public Westminster completion unless all required obligations verify.

## Local-only completion ruling

The implementation and local verification are complete. Staging release-gate,
packet issuance, Governance apply and public read-back remain intentionally
unexecuted because this run is explicitly forbidden from changing the server.
