# NHK V3 Brand Relationship Matrix

Status: approved architectural requirements with the six definitions now
registered in code, 2026-09-02. This is a contract matrix, not a data-repair
approval. No row in this document authorizes a Graph edge to be created.

| Predicate / fact | Source → target | Cardinality | Fact scope | Direct or derived | Evidence requirement | Governance behavior | Public-query implication | Status |
|---|---|---|---|---|---|---|---|---|
| `model_of` | Model → Brand | Model outbound exactly 1 active canonical Brand; Brand inbound 0..N Models | Structural product-line parentage | Direct persisted child→parent edge | Canonical UUID/stable key, active endpoints, valid registered types, provenance where the relation operation requires it | Relation proposal must pass endpoint, active predicate, existence, self-edge, cardinality, revision, idempotency and audit checks; a second active parent blocks and never silently retires the first | A Model can be publicly complete only when the transition compatibility rule or later canonical Graph rule supplies exactly one safe Brand parent | REGISTERED IN `PredicateRegistry`; no physical edge backfill |
| `variant_of` | Variant → Model | Variant outbound exactly 1 active canonical Model; Model inbound 0..N Variants | Structural product-line parentage | Direct persisted child→parent edge | Canonical UUID/stable key, active endpoints, valid registered types, provenance where the relation operation requires it | Same governed relation and cardinality rules as `model_of`; multiple active parents are `STRUCTURAL_PARENT_AMBIGUOUS` | A Variant detail requires one safe Model and a complete Brand chain; no global Variant hub is required | REGISTERED IN `PredicateRegistry`; no physical edge backfill |
| Brand context for Variant | Variant → Model → Brand | Exactly one derived Brand context when both direct edges resolve uniquely | Navigation/read-model context, not ownership storage | Derived from `variant_of` then `model_of` | Both direct edges must be active, canonical and unambiguous | No separate proposal or persisted shortcut edge | Cards, breadcrumbs and Brand aggregation may explain the path as `DERIVED`; absence blocks structural completeness | APPROVED; never persist `Variant → Brand` shortcut |
| `uses_movement` | Variant → Movement | Reusable Movement may be referenced by 0..N Variants; one Variant may reference 0..N documented Movements unless a future registered definition narrows it | Variant configuration/documented use | Direct when registered and evidenced | Variant and Movement canonical identities plus evidence of documented/configured use | Govern through the existing Graph/Governance boundary; no free-form relation or direct backfill | Brand aggregation may show Movement through a valid Variant path and must label the path; Movement does not acquire Brand ownership | REGISTERED IN `PredicateRegistry`; no physical edge backfill |
| `supports_music` | Movement → Music | Reusable Movement may support 0..N Music programs; Music may be supported by 0..N Movements | Movement-scope technical/documentary capability | Direct when registered and evidenced | Evidence must support Movement capability; rod/hammer counts, Brand, case style and visual similarity are insufficient | Governed relation proposal with provenance/evidence and optimistic revision; no automatic inference from other facts | Brand aggregation may derive Music through a valid `uses_movement` path; it must not imply every Variant or Specimen plays it | REGISTERED IN `PredicateRegistry`; no physical edge backfill |
| `configured_with_music` | Variant → Music | Variant may have 0..N documented Music configurations; Music may apply to 0..N Variants | Variant-scope configuration/offering | Direct when registered and evidenced | Evidence must document the Variant configuration; Movement capability alone is not enough | Governed proposal with evidence, revision and idempotency; no automatic promotion from Movement or Specimen facts | Variant page may show the direct fact; Brand aggregation may include it with a `DIRECT` or explicit derived path label | REGISTERED IN `PredicateRegistry`; no physical edge backfill |
| `observed_playing_music` | Specimen → Music | Specimen may have 0..N observations; Music may be observed on 0..N Specimens | One concrete physical object observation | Direct when registered and evidenced | Observation provenance, object identity and observation evidence | Governed proposal only; never promotes observation to Variant or Model configuration automatically | Specimen page may show the observation; Brand/Model pages must preserve `DERIVED` observation scope and must not generalize it | REGISTERED IN `PredicateRegistry`; no physical edge backfill |

## Existing registered relations

The current registry contains `about`, `depicts` and the six exact approved
definitions above. `Variant → Brand`, `Movement → Brand`,
`Music → Brand`, `Component → Brand`, `Media → Brand` and other ownership
shortcuts are not approved predicates and must not be registered or persisted
for display acceleration.

## Transitional structural evidence

The current Authority payload fields `model.brand_uuid` and
`variant.model_uuid` are compatibility evidence only. They are not canonical
Graph truth. During the transition:

| Evidence state | Public result | Reason classification |
|---|---|---|
| One clear, valid, unique active payload parent and no canonical edge yet | May remain publicly eligible under the transition contract, with an internal warning | `DATA_COMPATIBILITY_GAP` |
| Parent missing, malformed, inactive or unresolved | Block structural completeness | `STRUCTURAL_PARENT_MISSING` |
| Multiple/conflicting parent candidates | Block structural completeness | `STRUCTURAL_PARENT_AMBIGUOUS` |
| Code treats the payload field as canonical Graph ownership | Stop the conflicting semantic change and document it | `CONSTITUTION_CONFLICT` |

No transition branch creates or edits an edge. A future governed repair must
follow discovery → evidence inspection → proposal → human approval →
eligibility → Controlled Apply → Graph → durable audit.
