# Graph Relation Foundation Design

**Date:** 2026-09-06  
**Status:** Approved in chat; awaiting written-spec review  
**Scope:** NHK V3 Graph, governed relation commands, semantic read-model, MCP read boundary and generic legacy backfill

## 1. Problem and root cause

The current runtime has one canonical Graph store and a working `relation_create`
executor, but the controlled-apply compatibility registry allows that operation
only when `entity_type=relation`. A proposal created with `entity_type=knowledge`
and `operation=relation_create` is therefore rejected by the governance executor
before `GraphService` can validate or create the edge.

The proposal model also has `subjectId`, while the relation command stores its
source and target in payload fields. These fields have different meanings. The
relation endpoint UUIDs must remain in the relation packet and must not be
replaced by an entity type or a generic proposal subject. The observed
`subject_id: knowledge` shape is therefore treated as an identity-boundary bug
until proven otherwise.

## 2. Architectural invariants

- `wp_posts` remains editorial truth; Graph remains the only canonical semantic
  relation store.
- All semantic mutations use Proposal → approval → eligibility → Controlled Apply
  → owning service/repository → canonical readback.
- No raw database writes, legacy-body import, V2/staging/production mutation,
  duplicate Graph store, keyword-derived canonical relation or invented
  Product–Specimen predicate.
- Relation identity is the typed pair of endpoint references plus predicate;
  endpoint UUIDs are validated against registered canonical resolvers before
  persistence.
- Existing UUIDs, stable keys, valid relations, Video semantic attachments,
  Media identity/assets/usages and Article bodies are preserved.

## 3. Selected architecture

### 3.1 Canonical relation command and registry

Use the existing `PredicateRegistry`, `PredicateDefinition`,
`EndpointTypeRegistry`, `GraphService` and Graph repository. Extend the runtime
contract only where needed so each relation command has:

```text
source_type, source_uuid, predicate, target_type, target_uuid
```

The implementation may accept the existing `source_key/target_key` names as a
compatibility input when their values are canonical UUIDs. Internally and in
read-back, the canonical endpoint identity is explicit and never inferred from
`Proposal.subjectId`.

The controlled-apply compatibility policy becomes operation-oriented: relation
create/retire/reactivate is available to the approved semantic command boundary
for Knowledge, Article, Video and Media, while endpoint and predicate validation
remains generic and fail-closed. The final policy must preserve the existing
relation lifecycle and must not broaden endpoint pairs merely because an entity
type is allowed to issue a command.

The predicate matrix initially retains the executable vocabulary:

`about`, `depicts`, `model_of`, `variant_of`, `uses_movement`,
`supports_music`, `configured_with_music`, `observed_playing_music`.

`classified_as` is added only for `model → classification`,
`variant → classification` and `specimen → classification` after reconciling
the current Authority relationship contract. It is never implemented as a
WordPress taxonomy or as an `about` substitute. Product–Specimen remains an
explicit `REGISTRY_GAP`.

### 3.2 Subject UUID preservation

Proposal construction, persistence, serialization, eligibility and apply must
preserve the relation packet unchanged. The serializer will expose the actual
source and target endpoint fields, while `subject_id` remains the proposal's
semantic subject field. Apply must resolve both endpoint references through the
registered resolver and abort before Graph mutation when either UUID is missing,
malformed, unavailable or resolves to the wrong type.

Idempotency fingerprints include operation, source endpoint identity, predicate,
target endpoint identity, expected revision, dependencies and payload. Retried
identical commands return the existing proposal/edge; a changed packet under an
existing idempotency key fails closed. A retired matching edge requires explicit
reactivation and is never silently resurrected by create.

### 3.3 Graph reads and semantic neighborhood

`GraphService` remains the application boundary for direct outbound and inbound
reads. Add or complete reusable query seams for predicate/type filters, bounded
cursor pagination, stable ordering, deduplication and inactive-by-default
behavior. The repository must perform bounded endpoint queries rather than
per-target lookup loops.

Reuse `RelatedSemanticQuery` and its registry-driven traversal policy for a
profile-based neighborhood read-model. Profiles define allowed predicates,
directions, endpoint types and maximum hops for Brand, Model, Variant,
Classification and Specimen (and can be extended for other registered types).
Direct relations are reported separately from derived traversal. Traversal is
bounded and deduplicated; it never invents inverse predicates or persists a
derived edge. Source/Evidence citation remains an independent Knowledge model,
not a replacement for a Graph `about` relation.

