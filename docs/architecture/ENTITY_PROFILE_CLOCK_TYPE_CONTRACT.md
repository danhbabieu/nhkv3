# NHK V3 Entity Profile — Brand và Clock Type Contract

> **ACTIVE contract — PR1, 2026-09-12.** Contract này nằm dưới
> `docs/constitution/NHK_V3_CONSTITUTION.md`. Nó khóa read/profile seams và
> regression boundary; không cấp quyền tạo semantic data, ghi Graph, cấp slug,
> migrate/backfill dữ liệu hoặc triển khai Clock Type write-path.

## 1. Quyết định kiến trúc

NHK V3 dùng một semantic core, một Capture pipeline, một Graph và một shared
Entity Dossier/Related Projection. Brand và Clock Type là hai Entity Profile
độc lập trên cùng core; Profile là capability/query/presentation contract, không
phải Authority type hoặc persistence owner mới.

| Profile | Canonical Authority shape | Ý nghĩa |
|---|---|---|
| `brand` | `entity_type=brand`, `profile=brand` | Thương hiệu, không bắt buộc với mọi vật thể/listing |
| `clock_type` | `entity_type=classification`, `family=clock_type`, `profile=clock_type` | Loại đồng hồ, là một Classification family |

Không tạo `ClockType` hoặc `clock_type` Authority/Graph endpoint mới. Runtime
`EntityTypeRegistry` vẫn chỉ đăng ký `classification`; `family` phải được đọc
từ payload đã validate. `family` không được suy ra từ tên, stable key, slug,
title, token matching hoặc UI label.

### 1.1 Exact family và phân tách Classification

`clock_type` là family canonical của Profile này. Các family khác vẫn độc lập,
ví dụ `case_form`, `origin`, `material`, `dial_form` và
`recognition_feature`. Cùng tên hiển thị hoặc lexical similarity không phải
merge proof:

```text
"Đồng hồ vai bò" + family=clock_type  => Clock-Type Profile
"Dáng vai bò"     + family=case_form  => Case-Form Classification
```

Hai record trên giữ canonical UUID/stable key riêng. Facet composition như
`Table Clock + France` cũng không tạo một Classification identity kết hợp.
Family thiếu, malformed hoặc không resolve được phải fail closed và giữ
diagnostic; không đoán từ stable-key prefix.

Current runtime còn có compatibility fixture/branch nhận `family=clock-type`.
Đó là legacy compatibility/data gap, không phải family canonical mới và không
được dùng làm lý do để đổi live data trong PR1.

## 2. Brand optionality và identity

Product/Specimen hợp lệ với bất kỳ trạng thái nào sau đây:

```text
Brand + Clock Type
không Brand + Clock Type
Brand + chưa resolve Clock Type
```

Không tạo `Unknown Brand`, `No Brand`, `Unbranded` hoặc filler identity. Thiếu
Brand không làm Product/Specimen invalid. Specimen vẫn là một physical object;
Product vẫn là một listing/offer và không thay thế Specimen.

Mọi Profile dùng cùng canonical UUID, scoped stable key, revision, lifecycle,
Public Identity và Governance policy hiện hành. Display name, family, profile
label và public slug không phải canonical identity.

## 3. Relation matrix và scope

Graph là relation store duy nhất. PR1 không thêm predicate; chỉ tái khẳng định
vocabulary đã đăng ký:

| Source | Predicate | Target | Clock-Type rule |
|---|---|---|---|
| Model | `model_of` | Brand | structural child → parent |
| Variant | `variant_of` | Model | structural child → parent |
| Model, Variant, Specimen, Product | `classified_as` | Classification | direct membership, source scope phải đúng source type |
| Classification | `subtype_of` | Classification | chỉ same-family, ACTIVE, cycle-free |

`classified_as` không được tạo trực tiếp từ Brand hoặc Movement. Movement cần
constitutional/contract extension riêng; không dùng shortcut để lách rule.
Specimen/Product membership không làm phát sinh Brand, Model hoặc Variant nếu
scope/evidence không hỗ trợ.

### 3.1 Derived Brand ↔ Clock Type

