# NHK V3 Constitution

**Trạng thái:** Hiến pháp kiến trúc duy nhất và tối cao của NHK V3.

**Phạm vi:** cấu trúc semantic, identity, quan hệ, nguồn sự thật, governance,
public projection, WordPress editorial boundary, MCP/Admin, frontend, health và
deployment. Văn bản này không cấp quyền thay đổi dữ liệu.

## 1. Tối cao và phạm vi hiệu lực

NHK V3 tuân theo một nguyên tắc tối cao:

> **STRUCTURE FIRST. RELATIONSHIPS FIRST. DATA LATER.**

File này là nguồn chuẩn duy nhất cho luật kiến trúc NHK V3. Mọi implementation,
specification, plan, audit, README, historical note hoặc tài liệu khác chỉ là
bằng chứng, hướng dẫn triển khai hoặc lịch sử. Nếu bất kỳ nội dung nào mâu
thuẫn với file này, kết quả là CONSTITUTION_CONFLICT và file này thắng cho
đến khi có constitutional amendment hợp lệ.

Hiến pháp này không cho phép migrate, import, parse hoặc population body bài
viết cũ; không cho phép seed, sửa, backfill, merge hoặc xóa semantic record;
không cho phép ghi Graph edge; và không cho phép thay đổi V2, staging hoặc
production. Những hành động đó cần contract, governance và gate riêng.

## Amendment record — 2026-09-02 — Article Ingest Boundary

**WHY:** A V3 knowledge Article request may cross the editorial and semantic
boundaries, but its completion claim must not be inferred from a WordPress write
or from an ungoverned semantic side effect.

**WHAT:** Approve the operation-level Article Ingest Contract with the sequence
`semantic preflight → WordPress draft → semantic Governance / Controlled Apply
→ read-back verification → WordPress publish`. This does not create an Article
Authority entity, a second editorial body, a Graph `article` endpoint, a new
status enum or a new operation name.

**AFFECTED SUBSYSTEMS:** WordPress editorial boundary, Authority, Knowledge,
Source/Evidence, Graph, Governance, MCP/Admin orchestration, Article ingest
readiness and documentation.

**COMPATIBILITY AND PUBLIC PROJECTION:** WordPress `wp_posts` remains the sole
source of truth for title/body and public editorial URLs. Registered semantic
entities, atomic Knowledge claims, provenance and typed relations remain owned
by their existing bounded contexts. Existing generic WordPress publication
remains independent; only a V3 knowledge Article completion claim is subject to
the coordinated contract.

**DATA, MIGRATION AND ROLLOUT:** No legacy article body is migrated, imported,
parsed or populated by this amendment. No existing semantic identity, slug,
URL, WordPress post, Graph edge or production/staging data is changed. Runtime
implementation, idempotency, WordPress revision binding, outcome vocabulary,
read-back and observability remain follow-up work under the approved contract.

**GOVERNANCE, TEST AND DEPLOYMENT:** Semantic mutation remains Proposal → Human
Approval → Eligibility → Controlled Apply → repository → audit. The Article
workflow must fail closed on registry, contract, governance, verification or
infrastructure failure and must be tested before implementation is accepted.

**DECISION OWNER / DATE:** NHK V3 architecture approval, 2026-09-02.

## Amendment record — 2026-09-02 — Product / Specimen Boundary

**WHY:** Product and Specimen are different identities, but the previous
runtime contract did not completely define their ownership, lifecycle,
cardinality or semantic-completeness rules. Leaving that boundary implicit
would allow a commercial listing to become a physical identity or a physical
record to become a mutable offer.

**WHAT:** Approve the narrow Product / Specimen law in §11 and the related
Knowledge, Media, Governance and acceptance-invariant clarifications. Specimen
is the canonical identity of one physical object. Product is the canonical
identity of one commercial listing/offer/context. One Specimen may have zero to
many Products over time; one Product may reference zero or one Specimen. A
specific-object Product is semantically complete only after resolving exactly
one Specimen. A generic or pre-specimen Product may exist without a Specimen,
but never owns physical identity in its place.

**AFFECTED SUBSYSTEMS:** Authority, Product/Specimen domain contracts, Graph
relationship contracts, Knowledge, Source/Evidence, Media/MediaUsage,
Governance, MCP/Admin diagnostics and public projection.

**COMPATIBILITY AND PUBLIC PROJECTION:** Product and Specimen retain separate
canonical IDs, stable keys, lifecycle and public routes. Product may present
approved Specimen-derived context, but listing copy is not physical truth and
public projections must preserve the source/scope distinction without exposing
internal identifiers.

**DATA, MIGRATION AND ROLLOUT:** This amendment authorizes no storage shortcut,
seed, inferred link, identity merge, payload repair, Graph edge, migration or
legacy-data operation. The existing broad `about` predicate is not a
Product–Specimen ownership contract. A dedicated relation may be registered
only after its semantics, endpoints, cardinality, provenance and Governance
contract are separately reviewed. Until then, physical linkage remains an
explicit REGISTRY_GAP/CODE_GAP and all current data remains untouched.

**GOVERNANCE, TEST AND DEPLOYMENT:** Physical identity, Specimen
identification/observation/provenance and Product–Specimen semantic linkage
use Proposal → Human Approval → Eligibility → Controlled Apply → repository →
audit. Commerce-only Product edits remain ordinary commerce writes unless they
also mutate semantic truth. Tests must prove identity separation, lifecycle
survival, at-most-one linkage, completeness diagnostics, claim non-promotion
and no implicit repair.

**DECISION OWNER / DATE:** NHK V3 architecture approval, 2026-09-02.

## Amendment record — 2026-09-02 — Semantic Relationship Navigation & Related Projection

**WHY:** Canonical entities are discovery entry points, not isolated pages. The
previous law distinguished direct and derived relations, but did not state the
global traversal bound, related-result ranking, deduplication,
explainability, or the boundary between Graph truth and frontend projection
strongly enough to govern every entity page.

**WHAT:** Approve §9.1, the related-navigation invariants in §26, and the
implementation contract at
`docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`. Every registered
canonical endpoint may start a bounded related query. Direct results are one
governed hop; derived results may use at most two governed hops, retain an
explainable path, remain derived, and never become a persisted shortcut.
Related candidates are filtered by Graph truth before editorial ranking and
projection limits are applied.

**AFFECTED SUBSYSTEMS:** Graph, Authority, Knowledge, Source/Evidence, Media,
Video, WordPress Post endpoint links, Public Projection, frontend query
services and MCP read orchestration.

**REGISTRY AND COMPATIBILITY:** This amendment adds no entity type, endpoint,
predicate, field, relation type, operation, MCP tool or data. The runtime
matrix and gaps are recorded in the related projection contract. `Article` is
an editorial workflow, not a canonical endpoint; Album/Collection remains a
semantic gap unless a later contract registers it. Existing registry
directionality limitations, missing traversal engine and current
Brand/related implementation divergence remain explicit implementation gaps;
they are not legalized by this amendment.

**DATA, MIGRATION AND ROLLOUT:** No Graph edge, Authority record, WordPress
Post, taxonomy, post meta, legacy article body, V2/live data or cache is
created, repaired, backfilled or rewritten. Related projection is read-only
and fail-closed on registry, identity, eligibility, provenance, readiness or
infrastructure failure.

**GOVERNANCE, TEST AND DEPLOYMENT:** Future implementation must use the
existing Graph read/repository boundaries and public eligibility/route policy.
It must prove direct-vs-derived precedence, two-hop bounds, directionality,
cycle prevention, path explainability, semantic filtering before latest or
featured ranking, honest empty/unavailable states and no taxonomy/post-meta
fallback before acceptance.

**DECISION OWNER / DATE:** NHK V3 architecture approval, 2026-09-02.

## 2. Ranh giới trách nhiệm tối cao

Mỗi subsystem chỉ sở hữu trách nhiệm được nêu dưới đây:

| Subsystem | Trách nhiệm canonical |
|---|---|
| WordPress native Post | Nội dung biên tập, tiêu đề, body, featured/content images, tác giả, ngày, category, archive, search, RSS, sitemap và permalink editorial |
| Authority | Identity và lifecycle của canonical semantic entity |
| Graph | Quan hệ semantic typed giữa các endpoint đã đăng ký |
| Knowledge | Atomic claim/fact/research statement |
| Source/Evidence | Identity nguồn và đơn vị provenance/support cho claim hoặc quan hệ theo contract |
| Media | Semantic identity của media |
| MediaAsset | Binary và derivative metadata, storage/delivery boundary |
| MediaUsage | Placement/context và role sử dụng Media |
| Video | Canonical external-video reference |
| Specimen | Identity của một hiện vật vật lý cụ thể |
| Product | Identity của listing/offer và ngữ cảnh thương mại |
| Governance | Durable semantic mutation, approval, eligibility, apply và audit |
| Public Projection | Read model, route, SEO và presentation của sự thật đã được phép hiển thị |

