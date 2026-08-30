# Graph Core V1 Contract

## Boundary

Graph Core là Semantic Graph duy nhất dùng chung cho Post và các endpoint tương
lai. Domain không phụ thuộc WordPress hoặc `$wpdb`; Infrastructure chứa WPDB
adapter. P2 không expose REST/MCP mutation và không tạo Media/Video/Authority/
Knowledge/Proposal tables.

## Types and registries

`NodeReference` gồm `endpoint_type` và `endpoint_key`. `EndpointTypeRegistry`
đăng ký resolver với `supports()`, `exists()` và `normalize()`. Production P2 có
resolver `wp_post`; key là `<blog_id>:<post_id>`, post draft vẫn tồn tại hợp lệ,
trash không bị Graph tự xóa. Các type còn lại dùng contract/fake resolver.

`PredicateRegistry` seed tối thiểu `about` và `depicts`. Predicate có source/target
allow-list, outbound/inbound cardinality (`ONE`/`MANY`), self-relation và active.
Không cho nhập predicate tùy ý và không lưu rule mutable JSON trong DB.

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
