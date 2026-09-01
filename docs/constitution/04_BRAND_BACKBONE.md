# 04 — Brand Backbone

## 1. Vai trò

Brand là semantic backbone của NHK V3. Điều này không có nghĩa mọi record đều phải có một Brand field trực tiếp; nghĩa là cấu trúc phải bảo toàn khả năng xác định đúng ngữ cảnh Brand của các đối tượng thuộc lineage sản phẩm/kỹ thuật khi semantic domain yêu cầu.

## 2. Quy tắc ngữ cảnh

- Model phải thuộc đúng ngữ cảnh Brand theo contract.
- Variant phải giữ được lineage tới Model và Brand mà không tạo relation giả.
- Movement có thể dùng chung giữa nhiều Model/Variant/Brand nếu bằng chứng và contract cho phép; không ép Movement thành tài sản độc quyền của một Brand.
- Music và Component có thể dùng chung xuyên Brand; không duplicate canonical identity chỉ để giữ cây điều hướng.
- Classification không thay thế Brand lineage.

## 3. Không biến backbone thành cây cứng

Brand backbone là nguyên tắc semantic, không phải yêu cầu mọi quan hệ phải tạo thành một cây duy nhất.

NHK V3 phải hỗ trợ graph thực tế:

- một Movement có thể liên hệ nhiều Variant;
- một Component có thể xuất hiện trong nhiều Movement;
- một chương trình nhạc có thể xuất hiện trong nhiều cấu hình;
- Media/Video/Post có thể liên hệ nhiều entity khác nhau.

Không duplicate entity để ép graph thành hierarchy đơn giản.

## 4. Projection theo Brand

Brand page hoặc Brand context có thể tổng hợp Model, Variant, Movement, Music, Component, Media, Video, Post và Specimen thông qua query/application service.

Projection không được tạo canonical relation mới chỉ để phục vụ giao diện.

## 5. Kiểm tra sai cấp

Một claim hoặc relation phải gắn vào cấp hẹp nhất mà bằng chứng thực sự hỗ trợ.

Ví dụ:

- đặc điểm chỉ đúng cho một Variant không được nâng lên Brand;
- cấu hình chỉ đúng cho một Movement revision không được nâng lên toàn bộ Model;
- chi tiết của một Specimen không được biến thành đặc tính canonical của Variant.

Sai cấp semantic là lỗi cấu trúc, không phải lỗi dữ liệu đơn thuần.