# Specimen Catalogue Relationship Extension — Approved-Design Candidate

Ngày: 2026-10-09
Baseline: adae28f08267ed479e8d2d6a4f966e7a94ca5bbb (main)
Trạng thái: DESIGN_CANDIDATE / NOT_ACTIVE / NO_RUNTIME_MUTATION

## 1. Phạm vi và địa vị

Đây là candidate cho thiết kế được phê duyệt, không phải ACTIVE contract,
registry snapshot hay quyền ghi dữ liệu. Tài liệu này không thay Constitution,
không đăng ký predicate, không thêm schema field, không migrate và không tạo
dữ liệu nghiệm thu.

Owner hiện hành không đổi:

| Owner | Trách nhiệm |
|---|---|
| Brand / Model / Variant / Clock Type | Authority identity và phân loại canonical |
| Specimen | Một chiếc đồng hồ vật lý cụ thể, provenance, condition và nhận diện |
| Product | Một listing/offer và lifecycle thương mại |
| Graph | Hệ quan hệ semantic duy nhất |
| Knowledge | Atomic claim đúng scope, không sao chép claim chung |
| Source / Evidence | Provenance và support |
| Media / MediaAsset / MediaUsage | Binary, asset và usage tách biệt |
| Video | External source identity và nội dung video |
| Governance | Proposal, approval, eligibility, Controlled Apply và audit |
| Public projection | Public Identity và read model, không lộ UUID/stable key |

## 2. Kết luận kiến trúc

Hai quan hệ dưới đây là tên candidate được đề xuất. Chưa predicate nào là
ACTIVE ở checkpoint này.

| Quan hệ candidate | Source → target | Cardinality | Ý nghĩa |
|---|---|---|---|
| specimen_of | Specimen → Variant hoặc Model | Mỗi Specimen tối đa một identity edge active; Model/Variant nhận nhiều Specimen | Nhận diện chiếc vật lý |
| lists_specimen | Product → Specimen | Mỗi Product tối đa một; một Specimen nhận nhiều Product theo thời gian | Listing/offer gắn đúng chiếc vật lý |

Quy tắc chung:

1. Đây là quan hệ Graph có typed endpoint, revision, lifecycle, provenance,
   Evidence và idempotency; không lưu ở postmeta, taxonomy, Product payload,
   specimen_uuid hay broad about.
2. Mọi apply đi qua Governance. GraphService/controlled adapter chỉ nhận
   chúng sau khi registry, RelationPolicy và operation policy được mở rộng.
3. Duy nhất một identity edge specimen_of active được tồn tại. Retired edge
   giữ để audit, không hard-delete.
4. Không tạo edge ngược chỉ để query; reverse traversal là read concern.
5. Không suy diễn Brand trực tiếp từ Specimen. Brand chỉ đọc qua
   Variant → variant_of → Model → model_of → Brand hoặc Model → model_of →
   Brand theo chain hiện hành.
6. specimen_of không diễn đạt compatible_with, fits, similar_to hay “cùng dòng”.
   Compatibility vẫn là payload boundary hiện có cho đến khi contract riêng được
   phê duyệt.

## 3. A — Specimen → Model/Variant

### 3.1 Predicate, direction và target

Candidate predicate là specimen_of, source Specimen, target chính xác một trong
Variant hoặc Model.

- Specimen → Variant: chiếc vật lý nhận diện tới biến thể cụ thể.
- Specimen → Model: nhận diện tới model nhưng chưa đủ bằng chứng chọn Variant.
- Không đồng thời Specimen → Model và Specimen → Variant active.
- Target Variant dẫn xuất Model/Brand qua owner chain; không ghi shortcut
  Specimen → Model hoặc Specimen → Brand.
- Target Model không được public projection hiển thị như Variant đã xác minh.

### 3.2 Model, Variant và compatibility

Model là identity level của dòng/model. Variant là child identity level do Model
sở hữu. Theo Constitution, Variant có đúng một Model parent và Model có đúng một
Brand parent; parent phải active, revision hợp lệ và chain không conflict.

Trường Specimen.model_uuid hiện có chỉ là compatibility payload boundary. Nó
không trở thành canonical edge khi specimen_of được mở:

- Nếu model_uuid trùng target Model hoặc Model dẫn xuất từ Variant, ghi nhận
  COMPATIBILITY_MATCH trong diagnostic/read model, không tạo duplicate edge.
