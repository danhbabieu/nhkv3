# NHK V3 Dictionary Entry/Sense Architecture Decision

> **DOCUMENTATION + READ-SLICE STATUS — 2026-10-03.** This document is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md` and
> `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`.
> It does not authorize code, migration, data mutation, rekeying, Graph
> vocabulary, staging acceptance or deployment.

## 1. Scope and status vocabulary

This decision separates four facts that must never be presented as one:

| Layer | Meaning for this decision |
|---|---|
| **CURRENT LAW** | Dictionary is lexical curation only. Authority owns canonical entities; Knowledge owns atomic claims; Source/Evidence owns provenance/support; Graph owns semantic relations; WordPress owns editorial posts; Media/Asset/Usage and Video retain their own boundaries. |
| **CURRENT IMPLEMENTATION** | Migration015 remains the compatibility source. Additive Migration024 tables, `LexicalEntry`/`LexicalEntryForm` values, mapping-first public detail composition, reverse Mention/related-term projections and the enrichment audit/plan/apply tooling exist. The original bounded materialization contains 29 public Entries, 29 Forms and 29 Entry↔Sense mappings; do not rerun it. `DictionaryConcept` remains the durable Sense identity. |
| **APPROVED TARGET DESIGN** | `DictionaryConcept` retains its UUID and durable identity and plays the target semantic role of `LexicalSense`. The target model is `LexicalEntry → Forms → 1..N DictionaryConcept-as-LexicalSense`. `LexicalSense` is a design role for the current Concept, not a new runtime class/table. Candidate and Mention remain discovery/provenance objects. |
| **IMPLEMENTATION GAP** | Full Entry/Form/Sense editorial write lifecycle, context-qualified sense filtering, review action distinctions, complete Knowledge destination lifecycle, and governed owner-specific enrichment remain incomplete or runtime-gated. Migration015 data has not been transformed. |

All status statements in this document use those labels. “Target”, “should” and
“recommended” are not claims that a runtime capability exists.

The Universal Structured Semantic Intake contract remains historical/current
intake law exactly as written. This Entry/Sense evolution does not rewrite that
history, change its schema, authorize migration, or change the intake/runtime
boundary; Entry/Sense is a separate architecture design that follows it.

## 2. Ownership map

| Object | Absolute owner | Dictionary may do | Dictionary must not do |
|---|---|---|---|
| LexicalEntry | Dictionary | Own lexical headword/form family and entry lifecycle | Become Authority identity or Graph endpoint |
| DictionaryConcept-as-LexicalSense | Dictionary | Own one lexical meaning and lexical context in the target role | Become a Knowledge claim or replace canonical entity payload |
| Forms/Aliases | Dictionary | Store spelling, inflection, colloquial, technical and phonetic forms with scope | Treat every form as Authority alias or public canonical name |
| Candidate | Dictionary review boundary | Record discovery/review state | Approve public truth automatically |
| Mention | Dictionary lexical provenance | Record an occurrence and source locator | Assert identity, relation, Evidence or claim support |
| Brand/Model/Variant/Movement/Music/Component | Authority | Resolve/reference existing owner | Duplicate, rename or own their canonical identity |
| Classification / Clock Type | Authority + registered Graph policy | Resolve/reference existing classification; show lexical context | Create a classification, hierarchy or membership from a lexical match |
| Specimen | Authority | Resolve/reference the concrete physical object | Generalize an observation to Model/Brand |
| Product | Authority | Resolve/reference the listing/offer | Use Product as Specimen identity |
| Knowledge | Knowledge | Retrieve/reuse or hand off a separately governed candidate | Copy a claim/definition into a second factual store |
| Source/Evidence | Source/Evidence | Preserve lexical attestation reference and locator | Store Evidence payload or upgrade an attestation into Evidence |
| Graph | Graph/Governance | Produce a validated relation handoff when separately justified | Persist a lexical relation or invent endpoint/predicate |
| Article / WordPress | WordPress editorial owner | Read bounded text and project lexical links | Rewrite stored body or own lexical truth |
| Media / MediaAsset | Media boundary | Resolve eligible illustrative media | Duplicate binary or make an image the lexical owner |
| MediaUsage | MediaUsage | Bind an approved illustration/presentation context | Treat placement as Graph, Evidence or semantic truth |
| Video | Video boundary | Read authorized metadata/transcript and record lexical Mention | Broaden `about`, create an alias or turn transcript into Evidence |
| Governance | Governance | Govern any separate semantic/owner mutation | Be replaced by Dictionary curation shortcuts |
| Public Projection / Dossier | Read/projection boundary | Render approved lexical context and owner links | Persist reverse ownership or infer relations from reachability |

Dictionary is therefore a lexical owner, but not a semantic owner. Entry/Sense
does not add a new Authority type, Graph endpoint, predicate or Knowledge type.

## 3. Target lexical model

```text
LexicalEntry (lexical UUID, lifecycle, locale/domain scope)
  ├── 1..N Form (surface forms / lookup forms)
  └── 1..N DictionaryConcept-as-LexicalSense
         ├── lexical definition
         ├── domain/context and usage notes/examples
         ├── optional CanonicalConceptReference
         └── 0..N LexicalAttestationReference

