# 01 — Scope and Invariants

## 1. Mục tiêu

NHK V3 phải được phát triển theo thứ tự:

1. Structure first.
2. Relationships first.
3. Data later.

Cấu trúc và quan hệ là phần phải ổn định trước khi bàn đến population dữ liệu thực tế.

## 2. V2 chỉ là nguồn tham chiếu đọc

V2 và các hệ thống cũ chỉ được dùng để nghiên cứu:

- cấu trúc thông tin;
- identity và cách nhận diện đối tượng;
- loại quan hệ đã từng tồn tại;
- hành vi UI/UX;
- dữ liệu mẫu phục vụ kiểm chứng mô hình.

Không được suy ra rằng một field, entity type hoặc relation type cũ mặc nhiên có quyền tồn tại trong V3.

Trong giai đoạn constitution, không lập kế hoạch migrate/import/parse body bài viết và không lập kế hoạch population dữ liệu thực tế.

## 3. Bất biến canonical

- Canonical semantic identity thuộc domain authority tương ứng.
- Database surrogate ID chỉ là chi tiết lưu trữ, không thay thế domain identity.
- Stable key là định danh bền vững phụ trợ khi contract có quy định; không được tái sinh âm thầm.
- Alias/legacy key thuộc boundary riêng, không được dùng để tạo duplicate semantic identity.
- Graph edge không chứa body, payload lớn hoặc metadata của domain khác.

## 4. Bất biến quan hệ

- Chỉ một Semantic Graph dùng chung.
- Mỗi quan hệ phải qua predicate registry và endpoint registry.
- Source type, target type, cardinality, self-relation và trạng thái active phải do contract quyết định.
- Không tự nhập predicate tùy ý.
- Không tạo quan hệ tổ tiên giả chỉ để tiện query hoặc hiển thị.
- Direct relation và derived relation phải phân biệt rõ; derived chỉ tồn tại khi policy cho phép.
- Mutation phải idempotent, có revision semantics và fail-closed khi vi phạm contract.

## 5. Bất biến dữ liệu và bằng chứng

- Source là thực thể nguồn khi runtime hỗ trợ.
- Evidence là bằng chứng gắn claim/relation theo contract, không nhét JSON tùy ý vào Graph edge.
- Citation là representation trình bày, không mặc nhiên là canonical entity.
- Thiếu dữ liệu không phải lý do để invent field.
- Thiếu registry không phải lý do để invent type.

## 6. Bất biến triển khai

Implementation hiện tại không có quyền cao hơn Hiến pháp.

Bất cứ phần nào trái Hiến pháp phải được đánh dấu:

`CONSTITUTION_CONFLICT`

Không được che xung đột bằng compatibility code, duplicate truth, mapping ngầm hoặc fallback tự tạo semantic meaning.