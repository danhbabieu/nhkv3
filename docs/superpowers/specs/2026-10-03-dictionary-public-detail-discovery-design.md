# Dictionary Public Detail Discovery Design

> **STATUS: IMPLEMENTED DESIGN RECORD**
>
> This file remains the architectural design record for the Dictionary public
> detail work. Its historical “design only” language records the authorization
> boundary at design time; implementation was completed after that design was
> written. Do not use this file to determine current execution state. Read:
> `docs/architecture/V3_EXECUTION_STATE.md`,
> `docs/architecture/DICTIONARY_ENRICHMENT_AUDIT_OPERATIONS.md`, and the
> **Dictionary — Current Handoff / Resume Here** section in the execution
> state.

## Decision

Build an Entry-centric public Dictionary detail projection. Dictionary remains
the lexical owner; Authority, Knowledge, Graph, Media, Video and WordPress
Article retain their canonical ownership. Dictionary Entry/Sense never becomes
a Graph endpoint or semantic relation owner.

The read precedence is durable Entry → Sense mapping first, followed by an
explicit read-only Concept compatibility fallback. Semantic data is projected
per Sense and is never promoted from the first Sense to Entry level.

## Read model

Each public Sense exposes lexical data, a `semantic_reference` object, and
bounded owner projections. A semantic reference contains `status`, `type`,
`id`, `revision`, `source` and resolved `canonical_url`. Mapping data is the
authoritative reference; Concept destination fields are compatibility data only.

The public detail packet contains Entry metadata, Forms, independent Sense
blocks, SEO state, breadcrumbs and per-Sense sections for owner, Knowledge,
Media, Video, Articles, Graph relations, derived entities, related terms and
lexical Mentions. Sections distinguish `AVAILABLE_WITH_ITEMS`,
`AVAILABLE_EMPTY`, `UNAVAILABLE_IMPLEMENTATION_GAP` and `BLOCKED`.

## Write model

An existing Entry/Sense mapping is updated through a governed Dictionary
operation using Entry revision as the aggregate CAS token. The operation
validates the canonical target, is idempotent, records audit data, updates only
the mapping, and performs canonical read-back. No Graph edge or owner mutation
is created.

## Discovery rules

Mentions are reverse-readable by Concept/Sense and remain lexical attestations,
never Evidence or semantic relations. Related terms use only the same canonical
owner, an approved bounded Graph path between canonical owners, or a future
explicit Dictionary lexical relation. Keyword similarity, embeddings, shared
Articles, shared Mentions and AI inference are forbidden.

## SEO

An owner-only Entry redirects directly to its canonical owner. A standalone
lexical Entry is indexable. A rich owner-backed lexical page may remain useful
with `noindex,follow`, but the owner remains canonical. Multi-Sense Entries
with different owners are lexical disambiguation pages and keep Sense-qualified
owner links.

## 400-day target

`400 ngày` exposes its approved Forms and, when available, the existing
canonical Clock Type owner `Đồng hồ 400 ngày` through read projection only.
Knowledge, Media, Video, Article, derived Brand/Model/Specimen and Mention
sections render only when their owning projections return eligible public data.
No counts or content are invented.

## Constraints

This design does not authorize data materialization, migration, direct SQL
outside repositories, Graph edge creation, V2/production mutation, deployment,
push or merge.
