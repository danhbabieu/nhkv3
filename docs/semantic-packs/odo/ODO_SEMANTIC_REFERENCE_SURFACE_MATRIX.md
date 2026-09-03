# Odo Semantic Reference Surface Matrix

**Date:** 2026-09-03
**Status:** `CODE_OBSERVED`; runtime values remain `RUNTIME_UNVERIFIED` until
the existing read-only inventory can complete. This matrix is an ownership
decision, not authorization to mutate Odo data.

| DOMAIN | REFERENCE_FORM | READ_REPOSITORY | WRITE_BOUNDARY | MERGE_ADAPTER_REQUIRED | VERIFY_METHOD | TRANSACTION_BOUNDARY | STATUS |
|---|---|---|---|---|---|---|---|
| Graph inbound | Active Graph edge target endpoint `(entity_type, stable_key)` | `GraphRepository::incoming` through `GraphService` | Governance `relation_create`/`relation_retire` via `ControlledApplyService` | YES — `SemanticMergeGraphAdapter` | Old edge absent; target triple present/read back | Same `wpdb` transaction as merge | IMPLEMENTED / runtime-unverified |
| Graph outbound | Active Graph edge source endpoint `(entity_type, stable_key)` | `GraphRepository::outgoing` through `GraphService` | Governance Graph service | YES — same adapter | Old edge absent; target triple present/read back | Same `wpdb` transaction as merge | IMPLEMENTED / runtime-unverified |
| Knowledge | `KnowledgeClaim` has its own UUID; no Authority UUID field | `KnowledgeRepository` | Knowledge governed create/update/lifecycle | NO — no direct Authority reference; Graph is authoritative if linked | Graph read-back where an association exists | N/A; Graph movement owns association | NOT_APPLICABLE |
| Source | `Source` has its own UUID/stable key; no Authority UUID field | `SourceRepository` | Source governed create/update/lifecycle | NO — no direct Authority reference; Graph is authoritative if linked | Graph read-back where an association exists | N/A; Graph movement owns association | NOT_APPLICABLE |
| Evidence | `claim_uuid` + `source_uuid`; neither is Authority UUID | `EvidenceRepository` | Evidence governed create/update/lifecycle | NO — Evidence ownership is Claim/Source, not Authority | `EvidenceRepository` read-back; Graph read-back for semantic association | N/A; no Authority move | NOT_APPLICABLE |
| MediaUsage | `endpoint_type` + `endpoint_key` placement context; not semantic Graph truth | `MediaUsageRepository` | Media application service; usage is placement/context | NO — no Authority UUID and must not become Graph truth | Usage read-back; Graph separately | N/A; no Authority movement | NOT_APPLICABLE |
| Video | Own Video UUID; optional `thumbnail_media_uuid` points to Media | `VideoRepository` | Video application service | NO — thumbnail is Media reference, not Authority reference | Video read-back + Media read-back; Graph separately | N/A; no Authority movement | NOT_APPLICABLE |
| wp_post semantic references | `wp_post` Graph endpoint; editorial post itself remains WordPress-owned | `GraphRepository`/`WpPostEndpointResolver`, Article verification reader | Graph Governance only; WordPress title/body/slug/revision are read-only dependencies | NO separate adapter — covered by Graph adapter | Graph read-back plus Article fingerprint read-back; no editorial delta | Same Graph transaction; editorial state is not rewritten | GRAPH_ONLY |
| Registered semantic reference repositories | Only a repository with an explicit Authority UUID/reference contract qualifies | Current contracts audited above | Owning application service under Governance | Add only after a concrete contract is registered | Owner read-back and zero dangling references | Must join merge transaction or block | NO_OTHER_SURFACE_FOUND |

Rules: no direct SQL semantic rewrite, no raw JSON replacement, no endpoint-key
guessing, and no duplicate movement mechanism. An unavailable or malformed
surface blocks source lifecycle completion; `NOT_APPLICABLE` means the surface
was inspected and does not own an Authority reference, not that its records may
be ignored.