- Nếu khác target canonical, phát hiện DATA_COMPATIBILITY_GAP/identity conflict
  và block public eligibility; không tự sửa payload hoặc ưu tiên payload hơn
  Graph.
- Không backfill hàng loạt từ model_uuid trong bước này.
- Không thêm predicate compatible_with vào extension này.

### 3.3 Cardinality và lifecycle

| Trạng thái | Quy tắc |
|---|---|
| Chưa nhận diện | Không có specimen_of; giữ ở intake/review |
| Nhận diện Model | Một edge Specimen → Model active |
| Nhận diện Variant | Một edge Specimen → Variant active; Model/Brand dẫn xuất |
| Đổi phân loại có bằng chứng | Governance replace: retire cũ và add mới trong controlled operation |
| Hai target active | SPECIMEN_IDENTITY_CONFLICT; block apply/public |
| Parent inactive/retired/conflict | Không đủ eligibility; không auto-repair |

Đổi Model, đổi Variant hoặc đổi Model parent là high-impact identity operation.
Edge cũ phải còn audit trail gồm actor, reason, Evidence, revision và thời điểm.
Retired không tự resurrect vì replay cùng triple; reactivation là operation rõ
ràng và phải pass eligibility hiện tại.

### 3.4 Provenance, Evidence và revision

Mỗi edge cần relation context với:

- exact source/target UUID;
- source_revision, target_revision và expected revisions;
- scope code riêng cho identity observation;
- provenance phù hợp: EXPLICIT_USER_KNOWLEDGE, OBSERVED_FROM_MEDIA,
  CATALOG_SUPPORTED hoặc EXTERNAL_RESEARCH;
- Evidence reference có locator/excerpt/subject scope;
- actor/approval, proposal fingerprint và idempotency key;
- lý do chọn Model hay Variant; confidence không thay thế Evidence.

Ảnh/video chỉ là provenance khi Media/Video contract và Evidence xác nhận nói về
đúng chiếc. Ảnh giống hình không đủ để tạo identity edge. SYSTEM_INFERENCE chỉ
tạo candidate/review packet, không tự Controlled Apply identity.

### 3.5 Conflicting identity

Phải dừng ở review khi có hai Variant được xác nhận, parent không nhất quán,
model_uuid khác Graph target, cùng serial/provenance nhưng có hai Specimen,
source nói Model A/B, hoặc target/revision đã stale. Không chọn target đầu tiên.

Conflict packet giữ Capture/request, candidates, dependency closure, Evidence,
revisions, registry fingerprint, expiry và idempotency. Resolve là delta có lý
do trên cùng subject; không tạo Specimen hoặc Video mới vì identity conflict.

## 4. B — Product ↔ Specimen

### 4.1 Predicate, direction và semantic boundary

Candidate predicate là lists_specimen, source Product, target Specimen:

- Một Product có 0..1 Specimen.
- Một Specimen có 0..N Product trong lịch sử.
- Product cụ thể phải resolve đúng một Specimen trước semantic completeness.
- Product generic/pre-specimen có thể không có edge nếu Product contract cho phép;
  public phải nói rõ listing chưa gắn hiện vật cụ thể.
- Multi-object listing không được suy diễn thành một edge. Bundle là contract
  riêng nếu sau này cần.

lists_specimen không mang giá, availability, title, vendor hay condition
canonical của Specimen. Field thương mại vẫn thuộc Product; physical condition,
serial và provenance vẫn thuộc Specimen.

### 4.2 Bán, relist và lịch sử

- Sửa giá, availability, title, vendor copy hay thời gian bán không tạo
  Specimen mới và không đổi identity edge.
- Archive/expire/sold Product không xóa Specimen hoặc relation history.
- Cùng commercial context được tái hoạt động thì update/reactivate Product hiện
  có; không tạo bản sao chỉ để tăng số listing.
- Commercial context mới có thể tạo Product mới và link cùng Specimen.
- Product đã bán giữ historical link; public hiển thị tùy visibility policy,
  không giả vờ là offer đang active.
- Reassignment Product từ Specimen A sang B là high-impact replacement: retire
  cũ, add mới, Evidence và lý do.
- Product merge không merge Specimen.

### 4.3 Dedupe và chống liên kết sai

Idempotency của relation phải bind Product UUID, Specimen UUID, operation family,
current revisions, dependency closure và Capture/request fingerprint. Unique
triple của Graph chặn duplicate vật lý; replay cùng packet trả canonical read-back.

