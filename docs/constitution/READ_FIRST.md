# NHK V3 Structure & Relationship Constitution — READ FIRST

Tài liệu trong thư mục này là luật kiến trúc cấp cao cho NHK V3 về **cấu trúc**, **quan hệ** và **bất biến hệ thống**.

## Nguyên tắc tối cao

> Structure first. Relationships first. Data later.

Mọi phiên Codex phải đọc tài liệu này trước khi phân tích, thiết kế hoặc sửa implementation.

## Phạm vi

Hiến pháp này điều chỉnh:

- cấu trúc semantic domain;
- canonical identity và ranh giới authority;
- typed relationships và graph contract;
- Brand backbone;
- quan hệ Brand / Model / Variant / Movement / Music / Component / Classification;
- cấu trúc côn, búa, thùng, mặt số và các thành phần kỹ thuật khi runtime đã đăng ký;
- Media, Video, Knowledge, Source/Evidence, Post, Specimen, Product;
- projection, SEO/URL, Frontend/Admin và MCP ở mức cấu trúc/quan hệ;
- governance, registry/contract và cách xử lý xung đột.

Hiến pháp này **không** là kế hoạch population dữ liệu, migrate/import nội dung, hoặc sao chép body bài viết từ hệ thống cũ.

## Luật bắt buộc

1. Không lập kế hoạch migrate/import/parse body bài viết sang V3 trong phạm vi constitution.
2. Không tạo mới entity type, predicate, relation type hoặc field nếu runtime registry/contract chưa đăng ký.
3. Không dùng dữ liệu mẫu hoặc kiến trúc cũ để hợp thức hóa một loại quan hệ chưa có trong runtime.
4. Brand là semantic backbone: các miền Model, Variant, Movement, Music, Component, Classification và nội dung liên quan phải giữ được ngữ cảnh Brand theo contract hợp lệ, trực tiếp hoặc qua quan hệ dẫn xuất được policy cho phép.
5. Graph là hệ quan hệ duy nhất; không tạo relation persistence song song.
6. Quan hệ phải typed, có source/target hợp lệ, cardinality rõ ràng, identity ổn định, provenance/evidence khi contract yêu cầu và fail-closed khi không chắc chắn.
7. Media và Video chia sẻ semantic relation primitives nhưng không chia sẻ canonical identity hay persistence.
8. Specimen là hiện vật vật lý cụ thể; Product là listing/offer và không được dùng làm identity thay cho Specimen.
9. Post giữ boundary biên tập/URL theo runtime hiện hành; semantic domain không được nhét body vào Graph edge.
10. Nếu implementation, migration, tài liệu hoặc đề xuất trái Hiến pháp này, phải đánh dấu rõ `CONSTITUTION_CONFLICT` và dừng việc coi phần đó là hợp lệ cho đến khi được giải quyết.

## Thứ tự ưu tiên khi có mâu thuẫn

1. Runtime registry/contract hiện hành.
2. Bộ Hiến pháp trong `docs/constitution/`.
3. Các architecture decision đã khóa và không trái Hiến pháp.
4. Implementation hiện tại.
5. Tài liệu cũ, dữ liệu mẫu, hệ thống V2 hoặc suy luận từ UI.

Nếu mục 1 và mục 2 mâu thuẫn, không tự ý sửa contract hoặc invent khái niệm mới. Gắn `CONSTITUTION_CONFLICT`, chỉ ra chính xác registry/contract nào liên quan và yêu cầu quyết định kiến trúc trước khi tiếp tục.

## Bộ tài liệu

- `01_SCOPE_AND_INVARIANTS.md`
- `02_DOMAIN_STRUCTURE.md`
- `03_RELATIONSHIP_CONSTITUTION.md`
- `04_BRAND_BACKBONE.md`
- `05_BOUNDARIES_AND_PROJECTIONS.md`
- `06_REGISTRY_GOVERNANCE_AND_CONFLICTS.md`

Mọi tài liệu con phải được đọc cùng file này, không tách riêng khỏi nguyên tắc tối cao.