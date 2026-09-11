# NHK V3 P4 Governance Core Contract

> **NON-NORMATIVE.** This is implementation contract evidence. If it conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Status

P4 implementation and test gates are `ACCEPTED` on `nhk_v3_test`; the final
close still requires the non-destructive Migration003 UP on `nhk_v3`, health
3/3, and release diff/secret review. Evidence is recorded in
`17_P4_ACCEPTANCE_MATRIX.md`.

P4 giữ governance ở application/domain boundary, không public mutation endpoint và không phụ thuộc UI.

## Single entry point for new semantic input — 2026-09-09

Governance remains the only semantic mutation owner, but it is not a parallel
operator intake surface. New semantic intent enters through
`nhk.capture.ingest`, which resolves/retrieves context and produces the
governed write-back packet before Article composition. Direct proposal,
Knowledge, Evidence, Source or relation entry is retained only for
internal/admin lifecycle work with `nhk_internal_content_operations`; it cannot
replace Capture or report a partial submission as complete.

Proposal phải bind `subject_id`, operation, canonical payload fingerprint, expected revision và dependency-closure fingerprint. Replay cùng binding là idempotent; cùng proposal id với binding khác bị từ chối.

Idempotency lookup is fail-closed: if a key row exists but its canonical Proposal
cannot be hydrated/read back, the repository must raise the deterministic
`IDEMPOTENCY_STALE_BINDING` outcome. It must not treat the binding as absent,
retry the insert, reuse the unreadable UUID, or report success. Repair of such a
row is outside the current repository contract and requires a separately
governed atomic repair operation.

State machine tối thiểu: `draft → approved → applied` hoặc `draft → rejected`. Approval chỉ hợp lệ khi cả content và dependency closure khớp. Apply chỉ hợp lệ khi binding khớp và actual revision bằng expected revision; stale proposal phải fail closed.

## Governance Automation Policy — 2026-09-07

Human review is configurable; Governance gates are not. The registered policy
resolver supports only `REVIEW_REQUIRED`, `AUTO_APPROVE` and `AUTO_PUBLISH` and
defaults every missing type to `REVIEW_REQUIRED`. Automation uses the same
proposal, approval, eligibility, controlled-apply and canonical read-back
boundaries; it is not a Governance bypass. `AUTO_APPROVE` stops before Apply,
while `AUTO_PUBLISH` must also prove projection and frontend availability before
reporting publication success. Automated actions use the existing system actor
convention and are auditable separately from human actions.

Conversational Authority adds the bounded setting `OFF`, `REVIEW_REQUIRED` or
`AUTO_APPROVE_AFTER_OWNER_CONFIRMATION`. Its effective mode is the stricter of
the generic Governance policy and this setting: Authority `OFF` blocks apply;
generic review or Authority review remains review-required even if the other
side is automatic. Owner confirmation authorizes only the exact candidate IDs
and plan fingerprint; it never bypasses authentication, capability,
Governance, eligibility, registry or revision checks. Automatic Authority
creation still executes Proposal → Submit → Approve → Eligibility → Controlled
Apply → canonical read-back.

Media file adoption is not a parallel governance bypass: adapters submit to the
canonical governed Media V3 boundary, where idempotency, payload validation,
source-original PRIVATE retention, derivative visibility and cleanup of partial
artifacts are enforced. Attachment creation is an infrastructure projection;
semantic Media identity remains the single governed identity.

Audit là port bắt buộc tùy chọn ở core boundary. Persistence adapter dùng một
append-only shared event store cho Graph, Authority và Governance; public
transport vẫn chưa được expose ở P4.

Migration 003 tạo normalized proposal, dependency, approval, apply-attempt và append-only audit tables. `READY`/`BLOCKED` không được lưu; `ProposalEligibilityService` trả reason codes máy đọc được và kiểm tra approval state, target revision, target existence và dependency closure.

## Current Odo merge runtime evidence — 2026-09-04

The governed operation vocabulary now exposes `rekey` and same-type `merge`,
and the merge executor is wired locally. This supersedes historical
capability-gap wording; it does not claim a live apply. A live proposal-create
diagnostic using pinned-dial source UUID
`32f43d4b-d6c8-4223-a89b-cc47f30cda77` persisted `subject_id="component"`
instead of that UUID. The diagnostic was rejected and no merge/apply or
semantic data mutation occurred. Current blocker:
`PINNED_DIAL_MERGE=BLOCKED` / `LIVE_MERGE_SUBJECT_BINDING_INVALID`.

## Current cross-domain mutation law — 2026-09-07

Video, Media, relation, Claim, Source and Evidence all use the same semantic
mutation sequence: `Proposal → Submit → Review/Approve → Eligibility →
Controlled Apply → canonical read-back`. Admin/MCP are control-plane adapters;
they do not bypass Governance, write directly to semantic tables, create a
duplicate writer or report Apply PASS as frontend success.

Frontend state is reported separately as `Canonical Applied`, `Projection
Available`, `Frontend Available` or `Frontend Blocked`. The last state becomes
Available only after canonical route resolution and read-back succeed.

Visual Support Requirement ledger writes and MediaUsage bindings are
application orchestration, not a new semantic truth writer. They must use the
existing owner services and read-back; a visual relation that would alter
semantic Claim/Source/Evidence/Graph state still requires its normal Proposal,
Governance and eligibility gates. No visual binding may be exposed as Evidence
or Claim merely because its suitability review passed.
