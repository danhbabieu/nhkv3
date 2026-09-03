# NHK V3 Authority Core V1

> **NON-NORMATIVE.** Đây là contract evidence của phase cũ; runtime registry
> và Hiến pháp hiện hành kiểm soát. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Authority entities use an immutable UUIDv7 canonical identity and a scoped stable key `(entity_type, stable_key)`. Names, payload, lifecycle state, and schema version are mutable through the application service; revisions use optimistic locking. Payloads are deterministic JSON and unknown fields are rejected at the boundary. Brand is the only production entity type in P3. Graph references resolve authority UUIDs through a generic registry-backed resolver, so rename, rekey, retire, and reactivate never rewrite graph triples. Rekey is a governed operation: it requires the exact old key and expected revision, atomically changes only the scoped key, preserves UUID/name/payload/state, increments revision once, and fails closed on collision. No public authority mutation endpoint is exposed.
