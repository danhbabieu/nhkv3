# VIDEO BACKLOG RECOVERY

## Kết luận điều hành

Audit V3-1309 đã hoàn tất ở các runtime được phép đọc. Database phát triển
`nhk_v3` đã lên schema 20/20 nhưng không có canonical Video/Capture/Video
Proposal để chạy recovery. Demo/staging `demo.1945.vn` được Constitution quy
định là read-only reference, nên không Apply, reconcile, sửa Public Identity,
tạo route, enrich nội dung hoặc publish dữ liệu trên đó.

Vì vậy không có semantic recovery hoặc canonical republish nào được thực hiện.
Thay đổi runtime duy nhất là migration UP đưa schema từ 13 lên 20/20; không có
semantic row nào được ghi.

## Phạm vi và nguyên tắc

- Đã bootstrap canonical documentation/runtime trước inventory.
- Đã đọc Constitution và các contract Video, relation, YouTube source, SEO,
  Public Identity và MCP Video workflow.
- Đã kiểm tra `nhk_v3` bằng canonical preflight, migration status, canonical
  inventory và read-only database counts.
- Đã kiểm tra `nhk_v3_test` read-only; không chạy destructive integration
  operation.
- Đã quan sát public archive và governance queue trên demo bằng read-only UI.
- Không dùng direct `nhk.video.ingest`, generic writer, direct SQL write,
  manual slug/Public Identity, repair Proposal APPLIED, duplicate creation hay
  Article publication.

## Inventory theo runtime

| Runtime | Videos | Captures | Proposals | Video proposals | Public Identities | Quyền xử lý |
|---|---:|---:|---:|---:|---:|---|
| `nhk_v3` | 0 | 0 | 23 | 0 | 0 | canonical development |
| `nhk_v3_test` | 0 | 5 | 10 | chưa có Video owner | 0 | integration; không mutation |
| `demo.1945.vn` | không có full DB inventory | không đọc đủ | 29 hàng queue | 29 | không read-back đủ | read-only reference |

Trong `nhk_v3`, các Proposal hiện có chỉ thuộc `brand` và `knowledge`
relation/brand operations; không có Proposal `entity_type=video`. Các bảng
tham chiếu hiện có nhưng không phải backlog Video: 373 entities, 243 Graph
edges, 19 Sources, 655 Knowledge claims và 40 Evidence.

## Phân loại

| Nhóm | Số lượng | Kết quả |
|---|---:|---|
| A. `COMPLETE_VERIFIED` | 1 reference | Video số 372; golden control đã cho và đã read-only xác nhận detail route + collection |
| B. `RECOVERABLE_APPROVED` | 0 trong `nhk_v3` | Không có target canonical được phép Apply |
| C. `RECOVERABLE_APPLIED_INCOMPLETE` | 0 trong `nhk_v3` | Không có local Video owner để reconciliation |
| D. `PROVENANCE_INCOMPLETE` | 0 trong `nhk_v3` | Không có local candidate |
| E. `SEMANTIC_ATTACHMENT_INCOMPLETE` | 0 trong `nhk_v3` | Không có local candidate |
| F. `PUBLIC_IDENTITY_INCOMPLETE` | 0 trong `nhk_v3` | Local Public Identity count là 0 |
| G. `FRONTEND_INCOMPLETE` | 0 trong `nhk_v3` | Không có local candidate |
| H. `DATA_CONFLICT` | 27 demo queue rows | `Dữ liệu bị chặn`; Proposal Video không đạt điều kiện đọc an toàn |

## Candidate read-back

### Golden control — A / COMPLETE_VERIFIED

| Field | Value |
|---|---|
| External ID | `iqbGOL967t4` |
| Capture | `01a094df-6e43-7226-a3a5-78a6c4c05c7e` |
| Proposal | `01a094df-7198-7c74-b599-3074c604116e` |
| Canonical Video | `01a094df-6ff2-7872-9bbe-ca4e843a68ef` |
| Semantic subject | Variant `852da54d-457a-4397-a16d-52d9452ba766`, `nhk:variant:odo.36.8` |
| Source / Claim / Evidence | Golden control xác nhận; warning duy nhất `TRANSCRIPT_UNAVAILABLE` |
| Attachment / Graph | Video → `about` → Variant, ACTIVE |
| Completeness | COMPLETE |
| Public Identity / route | `/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/` |
| Frontend | Detail resolves; `/video/` collection contains item; user status `VERIFIED` |
| Article Post | 454, `draft`; không publish |
| Content enrichment | Specimen facts chỉ áp dụng cho chiếc số 372; không có enrichment mutation trong audit |

### Public-only reference — cần canonical inventory ở target được phép

| Field | Value |
|---|---|
| External ID | `truOChTNbwA` |
| Public route | `/video/kham-pha-gai-carillon-ban-nhac-lam-nen-ten-tuoi-cua-odo-36-10-cung-nha-kho/` |
| Public read-back | Detail route resolves; collection contains item; YouTube source/provenance hiển thị |
| Visible context | Odo 36/10; public page hiển thị Variant UUID `95873bfe-d978-4eda-a5a2-ce9ba79625df` |
| Capture / Proposal / Canonical Video / Public Identity | Không đọc được authoritative binding từ public UI |
| Classification | Handoff blocked as `CANONICAL_BINDING_UNREADABLE`; không suy đoán hoặc tạo lại |
| Next action | Cấp canonical target runtime/export có kiểm soát để read-back Capture/Proposal/Graph/Public Identity |

### Applied-but-not-verified reference trên demo