Không subsystem nào được âm thầm thay thế hoặc nhân bản trách nhiệm của
subsystem khác.

## 3. Từ vựng canonical

Một thuật ngữ chỉ có một nghĩa trong NHK V3.

### 3.1 Identity và presentation

- **Authority:** boundary sở hữu canonical semantic entity.
- **Canonical Entity:** đối tượng semantic bền vững thuộc một type đã đăng ký.
- **Canonical ID:** UUID ở domain/API boundary, bất biến trong suốt vòng đời.
- **Stable Key:** alternate durable identifier có scope theo entity type; cấp một
  lần và không tái sinh âm thầm.
- **Display Name:** tên phục vụ trình bày; có thể đổi mà không đổi identity.
- **Public Slug:** định danh route public đã được cấp và quản trị; không phải
  canonical semantic identity.
- **Alias:** tên hoặc key thay thế phục vụ resolution, không tạo identity mới.
- **Historic Slug:** public slug cũ được giữ để redirect một hop tới slug hiện
  hành.

Alias không phải Historic Slug: alias phục vụ resolution, còn Historic Slug là
route identity cũ có redirect policy riêng.

### 3.2 Graph và quan hệ

- **Graph Node:** endpoint được đăng ký trong EndpointTypeRegistry.
- **Graph Edge:** bản ghi quan hệ typed trong Semantic Graph.
- **Predicate:** quan hệ đã đăng ký, có allow-list source/target và cardinality.
- **Structural Relation:** quan hệ xác định lineage/cấu trúc, như Model→Brand.
- **Semantic Relation:** quan hệ mô tả capability, configuration hoặc association.
- **Provenance Relation:** association cần nguồn/bằng chứng theo contract.
- **Direct Relation:** fact được lưu trực tiếp như canonical Graph edge.
- **Derived Relation:** association được suy ra từ direct edges theo policy; không
  phải edge persistence mới.

### 3.3 Tri thức và tài nguyên

- **Knowledge Claim:** một claim/fact/research statement atomic; không phải bài
  viết dài và không phải WordPress body.
- **Source:** canonical identity của nguồn.
- **Evidence:** đơn vị hỗ trợ, phản biện hoặc qualify một claim và gắn với Source.
- **Media:** semantic media object.
- **MediaAsset:** binary hoặc derivative của Media.
- **MediaUsage:** vị trí, endpoint context và vai trò của Media.
- **Video:** canonical external reference gồm platform, external ID, URL và
  metadata được phép.
- **Variant Configuration:** fact mô tả một cấu hình hoặc offering cụ thể ở
  scope Variant; không tự tạo entity, field hoặc quan hệ mới nếu registry/
  contract chưa đăng ký.
- **Specimen Observation:** observation gắn với đúng một Specimen và evidence
  của observation đó; không tự promotion thành fact của Variant hoặc Model.
- **WordPress Post:** nội dung biên tập native, có thể liên hệ semantic object
  nhưng không trở thành nơi chứa duplicate semantic truth.

### 3.4 Visibility, mutation và projection

- **Public Eligibility:** quyết định có cấu trúc về việc entity/read model có
  được xuất hiện ở public hay không; gồm blockers và warnings.
- **Governance:** boundary điều khiển semantic write.
- **Proposal:** command semantic đã bind subject, operation, payload, revision,
  dependency và idempotency.
- **Controlled Apply:** execution sau approval và eligibility, trong transaction,
  có audit và retry semantics.
- **Projection:** read model/presentation; không phải canonical storage.

## 4. Registry và nguyên tắc fail-closed

EntityTypeRegistry, EndpointTypeRegistry, PredicateRegistry và các contract
runtime là executable boundary. Chỉ type, endpoint, predicate, field,
attribute, knowledge profile và operation đã đăng ký mới tồn tại trong V3.

Implementation, fixture, UI, dữ liệu lịch sử hoặc nhu cầu query không tự cấp
quyền tạo khái niệm mới. Không được:

- tạo entity type, endpoint type, predicate, relation type hoặc canonical field
  ngoài registry;
- nhận predicate free-form từ input;
- tạo relation persistence song song với Graph;
- nhét semantic relation vào blob, postmeta hoặc bảng module riêng;
- dùng alias, display name, checksum hoặc URL như canonical identity;
- biến implementation gap thành silent fallback có semantic meaning.

Unknown type, endpoint, predicate, field, operation, target hoặc format phải
fail-closed và trả diagnostic typed. Ambiguity không được giải quyết bằng đoán.

Khi registry chưa đủ để biểu diễn cấu trúc đã được xác nhận, ghi nhận
REGISTRY_GAP; khi code chưa thực hiện luật, ghi CODE_GAP; khi record cũ
chưa phù hợp, ghi DATA_COMPATIBILITY_GAP. Không hạ luật để khớp code cũ.

## 5. Authority và entity architecture

Authority là nơi duy nhất sở hữu identity/lifecycle của canonical semantic
entity. Canonical ID là UUID (UUIDv7 cho record mới; codec phải round-trip legacy
UUID khi contract yêu cầu). Stable Key có scope (entity_type, stable_key) và
bất biến sau khi cấp. Database surrogate ID chỉ là storage optimization.

Mỗi entity phải giữ canonical name, schema version, payload allow-list, state,
revision và identity theo contract. Rename, update, retire và reactivate không
được đổi canonical ID hoặc stable key. Payload unknown fields, malformed UUID,
invalid URL, invalid state và revision không hợp lệ phải bị từ chối hoặc loại bỏ
ở boundary theo contract.

### 5.1 Authority type catalogue

Authority chỉ có chín family hiện được phép dùng:

| Type | Nghĩa canonical |
|---|---|
| brand | Thương hiệu |
| model | Dòng/model thuộc một Brand |
| variant | Biến thể thuộc một Model |
| movement | Bộ máy/tổ hợp kỹ thuật có thể dùng chung |
| music | Tác phẩm/chương trình âm thanh có thể dùng chung |
| component | Linh kiện hoặc technical assembly có identity tái sử dụng |
| classification | Nhóm mô tả/cấu hình tái sử dụng |
| specimen | Một hiện vật vật lý cụ thể |
| product | Một listing/offer thương mại |

Không tạo thêm Authority type chỉ vì V2, UI hoặc một field cũ có tên tương tự.
Knowledge, Source, Evidence, Media và Video có domain identity riêng; chúng
không tự động trở thành Authority type.

## 6. Brand Backbone — luật cấu trúc tối cao

Brand là semantic backbone của product/technical lineage. Backbone bảo toàn
ngữ cảnh Brand, không biến toàn hệ thành một cây cứng và không buộc shared
entity phải có Brand owner.

Canonical storage direction luôn là **child → parent**:

    Model   --model_of-->   Brand
    Variant --variant_of--> Model

Cardinality bắt buộc:

- một Model có **đúng một** active canonical Brand parent;
- một Brand có 0..N Model;
- một Variant có **đúng một** active canonical Model parent;
- một Model có 0..N Variant;
- Brand context của Variant được suy ra qua variant_of rồi model_of;
- tuyệt đối không persist Variant → Brand shortcut;
- reverse Brand→Model và Model→Variant là query traversal, không phải reverse
  edge được lưu thêm.

model_of và variant_of phải là predicates đã đăng ký với source/target và
cardinality tương ứng. Nếu registry chưa có, đó là REGISTRY_GAP; không được
thay bằng about, payload shortcut hoặc relation đặc biệt.

Payload model.brand_uuid và variant.model_uuid, nếu còn tồn tại trong runtime,
chỉ là transitional compatibility evidence cho tới khi Graph-owned structural
truth được cut over qua governed decision. Code coi các field đó là canonical
Graph ownership là CONSTITUTION_CONFLICT.

## 7. Shared entity independence

Movement, Music, Component và Classification là reusable semantic entities.
Chúng không cần Brand parent và có thể dùng xuyên nhiều Brand.

