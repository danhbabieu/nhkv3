# NHK V3 Universal Structured Semantic Intake & Synthesis Contract

> **APPROVED SUBORDINATE CONTRACT — 2026-09-30.** This contract is
> subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`. If any wording
> conflicts with the Constitution, the Constitution controls.

## 1. Purpose and scope

This contract defines the shared planning law for interpreting linguistic input
before NHK V3 resolves canonical identity, enriches existing semantic truth,
plans a Dictionary/Knowledge/Relation delta or synthesizes editorial output.
It applies to Article text, Knowledge text, Video title/description/transcript,
Media caption/alt/OCR/context, spoken-language transcripts and human hints.

The canonical conceptual sequence is:

```text
RAW INPUT
  → INTERPRET
  → STRUCTURED SPANS / CANDIDATES
  → RESOLVE
  → REUSE
  → EVALUATE SCOPE, PROVENANCE, EVIDENCE AND APPLICABILITY
  → DELTA PLANNING
  → GOVERNANCE IF MUTATION
  → SYNTHESIS / PUBLIC PROJECTION IF READ PATH
```

This is an application/planning boundary. It does not replace the owning
contracts for Authority, Graph, Knowledge, Source/Evidence, Dictionary, Media,
Video, Article, Capture, Content Intent or Governance.

`nhk.capture.ingest` remains the only normal entry point for a new submission.
Direct domain writers remain guarded internal/admin lifecycle boundaries under
the Constitution and their owning contracts.

`KNOWLEDGE_DELTA` and `KNOWLEDGE_REPAIR` are separate Content Intents. Delta
planning handles ordinary semantic reuse/addition; repair planning handles
existing-owner Knowledge/Source/Evidence cleanup with exact canonical target
identity, revision and dependency closure. A repair plan cannot be admitted by
renaming its intent to `KNOWLEDGE_DELTA`.

## 2. Ownership and non-persistence

The shared result is an ephemeral/read-planning DTO contract, referred to here
as a `StructuredInterpretationPacket`. The name is conceptual until an approved
runtime implementation exists; it does not authorize a new runtime type.

The packet may contain, as applicable:

- source context, raw-input reference/lineage, locale and Content Intent context;
- provenance, evidence, uncertainty, scope, subject and editorial signals;
- lexical, proper-name, identifier, configuration and technical-term spans;
- resolved references, unresolved terms and ambiguous terms;
- attribute, relation and claim candidates;
- reuse matches and diagnostics;
- Dictionary, Knowledge and Relation delta candidates.

The packet is not a database entity, canonical semantic identity, Authority
type, Graph endpoint, Source, Evidence, Knowledge record, Dictionary owner or
Article semantic owner. It does not persist raw Article body as Knowledge,
create a parallel semantic store or authorize a write.

## Source Adapter Contracts

The inheritance law is one shared semantic path:

```text
Natural Chat ─────┐
Image / Media ────┤
Article / News ───┤
Video ────────────┤
Legacy Knowledge ─┤
                  ▼
      Shared Semantic Core
                  ▼
 StructuredInterpretationPacket
                  ▼
 Dictionary / Retrieval
 Knowledge / Relation
 Writer / Search
```

Future physical ingest, image/media, article/news and video flows add only an
outer source adapter:

```text
physical ingest
→ source adapter
→ Shared Semantic Core
→ Dictionary resolution
→ canonical semantic retrieval
→ Knowledge reuse
→ relation discovery
→ enrichment
→ Writer/read-back
```

No Image, Video or Article adapter may create a second semantic parser. The
same trust boundaries remain in force: `OCR ≠ Evidence`, `recognition ≠
canonical identity`, `transcript ≠ Knowledge`, `transcript ≠ Evidence
automatically`, `generated prose ≠ Knowledge`, `generated prose ≠ Evidence`,
`Dictionary match ≠ semantic identity`, and `Graph reachability ≠
applicability`.

The source adapter law is deliberately source-agnostic after extraction:

```text
IMAGE / MEDIA   ─────┐
ARTICLE / NEWS  ─────┤
VIDEO           ─────┤
HUMAN / CHAT    ─────┤
KNOWLEDGE       ─────┤
                    ▼
           SHARED SEMANTIC CORE
                    ▼
      StructuredInterpretationPacket
                    ▼
 Dictionary / Retrieval / Knowledge
 Relation / Editorial Writer / Search
