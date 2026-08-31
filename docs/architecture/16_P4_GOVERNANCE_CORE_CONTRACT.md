# NHK V3 P4 Governance Core Contract

## Status

P4 remains `BLOCKED`, not `ACCEPTED`: the persistence/eligibility foundation and Migration 003 schema are present, but controlled transactional apply, durable approval/attempt lifecycle, real MySQL concurrency/rollback/retry coverage, and capability integration are not yet implemented and therefore cannot be certified.

P4 giữ governance ở application/domain boundary, không public mutation endpoint và không phụ thuộc UI.

Proposal phải bind `subject_id`, operation, canonical payload fingerprint, expected revision và dependency-closure fingerprint. Replay cùng binding là idempotent; cùng proposal id với binding khác bị từ chối.

State machine tối thiểu: `draft → approved → applied` hoặc `draft → rejected`. Approval chỉ hợp lệ khi cả content và dependency closure khớp. Apply chỉ hợp lệ khi binding khớp và actual revision bằng expected revision; stale proposal phải fail closed.

Audit là port bắt buộc tùy chọn ở core boundary. Persistence adapter và public transport sẽ được bổ sung sau khi contract này được acceptance bằng integration test.

Migration 003 tạo normalized proposal, dependency, approval, apply-attempt và append-only audit tables. `READY`/`BLOCKED` không được lưu; `ProposalEligibilityService` trả reason codes máy đọc được và kiểm tra approval state, target revision, target existence và dependency closure.
