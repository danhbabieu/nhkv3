# NHK V3 — Video intake first-pass quality & Capture subject handoff repair plan

**Status:** PROPOSED / NON-NORMATIVE / NOT IMPLEMENTED  
**Date:** 2026-10-09  
**Scope:** shared intake, Video adapter, Capture orchestration, semantic resolution, governed relations, recovery, publication/read-back.  
**Authority:** `AGENTS.md` → `docs/constitution/READ_FIRST.md` → `docs/constitution/NHK_V3_CONSTITUTION.md` → ACTIVE owning contracts. This document creates **no** new semantic owner, registry vocabulary, storage, bypass or permission.

## 1. Problem statement and runtime evidence

The YouTube Short `kBTZDH4mTv4` was submitted through `nhk.capture.ingest` with VIDEO intent. Runtime: staging, source revision `81b935fe9111e8297aeaa1f6dbddef4e9687eacf`, documentation version `d7beb292daf2729754d9388475ae14c496a29b2e0a90a95a731488df57408375`, manifest `09886ed42382e9f111d0747245dad9d11962251df4f0fa465fa344bd698dcaef`.

Capture `01a11e52-45c0-72dc-b994-cbf550188a59`, Video child `01a11e52-49ba-72ed-9b17-fae4fc0c2660`. Video preview: YouTube source valid, embeddable, source title “「02」Vì sao đồng hồ công cộng phải có bộ máy khổng lồ?”, 9 seconds; `CONTENT_COMPLETE`; resolved subject packet `classification:01a09e44-539a-7f1a-938a-d7d91bb689a3` (“Đồng hồ công cộng”); no semantic attachments. Independent read-only semantic resolve `{classification:"Đồng hồ công cộng"}` returned the exact canonical entity, revision 2, with no ambiguity.

**Persisted Capture read-back:** revision 7, `stage=INTERPRETED`, `capture_status=REVIEW_REQUIRED`, `review.reasons=["PRIMARY_SUBJECT_NOT_RESOLVED"]`, `subject_resolution_packet=null`, `lifecycle_state=RECOVERABLE_INTERRUPTED`, `retry.eligible=false` / `CAPTURE_RETRY_NOT_ALLOWED`, no publication or frontend read-back. The standalone Video read returned null. **This is evidence of a handoff/completion discrepancy, not yet proof of its root cause in code.**

## 2. Design objective

Every input should be assessed **before any governed mutation** against the same shared semantic core and the owning domain's completion rules, with explicit outcomes:

- `READY_FOR_GOVERNED_REVIEW`: valid source, coherent Content Intent, canonical subject packet, non-contradictory hints, required dependencies and proposed relation evidence are complete.
- `NEEDS_INPUT`: user can supply missing topic, model, exact subject, licensed transcript or source locator.
- `NEEDS_EVIDENCE`: canonical subject exists but a registered relation cannot be supported with canonical active Evidence.
- `AMBIGUOUS_SUBJECT` / `SUBJECT_CONFLICT_REVIEW_REQUIRED`: no silent fallback or fabricated identity.
- `SOURCE_UNAVAILABLE`, `DUPLICATE_REUSE`, `CONTENT_NEEDS_REVIEW`, `OUTCOME_UNKNOWN`: distinguish their owners and recovery action.

These are **proposed presentation categories**, not new persisted enums or registry codes. Map them to existing legal statuses and diagnostics unless an approved contract change authorizes additions. First-pass fit means explainable readiness and the right continuation, **not** guaranteed publication of insufficient evidence.

## 3. Shared architecture, not a Video-only parser

`source adapter → Shared Semantic Core / StructuredInterpretationPacket (ephemeral) → canonical resolution → compatibility/contradiction gate → existing Knowledge/Graph discovery → scope/provenance/Evidence eligibility → governed delta planning → domain-specific compose/quality → Governance → owner read-back → public projection/read-back`.

Apply consistently to Video, Article, Media, Knowledge and future adapters. Keep each owner boundary intact. No separate Video semantic resolver, no auto-promotion of YouTube description/transcript/user hint into Knowledge or Evidence, no inference from a thumbnail as a technical fact.

## 4. Required implementation changes (proposals pending code inspection)

### P0 — Single authoritative subject packet
1. Trace `EditorialCaptureCoordinator` and Video child preview from preflight through persistence. Identify whether the resolver runs before/after the Video preview and where `subject_resolution_packet` is serialized.
2. Resolve exactly once in the shared Capture path, with explicit canonical type/id/revision, source locators, confidence and contradiction diagnostics. Preserve the same immutable packet in Video child and relation/enrichment planning. A downstream preview may propose candidates but cannot silently replace or override the Capture packet.
3. On an exact match, persist/read back the packet before advancing. On mismatch, return a typed recoverable diagnostic with the responsible phase. No shadow resolver, silent null, or arbitrary Model/Brand fallback.
4. Regression fixture: exact `classification` subject resolved in preview must not produce `PRIMARY_SUBJECT_NOT_RESOLVED` on Capture read-back unless an explicit contradiction or failed persistence is recorded.

### P0 — Clear continuation/retry state machine
1. Audit `RECOVERABLE_INTERRUPTED` versus `CAPTURE_RETRY_NOT_ALLOWED`; distinguish `continuation`, `resume_children=["video"]`, and transport retry in the existing contract.
2. Recover using original Capture UUID, original idempotency identity and normalized YouTube ID. Rehydrate persisted original request; only accepted execution controls may differ.
3. Reconcile canonical Capture/Video/Graph state before any retry. `OUTCOME_UNKNOWN` is not proof of no write. Never create a second Capture or owner on lost responses.
4. Read-back must surface an actionable next step and a phase-specific blocker, not generic “review required” alone.

