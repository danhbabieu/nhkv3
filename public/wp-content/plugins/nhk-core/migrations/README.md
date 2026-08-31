# Migration framework

Phiên bản khởi đầu của project là `0`. Các migration hiện có: Graph `001`,
Authority `002`, Governance `003` và Media/Video `004`.

Mọi migration tương lai phải có version riêng, idempotent, có status current/target,
transaction khi phù hợp và không thực hiện thao tác phá huỷ ngầm.
