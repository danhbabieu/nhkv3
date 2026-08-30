# Migration framework

Phiên bản khởi đầu của project là `0`. Chưa có migration domain nào được tạo.

Mọi migration tương lai phải có version riêng, idempotent, có status current/target,
transaction khi phù hợp và không thực hiện thao tác phá huỷ ngầm.
