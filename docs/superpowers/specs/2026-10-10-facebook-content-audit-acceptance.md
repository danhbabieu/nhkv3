# Facebook Content Audit CLI — Acceptance Criteria

## Scope and identity

- [ ] The canonical target URL is enforced exactly.
- [ ] Scope mismatch fails closed with `SCOPE_MISMATCH`.
- [ ] Missing or unverified Page ID fails closed with `IDENTITY_NOT_VERIFIED`.
- [ ] A Page ID is written to the scope lock only after verified identity.
- [ ] No unrelated Page is requested or collected.

## Access and collection

- [ ] All seven requested read surfaces plus delete-capability verification are
  reported independently as granted, denied, unsupported, inaccessible, or
  not checked.
- [ ] Page posts paginate beyond the first page and resume from checkpoint.
- [ ] Retryable failures retry within a bound; permanent failures remain visible.
- [ ] Repeated cursors fail closed rather than looping.
- [ ] Group rows distinguish Page-authored, Page-shared, and third-party-shared.
- [ ] Inaccessible groups are counted as inaccessible, never as zero posts.
- [ ] No write permission or mutation call is made.

## Data and classification

- [ ] Post rows include IDs, direct URLs, dates, type, text, media references,
  reaction/comment/share counts, optional video views, source and timestamp.
- [ ] Missing values remain distinct from authoritative numeric zero.
- [ ] Duplicate detection needs matching normalized content and media identity.
- [ ] Low-engagement analysis is grouped by content type and age bucket.
- [ ] Trademark output is review-only and never states legal infringement.
- [ ] Delete candidates never trigger deletion.

## Workbook

- [ ] All nine required sheets are present.
- [ ] Vietnamese text survives workbook serialization and reload.
- [ ] Direct post URLs are clickable hyperlinks.
- [ ] Formula-injection prefixes are neutralized.
- [ ] Credentials and authorization headers do not appear in the workbook.

## Verification

- [ ] Fixture end-to-end test produces a readable `.xlsx`.
- [ ] Focused PHPUnit tests pass.
- [ ] Appropriate regression PHPUnit tests pass.
- [ ] PHP lint passes for changed PHP files.
- [ ] `git diff --check` passes.
- [ ] No live Meta collection is claimed without verified credentials and Page
  access.