Sự xuất hiện trong Brand page không có nghĩa là ownership bởi Brand. Brand page
chỉ được tổng hợp chúng qua direct/derived relation path hợp lệ. Không persist:

- Movement → Brand chỉ để điều hướng;
- Music → Brand chỉ vì được hiển thị trên Brand page;
- Component → Brand chỉ vì xuất hiện trong một record;
- Classification → Brand chỉ vì dùng làm filter;
- bất kỳ ancestor shortcut nào khác chưa được registry và contract phê duyệt.

Một adjective không tự trở thành Component. Một filter label không tự trở thành
Classification. Một technical object không được hạ thành Classification chỉ vì
UI dễ hơn.

## 8. Graph và relationship law

Semantic Graph là hệ quan hệ duy nhất. Mỗi edge phải có Graph Node source/target
hợp lệ, predicate đã đăng ký, active/retired state, revision và canonical edge
identity. Predicate rule phải nằm trong code registry, không phải mutable JSON
rule trong database.

Graph service phải kiểm tra endpoint normalization/existence, source/target
allow-list, self-relation, cardinality, duplicate, revision, state và audit.

- exact active triple create là idempotent;
- retired edge không tự resurrect; reactivate phải là operation tường minh;
- cardinality conflict không được tự retire edge cũ;
- revision mismatch là typed conflict;
- node còn edge không được hard-delete;
- query mặc định chỉ trả active edge, retired phải được yêu cầu tường minh;
- reverse query dùng cùng Graph, không tạo reverse persistence;
- edge không chứa body, payload domain khác, Media metadata hoặc Evidence blob.

Mọi relation mutation phải qua Governance. Raw Graph operational read có thể
được giới hạn cho Admin; public projection chỉ trả reader-safe object, label và
path cần thiết.

### 8.1 Các predicate kỹ thuật được phê duyệt

Các predicate dưới đây là vocabulary V3 đã được quyết định; predicate chưa có
trong runtime được phân loại ở Appendix B, không được tự invent thêm:

| Predicate | Source → Target | Cardinality | Scope |
|---|---|---|---|
| model_of | Model → Brand | outbound ONE / inbound MANY | Structural parentage |
| variant_of | Variant → Model | outbound ONE / inbound MANY | Structural parentage |
| uses_movement | Variant → Movement | MANY / MANY | Variant documented/configured use |
| supports_music | Movement → Music | MANY / MANY | Movement capability |
| configured_with_music | Variant → Music | MANY / MANY | Variant configuration/offering |
| observed_playing_music | Specimen → Music | MANY / MANY | One physical-object observation |

Evidence phải phù hợp đúng scope. Không suy ra các predicate này từ tên, binary,
rod count, hammer count, case style, visual similarity hoặc Brand.

## 9. Direct, derived và explainability

Direct fact được lưu ở cấp semantic nhỏ nhất mà bằng chứng hỗ trợ. Derived
association chỉ là kết quả traversal theo policy đã phê duyệt.

Ví dụ hợp lệ:

    Variant --variant_of--> Model --model_of--> Brand
    Variant --uses_movement--> Movement --supports_music--> Music

Music hiển thị trên Brand page qua đường thứ hai là DERIVED; Music gắn trực
tiếp với Variant bằng configured_with_music là DIRECT ở Variant scope.

Mỗi derived result phải giải thích được DIRECT hoặc DERIVED và, khi thực tế
cho phép, trả relation path. Presence trên projection không được bị hiểu thành
ownership. Không materialize transitive edge chỉ để frontend dễ query.

### 9.1 Điều hướng quan hệ ngữ nghĩa và phép chiếu nội dung liên quan

Mọi canonical entity và mọi Graph endpoint đã đăng ký là một điểm bắt đầu hợp
lệ của Semantic Graph navigation. Một trang public có thể không có related
 item, nhưng không được mặc định là ngõ cụt và không được tạo quan hệ để làm
 đầy giao diện.

Related candidate chỉ được lấy từ active Graph edge có source/target endpoint,
predicate, direction, identity, provenance và public/readiness policy hợp lệ.
Direct Related là một hop. Derived Related được phép tối đa **2 hop**
(`MAX_HOPS = 2`); không recursive traversal, không graph explosion, không
materialize transitive edge và không persist derived result thành Authority
relation.

Traversal phải dùng đúng source/target allow-list và directionality của
PredicateRegistry cùng traversal policy đã được contract phê duyệt. Query
incoming để đọc child của một child→parent edge không tự tạo inverse predicate;
`A → B` không mặc định có nghĩa `B → A` cho semantic traversal. Predicate
không có traversal/inverse rule rõ ràng là implementation gap và phải fail
closed cho đường đi chưa được phép.

Mỗi result phải giữ được ở application/query boundary: source canonical
identity, target canonical identity, `DIRECT` hoặc `DERIVED`, `hop_count`, best
path, các alternative paths nếu có, predicate của từng hop và provenance khi
contract hỗ trợ. Public serialization chỉ được expose reader-safe title,
label, route và path explanation phù hợp; không leak internal UUID, stable key,
lifecycle hoặc raw Graph storage.

Khi nhiều path tới cùng target, query layer phải deduplicate theo canonical
identity, chọn best path theo ranking contract và giữ alternative paths khi
reader/admin contract cho phép. Direct luôn đứng trước derived tương đương.
Frontend chỉ quyết định section, layout, title, limit, pagination và thứ tự
trình bày; Authority/Graph không nhận UI concern.

Các mode `RELATED`, `FEATURED` và `LATEST` đều lọc candidate theo Graph trước.
`RELATED` ưu tiên direct rồi derived; `FEATURED` chỉ dùng quality/editorial
signal đã có contract; `LATEST` chỉ sort theo thời gian trong tập candidate
semantic đã lọc. Limit, pagination, diversity và cache là projection/query
concerns, không phải Authority fact.

Không dùng WordPress category/tag, post meta, hard-coded ID, display name,
slug, checksum hoặc raw semantic SQL làm semantic fallback. Không có relation
thật thì trả empty thành công; dependency không sẵn sàng, identity mơ hồ,
registry/Graph lỗi hoặc public eligibility thất bại phải trả trạng thái
unavailable/conflict/gap tương ứng, không giả thành empty hoặc related.

Brand, Model, Movement, Variant, Knowledge, WordPress Post/Article workflow,
Media, Video, Specimen, Product và mọi endpoint canonical khác chịu cùng luật
này. Album/Collection chỉ xuất hiện khi một entity/endpoint/predicate contract
được đăng ký riêng; tên section hoặc field `music.album` không tạo identity.

## 10. Fact-scope law

Mọi assertion kỹ thuật phải có scope hẹp nhất mà evidence thực sự hỗ trợ:

- General Entity Fact;
- Brand Fact;
- Model Fact;
- Variant Fact;
- Movement Fact;
- Specimen Observation.

Fact ở scope hẹp không tự động được nâng lên scope rộng. Một Specimen có một
component không chứng minh Variant có component đó; Variant có một cấu hình
không chứng minh toàn Model; Movement support không chứng minh mọi Variant hoặc
Specimen phát cùng Music.

Promotion scope chỉ được thực hiện khi có evidence phù hợp, canonical target,
revision/idempotency và Governance approval. Không có automatic
Specimen observation → Variant → Model promotion.

### 10.1 Music

Music là canonical reusable work/program. Ba fact sau độc lập và không được
promotion ngầm:

1. supports_music: capability ở Movement scope;
2. configured_with_music: documented configuration ở Variant scope;
3. observed_playing_music: observation của một Specimen.

Không infer Music chỉ từ số côn/thanh, số búa, Brand, kiểu thùng, hình ảnh hoặc
visual similarity.

## 11. Specimen và Product

**Specimen** là canonical semantic identity của **một physical object duy
nhất**. Specimen sở hữu physical-object truth, gồm physical identity, serial
hoặc physical identifying evidence, physical provenance, technical
observations, condition observations mô tả chính object đó, identification tới
Brand/Model/Variant trong mức evidence hỗ trợ và object-specific semantic
evidence. Evidence không đủ specificity thì hệ thống không được ép object lên
một type/identity chi tiết hơn.

**Product** là canonical identity của **một commercial listing/offer/commerce
context duy nhất**. Product sở hữu commercial truth, gồm listing identity,
listing title/copy ở ngữ cảnh thương mại, offer state, asking/sale-price
context, availability, inventory/listing state, commercial lifecycle,
listing start/end timestamps và sale/listing presentation context.

