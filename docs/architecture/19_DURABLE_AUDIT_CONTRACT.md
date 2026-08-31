# Durable Audit Contract

`wp_nhk_audit_events` is append-only and shared by Graph, Authority, and Governance adapters. Events store identity, operation, state/revision, reason or hash, actor ID, and timestamp; they must never contain credentials, tokens, keys, passwords, or raw secret/content payloads.

The database sink uses a UUIDv7 event identity and bounded event/object fields. Runtime mutation and deletion APIs are intentionally absent. Integration acceptance must query events by `(object_type, object_key)` and verify all three subsystem adapters.
