# 02 — Domain Structure

## 1. Semantic domain families

Các nhóm dưới đây mô tả cấu trúc khái niệm cần được bảo toàn. Chúng **không tự động tạo entity type**. Một type chỉ được dùng khi runtime registry/contract đã đăng ký.

### Brand backbone

Brand là xương sống ngữ nghĩa để tổ chức và kiểm tra ngữ cảnh của các đối tượng liên quan.

### Product lineage / technical lineage

Các khái niệm cần mô hình hóa khi runtime cho phép gồm:

- Brand;
- Model;
- Variant;
- Movement;
- Music/chime program;
- Component;
- Classification.

Các thành phần kỹ thuật như côn, búa, thùng, mặt số chỉ trở thành entity/field/relation độc lập nếu contract đã định nghĩa. Nếu chưa có, không tự nâng dữ liệu mô tả thành canonical type.

## 2. Quan hệ cấu tạo

Mô hình phải đủ khả năng diễn đạt, theo registry hợp lệ:

- một Brand có thể có nhiều Model;
- Model có thể có nhiều Variant;
- Variant có thể dùng hoặc liên hệ tới Movement;
- Movement có thể liên hệ tới hệ phát âm, chương trình nhạc và Component;
- Component có thể được phân loại và có quan hệ cấu tạo;
- thùng, mặt số, côn và búa phải được đặt đúng cấp semantic thay vì nhét lẫn vào một blob mô tả.

Đây là yêu cầu về **khả năng biểu diễn cấu trúc**; không phải giấy phép invent predicate.

## 3. Shared classification

Classification là lớp phân loại dùng chung khi runtime hỗ trợ. Classification không được thay thế canonical identity của Brand, Model, Variant, Movement hoặc Component.

Không dùng taxonomy chỉ vì thuận tiện cho UI nếu semantic meaning thực chất là canonical relation.

## 4. Specimen và Product

- Specimen: một hiện vật vật lý cụ thể.
- Product: một listing/offer có thể trỏ tới Specimen theo thời gian.

Product không phải identity của hiện vật. Một thay đổi listing không được làm thay đổi identity của Specimen.

## 5. Editorial Post

Post thuộc boundary biên tập. Post có thể liên hệ tới semantic entities thông qua Graph nhưng không trở thành nơi chứa duplicate canonical truth.

Body bài viết không phải nguồn để tự động invent semantic schema trong V3.

## 6. Media và Video

Media và Video là canonical domain riêng:

- Media: semantic identity ảnh, tách khỏi binary asset và usage;
- Video: canonical external-reference entity theo contract riêng.

Hai domain có thể chia sẻ typed relation semantics, provenance/evidence policy và readiness primitives nhưng không gộp identity hoặc persistence.