Product không trở thành canonical physical identity; Specimen không trở thành
canonical listing/offer identity. Product không được sở hữu hoặc định nghĩa
lại physical serial identity, canonical technical observation, physical
provenance, specimen-level condition identity hoặc Brand/Model/Variant
identification của physical object chỉ vì listing copy nói như vậy. Specimen
không được sở hữu listing price, offer status, listing availability, listing
lifecycle, sales-copy identity hoặc commercial inventory state.

Cardinality cố định:

- một Specimen có **0..N Product theo thời gian**; object có thể chưa từng
  được list, được list một lần, relist hoặc có nhiều Product lịch sử;
- một Product có **0..1 Specimen**; Product không thể đồng thời đại diện cho
  hai Specimen;
- một listing đại diện cho nhiều physical object không phải là quan hệ
  Product–một-Specimen hợp lệ; không invent commerce model đó trong amendment
  này.

Nếu Product tuyên bố đại diện cho một physical object cụ thể, Product phải
resolve tới đúng một Specimen trước khi được coi là semantically complete.
Product generic, catalog-like hoặc pre-specimen có thể tồn tại không có
Specimen nếu Product contract hiện hành cho phép, nhưng không được dùng Product
để thay thế physical identity. Diagnostic reader phải phân biệt ít nhất
`PRODUCT_WITH_SPECIMEN`, `PRODUCT_WITHOUT_SPECIMEN_ALLOWED`,
`PRODUCT_REQUIRES_SPECIMEN` và `PRODUCT_SPECIMEN_CONFLICT` hoặc các reason code
canonical tương đương hiện hành.

Expiring, archiving hoặc deleting Product không được xóa Specimen. Thay đổi
price, availability, listing title hoặc commerce state không tạo Specimen mới.
Tạo Product thứ hai cho cùng Specimen không tạo physical identity thứ hai.
Merge Product không tự merge Specimen; merge/reassignment Specimen là
high-impact identity operation được Governance riêng phê duyệt.

Specimen condition observation mô tả condition vật lý của object. Product
condition copy chỉ là commercial presentation. Product có thể project/reference
condition hiện hành của Specimen, nhưng copy không được silently overwrite
observation canonical. Khi listing text xung đột evidence của Specimen, hệ
thống giữ nguyên Specimen và tạo diagnostic/proposal theo Governance.

Product copy không phải Knowledge Claim. Commercial description không tự động
trở thành Knowledge, Source/Evidence hoặc semantic relation. Claim về Brand,
Model, Variant, Movement, Music, Component, condition, provenance, production
date hoặc technical configuration chỉ được promotion qua evidence → Proposal →
Human Approval → Eligibility → Controlled Apply → canonical semantic state.

Media vẫn thuộc Media/MediaAsset/MediaUsage law hiện hành. Media dùng trên
Specimen page, Product listing hoặc editorial Post không tự chứng minh semantic
depiction hay ownership; listing image chỉ được nói là ảnh của canonical
Specimen khi có relation/evidence hợp lệ. Product–Specimen association cũng chỉ
được persist khi đã có một relation mechanism được đăng ký với semantics,
canonical owner, endpoints, cardinality, provenance và Governance rõ ràng.
Không dùng `specimen_uuid` trong Product payload hoặc broad `about` để âm thầm
định nghĩa quan hệ đó; nếu chưa có mechanism hợp lệ, ghi REGISTRY_GAP/CODE_GAP
và fail closed, không tạo/sửa/sync hai bản truth.

## 12. Knowledge, Source và Evidence

Knowledge lưu atomic claim/fact/research statement, không lưu bài viết dài và
không thay WordPress body. Claim có canonical ID, stable key, claim type,
revision, lifecycle và provenance theo contract.

Source lưu canonical source identity, loại nguồn và locator/metadata được phép.
Evidence là đơn vị cụ thể gắn Claim với Source, có thể supports, contradicts
hoặc qualifies theo contract. Source tồn tại không chứng minh mọi claim của
entity đó. Evidence phải hỗ trợ assertion/relation cụ thể.

Commercial Product copy remains outside Knowledge. A listing statement is not
an atomic canonical fact merely because it names a Brand, Model, Variant,
condition, provenance or technical attribute. Promotion from Product copy into
Knowledge or a semantic relation requires the existing evidence and Governance
chain; a Product update must never silently mutate Specimen, Knowledge, Source,
Evidence or Graph truth.

Public claim/source/evidence phải qua active/public/verification policy và
reader-safe serialization. Raw metadata/provenance internals và lifecycle fields
không tự động trở thành public copy. Source/Evidence không mặc định có standalone
SEO page; chúng xuất hiện như provenance trong projection đã đủ điều kiện.

## 13. Media và Video

### 13.1 Media

Media identity, MediaAsset binary/derivative và MediaUsage placement là ba
boundary khác nhau:

    một Media semantic identity
    → một hoặc nhiều Asset/derivative
    → nhiều Usage/placement

Checksum chỉ tạo duplicate candidate cho review. Nó không chứng minh semantic
identity và không được auto-merge Media. Attachment không tự là Media; Asset
không mặc định là Graph endpoint; MediaAsset không mặc định có standalone
indexable SEO page.

Public binary delivery phải kiểm tra parent Media, active/ready state, visibility,
MIME allow-list, storage containment, size và checksum. Thiếu policy hoặc nguồn
binary thì fail-closed, không sinh broken URL.

Product listing use of Media does not transfer physical Media identity to
Product or prove that the asset depicts a referenced Specimen. Such depiction
or object-specific use remains a MediaUsage/Graph/evidence fact under its
registered contract.

### 13.2 Video

Video là canonical external reference gồm platform, external video ID, canonical
URL và reader-safe metadata. URL được normalize trước khi identity/reference
được chấp nhận. Runtime hiện tại hỗ trợ YouTube contract; reference không hợp lệ,
ID/URL mâu thuẫn hoặc platform chưa hỗ trợ phải fail-closed.

Video không mặc định được tải thành local MP4 hoặc biến thành MediaAsset. Thumbnail
Media nếu có là typed reference riêng. Relation của Video mô tả nội dung mà Video
yêu cầu; derived Brand visibility dùng Graph traversal, không dùng fake ownership.

## 14. WordPress editorial law

WordPress native wp_posts là nguồn sự thật duy nhất cho editorial title, body,
featured/content images, author, date, category, archive, search, RSS, sitemap
và editorial URL. Editorial publication của Post cũng thuộc native WordPress
boundary.

Post có thể liên hệ nhiều semantic entity qua Graph/application service. Post
about một Brand không chứng minh mọi object trong body thuộc Brand. Body không
được copy vào Knowledge để làm semantic truth, không được nhét vào Graph edge,
và không được tạo Article Authority hoặc Article Projection body path.

WordPress editorial create/update/publish hoạt động độc lập với Semantic
Controlled Apply. Governance kiểm soát semantic mutation; nó không biến việc
biên tập và xuất bản một native Post thành semantic apply bắt buộc.

### 14.1 Coordinated Article Ingest Boundary

V3 knowledge Article là một workflow ở cấp operation, không phải một Authority
entity. Không tạo Article Authority, Article body, body projection thứ hai,
Graph `article` endpoint hoặc semantic identity cho bài viết. WordPress native
`wp_posts` vẫn sở hữu title, body, editorial metadata và public URL.

Khi request có intent tạo/cập nhật/xuất bản V3 knowledge Article hoặc Post kèm
semantic claims/relations, completion chỉ hợp lệ sau khi Article Ingest Contract
đã hoàn tất, theo thứ tự: semantic registry resolution và preflight; ghi Post ở
trạng thái draft; semantic Proposal/Governance/Controlled Apply; read-back
verification; rồi mới đủ điều kiện publish WordPress. Generic WordPress
create/update/publish không tự trở thành Article Ingest và không được báo là
workflow V3 knowledge hoàn tất.

Nếu semantic required stage thất bại hoặc unavailable, kết quả phải là explicit
non-success, retryable, unavailable, conflict hoặc outcome tương đương được
định nghĩa bởi Article Ingest Contract đã phê duyệt. Amendment này không tự
đặt thêm enum status/operation.

Một fact canonical chỉ có một owner. Article, FAQ, Search và hub chỉ được reuse
registered Authority, Knowledge, Source/Evidence và Graph data; không tạo FAQ
entity hoặc semantic type mới bằng workflow Article.

Alias/model/component/classification trong bài phải resolve qua registry. Không
dùng prose, title, body, URL, slug, checksum hoặc display name làm semantic
identity. Post endpoint dùng `wp_post` với stable key `<blog_id>:<post_id>`;
không có `article` Graph endpoint.