```

`IMAGE`, `ARTICLE`, `NEWS`, `VIDEO`, `CHAT` and `KNOWLEDGE` must not grow
separate semantic parsers. Each adapter is limited to source-specific
extraction, source metadata, provenance/lineage and validated canonical-target
hints. It then calls the shared interpreter. The Core has no dependency on an
adapter and downstream consumers must consume the same packet shape and trust
invariants. Adding a future adapter therefore means `build adapter → plug into
Core`, not changing Core, Dictionary, Knowledge or Writer semantics.

### Human / Chat adapter

Human text and Chat input provide `USER_TEXT`, optional user hints, source
context and explicit intent/target hints. The adapter preserves the exact
request and its lineage, but the user's wording is still interpreted through
the same lexical, subject, scope, provenance and Governance boundaries. A user
assertion is not Evidence merely because it is explicit; it is a bounded
observation/provenance signal for planning.

### Conversational lexical capture law — 2026-10-04

Human/Chat input has two independently gated outputs:

```text
USER / CHAT
  → lexical observation track → Dictionary resolution / reuse / private Candidate
  → semantic truth track      → Authority / Knowledge / Relation planning
```

The governing distinction is:

```text
SEMANTIC SUBJECT UNRESOLVED ≠ LEXICAL OBSERVATION INVALID
LEXICAL CANDIDATE ≠ SEMANTIC FACT
```

A downstream semantic-subject, Knowledge, Relation or Authority gate may block
semantic mutation, but it must not by itself erase a reusable lexical
observation that already passed the shared lexical-quality boundary. When the
submission is authenticated/allowed, the Capture has a durable source identity,
and a span is lexically eligible, the Dictionary path must independently:

1. search and resolve approved Forms/Labels first;
2. reuse the existing Entry/Sense when resolution is unique and applicable;
3. otherwise create or increment only a private review Candidate, preserving
   the exact observed form, normalized form, source lineage and uncertainty;
4. persist a Mention only when a durable source binding can be represented
   without inventing an Article, Knowledge owner, Authority owner or Graph edge;
5. remain idempotent for replay of the same Capture/source fingerprint.

No primary canonical semantic subject is required merely to remember that a
qualified term was observed. Conversely, lexical durability never grants
semantic truth: unresolved Brand/Model/person-like wording remains lexical
review state and must not mint Authority. Spelling, ASR and transcription
uncertainty must preserve the raw form; a suggested correction may aid lookup
but may not silently replace the user's observed wording or assert identity.

A whole-Capture authorization failure, invalid payload or rejected lexical span
may still prevent persistence. A downstream semantic review requirement is not
such a reason. Generated or derived restatements retain lineage and must not
inflate independent lexical occurrence counts.

### Image / Media adapter (planned seam)

The future Media adapter must expose this ordered, read-back-oriented pipeline:

```text
uploaded image
  → physical Media ingest
  → Media identity/read-back
  → permitted metadata
  → caption / alt / filename
  → OCR if available
  → visual recognition/observation if available
  → Shared Semantic Core
  → StructuredInterpretationPacket
  → Dictionary resolution
  → Authority resolution
  → Knowledge retrieval
  → relation/claim candidates
  → enrichment/editorial planning
```

The adapter must retain signal kind and lineage for each input. The permitted
signal kinds are `USER_TEXT`, `CAPTION`, `ALT_TEXT`, `FILENAME`, `OCR`,
`VISUAL_OBSERVATION` and `MODEL_RECOGNITION`. Each signal carries its source
locator/field, extraction method, timestamp or request context when available,
confidence/uncertainty and parent lineage. Confidence ranks a candidate; it
never promotes it to identity, fact or Evidence.

The following trust boundaries are mandatory:

```text
OCR ≠ Evidence
filename ≠ Evidence
recognition ≠ canonical identity
MediaUsage/depicts ≠ factual proof
specimen observation ≠ model/variant fact
```

Media identity belongs to Media/MediaAsset and usage belongs to MediaUsage;
neither is replaced by an interpretation packet. If upstream supplies an
already canonical target, the adapter passes that target as context, including
its UUID/stable key and revision where available. It must not expand that
context to another Brand, Model or Variant merely because a caption, filename,
OCR or recognition result matches a broader or neighboring term.

### Article / News adapter (planned seam)

The future Article/News adapter must keep editorial ownership in native
WordPress posts and expose this planning sequence:

```text
Article / news / research text
  → Source context
  → text segmentation
  → Shared Semantic Core
  → terminology/query seeds
  → subject resolution
  → canonical Knowledge search
  → claim extraction
  → duplicate/reuse analysis
  → qualification/contradiction analysis
  → Source/Evidence candidate planning
  → governed Knowledge/Graph planning
  → editorial synthesis
