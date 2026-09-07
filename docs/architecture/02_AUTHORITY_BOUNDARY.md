# Ranh giới Authority

> **NON-NORMATIVE CURRENT BOUNDARY GUIDE.** Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Authority sở hữu identity và lifecycle của đúng chín canonical semantic family
hiện được đăng ký: Brand, Model, Variant, Movement, Music, Component,
Classification, Specimen và Product.

Knowledge, Source, Evidence, Media và Video có canonical domain identity riêng;
chúng không phải Authority type và không được ghi như Authority chỉ vì cùng tham
gia Semantic Graph. WordPress Post cũng không phải Authority; Post là editorial
owner và chỉ xuất hiện như endpoint đã đăng ký khi một relation contract cần nó.

## Resolve and reconcile before create

Trước khi tạo Authority node mới, resolver/research phải kiểm tra canonical
state theo UUID → stable key → exact canonical name/alias và đối chiếu intent mới
với record hiện có. Kết quả phải là `EXACT_EXISTING`, `MERGE_CANDIDATE`,
`RELATED_BUT_DISTINCT`, `NO_EXISTING_CANONICAL_RECORD` hoặc `UNCERTAIN`.

Fuzzy/keyword/lexical similarity chỉ hỗ trợ discovery, không chứng minh identity.
`NO_EXISTING_CANONICAL_RECORD` mới cho phép đi vào governed create. `UNCERTAIN`
phải defer; không mint node tạm để “gắn dữ liệu sau”.

Authority mutation dùng owner service/repository qua Governance theo lifecycle:
`proposal/create-or-ingest → submit → review → approval binding → eligibility →
Controlled Apply → canonical Authority read-back → idempotency verification`.

## Current create runtime evidence — 2026-09-07

Runtime probe proposal `01a07c4e-14b2-734e-8264-3f04b37e5fe4` với
`operation=create, entity_type=classification` đã PASS create, submit, review,
approval và eligibility (`ready=true`, `reasons=[]`). Probe cố ý không Apply để
tránh tạo canonical Classification rác.

Do đó Authority create pipeline không phải blocker tới eligibility. Chưa được
claim là đã runtime-proven cho một node mới thật ở các bước Controlled Apply →
generated canonical UUID → `entity_get`/resolver read-back → immediate relation
use. Phần đó chỉ chứng minh khi có một Authority node thật sự cần tạo.

`subject_id="classification"` trong create proposal không phải cùng lỗi với
historical `relation_create` hydration defect: trước create chưa có canonical UUID
của node mới. Relation hiện hành phải dùng source UUID thật.
