# 03 — Relationship Constitution

## 1. Một hệ quan hệ duy nhất

Semantic Graph là hệ quan hệ duy nhất của NHK V3. Không tạo bảng hoặc cơ chế relation song song theo từng module nếu mục đích chỉ là biểu diễn cùng một semantic edge.

## 2. Quan hệ phải typed

Mọi edge phải có:

- source endpoint hợp lệ;
- predicate đã đăng ký;
- target endpoint hợp lệ;
- cardinality theo contract;
- active/retired state rõ ràng;
- revision semantics;
- canonical edge identity theo runtime.

Không cho phép free-form predicate từ input.

## 3. Direct và derived

Phải phân biệt:

- direct relation: edge canonical được lưu trực tiếp;
- derived relation: kết quả suy ra từ các edge khác theo policy được phê duyệt.

Không materialize derived ancestor relation chỉ để làm frontend dễ hơn nếu contract không quy định.

Ví dụ: nếu Variant liên hệ Model và Model liên hệ Brand, không tự tạo thêm Variant → Brand edge trừ khi registry/policy xác định đó là direct relation hợp lệ.

## 4. Quan hệ cấu thành và ngữ cảnh

Quan hệ giữa Brand, Model, Variant, Movement, Music, Component, Classification, côn, búa, thùng và mặt số phải giữ đúng cấp semantic.

Không được:

- biến thuộc tính mô tả thành entity chỉ vì muốn query;
- biến entity thành field JSON chỉ vì muốn triển khai nhanh;
- đặt quan hệ ở cấp Brand nếu thực tế chỉ đúng cho một Variant;
- kế thừa dữ liệu xuống cấp con nếu chưa có inheritance policy;
- gộp hai identity khác nhau vì tên hoặc binary giống nhau.

## 5. Provenance và evidence

Quan hệ có nguồn/bằng chứng phải dùng boundary provenance/evidence khi runtime hỗ trợ. Evidence không được nhét tùy ý vào edge payload.

Confidence, direct-vs-derived và inheritance policy phải là khái niệm có contract, không phải cờ tự phát trong module.

## 6. Idempotency và conflict

- Exact active triple phải idempotent theo Graph contract.
- Retired edge không tự hồi sinh nếu không có explicit operation.
- Cardinality conflict phải fail rõ ràng, không âm thầm retire edge cũ.
- Revision mismatch phải là typed conflict.
- Endpoint không tồn tại hoặc predicate không hợp lệ phải fail-closed.

## 7. Không dùng dữ liệu để ép schema

Khi gặp dữ liệu thực tế chưa biểu diễn được bằng registry hiện hành:

1. không invent predicate/type/field;
2. không nhét vào field gần giống;
3. ghi nhận semantic gap;
4. đánh dấu `CONSTITUTION_CONFLICT` nếu implementation đang ép dữ liệu vào cấu trúc sai;
5. chỉ mở rộng registry qua quyết định contract riêng.