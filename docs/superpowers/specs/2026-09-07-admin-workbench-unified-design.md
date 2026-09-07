# NHK V3 Admin Workbench thống nhất — Thiết kế

## Mục tiêu

Biến Admin NHK V3 thành một workbench dùng được hằng ngày cho Nội dung,
Video, Media, Tri thức và Governance, với một shell trình bày chung và các
adapter domain đọc/điều phối qua service hiện có. Người dùng không phải nhập
UUID proposal/evidence/fingerprint/revision trong workflow thông thường.

## Ranh giới bất biến

- WordPress `wp_posts` vẫn là nguồn sự thật cho nội dung biên tập.
- Authority, Knowledge, Source/Evidence, Graph, Media và Video giữ owner riêng.
- Semantic mutation vẫn đi qua Proposal → Submit → Review/Approve → Eligibility
  → Controlled Apply → canonical read-back → projection/frontend read-back.
- Không thêm semantic type, endpoint type, predicate, operation, writer,
  migration, seed hoặc đường tắt SQL.
- UI là adapter/orchestrator; query dùng repository/application service hiện có.
- Vietnamese-first, HTML ngữ nghĩa, trạng thái empty/error/blocked/unavailable
  trung thực.

## Kiến trúc

`AdminWorkbenchPage` đăng ký bảy khu vực chính: Tổng quan, Nội dung, Media,
Tri thức, Duyệt, Hệ thống và Nâng cao. `AdminShell` cung cấp layout và
navigation. `AdminWorkspaceViewModel` chuẩn hóa outcome/state. Các adapter
`AdminContentAdapter`, `AdminVideoAdapter`, `AdminMediaAdapter`,
`AdminKnowledgeAdapter` và `AdminGovernanceAdapter` chỉ trả về view-model
read-only hoặc gọi REST action đã đăng ký.

Shared presentation gồm `AdminListTable`, `AdminStatusBadge`,
`AdminReadBackPanel`, `AdminTechnicalDetails` và `AdminDetailShell`. Adapter
không render SQL, không tự ghi canonical record và không tự diễn giải một
candidate thành truth.

## Luồng domain

### Nội dung / Video

Nội dung có tab Bài viết và Video. Video list tìm theo title, YouTube ID hoặc
canonical UUID; detail hiển thị player, metadata, provenance, semantic
attachments, Source → Claim → Evidence, Graph, proposal history và trạng thái
canonical/projection/frontend. Guided relation chỉ nhận Video đã chọn, target
Authority và reason tùy chọn; `VideoRelationAdminService` tự resolve proposal
và provenance chain.

### Media

Media list/detail đọc Media, MediaAsset, MediaUsage, attachment mapping và
projection hiện có. Guided attachment dùng `MediaService`/Media REST boundary;
không biến attachment thành semantic identity và không tự tạo Graph edge.

### Tri thức

Một ô tìm kiếm chung điều hướng Entity, Claim, Source, Evidence và Relation.
Detail hiển thị identity/provenance/relations và dùng disclosure cho stable key,
UUID, revision. Không copy claim text vào store khác.

### Duyệt

Queue trình bày proposal bằng các trạng thái người dùng: Chờ duyệt, Đã duyệt,
Sẵn sàng Apply, Đã Apply, Bị chặn, Bị từ chối. Human-readable diff được dựng
từ operation/payload đã tồn tại; raw UUID/fingerprint/payload chỉ ở technical
details. Nút hành động gọi Governance REST hiện có và sau mutation yêu cầu
read-back.

## Read-back contract

Mỗi action hiển thị bốn lớp độc lập: `canonical`, `relation`, `projection`,
`frontend`. `Applied` không đồng nghĩa `Frontend Available`. Runtime failure,
missing data, ambiguity và blocked eligibility là các outcome khác nhau.

Regression bắt buộc: Video `truOChTNbwA`, canonical UUID
`01a07af5-3303-7a73-9f15-b7f675293dc5` phải xuất hiện trong list/detail khi
runtime có record; nếu runtime không có record, UI phải hiển thị unavailable
hoặc empty thật, không seed/fake dữ liệu.

## Kiểm thử

- Unit test cho registry, state mapping, adapters, human-readable diff và
  technical disclosure.
- Static architecture test cấm SQL writer/`save`/`create` trong presentation.
- JS smoke test cho tab/list/detail, status và guided Video flow.
- PHP lint, Composer validation, Unit suite, guarded Integration khi runtime
  khả dụng, `git diff --check` và secret review.

## Không thuộc phạm vi

Không thay đổi public route, không bulk repair/backfill, không migration, không
publish production/demo và không triển khai một semantic aggregator mới.
