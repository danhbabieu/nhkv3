# Clock Type PR1–PR4 Gap Report và PR5–PR6 Plan

Date: 2026-09-12
Scope: contract/read-only foundation only; no semantic mutation
Mutation authority: none

## Canonical checkpoint

Giá trị được cập nhật sau khi canonical documentation snapshot được regenerate:

- `documentation_version`: `22b30cd7dd9283478027bc3c5ac370c8ab198d92fbe256896c88b7a3995d0800`.
- `manifest_hash`: `696ab741e72efcc38dac8e7d3695b4e131eedda1ed90230be896e06daaa28f88`.
- `build_identity`: `d9fedecc168309c74f18bfb8c88461a090e4d79fb1c85368fb7cb8bb69ffbc3e`.
- `runtime_version`: `0.1.0`. Đây là local package/runtime identity; không
  phải live/deploy evidence.

## IMPLEMENTED_IN_PR2

- `EntityProfileRegistry` là application-level registry với đúng hai profile:
  `brand` và `clock_type`; không thêm Authority type, endpoint hoặc predicate.
- `EntityProfileResolver` resolve duy nhất từ `entity_type` và persisted
  `payload.family`: canonical `clock_type`, legacy read-compatible
  `clock-type`, còn family khác/missing/unknown là `PROFILE_UNRESOLVED`.
- `EntityProfileReadFoundation` bọc `EntityDossierReader` hiện hành để trả
  profile-aware read packet; không tạo datastore, route, slug hoặc writer mới.
- `SemanticDossierQuery` chỉ implement read-only `EntityDossierReader`; Brand
  aggregation/frontend path hiện hành không bị refactor.
- Target groups và capabilities trong registry là projection metadata; predicate
  legality vẫn thuộc Graph registry/policy.

## IMPLEMENTED_IN_PR3

- `EntityProfilePublicDossier` dùng shared read foundation để expose section
  readiness và profile-specific presentation metadata cho Brand/Clock Type;
  Brand aggregation/query path cũ không bị refactor.
- `RootPublicEntityRouteResolver` chỉ resolve `/{slug}/` từ một persisted root
  Public Identity read adapter đến canonical Authority UUID/type rồi qua
  `EntityProfileResolver` và shared dossier. Missing/namespaced identity không
  tạo URL hoặc reproject.
- `RootRouteCollisionPolicy` và `RootRouteOwnershipReader` tạo global collision
  seam; WordPress adapter reuse `PublicRouteResolver` reserved-root registry và
  native WP owner checks, còn thiếu central registered-route owner thì trả typed
  implementation gap.
- Clock-Type root fixture, legacy `clock-type`, case-form separation,
  brandless dossier, Entity/Page/reserved/registered-route collisions và
  `/video/{slug}/` non-interception đã có regression coverage.

## IMPLEMENTED_IN_PR4

- `ClockTypeShadowClassifier` consume Capture's already-resolved one-primary-
  subject context and returns transient typed candidates/diagnostics only.
- Evidence order is explicit canonical identity → exact scoped context →
  existing canonical membership reader → explicit user statement → weak
  lexical/media review. Brand never supplies Clock-Type evidence.
- Canonical `clock_type`, legacy `clock-type`, `case_form`, unknown family,
  brandless input, multiple candidates, weak title input and existing
  `classified_as` membership are covered by read-only unit tests.
- No shadow result is persisted and the packet exposes no writer/proposal/apply
  field. Video/Media/Knowledge context is ignored for mutation and the primary
  subject packet is retained unchanged.
- Local verification: 13 tests / 49 assertions for the shadow classifier.

## ALREADY_SUPPORTED

- `EntityTypeRegistry`/`CanonicalEntityTypeCatalog` có `brand` và
  `classification`, không có `clock_type` Authority type.
- `PredicateRegistry` đã có `model_of`, `variant_of`, `classified_as` và
  `subtype_of`; `ClassifiedAsPolicy` giới hạn membership vào
  Model/Variant/Specimen/Product và giữ scope/provenance.
