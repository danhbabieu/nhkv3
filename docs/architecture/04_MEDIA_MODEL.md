# Media model

> **NON-NORMATIVE.** Đây là domain-model evidence hiện hành. Nếu mâu thuẫn
> với `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát. MCP
> transport và runtime capability được chốt ở các contract hiện hành được
> router trong `READ_FIRST.md` dẫn tới.


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

## Public canonical image resolution — 2026-09-09

Public projection phải chọn source-derived image lớn nhất đủ điều kiện làm
canonical `/anh/<slug>.webp`. Khi source-original rộng từ 900px trở lên,
derivative public canonical cũng phải rộng tối thiểu 900px; derivative 240×340
hoặc thumbnail tương tự không được dùng làm full-size asset. Derivative phải
được tạo trực tiếp từ source/original, không upscale từ derivative nhỏ hơn,
giữ nguyên aspect ratio nguồn và không sharpen quá mức hay đổi màu ảnh gốc.

WebP dùng quality mục tiêu 82–88 (default hiện hành 86). Listing có thể dùng
thumbnail riêng, nhưng link click từ `/thu-vien/` luôn mở canonical
`/anh/<slug>.webp` độ phân giải lớn. Nếu source đủ lớn nhưng chưa có public
derivative đạt chuẩn, projection fail-closed thay vì trả về thumbnail.

Image payload phải được validate thực sự trước persistence. Corrupt, fake hoặc
unreadable input fail-closed và không được để lại orphan artifact.

`MediaUsage` chỉ mô tả placement, role và contextual SEO của một Media đã có.
Usage không tự tạo `depicts`, `about`, Knowledge, Source hoặc Evidence. Role
`representative` tách khỏi `evidence`/`technical_detail`; ảnh evidence không tự
thay ảnh đại diện. Checksum, tên file, URL attachment và thời điểm upload không
được dùng để merge hoặc thay canonical Media identity.

## Universal MCP post-ingest reconciliation — 2026-09-09

Sau mỗi MCP Media ingest đã đọc lại được, Media phải chạy bounded semantic
enrichment theo chuỗi canonical: `ingest → read-back → canonical search →
neighborhood/Graph inspection → duplicate/reuse analysis → relation candidate
discovery → evidence/provenance validation → apply mọi relation hữu ích có căn
cứ → final read-back`. Đây là một phần của completion contract, không phải
optional enrichment. Bounded theo runtime registry, Graph traversal/result
limits, dependency closure và Governance; không tạo weak/speculative edge.

Một Media có thể được reuse bởi nhiều MediaUsage hoặc quan hệ tới nhiều node
khi từng context có căn cứ. Sau ingest phải tìm các node trực tiếp liên quan
đang thiếu ảnh và đánh giá ảnh tốt nhất hiện có làm representative tạm thời.
Representative là `BEST CURRENTLY AVAILABLE`, không immutable. Suitability
được ưu tiên theo exact subject specificity → visual coverage → technical
relevance → image quality/resolution → provenance confidence → current
representative quality. Ảnh cấp Variant không đại diện Brand/Model rộng hơn
nếu thiếu representative relevance.

Khi có ảnh phù hợp hơn, compare suitability, promote ảnh mới và demote ảnh cũ
về gallery, `technical_detail` hoặc evidence nếu còn phù hợp. Không xóa Media,
MediaAsset hay provenance cũ. Role/usage reconciliation không tự tạo
`depicts`; mọi semantic relation vẫn phải là predicate đã đăng ký, evidence-
supported và Governed.

Các adapter MCP/Admin/WordPress phải đi qua cùng application boundary của Media;
không adapter nào được ghi trực tiếp bảng Media/Asset/Usage như một writer thứ
hai. Downstream reuse phải ưu tiên canonical Media UUID/stable key + revision,
sau đó dùng asset/usage phù hợp thay vì upload hoặc nhân bản lại cùng semantic
identity.

## Canonical file-to-Media workflow — 2026-09-08

Đường upload file canonical là:

`nhk.media.upload-batch` (multipart `files[]`)
→ WordPress native attachment lifecycle
→ canonical attachment read-back / `nhk.media.attachment.get`
→ `nhk-v3/media-ingest` attachment adoption/binding
→ `MediaAsset` → `Media` → `MediaUsage`.

Một file là batch có một item; batch hỗ trợ 1..N file trong giới hạn runtime.
Manifest phải giữ thứ tự và trả về per-item `attachment_id`, `source_url`,
filename, MIME, byte size, dimensions, SHA-256, `created`/`reused`, read-back
status và typed error. Batch là per-item, không all-or-nothing: item thành công
được giữ lại khi item khác lỗi và `partial_success` được trả về.

`nhk.media.upload-batch` là PRIMARY multipart transport. URL đã có public
HTTPS dùng `wp_upload_media_from_url` là SECONDARY/IMPORT. `wp_upload_media`
base64 chỉ là FALLBACK/COMPATIBILITY cho payload nhỏ, không phải đường mặc định
cho workflow nhiều ảnh. Hai transport và semantic `media-ingest` không được
nhập thành một boundary.

Connector discovery cũng nhận capability này qua Ability
`nhk-v3/media-upload-batch` với top-level `files[]` binary parameter. Adapter
giữ native multipart parts và chuyển tiếp chúng tới custom MCP transport;
binary không đi qua Ability JSON, base64 hoặc client filesystem path.

SHA-256 được tính trên binary thật. Cùng idempotency key và cùng payload được
replay/reuse; cùng key nhưng payload khác phải fail deterministic
`IDEMPOTENCY_CONFLICT`. Filename trùng không chứng minh cùng file và checksum
không tự tạo global semantic dedup. Nếu concurrency chưa có runtime proof,
trạng thái phải ghi `IMPLEMENTED_CODE_SIDE / LIVE_ACCEPTANCE_PENDING`.

Upload transport chỉ tạo/adopt attachment và đi qua governed Media boundary;
trong transport phase nó không infer/apply Knowledge, Source, Evidence, Graph
relation, Model/Variant suy diễn hay semantic truth. Sau Media semantic ingest
và canonical read-back, universal post-ingest reconciliation bắt buộc phải
chạy; Source/Evidence và Graph chỉ được tạo/reconcile khi có provenance,
evidence, registered relation và Governance phù hợp. Locator canonical của
attachment được ưu tiên khi lifecycle đã đọc lại thành công, không duplicate
Source/Evidence chỉ để thay `chatgpt-upload:*`.

Media workflow có thể chuẩn bị cho `batch images → attachments → Media →
MediaUsage → Specimen → Product`, nhưng Specimen vẫn là một hiện vật vật lý,
Product là listing/offer. Product–Specimen vẫn là `REGISTRY_GAP` nếu chưa có
predicate/contract được đăng ký; không dùng `about` làm workaround.

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

## Current canonical frontend/Admin boundary — 2026-09-07

Media semantic records remain canonical V3 objects. WordPress attachments/posts
may project storage or editorial placement, but are never Media authority,
semantic identity or a replacement for Graph/Projection. Admin Hình ảnh is the
canonical Media management workspace and has its own list/detail read surfaces;
this does not authorize a standalone public Image/Media page. Any future
standalone route requires separate architectural approval.

Admin guided flows resolve/reuse canonical Media through the application
boundary and Governance. Normal forms do not require proposal UUID, Evidence
UUID, fingerprint, expected revision or raw JSON; technical identifiers belong
under Kỹ thuật/Nâng cao.