Candidate ──discovery/review──> Entry or Sense decision
Mention ──occurrence/provenance──> observed Entry/Sense or unresolved term
```

### 3.1 Entry and Sense

The approved target split is:

- `LexicalEntry` is the spelling/form family. It has its own stable lexical
  identity and lifecycle, but no semantic Authority identity.
- `Form` is the lookup/display surface. A form may be preferred,
  alternate, colloquial, technical, phonetic or hidden, with locale and
  context qualifiers.
- `DictionaryConcept` is the current durable Concept and the target
  `LexicalSense` semantic role. It owns definition, lexical context, usage
  notes/examples and optional canonical-owner reference. No parallel Sense
  owner is created merely because the architecture names this role.
- A sense may reference an Authority, Knowledge, Article or another existing
  public owner only through a typed, read-time-validated reference. It does not
  copy that owner’s payload.
- The same Entry may have several Senses. A single Form may resolve to several
  Senses in different contexts; absent a unique context the result is
  ambiguous and must not auto-link.

### 3.2 Forms: Entry-level first, Sense-qualified when necessary

Forms should be Entry-level by default because spelling, inflection and lookup
normalization are properties of the lexical family. The target also supports a
Sense qualification (`sense_id`, context, or equivalent bounded association)
when one surface form has different meanings, domains or languages. This avoids
duplicating the same surface string while retaining disambiguation.

`LexicalEntry` owns the headword/preferred display form. General forms and
aliases belong to the Entry/Form boundary. Sense-specific forms are allowed
only when a clear semantic reason requires them. Runtime currently has both
`DictionaryConcept.preferred_label` and `DictionaryLabel(kind=PREFERRED)`, and
they can be edited independently; runtime must not be described as having
solved their synchronization. `DictionaryConcept.preferred_label` is therefore
kept as a migration compatibility field, not a second long-term owner.

**CURRENT IMPLEMENTATION GAP:** preferred wording has two independently
mutable runtime paths. A future implementation must define one Entry-owned
source of truth and an explicit read precedence before enabling any migration
write.

### 3.3 References, destinations and provenance

The target separates:

1. **Semantic reference**: `Sense → semantic_reference(type,id)` to an existing
   Authority Entity, Knowledge record or Article owner, with UUID/stable key /
   revision where the owner contract exposes them.
2. **Public Destination Projection**: `semantic owner → Public Projection →
   current canonical route`, derived from the owning Public Identity/SEO
   boundary and revalidated at read time.
3. **Lexical Attestation Reference**: source kind, source ID, locator, field,
   timestamp/segment and derivation lineage showing where wording was observed.

`destination_type`, `destination_id` and `destination_url` in the current
Concept are a compatibility representation during transition. They currently
mix semantic reference and navigation destination; a URL is never evidence of
semantic identity.

The target therefore is:

```text
Sense → semantic_reference(type,id)
      → semantic owner → Public Projection → current canonical route