```

It must distinguish `SOURCE_TEXT`, `FACTUAL_CLAIM`, `EDITORIAL_LANGUAGE`,
`QUOTE`, `AUTHOR_OPINION` and `GENERATED_SUMMARY`. A whole article, research
note or news story is not Knowledge. Generated summary or an NHK-authored
Article body is:

```text
GENERATED SUMMARY / NHK ARTICLE BODY ≠ Source mới
GENERATED SUMMARY / NHK ARTICLE BODY ≠ Evidence mới
GENERATED SUMMARY / NHK ARTICLE BODY ≠ independent corroboration
```

Rephrasing one source in multiple places remains one provenance family. The
adapter must carry source identity, publication/locator context, quote and
derivation lineage so duplicate/reuse and corroboration checks cannot count
editorial rewrites as independent support.

### Video adapter (planned seam)

The future Video adapter must expose:

```text
Video identity
  → title
  → description
  → tags
  → transcript
  → timestamped observations if available
  → Shared Semantic Core
  → Dictionary/query seeds
  → subject resolution
  → Knowledge retrieval
  → observation/claim/relation candidates
  → enrichment plan
  → Writer/output
```

`transcript ≠ Knowledge`, `transcript ≠ Evidence` automatically and spoken
wording is not canonical terminology. ASR errors remain recoverable: raw
transcript, ASR confidence, correction lineage and segment/timestamp context
must remain available. Every visual/audio observation carries its timestamp,
segment and source context. If Video has an explicit canonical semantic target,
the target is context only; words in the transcript are not automatically
aliases or relations of that target, and the adapter must not broaden the
target to another subject without an independently resolved and governed path.

### Shared handoff law

After any adapter reaches the Core, the only shared consumers are Dictionary,
Semantic Retrieval, Knowledge planner, Relation planner, Editorial Writer and
Search/Projection. Core output remains ephemeral and planning-only until the
existing owner/Governance boundary accepts a mutation. No adapter may add an
entity type, endpoint type, predicate, relation type, canonical field,
Knowledge profile, alias or Evidence rule to make its source easier to parse.

## 3. Three interpretation layers

### 3.1 Language layer

This layer records what wording is observed: aliases, colloquial terms,
technical terms, names, shorthand, phonetic forms and normalized lookup forms.
Dictionary and other lexical capabilities operate here.

### 3.2 Semantic-structure layer

This layer proposes or resolves subjects, designations, attributes,
configurations, claims and relations through existing Authority, Knowledge and
Graph boundaries. A lexical match is not semantic identity. A resolved subject
is not automatically related to another subject, and a relation candidate is
not a fact.

### 3.3 Trust-and-scope layer

This layer preserves source kind, source identifier, raw/derived lineage,
observation class, provenance, evidence status, applicability, scope and
uncertainty. Layer A cannot decide Layer C. Frequency and model confidence are
signals, not authority or approval.

## 4. Constitutional interpretation invariants

The packet and every consumer must preserve these distinctions:

```text
DETECTED ≠ TRUE
RESOLVED ≠ RELATED
RELATED ≠ FACT
FACT ≠ UNIVERSAL
MENTION ≠ EVIDENCE
GENERATED PROSE ≠ KNOWLEDGE
GENERATED PROSE ≠ EVIDENCE
LEXICAL MATCH ≠ SEMANTIC IDENTITY
GRAPH REACHABILITY ≠ APPLICABILITY
FREQUENCY ≠ AUTHORITY
CONFIDENCE ≠ APPROVAL
UNKNOWN ≠ FALSE
```

`AMBIGUOUS` identity, owner, scope, predicate or applicability fails closed.
`UNKNOWN` may remain a valid private lexical candidate; it is not silently
discarded or treated as false. Narrow scope must not silently widen. Search and
canonical reuse precede any create/propose decision.

## 5. Shared lexical and structural interpretation law

The shared lexical boundary is locale-aware and reusable across Article,
Knowledge, Media, Video, transcript and human-input planning.

1. Prefer the longest reusable span with valid syntax and lexical reason.
2. Stop at conjunction, preposition, pronoun, auxiliary, question, clause
   continuation or other editorial tail; do not consume prose merely to make a
   longer candidate.
3. `NUMBER + WORD` is not sufficient for a structural configuration. Each
   `UNIT` position needs independent lexical/structural eligibility from an
   approved/hinted lexical capability, an existing generic detector reason or
   another governed structural-unit boundary. Arbitrary following words are
   not units.
4. A composite configuration is emitted only when every unit position is
   eligible. An invalid position fails closed or leaves only independently
   justified lexical spans; it must not create an interior fragment merely
   because a larger composite was attempted.
5. `/`, `-` and `.` do not independently create an identifier. Identifier
   eligibility requires sufficiently strong code/reference morphology or
   bounded contextual identity evidence, such as a digit-bearing reference
   segment, alphanumeric code morphology, registered technical prefix or
   approved/hinted identifier context.
6. A hyphenated proper-name beginning must retain a valid continuation when
   syntax/context supports one; a weaker first-token fragment is not emitted
   solely because it is easier to match.
7. A weaker interior span is suppressed when it exists only inside a stronger
   selected span. An atomic term remains valid when an independent detector
   reason exists.
8. Numeric-only designations are not rejected categorically. A numeric token
   may be a model, calibre, collector alias, variant shorthand or reference,
   but requires an independent context, hint or approved lexical reason.
9. If multiple canonical owners remain viable for one span, the result is
   `AMBIGUOUS`; it is not auto-linked or auto-attached.

Production/runtime corpus examples are regression observations only. They are
not implementation vocabulary, hard-coded production rules or authorization.
Synthetic and unseen fixtures are required to demonstrate generic behavior.

## 6. Spoken-language and transcript input

Transcript and ASR input may contain missing subjects, shorthand, aliases,
repetition, broken clauses, self-correction, incomplete spoken numbers,
community terminology and transcription errors. The interpreter must:

- preserve raw source lineage and the distinction between transcript and later
  derived/editorial text;
- retain uncertainty and ambiguity instead of silently correcting identity;
- treat ASR/transcript confidence as an observation signal, not semantic
  confidence or Evidence;
- allow a human correction to provide a stronger lexical hint without
  bypassing canonical resolution, scope, provenance, evidence or Governance;
- keep transcript-derived Knowledge/relation output planning-only until the
  owning governed lifecycle validates it.

Transcript text is not automatically a Source, Evidence, canonical claim or
relation.

## 7. Reuse-first enrichment order

After interpretation, enrichment follows this conceptual order:

```text
resolve subject
  → Dictionary lookup
  → canonical search
  → bounded Graph neighborhood
  → Knowledge search
  → exact/reuse analysis
  → Source/Evidence validation
  → determine actual gap