Brand ↔ Clock Type là derived association, không phải canonical edge. Các path
hợp lệ có thể gồm:

```text
Brand
← model_of — Model
← variant_of — Variant
— classified_as → Classification(family=clock_type)
```

Brand → Clock Type và Clock Type → Brand chỉ là query/projection result theo
profile-specific recipe, giữ `DIRECT|DERIVED`, `hop_count`, predicate path và
provenance khi contract hỗ trợ. Direct membership của Model/Variant/Specimen/
Product không được rewrite thành `Brand → classified_as → Clock Type`.

Không persist:

```text
Brand.types[]
ClockType.brands[]
Brand ──classified_as──> Clock Type
```

Không tạo giao điểm semantic như `Odo vai bò`. Derived result không được ghi
shortcut edge, taxonomy, post meta hoặc parallel store để frontend query dễ hơn.
Generic related traversal vẫn tuân `MAX_HOPS` hiện hành; recipe dài hơn phải
được đăng ký rõ ràng, không được nâng global bound âm thầm. Trong PR1, reverse
three-edge Brand recipe chưa được coi là shared-engine capability hoàn tất.

## 4. Shared Entity Dossier / Profile seam

Shared dossier có thể compose, theo availability và profile capability:

```text
Identity → Public Identity → Knowledge → Media → Video
        → Article/Post relation → related entities → representative Media
        → Specimen/Product context → SEO → Public Dossier
```

Profile-specific relation/query recipe quyết định predicate và scope; không
được rải logic theo kiểu `if brand ... if classification ...` khi một registry
hoặc capability matrix biểu diễn được khác biệt đó. Tuy nhiên PR1 không refactor
Brand aggregation đang production-safe và không claim EntityProfileRegistry đã
được implement.

Direct Knowledge vẫn exact subject-scoped. Related Knowledge, Media, Video,
Article/Post và descendant context giữ origin/path; reachable không đồng nghĩa
ownership hoặc claim promotion. Archive/list nhẹ hơn detail dossier. Empty,
unavailable, invalid row, conflict và infrastructure failure vẫn là các trạng
thái khác nhau.

## 5. Capture contract

Brand và Clock Type đều là semantic context trong cùng một Capture, không phải
hai primary subject. Một submission có đúng một canonical primary subject,
thường là Specimen/Product/Variant hoặc subject đã resolve; Capture sau đó có
thể resolve Brand, Clock Type và các context khác trong cùng packet.

```text
Capture
→ interpret once
→ resolve one primary subject
→ resolve Brand/Clock-Type semantic context
→ bounded Graph/Claim/Media/Video candidates
→ Governance/review theo owner contract
```

PR1 chưa bật automatic `classified_as`, không tạo candidate apply, không submit/
approve/apply proposal và không đổi Capture write-path. PR4 mới chuẩn bị shadow
classification; mọi write sau đó vẫn phải đi qua Governance và canonical
read-back.

## 6. Media, Video và Knowledge compatibility

Việc tồn tại Clock-Type Profile không thay đổi owner hoặc output của domain cũ:

- Media, MediaAsset và MediaUsage giữ nguyên identity/storage/optimization,
  source-original, derivative, representative và contextual usage contract.
  Ảnh một physical object ưu tiên `Media — depicts → Specimen` khi exact scope
  và evidence hỗ trợ; không tạo hàng loạt shortcut tới Brand/Model/Variant/
  Clock Type.
- Video giữ canonical UUID, platform + external source identity, current
  `about` target, governed Proposal lifecycle, Capture handoff, Knowledge
  enrichment scope, Public Identity, `/video/{slug}/`, SEO, VideoObject,
  sitemap, thumbnail và readiness/publication semantics. Nếu Video `about`
  Variant thì thêm `Variant — classified_as → Clock Type` không rewrite Video
  thành `about → Clock Type`.
- Knowledge vẫn là atomic claim với subject/scope/provenance/Evidence riêng.
  Reachable Clock Type không promote claim của Specimen/Variant/Model/Brand;
  transcript, OCR, caption, generated copy và lexical match không tự là
  Evidence hay Graph truth.

