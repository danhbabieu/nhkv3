# Public Dossier Form Design

## Mục tiêu

Tạo một form hồ sơ công khai liền mạch cho mọi loại hồ sơ đồng hồ. Người đọc mới phải hiểu nhanh hồ sơ là gì và nên đọc theo hướng nào trước khi gặp dữ liệu chi tiết; người sưu tầm phải nhìn thấy đường đi từ khái niệm đến cấu hình, chứng cứ, hình ảnh và các hồ sơ liên quan.

Lát cắt đầu tiên tập trung vào hồ sơ chi tiết, đặc biệt `/dong-ho-cong-cong/`, nhưng mọi tiêu đề và bố cục đều phải dùng được cho brand, model, variant, movement, classification, specimen và product theo dữ liệu thực tế của từng profile.

## Nguyên tắc nội dung

1. `Đây là gì?` lấy từ summary/description hoặc claim định danh đã có; không tự phát minh dữ kiện.
2. `Vai trò & bối cảnh` lấy từ claim bối cảnh/lịch sử nếu có; nếu không có thì hiển thị trạng thái đang cập nhật.
3. `Giá trị sưu tầm` chỉ trình bày các chiều provenance, rarity, condition và originality khi có claim tương ứng; không đồng nhất độ hiếm với giá trị.
4. `Người sưu tầm thường xem gì?` lấy từ collector facets như display form, case style, movement, sound và music; không biến thành lời quảng cáo hoặc cam kết giá.
5. Chứng cứ và phạm vi áp dụng luôn nằm cạnh claim; dữ liệu chưa có nguồn phải được ghi rõ, không giả vờ đã kiểm chứng.
6. Chỉ hiển thị section có dữ liệu hoặc trạng thái cần thiết; không hiển thị rail rỗng, thống kê 0 hoặc liên kết trùng.

## Form chung

```text
Breadcrumb
  → Hero: loại hồ sơ, tên, câu dẫn, ảnh đại diện, tín hiệu tư liệu có thật
  → Thanh mục lục: Tổng quan · Tri thức · Hình ảnh · Video · Bài viết
  → Mở hồ sơ: Đây là gì? | Vai trò & bối cảnh | Giá trị sưu tầm | Góc nhìn sưu tầm
  → Định danh & vị trí phân loại
  → Tri thức đã ghi nhận: claim theo facet, nguồn ngay dưới claim
  → Tri thức & chứng cứ: ledger chi tiết khi có projection
  → Hình ảnh / video / bài viết
  → Quan hệ liên quan, phân biệt trực tiếp và suy ra
```

## Hành vi theo dữ liệu

- Dossier không có summary vẫn được render bằng câu dẫn trung tính về mục đích tra cứu, không phải một tuyên bố sự thật.
- Dossier không có collector claim vẫn có khung hướng dẫn đọc với trạng thái “đang bổ sung”, không có số 0 gây cảm giác lỗi.
- Dossier có ít dữ liệu không bị kéo giãn bởi sidebar rỗng.
- Liên quan article/media/video được khử trùng lặp theo URL/canonical identity trước khi render.
- Các route, Public Identity, canonical UUID, relation predicate và owner boundary không đổi.

## Phạm vi kỹ thuật

- Theme: `entity.php`, `entity.css`, `template-parts/presentation/entity-hero.php`, `front-page.php` nếu cần copy dẫn đường.
- Presentation: chỉ thêm helper/read model cho ngôn ngữ trình bày nếu thật sự cần; không thêm canonical field vào Authority hay Knowledge.
- Tests: contract kiểm tra thứ tự section, không render section rỗng/trùng, và giữ label visitor-safe.
- Verification: focused PHPUnit, PHP lint, frontend route smoke nếu runtime có sẵn, `git diff --check`, secret review và kiểm tra trực quan trang hồ sơ.

## Ngoài phạm vi

- Không migrate/backfill/import nội dung V2.
- Không ghi dữ liệu semantic hoặc sửa quan hệ live.
- Không tạo profile/entity type mới.
- Không biến trang hồ sơ thành trang bán hàng; “vì sao người ta mua” được diễn giải thành góc nhìn nghiên cứu/sưu tầm, chỉ hiển thị khi có tư liệu phù hợp.
