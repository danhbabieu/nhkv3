# Dictionary Enrichment Audit and Safe Planning Design

## Status

Approved in conversation on 2026-10-03. This design is subordinate to the
NHK V3 Constitution and the active Dictionary, Authority, Knowledge, Graph,
Media, Video, Article, Public Route, SEO and Governance contracts.

## Goal

Complete a generic, read-only Dictionary enrichment audit and deterministic
planning boundary for every public durable Entry/Sense, then allow only exact,
revision-bound lexical Form enrichment through the existing canonical
Dictionary mutation service. The system must expose the actual state of
lexical and owner-backed discovery without inventing semantic truth.

## Success criteria

- Every public durable Entry is auditable with bounded work and an explicit
  classification, not an opaque quality score.
- Approved, identity-safe legacy labels produce deterministic Form candidates;
  duplicate Forms become `NOOP` and unsupported hidden Forms remain blocked or
  non-public.
- Semantic owner matching uses only strong governed evidence and applies only
  `EXACT_UNIQUE` candidates.
- Knowledge, Graph, Media, Video and Article gaps are reported as owner-pipeline
  candidates; this Dictionary boundary never writes those systems.
- The `400 ngày` Entry/Sense is handled by the same generic resolver and is
  actionable only if its evidence is `EXACT_UNIQUE`.
- Audit and plan are available through bounded internal/admin diagnostics;
  public requests never execute corpus enrichment.
- Apply, where runtime capability and exact plan state permit it, uses CAS,
  idempotency, audit and canonical read-back. Unavailable runtime produces a
  deterministic plan rather than a false success.

## Non-goals and constitutional constraints

- No Dictionary Graph endpoint, Dictionary-owned semantic relation, new
  Authority entity, Graph predicate, Knowledge claim, Source/Evidence record,
  Media identity, MediaUsage, Video identity or Article body.
- No owner inference from label similarity, keyword overlap, AI similarity,
  filename, OCR, transcript mention, URL, slug or frequency.
- No bulk best-guess mapping, legacy article-body migration, raw SQL mutation,
  direct WordPress writer, Governance bypass, staging/production mutation or
  materialization of the 29 Entries merely to fill the UI.
- No change to `style.css` 1.3.5 or the existing expectation 1.3.3.
- Existing baseline failures remain classified as pre-existing unless a new
  failure is demonstrated.

## Terminology and boundaries

Dictionary owns lexical truth: Entry, Form, Sense-as-existing-Concept,
approved labels, definitions, lexical scope and Mention observations.
Canonical owners own semantic truth. An Entry/Sense semantic reference is a
typed mapping to an already-existing owner; it is not a new owner or relation.

The flow is:

```text
public Entry/Sense read
  -> bounded enrichment audit
  -> strong-evidence owner resolution
  -> deterministic plan + fingerprint
  -> optional exact Dictionary mutation apply
  -> canonical read-back
```

Owner enrichment remains a separate flow:

```text
audited canonical owner
  -> owner-pipeline coverage adapters
  -> read-only candidates for Knowledge/Graph/Media/Video/Article
  -> owning Governance pipeline
```

“More content” is not a reason to invent a semantic relation.

## Audit contract

Introduce an application-level `DictionaryEnrichmentAudit` boundary with a
bounded input containing `limit`, opaque `cursor`, optional exact Entry/Sense
scope and a public-only flag. Its output contains:

- Entry identity, preferred form, Form count and Sense count;
- semantic-reference state: `PRESENT_VALID`, `ABSENT`, `STALE`, `INVALID` or
  `AMBIGUOUS`;
- canonical owner type, UUID/stable identity where valid, and public readiness;
- per-owner-pipeline coverage packets for Knowledge, Media, Video, Article,
  Brand, Model and Specimen;
- Mention counts for `ARTICLE`, `KNOWLEDGE`, `MEDIA` and `VIDEO`;
- RelatedTerm count/status;
- lexical gaps such as missing approved Forms and detectable locale/kind;
- deterministic classification and `next_action`.

Coverage packets must preserve `status` (`AVAILABLE`, `EMPTY`, `UNAVAILABLE`,
`BLOCKED`, `AMBIGUOUS`) and counts; they must not collapse unavailable data
into zero. The audit may reuse existing bounded dossier, projection,
repository and relation adapters. It must not recursively hydrate full
content or perform N×N Entry scans.

Required classifications are `COMPLETE`, `LEXICAL_GAP`, `OWNER_GAP`,
`KNOWLEDGE_GAP`, `MEDIA_GAP`, `RELATION_GAP`, `MENTION_ONLY`,
`STANDALONE_LEXICAL`, `AMBIGUOUS` and `BLOCKED`.

## Owner matching contract

The resolver evaluates evidence in this order:

1. existing explicit legacy destination;
2. exact canonical stable identity;
3. exact approved alias;
4. same curated external terminology;
5. existing governed Graph/Knowledge provenance;
6. unique canonical resolver result.

