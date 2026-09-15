# NHK V3 Unified Capture / Media / Editorial Design

**Status:** DRAFT FOR OWNER APPROVAL
**Date:** 2026-09-15
**Implementation status:** DESIGN ONLY — this document authorizes no code
change, deployment, semantic mutation, editorial mutation or data migration.

## 1. Phạm vi, nguồn và thứ bậc chỉ dẫn

Yêu cầu của phiên này là hoàn thiện và commit **một** spec canonical tại đúng
đường dẫn này. Hai file trong Downloads là đầu vào thiết kế:

- `2026-09-15-unified-capture-media-editorial-design.md` là thiết kế nền;
- `2026-09-15-unified-capture-deep-review-addendum.md` là review-only
  addendum, không phải contract và không tự cấp quyền triển khai.

Yêu cầu người dùng về workspace, target branch, read-only inventory, không sửa
code, không deploy và không mutation là chỉ dẫn thực thi của phiên. Nội dung
kiến trúc của cả hai file đính kèm chỉ được giữ khi phù hợp với Constitution,
ACTIVE contracts và executable reality. Constitution và runtime registries
thắng mọi spec, plan, audit hoặc addendum. Addendum không trở thành nguồn luật
song song sau khi spec này được hợp nhất.

### 1.1. Bootstrap và contract đã đối chiếu

Đã đọc theo thứ tự bắt buộc:

1. `AGENTS.md`;
2. `docs/constitution/READ_FIRST.md`;
3. `docs/constitution/NHK_V3_CONSTITUTION.md`;
4. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`;
5. `docs/architecture/V3_EXECUTION_STATE.md`;
6. các contract ACTIVE về Article, Capture/MCP, Media/Video, Knowledge/
   Source/Evidence, VisualSupportRequirement, Governance retry, SEO/public
   identity, sitemap/indexability và public-claim compliance.

Các tài liệu historical chỉ là bằng chứng có ngày tháng. Spec này không dùng
chúng để override Constitution hoặc suy ra capability hiện tại.

## 2. Kết quả thiết kế và luật owner

NHK V3 có đúng một canonical submission boundary cho submission mới:

```text
nhk.capture.ingest
  → one durable Capture
  → physical ingest when applicable
  → interpret
  → registered Content Intent
  → canonical subject resolution
  → bounded Graph discovery / Claim retrieval
  → Source/Evidence scope evaluation
  → governed semantic planning/apply/read-back
  → Article composition when required
  → SEO/compliance/publication gate when applicable
  → final owner and rendered-route read-back