```

Graph reachability discovers candidates only. It does not authorize claim reuse,
relation applicability or scope widening. Existing canonical IDs and revisions,
original subject/scope, provenance, evidence and relevance must be retained for
every reused Knowledge item.

The packet may classify planning outcomes as `NO_CHANGE`, `REUSE_EXISTING`, a
Dictionary/Knowledge/Relation candidate, an evidence-addition candidate,
`AMBIGUOUS`, `EDITORIAL_ONLY`, `NOISE` or `UNRESOLVED`. These are semantic
planning classes, not a license to create storage enums or runtime vocabulary.

## 8. Knowledge enrichment

Input sentences are atomized into subject/designation, attribute or component,
factual proposition, provenance signal and scope signal. A complete sentence
is not automatically one Knowledge claim.

If equivalent applicable Knowledge exists, reuse it or plan valid evidence or
qualification enrichment before proposing a new claim. Repeated wording,
similarity, lexical frequency or generated prose does not create a duplicate
claim. User statements, specimen observations, transcript observations and
system inference retain their bounded provenance and scope. A narrow
observation must not silently become a Model, Brand or universal fact.

Durable Knowledge, Source or Evidence mutation remains:

`Proposal → Human Approval → Eligibility → Controlled Apply → repository → audit → read-back`.

## 9. Dictionary enrichment

Dictionary can consume Article, Knowledge, Video, Media, transcript and human
input to propose preferred, alternate, colloquial, technical, phonetic or
contextual labels and lexical concepts. It remains lexical curation only.

Automatic detection creates a private candidate or planning result; it does not
approve a concept, prove identity, establish factual truth, prove a relation or
become Evidence. Existing canonical owners are preferred for resolution. A
Dictionary definition does not copy or replace the owner's semantic truth.
Approval and any durable curation action remain human/governed under the
Dictionary contract.

## 10. Relation discovery

A relation candidate requires resolved source and target identities, a
registered predicate, valid direction, valid scope and sufficient
provenance/evidence under the owning contract. Co-occurrence, lexical
proximity, appearing in one sentence, same transcript segment or Graph
reachability alone is not a relation or evidence.

Relation mutation remains governed and read back. The packet cannot invent an
endpoint, predicate, relation type, direction or semantic owner.

## 10.1 Lexical-semantic editorial enrichment law

Dictionary, keywords, Knowledge, relations and prose have different jobs and
must remain separate while composing one coherent reader-facing result:

- **Dictionary / Entry / Sense / Form** owns language: what a term means
  lexically, accepted forms, aliases, technical/colloquial register and the
  lexical route to an existing semantic owner when one is known.
- **Keyword/query intent** is a retrieval and SEO planning signal derived from
  reader intent plus resolved lexical/semantic context. It is not identity,
  Knowledge, Evidence or a relation.
- **Knowledge** owns factual propositions with canonical subject, scope,
  provenance, evidence and revision.
- **Graph/relations** discover bounded context and candidate paths; reachability
  does not make a claim applicable.
- **Writer/editorial synthesis** chooses the natural surface wording for the
  selected, eligible facts. It owns prose, not truth.

For enrichment and "write better" flows, the required composition direction is:

```text
EDITORIAL INTENT
  → resolve canonical subject
  → retrieve and select eligible Knowledge
  → validate scope / provenance / evidence / applicability
  → resolve Dictionary Senses for the concepts actually being expressed
  → expand bounded keyword/query intent from those Senses + reader intent
  → choose preferred / alternate / colloquial / technical Forms by register
  → compose natural prose
  → compliance / SEO / public projection / read-back