- `BrandAggregationQuery` đã có read-only `clock_types` bucket và derived
  Brand recipe; không persist shortcut edge.
- `SemanticDossierQuery`/`BrandDossierProjection` là shared dossier seam;
  direct Knowledge, MediaUsage, Video và Article/Post giữ owner riêng.
- Video external identity, `about` attachment, Public Identity/`/video/{slug}/`,
  Video SEO/VideoObject/sitemap/thumbnail/readiness và Capture handoff đã có
  focused regression boundaries.
- Media identity/storage/derivative/representative contract và Knowledge
  subject/provenance/Evidence contract đã được bảo vệ bởi current tests/docs.

## IMPLEMENTATION_GAP

- Profile-aware foundation/public dossier chưa được wired vào frontend/controller
  production flow; PR3 mới cung cấp application seam và in-memory acceptance.
- Shared reverse Brand↔Clock Type recipe chưa hoàn tất. Current generic
  `RelatedSemanticQuery` bound là 2 hops, trong khi recipe Brand đọc qua
  Variant cần 3 edge; không được nâng bound trong PR1.
- Current Brand clock-type projection still recognizes legacy
  `family=clock-type`; the new resolver now exposes this only as
  `COMPATIBILITY_READ` and does not normalize stored data. Existing Brand
  aggregation convergence remains a later seam.
- The existing production Capture coordinator is not yet injected with the
  shadow classifier in this checkpoint. The new service is a safe application
  seam and is exercised directly; wiring its transient diagnostic into the
  coordinator requires a separate scoped change that must preserve the
  concurrent Capture continuation work.

## CONTRACT_GAP

- Trước PR1 chưa có ACTIVE contract định nghĩa Entity Profile, Clock-Type
  profile, exact family, optional Brand, derived Brand↔Type và root Public
  Identity intent trong một boundary.
- Profile-specific dossier/query recipes, path explanation và reverse-query
  acceptance chưa được contract hóa đầy đủ.

## DATA_GAP

- PR1 không inventory/mutate live semantic data để lấp gap. Các Classification
  fixture/pack hiện hành dùng legacy hyphen family và không được coi là proof
  của canonical underscore data.
- Actual `classified_as` data count: **NOT_VERIFIED**. PR2 không đọc live/
  integration inventory và không đưa ra count suy đoán.
- Legacy Classification family/Graph membership coverage cần audit riêng; không
  suy classification từ title/token matching đơn thuần.

## REGISTRY_GAP

- Entity Profile/capability/recipe registry gap đã được đóng ở mức application
  read foundation; predicate/endpoint legality vẫn không thuộc registry này.
- Production route consumer chưa gọi root resolver; current router vẫn giữ
  Classification ở `/phan-loai/{slug}/` và Brand behavior hiện hành.
- Endpoint/predicate registry hiện hành đủ để không invent type/predicate mới,
  nhưng chưa biểu diễn profile-specific traversal recipe/collision namespace.
- The read-only `ClockTypeCanonicalMembershipReader` contract is only an
  adapter seam; a production Graph-backed implementation is not registered.

## CLIENT_EXPOSURE_GAP

- Không có dedicated Clock-Type operator write surface được PR1 cho phép.
  Classification hiện được đọc qua generic/entity/collector surfaces khi runtime
  dependency và public eligibility sẵn sàng; client-specific availability phải
  được xác nhận bằng fresh discovery, không suy ra từ code catalog.
- Clock-Type dossier foundation hiện là application read/presentation seam;
  frontend/client availability chưa được wire hoặc live-proven.

## PUBLIC_PROJECTION_GAP

- Current Classification compatibility route là `/phan-loai/{slug}/`; desired
  root routes `/vai-bo/`, `/dong-ho-cong-cong/` và `/dong-ho-chim-cuc-cu/` chưa
  được reproject trong PR3; root read resolution chỉ là foundation.
