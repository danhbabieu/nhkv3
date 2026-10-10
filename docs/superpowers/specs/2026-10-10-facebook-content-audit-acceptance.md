# Facebook Content Audit CLI — Acceptance Criteria

## Scope and identity

- [x] The canonical target URL is enforced exactly.
- [x] Scope mismatch fails closed with `SCOPE_MISMATCH`.
- [x] Missing or unverified Page ID fails closed with `IDENTITY_NOT_VERIFIED`.
- [x] A Page ID is written to the scope lock only after verified identity.
- [x] No unrelated Page is requested or collected.

## Access and collection

- [x] All seven requested read surfaces plus delete-capability verification are
  reported independently as granted, denied, unsupported, inaccessible, or
  not checked.
- [x] Page posts paginate beyond the first page and resume from checkpoint.
- [x] Retryable failures retry within a bound; permanent failures remain visible.
- [x] Repeated cursors fail closed rather than looping.
- [x] Group rows distinguish Page-authored, Page-shared, and third-party-shared.
- [x] Inaccessible groups are counted as inaccessible, never as zero posts.
- [x] No write permission or mutation call is made.

## Data and classification

- [x] Post rows include IDs, direct URLs, dates, type, text, media references,
  reaction/comment/share counts, optional video views, source and timestamp.
- [x] Missing values remain distinct from authoritative numeric zero.
- [x] Duplicate detection needs matching normalized content and media identity.
- [x] Low-engagement analysis is grouped by content type and age bucket.
- [x] Trademark output is review-only and never states legal infringement.
- [x] Delete candidates never trigger deletion.

## Workbook

- [x] All nine required sheets are present.
- [x] Vietnamese text survives workbook serialization and reload.
- [x] Direct post URLs are clickable hyperlinks.
- [x] Formula-injection prefixes are neutralized.
- [x] Credentials and authorization headers do not appear in the workbook.

## Verification

- [x] Fixture end-to-end test produces a readable `.xlsx`.
- [x] Focused PHPUnit tests pass.
- [x] Appropriate regression PHPUnit tests pass.
- [x] PHP lint passes for changed PHP files.
- [x] `git diff --check` passes.
- [x] No live Meta collection is claimed without verified credentials and Page
  access.