Governance queue read-only hiển thị một Proposal Video khác đã `Đã áp dụng`,
canonical `01a094a1-3824-7bba-9e3f-9b9fcaf20755`, với resource completion
“Chưa đọc lại / Chưa kiểm tra / Chưa kiểm tra”. Queue không đủ read-back để
chứng minh Evidence, Graph, Public Identity và frontend VERIFIED. Đây là
`C. RECOVERABLE_APPLIED_INCOMPLETE` trên reference surface, nhưng fail-closed vì
demo/staging không phải target mutation. Không repair/re-apply Proposal immutable.

## Demo queue blocked records

27 hàng Proposal Video hiển thị `Dữ liệu bị chặn` và summary `Bản ghi Proposal
không đạt điều kiện đọc an toàn.`. Các UUID:

`01a09131-5da8-7f80-aa57-e23f8278eefa`,
`01a0675e-d02e-7bda-a931-464ad7eaf5c8`,
`01a066b6-853e-740a-9360-4ba5e81d5d1f`,
`01a066b0-3414-7c61-bd1a-0a5e07a37686`,
`01a08c68-ab9d-70ca-8a4b-4411779b56b6`,
`01a07af3-517f-7315-a772-2441b7029c1e`,
`01a0796f-ebe8-7592-8475-c0d482ba3efe`,
`01a0796e-c75f-72b3-99bb-753b2850c1b1`,
`01a06802-adca-7d15-aaa4-e93b1bc2ea0d`,
`01a0677b-171f-7e35-a0c8-7c7615fd2d44`,
`01a06b7d-7f70-7af6-995e-d4039723c027`,
`01a08c4b-d01a-7bb7-9458-8cbf1209e784`,
`01a08c41-3f26-746a-891c-b38ed1865e8a`,
`01a08c2a-7a5b-7881-aa3f-7a7288e1670f`,
`01a08bfa-6cd4-736b-be4b-258bbf2d6616`,
`01a07af5-335f-7e83-a3aa-7f805ef9fb7c`,
`01a07a27-b4aa-7696-bd20-381cadbd00dc`,
`01a07971-3003-732b-9842-04c51a2669af`,
`01a077ef-ece0-7179-b2e5-79ca3310dd87`,
`01a072e9-45cd-73a0-8a1a-4a41752e3a76`,
`01a06b7d-ce19-788f-b166-124ef4bbd9e0`,
`01a06815-1e63-73c6-8ecc-77d14c583de6`,
`01a06696-cd27-7f4a-b497-79d564de04da`,
`01a06690-d58a-79bb-a8db-2c6e6636b9e0`,
`01a0666c-2ebf-7e65-b3fe-c14fa383a97c`,
`01a06659-d156-71aa-8d7b-a5b36a5a7f5f`,
`01a065d5-a7e0-7092-a798-2decd42213b5`.

Exact next action for each: obtain authoritative Capture/source/external
identity and Proposal read-back in the permitted target runtime, then use the
registered Capture workflow. Do not repair these rows in place.

## Recovery and enrichment result

- Approved-never-applied recovery: **0 executed**.
- Applied-proposal reconciliation: **0 executed**.
- Source → provenance Claim → Evidence: **0 created**.
- Semantic attachment / governed Graph reconciliation: **0 executed**.
- Completeness recompute: **0 executed against Video owners**.
- Public Identity/public completion reconciliation: **0 executed**.
- Content enrichment: **0 mutations**; no specimen/context facts were invented.
- Public Article publication: **0**.

## Final report

- Total Video/Capture candidates in authorized canonical development runtime: **0**.
- Already COMPLETE + VERIFIED: **1 reference control** (Video số 372).
- Recovered successfully: **0**.
- Still blocked: **27 invalid demo Proposal rows**, plus **1 public-only
  canonical binding requiring target-runtime inventory**, plus **1 applied-but-
  unverified demo reference**.
- Duplicate Capture created: **0**.
- Duplicate Video created: **0**.
- Duplicate semantic entities created: **0**.
- Articles auto-published: **0**.
- Frontend VERIFIED count: **1 user-certified control**; public demo collection
  visibly contains **2** items, but the second is not counted as canonical
  VERIFIED without authoritative read-back.
- Remaining incomplete count in `nhk_v3`: **0 candidates**; remote/reference
  backlog remains unresolved and must not be mutated from this workspace.

### Required next action

Provide or authorize an isolated canonical runtime/data export containing the
historical Video/Capture/Proposal records (not V2, production, staging or demo),
with its documentation checkpoint and governed write endpoint. Then rerun this
same report against that target. Until then, Apply/reconcile/create Public
Identity/republish would violate fail-closed and workspace-scope rules.

## Documentation checkpoint

- `documentation_version`: `9777115d574b424daf70bff959d8f159575c0e2e0469fe674cf5335868fbcd54`
- `manifest_hash`: `1ac77fe4cef324610e79920df7ef99e76c864968d7305a936d00989d77cf26cc`
- `build_identity`: `504b93f4b04d30d6aa057fd222f9e779c6f6f3a03f9cc1c8d08c5d0c4958770b`
- `runtime_version`: `0.1.0`
- `generated_at`: `2026-09-12T09:27:16+00:00`
- Schema: development `nhk_v3` 20/20 after UP-only migration.
- Preflight: 11/11 PASS.

## Verification gates

- Unit suite: PASS, 1,203 tests / 5,918 assertions; 13 warnings and 11
  deprecations.
- PHP lint: PASS.
- `git diff --check`: PASS.
- Full `composer test`: **not passable in this environment**; 35 integration
  errors and 15 environment-gated failures occur before WordPress integration
  bootstrap (`update_option()`/wpdb test context unavailable and
  `NHK_WP_TEST_PATH=public` not configured). No integration result is claimed.
- Changed-scope secret review: no credential or private-key material found.