```

Capture chỉ là orchestration, idempotency, audit và resumable recovery. Capture
không phải semantic owner và không được tạo store thứ hai.

| Boundary | Canonical owner | Không được làm |
|---|---|---|
| Article/editorial URL | native WordPress `wp_posts` | copy body sang Knowledge/Graph hoặc tạo Article Authority |
| Authority | registered Entity/Brand/Model/Variant/Movement/Component/Classification | invent type, broaden identity hoặc merge mơ hồ |
| Knowledge | atomic Claim | coi prose, OCR, caption hoặc model confidence là Evidence |
| Source/Evidence | provenance, locator và support | dùng search snippet/summary làm Evidence |
| Graph | registered typed relations | tạo edge chỉ để giải quyết UI |
| Media | canonical image identity | biến attachment hoặc checksum thành semantic identity |
| MediaAsset | source-original và derivatives | public hóa source-original |
| MediaUsage | contextual placement/role/alt/caption | coi usage là Claim/Evidence/Graph truth |
| Video | canonical external reference | tạo Post hoặc local-video owner mặc định |
| Governance | durable semantic mutation lifecycle | bypass proposal/review/eligibility/apply |
| Public Projection/SEO | derived, read-only public state | tự tính một readiness/publication truth thứ hai |

Không tạo `Image Authority`, `Album Authority`, `Article Authority`,
`Album` entity, Album Graph endpoint/predicate/repository hoặc generic writer
thay cho Capture.

## 3. Runtime inventory — executable reality tại checkpoint

Inventory này là read-only và không phải lời hứa implementation.

### 3.1. Capture và Content Intent

- `ContentIntent` hiện chỉ đăng ký `VIDEO`, `IMAGE_ARTICLE`, `TEXT_ARTICLE`,
  `KNOWLEDGE_DELTA`. `MEDIA_ENRICHMENT` **chưa tồn tại** trong enum/router,
  catalog hoặc contract executable.
- `EditorialCaptureCoordinator` hiện tiếp nhận một Capture, lưu phase
  receipts, gọi `ContentIntentRouter`, tạo tối đa một draft cho Article intent,
  adoption Media, subject resolution, Claim retrieval, composition, semantic
  write-back, publication gate và final read-back.
- `WpdbCaptureRepository` có unique idempotency key và `save()` dùng optimistic
  `revision` CAS. `create()` hiện xử lý race bằng unique index rồi đọc lại,
  nhưng chưa có API/receipt riêng diễn đạt atomic idempotency reservation.
- migration 017 lưu Capture, `wp_post_id`, `wp_state_token`, assets, context,
  diagnostics, phase receipts và revision. Đây là orchestration state, không
  phải content store.
- `capture.ingest` nhận `media_ids` hoặc native `files[]`, không cho cả hai
  trong cùng physical packet. Continuation có `capture_id` và
  `followup_mode=ATTACH_ASSETS`.

### 3.2. Physical image và widget

- Canonical physical path là `nhk.media.upload-batch` multipart → native
  WordPress attachment → attachment read-back → governed Media adoption;
  `ImageIngestEntrypoint` dùng lại path này cho native multipart hoặc trusted
  structured file references.
- `MediaBatchUploadService` hiện xử lý per-item, giữ item thành công khi item
  khác lỗi và trả `partial_success`; runtime code đang có giới hạn 1–20 file và
  50 MB tổng batch.
- `nhk.media.widget-upload` chỉ materialize trusted ChatGPT references rồi
  gọi `ImageIngestEntrypoint`; nó không gọi semantic enrichment, Article,
  Graph, Knowledge, Governance hoặc publication.
- Widget source hiện lấy `media_id`/attachment/public filename/dimensions và
  gửi tiếp các Media ID vào Capture continuation. Widget có thể báo UI
  `SUCCESS` sau physical read-back, nhưng đó chỉ là transport success; không
  được gọi là Capture `COMPLETE`.
- `TrustedProvidedFileMaterializer` đã có exact host allowlist, HTTPS,
  redirect validation, private-IP rejection, MIME sniffing và giới hạn tải;
  các quy tắc defense-in-depth ở §10 vẫn là acceptance contract phải hoàn tất.

### 3.3. Media, Asset, Usage và visual support

- `Media` có canonical UUID, stable key, readiness, provenance, active state
  và revision; repository update có revision CAS.
- `MediaAsset` có original/derivative, storage key, checksum, MIME, byte size,
  dimensions, visibility và metadata. Source-original mặc định PRIVATE.
- `MediaUsage` hiện có `usage_uuid`, Media, endpoint type/key, role, sort order,
  alt, caption và keyword groups. Schema/domain **chưa có contextual `title`
  và chưa có revision/CAS**; unique identity hiện là Media + endpoint + role.
  `update()` và retire path chưa thể bảo vệ concurrent edit bằng usage
  revision.
- `VisualSupportRequirement` đã có exact subject/scope/facet/feature/intent,
  selected Media revision, semantic/idempotency fingerprints và revision;
  reverse reconciliation dùng exact matching và canonical Media read-back.
- `RepresentativeMediaReconciler` là presentation-only nhưng hiện demote/
  promote qua MediaUsage updater chưa có usage CAS. Comparator và scope
  precedence phải được giữ trong thiết kế mới.

### 3.4. Article, Gutenberg và public projection

- `EditorialDraftGateway` có receipt idempotency và WordPress native
  `state_token` CAS cho draft update/publish review/apply.
- `ArticleComposer` có managed-section `semantic_key` và content fingerprint
  trong composition packet, nhưng chưa có một parser/marker contract đảm bảo
  stable projected-content fingerprint trong native Gutenberg body.
- `ArticlePublicationGate` hiện còn kiểm tra hai role Article
  `featured_primary` và `inline_primary` theo luật hiện hành; single-real-image
  exception vì vậy là constitutional amendment, không phải code-only change.
- `RenderedArticleVerifier` kiểm tra stored/rendered/public route evidence,
  nhưng image asset-specific HTTP assertions phải được bổ sung vào acceptance.
- `/anh/{filename}.webp` đã có route delivery, `Content-Type`, `nosniff`,
  immutable cache và noindex headers; đây là asset URL, không phải standalone
  HTML Article/page.
- Gallery query hiện chọn canonical public derivative và global card dùng
  `Media.canonicalName`; `PublicMediaArticleLinkResolver::firstPublished()`
  chọn usage published đầu tiên, nên chưa giải quyết ambiguity khi một Media
  được dùng ngang nhau trong nhiều Article.
- Theme `single.php` + `album.js` hiện có inline album carousel và keyboard
  left/right, nhưng chưa phải modal dialog có focus trap, Escape, return focus,
  fallback anchor hoặc đầy đủ accessible lightbox semantics.

### 3.5. SEO, compliance, deployment

- Article/Media SEO, Public URL, Sitemap/Indexability và rendered-route
  verification đều là separate read/projection gates.
- Public-claim compliance contract đang active nhưng automated claim
  classification/evidence validation trên mọi output channel chưa được runtime
  chứng minh đầy đủ; không được coi thiếu capability là PASS.
- Canonical deployment wrapper là `scripts/nhk-deploy-verify`/các adapter đã
  đăng ký; `NHK_DEMO_DEPLOY_CONFIG` là gate. Phiên này không deploy.

## 4. Hai constitutional amendments phải được owner duyệt trước implementation

### 4.1. Media-only intent

Đề xuất amendment hẹp: thêm registered Content Intent `MEDIA_ENRICHMENT`.
Intent này nhận một hoặc nhiều ảnh và mô tả, chạy physical Media ingest,
semantic research/enrichment, canonical Media/Asset/Usage reuse, representative
reconciliation và VisualSupportRequirement reverse reconciliation; mọi
Knowledge/Source/Evidence candidate hoặc relation plan vẫn planning/governed.

Intent này không tạo Article, không tạo Post giả, không tạo owner mới và không
cho phép direct Media writer làm normal path. Registry, capture route,
replay/idempotency, client/runtime availability và no-Article acceptance phải
được cập nhật đồng bộ. Khi chưa có amendment và executable registry, mọi
`MEDIA_ENRICHMENT` request phải fail closed như `CONTENT_INTENT_UNAVAILABLE`;
không giả làm `KNOWLEDGE_DELTA`.

### 4.2. Single-real-image `IMAGE_ARTICLE`

Đề xuất amendment hẹp cho `IMAGE_ARTICLE` có đúng một eligible real Media:

- Media đó là `FEATURED_PRIMARY`;
- không mint duplicate binary/Media;
- không tạo placeholder thứ hai;
- không lặp cùng binary trong body chỉ để đủ quota;
- Article có thể đạt visual completeness khi các gate khác PASS.

Article nhiều ảnh vẫn có một hero/featured và các supporting/gallery usage
theo editorial order; inline placement chỉ xuất hiện khi có nhu cầu thực.
Amendment không mở rộng sang Article types khác. Cho tới khi owner duyệt và
registry/gate/tests được cập nhật, luật hiện hành về hai usage/placeholder vẫn
kiểm soát implementation.

## 5. Canonical submission và widget transport boundary

### 5.1. Hai nút, một Capture

UI có một mô tả, một ordered file selection và hai explicit actions:

- `Tải ảnh lên` → `MEDIA_ENRICHMENT` sau amendment;
- `Tạo bài viết` → `IMAGE_ARTICLE`.

Cả hai đều đi qua `nhk.capture.ingest`. Khác biệt là Content Intent và owner
branches cần thiết, không phải endpoint/uploader/store thứ hai.

### 5.2. Physical widget upload là adapter, không phải submission completion

`media-widget-upload` chỉ được phép:

1. validate trusted reference và materialize bytes;
2. validate actual file và tạo/adopt native attachment;
3. tạo/reuse canonical Media/MediaAsset theo physical boundary;
4. read back attachment/Media/public derivative;
5. trả ordered receipt dùng để Capture nhận/reuse Media IDs.

Nó không được tạo Article, semantic enrichment, Knowledge, Source/Evidence,
Graph relation, Governance proposal hoặc publication. Physical success không
đồng nghĩa canonical user submission complete. Khi Capture thất bại sau
transport, successful Media vẫn là reusable canonical record; không xóa ngầm
để bù trừ. Abandoned staged Media chỉ được cleanup khi có lifecycle/retention
policy riêng được duyệt.

Replay final action phải reuse staged `media_ids`, không re-download/re-upload.
Capture phải kiểm tra từng Media active, attachment bind và canonical read-back;
unknown, duplicate, unbound hoặc wrong-scope ID fail closed.

### 5.3. Partial multi-image semantics

Batch per-item, không all-or-nothing:

- giữ attachment/Media thành công;
- lưu failed item với typed diagnostic, không log private URL/path;
- replay chỉ resume child failed/incomplete;
- không upload lại child đã thành công;
- `Tải ảnh lên` trả partial/non-complete cho tới khi mọi required item đạt
  boundary dự kiến;
- `Tạo bài viết` có thể tạo draft theo policy, nhưng không được bỏ ảnh lỗi để
  publish album N−1;
- thay đổi file set dưới cùng idempotency key là `IDEMPOTENCY_CONFLICT`, không
  phải lệnh remove failed image.

Không auto-reduce album từ N xuống N−1. Muốn file set mới phải là payload mới,
idempotency key mới hoặc một continuation được contract cho phép.

## 6. Shared semantic research / editorial enrichment core

Shared core là bounded read/planning orchestration dùng lại bởi Media, Video,
Knowledge và Article; domain owner vẫn giữ writer.

### 6.1. Input và resolution

Input packet gồm Capture ID/revision, Content Intent, mô tả, explicit subject
hints, normalized observations, canonical Media/attachment IDs, Video reference
nếu có, existing Article target, documentation checkpoint và runtime capability
snapshot.

Subject precedence là canonical UUID → stable key → exact registered
name/alias → bounded semantic resolve → ambiguity diagnostics. Không broaden
Variant thành Model/Brand vì title, filename, keyword hoặc visual similarity.
Một immutable typed resolution packet được truyền xuống mọi child; child không
được tự resolve lại sang subject rộng hơn.

Graph chỉ discovery. Claim reuse phải giữ Claim UUID/revision, subject gốc,
scope, provenance, evidence status, relevance và explainable relation path.

### 6.2. External Internet research boundary

Runtime MCP hiện không được giả định có general Internet search/fetch. External
Research là read-only orchestration/provider:

- search snippet chỉ discovery, không phải Evidence;
- public claim cần Source canonical, Evidence canonical, locator/content
  support, subject scope, time scope khi có và conflict state;
- generated summary, OCR, caption, transcript hoặc AI confidence không tự là
  Evidence;
- provider unavailable chỉ không block khi internal canonical evidence đủ cho
  mọi mandatory public claim;
- required unsupported claim + provider unavailable block publication;
- mỗi Capture có bounded query/result/source budget;
- reliable-source conflict giữ claim ngoài normal public copy cho tới khi
  Article mô tả disagreement chính xác hoặc owner review xử lý.

External provider không được trở thành semantic owner và không ghi thẳng
Knowledge/Source/Evidence. URL/locator phải qua owner boundary và privacy
policy.

### 6.3. Composition, claim trace và managed sections

Composer chỉ dùng user input, classified Media observations và selected
canonical Claims. Generated prose không trở thành Evidence. `claim_trace` chỉ
giữ Claim ID/revision, Source/Evidence IDs khi cần, subject/revision,
provenance, scope, composition revision và không duplicate full prose.

Managed section phải có logical stable identity từ registry, projected-content
fingerprint và dependency binding. Fingerprint/state binding bao gồm canonical
IDs/revisions, Claim trace, MediaUsage revision, VisualSupportRequirement
revision, SEO/Public Identity revision nếu được tiêu thụ và native Article
state token.

Apply/reprojection chỉ được phép khi current section vẫn match expected
fingerprint/token. Block/Gutenberg phải parse structurally theo marker/section
identity; không raw string replace brittle. Nếu human đã sửa managed content:

- dừng targeted reprojection;
- trả editorial conflict/review;
- không overwrite chỉ vì stable section ID còn đó;
- claim trace exact generated span trở thành stale/detached khi bị sửa
  materially.

Human-authored blocks được giữ verbatim. Reorder Article trong WordPress làm
current native order thắng historical Capture plan; replay không revert order.

## 7. Resumable recovery, idempotency và CAS

WordPress, Media, Governance, Public Identity và frontend không chia sẻ một
global transaction. Capture phải dùng phase journal/receipt, không giả lập
global rollback.

### 7.1. Phase receipt

Mỗi child phase có idempotency binding, start/completed/failed state, canonical
read-back và retry boundary. Tối thiểu observability body-free gồm:

`capture_id`, `capture_revision`, hash/reference của idempotency key, physical
upload receipt, attachment/Media IDs, stage status, dependency fingerprint,
read-back status, typed failure code, retry boundary và render/public probe.

Một child đã valid mà child sau thất bại phải là `PARTIAL`, giữ read-back và
resume từ failed boundary. Chỉ temporary file/incomplete infrastructure
artifact được cleanup khi owner contract cho phép. Semantic reversal/deletion
phải dùng governed lifecycle của owner; không ad-hoc delete canonical owner.

### 7.2. Binding matrix

| State cần bảo vệ | Rule bắt buộc |
|---|---|
| Capture idempotency | atomic reservation của key + payload fingerprint; identical concurrent request serialize/reuse |
| Capture row | repository revision CAS, stale revision fail closed |
| WordPress Post | native `state_token`/CAS trước managed write; read token sau write |
| Media | canonical UUID/stable key + Media revision CAS |
| MediaUsage | phải có canonical usage revision/CAS trước implementation; current runtime gap |
| representative | usage/representative revision CAS; current updater gap phải được đóng |
| VisualSupportRequirement | exact requirement revision CAS, Media revision binding |
| Governance Proposal | proposal revision, expected subject revision, content/dependency fingerprint và approval binding |
| Public Identity | owner revision, collision audit, idempotency và one-hop history policy |
| Human edit | token/fingerprint mismatch → `EDITORIAL_CONFLICT`, không overwrite |

Sau native write nếu token đổi, refresh current state và chạy lại bounded
reconciliation một lần; không replay vô hạn. Acceptance phải có two-client /
two-connection races cho Capture, Post, usage/representative và managed
section khi boundary hỗ trợ.

## 8. Media identity, usage, title và anchor

### 8.1. Media/Asset/Usage rules

One binary có thể có một canonical Media, một private source-original và nhiều
derivatives/usages. Checksum chỉ là duplicate candidate. Không tự merge Media
semantic identity nếu subject/provenance/context chưa chứng minh cùng identity.

MediaUsage giữ contextual role, subject/scope, alt, caption, placement,
readiness và provenance theo contract. `FEATURED_PRIMARY`, `INLINE_PRIMARY`,
`INLINE_SUPPORTING`, `REPRESENTATIVE`, `EVIDENCE`, `TECHNICAL_DETAIL` và các
registered compatibility roles không được tự mở rộng từ UI.

### 8.2. Contextual title ownership decision

Inventory xác nhận `MediaUsage` hiện **không có `title`**. Vì Article title của
ảnh là Article-scoped, spec chọn WordPress Article managed placement/block
metadata làm owner hiện tại cho contextual image title. Global Media/gallery
dùng `Media.canonicalName` khi không có một Article-scoped target rõ ràng.

Không overload WordPress attachment title và không tạo duplicate title vừa ở
MediaUsage vừa ở Article. Nếu tương lai cần title dùng chung xuyên nhiều
Article, phải có contract/data-model extension hẹp trước code và migration;
không invent field trong implementation slice này.

### 8.3. Article-scoped anchor identity

Media ID không phải Article anchor. Anchor là Article-scoped placement identity,
ưu tiên existing stable `MediaUsage` UUID hoặc deterministic placement identity
đã được contract đăng ký. WordPress body sở hữu actual HTML/Gutenberg anchor;
projection chỉ tham chiếu.

- reorder không đổi anchor;
- cùng Media trong hai Article tạo hai anchors khác nhau;
- cùng Media xuất hiện nhiều lần trong một Article cần placement identity riêng,
  không dùng array index và không dựa vào short ID không có collision proof;
- current usage unique key chỉ cho một Media + endpoint + role, nên multiple
  placements/usage revision là `CONTRACT_EXTENSION_REQUIRED` trước code.

### 8.4. One Media, many Articles

Trong Article, image title link chỉ đi tới Article canonical URL + anchor của
Article hiện tại. Trong global gallery, chỉ link Article khi có đúng một
eligible, unambiguous editorial target theo projection policy. Nếu nhiều usage
ngang nhau và chưa có approved primary target, title là plain text. Không chọn
newest/oldest Post tùy tiện và không tạo Graph relation mới chỉ để sửa UI.

Featured Article image và entity representative là hai presentation decision
khác nhau. Một Media có thể thỏa cả hai, nhưng Article `featured_media` không
tự overwrite entity representative.

## 9. Representative và VisualSupportRequirement

Representative reconciliation ưu tiên exact subject specificity → visual
coverage → technical relevance → quality/resolution → provenance confidence →
current representative quality. Candidate Component/Variant không tự đại diện
Model/Brand rộng hơn. Existing better representative là `KEEP`; candidate mới
chỉ promote khi so sánh đầy đủ; old usage chỉ demote nếu vẫn suitable, không
xóa Media/Asset/provenance.

Visual support reverse reconciliation match đúng:

`subject + scope + facet + feature_key + visual_intent`

Không match filename, keyword, same brand, checksum, visual similarity hoặc
recency. `MISSING`, `RESOLVED`, `REVIEW_REQUIRED` là ledger state; requirement
không phải Claim/Evidence/Graph. Missing optional visual không block text-safe
Article; `REQUIRED_FOR_PUBLICATION` missing block đúng publication policy.

Khi Media public-ineligible, frontend không được chọn derivative đó; Article/
Entity reconcile sang Media eligible khác hoặc honest missing state. Mandatory
image giữ publication blocked. Không tự xóa source-original/provenance.

## 10. Upload security, privacy và remote URL boundary

Server là authority, không tin extension/client MIME. Mỗi item phải có:

- actual byte count, signature/MIME sniff và decoder verification;
- allowlist format runtime; SVG/active/vector fail closed nếu chưa có sanitizer
  contract;
- decoded pixel, memory, CPU/time và compressed-byte limits;
- safe generated filename, storage containment và checksum read-back;
- public derivative mặc định strip GPS/private EXIF/IPTC và metadata không cần
  thiết; metadata hữu ích chỉ lưu protected observation khi contract cho phép;
- public response đúng `Content-Type: image/webp` và `X-Content-Type-Options:
  nosniff`;
- không log trusted download URL, local path, credential, temporary filename
  hoặc private source content;
- batch limits 1..20 và 50 MB lấy từ runtime policy/registry, UI chỉ là hint,
  server enforce lại.

Remote URL import là **OUT OF SCOPE** cho feature này; canonical path là native
files hoặc trusted provided-file transport. Nếu một future slice bật import,
đó là security boundary riêng: chỉ HTTP/HTTPS, block loopback/private/link-local/
metadata destination, validate mọi redirect target, bounded redirect/bytes/
timeout và không cho URL chọn filesystem path. Không tự downgrade trusted-file
path thành remote import.

## 11. Article composition, publication và auto-public

Article composition tạo tối đa một native Post cho Article intent. WordPress
giữ title, body, excerpt, author, dates, category, slug, editorial image order
và public URL. Không copy full body sang semantic store.

Publication unit phải có current Post read-back, semantic read-back, Media/
MediaAsset/MediaUsage read-back, representative/visual dependencies, SEO/
canonical checks, claim compliance, route/rendered verification và stable final
dependency fingerprint. Stored DTO pass không đủ.

Owner preference auto-public khi final PASS chỉ là target capability. `publish=true`
chỉ được honor khi exact deployed runtime chứng minh mọi gate sau đều available
và PASS:

- subject/public identity unambiguous và collision-free;
- rights và binary/derivative valid;
- semantic/Governance dependencies applied, readable và revisions stable;
- compliance classification/evidence validation đầy đủ cho mọi output channel;
- Article, MediaUsage, SEO/canonical và rendered route pass;
- public `/anh/{slug}.webp` read-back pass;
- publication receipt bind policy/compliance version.

Thiếu automated compliance capability không được convert thành PASS; trước khi
runtime verification hoàn tất, boundary là draft/review/owner approval. Không
auto-upgrade `OWNER_REVIEW_REQUIRED` hoặc `AUTO_PUBLIC_UNAVAILABLE`.

## 12. Public image UX và SEO

Canonical public image là `/anh/{slug}.webp`, được derive trực tiếp từ
source-original, max long edge 1200 px, giữ aspect ratio, không upscale/crop/
stretch, WebP quality mặc định 86. Source-original PRIVATE; thumbnail không
được làm canonical full-size.

Image click mở canonical WebP trong progressive-enhancement lightbox; không
JS vẫn dùng `<a href="/anh/{slug}.webp">`. Title click đi Article + anchor khi
eligible; hai interaction không dùng cùng URL.

Lightbox phải là real modal dialog với `role=dialog`, `aria-modal=true`,
accessible name, focus vào dialog, Tab/Shift+Tab trap, Escape close, visible
close button, return focus tới invoking thumbnail/title, labelled previous/next
và disabled/end-state, caption programmatically associated, ArrowLeft/Right
navigation và single-image behavior. Caption theo từng item, gallery giữ
current/total. Đây là progressive enhancement, không thay thế fallback link.

Public asset acceptance/read-back phải kiểm tra HTTP 200, expected MIME WebP,
`nosniff`, dimensions, checksum/asset binding nếu có, không lộ private source
URL, click target canonical WebP, không có standalone HTML image page và image
sitemap (nếu bật) chỉ tham chiếu eligible image từ canonical content page.

Stable public slug phải có cache policy nhất quán: immutable bytes per slug,
hoặc validated replacement có ETag/Last-Modified và purge/revalidation. Không
thay bytes âm thầm sau cache dài hạn.

Sitemap/indexability đi theo canonical owner → public identity/native URL →
eligibility → indexability → sitemap. Exclude private, ambiguous, incomplete,
redirect/history, technical endpoint, asset delivery URL và compliance-blocked
projection.

## 13. Failure vocabulary và observability

Fail closed cho authentication/permission, stale documentation checkpoint,
runtime/client capability gap, ambiguous identity, corrupt file, rights failure,
route collision, mandatory dependency unavailable, Governance ineligible,
controlled apply failure, human edit conflict và rendered route unavailable.

Phải phân biệt ít nhất:

`EMPTY_RESULT`, `DEPENDENCY_UNAVAILABLE`, `OWNER_REVIEW_REQUIRED`,
`CLIENT_EXPOSURE_GAP`, `EDITORIAL_CONFLICT`, `IDEMPOTENCY_CONFLICT`,
`PARTIAL`, `SYSTEM_BLOCKED`, `SERVER_TOOL_EMPTY_RESULT`,
`CAPTURE_NOT_COMPLETE` và infrastructure failure.

`SERVER_TOOL_EMPTY_RESULT` chỉ mô tả transport/result mapping symptom; không
được collapse với failed Capture, failed Media read-back hoặc publication
failure. Diagnostic body-free phải chỉ ra stage, receipt, owner IDs, retry
boundary và read-back status.

## 14. Acceptance matrix — tests before implementation

### Core and owner boundaries

1. Media-only một ảnh không Article.
2. Media-only nhiều ảnh không Article.
3. Một ảnh tạo đúng một Article sau amendment.
4. N ảnh tạo đúng một Article và giữ order.
5. Normal submission không gọi direct Article/Media/Video/Knowledge/Graph writer.
6. Same key + same payload reuse một Capture/owner.
7. Same key + changed payload trả `IDEMPOTENCY_CONFLICT`.
8. Two concurrent identical Capture requests serialize/reuse một Capture.
9. Replay không re-upload completed binary.
10. Unknown/ambiguous subject fail closed.

### Partial/recovery/CAS

11. Batch five ảnh, một corrupt: bốn success được giữ, không publish album bốn ảnh.
12. Replay chỉ resume failed child.
13. Physical transport success rồi Capture fail: replay reuse Media, không duplicate.
14. Cross-owner failure trả `PARTIAL` và phase receipt resumable.
15. Capture stale revision fail closed.
16. WordPress state-token race không overwrite human edit.
17. MediaUsage/representative revision race fail closed.
18. VisualSupportRequirement stale revision fail closed.
19. Proposal revision/fingerprint mismatch không apply.
20. Human reorder Article survives replay.

### Media and semantic safety

21. Same checksum chỉ là duplicate candidate, không semantic auto-merge.
22. Existing better representative là `KEEP`.
23. Technical-detail image không đại diện broad subject.
24. Exact VisualSupportRequirement resolve đúng scope/facet/feature/intent.
25. Caption/alt khác theo usage, Media identity vẫn reuse.
26. Source-original không public.
27. Public WebP không vượt 1200px long edge.
28. Không upscale/crop/stretch.
29. Generated prose không persist thành Evidence.
30. Search snippet không satisfy Evidence.
31. External research unavailable + internal evidence đủ vẫn proceed.
32. External research unavailable + required unsupported claim block publish.
33. Rights revoked removes public selection, preserves original/provenance.
34. Public derivative strips GPS/private EXIF/IPTC.
35. Small compressed bomb bị reject bởi decoded-pixel/resource budget.
36. SVG/active format reject nếu thiếu sanitizer.
37. Runtime limits enforce lại server-side.

### Anchor/title/reuse

38. Image title trong Article trỏ đúng Article-scoped anchor.
39. Reorder không đổi anchor.
40. Một Media trong hai Article tạo hai anchor.
41. Repeated placement không dùng array index/short Media ID collision-prone.
42. Global gallery nhiều Article ngang nhau render title plain text.
43. Global gallery chỉ link một target khi target unambiguous/eligible.
44. Không tạo Graph relation chỉ để giải quyết title-link UI.
45. Contextual title dùng đúng Article placement owner, không duplicate field.
46. Article featured image không overwrite entity representative.

### Managed sections and publication

47. Managed update replace đúng section, không append duplicate.
48. Human-authored block giữ nguyên.
49. Human sửa generated prose làm claim trace stale/detached.
50. Fingerprint stale block targeted apply.
51. Single-image Article không placeholder/duplicate Media sau amendment.
52. Conflicting claim bị loại khỏi public copy.
53. Optional visual missing không block text-safe Article.
54. Required visual missing block publication.
55. Auto-public compliance capability unavailable → không publish.
56. `OWNER_REVIEW_REQUIRED` không upgrade thành PASS.
57. Rendered route unavailable khác `EMPTY_RESULT`.
58. Auto-public chỉ sau stored + rendered + public-route read-back.

### Transport, public route và accessibility

59. Widget upload success → Capture consumes same ordered Media IDs.
60. `SERVER_TOOL_EMPTY_RESULT` không bị coi là semantic success.
61. Public asset trả HTTP 200, WebP và `nosniff`.
62. Canonical click target là `/anh/{slug}.webp`, không là Article.
63. Không tạo standalone image HTML page/sitemap URL.
64. Lightbox dialog có focus trap, Escape và return focus.
65. Previous/next, caption và keyboard labels đúng item hiện hành.
66. JS disabled vẫn tải canonical WebP qua anchor.
67. Remote URL import out-of-scope không được tự kích hoạt.

## 15. Rollout và human gates

### Slice 1 — inventory/read-only

Hoàn tất branch/status/remote/fetch, executable registry, Capture coordinator,
Capture repository/state machine, idempotency, physical upload/widget path,
Media schema/registry, Article gateway/CAS, managed section handling,
representative/VisualSupport reverse reconciliation, SEO/publication/rendered
route, `/anh/` route, gallery/lightbox, deployment wrapper và observed tests.

### Slice 2 — spec và amendments

Owner duyệt spec này; sau đó mới xử lý constitutional amendment cho
`MEDIA_ENRICHMENT` và single-real-image `IMAGE_ARTICLE`, executable registry,
contract tests và contextual title/usage-anchor data-model decision.

### Slice 3 — implementation plan

Chỉ sau owner approval. Plan phải nêu exact file/class/method/test, migration
nếu thật sự cần, CAS/receipt changes, no-go boundaries và release order.

### Slice 4 — tests first

Viết failing tests cho amendment, transport/Capture handoff, partial batch,
recovery/CAS, managed sections, title/anchor ambiguity, security/privacy,
lightbox accessibility và auto-public capability gate trước production code.

### Slice 5 — shared core và vertical release

Triển khai theo vertical slice nhỏ, reuse owner hiện hữu, Governance lifecycle,
canonical read-back và runtime documentation checkpoint. Không migration,
seed, backfill, deploy hoặc semantic mutation trong bước thiết kế này.

Final production cutover luôn cần Cutover Readiness Report và human gate; spec
này không phải authorization cho cutover.
