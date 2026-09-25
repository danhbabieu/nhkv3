# Capture → One Article → Ordered Media Convergence

## Goal

Sửa pipeline Editorial Capture để một submission tạo đúng một Capture, tạo tối đa một Article cho intent `IMAGE_ARTICLE`, giữ nguyên thứ tự toàn bộ manifest Media, và chỉ báo hoàn tất sau canonical MediaUsage read-back xác nhận.

## Invariants

- Một submission có 1, 3 hoặc 10 ảnh tạo một Capture duy nhất và tối đa một Article.
- Retry cùng Capture/idempotency key không tạo Article thứ hai hoặc MediaUsage duplicate.
- Ảnh usable cùng shared editorial description mặc định route thành `IMAGE_ARTICLE` nếu không có explicit intent ưu tiên.
- Feature binding là nhánh representative tùy chọn; feature rỗng không làm mất Article branch.
- Article body/title/excerpt chỉ lấy từ composed editorial result sau interpretation, subject resolution và Knowledge retrieval/reconciliation.
- Toàn bộ ordered Media manifest được đưa vào Article media plan; không Media nào bị bỏ qua.
- Canonical MediaUsage được apply và read-back trước WordPress projection/read-back.
- `COMPLETE` yêu cầu Article owner, mọi required Media disposition terminal và canonical usage read-back xác nhận; `usages=[]` không thể là complete.
- Feature/Knowledge optional review failure không rollback Media identity hoặc Article MediaUsage hợp lệ.

## Canonical flow

`widget → one submission → one Capture → ordered Media manifest → shared semantic input → intent → interpretation → subject packet → Knowledge retrieval/reuse/review → composition → one Article draft → whole-manifest Media plan → canonical MediaUsage apply/read-back → WordPress projection/read-back → public read-back → completion reducer`.

`MEDIA_ENRICHMENT` remains media-only when explicitly selected. No new entity type, relation, predicate, semantic owner, or generic WordPress writer is introduced.

## Root-cause boundary

The current coordinator can admit/dismiss the minimum owner based on preparation findings before Article creation, creates a raw draft before composed content, invokes deferred editorial preparation before the Article owner is actually composed, and reconciles Media before the composed Article publication unit is finalized. Completion currently aggregates child packets but does not enforce the full Capture manifest’s Article usage read-back as a hard Article-intent invariant.

## Design changes

1. Make the default image+description route explicit in the router while preserving explicit intent precedence and video/knowledge-only rules.
2. Keep subject resolution and Knowledge preparation independent of feature requests; optional feature results are recorded but do not gate Article admission.
3. Compose the Article result before creating a native draft. Create the draft once using `capture_id . ':article'`; retries reuse the persisted Article identity.
4. Pass the entire ordered manifest to ArticleMediaCoordinator. Primary slots and supporting placements receive deterministic terminal dispositions, with canonical usage read-back exposed in the result.
5. Enforce the order canonical usage apply/read-back → WordPress projection/read-back. A missing usage read-back produces a retryable/partial result and completion blocker.
6. Make completion required-owner and disposition checks intent-aware: IMAGE_ARTICLE requires one Article and verified usage for every usable manifest Media; optional feature/Knowledge branches remain isolated.

## Error handling

- Missing/ambiguous subject remains review/block according to the existing preparation contract.
- A feature binding error is retained as a child diagnostic and does not cancel a valid Article branch.
- A MediaUsage apply/read-back error is retryable and prevents `COMPLETE`.
- Article creation failure prevents MediaUsage targeting and prevents Article completion, while preserving already-ingested canonical Media.
- Changed retry payload/idempotency key remains a conflict; unchanged retry resumes the same Capture and Article.

## Verification

Add unit/regression coverage for one, three and ten images; empty feature requests; shared descriptions over 500 characters; one Article on retry; composed body not raw passthrough; full-manifest dispositions; canonical `usages=[]` blocker; Knowledge `NEEDS_REVIEW`; and feature failure isolation. Run focused tests, full Unit suite, PHP lint, `git diff --check`, secret review, and guarded checks without staging/production mutation.
