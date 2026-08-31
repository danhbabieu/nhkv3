# Migration framework

Phiên bản khởi đầu của project là `0`. Các migration hiện có: Graph `001`,
Authority `002`, Governance `003` và Media/Video `004`.

Migration 009 thêm `nhk_legacy_projection_contexts` như một sink metadata
không canonical cho semantic projection V2. Sink giữ source identity,
provenance và cờ chất lượng nhưng không có cột body; mapper từ chối projection
có body bằng `PROJECTION_BODY_FORBIDDEN`.

Mọi migration tương lai phải có version riêng, idempotent, có status current/target,
transaction khi phù hợp và không thực hiện thao tác phá huỷ ngầm.
