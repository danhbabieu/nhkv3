# Ranh giới nội dung biên tập

> **NON-NORMATIVE.** Đây là evidence triển khai lịch sử. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

WordPress Post là nguồn sự thật duy nhất cho nội dung dạng bài đọc: tri thức,
góc chia sẻ, tin tức, bài chuyên sâu, lịch sử, kỹ thuật và so sánh.

NHK không tạo Article Authority có bản body thứ hai và không xây pipeline
Authority Article → Projection → WordPress Post. Bài được tạo trực tiếp dưới
dạng WordPress Post. Category native dự kiến gồm Bài viết, Tri thức, Góc chia sẻ
và Tin tức; taxonomy domain riêng chưa được tạo ở bootstrap này.

Với request có intent là V3 knowledge Article, Article Ingest là workflow phối
hợp ở cấp operation: semantic preflight → WordPress draft → semantic
Governance/Controlled Apply → read-back verification → WordPress publish. Native
WordPress create/update/publish vẫn là workflow editorial độc lập; chỉ Article
Ingest mới được báo hoàn tất khi toàn bộ required stages của contract thành công.
Không hardcode status/operation mới trong tài liệu này; outcome phải theo Article
Ingest Contract được phê duyệt.
