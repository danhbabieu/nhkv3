# Nguyên tắc migration

> **NON-NORMATIVE.** Đây là guidance/evidence triển khai. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Project mới bắt đầu từ migration version `0`, không mang số hoặc dữ liệu từ
project cũ. Migration tương lai phải versioned, idempotent, transaction khi phù
hợp, có status current/target và tuyệt đối không destructive ngầm. Bootstrap này
không tạo bảng domain lớn và không triển khai data migration.