## 15. Identity, slug, alias và URL

Canonical ID, stable key, display name, public slug, alias và historic slug là
các khái niệm khác nhau. Stable key/UUID không bao giờ xuất hiện trong canonical
public URL.

Public slug phải ổn định sau khi được cấp rõ ràng. Đổi Display Name không được
ngầm thay entity hoặc ngầm đổi canonical URL. Đổi slug là operation explicit,
được Governance/audit và phải lưu historic slug.

Historic URL redirect trực tiếp một hop bằng HTTP 301 tới canonical URL hiện
hành. Không tạo redirect chain. Ambiguous slug, alias collision, parent collision
hoặc native WordPress route collision phải fail-closed; không redirect bằng guess.

Nếu runtime chỉ derive slug từ Display Name mà chưa có persisted public identity
và history, trạng thái đó là PUBLIC_IDENTITY_STORAGE_GAP. Không được mô tả
render-time derivation như durable public identity.

## 16. Public eligibility và transitional compatibility

Có một underlying Public Eligibility Policy dùng cho homepage, hub, detail,
search, REST, card, sitemap, canonical metadata và preview. Policy trả:

    eligible: bool
    blockers: list<reason-code>
    warnings: list<reason-code>

Empty dataset chỉ là empty state sau query thành công với zero eligible result.
Malformed row có thể bị omit theo bounded row-data contract. Storage/runtime/
programming/hydration failure không được biến thành empty collection.

Model và Variant trong transition có thể public khi có đúng một payload parent
rõ ràng, hợp lệ, unique, active và giải được tới ancestor cần thiết, dù canonical
Graph edge chưa có. Khi đó thêm warning DATA_COMPATIBILITY_GAP; payload này
không trở thành Graph truth.

Parent missing, malformed, inactive hoặc unresolved là blocker
STRUCTURAL_PARENT_MISSING. Conflicting hoặc multiple candidate là blocker
STRUCTURAL_PARENT_AMBIGUOUS. Khi Graph-backed structural cutover được governed
phê duyệt, active model_of/variant_of là nguồn canonical cho structural reads;
cutover không xảy ra như side effect của route/query.

Movement, Music, Component và Classification không cần Brand để eligible. Media,
Video, Knowledge, Source và Evidence dùng active/readiness/public contracts riêng
của chúng nhưng phải được nối vào cùng nguyên tắc public failure/diagnostic.

## 17. Public routes, hubs và menu

### 17.1 Canonical hubs

Các hub public canonical là:

    /thuong-hieu/   /mau/         /bo-may/       /ban-nhac/
    /linh-kien/     /phan-loai/   /tri-thuc/     /hien-vat/
    /san-pham/      /video/       /so-sanh/

Detail routes:

    Brand:         /{brand-slug}/
    Model:         /{brand-slug}/{model-slug}/
    Variant:       /{brand-slug}/{model-slug}/{variant-slug}/
    Movement:      /bo-may/{slug}/
    Music:         /ban-nhac/{slug}/
    Component:     /linh-kien/{slug}/
    Classification: /phan-loai/{slug}/
    Specimen:      /hien-vat/{slug}/
    Product:       /san-pham/{slug}/
    Video:         /video/{title-slug}-{external-id}/

Mẫu hub là discovery only; không tạo Model canonical detail thứ hai. Variant
không cần global enumeration hub mặc định; được discover qua Model/Brand context.
Comparison là query-driven surface giữa hai public Authority references, không
có persisted comparison identity.

Knowledge Claim, Source, Evidence và Media không tự có standalone indexable SEO
page. MediaAsset chỉ có delivery URL khi policy cho phép. WordPress Post dùng
native editorial permalink.

### 17.2 Legacy route và menu

Technical roots /brand/, /model/, /variant/, /movement/, /music/, /component/,
/classification/, /specimen/, /product/ và /comparison/ không phải canonical.
Nếu legacy route còn cần tương thích, nó chỉ được redirect một hop tới final
Vietnamese destination hoặc trả honest 404 theo route ledger. Không đưa technical
route vào menu, canonical tag, sitemap, breadcrumb, card hay search result.

Menu dùng ngôn ngữ người đọc, không dùng registry. Primary menu hiện định hướng:
Tri thức, Thương hiệu, Mẫu, Bộ máy, Bản nhạc, So sánh, Linh kiện, Hiện vật,
Video. Classification và Product không tự động vào primary menu chỉ vì type đã
đăng ký; Góc chia sẻ chỉ hiện khi có editorial section thật.

## 18. Public collection, projection và SEO

Public flow canonical là:

    Authority/domain read
    → Public Identity
    → Public Eligibility
    → Public Route
    → Collection/Detail Read Model
    → Presentation

Homepage, semantic hubs, entity pages, search, REST lists/details, cards,
breadcrumb, canonical tag, OpenGraph URL và sitemap phải dùng cùng identity,
eligibility và route result. Template không tự tạo slug, parent, state, routeability,
visibility hoặc metric.

Public projection phải:

- dùng real query/application services, không raw DB từ template;
- hide module khi dependency không available;
- hiển thị empty state chỉ cho successful zero-result;
- ghi rõ DIRECT/DERIVED và path khi một association được tổng hợp;
- không expose internal Authority, Proposal, Knowledge Claim hoặc storage terms
  trong visitor-facing copy;
- canonicalize one URL per public entity và loại UUID/stable key khỏi indexable URL.
- public brand copy phải dùng đúng display spelling đã được Constitution/contract
  phê duyệt; alias né tránh hoặc sai chính tả không được phát ra ở visible text,
  public metadata hoặc JSON-LD.

Canonical route, canonical tag, OpenGraph URL, breadcrumb, sitemap, search và
internal link phải nhất quán. Search result và archive page không được tạo
canonical duplicate page; page 2+ có thể noindex,follow theo SEO policy nhưng
vẫn link được tới canonical content.

## 19. Governance và Controlled Apply

Mọi semantic durable mutation đi theo:

    Proposal
    → Human Approval
    → Eligibility
    → Controlled Apply
    → Authority/Graph/domain repository
    → durable audit

Proposal phải bind subject, operation, canonical payload/content fingerprint,
expected revision, dependency-closure fingerprint và idempotency key. Approval
chỉ có giá trị khi binding còn khớp. Apply phải kiểm tra target existence, revision,
dependencies, capability, endpoint/predicate/field contract và transaction.

Apply thành công là một atomic semantic transaction cùng attempt/success audit.
Failure rollback semantic mutation và proposal transition; FAILED attempt được
ghi bounded trong transaction riêng. Retry re-evaluates eligibility. Apply lại
proposal APPLIED phải idempotent và không mutate lần hai.

Không có admin shortcut, direct SQL, MCP shortcut hoặc compatibility adapter nào
được bypass registry, revision, provenance, idempotency, permission, audit hay
Graph boundary. Semantic merge, reassignment, retirement và structural parent
change là high-impact mutation; phải đánh giá identity, alias, Graph, Knowledge,
Media, Source/Evidence, Specimen/Product, slug, redirect, SEO và MCP trước khi
apply. Canonical entity không bị hard-delete tùy tiện.

Native WordPress editorial publish là ngoại lệ có chủ đích: vẫn thuộc Post
boundary và không cần semantic Governance apply.

Product price, availability, listing copy and ordinary offer-state edits are
commerce-only when they do not change semantic truth. Any Product–Specimen
linkage, physical identification, Specimen observation, provenance or other
canonical semantic mutation must use the full Governance chain. A commercial
edit that conflicts with physical evidence produces a diagnostic/proposal; it
does not directly update Authority, Knowledge, Source/Evidence or Graph.

## 20. MCP và Admin

MCP/Admin là orchestration/diagnostic surface, không phải canonical owner. MCP
chỉ expose operation đã có contract và Governance; không tạo type/predicate/field
mới, không tạo persistence path thứ hai và không bypass capability.

Read surface phải dùng reader-safe serializers, canonical UUID validation, active/
public/readiness policy và ambiguity diagnostics. Mutation tools phải capability-
gated và dùng đúng Proposal → Approval → Eligibility → Controlled Apply.

Admin phải hiển thị, khi contract có, identity, state, revision, blockers,
warnings, relation path, compatibility gaps, proposal binding, apply status và
reason codes. Diagnostic visibility khác public visibility: Admin không được che
record invalid/ineligible bằng cách biến chúng thành “không tồn tại”.