It returns `EXACT_UNIQUE`, `AMBIGUOUS`, `NO_OWNER`, `OWNER_MISSING` or
`CONFLICT`. Only `EXACT_UNIQUE` can create a ready plan action. A label-only
match is never sufficient.

## Deterministic plan contract

`DictionaryEnrichmentPlan` accepts an audit snapshot and returns:

- `status`: `READY`, `REVIEW_REQUIRED`, `BLOCKED` or `NOOP`;
- ordered actions with `action_type`, Entry/Sense IDs, current revision,
  target, evidence/reason, risk and action status;
- `ADD_ENTRY_FORM`, `SET_SEMANTIC_REFERENCE`, `NOOP` and `REVIEW_REQUIRED`
  action types only;
- stable, canonicalized plan fingerprint independent of input ordering;
- owner-enrichment candidates separated from executable Dictionary actions.

Stale revisions, invalid targets, unsupported hidden semantics, ambiguous
owners and unavailable required runtime state are not ready. Existing exact
Forms produce `NOOP`. Forms are created only from approved durable labels,
never from article text, Mentions, candidate rows, AI translation or guessed
foreign names.

## Apply contract

Apply is an internal/admin operation over an exact approved fingerprint. It
accepts only `READY` actions with `EXACT_UNIQUE` resolution, exact current
Entry/Sense revision and a caller idempotency key. It delegates Form creation
and existing semantic-reference mutation to `DictionaryMutationService`.
It validates the target before mutation, records an append-only audit outcome,
replays idempotently, and performs canonical read-back. Any CAS conflict,
target drift, unavailable repository or failed read-back returns a non-success
diagnostic and never claims enrichment.

The apply path does not create or update Graph, Knowledge, Media, Video,
Article or Public Identity data. It does not expose a new public mutation route.

## Admin/MCP surface

Extend the existing Dictionary MCP/admin handler and runtime registration with
read-only abilities named:

- `nhk.dictionary.enrichment.audit`
- `nhk.dictionary.enrichment.plan`

If and only if the existing guarded mutation capability is available, expose an
internal/admin-only apply operation under the same registered Dictionary
boundary. It must reject public transport, missing capability, stale plan
fingerprints and unbounded limits. Public hub/detail routes remain lightweight
and never call the enrichment audit.

## Data enrichment policy

The priority order is Forms, semantic reference, Knowledge, Graph relations,
Media, Video, Articles, derived Brand/Model/Specimen projections, RelatedTerm
projection and Mention-only discovery. The audit reports every layer even when
an earlier layer is missing; the plan never promotes a later projection into
semantic truth.

Mention harvesting may be extended only through the existing idempotent
Mention boundary for authorized Article, Knowledge, Media metadata and Video
metadata/transcript sources. A Mention remains lexical discovery and is never
promoted to an Article-about, Video-about, Knowledge claim or Graph edge.

RelatedTerms may use same-owner and bounded canonical Graph paths through the
existing projection boundary. No manual related-term list or Dictionary Graph
edge is introduced.

## 400-day reference case

The generic audit must inspect Entry
`01a100e0-1803-7271-b244-04ae86c732b4`, Sense
`01a0ff0c-6687-798d-8f44-6761d242815a` and the existing classification owner
candidate `01a0a868-2918-7dac-81dc-bfc25e710068` with stable identity
`nhk:classification:clock-type.dong-ho-400-ngay`. Approved terminology such
as `400 ngày`, `400-Day Clock`, `Anniversary clock`, `Jahresuhr/400` and
`torsion-pendulum clock` is evidence only; the runtime must decide whether it
is exact and unique. No special-case branch is permitted.

## Testing and evidence

Add focused tests proving:

- bounded audit over public Entries and cursor continuation;
- valid/absent/stale/invalid/ambiguous semantic references;
- approved alias Form `READY`, duplicate `NOOP`, unsupported hidden Form
  blocked/non-public and candidate/private source rejection;
- exact unique, ambiguous, no-owner, missing-owner and conflict matching;
- stale target blocking, deterministic fingerprints and ordering;
- apply CAS, idempotency, audit, read-back and zero Graph mutation;
- owner coverage status separation and no semantic inference from Mentions;
- public detail/search consuming durable Forms without invoking audit;
- standalone lexical Entry remaining renderable when enrichment is absent;
- bounded performance: no unbounded corpus scans or public-request audit.

Verification includes changed PHP lint, relevant focused PHPUnit suites,
`git diff --check`, changed-scope secret review and an execution-state update
with only verified results. No generated report containing secrets is
committed.

## Documentation and rollout

Add one canonical operations document describing lexical enrichment versus
canonical-owner enrichment, action statuses, evidence rules, bounded audit
usage and safe apply behavior. Update `V3_EXECUTION_STATE.md` after verified
checkpoints. Keep all runtime/database writes disabled unless the exact
governed mutation capability, approved deterministic plan and read-back are
available.
