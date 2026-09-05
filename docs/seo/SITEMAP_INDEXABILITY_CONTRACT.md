# Sitemap and Indexability Contract

> **SUBORDINATE TO THE CONSTITUTION.** This is a projection-only contract.

## Required flow

`canonical owner → Public Identity/native editorial URL → public eligibility →
indexability → canonical URL → sitemap`.

Database existence alone never creates a sitemap entry. Exclude redirects and
history URLs, noindex/private/unavailable/ambiguous/incomplete projections,
technical endpoints, MediaAsset delivery URLs, retired/deleted objects,
compliance-blocked copy and duplicate non-canonical URLs.

Native Article, Entity, Video, approved Dictionary and supported image sitemap
families are separate operational adapters that consume the same
canonical/indexability decision.

## Dictionary projection

Dictionary sitemap behavior follows
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`:

- `/tu-dien/` may be included when the Dictionary public projection is
  available.
- Include `/tu-dien/{slug}/` only for an approved dedicated concept whose
  Dictionary page is the actual canonical owner and whose public projection is
  READY/indexable.
- Exclude owner-delegated concepts from Dictionary detail sitemap entries; the
  existing Entity/Article owner remains responsible for its own sitemap URL.
- Exclude draft, ambiguous, rejected, ignored, suppressed, incomplete,
  redirected or duplicate Dictionary records.
- A stale/missing delegated destination fails closed. It is not converted into
  a fallback Dictionary URL.
- Dictionary candidate occurrence time is not `lastmod` for the canonical
  owner. Only a meaningful public lexical projection/owner change may update a
  dedicated Dictionary page's `lastmod`.

`lastmod` changes only for a meaningful owner or projection change. Request
time, scan time, lexical detection time and daily refreshes are not meaningful
changes. Completion claims require public read-back when runtime is available;
unavailable runtime is `UNAVAILABLE`/`ENVIRONMENT_BLOCKED`, never empty success.
