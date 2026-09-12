# Clock Type PR1 Gap Report và PR2–PR6 Plan

Date: 2026-09-12
Scope: contract/read-only regression only
Mutation authority: none

## Canonical checkpoint

Giá trị được cập nhật sau khi canonical documentation snapshot được regenerate:

- `documentation_version`: `ed8012a761f267c925a7c5b2142335e6ae281f740c1e85aa2cc278afd7498f12`.
- `manifest_hash`: `d782c90bb45e8dcbe7fee4abab9e963b85904834e6fb5bb4a3c5114b19cb6c31`.
- `build_identity`: `714134b37edf08be023f221b2084269bc473d1d9e395e1f6bd6c793dda0c2cf3`.
- `runtime_version`: `0.1.0`. Đây là local package/runtime identity; không
  phải live/deploy evidence.

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

- Chưa có `EntityProfileRegistry` hoặc capability matrix cho `brand` và
  `clock_type`; profile behavior còn rải ở Brand-specific aggregation và
  generic dossier seams.
- Shared reverse Brand↔Clock Type recipe chưa hoàn tất. Current generic
  `RelatedSemanticQuery` bound là 2 hops, trong khi recipe Brand đọc qua
  Variant cần 3 edge; không được nâng bound trong PR1.
- Current Brand clock-type projection recognizes legacy `family=clock-type`,
  chưa canonical `family=clock_type`. PR2 phải tạo read normalization/compat
  seam fail-closed, PR5 mới xem xét governed new-data path.

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
- Legacy Classification family/Graph membership coverage cần audit riêng; không
  suy classification từ title/token matching đơn thuần.

## REGISTRY_GAP

- Không có runtime registry riêng cho Entity Profile/capability/recipe.
- Endpoint/predicate registry hiện hành đủ để không invent type/predicate mới,
  nhưng chưa biểu diễn profile-specific traversal recipe/collision namespace.

## CLIENT_EXPOSURE_GAP

- Không có dedicated Clock-Type operator write surface được PR1 cho phép.
  Classification hiện được đọc qua generic/entity/collector surfaces khi runtime
  dependency và public eligibility sẵn sàng; client-specific availability phải
  được xác nhận bằng fresh discovery, không suy ra từ code catalog.

## PUBLIC_PROJECTION_GAP

- Current Classification compatibility route là `/phan-loai/{slug}/`; desired
  root routes `/vai-bo/`, `/dong-ho-cong-cong/` và `/dong-ho-chim-cuc-cu/` chưa
  được reproject trong PR1.
- Persisted Public Identity implementation có trong code nhưng allocation,
  collision scope, current-route consumer parity và target-runtime read-back
  chưa live-proven cho Clock Type. Brand/Classification root collision phải fail
  closed hoặc governed resolution, không `foo-2` ngầm.

## Explicit no-mutation verification

PR1 thay đổi contract/documentation registry và chỉ dùng test in-memory. Không có
database migration, entity/claim/source/evidence insert/update/delete, Graph
apply/backfill, public slug allocation/reprojection, Article publish, Video,
Media hoặc Knowledge rewrite. Không tạo entity `Odo vai bò`, Unknown Brand hay
Brand↔Clock Type shortcut.

## PR2–PR6 implementation seams

| Phase | Chỉ trong scope phase | Không được gộp |
|---|---|---|
| PR2 | `EntityProfileRegistry`, capability matrix, exact family read foundation, shared bounded recipe API, registry tests | no writes, no slug allocation, no backfill |
| PR3 | Classification dossier/profile read projection, Public Identity root-route collision/read resolver, SEO/read-only acceptance | no silent URL reproject, no semantic mutation |
| PR4 | Capture one-primary-subject handoff, shadow classification diagnostics/candidates | no `classified_as` apply |
| PR5 | new-data governed `classified_as`, scope/evidence/revision/idempotency, shared Brand↔Type derived query | no legacy apply, no shortcut edge |
| PR6 | legacy inventory, evidence-aware dry-run, owner-review packet and canonical read-back plan | no live apply, no title/token-only inference |

## Review gate

Chỉ bắt đầu PR2 sau khi PR1 được review. Không claim implementation/live/deploy
readiness từ contract snapshot hoặc in-memory golden tests.
