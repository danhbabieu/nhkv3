# Bản nháp contract Semantic Graph

Chỉ có một semantic graph dùng chung cho wp_post, media, video, knowledge,
source và các authority entity. Relation tương lai có dạng:

`source_type, source_id, predicate, target_type, target_id`

Contract phải typed, validate được, idempotent, chống duplicate, query hai
chiều, có pagination và index; không phụ thuộc riêng vào WordPress Post. Chưa
tạo production relation table ở phase bootstrap.
