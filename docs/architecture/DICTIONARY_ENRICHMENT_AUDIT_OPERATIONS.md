# Dictionary Enrichment Audit Operations

## Boundary

Dictionary enrichment has two separate write boundaries:

- Lexical enrichment owns Entry Forms and existing Entry/Sense semantic
  references through the Dictionary mutation service.
- Canonical-owner enrichment owns Knowledge, Graph, Media/MediaUsage, Video
  and Article relations through their existing owner/Governance pipelines.

“More content” is not a reason to invent a semantic relation. A Mention is
lexical discovery only; it does not become an Article-about, Video-about,
Knowledge claim or Graph edge automatically.

## Audit

`nhk.dictionary.enrichment.audit` is an internal, bounded, read-only ability.
It accepts `limit` (1–100), an opaque cursor and optional exact Entry/Sense
scope. It returns public Entry/Sense identity, Forms, semantic-reference
state, owner readiness, bounded owner coverage, Mention counts by source kind,
RelatedTerm coverage, classification and next action. Unavailable, blocked and
ambiguous infrastructure are distinct from an empty result.

Audit classifications are:

`COMPLETE`, `LEXICAL_GAP`, `OWNER_GAP`, `KNOWLEDGE_GAP`, `MEDIA_GAP`,
`RELATION_GAP`, `MENTION_ONLY`, `STANDALONE_LEXICAL`, `AMBIGUOUS` and
`BLOCKED`.

Audit never runs from public hub/detail/search requests and never mutates
storage. The public projection remains bounded and may render a standalone
lexical Entry when owner enrichment is absent.

## Practical runbook

The following sequence is the safe operational handoff. Each stage must stop
on its stated condition; an unavailable provider is never converted to an
empty result.

### A. Audit

- **Mode:** read-only; no Dictionary or owner data is mutated.
- **Expected status:** bounded packet with explicit item and coverage status,
  `read_only=true`, and `mutated=false`.
- **Safe stop:** stop if deployed build, schema mode, exact scope or provider
  availability is not verified.
- **Failure behavior:** fail closed with an explicit runtime/infrastructure
  error or blocked packet; do not continue as if the corpus were empty.

### B. Plan

- **Mode:** read-only deterministic planning; no mutation.
- **Expected status:** plan fingerprint plus `READY`, `REVIEW_REQUIRED`,
  `BLOCKED` or `NOOP` actions.
- **Safe stop:** stop when evidence is not `EXACT_UNIQUE`, the snapshot is
  stale, or a dependency is unavailable.
- **Failure behavior:** retain the action as `REVIEW_REQUIRED`/`BLOCKED`; never
  downgrade uncertainty to a best guess.

### C. Review statuses

- **Mode:** read-only review; no mutation.
- **Expected status:** every action is explicitly accepted as `READY`, left
  `REVIEW_REQUIRED`, retained as `BLOCKED` or recognized as `NOOP`.
- **Safe stop:** do not approve label similarity, inferred identity or any
  owner-pipeline write from this Dictionary plan.
- **Failure behavior:** leave the action non-ready and record the reason.

### D. Apply

- **Mode:** mutating Dictionary lexical/mapping data only.
- **Expected status:** exact fingerprint, current revision, idempotency key,
  canonical mutation receipt and read-back.
- **Safe stop:** stop on fingerprint drift, CAS conflict, unavailable storage,
  missing capability, invalid target or read-back failure.
- **Failure behavior:** fail closed; do not retry with a new plan or bypass
  CAS/idempotency/audit/read-back.

Apply must not mutate Graph, Knowledge, Media, Video, Article or Authority.
Those systems can change only when their own canonical subsystem is invoked
separately through its governed workflow.

### E. Read-back

- **Mode:** read-only canonical verification after Apply.
- **Expected status:** exact Entry/Form/Sense revision and applied lexical or
  mapping state returned by the canonical owner.
- **Safe stop:** stop if read-back is unavailable, stale or differs from the
  receipt.
- **Failure behavior:** report indeterminate/failed verification; never claim
  applied state from an acknowledgement alone.

### F. Re-audit

- **Mode:** read-only.
- **Expected status:** fresh audit reflects only verified changes and keeps
  unrelated owner coverage unchanged.
- **Safe stop:** stop if build identity or audit snapshot is stale.
- **Failure behavior:** preserve the prior state as unverified and do not
  launch another Apply.

### G. Public acceptance

- **Mode:** read-only browser/public projection verification.
- **Expected status:** deployed source revision is verified; public Forms,
  Sense-qualified sections, SEO state and empty/unavailable states match the
  canonical read-back.
- **Safe stop:** stop if deployed SHA is unknown or pages expose stale/mixed
  Sense data.
- **Failure behavior:** classify as deployment/readiness or projection gap;
  do not mutate an owner merely to fill the UI.

## Owner resolution

Evidence is evaluated in this order:

1. existing explicit legacy destination;
2. exact canonical stable identity;
3. exact approved alias;
4. same curated external terminology;
5. existing governed Graph/Knowledge provenance;
6. unique resolver result.

The result is `EXACT_UNIQUE`, `AMBIGUOUS`, `NO_OWNER`, `OWNER_MISSING` or
`CONFLICT`. Label similarity, keyword overlap, AI similarity, URL, slug,
filename, OCR, transcript wording and frequency are not sufficient evidence.

## Persisted semantic reference versus owner hint — 2026-10-08

Audit and planning must expose two distinct concepts:

- `persisted_semantic_reference`: mapping-level truth loaded from the
  Dictionary Entry/Sense mapping repository;