URL/title giống nhau, Model/Variant giống nhau, ảnh dùng lại, listing copy,
giá/vendor/thời gian giống nhau không tự chứng minh cùng một hiện vật. Specific
link cần exact-object evidence như serial/marking, ảnh có đối chiếu, provenance
nhập kho hoặc user confirmation có thẩm quyền. Candidate 0 hoặc >1 phải giữ
diagnostic PRODUCT_REQUIRES_SPECIMEN hoặc PRODUCT_SPECIMEN_CONFLICT; không chọn
theo xếp hạng mơ hồ. Listing copy không phải Knowledge/Evidence.

## 5. Knowledge, Video và Media đúng scope

Claim chung về Model/Variant lưu một lần ở subject đúng scope. Serial, condition,
repair, provenance hay observation của một chiếc lưu ở Specimen scope. Không copy
claim Variant xuống hàng nghìn Specimen.

Read service có thể lấy claim Model/Variant làm context dẫn xuất nếu path và
visibility cho phép, nhưng giữ subject scope và nhãn direct/derived. Claim
Specimen không promote ngược lên Variant, Model hoặc Brand chỉ vì lặp lại.

Mỗi external source identity vẫn là một Video riêng. Nhiều Video có thể about
cùng Specimen nếu Evidence/subject packet đúng. Video không phải owner Specimen
và không chuyển subject sang Variant chỉ vì Variant là context.

Media/Asset được dedupe ở binary layer; MediaUsage phân biệt representative,
evidence và technical usage. Reuse asset không tự sinh Graph edge hoặc Evidence.

### Scale hàng nghìn Specimen

Một Authority/Graph identity cho mỗi chiếc, tái sử dụng Variant, Model,
Knowledge và Media. Read path đề xuất:

1. archive dùng keyset/cursor, không hydrate toàn bộ authority table;
2. incoming traversal dùng target/predicate indexes hiện có, batch target IDs;
3. filter Brand/Model/Variant/Clock Type dùng tập target canonical rồi intersect
   với trang Specimen/Product;
4. detail hydrate direct subject trước, derived context tối đa theo public law;
5. Video/MediaUsage/Knowledge query theo exact subject và paginate độc lập;
6. chỉ đề xuất additive index/read model sau benchmark chứng minh index hiện có
   không đủ, không tạo denormalized semantic owner.

## 6. Workflow end-to-end candidate

1. Capture ảnh, video, text và intent trong một Capture bền vững; giữ source
   identity và idempotency.
2. Resolve/reuse Brand → Model → Variant và Clock Type bằng registry hiện hành;
   canonical UUID trước, stable key/alias sau. Chưa có Authority thì proposal
   owner riêng, không invent type.
3. Establish Specimen: duplicate audit theo serial/marking/provenance/media;
   reuse exact physical match hoặc tạo/review Specimen. Không dùng Product để
   tạo physical identity.
4. Lập specimen_of ở level Model hoặc Variant, attach Evidence, verify parent
   chain và revisions; không add Brand shortcut.
5. Apply classified_as Clock Type riêng nếu đủ bằng chứng.
6. Ingest Media/Asset và tạo MediaUsage đúng Specimen; Usage không tự là Graph.
7. Tạo/reuse Video theo external source; video của chiếc cụ thể target Specimen.
8. Lưu Knowledge chung ở Model/Variant, observation riêng ở Specimen; giữ
   support/provenance.
9. Proposal → Human Approval → Eligibility → Controlled Apply → canonical
   Graph/Authority/Video/Media read-back. Bind Capture/request, packet,
   revisions, dependency closure, expiry và idempotency.
10. Khi có yêu cầu bán, tạo/reuse Product cho commercial context; specific
    listing lập lists_specimen, generic listing không gắn bừa Specimen.
11. Read-back Public Identity, route, eligibility, direct/derived labels,
    media/video/knowledge scope và Product history. Ingest success không là
    completion.

## 7. Public catalogue và truy xuất

### /hien-vat/

Archive card là physical object: public name/slug, verified Model/Variant nếu có,
derived Brand khi chain hợp lệ, Clock Type, representative media và video
indicator chỉ khi query thật có kết quả. Filter: Brand, Model/dòng, Variant,
Clock Type, có video/media verified và public eligibility.

Detail /hien-vat/{slug}/ gồm identity evidence, physical/provenance/condition
được phép public, direct/derived labels, nhiều video, media usages, Knowledge
đúng scope và lịch sử Product. Không hiển thị UUID, stable key, revision, packet
hay internal diagnostic.

### /san-pham/

