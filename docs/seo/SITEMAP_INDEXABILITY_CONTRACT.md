# Sitemap and Indexability Contract

> **SUBORDINATE TO THE CONSTITUTION.** This is a projection-only contract.

## Required flow

`canonical owner → Public Identity/native editorial URL → public eligibility →
indexability → canonical URL → sitemap`.

Database existence alone never creates a sitemap entry. Exclude redirects and
history URLs, noindex/private/unavailable/ambiguous/incomplete projections,
technical endpoints, MediaAsset delivery URLs, retired/deleted objects,
compliance-blocked copy and duplicate non-canonical URLs.

Native Article, Entity, Video and supported image sitemap families are separate
operational adapters that consume the same canonical/indexability decision.

`lastmod` changes only for a meaningful owner or projection change. Request
time, scrape time and daily refreshes are not meaningful changes. Completion
claims require public read-back when runtime is available; unavailable runtime
is `UNAVAILABLE`/`ENVIRONMENT_BLOCKED`, never empty success.