Raw Graph REST có thể là administrator-only operational read; public API không
được leak endpoint keys, edge state/revisions hoặc storage identifiers nếu không
được contract cho phép. WordPress Abilities chỉ là discoverability bridge của
existing read contracts, không phải persistence hoặc write bypass.

## 21. Health, hydration và deployment

Một database hợp lệ không được xuất hiện như empty chỉ vì runtime dependency,
autoload, bootstrap hoặc query infrastructure lỗi.

Health phải phân biệt ít nhất:

1. STORAGE — database và migration readiness;
2. RUNTIME — Composer/autoload và mandatory runtime classes;
3. HYDRATION — row có thể thành domain object không mất dữ liệu;
4. APPLICATION — service/query composition;
5. REST/PUBLIC PROJECTION — endpoint và read model.

Row-data malformed có thể fail-closed với bounded reason và omit row. Error,
TypeError, missing class, autoload failure, database/infrastructure failure và
unexpected programming exception phải surface loudly; tuyệt đối không catch
rộng để báo empty success.

Release là một gói coherent gồm source code, composer.lock, installed runtime,
configuration contract, schema compatibility và bootstrap verification. Composer
dependency completeness là deployment invariant. Read-only preflight phải fail
trước traffic nếu dependency, bootstrap, migration state, Authority hydration hoặc
REST initialization không đạt. nhk_v3 chỉ được health/smoke/schema addition/
UP migration; destructive integration chỉ được trên exact nhk_v3_test qua guard.

## 22. Frontend UX law

Public experience là Vietnamese-first, editorial, dễ đọc và discovery-oriented.
Internal class name, registry key, UUID, stable key, Proposal, Authority và
Knowledge Claim không bắt buộc xuất hiện trong visitor copy.

- Normal H1: khoảng 30–36px mobile và 40–48px desktop; H2 24–32px.
- Body: 16–18px, line-height khoảng 1.6–1.75.
- Không dùng display heading quá lớn kiểu SaaS hoặc blanket weight 800/900.
- Typography, spacing, container, grid, image ratio, radius, color và shadow
  dùng token tập trung; template không tạo override ad hoc.
- Reading width khoảng 720–800px; discovery wide container có tối đa hai cột
  desktop và một cột màn hình nhỏ.
- Card có gap, aspect ratio, bounded text và một action rõ ràng.
- Semantic HTML, heading order, keyboard focus, touch-sized control, contrast,
  alt text có nghĩa, lazy loading below-fold và không overflow ngang là bắt buộc.
- Empty module bị ẩn; page zero-result dùng một empty state trung thực.
- Public label phải là tiếng Việt tự nhiên; code/database name giữ canonical
  technical name khi cần chính xác.

## 23. Empty, error và ambiguity law

Các trạng thái sau khác nhau và phải được biểu diễn khác nhau:

- empty dataset: query thành công, zero eligible result;
- unavailable: dependency/storage/module chưa sẵn sàng;
- invalid row: row bị bounded hydrator loại bỏ;
- identity conflict: nhiều identity/candidate không thể chọn duy nhất;
- relationship conflict: target, direction, cardinality hoặc revision conflict;
- infrastructure/programming failure: lỗi phải surface, không thành empty.

Ambiguous identity, alias, slug, parent, relation target hoặc evidence path luôn
fail-closed. Hệ thống trả candidate/diagnostic/proposal theo contract, không guess.

## 24. Conflict và gap taxonomy

Dùng reason code chính xác, không gom thành error chung:

- CONSTITUTION_CONFLICT: code, tài liệu, migration hoặc contract khác luật này;
- REGISTRY_GAP: luật cần type/endpoint/predicate/field/operation chưa đăng ký;
- CODE_GAP: registry có hoặc luật rõ nhưng code chưa thực hiện;
- DATA_COMPATIBILITY_GAP: data hợp lệ theo lịch sử nhưng chưa đủ contract mới;
- IDENTITY_CONFLICT: canonical identity, alias, slug hoặc stable key collision;
- RELATIONSHIP_CONFLICT: direction, endpoint, cardinality, revision hoặc target
  conflict;
- PUBLIC_IDENTITY_STORAGE_GAP: public slug/history chưa có durable contract;
- PUBLIC_ELIGIBILITY_FAILURE: public surfaces dùng policy khác nhau;
- SEMANTIC_GAP: domain concept chưa có owner/identity/contract hợp lệ.

Khi ghi CONSTITUTION_CONFLICT, phải nêu file/module/operation, điều khoản bị
vi phạm, registry/contract hiện hành, hành vi thực tế, rủi ro và architectural
decision cần có. Không tự sửa Hiến pháp để hợp thức hóa implementation.

### 24.1 Deterministic adversarial outcomes

Các trường hợp biên sau đây có kết quả kiến trúc cố định:

| Tình huống | Kết quả bắt buộc |
|---|---|
| Một Brand có nhiều Model; một Model có nhiều Variant | Hợp lệ nếu mỗi Model chỉ có một Brand parent và mỗi Variant chỉ có một Model parent; reverse collections là traversal |
| Một Movement được nhiều Brand dùng; một Music được nhiều Movement support | Hợp lệ; shared entity không có Brand owner ngầm và quan hệ MANY/MANY giữ nguyên scope |
| Variant cấu hình Music nhưng Specimen quan sát khác Music | Cả hai fact cùng tồn tại ở scope riêng; không promotion, overwrite hoặc suy ngược |
| Specimen có observation riêng | Giữ ở Specimen Observation với evidence; không biến thành Variant/Model/Brand fact |
| Knowledge Claim có Source nhưng Evidence không đủ | Không đủ điều kiện public/verified hoặc apply theo contract; giữ diagnostic/provenance gap, không nâng claim thành sự thật |
| Model thiếu, mơ hồ hoặc conflicting parent | Fail-closed với STRUCTURAL_PARENT_MISSING hoặc STRUCTURAL_PARENT_AMBIGUOUS; không đoán, không dùng payload shortcut làm Graph truth |
| Brand đổi tên hoặc public slug đổi | Giữ Canonical ID và Stable Key; rename không tự đổi slug; slug change là operation explicit, giữ Historic Slug và redirect một hop |
| Alias collision hoặc Historic Slug collision | Fail-closed với IDENTITY_CONFLICT; alias không được dùng như historic redirect |
| Merge/reassignment entity | High-impact semantic mutation; cần Governance, identity/Graph/provenance/redirect review và durable audit, không hard-delete hoặc tự merge |
| Một Specimen xuất hiện trong nhiều Product; Product biến mất | Hợp lệ theo thời gian; Product là listing/offer, Specimen vẫn giữ physical identity |
| Specific-object Product không có Specimen | Semantically incomplete/blocked; không tự tạo Specimen hoặc suy ra physical identity |
| Generic/pre-specimen Product không có Specimen | Chỉ hợp lệ khi Product contract hiện hành cho phép; Product vẫn không sở hữu physical identity |
| Product price/availability/title thay đổi | Commerce-only nếu không đổi semantic truth; không tạo, đổi hoặc xóa Specimen |
| Product copy mâu thuẫn Specimen evidence | Giữ nguyên Specimen; tạo diagnostic/proposal theo Governance, không silently overwrite |
| Product–Specimen link dùng field hoặc broad `about` chưa được chọn owner | REGISTRY_GAP/CODE_GAP; fail closed, không persist hoặc đồng bộ hai bản truth |
| Database hợp lệ nhưng runtime dependency thiếu | Health là RUNTIME/BOOTSTRAP failure; surface failure, không trả empty semantic data |
| Homepage và hub cho membership khác nhau | PUBLIC_ELIGIBILITY_FAILURE; dùng cùng policy, identity, route và blocker/warning, không sửa bằng template |
| Derived Music xuất hiện trên Brand page | Chỉ được hiển thị như DERIVED với relation path; không tạo Brand→Music shortcut |
| Shortcut trùng với derived path | Không persist shortcut; giữ một direct path và giải thích derived traversal |
| Legacy V2 field không có V3 contract | REGISTRY_GAP hoặc DATA_COMPATIBILITY_GAP; không phát minh type/field/relation và không migrate tự động |
| Generic WordPress Post publish | Hợp lệ độc lập ở Post boundary; không được báo là V3 knowledge Article hoàn tất nếu thiếu Article Ingest contract |
| Semantic MCP/Admin mutation | Chỉ Proposal → Human Approval → Eligibility → Controlled Apply → repository → audit; bypass là CONSTITUTION_CONFLICT |

