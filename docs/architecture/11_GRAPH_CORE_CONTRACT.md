# Graph Core V1 Contract

> **NON-NORMATIVE.** This is implementation contract evidence. If it conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Boundary

Graph Core là Semantic Graph duy nhất dùng chung cho Post và các endpoint tương
lai. Domain không phụ thuộc WordPress hoặc `$wpdb`; Infrastructure chứa WPDB
adapter. P2 không expose REST/MCP mutation và không tạo Media/Video/Authority/
Knowledge/Proposal tables.

Raw Graph REST reads are an administrator-only operational surface because they
return endpoint keys, edge state and revisions. Public post/entity surfaces use
`RelatedContentQuery`, which resolves active records into reader-facing titles and
URLs and omits unavailable groups. This keeps the single Graph relation system
without exposing its storage identifiers as public content API.

Article Ingest is an operation-level coordination boundary, not a Graph endpoint.
It may reference a registered `wp_post` and registered semantic endpoints only;
there is no `article` endpoint and no Article semantic identity or body in Graph.

## Single entry point for new relation intent — 2026-09-09

New relation intent arrives through `nhk.capture.ingest`, where Graph is used
for bounded discovery and candidate retrieval before semantic write-back. A
direct relation/proposal/admin relation operation is internal/admin lifecycle
compatibility only, requires `nhk_internal_content_operations` at its exposed
boundary and cannot be used as a normal submission path or as a partial
replacement for Capture.

## Types and registries

`NodeReference` gồm `endpoint_type` và `endpoint_key`. `EndpointTypeRegistry`
đăng ký resolver với `supports()`, `exists()` và `normalize()`. Production P2 có
resolver `wp_post`; key là `<blog_id>:<post_id>`, post draft vẫn tồn tại hợp lệ,
trash không bị Graph tự xóa. Các type còn lại dùng contract/fake resolver.

Các canonical resolver cần tham gia governed `relation_create` phải triển khai
thêm `EndpointRevisionReader::revision()` để trả revision hiện tại; proposal
binder đọc revision của cả source và target tại thời điểm tạo. Nếu revision
không đọc được thì fail-closed, tuyệt đối không mặc định `1`.

`PredicateRegistry` hiện đăng ký `about`, `depicts`, các quan hệ cấu trúc hiện
hành và hai quan hệ Authority conversational đã được phê duyệt là
`subtype_of` và `classified_as`. Predicate có source/target allow-list,
outbound/inbound cardinality (`ONE`/`MANY`), self-relation và active.
Không cho nhập predicate tùy ý và không lưu rule mutable JSON trong DB.

### Conversational Authority hierarchy and classification membership

`subtype_of` là Graph-owned, governed, revision-bound và canonical-read-back
relation từ Classification tới Classification, outbound ONE/inbound MANY.
Endpoints phải ACTIVE và có `family` persisted; family thiếu hoặc không tương
thích, self-relation và mọi cycle đều fail closed. `classified_as` là governed
membership từ Model, Variant, Specimen hoặc Product tới Classification, MANY →
MANY. Brand và Movement không được dùng predicate này; Movement cần contract
extension riêng.

`subtype_of` chỉ biểu diễn subtype thật trong cùng family. Origin, geography,
case style hoặc feature là facet/filter hoặc component khi registry/evidence
chứng minh như vậy; không dùng `about` hay combined stable key để mô phỏng cây.
Legacy Classification rows phải qua inventory audit và explicit idempotent
backfill plan trước hierarchy apply; không dùng stable-key prefix làm semantic
truth duy nhất. Nếu family không xác định, apply trả
`CLASSIFICATION_FAMILY_UNRESOLVED` trước khi Graph mutation.

Post-to-Knowledge links remain Graph relations and must be applied through
Governance/Controlled Apply when part of Article Ingest. A direct mutation path
outside that boundary is a `CONSTITUTION_CONFLICT` to be audited and closed.

## Storage migration 001

### `{$wpdb->prefix}nhk_graph_nodes`

`id BIGINT UNSIGNED AUTO_INCREMENT`, `endpoint_type VARCHAR(64)`,
`endpoint_key VARCHAR(191)`, `created_at DATETIME(6)`. Unique
`(endpoint_type, endpoint_key)` và index `(endpoint_type,id)`.

### `{$wpdb->prefix}nhk_graph_predicates`

`id SMALLINT UNSIGNED AUTO_INCREMENT`, `predicate_key VARCHAR(64) UNIQUE`,
`created_at DATETIME(6)`. Đây chỉ là numeric dictionary; rules nằm trong code.

### `{$wpdb->prefix}nhk_graph_edges`

`id BIGINT UNSIGNED AUTO_INCREMENT`, `edge_uuid BINARY(16) UNIQUE`,
`source_node_id`, `predicate_id`, `target_node_id`, `state` (1 ACTIVE/0 RETIRED),
`revision`, timestamps và `retired_at`. Unique triple
`(source_node_id,predicate_id,target_node_id)`. Composite indexes:

- `(source_node_id,predicate_id,state,target_node_id)`
- `(target_node_id,predicate_id,state,source_node_id)`

Không có foreign-key cascade; không lưu UUID/string endpoint, JSON lớn, body,
Media metadata hoặc Evidence payload trong edge table. Graph node không được hard
delete khi còn edge.