Archive card là commercial offer: vendor, listing title, price/currency,
availability/offer state và canonical Product identity. Filter: vendor/state,
Brand/Model/Variant/Clock Type qua relation/read path đã xác minh, có hiện vật
cụ thể và current/historical listing.

Detail /san-pham/{slug}/ giữ commercial truth. Có lists_specimen hợp lệ thì link
public Specimen; Product generic được phép thì copy rõ listing chưa gắn hiện vật.
Không suy physical condition/serial từ listing copy.

Dictionary links dùng lexical/facet projection hiện có, không làm semantic owner.
Mỗi page có một canonical route và structured data chỉ từ eligible data. Empty,
unavailable, conflict và contract gap phải phân biệt. Không lộ UUID/stable key qua
HTML, JSON-LD, URL, search facet hay debug payload.

## 8. Acceptance case — ÔĐô 36/8

Input: YouTube
https://youtube.com/shorts/MJ82AhKAwLk?feature=share; Capture
01a120b2-6987-7f78-bb74-20640cdddfad. Ngữ nghĩa đã xác định: video của một
chiếc ÔĐô 36/8 cụ thể, không phải video đại diện cho toàn Variant.

Checkout không chứa fixture này và authorized WP runtime chưa sẵn sàng; phần
này là continuation design, không phải claim đã nghiệm thu.

Nếu same-type candidates tạo SUBJECT_CONFLICT_REVIEW_REQUIRED, giữ Capture ở
review-required: không tạo Video, Variant child, about edge, Specimen duplicate
hay public projection.

Continuation hợp lệ phải dùng explicit resolved-subject path hiện có:

1. Đọc lại exact Capture và kiểm tra status/request/idempotency.
2. Dùng server-owned resolved reconciliation packet với status=resolved và
   authority USER_CONFIRMED_SUBJECT_RECONCILIATION hoặc
   GOVERNED_SUBJECT_RECONCILIATION.
3. Packet chứa exact Specimen target, Variant/Model context nếu cần, Evidence và
   current revisions.
4. Gọi persistResolvedReconciliation/shared subject handoff; không dùng generic
   candidate_uuid khi packet đã resolved.
5. Reuse cùng Capture, source identity, Video idempotency và request binding.
6. Tạo/reuse đúng một Video và about đúng Specimen sau Video/Evidence/Governance.
7. Canonical read-back xác minh Video → Specimen, không có Variant-representative
   relation và không có duplicate Capture/Video/Specimen/edge.
8. Packet khác target/revision hoặc replay payload khác thì fail closed và về
   review; immutable packet không bị sửa.

Generic candidate continuation với packet resolved phải trả
CAPTURE_SUBJECT_RECONCILIATION_NOT_AMBIGUOUS; đây là guard, không phải lý do tạo
duplicate. Variant page chỉ được hiển thị context/derived link nếu policy cho
phép, không gắn nhãn video đại diện cho toàn Variant.

## 9. Relationship matrix — before / after

| Subject → object | Baseline | Candidate sau phê duyệt |
|---|---|---|
| Variant → Brand | variant_of hiện hành | Giữ nguyên |
| Model → Brand | model_of hiện hành | Giữ nguyên |
| Specimen → Model/Variant | model_uuid compatibility; chưa canonical physical edge | specimen_of, một active target Model hoặc Variant; cần contract/registry |
| Specimen → Brand | Không canonical; chỉ derived | Không thêm; derive qua parent chain |
| Product → Specimen | ProductSpecimenAssessment read-only; chưa persistence | lists_specimen, 0..1 → 0..N; cần contract/registry |
| Video → target | about theo Video contract | Video case target exact Specimen; Video owner không đổi |
| Media → entity | MediaUsage/depicts hiện hành | MediaUsage vào Specimen; không suy identity |
| Variant/Model → Knowledge | Scope hiện hành | Giữ claim chung; không copy |
| Specimen → Knowledge | Direct observation khi contract cho phép | Giữ exact scope; không promote |
| Dictionary → Specimen | Lexical/facet read boundary | Giữ read-only facet; không owner |

## 10. Ngoài phạm vi

Không thêm Authority type, Product bundle type, Brand shortcut, public UUID,
compatible_with, reverse predicate, taxonomy owner hoặc metadata writer. Không
thay Music/Côn hoa thị. Không backfill mọi Specimen từ model_uuid. Không
migrate/import article bodies. Không runtime mutation, deploy, pull/fetch/push,
staging/production data hay final production cutover.