## 25. Change control

Hiến pháp chỉ được thay đổi bằng explicit architectural review. Mỗi amendment
phải ghi:

- WHY — lý do và evidence;
- WHAT LAW CHANGES — điều khoản cũ/mới;
- subsystem bị ảnh hưởng;
- compatibility và public projection impact;
- data/migration impact;
- Governance, test và deployment implications;
- decision owner và ngày hiệu lực.

Spec, plan, audit và implementation checkpoint không được tự trở thành
constitutional amendment. Không một data fixture, V2 behavior, UI request hoặc
runtime convenience nào tự sửa luật.

## 26. Acceptance invariants bắt buộc

Normative body của Hiến pháp kết thúc bằng các invariant có thể chuyển thành
machine test:

1. Có đúng một canonical Brand parent active cho mỗi Model.
2. Có đúng một canonical Model parent active cho mỗi Variant.
3. Không có Variant → Brand structural shortcut được persist.
4. Movement, Music, Component và Classification không cần Brand parent.
5. Không automatic promotion từ Specimen observation lên Variant/Model.
6. Không infer Music từ rod/hammer count, Brand, case style hoặc visual similarity.
7. Direct và Derived luôn phân biệt được; Derived có path giải thích được.
8. Unknown predicate/type/endpoint/field/operation fail-closed.
9. Ambiguous identity/slug/alias/parent/target fail-closed.
10. Stable key và UUID không xuất hiện trong canonical public URL.
11. Một Public Eligibility Policy underlying dùng cho hub/detail/search/REST/card/
    SEO/sitemap/preview.
12. Public eligible entity có membership, URL, detail và REST/search nhất quán.
13. Infrastructure/programming failure không bị đổi thành empty success.
14. Semantic writes luôn qua Governance và Controlled Apply.
15. Generic WordPress editorial publication remains independent from semantic
apply. A V3 knowledge Article workflow may report completion only after the
approved coordinated Article Ingest Contract has satisfied all required
editorial, semantic and verification stages.
16. Technical legacy routes không phải canonical và redirect tối đa một hop.
17. Media, MediaAsset và MediaUsage không bị gộp identity/persistence.
18. Product không trở thành Specimen identity.
19. Specimen không trở thành Product listing identity.
20. Một Specimen có thể có 0..N Product theo thời gian; một Product có tối đa
    một Specimen.
21. Specific-object Product không có đúng một Specimen là semantically
    incomplete/blocked; Product generic/pre-specimen chỉ được phép nếu contract
    hiện hành cho phép.
22. Product commerce update không tạo, xóa hoặc thay thế Specimen identity.
23. Product copy không tự promote thành Knowledge, Source/Evidence hoặc
    semantic relation.
24. Specimen condition/technical/provenance truth không bị Product copy
    silently overwrite.
25. Product–Specimen relationship persistence không được implicit; thiếu
    relation contract phải fail closed với gap/diagnostic.
26. Mọi canonical entity/Graph endpoint đã đăng ký có thể là điểm bắt đầu của
    Semantic Graph navigation.
27. Related content phải bắt nguồn từ governed Graph relationship; taxonomy,
    post meta, hard-coded ID và raw semantic SQL không thay thế Graph.
28. Derived related content chỉ được traverse tối đa hai governed hops.
29. Derived result phải giữ trạng thái derived và không được persist thành
    fake direct Authority edge chỉ vì presentation.
30. Direct relation luôn thắng derived relation tương đương sau deduplication.
31. Frontend projection không được mutate semantic truth hoặc ghi UI concern
    vào Authority/Graph.
32. Mọi derived result phải explainable bằng traversal path và predicate của
    từng hop.
33. Traversal phải tôn trọng directionality, inverse/traversal policy và
    allow-list của registry; chiều ngược không được suy đoán.
34. Relation filtering phải hoàn tất trước latest/featured/editorial ranking
    và trước limit/pagination của projection.
35. Related query phải bounded, chống cycle/graph explosion, deduplicate theo
    canonical identity và phân biệt honest empty với unavailable/error.
36. Public projection phải chuẩn hóa các brand alias đã được xác nhận về đúng
    display spelling duy nhất; policy này chỉ áp dụng ở presentation boundary,
    không sửa `wp_posts`, semantic record, alias, source/evidence hay legacy body.
37. Constitution này là nguồn normative duy nhất.

---

# Appendix A — CONSTITUTIONAL DECISION REGISTER

Appendix này là một phần của cùng file và ghi quyết định cuối cùng; các file
khác không được dùng như decision authority song song.

| Decision | Rationale | Consequence |
|---|---|---|
| Structure first / relationships first / data later | Sai cấu trúc và quan hệ gây duplicate identity, không thể sửa an toàn bằng population | Chốt registry, cardinality và scope trước mọi data operation |
| Authority owns canonical entities | Cần một owner duy nhất cho identity/lifecycle | UI, Post, Graph và MCP không được làm entity owner thứ hai |
| Brand backbone | Model/Variant cần lineage rõ nhưng shared domains không phải Brand asset | Model→Brand và Variant→Model là structural direct edges; Variant→Brand derived |
| Child→parent storage | Một hướng canonical tránh reverse duplication | Query incoming để điều hướng; không lưu reverse edges |
| Direct vs Derived | Presence trong projection không đồng nghĩa ownership | Read models trả origin/path; không materialize shortcut |
| Semantic relationship navigation | Mọi canonical endpoint là điểm vào của mạng tri thức, nhưng Graph truth và presentation có boundary khác nhau | Related query dùng Graph governed, tối đa 2 hop, direct thắng derived, path phải giải thích được và không tạo fake edge |
| Music has three scopes | Capability, configuration và physical observation là ba sự thật khác nhau | Không promotion hoặc infer từ count/visual/Brand |
| Specimen is physical identity | Một object có nhiều observation/listing theo thời gian | Product không thay thế Specimen |
| Product is offer identity | Commerce context thay đổi và relist được | Product–Specimen chỉ dùng khi contract chọn owner/semantics rõ |
| Product/Specimen cardinality | Tách durable physical identity khỏi commerce lifecycle mutable | Specimen 1 → 0..N Product theo thời gian; Product → 0..1 Specimen; relist không duplicate Specimen |
| Product semantic completeness | Specific-object listing cần một physical subject xác định; generic/pre-specimen listing không được giả physical identity | Product cụ thể không có đúng một Specimen bị block/incomplete; Product generic chỉ được phép khi contract hiện hành cho phép |
| Product commercial claims | Listing copy không phải canonical fact | Không tự promote Product copy thành Knowledge, Source/Evidence hoặc Graph relation; promotion qua evidence và Governance |
| Media distinctions | Semantic meaning, binary và placement có lifecycle khác nhau | Media/Asset/Usage tách persistence; checksum không merge |
| Knowledge vs Post | Claim atomic khác narrative editorial body | Post giữ body/URL; Knowledge giữ claim; Graph chỉ liên hệ |
| Coordinated Article Ingest | V3 knowledge Article completion crosses editorial and semantic boundaries | Approved operation-level contract: semantic preflight → WordPress draft → governed semantic apply → read-back → WordPress publish; no Article entity/body/endpoint |
| No Article semantic entity | Article is an editorial workflow, not a canonical semantic owner | Reuse registered Authority, Knowledge, Source/Evidence and Graph records; do not invent Article/FAQ types |
| Public identity distinctions | Rename không được thay semantic identity hoặc URL ngoài ý muốn | Slug durable, history và redirect là governed contract |
| Vietnamese hubs | Public IA dành cho người đọc, không leak registry | Technical roots chỉ là compatibility inputs |
| Eligibility parity | Một entity không được có membership khác nhau giữa surface | Một underlying policy và blocker/warning rõ ràng |
| Governance | Semantic mutation cần approval, revision, idempotency và audit | Controlled Apply là write boundary; Post publish vẫn độc lập |
| Product/Specimen boundary | Physical object identity và commercial offer identity có lifecycle/cardinality khác nhau | Specimen 1 → 0..N Product; Product → 0..1 Specimen; no implicit physical identity, claim promotion or repair |
| Deployment health | Runtime failure không được bị che thành empty data | Preflight, layered health và dependency completeness là release gate |

# Appendix B — CURRENT IMPLEMENTATION STATUS (NON-NORMATIVE STATUS SNAPSHOT)

