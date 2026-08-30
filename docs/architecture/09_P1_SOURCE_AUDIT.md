# P1 Source Audit — NHK V2 → NHK V3

## 1. Executive summary

Project cũ có contract governance, identity, revision, provenance, readiness,
external Video và test invariants có giá trị kế thừa. Tuy nhiên implementation
đã phình thành plugin lớn (596 PHP source files, 197 test files) với nhiều
repository, persistence shape và projection path song song. V3 nên kế thừa
contract/invariant, viết lại implementation theo vertical slice, không copy
class/plugin/schema.

Kết luận chính: Article authority/projection cho bài mới phải retire; WordPress
Post là nguồn sự thật của body. Semantic graph, domain tables, media/video/
proposal engine V3 không được triển khai trong P1.

## 2. Source tree đã rà

Đã rà các vùng `src/Core`, `Contracts`, `Shared`, `Domain/Authority`,
`CoreData`, `Identity`, `Knowledge`, `Media`, `MediaCollection`, `Operator`,
`Projection`, `Video`, `Seo`; `Application/Operator`, `Media`, `Video`,
`Source`, `Knowledge`, `Projection`, `Semantic`, `Editorial`, `ContentIntake`,
`Audit`; `Infrastructure/Migration`, `Persistence`, `Projection`, `Semantic`;
`Presentation/Admin`, `Abilities`, `Cli`, `Frontend`, `Rest`.

Đã đối chiếu docs trong `docs/project`, `docs/architecture`, `docs/core-data`,
`docs/data`, `docs/domain`, `docs/editorial`, `docs/knowledge`, `docs/laws`,
`docs/projection`, `docs/runtime`, `docs/admin`, `docs/integration`; tests trong
`tests/Unit`, `tests/Integration`, `tests/Support`, `tests/Fixtures`, `tests/Js`;
và migration/rehearsal ở `migrations/004`–`007`, `scripts/`.

## 3. Contract tốt cần kế thừa

Identity phải tách UUID/local ID/stable key/slug; stable key không tái sinh từ
label. Revision + expected revision, idempotency, approval binding, audit actor,
source provenance và fail-closed public eligibility đều là invariant dài hạn.
Video external reference và Media asset/attachment separation phù hợp V3.
Typed relation validation, reverse query, pagination và duplicate policy cần giữ
ở contract nhưng viết lại thành một implementation duy nhất sau review.

## 4. Implementation phải viết lại

Các vùng rewrite chính: `Core/Plugin` composition root, AuthorityType/registry,
`WpdbRelationManager`, semantic/knowledge/media/video relation stores,
`ControlledApplyService`, dependency closure, proposal persistence, public
projection orchestration và migration runner. Lý do chung là coupling cao,
trùng lặp repository, và logic domain ràng buộc trực tiếp vào WPDB/legacy tables.
`Snapshot`/`StableKey` nên giữ ý tưởng nhưng tách compatibility hydration khỏi
canonical runtime.

## 5. Compatibility chỉ dùng migration

`StableKey::fromStoredValue()` legacy form, `LegacyVideoRepository`, media
backfill/bridge, legacy proposal/import readers và source identifiers chỉ được
tồn tại trong boundary migration/read-only compatibility. Không đưa chúng vào
V3 domain core hoặc write path.

## 6. Code/flow cần retire

- Article Authority body và Article → Projection → WordPress Post cho bài mới;
- Proposal Center UI và admin/frontend breadth trong P1;
- semantic merge/cleanup/purge runtime cũ;
- graph/projection paths chỉ phục vụ legacy schema;
- video local-asset assumptions trong compatibility path;
- mảng ID lớn trong JSON/postmeta thay cho relation query có index.

## 7. Test invariants phải viết lại

| Old test | Invariant | V3 test tương ứng |
|---|---|---|
| `CanonicalIdentityMatcherTest` | stable/canonical identity không trùng | Unit identity |
| `CanonicalIdentityUnificationTest` | không tạo identity thứ hai | Unit identity boundary |
| `BatchIdempotency` | replay không duplicate | Unit idempotency |
| `ControlledApplyVerificationTest` | expected revision + verification | Contract mutation |
| `ControlledApplyRecoveryContractTest` | recovery giữ approved payload | Integration recovery |
| `ProposalWorkflowAdminTest` | approval state/binding | Contract governance, không phụ thuộc UI |
| `DependencyAwareBatchApplyServiceTest` | provider-before-dependent closure | Unit planner |
| `MediaPredicateRoundTripContractTest` | typed relation round trip | Contract relation |
| `SemanticMergeRelationOperationTest` | duplicate/relation safety | Unit relation idempotency |
| `SourceEntityContractTest` | provenance/source contract | Unit provenance |
| `MediaFoundationV1Test` | Media identity/asset separation | Unit media boundary |
| `MediaReadConsistencyTest` | readiness/read-back consistency | Integration media read |
| `VideoSimpleIntakeContractTest` | URL → external ID | Unit video reference |
| `MigrationRunnerTest` | version/status/idempotency | Integration migration |
| `PerformanceHardeningRegressionTest` | pagination/no N+1 | Query/performance contract |

