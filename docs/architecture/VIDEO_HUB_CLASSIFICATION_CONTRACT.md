# Video Hub Classification Contract

The eight values below are a controlled editorial/navigation vocabulary, not
Authority entities, Graph predicates or WordPress semantic taxonomy:

`01` Bắt đầu tìm hiểu đồng hồ cổ; `02` Thương hiệu & Lịch sử;
`03` Nhận diện bộ máy; `04` So sánh đồng hồ cổ; `05` Linh kiện & Cơ cấu;
`06` Âm thanh đồng hồ cổ; `07` Kiểu dáng & Mặt số; `08` Đồng hồ thực tế.

The classifier combines source title/description, tags, authorized transcript,
user hint and existing semantic context. It requires bounded multi-signal
support, returns one primary and optional secondary values, and emits
`CATEGORY_UNRESOLVED` when evidence is insufficient. It never creates a
semantic relation and never uses a single WordPress category/tag as truth.
