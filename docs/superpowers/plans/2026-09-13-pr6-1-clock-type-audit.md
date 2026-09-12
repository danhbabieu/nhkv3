# PR6.1 Production Read-Only Clock-Type Audit Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the preliminary PR6 bridge with a bounded, deterministic, production-owner read audit that can execute a real read-only Clock-Type dry-run when the target exposes the surface.

**Architecture:** `ClockTypeClassificationAudit` remains a pure analysis service over read-only ports. Authority records are streamed in deterministic per-type cursor pages and classified targets are read from canonical payload family values. Knowledge support is resolved through canonical Claim → Evidence → Source repositories with exact subject/scope/revision checks and safe summaries only. A read-only maintenance operation composes the existing owners and returns a typed unavailable/not-exposed result without any writer dependency.

**Tech Stack:** PHP 8+, PHPUnit, WordPress/WPDB adapters, existing NHK Core repositories, existing maintenance CLI/runtime hooks.

**Spec:** User-provided PR6.1 requirements and `docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md` §§14–16.

## Global Constraints

- No Proposal, Governance, Graph mutation, Authority mutation, Knowledge/Source/Evidence mutation, Media/Video mutation, migration, backfill, normalization, slug allocation or Article mutation.
- Only `model`, `variant`, `specimen` and `product` are audit sources for `classified_as`.
- `family=clock_type` is canonical; `family=clock-type` is compatibility-only; family is never inferred from stable key/name/title.
- `ClockTypeClassificationAudit` may depend only on read-only interfaces/services and never on Governance or writer ports.
- Every report fingerprint and semantic sample is deterministic and timestamp-free; private/hidden Evidence payload text never leaves the adapter.
- Existing PR5 derived Brand↔Clock-Type recipe and Video behavior remain unchanged.

---

### Task 1: Canonical Knowledge/Evidence support resolver

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Audit/ClockTypeAuditEvidenceReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/CanonicalKnowledgeEvidenceAuditReader.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CanonicalKnowledgeEvidenceAuditReaderTest.php`

**Interfaces:**
- Consumes: existing `KnowledgeRepository`, `EvidenceRepository`, `SourceRepository` read methods and canonical domain objects.
- Produces: `findForSubject(string $sourceType, string $sourceUuid): list<array<string,mixed>>` rows containing only canonical IDs/revisions, exact scope, target UUID, tier, provenance class, support state and safe support summary.

- [ ] Write failing tests for supported exact subject, wrong subject, scope mismatch, inactive Claim, inactive Source, inactive/unusable Evidence and private payload redaction.
- [ ] Run the focused PHPUnit file and verify failures are caused by missing canonical-chain behavior.
- [ ] Implement the minimal resolver: enumerate canonical Claims, validate canonical subject fields from the approved claim representation, follow each Claim’s Evidence rows to canonical Source, require active `supports` Evidence and active Source, and emit no excerpts/raw metadata.
- [ ] Run the focused test file and then the existing Knowledge unit tests.
- [ ] Refactor only after green; preserve repository owner boundaries and no write calls.

### Task 2: Bounded Authority inventory and target buckets

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Authority/CursorAuthorityInventoryReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Authority/WpdbAuthorityRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/ClockTypeClassificationAudit.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeClassificationAuditTest.php`

**Interfaces:**
- Consumes: `pageByType(type, limit, after, includeRetired)` read-only pages ordered by canonical UUID.
- Produces: bounded source-page audit results with `batch_size`, `cursor`, `next_cursor`, `records_read`, `completed`, and target inventory buckets `CANONICAL_CLOCK_TYPE`, `LEGACY_CLOCK_TYPE`, `OTHER_FAMILY`, `FAMILY_MISSING`, `FAMILY_UNRESOLVED`, `INACTIVE`.

- [ ] Add failing tests proving all four source types paginate without eager whole-dataset loading, cursor replay is deterministic, inactive rows are filtered when requested, and canonical payload family controls classification.
- [ ] Add failing audit tests for exact evidence READY, hint-only, brandless source, ambiguous targets, legacy target, wrong family, no signal and unavailable dependencies.
- [ ] Run focused tests and confirm expected RED failures.
- [ ] Implement one bounded page per audit invocation; keep target inventory bounded/read-only, sort stable results, and never use stable-key family inference.
- [ ] Run focused tests and the PR5 graph/derived regression suite.
- [ ] Refactor while preserving the existing status vocabulary and explicit failure states.

### Task 3: Read-only dry-run surface and report contract

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Audit/WpdbClockTypeClassificationAuditFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/bin/nhk-core-maintenance.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/ClockTypeClassificationAuditReport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeClassificationAuditTest.php`
- Test: add the narrowest maintenance/runtime contract test already used by the repository

**Interfaces:**
- Consumes: existing WordPress bootstrap and factory composition; no `ProposalRepository`, `ControlledApplyService`, Governance service or writer adapter.
- Produces: allowlisted read-only audit operation returning `LIVE_AUDIT_SURFACE_NOT_EXPOSED` when the callable target surface is absent, otherwise the bounded report and safe samples.

- [ ] Add failing tests for report fields, sample cap, timestamp-free fingerprint, no-write dependency graph and typed not-exposed behavior.
- [ ] Run the focused tests and verify RED.
- [ ] Implement the read-only operation/factory wiring and keep the existing maintenance allowlist’s write operations separate and unreachable from the audit branch.
- [ ] Run focused tests, PHP lint and command-level dry-run help/blocked-surface checks.
- [ ] Refactor only after green; do not broaden MCP catalog or add PR7 operations.

### Task 4: Fresh verification and checkpoint documentation

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/CLOCK_TYPE_PR1_GAP_REPORT.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` only if the active contract/runtime index requires a precise update

- [ ] Run the full relevant Unit suite, PHP lint, `git diff --check`, secret review and existing PR5/Video regressions.
- [ ] Re-read fresh documentation bootstrap, environment, `classified_as` inventory and target capability; run the real bounded dry-run only if the target exposes the new read-only surface.
- [ ] Record separate `DOCUMENTATION_CHECKPOINT_MATCH`, `CODE-SIDE IMPLEMENTATION`, `LIVE READ SURFACE AVAILABLE`, `REAL DRY-RUN EXECUTED` outcomes and exact counts or typed gap.
- [ ] Confirm no mutation before/after and do not claim PR7 readiness unless the acceptance criteria explicitly permit it; PR7 remains `NOT_READY`.