Frontend projections consume this read-model and render honest empty/error
states. MCP receives a read-only relation/neighborhood capability only if the
current executable catalog and transport contract can expose it; no client is
given raw database access and no write capability bypasses Governance.

## 4. Generic backfill

Introduce a reusable backfill application service with two modes:

- **dry-run:** enumerate canonical records, discover only deterministic or
  explicitly governed candidates, classify each as deterministic, ambiguous,
  unsupported, evidence-gap or registry-gap, and emit a report;
- **apply:** create governed relation proposals, apply only approved eligible
  candidates, then verify canonical Graph readback and inverse retrieval.

Resolution precedence is explicit UUID metadata, stable-key convention, explicit
relation hints, Article/Video `intended_relations`, structured identity payload,
exact normalized identifiers and finally manually reviewed mappings. General
keyword matches are never canonical resolution. Ambiguous rows retain record
UUID, stable key, record type, candidate endpoints, predicate, reason,
confidence and required owner action.

The report includes:

`TOTAL_RECORDS_SCANNED`, `RELATIONS_EXISTING`, `RELATIONS_PROPOSED`,
`RELATIONS_CREATED`, `RELATIONS_SKIPPED_IDEMPOTENT`, `AMBIGUOUS`,
`UNSUPPORTED_ENDPOINT`, `EVIDENCE_GAP`, `REGISTRY_GAP`, `ERRORS` and
`ZERO_CHANGE_ON_SECOND_RUN`.

The first governed fixtures are the existing Cuckoo Classification and the
existing Odo 36/8 Variant plus the three named Knowledge claims. No Knowledge,
Component or Authority record is recreated. Development apply is authorized
only after tests and dry-run pass; V2, staging and production remain out of
scope.

## 5. Data flow

```text
resolve canonical endpoints
  → build typed relation packet
  → create Proposal
  → submit/review/approve/eligibility
  → Controlled Apply
  → GraphService → canonical Graph repository
  → edge readback + inverse retrieval
  → semantic neighborhood/frontend or MCP projection
```

Knowledge completion is not claimed from canonical claim ingest alone. A claim
without a verified semantic subject relation is `RELATION_PENDING`; an
ambiguous candidate is queued for human review. Evidence and provenance remain
required according to the Knowledge contract.

## 6. Verification and acceptance gates

Tests cover the registry endpoint matrix, all approved Knowledge `about` target
types, invalid source/target/predicate/UUID cases, UUID preservation through
proposal lifecycle, idempotency/retry, retire/reactivate, outbound/inbound
queries, filters, inactive exclusion, bounded neighborhood and deduplication,
backfill dry-run/apply/second-run behavior, ambiguity preservation,
Source/Evidence isolation and Article/Video/Media compatibility.

Runtime acceptance requires:

1. Knowledge `relation_create` reaches Controlled Apply and creates a typed edge.
2. Cuckoo Classification inverse read returns its governed Knowledge relations.
3. Odo 36/8 inverse read returns all three required Knowledge relations.
4. Backfill second run reports zero changes and no duplicate edge.
5. Frontend/MCP results are Graph/read-model results, not keyword-search claims.

Before claiming completion, run project lint/PHP lint, unit tests, available
guarded integration tests, Graph tests, migration/backfill dry-run, fixture
readback, `git diff --check` and secret review. If the development runtime is
unavailable, report the exact `BLOCKED` owner action and do not claim live apply.

## 7. Documentation updates

Update the canonical Graph architecture/relationship contract, Authority
relationship matrix, Knowledge and Governance/Controlled Apply contracts, MCP
content operations, semantic discovery/read-model contract, frontend projection
contract, migration/backfill runbook, `CURRENT_DOCUMENTATION_STATUS_INDEX.md`
and `V3_EXECUTION_STATE.md`. The relationship formula ledger records direction,
inverse query, cardinality, canonical-versus-derived status, frontend usage,
runtime status and evidence/governance rule for each predicate.

## 8. Out of scope and stop conditions

No Product–Specimen predicate is invented, no Article body is rewritten, no
legacy article body is parsed or imported, and no semantic record is merged,
deleted, seeded or repaired outside the governed development apply explicitly
authorized for this task. Stop for a constitutional conflict, severe identity
ambiguity, missing required contract/registry support or unavailable required
credentials/infrastructure.
