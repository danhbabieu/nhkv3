# P2 Architecture Decisions

> **NON-NORMATIVE.** Đây là decision evidence của phase cũ. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Các quyết định này là baseline đã được phê duyệt cho P2.

## B1–B2. Canonical ID và stable key

Canonical identity mới là UUIDv7 ở domain/API boundary; database surrogate ID
chỉ tối ưu join. UUIDv4 legacy về sau được giữ nguyên, không regenerate.
`stable_key` là alternate durable identifier, cấp một lần khi CREATE và immutable
sau đó. Stable key không phải primary key, không phải Graph endpoint identity và
không được silently regenerate. Alias/legacy key thuộc boundary riêng.

## B3. Specimen và Product

`Specimen` là hiện vật vật lý cụ thể. `Product` là lớp commercial listing/offer
có thể trỏ tới Specimen theo thời gian. Product không định danh hiện vật. P2
không tạo Artifact entity.

## B4. Source, Evidence, Citation

Source là thực thể nguồn; Evidence là đơn vị bằng chứng cho claim/relation;
Citation là representation trình bày. Citation không phải canonical entity mặc
định. P2 chỉ ghi contract, chưa tạo bảng Source/Evidence.

## B5. Media boundary

Media là semantic identity ảnh; MediaAsset là original/derivatives/checksum;
MediaUsage là placement/role. Một Media có một original asset trong normal intake,
nhiều derivatives, nhiều Graph relations và nhiều usages. Asset không phải Graph
endpoint mặc định. Checksum chỉ phát hiện duplicate binary, không auto-merge.

## B6. Article legacy

Article Authority không tồn tại trong runtime V3. Article Ingest là operation-level
contract cho V3 knowledge workflow, không phải entity hoặc body projection.
LegacyArticleReader và mapping chỉ được tạo sau này trong migration/compatibility
boundary, rồi retire khi reconciliation, URL mapping, content hash, rollback
snapshot và observation window đạt yêu cầu. Article Ingest không được gọi
`V2MigrationService.php` hoặc bất kỳ legacy body import path nào.

## B7. Proposal states

Durable states: `DRAFT`, `SUBMITTED`, `APPROVED`, `REJECTED`, `CANCELLED`,
`SUPERSEDED`, `APPLIED`. `READY_TO_APPLY` và `BLOCKED` là derived state. Apply
attempt là record riêng và có `PENDING`, `RUNNING`, `SUCCEEDED`, `FAILED`.
P2 không implement Proposal.

## B8. Graph storage

Graph hot path dùng numeric surrogate node IDs. Domain/API dùng typed
`NodeReference(endpoint_type, endpoint_key)`; edge chỉ lưu numeric node IDs và
predicate ID. Không join hot path bằng UUID string nếu không cần.

## Consequences

- Mọi domain type mới đi qua registry/resolver, không giant switch.
- WordPress Post key là `<blog_id>:<post_id>`, không phải permalink/slug.
- Không tạo domain tables chỉ để làm Graph test.
- Legacy UUID codec vẫn cần round-trip nhưng không ảnh hưởng identity mới.
