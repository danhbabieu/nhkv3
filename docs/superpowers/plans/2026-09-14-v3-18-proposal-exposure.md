# V3-18 Proposal Approve/Apply Exposure Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Make the existing NHK V3 `nhk.proposal.approve` and `nhk.proposal.apply` actions discoverable and callable through the Easy MCP connector, then complete the governed lifecycle for the existing Đồng hồ tháp Proposal only.

**Architecture:** Treat runtime registration, WordPress Ability registration, Easy MCP enabled state, and serialized `tools/list` exposure as separate gates. Preserve the existing `McpAbilityRegistration` bridge and canonical `McpTransport`/Governance path; change only the exact exposure/reconciliation boundary if fresh live evidence proves it is missing.

**Tech Stack:** PHP 8+, WordPress Abilities API, NHK V3 MCP catalog/transport, Easy MCP AI 1.7.17, PHPUnit, repository deployment/read-back tooling.

**Spec:** User request `NHK V3 — ENABLE @v3-18 PROPOSAL APPROVE/APPLY END-TO-END` and `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`.

## Global Constraints

- Do not create a Proposal or Capture; use Proposal `01a09ef0-6b3c-78c9-bb15-e8c0e1cd7ebc` only.
- Do not bypass Governance, generic WordPress writers, generic relation writers, capability guards, approval policy, Proposal schema, Controlled Apply, or `internal_admin_only`.
- Do not enable `proposal-create` unless fresh evidence proves it is required; do not change semantic data outside the governed Proposal lifecycle.
- Keep `nhk_internal_content_operations`, existing proposal capabilities, canonical MCP delegation, and duplicate-free strict schemas intact.
- Do not touch frontend, Public URL, Media, Article, Video, Knowledge, subtype relations, `#485`, or `#487`.

### Task 1: Fresh runtime and connector inspection

**Files:**
- Read: `AGENTS.md`, `docs/constitution/READ_FIRST.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`, `docs/architecture/V3_EXECUTION_STATE.md`, `docs/architecture/V2_V3_PARITY_MATRIX.md`
- Inspect: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`, `McpToolCatalog.php`, `McpTransport.php`, `Plugin.php`

- [ ] Bootstrap documentation through the target MCP connection and record the deployed documentation/runtime identity.
- [ ] Verify `https://demo.1945.vn`, active NHK Core and Easy MCP versions, and the actual `initialize`/`tools/list` response.
- [ ] Record independent values for runtime catalog, WordPress Ability registry, Easy MCP enabled option, and serialized connector descriptors for submit/review/approve/eligibility/apply.
- [ ] Read exact existing Proposal state and fingerprints with read-only governed operations; do not create or alter anything during inspection.

### Task 2: Minimal exposure repair and focused verification

**Files:**
- Modify only if required: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`, `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpProposalLifecycleExposureTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`, `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`
- Update only if required: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Add or adjust a failing focused assertion only for a proven exposure gap: explicit Easy MCP opt-in, enabled-state reconciliation, projection ordering/serialization, or duplicate prevention.
- [ ] Run the focused test before implementation and confirm the failure identifies the live/code gap.
- [ ] Implement the smallest fix while preserving `internal_admin_only`, `nhk_internal_content_operations`, per-action capabilities, canonical transport delegation, strict schemas, and exclusion of `proposal-create`.
- [ ] Run focused unit/contract/guard tests, PHP lint, `git diff --check`, and secret review.

### Task 3: Deploy/read back and execute existing governed Proposal

**Files:**
- Update: `docs/architecture/V3_EXECUTION_STATE.md` only with evidence and checkpoint status

- [ ] If local verified HEAD differs from live, use the repository’s canonical deployment workflow and stop with `DEPLOYMENT_AUTHORIZATION_REQUIRED` if authorization is required.
- [ ] Freshly repeat live `initialize`/`tools/list` and connector capability checks; report whether reconnect is required.
- [ ] Call `proposal.review`, `proposal.approve` with the exact returned fingerprints, and `proposal.eligibility` for the existing Proposal.
- [ ] Call `proposal.apply` only when eligibility is `ready=true`; otherwise stop at the typed blocker.
- [ ] Read back the canonical Classification by stable key and verify duplicate count without creating any additional object, then run the final verification gate.