Các regression phải so sánh canonical owner output trước/sau khi thêm Clock-Type
context, không dùng live mutation để chứng minh compatibility.

## 7. Public Identity và route intent

PR1 không đổi route hiện có, không cấp slug và không reproject live identity.
Desired future detail route cho root Public Identity namespace là:

```text
Brand Odo                  → /odo/
Clock Type Đồng hồ vai bò  → /vai-bo/
Clock Type Đồng hồ công cộng → /dong-ho-cong-cong/
Clock Type Đồng hồ chim cúc cu → /dong-ho-chim-cuc-cu/
```

Archive/navigation có thể giữ `/thuong-hieu/` và `/loai-dong-ho/`. Route
resolution tương lai phải là:

```text
slug → Public Identity → canonical UUID → entity_type/profile → Entity Dossier
```

Không đoán entity type từ slug. Root slug phải unique trong toàn bộ routable
Public Identity namespace; collision giữa Brand và Classification phải fail
closed hoặc cần governed resolution. Không tự sinh `foo-2` nếu contract chưa
cho phép. Video vẫn giữ route exception `/video/{slug}/` hiện hành.

## 8. Backward compatibility và forbidden shortcuts

Contract này additive và không làm thay đổi existing Brand-only, Media,
Knowledge, Video, Article/Post, public route hoặc frontend behavior. Không được:

- tạo semantic data, seed Classification, backfill hoặc sửa Graph edge;
- migrate live/V2/staging/production hoặc parse legacy Article body;
- triển khai Clock-Type write-path trong PR1;
- tạo `ClockType` entity type, `Odo vai bò`, Unknown Brand hoặc Brand↔Type edge;
- đổi Video `about`, Video/Media/Knowledge identity, SEO, VideoObject, sitemap,
  thumbnail, readiness hoặc public route;
- dùng title/token/name similarity để suy classification hoặc merge family;
- persist hai mảng Brand↔Type làm canonical truth.

Nếu current runtime, data, registry, client hoặc public consumer chưa đáp ứng
contract, ghi đúng gap taxonomy và fail closed; không hạ contract để hợp thức
hóa code/fixture cũ.

## 9. Rollout và change control

Các phase là additive, tách biệt và không gộp thành một migration lớn:

1. **PR1 — Contract + golden regression:** khóa profile/family/scope/route
   intent, xác nhận current behavior và gap report; không write-path/data.
2. **PR2 — EntityProfileRegistry + Clock-Type read foundation:** registry,
   capability matrix, canonical `clock_type` read normalization và shared
   query seams; vẫn read-only.
3. **PR3 — Classification public dossier + root-route support:** dossier/profile
   projection, Public Identity collision policy và route read-back; không đổi
   URL hiện có âm thầm.
4. **PR4 — Capture shadow classification:** interpret/resolve context một lần,
   shadow-only candidate và diagnostics; không apply `classified_as`.
5. **PR5 — Governed new-data membership + derived projection:** chỉ data mới,
   evidence/scope/revision/idempotency đầy đủ, Governance Apply và Brand↔Type
   derived query; không shortcut edge.
6. **PR6 — Legacy dry-run:** read-only audit → deterministic evidence review →
   dry-run report; không title/token matching đơn thuần, không apply.
7. **PR7+ — Legacy governed apply:** chỉ sau owner review và approval riêng:
   governed batch → canonical read-back → projection/route verification.

Legacy rollout luôn là `audit → dry-run → owner review → governed batch →
canonical read-back`; mỗi phase có checkpoint/test evidence riêng.

## 10. PR1 acceptance seam

PR1 phải giữ green các golden tests cho Video, Brand-only content, brandless
Specimen/Product, Brand + Clock Type derived projection và family separation.
Test fixture chỉ ở in-memory/test repository; không phải semantic seed. Tests
phải chứng minh empty Clock-Type relation là successful empty, còn dependency/
Graph/runtime failure không bị đổi thành empty success.

PR1 không claim live/deploy readiness. Runtime checkpoint, documentation
manifest/build identity và changed-file test evidence phải được ghi trong gap
report/execution state sau checkpoint.
