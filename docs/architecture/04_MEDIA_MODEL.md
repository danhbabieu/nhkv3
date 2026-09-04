# Media model

> **NON-NORMATIVE.** Đây là evidence mô hình lịch sử. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Media là semantic entity độc lập. Media identity tách khỏi media usage: cùng
một binary có thể dùng cho nhiều Post, gallery Model hoặc Component mà không
nhân bản Media. Checksum chỉ phát hiện duplicate binary, không tự merge semantic
identity. Nguyên tắc: asset một lần, relation nhiều lần, usage nhiều lần.

## Current reusable storage boundary — 2026-09-04

Một upload ảnh mới phải create-or-resolve đúng một canonical `Media`. Binary gốc
được giữ dưới cùng Media đó như source-original `MediaAsset`; WebP/thumbnail/
responsive size là derivative `MediaAsset` hoặc WordPress projection và không
được tạo Media identity mới. WordPress attachment chỉ là storage/projection
record, không phải semantic identity và không phải Graph endpoint mặc định.

`MediaUsage` chỉ mô tả placement/role/contextual SEO của một Media đã có. Usage
không tự tạo `depicts`, `about`, Knowledge, Source hoặc Evidence. Role
`representative` tách khỏi `evidence`/`technical_detail`; ảnh evidence không tự
thay ảnh đại diện. Checksum, tên file, URL attachment và thời điểm upload không
được dùng để merge hoặc thay canonical Media identity.

Các adapter MCP/Admin/WordPress phải đi qua cùng application boundary của Media;
không adapter nào được ghi trực tiếp bảng Media/Asset/Usage như một writer thứ
hai. Downstream reuse phải ưu tiên canonical Media UUID/stable key + revision,
sau đó dùng asset/usage phù hợp thay vì upload hoặc nhân bản lại cùng semantic
identity.