Article/projection tests phải chuyển trọng tâm: phần còn cần giữ trở thành
legacy reader hoặc migration mapping test, không thành dependency của bài mới.

## 8. Dependency nguy hiểm

1. `Plugin::boot()` khởi tạo quá nhiều module và WPDB adapter.
2. `WpdbRelationManager` dùng field registry và special-case structural edges.
3. `RelationRegistry`, `KnowledgeRelationTypeRegistry`, `MediaPredicateRegistry`
   cùng tham gia xác định semantics.
4. `nhk_relations`, `nhk_knowledge_relations`, `nhk_visual_media_edges` và
   `nhk_media_usage` tạo nhiều graph logic/persistence shape.
5. `MediaEntity` gom identity, usage/presentation, asset và relations.
6. `MediaV2Schema` khác với migration tables thực tế.
7. `ControlledApplyService` điều phối proposal, identity, dependency,
   transaction, verification và projection trong một engine.
8. `PublicProjectionService` có nhiều type-specific branches.
9. `AuthorityType` đóng nhưng hệ thống đồng thời yêu cầu registry mở rộng.
10. Legacy compatibility hydration nằm trong canonical value objects.

## 9. Failure modes cũ không được lặp lại

- Projection mismatch (version/slug/type/parent/readiness) phải fail-closed và
  không được trở thành source truth.
- Approval phải bind content fingerprint, expected revision và dependency closure;
  stale/đổi closure buộc re-plan/re-approve.
- Attachment/upload không phải canonical Media; filename/YouTube ID không tạo identity.
- Video không tạo attachment/local binary.
- Relation sai type, self-edge, duplicate hoặc thiếu provenance phải bị từ chối.
- Không dùng mảng ID lớn trong postmeta/JSON thay relation query có index.
- Compatibility reader không được lọt vào canonical write path.

## 10. Những quyết định chưa đủ dữ kiện

- UUID v4/v7 và database representation;
- vocabulary predicate, cardinality, relation status, provenance;
- Media asset/usage ownership boundary;
- Article legacy reader và retention projection cũ;
- Proposal state tối thiểu và mutation nào cần governance;
- Source/Evidence/Citation là ba entity riêng hay không;
- identity/naming cuối cùng cho Product/Specimen/Artifact.

## 11. Đề xuất phạm vi P2

Chỉ chốt identity/stable key, version/revision, registry extension boundary và
read-only relation contract trong một vertical slice nhỏ. Sau ADR/contract được
duyệt mới thiết kế persistence/index. Media, Video, Knowledge, Proposal và Post
integration nên đi theo phase riêng.

## Relation audit snapshot

Hiện có ít nhất **5 đường persistence/repository relation/usage**: generic
`WpdbRelationManager`, `WpdbMediaRelationRepository`,
`WpdbVideoRelationRepository`, `WpdbMediaUsageRepository` và
`ProjectionRelationReader` adapters; thêm in-memory repositories cho test.
Registry gồm `RelationRegistry`, `KnowledgeRelationTypeRegistry` và
`MediaPredicateRegistry`. Đây là nguy cơ nhiều graph logic song song, không phải
một Semantic Graph duy nhất.

## Media / Video / Proposal / Article kết luận

- **Media:** KEEP identity/asset/readiness contract; REWRITE persistence boundary.
  Không merge dữ liệu trong P1.
- **Video:** KEEP external reference contract; REWRITE storage/relation adapter.
- **Proposal:** KEEP approval, fingerprint, expected revision, eligibility, audit;
  REWRITE engine; RETIRE Proposal Center UI khỏi bootstrap.
- **Article/Projection:** RETIRE Article authority/body và pipeline cho bài mới;
  giữ read/mapping legacy có hạn dùng nếu phase migration chứng minh cần.

## P1 boundary confirmation

P1 chỉ cập nhật tài liệu V3. Không tạo `semantic_edges`, GraphRepository,
relation write engine, Media/Video/Proposal/Authority tables, custom Post semantic
tables, frontend hoặc data migration.