### P1 — Intake fitness before submission
1. Validate provider URL, normalized ID, duplicate identity, metadata availability, embeddability, source rights and locale.
2. Parse title/description/transcript/user hints with the shared structured interpretation; label each as source fact, user hint, observed material or canonical claim, retaining locators.
3. Resolve and validate canonical subject (including classification/clock type); check contradictions and ambiguity; inspect registered relation eligibility and Evidence dependencies **before** proposing apply.
4. Produce an operator-visible intake checklist: source OK, subject OK, content OK, Evidence/Graph ready, approval pending, frontend pending; show exact missing items and safe ways to supply them.
5. A high-quality editorial body is not evidence of a supported `Video → about → target` edge.

### P1 — Evidence and relation lifecycle
1. Preserve only registered Video outbound `about`; Hub classification is not a substitute.
2. Every attachment needs non-empty `evidence_refs` of canonical active Evidence UUIDs, with active Claim/Source dependencies, validated at proposal and immediately before apply.
3. If evidence is absent, plan Source/Claim/Evidence through the Capture-owned governed dependency runner when justified; otherwise retain `REVIEW_REQUIRED` with `NEEDS_EVIDENCE`. Never fabricate evidence from marketing copy.
4. Reuse exact existing canonical entities and relation identities; no weak edge-count optimization.

### P1 — Editorial fitness and relevance
1. Compose from source facts and eligible Knowledge with provenance-aware scope; make the video's actual question central. For `kBTZDH4mTv4`, prioritize mechanical load, large bells and robust gear trains supported by the source; avoid padding with loosely related railroad history merely to pass `CONTENT_COMPLETE`.
2. Preserve `CONTENT_COMPLETE` as a **separate** gate; add relevance/coverage diagnostics via existing quality policy rather than replacing its semantics without approval.
3. Repair loop: compose → full quality → bounded repair → regenerate SEO/public projections → full revalidate. Never use generated prose as Evidence.

### P2 — Public verification
Require canonical Video owner read-back, valid Graph attachment, Public Identity, VideoFrontendProjection, detail/archive read-back, and homepage policy-specific read-back. `HTTP 200`, Capture acceptance, preview success and owner existence are individually insufficient.

## 5. Acceptance tests (must be added before deployment)

- **T01** Exact canonical classification in Video preview remains same ID/type/revision in Capture and child; no null packet.
- **T02** Ambiguous title/hints produce explicit review, no silent broadening or writer call.
- **T03** Explicit Variant remains Variant despite broader Model/Brand hints.
- **T04** Valid metadata and `CONTENT_COMPLETE` with missing Evidence remain review-required, not published.
- **T05** Active Evidence + Source + Claim permit registered `about` through Governance only.
- **T06** Existing normalized YouTube ID reuses owner/Capture, including `OUTCOME_UNKNOWN` recovery.
- **T07** `RECOVERABLE_INTERRUPTED` returns a legal continuation or explains non-recoverable conflict with consistent diagnostics.
- **T08** Missing transcript is a diagnostic, not an invented transcript or automatic blocker.
- **T09** Relevant source-specific copy beats unrelated Knowledge padding; technical claims retain source/claim trace.
- **T10** Frontend read-back failure never reports `COMPLETE_VERIFIED`.
- **T11** No duplicate Article, Graph predicate, Knowledge claim, Source/Evidence or WordPress writer.
- **T12** Same behavior across Video, Article and Media adapters at the shared subject-resolution boundary.
- **T13** Unchanged idempotent replay preserves Capture/owner identity and historical documentation checkpoint.
- **T14** No production or unrelated entity mutation in acceptance; no special-case fix for the specific YouTube ID.

## 6. Rollout and safety

1. Inspect exact deployed revision and repository working tree; collect read-only traces for the failing Capture and one known-good Video.
2. Implement shared-core handoff and state-machine corrections behind existing interfaces; update owning ACTIVE contracts only if approved behavior genuinely changes. Do not edit the Constitution to rationalize a bypass.
3. Run focused unit, contract, guarded integration, quality and replay tests; lint/diff checks; report baseline failures separately.
4. On authorized TEST only, exercise synthetic fixtures and read-back. Do not touch production or unrelated canonical objects.
5. Verify build identity/documentation manifest parity after deployment before a bounded real-world canary.
6. Only then continue the existing `kBTZDH4mTv4` Capture, preserving its UUID/idempotency; do not create a fresh submission to hide the failure.

## 7. Definition of done and open questions

**Done:** The input is classified correctly at first pass, subject identity remains stable end-to-end, blockers have typed explanations and lawful continuations, evidence is never bypassed, governed apply and public read-back are proven by tests, and no duplicate records are introduced.

**Still unknown pending code-level investigation:** precise code line causing the null Capture packet; whether retry denial is expected for this specific phase; existence of eligible Evidence for `classification:01a09e44-539a-7f1a-938a-d7d91bb689a3`; whether any Video owner was persisted beyond the current null read-back; whether the editorial relevance weakness is a quality-policy defect.

## 8. Source contracts reviewed

- `AGENTS.md`, `docs/constitution/READ_FIRST.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`
- `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`
- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`

**Status of this file:** implementation design and test specification only. It does not claim code fix, runtime rollout, or successful publication.
