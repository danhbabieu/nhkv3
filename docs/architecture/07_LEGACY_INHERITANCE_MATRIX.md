# Legacy Inheritance Matrix — P1

Phạm vi audit: project cũ `/Users/imac24-2125d/Documents/Codex/2026-07-24/nhk-v2-project-reboot-y-l`, đọc source/docs/tests chỉ-đọc. Các dòng dưới đây là **assessment units** (contract hoặc module), không phải lời mời copy code.

| Module / contract cũ | Trạng thái | Hướng xử lý | Lý do | Test cần kế thừa | Migration cần thiết |
|---|---|---|---|---|---|
| Canonical identity / UUID boundary | Contract tốt | KEEP_CONTRACT | Identity ổn định cho Authority | `CanonicalIdentityMatcherTest` | Mapping legacy ID → V3 identity |
| Stable key grammar | Contract tốt | KEEP_CONTRACT | Lookup không phụ thuộc label/slug | `CanonicalIdentityMatcherTest` | Reader mapping bounded |
| Revision / expected revision | Invariant tốt | KEEP_CONTRACT | Chống ghi đè stale state | `ControlledApplyVerificationTest` | Không migrate ở P1 |
| Context hash / snapshot hash | Ý tưởng đúng | REWRITE_IMPLEMENTATION | Coupled với version store cũ | `SemanticMergeGraphFingerprintTest` | Sau khi V3 version model duyệt |
| Idempotency contract | Contract tốt | KEEP_CONTRACT | Replay không duplicate | `BatchIdempotency` | Không migrate receipt |
| Proposal fingerprint / approval binding | Governance tốt | KEEP_CONTRACT | Bind approval với content | `ProposalServiceTest`, `ProposalWorkflowAdminTest` | Không migrate proposal |
| Dependency closure | Invariant tốt | REWRITE_IMPLEMENTATION | Resolve phụ thuộc nhiều adapter | `DependencyAwareBatchApplyServiceTest` | Sau graph contract |
| Controlled Apply | Contract tốt | REWRITE_IMPLEMENTATION | Engine quá lớn, legacy-coupled | `ControlledApplyVerificationTest` | Không migrate apply receipt |
| Entity registry | Ý tưởng đúng | REWRITE_IMPLEMENTATION | Enum và registry đang chồng lấn | `ModuleRegistryTest` | Không mang enum đóng nguyên xi |
| Relation registry | Contract cần giữ | REWRITE_IMPLEMENTATION | Nhiều registry/persistence song song | `MediaPredicateRoundTripContractTest` | Không migrate edges |
| Typed relation validation | Invariant tốt | KEEP_CONTRACT | Ngăn sai endpoint type | `SemanticMergeRelationOperationTest` | Mapping riêng sau review |
| Duplicate edge / reverse query | Yêu cầu cần giữ | REWRITE_IMPLEMENTATION | Query cục bộ, policy khác nhau | `GraphImpactResolverTest` | Không migrate index |
| Relation provenance | Contract cần giữ | REWRITE_IMPLEMENTATION | Metadata rải nhiều bảng | `SourceEntityContractTest` | Chuẩn hóa sau P2 |
| Source provenance | Contract tốt | KEEP_CONTRACT | Truy nguyên claim/media | `SourceEntityContractTest` | Source reader tương lai |
| Knowledge claim model | Contract tốt | REWRITE_IMPLEMENTATION | Tách claim khỏi prose/projection | `KnowledgeCoreTest` | Không migrate ở P1 |
| Knowledge ↔ Post composition | Nguyên tắc cần giữ | REWRITE_IMPLEMENTATION | Legacy trộn Article/projection | `EditorialSystemTest` | Mapping Post ↔ Knowledge |
| Media semantic identity | Contract tốt | REWRITE_IMPLEMENTATION | MediaEntity gom nhiều lớp | `CanonicalMediaManagementContractTest` | Không merge ở P1 |
| Media asset / attachment separation | Contract tốt | KEEP_CONTRACT | Attachment không phải authority | `MediaAssetBindingServiceTest` | Asset reader tương lai |
| Media usage | Contract cần giữ | REWRITE_IMPLEMENTATION | Repository riêng, thiếu graph chung | `MediaReadConsistencyTest` | Không migrate usage |
| Media readiness / visibility | Contract tốt | KEEP_CONTRACT | Public fail-closed | `MediaFoundationV1Test` | Không migrate readiness |
| Media role registry | Contract tốt | KEEP_CONTRACT | Kind/role validate cùng nhau | `MediaRoleContractTest` | Không migrate vocabulary |
| Media duplicate/checksum | Ý tưởng đúng | REWRITE_IMPLEMENTATION | Dedupe chưa cùng boundary | `CanonicalMediaManagementContractTest` | Compatibility reader nếu cần |
| Video external reference | Contract tốt | KEEP_CONTRACT | Không tải MP4/local asset | `VideoSimpleIntakeContractTest` | Legacy reader tạm thời |
| Video repositories/relations | Implementation phân tán | REWRITE_IMPLEMENTATION | Video tách khỏi visual edges | `VideoReadAbilityContractTest` | Map platform + external ID |
| Article authority/body | Mâu thuẫn V3 | RETIRE | Post là source of truth body | `EditorialSystemTest` → Post boundary | Compatibility reader nếu cần |
| Article projection pipeline | Mâu thuẫn V3 | RETIRE | Không Article → Projection → Post | `KnowledgeSingleProjectionTest` → migration test | Mapping legacy projection |
| Public projection eligibility | Contract cần giữ | REWRITE_IMPLEMENTATION | Phụ thuộc Authority projections | `PublicDiscoveryFrontendTest` | Không migrate ở P1 |
| Projection cache/invalidation | Ý tưởng đúng | REWRITE_IMPLEMENTATION | Derived data phải rebuild được | `ProjectionDependencyRebuilderTest` | Rebuild sau P2 |
| Proposal repository/lifecycle | Governance cần giữ | REWRITE_IMPLEMENTATION | State/persistence gắn WPDB cũ | `ProposalServiceTest` | Không migrate proposal |
| Apply eligibility | Contract cần giữ | REWRITE_IMPLEMENTATION | Phụ thuộc nhiều tầng | `ProposalExecutionEligibilityEvaluatorTest` | Không migrate state |
| Admin Proposal Center UI | Presentation legacy | RETIRE | UI không phải governance contract | `ProposalWorkflowAdminTest` chỉ giữ API | Không migrate UI |
| MCP / Abilities read | Contract cần giữ | KEEP_CONTRACT | Least privilege/capability | `AbilityRuntimeContractConsistencyTest` | API mapping sau P2 |
| MCP mutation abilities | Ngoài bootstrap | TEMPORARY_COMPATIBILITY | Chỉ bridge legacy có hạn dùng | `GovernanceAbilitiesDiscoveryTest` | Compatibility adapter |
| Migration runner/versioning | Contract tốt | REWRITE_IMPLEMENTATION | Cũ bắt đầu 004–007 | `MigrationRunnerTest` | V3 bắt đầu version 0 |
| Audit trail / actor | Contract tốt | KEEP_CONTRACT | Truy vết mutation | `AuthorityAuditTest` | Không migrate audit |
| Error model / failure codes | Contract cần giữ | REWRITE_IMPLEMENTATION | Code phát tán theo adapter | `RootCauseVisibilityTest` | Mapping nếu compatibility |
| Legacy import/backfill scripts | Ngoài V3 runtime | TEMPORARY_COMPATIBILITY | Chỉ làm mapping/rehearsal | `MigrationRehearsalScriptTest` | Chưa chạy |
| Semantic merge/cleanup/purge | Ngoài bootstrap | RETIRE | Destructive/legacy-specific | `SemanticMergeRehearsalSafetyTest` | Không migrate |
| SEO collection/frontend/admin | Ngoài P1 | RETIRE | Không làm frontend/projection P1 | `PublicDiscoveryFrontendTest` → P2 | Không migrate |

## Tổng số assessment units

| Trạng thái | Số lượng |
|---|---:|
| KEEP_CONTRACT | 10 |
| REWRITE_IMPLEMENTATION | 18 |
| TEMPORARY_COMPATIBILITY | 3 |
| RETIRE | 8 |
| **Tổng** | **39** |

Các số trên là số dòng đánh giá ở cấp contract/module, không phải số file source.