- `owner_hint`: advisory context/curator metadata that may help owner
  resolution but is not a persisted mapping.

Operational rules:

| Persisted mapping | Owner hint / resolution | Required plan result |
|---|---|---|
| absent | exact unique owner | `SET_SEMANTIC_REFERENCE` may be `READY` |
| valid + revalidated | same owner | semantic-reference mutation is `NOOP` |
| stale/invalid | any | fail closed to blocked/review-required |
| valid | conflicting hint | `REVIEW_REQUIRED`; never silently replace mapping |
| absent | ambiguous/no owner | review/standalone lexical according to current contract |

Context metadata alone must never be serialized or reported as
`persisted_semantic_reference`. Conversely, a real mapping remains
authoritative even when no context hint is present.

### Delegated-detail acceptance

After a valid semantic mapping is applied and revalidated, public read-back must
agree across hub, resolver, detail presentation and SEO:

- resolver/internal-link destination = canonical owner URL;
- detail route mode = `DELEGATED`;
- lexical detail canonical = canonical owner absolute URL;
- robots = `noindex,follow`;
- sitemap eligibility = false.

Standalone lexical Entries remain `DEDICATED` and indexability is unchanged.

Runtime acceptance on 2026-10-08 verified both classes with generic behavior:
delegated Music-backed Dictionary terms resolve to their canonical Music owners,
while standalone configuration descriptors remain dedicated/indexable. The
acceptance is evidence of the generic contract, not a term-specific exception.

## Plan

`nhk.dictionary.enrichment.plan` creates a deterministic plan from an audit
snapshot. Its fingerprint is the SHA-256 hash of the canonicalized ordered
actions. Actions are limited to:

- `ADD_ENTRY_FORM`;
- `SET_SEMANTIC_REFERENCE`;
- `NOOP`;
- `REVIEW_REQUIRED`.

Only approved durable labels can produce Form actions. Candidate/private
observations, article text, Mentions, guessed translations and unsupported
hidden Form semantics are rejected or blocked. Duplicate Forms are `NOOP`.
Only `EXACT_UNIQUE` owner matches can produce a ready semantic-reference
action. Stale or invalid references remain blocked/review-required.

The 400-day Entry/Sense is not special-cased. Its terminology can produce a
ready action only when the same generic evidence resolver proves the existing
classification owner exact and unique.

## Apply

`nhk.dictionary.enrichment.apply` is internal/admin-only. It requires:

- a `READY` plan;
- an exact approved fingerprint matching the action set;
- the current Entry/Sense revision;
- a non-empty idempotency key.

Form and semantic-reference writes delegate to `DictionaryMutationService`,
which performs CAS, idempotency and canonical read-back. Apply records result
packets and never writes Graph, Knowledge, Media, Video, Article or Public
Identity data. Fingerprint drift, CAS conflict, unavailable storage and
read-back failure fail closed.

## Owner handoff

Knowledge, Graph, Media, Video and Article gaps are diagnostic candidates only.
Each candidate must use the owning pipeline's registered vocabulary,
provenance, revisions, readiness and Governance lifecycle. Related Terms are
derived from same-owner or bounded canonical Graph projections; Dictionary does
not persist a parallel relation system.

## Operational verification

Run the focused Dictionary and MCP suites, changed-file PHP lint, `git diff
--check` and changed-scope secret review. Runtime/database unavailability must
produce a deterministic plan or explicit unavailable status; it must never be
reported as applied enrichment.

## 29-Entry coverage matrix

The audit export is expected to expose these columns without inventing counts:

`TERM`, `ENTRY_ID`, `FORMS`, `SENSES`, `SEMANTIC_REFERENCE`, `OWNER`,
`KNOWLEDGE`, `MEDIA`, `VIDEO`, `ARTICLE`, `BRAND`, `MODEL`, `SPECIMEN`,
`MENTIONS`, `RELATED_TERMS`, `STATUS`, `NEXT_ACTION`.

The status vocabulary is the runtime vocabulary: `COMPLETE`, `LEXICAL_GAP`,
`OWNER_GAP`, `KNOWLEDGE_GAP`, `MEDIA_GAP`, `RELATION_GAP`, `MENTION_ONLY`,
`STANDALONE_LEXICAL`, `AMBIGUOUS`, `BLOCKED`, plus action statuses
`READY`, `REVIEW_REQUIRED`, `NOOP` where the plan exposes them.

## Dependency order

Enrichment proceeds only after stable identity, in this order:

`P0 Durable lexical Forms → P1 Semantic Reference → P2 Canonical Knowledge →
P3 Graph relationships → P4 Representative Media/gallery → P5 Video → P6
Article → P7 Brand/Model/Specimen derived projections → P8 Related Terms →
P9 Mention-only discovery`.

Downstream presentation enrichment must not precede stable semantic identity.

## Current search and public detail

Current code supports search through durable Forms and the compatibility path
for terms including `400`, `400 ngày`, `400-Day Clock`, `Anniversary clock` and
`Jahresuhr/400`. Live behavior must be verified against the deployed source
revision; no browser/live correctness claim is valid before deployed SHA
read-back.

The public hierarchy is `Entry → Forms → Sense(s) → semantic reference →
canonical owner → owner projections`. Each Sense keeps its own definition,
context, owner, Knowledge, Media, Video, Article, Graph, Brand/Model/Specimen,
related-term and Mention projections. Semantic sections remain
Sense-qualified; multi-Sense Entries never promote the first Sense.