```

The reverse direction is forbidden: a keyword, popular phrase, Dictionary
definition, Graph neighbor or stylistic phrase must never manufacture a fact.

Enrichment planning must distinguish at least conceptually between:

```text
LEXICAL GAP            = fact/meaning exists, but wording/forms are incomplete
SEMANTIC KNOWLEDGE GAP = factual proposition is missing or insufficiently supported
RELATION GAP           = an independently valid semantic relation is missing
EDITORIAL COVERAGE GAP = facts exist but reader-facing explanation is incomplete
```

These are planning classes, not permission to add new storage enums.

Natural prose must not be reduced to exact-match keyword stuffing. Prefer the
canonical/preferred term when clarity benefits, then use only Forms that resolve
to the same applicable Sense and fit the intended audience/register. Do not
invent synonyms merely for variation. A keyword may guide headings, query
coverage and retrieval, but fact selection must already be justified by
Knowledge and scope.

Generated Article/SEO/summary text may be scanned again for lexical diagnostics
only with derived lineage. It is not an independent occurrence family,
corroborating Source, Evidence or automatic Dictionary approval. This prevents a
self-reinforcing loop in which generated wording makes itself look authoritative.

## 11. Synthesis and public projection

Self-writing follows:

```text
EDITORIAL INTENT
  → interpret topic
  → resolve canonical subjects
  → Dictionary language expansion
  → bounded Graph discovery
  → retrieve Knowledge
  → validate subject, scope, provenance and evidence
  → determine reader coverage
  → select applicable Claims
  → synthesize
  → compliance
  → public projection