- Persisted Public Identity implementation có trong code nhưng allocation,
  collision scope, current-route consumer parity và target-runtime read-back
  chưa live-proven cho Clock Type. Brand/Classification root collision phải fail
  closed hoặc governed resolution, không `foo-2` ngầm. Current production
  route-owner registry chưa được nối vào root resolver.
- Clock-Type dossier frontend readiness: `LOCAL_FOUNDATION_ONLY`; shared
  presentation packet đã có nhưng theme/controller/live HTTP chưa wire.

## PR3 STATUS / PUBLIC_IDENTITY_GAP

- Root slug collision coverage: **LOCAL_READER_FIXTURES_ONLY**. Entity vs
  Entity, Page, reserved `/video/` và registered route đều fail closed trong
  policy test; live cross-owner inventory chưa được chạy.
- Brand root route: **EXISTING_CURRENT_CONSUMER_UNCHANGED**. PR3 không migrate
  hoặc reproject Brand URLs.
- Clock-Type root route: **READ_FOUNDATION_ONLY**. `/vai-bo/` và các desired
  examples chỉ resolve khi fixture/owner đã có persisted root Public Identity;
  current Classification identity allocation/path vẫn namespaced.
- Legacy family: `clock_type` canonical; `clock-type` compatibility-read only;
  no normalization.
- Actual `classified_as` count: **NOT_VERIFIED**; no live Graph inventory was
  executed.
- Brand↔Clock Type derived traversal: **IMPLEMENTATION_GAP**. Shared generic
  reader remains bounded at two hops; PR3 exposes an unavailable section thay vì
  SQL/taxonomy/title fallback hoặc shortcut persistence.
- Live frontend/root-route verification: **NOT_RUN / ENVIRONMENT_GATED**.

## PR4 STATUS / SHADOW_CLASSIFICATION

- Shadow classifier: **IMPLEMENTED_IN_PR4 / LOCAL_UNIT_PROVEN**; no live Capture
  run was performed.
- Canonical `clock_type`: resolved only from existing active Classification
  rows through explicit/scoped evidence or an injected canonical membership
  reader.
- Legacy `clock-type`: **COMPATIBILITY_READ_ONLY** with retained
  `DATA_COMPATIBILITY_GAP`; no normalization or UUID/stable-key change.
- Actual `classified_as` inventory: **NOT_VERIFIED**; PR4 did not read or
  mutate live Graph data.
- Brand↔Clock Type reverse traversal: **IMPLEMENTATION_GAP**; PR4 does not
  provide a SQL/taxonomy/title fallback or shortcut edge.
- Capture runtime diagnostic persistence: **IMPLEMENTATION_GAP**; shadow result
  remains transient until a separately scoped coordinator adapter is approved.
- Root route/live frontend readiness: **NOT_LIVE_PROVEN**; PR4 does not
  allocate, reproject, redirect or enable root routes.

## Explicit no-mutation verification

PR1–PR4 chỉ thay đổi application read seams, contract/documentation registry và
test in-memory. Không có database migration, entity/claim/source/evidence
insert/update/delete, Graph apply/backfill, public slug allocation/reprojection,
Article publish, Video, Media hoặc Knowledge rewrite. Không tạo entity `Odo vai
bò`, Unknown Brand hay Brand↔Clock Type shortcut.

## PR5–PR6 implementation seams

| Phase | Chỉ trong scope phase | Không được gộp |
|---|---|---|
| PR5 | new-data governed `classified_as`, scope/evidence/revision/idempotency, shared Brand↔Type derived query | no legacy apply, no shortcut edge |
| PR6 | legacy inventory, evidence-aware dry-run, owner-review packet and canonical read-back plan | no live apply, no title/token-only inference |

## Review gate

PR3 đã hoàn tất ở mức local additive/read-side foundation. PR4 chỉ bắt đầu sau
review PR3; không claim implementation/live/deploy readiness từ contract
snapshot hoặc in-memory golden tests.
