# Clock Type PR1/PR2 Gap Report và PR3–PR6 Plan

Date: 2026-09-12
Scope: contract/read-only foundation only; no semantic mutation
Mutation authority: none

## Canonical checkpoint

Giá trị được cập nhật sau khi canonical documentation snapshot được regenerate:

- `documentation_version`: `95c14c17cc433cafbaae07f75e14b678bff2b017ec7366269d560f667a56ba3c`.
- `manifest_hash`: `d8f437ac9bb126d77bbe3e00fe6050aa32058e0c0202b46163dbebf23791572b`.
- `build_identity`: `50019f1355917fbdad3e0da4a5b9998ed168489798cf7683f3f6ac8c8c00b6d1`.
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

- Profile-aware foundation chưa được wired vào frontend/controller public flow;
  PR3 mới đánh giá Classification dossier/public projection integration.
- Shared reverse Brand↔Clock Type recipe chưa hoàn tất. Current generic
  `RelatedSemanticQuery` bound là 2 hops, trong khi recipe Brand đọc qua
  Variant cần 3 edge; không được nâng bound trong PR1.
- Current Brand clock-type projection still recognizes legacy
  `family=clock-type`; the new resolver now exposes this only as
  `COMPATIBILITY_READ` and does not normalize stored data. Existing Brand
  aggregation convergence remains a later seam.

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
- Public Identity collision namespace và route consumer parity vẫn là gap của
  PR3, không được giải quyết trong PR2.
- Endpoint/predicate registry hiện hành đủ để không invent type/predicate mới,
  nhưng chưa biểu diễn profile-specific traversal recipe/collision namespace.

## CLIENT_EXPOSURE_GAP

- Không có dedicated Clock-Type operator write surface được PR1 cho phép.
  Classification hiện được đọc qua generic/entity/collector surfaces khi runtime
  dependency và public eligibility sẵn sàng; client-specific availability phải
  được xác nhận bằng fresh discovery, không suy ra từ code catalog.
- Clock-Type dossier foundation hiện chỉ là application read seam; frontend/
  client availability chưa được wire hoặc live-proven.

## PUBLIC_PROJECTION_GAP

- Current Classification compatibility route là `/phan-loai/{slug}/`; desired
  root routes `/vai-bo/`, `/dong-ho-cong-cong/` và `/dong-ho-chim-cuc-cu/` chưa
  được reproject trong PR1.
- Persisted Public Identity implementation có trong code nhưng allocation,
  collision scope, current-route consumer parity và target-runtime read-back
  chưa live-proven cho Clock Type. Brand/Classification root collision phải fail
  closed hoặc governed resolution, không `foo-2` ngầm.
- Clock-Type dossier frontend readiness: `LOCAL_FOUNDATION_ONLY`; PR3 mới xử lý
  public dossier/root-route consumer foundation.

## Explicit no-mutation verification

PR1 thay đổi contract/documentation registry và chỉ dùng test in-memory. Không có
database migration, entity/claim/source/evidence insert/update/delete, Graph
apply/backfill, public slug allocation/reprojection, Article publish, Video,
Media hoặc Knowledge rewrite. Không tạo entity `Odo vai bò`, Unknown Brand hay
Brand↔Clock Type shortcut.

## PR3–PR6 implementation seams

| Phase | Chỉ trong scope phase | Không được gộp |
|---|---|---|
| PR3 | Classification dossier/profile read projection, Public Identity root-route collision/read resolver, SEO/read-only acceptance | no silent URL reproject, no semantic mutation |
| PR4 | Capture one-primary-subject handoff, shadow classification diagnostics/candidates | no `classified_as` apply |
| PR5 | new-data governed `classified_as`, scope/evidence/revision/idempotency, shared Brand↔Type derived query | no legacy apply, no shortcut edge |
| PR6 | legacy inventory, evidence-aware dry-run, owner-review packet and canonical read-back plan | no live apply, no title/token-only inference |

## Review gate

PR2 đã hoàn tất ở mức local additive/read-side foundation. Chỉ bắt đầu PR3 sau
khi PR2 được review. Không claim implementation/live/deploy readiness từ
contract snapshot hoặc in-memory golden tests.
