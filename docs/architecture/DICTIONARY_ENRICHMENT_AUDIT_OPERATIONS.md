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
