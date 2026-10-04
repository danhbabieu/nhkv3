# NHK V3 Dictionary Lexical Knowledge Contract

> **APPROVED SUBORDINATE CONTRACT — updated 2026-10-04.**
> This contract is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`.
> It introduces a bounded lexical/curation layer. It does **not** create a new
> Authority entity type, Graph predicate, semantic evidence source, Article body
> store, Media identity, Video identity or SEO writer.
>
> If any implementation would require a new canonical semantic type or a new
> Graph relation, that change must be proposed through the normal constitutional
> and registry-governance process rather than inferred from this document.

> **Entry/Sense status — 2026-10-04:** Migration015 remains the compatibility
> source. Migration024 Entry/Form/Entry→Sense rows have already been
> materialized for 29 public durable Entries (29 Forms and 29 mappings from
> the original materialization). Do not rerun materialization. The enrichment
> audit/plan/apply tools exist, but enrichment Forms and semantic references
> applied by that tooling remain 0 until canonical read-back proves otherwise.
> Current execution state is owned by `V3_EXECUTION_STATE.md`; the approved
> target and rationale remain in `DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md`.

This contract distinguishes `CURRENT LAW`, `CURRENT IMPLEMENTATION`,
`APPROVED TARGET DESIGN` and `IMPLEMENTATION GAP`. They are not interchangeable.

### Materialization boundary — 2026-10-04

Migration024 is additive schema and the original bounded materialization has
already populated 29 public Entries, 29 Forms and 29 Entry→Sense mappings.
The safe default remains one existing Concept to one Entry while retaining the
Concept UUID as Sense identity; many Concepts to one Entry is curator review
only. Do not run materialization again. Production remains read-only.
The MCP profile/plan operations are bounded diagnostics, while apply is
internal/admin, exact-fingerprint, revision-bound, idempotent and read-back
verified.

## 1. Purpose

Dictionary detection participates in the shared ephemeral interpretation
boundary governed by
`UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`. Its lexical-quality,
longest-span, structural-unit, identifier, proper-name, overlap, numeric and
ambiguity rules are shared planning law; Dictionary remains lexical curation
and does not become semantic identity, Knowledge, Evidence or Graph truth.

The Dictionary capability detects domain terms while NHK creates, researches,
updates or ingests Article, Knowledge, Media/Image and Video content; resolves
those terms to already-owned canonical public destinations whenever possible;
records reviewable lexical candidates when resolution is incomplete; and
projects approved terms into search, internal linking and a public dictionary
hub without duplicating canonical semantic truth.

The governing rule is:

`SEARCH FIRST → RESOLVE → REUSE → CREATE CANDIDATE ONLY IF UNRESOLVED`.

A detected term is never permission to mint a new Authority, Knowledge claim,
Source, Evidence, Graph relation, Media identity, Video identity or public URL.

Dictionary has three product goals:

1. help editors curate the language actually used by collectors/readers;
2. help readers understand terminology and move to the correct canonical owner;
3. improve discovery/internal linking without creating a second semantic truth
   system.

## 2. Lexical objects

The bounded lexical layer has four durable concepts.

### 2.1 Dictionary Concept

A curated lexical concept represents one approved meaning for dictionary use.
It has its own lexical UUID and revision only for dictionary curation. That
lexical UUID is **not** a canonical Authority UUID and does not become a Graph
endpoint merely by existing.

A concept records at minimum:

- `concept_id` — lexical UUID;
- `preferred_label` — one human-approved preferred term;
- `definition` — concise editorial definition, never Evidence by itself;
- `status` — `DRAFT`, `APPROVED`, `RETIRED`;
- optional contextual scope such as locale, professional/community usage or
  bounded domain context;
- optional `destination_type`, `destination_id` and `destination_url` resolved
  through existing owning/public-route boundaries;
- optimistic `revision`, created/updated timestamps and audit actor.

### 2.2 Dictionary Label

A label is a wording attached to one concept. Labels separate the meaning from
its spelling and usage forms.

Label kinds are:

- `PREFERRED` — the preferred display form;
- `ALTERNATE` — accepted synonym/variant;
- `COLLOQUIAL` — community or collector usage;
- `TECHNICAL` — technical-domain form;
- `PHONETIC` — pronunciation/transliteration form;
- `HIDDEN` — resolver/search form not intended for normal public display.

A label may include locale and context qualifiers. One raw string may map to
multiple concepts only when the contextual rules make the ambiguity explicit;
such a label must never auto-link without a unique contextual resolution.

Display text and lookup normalization are separate concerns. Vietnamese display
labels must remain human-readable and must not be replaced by their normalized
lookup form merely to simplify search or URL generation.

### 2.3 Dictionary Candidate

A candidate is the automatic discovery/review object. It is private by default,
non-indexable and non-authoritative.

Candidate states are:

- `DETECTED`
- `RESOLVED_EXISTING`
- `NEEDS_REVIEW`
- `AMBIGUOUS`
- `PROPOSED_NEW`
- `APPROVED`
- `REJECTED`
- `IGNORED`
- `DO_NOT_SUGGEST`

A candidate stores the normalized term, raw observed forms, source contexts,
occurrence count, first/last seen timestamps, resolver suggestions, confidence
signals and review decision. Confidence is advisory only and never substitutes
for ownership/evidence rules.

Detector windows must pass a lexical-quality boundary before they can become a
candidate. The boundary is locale-aware and reusable: it cuts conjunction,
pronoun, auxiliary, preposition, question and clause-continuation tails, and
rejects an empty/non-reusable remainder. It must not contain a list of known
Article phrases or domain-specific exceptions. Frequency only ranks review
priority; it never upgrades a weak segmentation to lexical truth.

`DO_NOT_SUGGEST` is durable suppression: the detector must stop recreating the
same normalized candidate for equivalent context unless a human explicitly
reopens it.

### 2.4 Dictionary Mention

A mention records that a term/concept was observed in a bounded content context
such as a WordPress Article, Knowledge claim, Media/MediaUsage or Video.

A mention is **not** a Graph relation, Evidence, proof of identity or proof of a
semantic relationship. It records lexical occurrence and provenance only. Do
not add a duplicated Entry foreign key merely for reverse lookup;
`mentions_by_entry(entry_id)` can be derived through Entry↔Sense mapping.
Lexical provenance is not Knowledge Evidence.

### 2.5 Entry/Sense target boundary (documentation-only)

The approved target model makes `LexicalEntry` the owner of a lexical form
family and the existing `DictionaryConcept` the durable identity playing the
semantic role `LexicalSense`. The target is:

`LexicalEntry → Forms → 1..N DictionaryConcept-as-LexicalSense`.

`LexicalSense` is a design role, not a new runtime class/table or parallel
owner. Forms are Entry-level by default and may carry a bounded Sense
qualification when the same surface form has multiple meanings. Sense owns
lexical definition, domain/context, usage notes/examples and an optional typed
reference to an existing canonical owner. It does not own an Authority
identity, Knowledge claim, Source/Evidence payload, Graph edge, Media binary or
Video identity.

`LexicalEntry` owns the headword/preferred display form. General forms/aliases
belong to the Entry/Form boundary; Sense-specific forms exist only with a clear
semantic reason. Runtime currently has both
`DictionaryConcept.preferred_label` and `DictionaryLabel(kind=PREFERRED)`,
which can be edited independently. `DictionaryConcept.preferred_label` is a
compatibility field during migration, not a second long-term owner.

**CURRENT IMPLEMENTATION GAP:** preferred wording synchronization is not
solved by the current runtime.

The safe migration default is `1 old Concept → 1 compatibility Entry → 1
Sense`; the old Concept UUID remains durable. This target does not permit
automatic merge or reinterpretation based on preferred/normalized label
equality. Current `preferred_label`, `definition`, `destination_type/id/url`
and context remain compatibility data until a future dry-run mapping
distinguishes one-to-one, multi-sense and destination-review cases.
`PREFERRED` remains a current Label kind; it is not a second owner of the
future preferred form.

The target resolver is `Form → Entry → Sense → semantic_reference(type,id) →
semantic owner → Public Projection → current canonical route`. The
`destination_type`, `destination_id` and `destination_url` fields are only a
compatibility representation during transition; URL is never semantic
identity. Reverse Entity/Classification/Brand/Knowledge/Article → Dictionary
is a derived query/projection, not a persisted mirror array/edge merely for UI.

The current Candidate runtime operations are only `ATTACH`, `CREATE_DRAFT`,
`AMBIGUOUS`, `REJECT`, `IGNORE` and `DO_NOT_SUGGEST`. The target design uses
`CREATE_ENTRY_WITH_SENSE`, `ADD_SENSE_TO_ENTRY`, `ADD_FORM_TO_ENTRY` and
optional `ADD_SENSE_SPECIFIC_FORM`; these are DESIGN / NOT IMPLEMENTED, not
current MCP names.
The review decision remains Dictionary curation and does not create Authority,
Knowledge, Evidence or Graph truth.

### 2.6 Authority Alias versus Dictionary Form

An Authority Alias is an exact identity-resolution asset owned by Authority.
A Dictionary Form is lexical wording owned by Dictionary. The resolver may
read Authority aliases when resolving a term, but Dictionary must not bulk-copy
all Authority aliases. A Dictionary Form is created only when it has explicit
lexical or editorial value.

## 3. Detection sources and trust

Detection is read/planning only. The detector may consume:

- Article topic/title/excerpt/body supplied to the planning boundary;
- approved/current Knowledge claim text;
- Media editorial caption/alt/context metadata;
- OCR, EXIF, filename or visual recognition only as weak observation signals;
- Video title/description/tags and transcript text only when the transcript is
  authorized by the existing Video contract;
- human-supplied hints and curation input.

Article prose, OCR, filename, caption, generated copy, Video title/description,
transcript and model recognition are **not** automatically Evidence and do not
create canonical semantic identity.

Detection must preserve `source_kind`, source identifier, locator/context and
observation strength so reviewers can see why a candidate exists.

### Adapter-specific lexical/query seeds

Every source adapter supplies only lexical/query seeds to Dictionary. The
shared packet preserves the adapter's signal kind and lineage; Dictionary does
not re-parse source formats or become a semantic parser.

| Adapter | Permitted seeds | Required lexical/trust handling |
| --- | --- | --- |
| Human/Chat | `USER_TEXT`, explicit user hint | Preserve exact wording and user lineage; resolve context without treating the assertion as Evidence. |
| Image/Media | `CAPTION`, `ALT_TEXT`, `FILENAME`, `OCR`, `VISUAL_OBSERVATION`, `MODEL_RECOGNITION`, plus bounded `USER_TEXT` | Keep field/extractor/confidence lineage. OCR, filename, recognition, MediaUsage and `depicts` remain lexical/observation signals, not Evidence or canonical identity. |
| Article/News | segmented `SOURCE_TEXT`, quotes, terminology/query seeds, and bounded editorial context | Keep source identity, locator, quote/opinion/editorial classification and derived lineage; do not index a whole Article as Knowledge or corroboration. |
| Video | title, description, tags, transcript segments and timestamped observations | Keep timestamp/segment and ASR lineage; spoken wording is not automatically canonical terminology, Knowledge, Evidence or an alias/relation. |
| Knowledge | canonical claim/subject/facet terminology already read from the owner | Reuse canonical IDs/revisions and provenance; Dictionary does not replace the Knowledge owner or infer new facts. |

For every seed, Dictionary records source kind, source identifier, field or
locator, derivation parent, confidence/uncertainty and provenance family. An
explicit canonical target is a disambiguation context only. It constrains
resolution and must not cause the detector to broaden to unrelated
Brand/Model/Variant candidates.

Source replay is idempotent at the Mention fingerprint boundary. A repeated
source identifier and equivalent lexical context must not create another
occurrence. Derived/generated copies may be observed for diagnostics, but their
lineage/source-family metadata must remain available to curator projections so
one provenance family is not mistaken for independent lexical corroboration.
Candidate detail and Admin review therefore project source mentions and their
contexts from the Mention owner; the aggregate candidate row is not a second
provenance store. A missing unresolved concept is represented as `NULL`; the
all-zero UUID is treated as a legacy/null sentinel on read and is never a valid
Dictionary owner.

### Human/Chat lexical persistence law — 2026-10-04

Human/Chat is a first-class lexical observation source. A valid term observed in
conversation must not depend on a successful Knowledge/Authority/Relation
mutation merely to become durable private lexical review state.

The required behavior is:

```text
Chat text
  → shared lexical-quality filter
  → exact/applicable Dictionary resolution
  → REUSE existing Entry/Sense/Form when unique
  → otherwise private Candidate upsert
  → source provenance / Mention when a durable source binding is available