Snapshot này là evidence tại 2026-09-02. Nó không sửa hoặc hạ bất kỳ luật nào
ở trên. Khi runtime thay đổi, cập nhật snapshot và evidence; nếu luật thay đổi,
phải dùng Change Control.

| Law / concern | Current status | Classification | Evidence |
|---|---|---|---|
| Authority type registry | Chín type brand, model, variant, movement, music, component, classification, specimen, product đã load qua CanonicalEntityTypeCatalog | COMPLIANT | public/wp-content/plugins/nhk-core/src/Domain/Authority/CanonicalEntityTypeCatalog.php |
| Graph endpoint registry | Full boot đăng ký wp_post, chín Authority type, media, video, knowledge, source, evidence — 15 endpoint types | COMPLIANT | CoreEndpointResolverRegistrar.php; MCP_V3_CONTENT_OPERATIONS.md |
| Graph predicates | Runtime hiện có about, depicts và sáu predicate kỹ thuật: model_of, variant_of, uses_movement, supports_music, configured_with_music, observed_playing_music | COMPLIANT vocabulary; DATA GAP physical rows | PredicateRegistry.php; Brand relationship evidence; không có physical backfill trong checkpoint này |
| Semantic relationship navigation | Canonical endpoints may be navigation entry points; direct/derived, path, ranking, projection và bounded traversal law đã được phê duyệt | PARTIAL / CODE_GAP; directionality and traversal policy gaps remain | RELATED_SEMANTIC_PROJECTION_CONTRACT.md; RelatedContentQuery.php; BrandAggregationQuery.php |
| Brand structural storage | Registry/cardinality đã có; payload brand_uuid/model_uuid vẫn được PublicRouteResolver dùng; physical structural rows chưa được backfill | CODE_GAP, CONSTITUTION_CONFLICT nếu payload bị coi là canonical, DATA_COMPATIBILITY_GAP cho rows | PublicRouteResolver.php, StructuralContextQuery.php, BRAND_BACKBONE_STRUCTURAL_CONTRACT_EVIDENCE_2026-09-02.md |
| Direct/derived Brand aggregation | Read-only Brand aggregation trả DIRECT/DERIVED và path; không tạo shortcut edge | IMPLEMENTED | BrandAggregationQuery.php; current execution state |
| Public identity | Slug vẫn derive từ canonical_name lúc đọc; chưa có persisted public-slug/history contract | PUBLIC_IDENTITY_STORAGE_GAP, CODE_GAP | PublicIdentityContract.php, PublicRouteResolver.php, V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md |
| Public eligibility | Production composition wires PublicEntityCollectionQuery into home, search, entity routes và REST; legacy/fallback query branches vẫn tồn tại và cần convergence proof | CODE_GAP / PUBLIC_ELIGIBILITY_FAILURE candidate | Plugin.php, PublicEntityCollectionQuery.php, EntityPageQuery.php, EntityApi.php |
| Transitional parent handling | Clear active payload parent can remain eligible with DATA_COMPATIBILITY_GAP; missing/conflicting parent blocks; no edge mutation | PARTIAL but contract-visible | PublicEntityEligibilityPolicy.php, StructuralContextQuery.php |
| Technical public routes | Vietnamese hubs/detail routes and one-hop archive redirects are implemented in code; live stored-menu and some runtime evidence remain gated | PARTIAL runtime evidence | PublicEntityRoutes.php, V3_PUBLIC_HUB_MATRIX.md, V3_MENU_ROUTE_AUDIT_2026-09-02.md |
| Knowledge/Source/Evidence | Separate domain records, active/public reader-safe gates, governed ingest and evidence chain exist; final public provenance policy remains open | IMPLEMENTED with publication gate | KnowledgeClaim.php, Source.php, Evidence.php, MCP_V3_CONTENT_OPERATIONS.md |
| Media/Asset/Usage | Separate domain/persistence objects, readiness/visibility and guarded delivery exist; byte upload and final publication policy remain limited/open | IMPLEMENTED with policy gap | Media.php, MediaAsset.php, MediaUsage.php, PublicMediaAssetDelivery.php |
| Video | Validated YouTube external-reference identity, canonical watch URL and optional thumbnail reference; no local MP4 behavior | IMPLEMENTED for current contract | Video.php, VideoService.php, MCP_V3_CONTENT_OPERATIONS.md |
| Product/Specimen ownership | Human-approved law separates physical identity and commerce identity; lifecycle, cardinality, completeness, condition and claim boundaries are explicit | PARTIAL / REGISTRY_GAP | This amendment; Product/Specimen tests; no dedicated approved Product–Specimen relation mechanism yet; existing `specimen_uuid`/broad `about` path is not canonical |
| Album | No Authority type, endpoint, predicate, repository, service or public contract | SEMANTIC_GAP | MCP content-operations audit |
| WordPress Post boundary | Native Post remains editorial title/body/author/date/category/URL truth; no Article Authority body path is approved | COMPLIANT | 01_EDITORIAL_CONTENT_BOUNDARY.md historical evidence; Plugin.php and public route contracts |
| Article Ingest boundary | Constitutionally approved operation-level workflow; no runtime coordinator, cross-boundary idempotency, WordPress revision binding or final outcome contract exists yet | CODE_GAP / SEMANTIC_GAP | Article Ingest amendment; MCP_V3_CONTENT_OPERATIONS.md; current runtime catalog |
| Governance | Proposal binding, approval, eligibility, Controlled Apply, capability checks, revision, idempotency and durable audit are implemented for current operations | COMPLIANT for registered operations | ControlledApplyService.php, ProposalEligibilityService.php, MCP catalog |
| MCP catalog | Exactly 19 tools; governed writes remain capability-gated; eight existing read abilities are exposed on supported WordPress versions | IMPLEMENTED for current catalog | McpToolCatalog.php, McpAbilityRegistration.php, MCP_V3_CONTENT_OPERATIONS.md |
| Hydration/health | Bounded malformed-row omission and layered health/preflight exist; runtime/DB evidence varies by environment | IMPLEMENTED with environment gates | AuthorityRowHydrator.php, HealthCheck.php, tools/deployment-preflight.php |
| Deployment | Root Composer lock/autoload and read-only preflight are release requirements; staging/server verification remains externally gated | PARTIAL evidence | P0_DEPLOYMENT_PREFLIGHT.md, V3_EXECUTION_STATE.md |
| Frontend law | Vietnamese-first theme tokens, responsive/accessibility/SEO constraints and route/read-model boundaries are implemented or contract-tested; visual/runtime gates remain recorded | IMPLEMENTED with open QA gates | V3_FRONTEND_DESIGN_CONTRACT.md, frontend route evidence |

Known current counts and environment results are maintained in
docs/architecture/V3_EXECUTION_STATE.md; that file is non-normative evidence
and must not redefine this snapshot's law.

# Appendix C — V2 RETIREMENT NOTES (NON-NORMATIVE)

V2 material was inspected read-only as historical/domain evidence. It has no
normative authority and is not a runtime dependency of this Constitution.

| Concept found in historical material | V3 disposition | Reason |
|---|---|---|
| Stable/canonical identity separation, revision, expected revision, idempotency, approval binding, audit actor, source provenance, typed relation validation, reverse query, pagination | RETAIN as V3 law | These are expressed here using V3 owners and registries |
| Media semantic identity separate from attachment/binary/usage | RETAIN as V3 law | Prevents binary dedupe from becoming semantic merge |
| Video external reference without implicit local MP4 | RETAIN as V3 law | Matches current external-reference contract |
| Article Authority body and Article→Projection→Post pipeline | RETIRE / SUPERSEDE | WordPress native Post owns editorial body and URL |
| Fragmented relation stores, module-specific relation registries and reverse-edge duplication | RETIRE / SUPERSEDE | Graph is the single relation system |
| Closed enum used as type authority, legacy God service and compatibility write path | RETIRE / REWRITE | Runtime registry, bounded services and Governance are authoritative |
| Legacy merge/cleanup/purge and automatic semantic backfill | RETIRE | Identity and relationship changes require explicit governed decisions |
| V2 route/branding/markup as implementation authority | RETIRE | Historical behavior may inform evidence; it cannot define V3 law |
| Legacy article-body import/parse/population | OUT OF SCOPE | Constitution forbids it in the current scope |

The active repository may retain clearly labeled V2 read-only inventories,
parity matrices, migration ledgers and audit evidence where they are required to
explain current status. They are subordinate evidence only. No engineer may
interpret their historical requirement language as permission to override this
Constitution.