## Mutation contract

Create normalize/validate endpoint, xác nhận existence, validate predicate/type/
cardinality rồi resolve node và insert edge. Exact ACTIVE triple trả edge cũ,
không tăng revision. Triple RETIRED không tự resurrect; chỉ explicit reactivate.
Cardinality violation fail rõ ràng, không auto-retire edge cũ.

Retire giữ row, set state/retired_at và tăng revision. Reactivate validate lại,
xóa retired_at và tăng revision. expected revision mismatch ném typed
`RelationRevisionConflict`.

Các mutation transaction-safe. InnoDB dùng unique constraints làm safety net;
transaction kết hợp `FOR UPDATE` trên exact edge và các active range theo
source/target/predicate để serialize cardinality check + insert. Không dùng
distributed lock.

## Query and pagination

Repository có `findOutgoing`, `findIncoming`, `findEdge`, `findByEdgeUuid`.
Mặc định chỉ ACTIVE; include RETIRED phải explicit. Cursor là `id > last_id`,
sort `id ASC`, limit mặc định 50 và hard maximum 200; query lấy thêm một dòng để
trả `next_cursor`. Forward/reverse query dùng đúng composite indexes ở trên,
không OFFSET làm contract duy nhất.

## UUID and audit/evidence

Edge UUID dùng UUIDv7, domain/API là canonical string, DB là BINARY(16). Codec
duy nhất nằm ở `Shared/Uuid/UuidCodec`, hỗ trợ cả UUIDv7 mới và UUIDv4 legacy
round-trip. Relation mutation phụ thuộc `AuditSink` và phát event
`RelationCreated`, `RelationRetired`, `RelationReactivated`; P2 chỉ có
`InMemoryAuditSink`, chưa giả vờ có durable audit. Evidence/provenance là boundary
riêng tương lai, không nhét JSON vào edge.

## Query-plan reasoning

Forward predicate query lọc `source_node_id`, `predicate_id`, `state` và order/id
được cover bởi `source_lookup`; reverse query tương ứng được cover bởi
`target_lookup`. Predicate không truyền vào vẫn dùng leftmost prefix
`source_node_id`/`target_node_id`. `EXPLAIN` trên migration thực tế phải cho
`possible_keys=source_lookup` hoặc `target_lookup`, không full table scan trên
đường query chính; kiểm tra runtime được ghi trong P2 acceptance output.

## Current public projection boundary — 2026-09-07

Graph remains the single relation system. The public read chain is
`Authority → Graph → canonical projection/read model → frontend`; Admin and
frontend adapters must not create a parallel relation store or infer facts from
WordPress post meta, attachment IDs, external URLs or fixture payloads.

Relations render only after the existing Graph/public eligibility policy passes.
An eligible public-safe knowledge projection does not make a relation public,
and Source/Evidence PRIVATE data must not leak through relation payloads.
Semantic relation changes still require the complete Governance lifecycle and
canonical read-back.

`relation_create` bind `source_revision` và `target_revision` vào payload của
proposal. Eligibility đối chiếu cả hai giá trị với canonical readers hiện tại
và trả `TARGET_REVISION_CHANGED` nếu một endpoint đã đổi sau khi bind.
# Claim projection traversal boundary — 2026-09-08

The Graph remains the only relation store. Claim projection uses the central
`GraphProjectionPolicy` and a maximum of two governed hops; a stored relation
alone is not permission to propagate a claim. Unsupported predicates,
unregistered inverses, inactive/dangling edges and ambiguous endpoints fail
closed. Related claims retain the original semantic subject and an explainable
path/context; no shortcut edge is persisted and the frontend never traverses
Graph directly.

## Editorial Capture Claim-discovery boundary — 2026-09-09

Editorial Capture may use the same bounded neighborhood/query layer to discover
related canonical nodes and candidate Claims. The Graph output is path/context
only: endpoint identity, relation direction/predicate and bounded hop path. It
must not decide Claim truth, Evidence sufficiency, semantic scope or editorial
relevance, and it must not rewrite the Claim subject to the Capture subject.

A reachable Claim is handed back to the Knowledge/Claim retrieval boundary with
its original canonical subject. That boundary must verify Claim identity and
revision, scope, provenance, Evidence state and relevance before the Claim can be
selected for Article synthesis. A relation path is therefore necessary context
for explainability, not authorization for inherited truth. Incompatible scope,
unsupported Evidence/provenance or irrelevant Claims remain excluded/review and
must not be promoted by adding a shortcut edge.

The Article/Capture layer may retain the selected relation path in a body-free
`claim_trace` or research snapshot for later reconciliation. Graph stores no
Article body, Claim copy, generated prose, Media caption/OCR payload or Capture
receipt. Generated editorial copy and Media observations remain outside Graph
and do not become Evidence or relation truth merely because they were reached
through a neighborhood query.

### Visual support boundary — 2026-09-11

VisualSupportRequirement is not a Graph predicate or edge. It is an indexed
application ledger keyed by the already-resolved subject/scope/facet/feature;
its consumer invalidation uses the existing Projection Dependency Index. A
MediaUsage binding says only that a Media is suitable to illustrate that
feature in context. It does not assert `depicts`, `about`, Evidence or Claim,
and it cannot broaden a specimen-scoped observation to a parent node.
