# NHK V3 Authority Core V1

> **NON-NORMATIVE.** Đây là contract evidence của phase cũ; runtime registry
> và Hiến pháp hiện hành kiểm soát. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Authority entities use an immutable UUIDv7 canonical identity and a scoped stable key `(entity_type, stable_key)`. Names, payload, lifecycle state, and schema version are mutable through the application service; revisions use optimistic locking. Payloads are deterministic JSON and unknown fields are rejected at the boundary. The current canonical catalog contains the nine registered types `brand`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen` and `product`; no `clock_type` is added. Graph references resolve authority UUIDs through a generic registry-backed resolver, so rename, rekey, retire, and reactivate never rewrite graph triples. Rekey is a governed operation: it requires the exact old key and expected revision, atomically changes only the scoped key, preserves UUID/name/payload/state, increments revision once, and fails closed on collision. No public authority mutation endpoint is exposed.

Conversational Authority planning is application orchestration over this owner,
not a second owner. Resolution order is canonical UUID, scoped stable key, exact
canonical name, registered alias, then bounded lexical review. Existing active
identities are reused; retired matches are not silently reactivated and
ambiguous matches remain review/blocker diagnostics. The server-owned stable
key policy creates previews; clients and agents cannot override them.
Classification `family` is persisted payload data and is audited before
hierarchy operations; missing family fails closed rather than being inferred
from a stable-key prefix.

Any Authority entity ingest exposed through MCP is subject to the universal
post-ingest reconciliation in Constitution §20.1. After canonical entity
read-back, the resolver must search for reusable identity, inspect the bounded
semantic neighborhood/Graph, discover only registered relation candidates,
validate evidence/provenance, apply every justified useful relation through
Governance and perform final read-back. Ingest success alone is never
`COMPLETE`; weak/speculative relations are rejected.
