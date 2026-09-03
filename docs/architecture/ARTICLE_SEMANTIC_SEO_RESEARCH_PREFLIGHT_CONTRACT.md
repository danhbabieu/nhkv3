# NHK V3 Article Semantic + SEO Research Preflight Contract

> **NON-NORMATIVE.** This contract is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md` and does not authorize a new
> entity, endpoint, predicate, field, operation or data mutation.

Status: approved contract with an initial read-only runtime slice, 2026-09-03.
Post references, bounded Knowledge → Evidence → Source inventory, shared
semantic traversal and route-gated link planning are now wired. Full
acceptance coverage and guarded integration evidence remain required before
the capability can claim READY.

## Purpose

An Article request managed by MCP is an operation over native WordPress
editorial content. Before a draft, editorial update or publication gate is
reached, the application must research the current NHK runtime and produce a
deterministic, read-only planning packet. WordPress remains the sole owner of
the Article title, body, category, dates, author, media ordering and URL.

The required order is:

```text
capability discovery → semantic resolution → site inventory → overlap check
→ Knowledge/Source/Evidence research → relation plan → SEO blueprint
→ Media/Video plan → claim compliance → draft/apply/read-back/publish
```

## Scope and fail-closed rules

The preflight applies to MCP intents that create, materially update, enrich,
relate, optimize or publish a V3 knowledge Article. It is read-only and must
distinguish an honest empty result from an unavailable dependency.

Identity resolution is ordered as canonical UUID, stable key, exact registered
name/alias, then ambiguity. Titles, bodies, slugs, taxonomy, postmeta,
keywords, visual similarity and AI memory are not semantic identity.

Unknown registry values, ambiguous identities, unavailable runtime reads,
unsupported predicates, private/retired targets and missing public routes
block the dependent stage; the application must not guess, fall back to
taxonomy or fabricate a relation/content result.

## Research packet

The packet is an application result, not canonical storage. Its controlled
sections are:

- subject resolution and assertion scope;
- WordPress Post/category/hub inventory;
- overlap classification: `NO_OVERLAP`, `COMPLEMENTARY_CONTENT`,
  `SUBSTANTIAL_OVERLAP`, `LIKELY_DUPLICATE_INTENT` or
  `EXISTING_CANONICAL_ARTICLE`;
- Knowledge, Source and Evidence inventory;
- relation candidates classified as `EXISTING_DIRECT`, `EXISTING_DERIVED`,
  `PROPOSED_DIRECT`, `EDITORIAL_RELATED`, `AMBIGUOUS` or `UNSUPPORTED`;
- internal-link candidates with public canonical route and reason;
- category, Media and Video plans;
- Article SEO Blueprint;
- public-claim compliance state;
- blockers, warnings and `ready_for_draft`.

`EXISTING_DERIVED` is query-only, bounded to an approved maximum of two hops,
and is never persisted as a shortcut. `EDITORIAL_RELATED` does not become a
Graph edge. Only a registry-valid, evidence/provenance-ready
`PROPOSED_DIRECT` candidate may enter the existing Governance proposal flow.

## Article gate

`ready_for_draft` is true only when required runtime inventory, subject
resolution, overlap decision, relation/claim policy and applicable media/SEO
checks are available. A preflight success does not mean a Post was written,
semantic mutation was applied or publication is allowed. Draft, Governance,
read-back, rendered SEO/public verification and publication remain separate
gates defined by `ARTICLE_INGEST_CONTRACT.md`.

## Update semantics

An update reruns research against the current Post revision, semantic links,
Knowledge references, MediaUsage, category, SEO projection and related
content. Plans are reconciled; they are never blindly appended.