```

The writer must not write from Graph reachability alone, dump every related
claim, use an unreviewed lexical/semantic candidate as fact, broaden a
specimen observation, or copy raw Knowledge payload into a second store.
Existing Article contracts govern body-free Claim ID/revision traces.

Generated Article prose, summaries, SEO descriptions and other derived copies
remain editorial/read-model output. They are not automatic Knowledge or
Evidence. When derived output is scanned lexically, its lineage/source family
must remain visible so it cannot be counted as independent corroboration,
inflate frequency or feed a self-training loop.

## 12. Fail-closed and read/write boundaries

Interpretation, search, resolution, bounded Graph discovery, Knowledge
retrieval and synthesis may run on the read path. Dictionary approval,
Knowledge create/update, Evidence, Relation and Authority change remain write
path operations requiring their existing Governance boundaries.

If interpretation is incomplete, return `AMBIGUOUS`, `UNRESOLVED` or
`REVIEW_REQUIRED` as appropriate. Fail-closed semantic identity does not erase a
valid unknown lexical observation or prevent a private Dictionary candidate.

## 13. Status and future acceptance

This contract is `LAW APPROVED / IMPLEMENTATION PARTIAL`. The first runtime
slice now provides an ephemeral `StructuredInterpretationPacket` through the
shared `StructuredSemanticInterpreter`; Dictionary planning, the legacy Capture
adapter, shared editorial enrichment and Article research consume the same
Dictionary-backed lexical semantics. Capture, Knowledge, Graph, Video and
Media remain on their existing owner boundaries, and the packet remains
planning-only. Full read-only corpus acceptance and every domain adapter are
still incremental work; this status must not be read as complete acceptance.

Future implementation acceptance must cover, with synthetic/unseen input:

- lexical span length and grammar boundaries, proper names, identifiers,
  structural configurations, contextual numeric aliases and ambiguity;
- subject resolution, claim atomization, scope preservation, relation-candidate
  validation, reuse and duplicate suppression;
- user observation versus universal fact, transcript versus Evidence and
  generated prose versus Evidence, including derived-lineage contamination;
- synthesis limited to applicable Knowledge, bounded Graph discovery,
  unresolved candidates not becoming facts and generated Articles not feeding
  canonical Knowledge automatically.

## 14. Compatibility

This contract changes no existing canonical data, schema, migration, URL,
identity, ownership, Graph predicate, endpoint, MCP operation or runtime
behavior. It creates no backfill, mutation, deployment or parallel pipeline.

## 15. Implementation checkpoint — 2026-09-30

Implemented locally without schema or data mutation:

- `StructuredSemanticInterpreter` and ephemeral `StructuredInterpretationPacket`
  preserve source context, lineage, locale, lexical spans, unresolved/
  ambiguous terms, planning candidates and bounded diagnostics.
- `DictionaryTermDetector` remains the lexical owner and is invoked through
  the shared interpreter by `DictionaryPlanningService` and the compatible
  `TextInputInterpreter` Capture seam.
- `SharedEnrichmentBoundary` and `ArticleResearchPreflight` expose the same
  packet for downstream read/planning consumers; relation output remains
  candidate-only and is not applied.
- `DerivedLineageGuard` rejects generated/derived prose as independent
  corroboration before Knowledge proposal planning.
- Synthetic tests cover structural units, identifiers, ambiguity, unknown
  lexical candidates, relation registry gaps, lineage and multi-consumer
  lexical parity.

The 77 Article / 1,170 Knowledge read-only corpus and live runtime bootstrap
remain `PARTIAL`/`ENVIRONMENT_BLOCKED` in this workspace; no fixture-specific
production rule was added.

## 16. Dictionary Seed v1 and external enrichment implementation checkpoint

The local implementation now exposes `semantic_query_seeds` on the ephemeral
packet. Each seed carries the raw span, normalized form, generic category,
locale, bounded context, optional canonical reference/facet hint, ambiguity
state and advisory diagnostics. Seeds remain lookup hints and never become
Knowledge, Evidence, Entity or Graph relations.

`DictionarySeedPlanner` is the first read-only consumer. It deduplicates by
normalized form plus bounded context, preserves raw forms/source-family
lineage, resolves through the existing Dictionary owner, and reports
resolved-existing, ambiguous, suppressed, editorial/noise and unresolved
candidate outcomes without approval or persistence.

`SemanticEnrichmentPlanner` is an external read-only seam that composes
Dictionary resolution, canonical-owner resolution, bounded Graph discovery
and Knowledge applicability checks. It rejects wrong scope and missing
evidence, preserves unknown lexical terms, blocks derived prose from
independent corroboration and reports unavailable runtime distinctly from
empty data. The MCP `nhk.dictionary.seed-audit` adapter is internal/admin-only
and privacy-safe; it cannot approve or mutate any owner.

The same adapter provides a server-side bounded corpus mode for `KNOWLEDGE`,
`ARTICLE` and, when both readers are available, `ALL`. Canonical source text
is consumed only inside the process; opaque deterministic cursors page source
IDs and the serialized result contains lexical planning data only. The legacy
Dictionary candidate queue may be compared after current interpretation for
planning-only cleanup/review, but it never changes the current packet or Seed
v1 result.

## Shared Capture preflight and editorial relevance gate — 2026-10-09

Every Capture adapter now receives one read-only preflight vocabulary covering
intent, source identity, canonical identity, duplicate/reuse, subject
compatibility, provenance, scope, Evidence, Graph eligibility and content
relevance. Each check retains its own `READY`, `INCOMPLETE`, `BLOCKED`,
`UNAVAILABLE` or `NOT_APPLICABLE` state and reason codes; a pending or missing
check is never collapsed into an empty success. The preflight is diagnostics
and planning state only and does not authorize a semantic write.

Shared editorial selection applies an explicit relevance gate before
KnowledgeUnit selection. Low/irrelevant candidates are excluded with
`TOPIC_IRRELEVANT`; Graph reachability alone is not content applicability.
Evidence and provenance remain separate eligibility checks. Article, Media and
Video profiles use the same gate, and generated prose, transcript text and
user hints remain outside automatic Knowledge/Evidence creation.

## Universal semantic recovery and Authority parity — 2026-10-09

The Capture contract distinguishes an explicit user statement from derived
interpretation. A candidate with `user_statement`, the registered
`EXPLICIT_USER_KNOWLEDGE` provenance and a permitted non-derived admission may
enter the governed Knowledge proposal path. Dictionary-only or otherwise
derived interpretation remains review-gated with
`KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED`; it is not silently promoted to user
knowledge. `SemanticClaimCandidateGuard::POLICY_VERSION` is server-owned and
cannot be supplied by a client or fixture.

The guard applies registered scope/facet rules and the governed sequence is
Capture → Proposal → Approval/Eligibility → Controlled Apply → canonical
Knowledge read-back. Pending, rejected or missing Source/Evidence dependencies
remain explicit dependency state; they do not become evidence by inference. A Knowledge delta
never creates or rewrites an Article: WordPress native Posts remain editorial
truth.

The active nine-type parity matrix is:

| Authority type | Document contract | Canonical registry | Admission boundary | Governed result | Canonical read-back | Public projection |
|---|---|---|---|---|---|---|
| `brand` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `model` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `variant` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `movement` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `music` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `component` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `classification` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `specimen` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |
| `product` | Universal intake | `EntityTypeRegistry` + `CanonicalEntityTypeCatalog` | subject + registered scope/facet | Knowledge proposal | Knowledge UUID/stable key/revision | dossier only when eligible |

The matrix is a parity check over the existing registry, not permission to add
types, predicates, fields, relations or writers.

## Universal input classification integrity — 2026-10-09

The shared interpreter classifies each segment before semantic fallback. This
is transient planning context and never creates a canonical owner:

| Input class | Shared result | Knowledge admission |
|---|---|---|
| Verified/reviewable factual assertion | semantic candidate with bounded provenance/scope | only after registry, Source/Evidence and Governance checks |
| Explicit raw user fact | `user_statement` + `EXPLICIT_USER_KNOWLEDGE` | may enter the governed candidate path |
| Source locator (`Source: https://...`) | source-context locator | never a Claim or Evidence by itself |
| Evidence excerpt | provenance/evidence planning context | requires canonical Claim + Source and governed Evidence |
| Operational/editorial instruction | non-semantic instruction context | never a Claim |
| Unverified inference (`có thể`, `dường như`, `được cho là`) | derived, review-required candidate | rejected by semantic admission until reviewed |
| Dictionary terminology | lexical span/candidate | never semantic truth or Evidence |
| Media metadata (`filename:`, `alt:`, `OCR:`, `caption:`) | metadata context | never a Claim by label alone |

Mixed input is split at this boundary: a URL or instruction cannot contaminate
a neighboring factual proposition, and an explicit raw fact is not downgraded
merely because the same Capture contains a locator. The generic examples are:
“Giai điệu Cambridge Quarters được sử dụng tại một công trình theo quyết định
của hội đồng” with a source to verify; “Hãy chia thành Claim nguyên tử” and
“Source: https://example.test/research” are not Knowledge; an historical
statement supported only by an unreviewed story or locator remains unverified.

The classifier is source-agnostic and contains no Westminster name, UUID,
stable key, route or fixture branch. It does not relax the existing registry or
Governance admission rules.