```

A semantic owner may remain unresolved. In particular,
`SUBJECT_CONFLICT_REVIEW_REQUIRED` on a Knowledge path must not, by itself,
discard a qualified Dictionary observation. The lexical path is allowed to
converge independently because Candidate/Mention are lexical provenance, not
semantic truth.

Persistence remains fail-closed and bounded:

- Candidate upsert is idempotent by normalized term plus bounded context/source
  fingerprint and accumulates observed raw forms without duplicate occurrences;
- the original user wording is preserved even when lookup uses a corrected,
  normalized or alternate form;
- typo/ASR correction is a lookup suggestion, never silent semantic identity;
- known Forms reuse the existing Sense rather than creating a duplicate;
- ambiguous or unknown proper names stay private lexical review state and never
  create Authority automatically;
- no public approval, semantic reference, Knowledge claim, Evidence, Graph
  relation or public URL follows merely from conversational frequency.

If the runtime cannot bind a Mention to a durable source without inventing an
owner, it may persist the Candidate with Capture/source provenance and defer the
Mention. It must not create a fake Article or Knowledge record simply to host a
lexical occurrence.

**CURRENT RUNTIME GAP (2026-10-04):** a live Human/Chat acceptance probe
identified qualified Dictionary delta candidates such as `côn hoa thị` and
reused existing Junghans / wall-clock identities, but the
`KNOWLEDGE_DELTA` Capture stopped at `SUBJECT_CONFLICT_REVIEW_REQUIRED`.
The attempted subject continuation returned
`CAPTURE_SUBJECT_RECONCILIATION_INTENT_NOT_SUPPORTED`, and no durable
Dictionary Candidate was added. This is an implementation gap against the law
above, not desired behavior. See `V3_EXECUTION_STATE.md` for the runtime
receipt.

## 4. Resolution order

For every detected normalized term, the resolver executes in this order:

1. exact approved dictionary label + applicable context;
2. existing destination owned by a current canonical Entity/Public Identity;
3. existing canonical Knowledge/public Knowledge owner where appropriate;
4. existing canonical Article/public editorial owner where appropriate;
5. approved dictionary concept with its own public dictionary destination;
6. otherwise return `UNKNOWN` or `AMBIGUOUS` and create/update a private
   candidate.

The resolver must never derive canonical identity from title, body, slug,
filename, URL, checksum, visual similarity, keyword frequency or AI memory.

If multiple viable destinations remain, resolution is `AMBIGUOUS`; auto-linking
and auto-attachment fail closed while the underlying Article/Media/Video ingest
may continue if no other contract requires the lexical decision.

Repeated resolution of the same approved input/context must be deterministic.

## 5. Canonical destination ownership

One public concept should have one canonical search destination.

Destination preference is:

1. current canonical public Entity page when the concept is the entity itself;
2. current public Knowledge owner when the public page is the appropriate
   knowledge destination;
3. existing canonical Article when it is the approved editorial owner for that
   reader intent;
4. dedicated `/tu-dien/{slug}/` page only when no better existing owner exists.

Dictionary is a resolver and lexical projection, not a URL factory.

If a dictionary page later loses ownership to a better canonical destination,
its old public route must become one direct 301 to the new canonical owner;
internal links must be recomputed to point directly to the new owner; redirect
chains are prohibited.

Media and Video are supporting/illustrative content by default and are not
chosen as the lexical canonical destination merely because a term was detected
inside them.

A delegated concept may remain visible in the `/tu-dien/` hub as a lexical entry,
but its clickable destination and canonical ownership belong to the delegated
owner. The system must not publish a competing indexable dictionary detail page.

## 6. Candidate creation and human curation

Automatic detection may create or increment a **candidate**, never silently
approve a new public concept.

The review inbox must support these decisions:

- attach to existing concept;
- add label/alias to existing concept;
- create a new draft concept;
- approve concept;
- edit preferred label, definition and bounded lexical context;
- delegate canonical destination to an existing approved owner;
- merge duplicate lexical concepts/labels without rekeying the semantic owner;
- mark ambiguous and require context;
- reject;
- ignore;
- do not suggest again;
- request AI-assisted comparison/questioning without granting the AI semantic
  write authority.

AI-assisted review may summarize occurrences, contrast likely meanings and ask
targeted questions. Its generated explanation is advisory editorial content,
not Evidence and not an approval event.

Curation should expose enough provenance for the editor to see observed forms,
where the term appeared, current suggestions, destination candidates and why a
candidate is ambiguous before deciding.

## 7. Duplicate prevention

Before any new concept proposal, the system must search:

- approved dictionary labels and normalized hidden forms;
- current Authority/public identity inventory;
- current Knowledge claims/pages;
- existing canonical Articles and intent-overlap results;
- suppressed/rejected candidates.

A factual statement already represented by current Knowledge must be reused or
enriched through Living Knowledge rather than duplicated because a dictionary
term was detected.

A dictionary concept may point to an existing owner without creating a second
public page.

Aliases, colloquial forms, phonetic forms and technical forms that resolve to
one meaning should normally remain labels of one concept rather than being
published as multiple equivalent concepts.

## 8. Article integration

Article research preflight adds a `dictionary_plan` section containing:

- `resolved_terms`;
- `ambiguous_terms`;
- `candidate_terms`;
- `internal_link_candidates`;
- `warnings`.

Dictionary detection runs after runtime/site inventory is available and before
final internal-link/SEO planning. It is read-only for Article preflight.

Unknown or review-pending dictionary candidates **do not by themselves block**
Article draft/publication. Ambiguous terms simply do not auto-link. Other
semantic, evidence, compliance, Media and publication gates remain unchanged.

A published Article body is never silently rewritten to insert lexical links.
The stored WordPress body remains editorial ownership. Dictionary linking is a
render/public projection or a separately approved editorial update.

## 9. Knowledge integration

Knowledge/Living Knowledge must resolve existing current truth before proposing
new claims. Dictionary labels can help lexical matching/disambiguation but do
not create Knowledge claims or Evidence.

A dictionary definition is editorial lexical copy. If review discovers a new
factual assertion that should become Knowledge, it must be handed to the
existing Living Knowledge planner and Governance lifecycle with normal Source /
Evidence requirements.

A public dictionary page may point readers to existing Knowledge, but it must
not copy a Knowledge claim into lexical storage and then present the copied text
as an independently sourced fact.

## 9.1 Dictionary as the lexical bridge for enrichment and writing

Dictionary provides the controlled language layer between canonical semantic
truth and natural editorial wording. It does not become the truth owner.

For Article, Media, Video and conversational enrichment:

```text
eligible Knowledge Claim
  → concept expressed by the Claim
  → Dictionary Sense
  → accepted Forms / register
  → bounded keyword/query variants
  → natural reader-facing wording