```

The old description of a single destination triple is not a target ownership
model. A future contract may retain a read-only destination snapshot for
cache/diagnostics, but the canonical owner reference and current public route
remain distinct.

Lexical attestation proves that a wording/meaning was observed or curated in a
lexical source. Knowledge Evidence supports or contradicts an atomic claim
through the Source/Evidence lifecycle. An attestation may reference Evidence,
but it is not Evidence merely because it has a source locator.

<!--
The legacy wording below is intentionally retained as a compatibility note:
the current owner reference may expose
   Authority/Knowledge/Article owner, with UUID/stable key/revision where the
   owner contract exposes them.
2. **Public Destination Projection**: a current URL/read route derived from the
   owning Public Identity/SEO boundary and revalidated at read time.
Authority/Knowledge/Article owner, with UUID/stable key/revision where the
owner contract exposes them. This is not a second target model.
-->

## 4. Review and resolver behavior

The resolver target is:

```text
Form → Entry → candidate Sense(s) → canonical-owner reference → public route
```

It must search/reuse approved Forms and Senses first, then resolve current
canonical owners, then create/update a private Candidate only when unresolved.
The context packet must include locale/domain/source context and preserve
ambiguity. It must never infer a Sense from label equality alone.

The current runtime Candidate workflow remains limited to:

`ATTACH`, `CREATE_DRAFT`, `AMBIGUOUS`, `REJECT`, `IGNORE`, `DO_NOT_SUGGEST`.

The following are target design semantics only, not current MCP operation
names or implemented runtime actions:

| Review action | Meaning |
|---|---|
| `CREATE_ENTRY_WITH_SENSE` | New Entry and its first Sense are required |
| `ADD_FORM_TO_ENTRY` | Existing Entry/Sense meaning is right; add a spelling/alias form |
| `ADD_SENSE_TO_ENTRY` | Existing Entry/form is right; add a distinct lexical meaning |
| `ADD_SENSE_SPECIFIC_FORM` | Add a form scoped to a Sense only when semantic justification exists |
| `ATTACH_REFERENCE` | Link a Sense to an existing canonical owner; do not create owner |
| `REVIEW_AMBIGUITY` | Multiple Senses/owners remain viable; no auto-link |
| `SUPPRESS` | Durable lexical suppression; do not recreate equivalent Candidate |

These target semantics must not be represented as current runtime operations or
collapsed into one “create concept” decision. A review action is still Dictionary curation and
does not approve a Knowledge, Graph or Authority mutation. Any secondary
semantic change enters its own Governance lifecycle.

## 5. Lexical relations versus semantic relations

Lexical relations (same form family, inflection, usage variant, sense
qualification, attestation and candidate lineage) belong to Dictionary and
are not Graph relations. Graph is reserved for registered semantic predicates
between registered semantic endpoints. Dictionary must not become a Graph
endpoint merely because Entry/Sense is durable.

The default Sense → Brand/Model/Variant/Movement/Music/Component/
Classification/Specimen/Product direction is a typed reference owned/read by
Dictionary and validated against the target owner. A semantic relation is
only a separately proposed Graph relation when the owning contract, registered
predicate, scope, provenance and Governance lifecycle all require it. Reverse
Entity/Classification/Brand/Knowledge/Article → Dictionary is a derived
query/projection from references and approved forms when needed; it must not be
persisted as mirror arrays, inverse edges or a second table merely for UI
convenience.

Two-way UI/readability is therefore not two persisted relationships.

## 6. Pairwise ownership and projection matrix

| Pair | Canonical write | Read/reverse projection | Mention/reference/Graph rule | Forbidden duplicate |
|---|---|---|---|---|
| Article ↔ Dictionary | Article writes only `wp_posts`; Dictionary writes lexical curation/observations | Article may render approved lexical links; Dictionary may inspect Article context | Mention for occurrences; no Article body copy; no Graph edge for co-occurrence | Dictionary definition as Article body store |
| Knowledge ↔ Dictionary | Knowledge owns claims and Source/Evidence lifecycle | Dictionary resolves/reuses terminology; public Dictionary may link to Knowledge | Reference/attestation only; no claim duplication | Dictionary claim mirror |
| Authority ↔ Dictionary | Authority owns entity identity/aliases | Dictionary resolves to current UUID/stable key/revision; Entity surfaces may project terms | Typed owner reference; Graph only if separately justified | Dictionary-owned Brand/Model alias truth |
| Classification ↔ Dictionary | Authority/Graph own classification and registered membership | Dictionary uses classification as context/filter | Reference only by default; no `classified_as` from wording | lexical taxonomy or hidden membership |
| Brand/Model/... ↔ Dictionary | Same Authority ownership rule | Derived reverse lookup from approved references | No persisted inverse relation | second alias/identity table |
| Media ↔ Dictionary | Media owns binary/semantic Media; MediaUsage owns placement | Dictionary can select eligible existing illustration | MediaUsage is presentation; OCR/filename/recognition is Mention input only | Dictionary binary or `depicts` shortcut |
| Video ↔ Dictionary | Video owns external identity and lifecycle | Dictionary reads authorized metadata/transcript | Mention/attestation only; no `Video --about--> DictionaryConcept`; any future pronunciation/explanation/illustration link is lexical/presentation association outside the first implementation slice | Video transcript alias/relation store |

## 7. Public rendering and routes

The approved public target `/tu-dien/{entry-slug}/` is Entry-centric because
readers recognize a word/form family first. One Entry may render multiple
Senses. Detail rendering is Sense-aware:

- one Entry with one approved Sense renders one concise lexical view;
- multiple approved Senses render separate sense sections/cards, each with its
  own context, definition and eligible owner link;
- an ambiguous/private/review-only Sense is not public;
- an owner-delegated Sense links directly to the current canonical owner and
  does not create a competing indexable Dictionary page;
- if multiple Senses reference different canonical owners, the Entry page is a
  lexical disambiguation page and each Sense links to its semantic owner;
- if one Sense delegates completely to one canonical Entity/Knowledge/Article,
  do not create a competing indexable Dictionary detail page: hub/search may
  link directly to the owner;
- a legacy Dictionary URL, if present, redirects directly in one 301 hop to
  the owner/new route, never through a redirect chain;
- a standalone Dictionary URL exists only when Dictionary owns the reader
  destination under the existing Public Identity/SEO rules.

**CURRENT IMPLEMENTATION:** Dictionary is still Concept-centric: detail is
resolved by one `public_slug`; multiple Concepts with the same slug are
`AMBIGUOUS`. Entry-centric rendering and `/tu-dien/{entry-slug}/` are not
runtime behavior.

Resolver and auto-linking remain `Form → Entry → Sense → owner → current URL`.
No URL is derived from a lexical UUID, label text, destination snapshot or
external source ID. Public Dossier may include an approved contextual lexical
projection, but Dictionary does not become part of the semantic dossier’s
ownership graph.

## 8. Migration decision: Option A versus Option B

### Option A — additive compatibility (recommended)

Retain `DictionaryConcept`, `DictionaryLabel`, `DictionaryCandidate` and
`DictionaryMention` and every UUID created by Migration015. The safe default is
`1 old Concept → 1 compatibility Entry → 1 Sense`, with the old Concept UUID
remaining the durable identity and the Sense being its target role. Only after
that compatibility representation is explicit may curators group Concepts into
one Entry. No automatic merge is performed because preferred labels or
normalized labels match.

Benefits: preserves UUIDs and replay behavior; supports old readers; permits
Structure First → Relationships First → Data Later; allows a dry-run mapping
before any semantic interpretation; keeps Candidate/Mention provenance intact.

Risks: a temporary compatibility projection must prevent two writers and must
define which fields are authoritative at each stage. This is a documentation
and governance problem, not permission to run a silent backfill.

### Option B — alter Concept/Label in place (not recommended)

Changing `dictionary_concepts`/`labels` directly could reduce table count, but
would overload existing Concept semantics, make Entry/Sense identity ambiguous,
risk changing current repository contracts, and make old UUIDs appear to have
changed meaning. It would also encourage a destructive or implicit migration
of preferred labels, destinations and definitions.

Option B has no acceptable advantage under current Constitution constraints.
It must not be used without a separately approved contract, migration design,
read/write cutover, rollback and exact data audit.

## 9. Legacy Migration015 preservation law

Until a future migration contract exists:

- do not delete, rekey, merge or rewrite Concept, Label, Candidate or Mention;
- treat current Concept UUID as durable legacy identity;
- preserve current preferred label, definition, destination snapshot, context,
  revision and lifecycle exactly on read;
- preserve Label kind/locale/context and `PREFERRED` without assuming it is a
  Sense owner;
- preserve Candidate state, raw forms, context hash, suggestions and revision;
- preserve Mention fingerprint, source kind/ID, locator/context, strength and
  concept reference;
- do not backfill Sense meaning from preferred-label equality, frequency or
  destination URL;
- do not perform implicit semantic backfill, rekey UUIDs, delete/reset old
  data, or auto-merge Concepts by `preferred_label` or normalized label;
- any later multi-Concept grouping into one Entry is explicit and
  human-governed; every old slug redirects one hop to the new route;
- make any future mapping read-only/dry-run first and report `UNMAPPED`,
  `ONE_TO_ONE`, `MULTI_SENSE_REVIEW` and `DESTINATION_REVIEW` separately.

No compatibility read path may silently create a second durable owner. If both
legacy and target structures are present, one explicitly named writer and one
read precedence rule must be documented before mutation is enabled.

## 10. Open assumptions and dissent

The following remain assumptions rather than implementation facts:

1. Every old Concept is the safe default compatibility representation of one
   Sense; grouping multiple Concepts into one Entry still requires review of
   definition/context/destination ambiguity.
2. Entry-level Forms plus optional Sense qualification is preferred; some
   specialized language data may require a stronger per-Sense form association.
3. Existing `destination_type/id/url` values require classification before a
   future split into owner reference and public route projection.
4. Dictionary Media read projection currently follows
   `DictionaryRuntime → EntityMediaProjection->forEntity('dictionary_concept', conceptId)`
   when a stored MediaUsage endpoint exists. The normal governed write path is
   `MediaTargetNormalizer → MediaTargetRegistry → Graph EndpointTypeRegistry`,
   while Dictionary is not a Graph endpoint. Therefore Dictionary Media
   binding write is a **CURRENT IMPLEMENTATION GAP**, not a reason to promote
   Dictionary into Graph. A future lexical/presentation target registry or
   bounded MediaUsage validation is deferred to an implementation plan.
5. Contract target allows Entity / Knowledge / Article semantic destinations.
   **CURRENT IMPLEMENTATION:** resolver has Knowledge lookup, but curation
   approval primarily accepts Authority entity types and delegated revalidation
   handles Authority and Article, not Knowledge fully. **STATUS:
   IMPLEMENTATION GAP.** Knowledge delegation is not READY.
6. Video metadata/transcript may create lexical Mention/Candidate. No
   `Video --about--> DictionaryConcept` is allowed; direct Sense↔Video binding
   is deferred from the first implementation slice.
7. No new Graph predicate, Dictionary endpoint or reverse relation is proposed.

These are deliberate disagreements with a “Concept equals Sense immediately”
model: the current schema has no parent Entry, no multi-sense identity, and a
mixed destination snapshot. Treating it as a perfect Sense now would erase
ambiguity and create a second source of truth.

## 11. Documentation and implementation acceptance checklist

- Current law, implementation, documented target and gap remain separately
  labeled.
- `CURRENT LAW`, `CURRENT IMPLEMENTATION`, `APPROVED TARGET DESIGN` and
  `IMPLEMENTATION GAP` are not interchangeable.
- This design record does not authorize additional PHP/runtime/schema/data
  changes; its implementation is recorded in `V3_EXECUTION_STATE.md`.
- Entry/Sense enrichment capability names are recorded in the execution-state
  handoff; they do not create a Dictionary Graph endpoint.
- Dictionary remains outside Graph endpoints/predicates.
- Article, Knowledge, Authority, Media, Video and Governance retain ownership.
- Option A is the only recommended migration direction.
- Any future implementation must add contract tests, read precedence,
  idempotency, revision binding, dry-run mapping and canonical read-back before
  semantic writes are considered.

## 12. Code-side checkpoint — 2026-10-04

The implementation is `IMPLEMENTED_CODE_SIDE` and locally tested for additive
structure, read resolution, public detail composition and bounded enrichment
planning. Migration 024 adds Entry, Form and Entry→existing-Concept/Sense
mapping tables; the original bounded materialization populated 29 Entries, 29
Forms and 29 mappings, while Migration015 remains unchanged. The repository
can read durable target rows or derive a read-only one-Concept compatibility
Entry from Migration015 data.
The Entry/Sense resolver returns one resolved Sense only when unique, and
returns ambiguity for multiple viable Senses. Destination URLs are
revalidated and are never used as identity.

Preferred-wording mutation ownership is intentionally not enabled: the
existing Concept/Label writers remain the only durable writers until a
CAS/read-back cutover is designed. Enrichment apply is limited to exact
Dictionary lexical/mapping data and does not mutate owner systems. Complete
Knowledge destination lifecycle, governed Dictionary Media binding and Video
association remain `IMPLEMENTATION GAP`.

## 13. Materialization checkpoint — 2026-10-03

`SCHEMA MIGRATION != SEMANTIC MATERIALIZATION`. The read-only
`DictionaryEntryMaterializationPlanner` inventories existing Migration015
Concepts against Migration024 mappings and classifies unmapped, mapped,
inconsistent, retired, destination-invalid and preferred-wording-divergent
cases. The safe default is `1 existing Concept → 1 Entry → 1 existing
Concept-as-Sense`; existing Concept UUIDs remain the Sense identity.

Equal labels, normalized forms, slugs, destinations and similar definitions
never merge Concepts. Such collisions emit
`GROUP_SENSES_UNDER_ENTRY / REVIEW_REQUIRED` only. Apply requires the exact
planner fingerprint, current Concept revision, idempotency and read-back, and
only accepts eligible one-to-one items. Production remains read-only; no
Knowledge, Source/Evidence, Graph, Candidate or Mention write is included.

## 14. Deploy-before-schema invariant — 2026-10-03

`CODE DEPLOYMENT MAY PRECEDE SCHEMA MIGRATION, THEREFORE ENTRY/SENSE READS MUST BE SCHEMA-GATED AND FAIL TO COMPATIBILITY MODE, NEVER RAW SQL ERROR.`

`SCHEMA MIGRATION IS A DEPLOYMENT LIFECYCLE STEP, NOT A FRONTEND REQUEST SIDE EFFECT.`

`DictionaryEntrySenseMigration024::schemaReady()` is the authoritative
`ENTRY_SENSE_SCHEMA_READY` capability. Public hub/detail, profile and planning
surfaces use Migration015 Concept compatibility mode while it is false; Entry,
Form and Sense repositories are not queried. Entry/Sense mutations fail closed
with `DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE`.
