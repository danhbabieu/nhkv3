# Media model

> **NON-NORMATIVE.** Đây là domain-model evidence hiện hành. Nếu mâu thuẫn
> với `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát. MCP
> transport và runtime capability được chốt ở các contract hiện hành được
> router trong `READ_FIRST.md` dẫn tới.

## Single entry point for new media submissions — 2026-09-09

New image input is submitted through `nhk.capture.ingest`, including one image
or multiple images attached to text, a `MEDIA_ENRICHMENT` submission, or
knowledge-only context. The Capture
physical phase delegates to the canonical Media/MediaAsset/MediaUsage owner;
it never creates a second Media store. Standalone Media upload/ingest remains
internal/admin compatibility or lifecycle tooling only and is not the normal
operator path.

The ChatGPT MCP Apps image widget is a transport/presentation adapter over the
same physical owner. Its `nhk.media.widget-upload` tool accepts only the
canonical structured provided-file object (`download_url` and `file_id`, plus
advisory `mime_type`/`file_name`) through its capability-gated boundary. Each
`files[]` item may carry its stable zero-based `ordinal` and transport-side
`media` hints; the optional ordered `items[]` packet is matched by
`client_file_id`/ordinal and carries the canonical per-item `title`, `alt_text`,
`caption` and `description`. The
object shape is trusted input structure, not a trust decision about the remote
host: the centralized materializer independently enforces HTTPS/443,
credential-free public-IP resolution, pinned TLS fetches, redirect
revalidation, streaming limits and actual image decoding before delegating to
`ImageIngestEntrypoint`. Host/provider patterns are diagnostics only; no
ChatGPT region hostname allowlist authorizes a download. URL-only, opaque ID,
local path and arbitrary URL inputs remain rejected.

The widget returns canonical attachment/Media read-back without semantic
mutation. It may hand its ordered canonical `media_ids` to Capture later; that
path reuses the existing attachment/Media and does not re-download or
duplicate it.

The widget's upload-only action is a fast Media commit boundary. It returns a
safe ordered batch/continuation context after attachment and Media read-back;
the context is workflow metadata, not an Album, Authority entity, Graph edge,
Knowledge record or Media owner. Optional enrichment remains `NOT_RUN`,
`PENDING` or `PARTIAL` until an existing downstream workflow is explicitly
continued. The Article action keeps its long body in `Capture.text` and sends
the previously committed ordered Media IDs to `IMAGE_ARTICLE`, so a long
Article request never travels through the 500-character media description and
successful Media is never uploaded a second time.

Article-scoped image title, alt text and caption are contextual placement
metadata. They belong to the Article-owned MediaUsage/managed placement and do
not overwrite the canonical Media name or the global WordPress attachment
title. MediaUsage updates use its optimistic revision; stale placement writes
fail closed.

An existing-Capture continuation is text-only unless it explicitly declares
the registered `followup_mode=ATTACH_ASSETS`. That mode accepts native files
through Capture, appends verified asset metadata to the same Capture, and
creates or reuses canonical Media without creating a second Article. When no
physical asset is present, Media selection may reuse an existing Media
only when its persisted subject scope matches the resolved canonical subject;
an unscoped/global reusable Media row is never an honest completion fallback.
If no eligible subject-scoped Media exists, the Article remains missing or
placeholder and any wrong-variant usage is reconciled away.

Historical Media reuse is permitted only after the Capture subject is locked
by the shared canonical resolution packet and the persisted Media/Usage scope
matches that subject. Filename, title similarity, an old Article plan,
stale representative state or a global row is not semantic scope evidence.
Final WordPress attachment read-back applies the same check, so stale featured
or inline usage is rejected rather than silently replaced by another image.
No eligible Media is a readiness/incomplete outcome; it is not a blueprint
corruption error and must not be reported as infrastructure retryable failure.


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

Public projection phải chọn source-derived image phù hợp làm canonical
`/anh/<slug>.webp` với **PUBLIC IMAGE MAX LONG EDGE = 1920 PX**. Nếu cạnh dài
nguồn `<= 1920`, giữ nguyên kích thước gốc; nếu `> 1920`, dùng
`scale = 1920 / max(width, height)` rồi tính từng cạnh bằng `round(dimension ×
scale)`. Không upscale, không crop, không kéo méo, không ép canvas vuông và
luôn giữ aspect ratio. Derivative phải được tạo trực tiếp từ source/original;
thumbnail 240×340 chỉ dành cho listing, không được làm canonical full-size.

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

## Multi-image metadata ownership — 2026-09-18

Capture/submission text is batch context or an ordered user instruction. It is
not implicitly a canonical title, alt text, caption or description for every
Media item. Each file keeps a stable ordinal (`files[i]` ↔ `items[i]`) and may
carry explicit per-item Media metadata. An ordered natural-language mapping is
accepted only when its item count matches the file count with sufficient
confidence; otherwise the batch context is retained and item metadata uses a
safe neutral fallback or remains review-required. The implementation MUST NOT
fabricate missing descriptions or duplicate free-form batch text across Media.

Album/submission context, canonical Media metadata and MediaUsage presentation
metadata remain separate owners. Article-scoped alt text, caption and title are
MediaUsage context and MUST NOT overwrite canonical Media metadata. A replay
with the same Capture/item fingerprint preserves identity, ordinal and
metadata; correction of existing metadata must use the governed Media owner and
canonical read-back without re-uploading the asset.

The correction operation is a governed Media `update` with the exact canonical
Media UUID and expected Media revision. It may change canonical name/readiness/
provenance only; it does not change the stable key, attachment locator,
MediaAsset checksum or MediaUsage UUIDs. Completion requires the updated Media
read-back. Direct SQL, `wp_update_attachment` and a new upload are not repair
paths.

For a Capture `MEDIA_ENRICHMENT` metadata-only repair, the server issues an
immutable staging scope bound to the exact Capture fingerprint, Media UUID,
expected revision and payload fingerprint before creating the Proposal. Missing,
stale or tampered scope remains fail-closed. This path does not run generic
subject reconciliation and does not infer or create `featured_primary` usage;
MediaUsage is changed only by explicit `media_bindings[]` or placement
operations. Completion requires read-back of every requested Media owner.

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
đang thiếu representative image và đánh giá ảnh tốt nhất hiện có làm representative tạm thời.
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

## Usage reconciliation and representative convergence — 2026-09-09

WordPress Media là authority của physical file; V3 `Media` là semantic
identity; `MediaUsage` là quan hệ semantic/presentation theo endpoint và role.
Attachment adoption phải `create-or-resolve` theo canonical UUID/stable key:
Media hiện hữu được bổ sung hoặc cập nhật asset/usage, không tạo Media mới chỉ
vì tên đọc lại, attachment replay hoặc Article khác. Cùng một Media được phép
đồng thời là `featured_primary`, `inline_primary`, `technical_detail`,
`evidence`, `gallery` và `representative` trong những context hợp lệ.

Usage reconcile là deterministic và idempotent: `KEEP`, `ADD`, `UPDATE`,
`DEMOTE`, `RETIRE`, `CONFLICT` hoặc `OWNER_REVIEW_REQUIRED`. Existing usage
UUID được giữ khi đổi Media binding hoặc contextual metadata; bản ghi cũ không
bị xóa để che giấu provenance. Duplicate current usage, ambiguous scope hoặc
candidate không có asset/readiness/public eligibility phải fail closed.

Representative là presentation-only và scoped theo đúng node/facet được caller
chứng minh. Điểm chọn hiện hành so sánh semantic specificity, visual coverage,
technical usefulness, clarity/resolution, provenance confidence và obstruction;
ảnh tốt hơn được promote, ảnh cũ được demote nếu vẫn phù hợp, không xóa Media,
MediaAsset hay provenance. Representative không phải Claim và không tự tạo
Graph edge.

## Canonical Media binding service — 2026-09-17

`MediaBindingService` là application owner duy nhất của workflow
`Media → MediaUsage → exact target → role`. Capture, Admin, MCP và upload
widget không ghi `nhk_media_usages` trực tiếp. Typed `media_bindings[]` của
Capture dùng `media_ref.item_index` sau physical ingest hoặc `media_ref.media_id`;
`nhk.media.bind` dùng cùng service cho Media đã tồn tại.

Representative user chọn dùng `selection_source=USER_EXPLICIT` và
`selection_policy=PINNED`; system reconciliation dùng `SYSTEM_AUTO` và `AUTO`.
Mỗi endpoint/target chỉ có một active representative slot, được bảo vệ bởi
optimistic revision/CAS ở Usage và unique active-slot constraint ở schema.
Thay thế chỉ demote usage cũ, không xóa Media, MediaAsset hay source-original.
Mỗi mutation có durable binding receipt và final canonical read-back; retry cùng
fingerprint tiếp tục receipt, còn payload đổi dưới cùng idempotency key bị từ chối.

URL first-party hoặc attachment ID chỉ là exact locator đã được chứng minh bằng
asset/WordPress metadata. Không download URL, không filename similarity, không
Graph traversal/NLP cho exact typed binding và không tạo Graph edge để biểu diễn
representative.

### Capture acceptance scope — server-issued and immutable

Một Capture `MEDIA_ENRICHMENT` ở staging chỉ được phép tiếp tục khi
`StagingAcceptanceScopeVerifier` phát hành packet từ chính Capture fingerprint,
exact Media UUID, exact target type/UUID/stable key/revision và binding intent.
Packet còn bind capability, request/plan/payload/dependency fingerprints,
idempotency, `operation_family`, canonical writer/entrypoint, thời hạn và chữ ký
HMAC của server; client không thể hợp thức hóa `approved=true`, thay Media,
target, Capture, revision, capability hoặc operation bằng cách sửa payload.

`MediaBindingStagingGuard` và `OperationScopedStagingGuard` dùng cùng một
verifier owner. Direct `USER_EXPLICIT/PINNED` đi qua `MediaBindingService`;
`SYSTEM_AUTO/AUTO` phải đi qua Governance `representative_bind` và không được
đi tắt qua typed Capture binding. Capture-derived packet không dùng whitelist
Media UUID lịch sử; các ID tĩnh trong acceptance package cũ chỉ còn là manual
legacy evidence, không phải authority cho Capture mới. Thiếu secret, admission,
signature, exact scope, expiry hoặc final read-back đều fail closed; production
không bao giờ được bật đường staging này.

Auto-discovery is exposed as a bounded candidate recipe, not as unrestricted
Graph traversal: `RepresentativeEligibilityRegistry` admits only registered
target types with explicit scope evidence, then `MediaBindingService` scores
the candidates. A tie or missing scope returns `REVIEW_REQUIRED`; reachable
Knowledge/Brand/parent nodes and filename/title similarity are never promoted.

### Authority representative target policy — 2026-09-18

The shared `RepresentativeEligibilityRegistry` is the single policy owner for
Authority targets that may receive a `representative` MediaUsage. The current
registered public Authority profiles are `brand`, `classification`, `model`,
`variant`, `movement`, `component`, `music`, `specimen` and `product`. Their
representative scope recipes are respectively `exact`, `representative`,
`broader`, `exact`, `exact`, `exact`, `exact`, `exact` and `exact`.

This policy is consumed by both bounded representative discovery and staging
admission; Capture does not maintain a second type-specific allow-list. A
target must still resolve to an existing active Authority entity, and the
Media must be canonical, active, non-placeholder and read-back eligible.
`nhk.capture.ingest` is the only normal operator entrypoint. `nhk.media.bind`
and `nhk.media.usage` remain internal/admin compatibility or Governance
boundaries and continue to fail closed without their exact staging packet and
capabilities. `USER_EXPLICIT/PINNED` is the owner-selected path; replacement
demotes the previous active slot and preserves its history, while idempotent
replay returns the existing receipt without a second active usage. Public
entity projections read the current active representative usage only; absent,
retired or unavailable Media remains an honest empty/unavailable state.

## Canonical file-to-Media workflow — 2026-09-08

Đường upload file canonical là:

`nhk.media.upload-batch` (multipart `files[]`)
→ WordPress native attachment lifecycle
→ canonical attachment read-back / `nhk.media.attachment.get`
→ `nhk-v3/media-ingest` attachment adoption/binding
→ `MediaAsset` → `Media` → `MediaUsage`.

Capture also accepts an existing first-party WordPress Media URL as a physical
input adapter. The adapter requires HTTPS and an approved site origin, resolves
the exact owning attachment through `_wp_attached_file`/attachment metadata,
reads it back locally and then enters the same attachment adoption boundary.
It never downloads the URL, fuzzy-matches a filename, creates a second
attachment or treats the URL as semantic identity. Derivative URLs resolve to
the owning attachment only when WordPress metadata proves the exact generated
path; foreign, ambiguous, traversal or non-image inputs fail closed.

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

### Visual Support Requirement — 2026-09-11

`VisualSupportRequirement` is the application-level ledger for a semantic
feature that needs an exact illustration. It is distinct from the rule that a
node lacks a representative image. It stores missing/review/resolved state,
subject/scope/facet/detail/intent, provenance, revision and optional Media;
MediaUsage remains the contextual binding. A later canonical Media read-back
performs an indexed, bounded reverse lookup and can satisfy an older missing
requirement without rewriting every Article or Video. Exact subject, scope,
facet, feature and context are mandatory; same brand/model, filename,
keyword, gallery, checksum or near visual match is not enough. Visual support
does not create Evidence, Claim or Graph truth, and public projections omit
private, review, placeholder, unavailable or ineligible Media.

## Governed MediaUsage mutations — 2026-09-18

The shared Governance pipeline exposes four operation-scoped MediaUsage
capabilities: `media:add`, `media:replace`, `media:remove` and
`media:representative_bind`. The resolver checks the most specific
target/owner/operation key, then owner/operation, then the legacy owner key;
an absent key is `REVIEW_REQUIRED`.

Admin and MCP submit these as ordinary Governance proposals. The proposal is
checked by `ProposalSubjectBindingValidator` and `ProposalEligibilityService`,
applied by `ControlledApplyService`, and delegated to `MediaBindingService`,
which remains the sole MediaUsage writer. The Admin **Duyệt dữ liệu** page is
the only review queue; no Media approval queue or parallel approval service is
created.

`add` creates one Usage for an exact target/role/placement; `replace` keeps the
Usage UUID and uses expected Usage revision CAS; `remove` keeps the row and
marks its active slot `retired`. Retired rows are excluded from active
reconciliation and public projection. All operations return Media and Usage
read-back and remain idempotent at the Governance proposal/attempt boundary.

For `wp_post`, an applied MediaUsage mutation is still subject to
`OwnerPublicationApplicationService` and `ArticlePublicationGate` before an
AUTO_PUBLISH result can become public. Authority targets use the
representative role only when admitted by the registered
`RepresentativeEligibilityRegistry` profiles. Movement generation/type
remains the registered Movement payload contract; no new entity type,
endpoint type or registry entry is invented by MediaUsage.
