# Universal Public Acceptance Execution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute the attached G0–G5 acceptance runbook read-only where authorization/runtime is absent, repair only demonstrated shared defects, and leave Westminster with an evidence-backed public readiness result.

**Architecture:** Constitution and the current ACTIVE documentation registry are authoritative. Runtime discovery, canonical owner read-back, Governance and public/frontend verification are separate gates; no local fixture or WordPress post substitutes for a canonical Music owner.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, local canonical documentation snapshot, existing Governance/Capture/Knowledge/Media/Public dossier services.

**Spec:** `/Users/imac24-2125d/Downloads/NHK_V3_Universal_Public_Acceptance_Execution.md`

## Global Constraints

- No deploy, push, migration, seed, backfill, direct SQL/DB write, hard delete or semantic mutation.
- Missing signed Capture-bound acceptance packet is `AUTHORIZATION_BLOCKED`; continue read-only only.
- Existing canonical owner IDs, revisions, Capture IDs and Governance receipts are never replayed or replaced.
- Local PASS, Proposal Applied, synthetic WAVs and article 322 do not prove public completion.
- Public completion requires canonical read-back and independent URL/frontend read-back.

## Review Focus

- Quality-audit infrastructure failure must remain distinguishable from an empty corpus; test the explicit diagnostic and correlation boundary.
- Source locators, evidence excerpts, process instructions, media metadata, inference and dictionary cues must not become Knowledge facts; test the nine Authority types through the same guard.
- A retryable Capture must persist the current dependency fingerprint and allow at most one policy-change reevaluation; test unchanged retry denial and immutable blockers.
- Music score/audio/rights readiness must not expose local research assets; test generic dossier behavior without Westminster-specific branches.
- Documentation/runtime and public URL verification must fail closed when the target runtime or connector is unavailable.

### Task 1: G0 documentation/runtime and permission evidence

**Files:**
- Read: `AGENTS.md`, Constitution chain, all registry ACTIVE entries, attached runbook.
- Verify: `public/wp-content/plugins/nhk-core/resources/canonical-docs/manifest.json`, runtime registry/catalog and `docs/architecture/V3_EXECUTION_STATE.md`.
- Record: `.superpowers/sdd/universal-public-acceptance-execution/progress.md`.

- [x] Capture HEAD, source revision, documentation version, manifest hash, runtime identity, connector/capability and mutation-scope evidence.
- [x] Run read-only preflight and record exact blockers.
- [x] Regenerate the ignored local docs snapshot only if parity requires it; never claim deployment.

### Task 2: G1 shared classifier and Knowledge quality-audit integrity

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/KnowledgeQualityAuditHandler.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeQualityAuditMcpTest.php` and existing interpreter/guard tests.

- [x] Write and run a failing test proving coordinator failure returns explicit internal diagnostics/correlation, not an indistinguishable empty corpus.
- [x] Implement the smallest privacy-safe diagnostic response and logging hook at the MCP adapter boundary.
- [x] Run the nine-type classifier/guard matrix and confirm source/process/metadata/inference inputs remain non-factual or review-only.

### Task 3: G2–G3 read-only Knowledge repair and Capture reconciliation

**Files:**
- Read/verify: Knowledge/Governance/Capture services, six canonical claim IDs if runtime is available.
- Test: Capture fingerprint/reducer/convergence/semantic-core suites.
- Record: acceptance ledger and exact repair actions; no live mutation without packet.

- [x] Produce the bounded per-ID repair decision rule; exact six IDs remain unavailable, so no per-ID mutation plan is invented.
- [x] Verify current code's policy-version fingerprint, retryable-failure persistence, no-progress lock, idempotency and CAS gates with focused tests.
- [x] If a regression fails, use TDD and fix only the demonstrated shared boundary; do not replay applied proposals.

### Task 4: G4 Westminster Music dossier and governed media readiness

**Files:**
- Read/verify: `MUSIC_DATA_COLLECTION_STANDARD.md`, Media/SEO/dossier contracts and generic Music projection.
- Test: generic Music dossier, score/audio contract and frontend suites.

- [x] Verify score edition/rights/audio states as non-canonical unless canonical MediaAsset and rights read-back exist.
- [x] Prove Westminster, Sonodo and Ave Maria use the same profile-driven renderer with no record-specific branch.
- [x] Do not submit/apply synthetic assets without a signed packet and exact owner/revision closure.

### Task 5: G5 public and independent outcome verification

**Files:**
- Verify: `/ban-nhac/westminster/`, canonical API/MCP read surfaces, route/SEO checks.
- Record: machine-checkable `ACCEPTANCE_LEDGER` fields from the runbook.

- [x] Attempt unauthenticated public read-back only when network/runtime permits; otherwise record `FRONTEND_READBACK_UNVERIFIED` for deployed-build identity.
- [x] Reconcile required obligations and cross-entity regressions.
- [x] Finish with `PARTIAL / BLOCKED` with one next action because mandatory runtime/packet/build gates are not evidenced.
