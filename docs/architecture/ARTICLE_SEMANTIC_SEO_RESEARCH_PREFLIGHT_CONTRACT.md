# NHK V3 Article Semantic + SEO Research Preflight Contract

> **NON-NORMATIVE.** This contract is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md` and does not authorize a new
> entity, endpoint, predicate, field, operation or data mutation.

Status: approved read-only research/reconciliation contract, reconciled to
current runtime on 2026-09-07.

## Purpose

An Article request managed by MCP/Admin is an operation over native WordPress
editorial content. Before draft/update/publication orchestration, the application
must research current canonical NHK state and produce a deterministic read-only
planning packet. WordPress remains the sole owner of Article title, body,
category, dates, author, media ordering and URL.

Required order:

```text
capability discovery
→ canonical semantic resolution
→ site/current-owner inventory
→ Article intent/overlap check
→ Authority/Knowledge/Source/Evidence reconciliation
→ relation plan
→ Dictionary lexical preview
→ internal-link/SEO blueprint
→ Media/Video plan
→ claim-compliance plan
→ only then draft / governed semantic apply / read-back / publish
```

## Reconcile-before-create role

Preflight is the required research/reconciliation stage, not just an SEO preview.
For every intended Authority/Knowledge creation it must identify whether current
canonical state is:

- `EXACT_EXISTING`;
- `MERGE_CANDIDATE`;
- `RELATED_BUT_DISTINCT`;
- `NO_EXISTING_CANONICAL_RECORD`;
- `UNCERTAIN`.

Only the genuinely-new outcome may proceed to create. A new claim additionally
requires its intended canonical subject/context to be resolved before creation.
If a missing Authority node is needed, the later mutation stage must create/apply
and read it back before the Knowledge claim is created. Uncertain candidates are
deferred, not turned into orphan records.

Titles, body text, URL/slug, taxonomy/postmeta, fuzzy/keyword match, visual
similarity, Dictionary label and AI memory are discovery signals only; none is
canonical identity proof.

## Scope and fail-closed rules

The preflight applies to intents that create, materially update, enrich, relate,
optimize or publish a V3 knowledge Article. It is read-only and distinguishes an
honest empty result from unavailable infrastructure/runtime.

Identity resolution is canonical UUID → stable key → exact registered name/
alias → ambiguity. Unknown registry values, ambiguous identity, unavailable
required reads, unsupported predicate, retired/ineligible target or unresolved
owner blocks the dependent stage. Do not guess or fall back to taxonomy.

Dictionary preview follows the lexical contract and may report resolved,
ambiguous or candidate terms/internal links. It never persists Mention/Candidate
rows during preflight and never becomes Evidence/Graph truth. Unknown lexical
terms are not automatically publication blockers.

## Research packet

The packet is an application result, not canonical storage. It includes:

- canonical subject resolution and assertion scope;
- reconciliation outcome for each proposed semantic creation/update;
- WordPress Post/category/hub inventory and Article overlap classification;
- existing Knowledge/Source/Evidence inventory and evidence coverage;
- relation candidates classified as `EXISTING_DIRECT`, `EXISTING_DERIVED`,
  `PROPOSED_DIRECT`, `EDITORIAL_RELATED`, `AMBIGUOUS` or `UNSUPPORTED`;
- Dictionary lexical plan;
- public canonical internal-link candidates;
- Category, Media and Video plans;
- Article SEO blueprint and public-claim compliance plan;
- blockers/warnings/deferred records and `ready_for_draft`.

`EXISTING_DERIVED` is bounded query-only context and is never persisted as a
shortcut. `EDITORIAL_RELATED` is editorial presentation only. A
`PROPOSED_DIRECT` candidate may enter Governance only when canonical typed
endpoints, registered predicate, provenance/evidence and subject reconciliation
are ready. Missing predicate remains `REGISTRY_GAP`; broad `about` is not a
substitute.

## Deferred packet

When preflight cannot safely resolve an intended semantic object/relation, retain
an explicit deferred entry rather than fabricate empty/success. Useful states
include `PENDING_RESEARCH`, `EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`,
`LEXICAL_GAP`, `FRONTEND_GAP`, `RUNTIME_BLOCKED`, `NEEDS_REVIEW`.

Each deferred entry should keep source/provenance, proposed canonical subject and
type, proposed relation, evidence, reason, registry/runtime blocker, canonical
IDs already resolved, existing proposal ID if any, and deterministic rerun
instructions. Preflight itself does not mint a new proposal just because an
older execution was interrupted.

## Article gate and completion boundary

`ready_for_draft` only means required preflight information is available. It
does not mean a Post was written, semantic mutation applied or publication is
allowed.

Later semantic operations use the full lifecycle:
`proposal create/ingest → submit → review → approval binding → eligibility →
Controlled Apply → canonical owner read-back → idempotency verification`.

A preflight pass or proposal state is never canonical completion. The Article
workflow follows `ARTICLE_INGEST_CONTRACT.md` for draft, semantic read-back,
Media, rendered/public verification and publication.

## Update/replay semantics

An update reruns research against current Post revision, semantic owners,
Knowledge/Evidence, Graph, MediaUsage, Video, category, Dictionary and SEO
state. Plans are reconciled, never blindly appended.

After an actual owning content save, Dictionary observation may persist lexical
state idempotently under its own contract; that does not rewrite Article body or
promote semantic truth.

If a later mutation is interrupted after a durable proposal exists, reuse the
same proposal/idempotency binding for unchanged intent. A rerun must not produce
duplicate Authority, Knowledge, Source, Evidence or active relation.
