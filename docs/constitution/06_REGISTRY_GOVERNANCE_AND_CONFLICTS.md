# 06 — Registry, Governance and Constitution Conflicts

## 1. Registry is the executable boundary

Runtime registry/contract quyết định type, predicate, field và operation nào thực sự tồn tại.

Không được invent:

- entity type;
- endpoint type;
- predicate;
- relation type;
- canonical field;
- attribute definition;
- operation;
- knowledge profile.

Một khái niệm được mô tả trong tài liệu hoặc xuất hiện trong V2 không đồng nghĩa runtime đã cho phép dùng nó.

## 2. Mở rộng contract

Khi phát hiện semantic gap:

1. mô tả use case và bằng chứng;
2. xác định canonical owner;
3. xác định endpoint source/target;
4. xác định predicate semantics và cardinality;
5. xác định direct hay derived;
6. xác định provenance/evidence requirements;
7. xác định readiness/projection consequences;
8. chỉ sau khi contract được phê duyệt mới implementation và population dữ liệu.

Không đảo thứ tự bằng cách thêm field/table trước rồi hợp thức hóa sau.

## 3. Governance

Durable semantic mutation phải đi qua governed operation đã đăng ký. Không bypass bằng admin shortcut, direct SQL, MCP shortcut hoặc compatibility layer.

Mutation phải bảo toàn:

- identity;
- revision;
- validation;
- idempotency;
- audit semantics;
- permission boundary;
- relation contract.

## 4. CONSTITUTION_CONFLICT

Dùng nhãn `CONSTITUTION_CONFLICT` khi một trong các điều sau xảy ra:

- implementation tạo type/predicate/field ngoài registry;
- implementation lưu relation ngoài Graph;
- implementation duplicate canonical truth;
- implementation đặt semantic fact sai cấp Brand/Model/Variant/Movement/Specimen;
- implementation gộp Media và Video identity;
- implementation dùng Product thay identity Specimen;
- implementation lập hoặc kích hoạt migration/import body bài viết trái phạm vi hiện tại;
- architecture document cũ mâu thuẫn với Hiến pháp;
- runtime contract hiện hành không đủ để biểu diễn một cấu trúc đã được xác nhận.

## 5. Cách xử lý conflict

Khi gắn `CONSTITUTION_CONFLICT`, phải ghi tối thiểu:

- file/module/operation liên quan;
- điều khoản Hiến pháp bị vi phạm;
- registry/contract hiện hành;
- hành vi thực tế;
- rủi ro semantic/identity;
- quyết định cần có để giải quyết.

Không tự sửa Hiến pháp để hợp thức hóa implementation.

## 6. Quy tắc cho Codex

Mỗi phiên Codex phải:

1. đọc `docs/constitution/READ_FIRST.md` trước mọi task;
2. kiểm tra task có chạm structure/relationship/identity hay không;
3. kiểm tra runtime registry/contract trước khi đề xuất type/predicate/field;
4. ưu tiên sửa implementation theo Hiến pháp;
5. gắn `CONSTITUTION_CONFLICT` nếu không thể đồng thời thỏa runtime contract và Hiến pháp;
6. không khởi tạo kế hoạch migration/import body bài viết trong phạm vi hiện tại.