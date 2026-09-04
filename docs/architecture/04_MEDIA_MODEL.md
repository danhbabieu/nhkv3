# Media model

> **NON-NORMATIVE.** Đây là evidence mô hình lịch sử. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.


Media là semantic entity độc lập. Media identity tách khỏi MediaAsset và
MediaUsage: cùng một binary có thể dùng cho nhiều Post, entity, gallery Model,
Component hoặc Product/Specimen context mà không nhân bản Media. WordPress
attachment chỉ là storage/projection record, không phải semantic authority.
Checksum chỉ phát hiện duplicate candidate/binary, không tự merge semantic
identity. Nguyên tắc: asset một lần, relation nhiều lần, usage nhiều lần.

## Current reusable storage boundary — 2026-09-04

Một upload ảnh mới phải create-or-resolve đúng một canonical `Media`.
Source-original được giữ dưới cùng Media đó như một `MediaAsset` ở trạng thái
`PRIVATE`/protected; WebP, thumbnail và responsive outputs là derivative
`MediaAsset` hoặc WordPress projection và có thể `PUBLIC` khi đủ điều kiện.
Derivative không được tạo Media identity mới.

Image payload phải được validate thực sự trước persistence. Corrupt, fake hoặc
unreadable input fail-closed và không được để lại orphan artifact.

`MediaUsage` chỉ mô tả placement, role và contextual SEO của một Media đã có.
Usage không tự tạo `depicts`, `about`, Knowledge, Source hoặc Evidence. Role
`representative` tách khỏi `evidence`/`technical_detail`; ảnh evidence không tự
thay ảnh đại diện. Checksum, tên file, URL attachment và thời điểm upload không
được dùng để merge hoặc thay canonical Media identity.

Các adapter MCP/Admin/WordPress phải đi qua cùng application boundary của Media;
không adapter nào được ghi trực tiếp bảng Media/Asset/Usage như một writer thứ
hai. Downstream reuse phải ưu tiên canonical Media UUID/stable key + revision,
sau đó dùng asset/usage phù hợp thay vì upload hoặc nhân bản lại cùng semantic
identity.

## Ô Đô media integrity incident — current operational rule

The September 2026 incident proved that semantic namespace normalization and
physical Media basename normalization are separate operations. A prior semantic
namespace normalization changed attachment metadata without renaming physical
files, affecting attachments `#83` (Ô Đô 62/6/10) and `#86` (Ô Đô 36/8), with
two originals and three derivatives in the initial broken set.

The safe repair retained canonical DB metadata, renamed originals and derivatives
together, matched checksums, and verified canonical HTTP `200 image/webp`
responses. Post-repair legacy physical files, broken originals/derivatives and
inline legacy URLs were zero. This is historical evidence, not permission to
repair other data.

The media-integrity auditor and its CLI audit path are read-only by default and
must run before and after basename-sensitive work. Semantic rekey must reject
WordPress attachment/path fields so semantic identity changes cannot implicitly
rename physical files.
