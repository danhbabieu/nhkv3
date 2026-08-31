# NHK V3 P4 Governance Core Contract

## Status

P4 implementation and test gates are `ACCEPTED` on `nhk_v3_test`; the final
close still requires the non-destructive Migration003 UP on `nhk_v3`, health
3/3, and release diff/secret review. Evidence is recorded in
`17_P4_ACCEPTANCE_MATRIX.md`.

P4 giữ governance ở application/domain boundary, không public mutation endpoint và không phụ thuộc UI.

Proposal phải bind `subject_id`, operation, canonical payload fingerprint, expected revision và dependency-closure fingerprint. Replay cùng binding là idempotent; cùng proposal id với binding khác bị từ chối.

State machine tối thiểu: `draft → approved → applied` hoặc `draft → rejected`. Approval chỉ hợp lệ khi cả content và dependency closure khớp. Apply chỉ hợp lệ khi binding khớp và actual revision bằng expected revision; stale proposal phải fail closed.

Audit là port bắt buộc tùy chọn ở core boundary. Persistence adapter dùng một
append-only shared event store cho Graph, Authority và Governance; public
transport vẫn chưa được expose ở P4.

Migration 003 tạo normalized proposal, dependency, approval, apply-attempt và append-only audit tables. `READY`/`BLOCKED` không được lưu; `ProposalEligibilityService` trả reason codes máy đọc được và kiểm tra approval state, target revision, target existence và dependency closure.
