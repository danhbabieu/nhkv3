# NHK V3 Dictionary Lexical Knowledge Contract

> **APPROVED SUBORDINATE CONTRACT — 2026-09-05.**
> This contract is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`.
> It introduces a bounded lexical/curation layer. It does **not** create a new
> Authority entity type, Graph predicate, semantic evidence source, Article body
> store, Media identity, Video identity or SEO writer.
>
> If any implementation would require a new canonical semantic type or a new
> Graph relation, that change must be proposed through the normal constitutional
> and registry-governance process rather than inferred from this document.

## 1. Purpose

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

`DO_NOT_SUGGEST` is durable suppression: the detector must stop recreating the
same normalized candidate for equivalent context unless a human explicitly
reopens it.

### 2.4 Dictionary Mention

A mention records that a term/concept was observed in a bounded content context
such as a WordPress Article, Knowledge claim, Media/MediaUsage or Video.

A mention is **not** a Graph relation, Evidence, proof of identity or proof of a
semantic relationship. It records lexical occurrence and provenance only.

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

## 6. Candidate creation and human curation

Automatic detection may create or increment a **candidate**, never silently
approve a new public concept.

The review inbox must support these decisions:

- attach to existing concept;
- add label/alias to existing concept;
- create a new draft concept;
- approve concept;
- mark ambiguous and require context;
- reject;
- ignore;
- do not suggest again;
- request AI-assisted comparison/questioning without granting the AI semantic
  write authority.

AI-assisted review may summarize occurrences, contrast likely meanings and ask
targeted questions. Its generated explanation is advisory editorial content,
not Evidence and not an approval event.

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

## 10. Media/Image integration

Media ingestion and identity remain governed by the Media contracts.

Dictionary detection may read permitted caption/alt/editorial context and weak
observations such as OCR/filename/recognition to produce candidate mentions.
Those signals never create a semantic `depicts` relation, Knowledge claim or
Evidence automatically.

An approved concept may select existing eligible Media as an illustration
through the existing MediaUsage/projection boundary. The image is reused; it is
not copied into a dictionary-owned binary store.

## 11. Video integration

Video intake preview/preflight may detect terms from authorized source metadata
and transcripts and may use an explicit validated Video semantic target as
context for disambiguation.

It must not broaden an explicit target or infer that every term in title,
description or transcript is an alias/relation of that target.

Dictionary candidates created from Video remain planning/curation objects and
never bypass Video Governance, Knowledge Governance or relation evidence rules.

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
- never mutate semantic truth because a link was rendered.

The stored WordPress Article body remains unchanged unless an independent
editorial write is explicitly approved.

## 13. Search and public dictionary hub

Search may expand approved labels/aliases to their concept/destination. Draft,
ambiguous, rejected, ignored and suppressed candidates are excluded from public
search expansion.

The public `/tu-dien/` hub lists approved dictionary concepts and approved
labels. If a concept delegates ownership to an existing Entity, Knowledge or
Article, the hub item links directly to that owner. A dedicated dictionary
route exists only for concepts whose canonical destination is the dictionary
page itself.

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

## 18. Acceptance criteria

The capability is not READY until tests and runtime read-back demonstrate:

1. existing approved term reuses one canonical destination;
2. unknown term creates/updates one private candidate without semantic writes;
3. ambiguous term does not auto-link;
4. suppression prevents candidate recreation;
5. longest-phrase-first linking avoids nested links and changes no stored body;
6. Article research exposes dictionary planning without making candidates a
   publication blocker;
7. Knowledge reuse does not mint duplicate claims;
8. Media weak observations create candidates only;
9. Video metadata/transcript observations create candidates only and preserve
   explicit semantic target scope;
10. search expands approved aliases only;
11. owner-delegated dictionary entries do not create duplicate indexable pages;
12. dedicated dictionary pages have one canonical URL and correct indexability;
13. all curated writes enforce authorization/revision/idempotency/read-back;
14. runtime failure is surfaced as unavailable, never an empty success.
