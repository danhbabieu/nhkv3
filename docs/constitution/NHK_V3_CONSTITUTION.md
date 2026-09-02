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

## 2. Ranh giới trách nhiệm tối cao

Mỗi subsystem chỉ sở hữu trách nhiệm được nêu dưới đây:

| Subsystem | Trách nhiệm canonical |
|---|---|
| WordPress native Post | Nội dung biên tập, tiêu đề, body, tác giả, ngày, category, archive, search, RSS, sitemap và permalink editorial |
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

Specimen là một physical object. Nó có thể có observation, provenance, media,
condition và technical identification. Evidence có thể chỉ đủ để nhận diện tới
Brand, Model hoặc Variant; hệ thống không được ép specificity cao hơn evidence.

Product là listing/offer/commercial context. Một Specimen có thể xuất hiện
trong nhiều Product theo thời gian. Product không thay thế, đổi tên hoặc trở
thành identity của Specimen.

Product–Specimen association chỉ được dùng khi semantics, canonical owner,
cardinality, provenance và mutation contract đã rõ ràng. Nếu một payload field
và một Graph predicate cùng biểu diễn một fact mà contract chưa chọn owner,
đó là CONSTITUTION_CONFLICT; không tự tạo, sửa hoặc đồng bộ hai bản.

## 12. Knowledge, Source và Evidence

Knowledge lưu atomic claim/fact/research statement, không lưu bài viết dài và
không thay WordPress body. Claim có canonical ID, stable key, claim type,
revision, lifecycle và provenance theo contract.

Source lưu canonical source identity, loại nguồn và locator/metadata được phép.
Evidence là đơn vị cụ thể gắn Claim với Source, có thể supports, contradicts
hoặc qualifies theo contract. Source tồn tại không chứng minh mọi claim của
entity đó. Evidence phải hỗ trợ assertion/relation cụ thể.

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
author, date, category, archive, search, RSS, sitemap và editorial URL.

Post có thể liên hệ nhiều semantic entity qua Graph/application service. Post
about một Brand không chứng minh mọi object trong body thuộc Brand. Body không
được copy vào Knowledge để làm semantic truth, không được nhét vào Graph edge,
và không được tạo Article Authority hoặc Article Projection body path.

WordPress editorial create/update/publish hoạt động độc lập với Semantic
Controlled Apply. Governance kiểm soát semantic mutation; nó không biến việc
biên tập và xuất bản một native Post thành semantic apply bắt buộc.

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
15. WordPress editorial publication vẫn độc lập với semantic apply.
16. Technical legacy routes không phải canonical và redirect tối đa một hop.
17. Media, MediaAsset và MediaUsage không bị gộp identity/persistence.
18. Product không trở thành Specimen identity.
19. Constitution này là nguồn normative duy nhất.

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
| Music has three scopes | Capability, configuration và physical observation là ba sự thật khác nhau | Không promotion hoặc infer từ count/visual/Brand |
| Specimen is physical identity | Một object có nhiều observation/listing theo thời gian | Product không thay thế Specimen |
| Product is offer identity | Commerce context thay đổi và relist được | Product–Specimen chỉ dùng khi contract chọn owner/semantics rõ |
| Media distinctions | Semantic meaning, binary và placement có lifecycle khác nhau | Media/Asset/Usage tách persistence; checksum không merge |
| Knowledge vs Post | Claim atomic khác narrative editorial body | Post giữ body/URL; Knowledge giữ claim; Graph chỉ liên hệ |
| Public identity distinctions | Rename không được thay semantic identity hoặc URL ngoài ý muốn | Slug durable, history và redirect là governed contract |
| Vietnamese hubs | Public IA dành cho người đọc, không leak registry | Technical roots chỉ là compatibility inputs |
| Eligibility parity | Một entity không được có membership khác nhau giữa surface | Một underlying policy và blocker/warning rõ ràng |
| Governance | Semantic mutation cần approval, revision, idempotency và audit | Controlled Apply là write boundary; Post publish vẫn độc lập |
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
| Brand structural storage | Registry/cardinality đã có; payload brand_uuid/model_uuid vẫn được PublicRouteResolver dùng; physical structural rows chưa được backfill | CODE_GAP, CONSTITUTION_CONFLICT nếu payload bị coi là canonical, DATA_COMPATIBILITY_GAP cho rows | PublicRouteResolver.php, StructuralContextQuery.php, BRAND_BACKBONE_STRUCTURAL_CONTRACT_EVIDENCE_2026-09-02.md |
| Direct/derived Brand aggregation | Read-only Brand aggregation trả DIRECT/DERIVED và path; không tạo shortcut edge | IMPLEMENTED | BrandAggregationQuery.php; current execution state |
| Public identity | Slug vẫn derive từ canonical_name lúc đọc; chưa có persisted public-slug/history contract | PUBLIC_IDENTITY_STORAGE_GAP, CODE_GAP | PublicIdentityContract.php, PublicRouteResolver.php, V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md |
| Public eligibility | Production composition wires PublicEntityCollectionQuery into home, search, entity routes và REST; legacy/fallback query branches vẫn tồn tại và cần convergence proof | CODE_GAP / PUBLIC_ELIGIBILITY_FAILURE candidate | Plugin.php, PublicEntityCollectionQuery.php, EntityPageQuery.php, EntityApi.php |
| Transitional parent handling | Clear active payload parent can remain eligible with DATA_COMPATIBILITY_GAP; missing/conflicting parent blocks; no edge mutation | PARTIAL but contract-visible | PublicEntityEligibilityPolicy.php, StructuralContextQuery.php |
| Technical public routes | Vietnamese hubs/detail routes and one-hop archive redirects are implemented in code; live stored-menu and some runtime evidence remain gated | PARTIAL runtime evidence | PublicEntityRoutes.php, V3_PUBLIC_HUB_MATRIX.md, V3_MENU_ROUTE_AUDIT_2026-09-02.md |
| Knowledge/Source/Evidence | Separate domain records, active/public reader-safe gates, governed ingest and evidence chain exist; final public provenance policy remains open | IMPLEMENTED with publication gate | KnowledgeClaim.php, Source.php, Evidence.php, MCP_V3_CONTENT_OPERATIONS.md |
| Media/Asset/Usage | Separate domain/persistence objects, readiness/visibility and guarded delivery exist; byte upload and final publication policy remain limited/open | IMPLEMENTED with policy gap | Media.php, MediaAsset.php, MediaUsage.php, PublicMediaAssetDelivery.php |
| Video | Validated YouTube external-reference identity, canonical watch URL and optional thumbnail reference; no local MP4 behavior | IMPLEMENTED for current contract | Video.php, VideoService.php, MCP_V3_CONTENT_OPERATIONS.md |
| Product/Specimen ownership | Both registered; Product payload allows specimen_uuid and broad about allows Product→Specimen, without one selected canonical owner | CONSTITUTION_CONFLICT | CanonicalEntityTypeCatalog.php, PredicateRegistry.php, MCP content-operations audit |
| Album | No Authority type, endpoint, predicate, repository, service or public contract | SEMANTIC_GAP | MCP content-operations audit |
| WordPress Post boundary | Native Post remains editorial title/body/author/date/category/URL truth; no Article Authority body path is approved | COMPLIANT | 01_EDITORIAL_CONTENT_BOUNDARY.md historical evidence; Plugin.php and public route contracts |
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
