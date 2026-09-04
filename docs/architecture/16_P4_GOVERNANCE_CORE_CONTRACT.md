# NHK V3 P4 Governance Core Contract

> **NON-NORMATIVE.** This is implementation contract evidence. If it conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Status

P4 implementation and test gates are `ACCEPTED` on `nhk_v3_test`; the final
close still requires the non-destructive Migration003 UP on `nhk_v3`, health
3/3, and release diff/secret review. Evidence is recorded in
`17_P4_ACCEPTANCE_MATRIX.md`.

P4 giữ governance ở application/domain boundary, không public mutation endpoint và không phụ thuộc UI.

Proposal phải bind `subject_id`, operation, canonical payload fingerprint, expected revision và dependency-closure fingerprint. Replay cùng binding là idempotent; cùng proposal id với binding khác bị từ chối.

State machine tối thiểu: `draft → approved → applied` hoặc `draft → rejected`. Approval chỉ hợp lệ khi cả content và dependency closure khớp. Apply chỉ hợp lệ khi binding khớp và actual revision bằng expected revision; stale proposal phải fail closed.

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
