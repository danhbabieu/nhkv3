# Hồ sơ nghiên cứu 21 cấu hình côn – búa – nhạc

Trạng thái: **USER_OBSERVATION / UNVERIFIED / NOT_PUBLIC**. Ngày 2026-10-08. CSV đi kèm là 21 dòng gốc của người sưu tầm, kể cả cách viết sai và nhận định chưa được kiểm chứng. Không dùng tài liệu này làm chứng cứ cho canonical fact.

## Mục đích
Bảo toàn quan sát để tiếp tục nghiên cứu; không sinh trực tiếp Knowledge, Authority, Dictionary, Graph, Article hay public claims bằng import CSV.

## Phân bổ dự kiến
- **Authority / Classification hoặc Movement**: mô tả từng cấu hình số thanh côn, số búa, số bài, scope theo bộ máy/đời; chỉ sau khi resolve/reuse và review.
- **Music**: reuse Sonodo, Westminster, Gai Carillon, Ave Maria Lourdes (đã resolve trên staging @v59); tên khác cần xác minh.
- **Dictionary**: thuật ngữ côn, búa, đánh mượn, biến thể tên và cấu hình; không tạo bản trùng với semantic owner.
- **Knowledge + Source + Evidence**: atomic claims và dẫn chứng cho từng hiện vật.
- **Graph**: chỉ quan hệ đã chứng thực với phạm vi phù hợp; không suy ra mọi ODO đều giống nhau.
- **Article**: tổng hợp từ Knowledge đủ điều kiện; không sử dụng quan sát chưa chứng minh như sự thật.

## Tên bài cần xác minh
- `westminsnter` → Westminster (sửa chính tả dự kiến).
- `clockch de comtoiser` → có thể liên quan `Cloche de Comtoise`; chưa xác nhận.
- `fonteynoy` / Fontenoy: cần phân biệt nhãn hiệu và bản nhạc.
- `avemaria loudes` → Ave Maria Lourdes (dự kiến).
- `avemaria fatima` cần xác định phân biệt với Ave Maria Lourdes.
- `bản ma normandie` → nghi `Marche Normande`; chưa xác minh.
- `le belle de candix` → nghi `La Belle de Cadix`; chưa xác minh.
- Angelus: kiểm tra tên giai điệu và số bài.

## Kiểm chứng ưu tiên
1. Nhóm ÔĐô 24, 6 thanh côn / 10 búa / 2 bài Sonodo–Westminster.
2. Nhóm đánh mượn 5/8, 6/8; phân biệt bộ búa gõ nhạc và gõ điểm giờ.
3. Nhóm 9/9 Fontenoy; 10/10, 10/11, 11/11, 11/17.
4. Nhóm 12/12/4 bị nghi độ chế: **không được kết luận không có thật** khi thiếu chứng cứ.
5. Tất cả gắn nguồn cụ thể và tình trạng nguyên bản/độ chế theo từng hiện vật.

## Tài liệu khởi đầu (chưa đủ để generalize)
- https://odomorbier.wordpress.com/2021/01/22/carillon-odo-24-10-marteaux-2-airs-sonodo-et-westminster/ — mô tả hiện vật ÔĐô 24 6 thanh côn / 10 búa / Sonodo + Westminster.
- https://odomorbier.wordpress.com/category/6-gongs/ — nhóm hiện vật 6 côn; phải đối chiếu từng bài.

## Ghi nhận thao tác
- @v59 trên staging đã resolve Sonodo, Westminster, Gai Carillon, Ave Maria Lourdes và một số Classification.
- Capture trước đó bị chặn bởi `AUTHORITY_CONTINUATION_REQUIRED`, một lần khác `ARTICLE_DRAFT_READBACK_UNAVAILABLE`; **chưa có bằng chứng đã lưu 21 dòng vào DB**.
- Dữ liệu trong branch này chỉ là tài liệu nghiên cứu, không phải migration/import hay duyệt publication.
- Khi thực hiện Capture trở lại, cần checkpoint tài liệu mới nhất, semantic-owner continuation, CAS, governance và canonical readback.

Nguồn: bảng 21 dòng do người dùng cung cấp trực tiếp trong hội thoại.