```

The bridge is bidirectional for lookup but one-way for truth. Reader wording,
search keywords, colloquial labels and technical labels can help resolve a
Sense and find the canonical owner; they cannot create a Claim. A Dictionary
definition may explain lexical meaning, but factual statements still come from
the owning Knowledge/Authority boundaries with their scope/provenance/evidence.

Writers may use preferred, alternate, colloquial or technical Forms only when
they resolve to the same applicable Sense. Register selection should make prose
natural for the audience rather than mechanically repeating one exact keyword.
Unknown or review-pending Forms may be quoted as user/community wording with
clear lineage when editorially useful, but they are not presented as approved
canonical terminology.

Keyword clustering must therefore be Sense-aware: variants that resolve to one
Sense can support one reader intent cluster, while identical strings that
resolve to different Senses remain separated. Keyword popularity or frequency
never merges Senses, creates semantic references or overrides ambiguity.

## 10. Media/Image integration

Media ingestion and identity remain governed by the Media contracts.

Dictionary detection may read permitted caption/alt/editorial context and weak
observations such as OCR/filename/recognition to produce candidate mentions.
Those signals never create a semantic `depicts` relation, Knowledge claim or
Evidence automatically.

An approved concept may select existing eligible Media as an illustration
through the existing MediaUsage/projection boundary. The image is reused; it is
not copied into a dictionary-owned binary store.

**CURRENT IMPLEMENTATION:** reads follow
`DictionaryRuntime → EntityMediaProjection->forEntity('dictionary_concept',
conceptId)` when a stored MediaUsage endpoint exists. The normal governed write
path is `MediaTargetNormalizer → MediaTargetRegistry → Graph
EndpointTypeRegistry`, and Dictionary is not a Graph endpoint. Normal governed
Dictionary Media binding writes are therefore incomplete.

**STATUS: IMPLEMENTATION GAP.** Do not resolve this by making Dictionary a
Graph endpoint. A future lexical/presentation target registry or bounded
MediaUsage target validation is deferred to a later implementation plan.

A dictionary illustration is presentation context only and does not prove the
term's definition or semantic relation.

## 11. Video integration

Video intake preview/preflight may detect terms from authorized source metadata
and transcripts and may use an explicit validated Video semantic target as
context for disambiguation.

It must not broaden an explicit target or infer that every term in title,
description or transcript is an alias/relation of that target.

Dictionary candidates created from Video remain planning/curation objects and
never bypass Video Governance, Knowledge Governance or relation evidence rules.

Video transcript segments, ASR alternatives and timestamped observations are
distinct lexical inputs. A transcript correction may produce a new query seed
with recoverable lineage; it does not rewrite the source transcript or create
an alias automatically. A target hint narrows lookup only and never makes
every matching word in the transcript a label, relation or fact about that
target.

No `Video --about--> DictionaryConcept` is permitted. A future video
pronunciation/explanation/illustration association would be lexical or
presentation scope outside Graph; direct Sense↔Video binding is deferred from
the first implementation slice.

## 11.1 Knowledge destination status

**CONTRACT TARGET:** Entity, Knowledge and Article destinations are allowed
through typed semantic references.

**CURRENT IMPLEMENTATION:** the resolver has Knowledge lookup, but curation
approval primarily accepts Authority entity types and delegated destination
revalidation handles Authority and Article, not Knowledge fully.

**STATUS: IMPLEMENTATION GAP.** Knowledge delegation is not READY.

## 12. Auto-link projection

Only approved labels with exactly one eligible public destination may auto-link.

The linker must:

- use longest-phrase-first matching;
- honor lexical boundaries and context;
- avoid nested/overlapping links;
- skip headings, existing anchors, code/preformatted text and administrative
  content;
- normally link the first occurrence of a concept per Article/page;
- never link an ambiguous or review-pending candidate;
- use the resolved canonical destination directly, not a compatibility URL;
- revalidate delegated destination/public eligibility at read time;
- never mutate semantic truth because a link was rendered.

The stored WordPress Article body remains unchanged unless an independent
editorial write is explicitly approved.

When a canonical destination changes, future render projection must point
straight to the current canonical owner instead of deliberately preserving an
old redirect as an internal link.

## 13. Search and public dictionary hub

Search may expand approved labels/aliases to their concept/destination. Draft,
ambiguous, rejected, ignored and suppressed candidates are excluded from public
search expansion.

The approved target public detail route is `/tu-dien/{entry-slug}/` and is
Entry-centric; one Entry may render many Senses. If multiple Senses delegate to
different owners, the page is a lexical disambiguation page and each Sense
links to its owner. If one Sense delegates completely to one owner, do not
create a competing indexable Dictionary detail page: hub/search may link
directly to the owner. Any legacy Dictionary URL redirects in one direct 301
hop, never a chain.

**CURRENT IMPLEMENTATION:** detail remains Concept-centric by one
`public_slug`; multiple Concepts with the same slug are `AMBIGUOUS`. The
Entry-centric route is not runtime behavior.

The hub is a first-class discovery surface and should support reader-oriented
browse/search such as preferred label, aliases and bounded lexical grouping when
those fields are actually curated. It must not manufacture taxonomies or
semantic classifications merely to create navigation.

Public search should match approved preferred/alternate/colloquial/technical or
phonetic labels according to current resolver rules, while hiding private
candidate and hidden administrative state from readers.

## 14. SEO and structured data

SEO remains projection-only and must reuse the canonical owner selected above.
Dictionary may not create/change canonical UUIDs, Public Identity, Knowledge,
Graph, Media, Video or semantic facts.

Eligible dedicated dictionary pages may project Schema.org `DefinedTerm` /
`DefinedTermSet` semantics when visible content supports them. This is a
structured-data projection only. The implementation may use W3C SKOS label and
relationship concepts as design reference, but no external vocabulary is a
runtime authority or automatic Graph writer.

Sitemap/indexability rules apply normally. Do not index draft, ambiguous,
rejected, ignored, suppressed, duplicate, redirected or owner-delegated
non-canonical dictionary pages.

Standalone dictionary public slugs must use the current shared public URL/slug
contract. The lexical UUID, semantic owner UUID, internal database IDs or
technical source keys are not SEO slug material.

Canonical, Open Graph URL, structured-data URL, sitemap URL and internal links
for a standalone dictionary page must resolve to the same canonical path.

## 15. MCP and Admin control plane

MCP/Admin are orchestration/input surfaces, not lexical truth stores.

Read surfaces may expose dictionary search, candidate inbox and concept detail.
Semantic/curation mutation must use dedicated bounded operations and
capabilities with authorization, optimistic revision, idempotency and read-back.

No generic WordPress Post/CPT/taxonomy/postmeta writer may substitute for the
Dictionary repository or for existing Authority/Knowledge/Graph governance.

Dictionary curation operations must not be represented as Knowledge/Graph
proposal approval unless they actually mutate those bounded contexts. If a
curation decision additionally proposes a Knowledge/Graph change, that
secondary change enters the existing Proposal → Human Approval → Eligibility →
Controlled Apply lifecycle separately.

A usable curator workspace should expose, subject to current runtime capability:

- candidate queue by state and recency;
- exact/alias lookup before create;
- occurrence/source-context inspection;
- resolve/delegate/merge/reject/suppress decisions;
- concept and label editing with optimistic revision;
- preview of public destination and auto-link effect;
- read-back/audit result after a curated write.

Code presence alone is not proof that a dedicated Dictionary MCP action is live;
runtime availability must be confirmed by current catalog/discovery.

## 16. Runtime/storage requirements

Dictionary persistence must be isolated from semantic canonical stores. At
minimum it needs dedicated concept, label, candidate and mention persistence or
an equivalent schema with the same ownership separation.

Required invariants:

- stable lexical UUIDs;
- optimistic revision for curated records;
- normalized-label uniqueness scoped by context/meaning rules;
- idempotent candidate upsert by normalized term + bounded context;
- occurrence accumulation without duplicate mention rows;
- durable suppression;
- explicit destination owner/type/id/url snapshot plus read-time revalidation;
- no binary duplication;
- no Article body duplication;
- no semantic Graph edge hidden in lexical persistence.

Runtime unavailability must be reported as unavailable; it must not be rendered
as an honest empty dictionary.

## 17. Backfill

Existing Article, Knowledge, Media and Video content may be scanned only in a
read-only/dry-run first phase.

The dry-run report must separate at least:

- resolved existing terms;
- candidate new terms;
- ambiguous terms;
- suppressed terms;
- source counts by Article/Knowledge/Media/Video;
- no-write confirmation.

Bulk apply must never auto-approve new public concepts. It may only persist
mentions/candidates and approved deterministic reuse allowed by this contract.

Backfill must be replay-safe. A second scan of unchanged content should not
create duplicate mentions or duplicate candidates.

## 18. Functional capability requirements

Dictionary implementation is expected to provide the following bounded
capabilities. These are product/UX requirements inside the ownership rules above;
they do not expand semantic authority.

### 18.1 Reader-facing capabilities

- browse the public Dictionary hub;
- search by approved preferred and accepted alternate lexical forms;
- open a dedicated Dictionary detail page only when Dictionary owns the reader
  destination;
- follow owner-delegated entries directly to the current canonical owner;
- see concise lexical definition/explanation, approved aliases and contextual
  usage information where curated;
- see eligible representative/illustrative Media when available through the
  existing Media projection;
- discover approved Dictionary terms contextually from Article and Entity detail
  surfaces without duplicating the owning content;
- return a clear no-match state instead of inventing a definition or destination.

### 18.2 Editor/curator capabilities

- inspect unresolved and ambiguous candidate terms;
- inspect raw forms, normalized form, occurrences and source contexts;
- search/reuse an existing concept or canonical owner before create;
- add/retire labels without changing semantic owner identity;
- create a draft lexical concept;
- approve/retire a lexical concept through the dedicated curation boundary;
- delegate a concept to an existing canonical owner;
- merge/reconcile duplicate lexical concepts where ownership is lexical;
- reject, ignore or durably suppress unwanted suggestions;
- preview public destination and projected internal-link behavior before a
  consequential curation decision;
- receive explicit conflict/unavailable states instead of silent fallback.

### 18.3 Contextual projection capabilities

Dictionary may enrich these public/read surfaces when approved data exists:

- homepage Dictionary module/highlights;
- `/tu-dien/` browse/search hub;
- standalone Dictionary detail pages;
- Article contextual terms/sidebar/rail;
- Entity dossier contextual terms/sidebar/rail;
- site search alias expansion;
- render-time internal lexical linking.

A contextual list is a lexical aid, not a claim that every displayed term is a
semantic child/relation of the current page.

## 19. Public UX and presentation contract

Dictionary public UX is Vietnamese-first, reader-oriented and intentionally
lighter than a semantic dossier.

A standalone Dictionary detail page should project, when available and actually
owned by that concept:

1. preferred label;
2. concise definition/explanation;
3. accepted alternate/colloquial/technical/phonetic labels suitable for public
   display;
4. bounded usage/context note when needed for disambiguation;
5. canonical owner link when ownership is delegated rather than duplicated;
6. eligible illustrative Media;
7. related public reading destinations only when produced by an existing
   approved read/projection boundary;
8. clear canonical/indexability state.

The page must not expose internal lexical UUIDs, revisions, candidate confidence,
private review notes, source-system IDs or hidden resolver labels as reader copy.

Contextual Dictionary widgets on homepage, Article and Entity pages should be
compact discovery components. They must deduplicate concepts, prefer the
approved public label, and never display a private/ambiguous candidate as if it
were an approved term.

When no eligible public term exists, the component should be omitted or report
an unavailable/no-match state appropriate to the host surface; it must not fill
space with invented lexical content.

### 20.1 Issue #21 executable reconciliation

The canonical runtime path is now explicit: Article research/Capture preview,
Knowledge writes, Media attachment observations and Video writes all enter
`DictionaryObservationRegistry`; the registry delegates to the existing
`DictionaryHarvester`, which delegates to `DictionaryPlanningService`. The
harvester persists only Dictionary mentions/candidates on an observation path
and never writes semantic truth. Dry-run uses the same harvester with
`persist=false`.

The executable MCP read surface includes approved search, lexical resolve,
Concept detail/labels, candidate queue/detail, mention/source context and a
bounded live profile containing storage/readiness/coverage/public preview.
Curated writes remain the dedicated revision/idempotency/audit boundary. Admin
uses the same mutation service. Relation handoff validates existing owner and
predicate registries then returns a Governance-ready review packet; it never
writes Graph.

The owner-backed projection is read-only and revalidated at the owner boundary:
Dictionary lexical entry → canonical owner → existing Graph/Knowledge/Media or
Video read projections → coverage/readiness. No factual claim is copied into
Dictionary and no Dictionary row is treated as an Authority or Graph endpoint.

## 20. Acceptance criteria

The capability is not READY until tests and runtime read-back demonstrate:

1. an existing approved term reuses one canonical destination;
2. an approved alias resolves to the same concept/destination as its preferred
   label;
3. an unknown term creates/updates one private candidate without semantic
   writes;
4. an ambiguous term does not auto-link;
5. durable suppression prevents equivalent candidate recreation;
6. longest-phrase-first linking avoids nested links and changes no stored body;
7. Article research exposes dictionary planning without making candidates a
   publication blocker;
8. Knowledge reuse does not mint duplicate claims;
9. Media weak observations create candidates only;
10. Video metadata/transcript observations create candidates only and preserve
    explicit semantic target scope;
11. search expands approved aliases only;
12. owner-delegated dictionary entries do not create duplicate indexable pages;
13. a dedicated dictionary page exists only when Dictionary owns the canonical
    reader destination;
14. dedicated dictionary pages have one canonical URL and correct indexability;
15. homepage/Article/Entity contextual Dictionary projection emits only approved
    public concepts and deduplicates repeated labels;
16. delegated terms link directly to the current canonical owner, not an old
    compatibility/redirect URL;
17. changing a label or lexical definition does not rekey the delegated
    semantic owner;
18. hidden/private/candidate state is absent from public archive, search,
    contextual widgets, sitemap and indexable detail projection;
19. all curated writes enforce authorization/revision/idempotency/read-back;
20. repeated unchanged scans/projections are deterministic and replay-safe;
21. canonical/Open Graph/schema/sitemap/internal-link URL surfaces agree for a
    standalone Dictionary page;
22. no Dictionary operation implicitly creates Authority, Knowledge, Source,
    Evidence or Graph truth;
23. runtime failure is surfaced as unavailable, never an empty success.

## 20.2 Dictionary Seed v1 read-only planner

### 20.2.1 Bounded canonical corpus audit

The existing `nhk.dictionary.seed-audit` read operation also accepts a
bounded `source_scope` of `KNOWLEDGE`, `ARTICLE` or `ALL`, plus an opaque
deterministic cursor and a maximum page size of 100. The server reads
canonical source text internally, preserves source identity/family and raw or
derived lineage in the ephemeral packet, then interprets with the shared
`StructuredSemanticInterpreter` and aggregates through `DictionarySeedPlanner`.
Private claim/article text, raw forms and source bodies are never serialized to
MCP. The result is planning-only and always declares `read_only=true` and
`mutated=false`.

The aggregate is keyed by normalized lexical seed and reports occurrences,
distinct source/family counts, resolution, ambiguity, destination IDs and one
of the existing actions `REUSE_EXISTING`, `ADD_ALIAS_CANDIDATE`,
`NEW_CONCEPT_CANDIDATE`, `REVIEW_AMBIGUITY`, `SUPPRESS_EDITORIAL` or
`SUPPRESS_NOISE`. The legacy Dictionary candidate queue is read only for a
bounded comparison map; it is not an interpretation input or Seed v1 source of
truth. No candidate, concept, label, Knowledge, Evidence, Graph or Authority
record is changed.

The shared `StructuredInterpretationPacket` is the Dictionary planning input.
`DictionarySeedPlanner` applies `SEARCH FIRST → RESOLVE → REUSE → CANDIDATE
ONLY IF UNRESOLVED` over semantic query seeds and does not run a second parser.
It preserves normalized deduplication, raw observed forms, source-family
lineage and occurrence counts without treating frequency as authority.

For a `CONFIGURATION` seed that already passed the shared structural-unit
eligibility boundary, the observed form is resolved first. Only when that exact
lookup is `UNKNOWN` may planning try a bounded syntax-derived lookup variant.
The current generic shape is `N unitA N unitB → unitA N unitB` when the two
cardinalities are equal. This is lookup-only: reuse still requires one exact
canonical resolver result, while the observed wording remains an alias/Form
candidate. Unequal cardinalities, ambiguity and unsupported shapes remain
reviewable; no domain-specific vocabulary, fuzzy similarity or model memory may
establish identity.

The planner reports existing reuse/aliases, proper names, identifiers,
configurations, technical/colloquial/phonetic observations, ambiguity,
unresolved candidates, editorial/noise and suppression using existing runtime
vocabulary. It is ephemeral and read-only: it never approves, attaches,
creates a concept/label, writes Knowledge/Evidence or writes Graph.

The internal/admin-only `nhk.dictionary.seed-audit` operation exposes bounded,
privacy-safe pagination/filtering and aggregate counts with explicit
`read_only=true`, `mutated=false` and unavailable handling. It is not in the
public operator allowlist.

## Entry/Sense runtime checkpoint — 2026-10-04

Migration 024 remains additive while Migration015 stays the compatibility
source. The original bounded materialization has already produced 29 public
Entries, 29 initial Forms and 29 Entry→existing-Concept/Sense mappings; do not
run that materialization again. `DictionaryConcept` remains the durable Sense
identity, and compatibility fallback remains read-only.

`DictionaryEntrySenseResolver` keeps the bounded read path
`normalized Form → Entry → approved Sense → revalidated owner route`. One
viable Sense resolves; multiple viable Senses remain `AMBIGUOUS`; stale or
invalid owner routes fail closed. Mapping-level `semantic_reference` is
authoritative over the legacy destination snapshot. Dictionary still creates
no Graph endpoint, predicate, Evidence store, Knowledge copy, Media relation or
Video relation.

The current source catalog and dispatch include guarded internal/admin Entry
creation, Form addition, Sense attachment and enrichment audit/plan/apply
operations. Connector exposure is a separate deployment/configuration fact and
must be verified after release. Preferred-wording synchronization,
context-qualified Sense filtering, complete Knowledge destination lifecycle,
governed Dictionary Media binding and Video association remain
`IMPLEMENTATION GAP`.

All future source adapters enter Dictionary through the same
`StructuredInterpretationPacket`: physical ingest → source adapter → Shared
Semantic Core → Dictionary resolution → canonical semantic retrieval →
Knowledge reuse → relation discovery → enrichment → Writer/read-back. A
Dictionary match remains lexical discovery only; it is never semantic identity,
Evidence, Knowledge or a Graph relation, and Graph reachability never proves
applicability.
