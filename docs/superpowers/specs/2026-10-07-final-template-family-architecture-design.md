# Final Template-Family Architecture Design

**Base:** `c98f0051e136831c0226ceba068ae8ec22e519f8`

## Goal

Keep public presentation bounded by approved experience families. Creating a
new Article, Video, Dictionary term, Entity, Media item, or Knowledge claim
must never require a new record-specific PHP, CSS, or JavaScript file.

## Architecture

Canonical owners provide family query results, normalized presentation packets
feed one family entry template, and shared presentation components render
reusable sections. Entity remains one family entry at `entity.php`; Dictionary
remains `dictionary.php` plus one common detail partial; Video remains
`video.php`; Media has only the library surface; Knowledge has no atomic public
detail; native WordPress Posts remain on `single.php`.

The implementation is read-only with respect to semantic data. No migration,
backfill, staging/production mutation, Dictionary lifecycle change, Côn hoa
thị data change, deployment, or push is permitted.

## Required behavior

- Enforce the new-record-zero-files invariant with automated contract and
  behavioral tests using neutral synthetic fixtures.
- Fix Dictionary public usage text so generic internal `scope` is never shown;
  explicit `usage_scope` is preferred, then `usage_notes`, then singular
  `usage_note`, with legacy `notes` accepted only by an explicit reader-facing
  contract.
- Keep `wp_robots` as the sole robots serializer and converge SEO decisions
  into one normalized packet/serializer without adding a manual robots tag.
- Use explicit family asset decisions; flatten unrelated CSS dependencies.
- Remove or retire unreachable Media/Knowledge detail presentation only with
  negative-route regression coverage.
- Replace public full-scan collection behavior where existing repository
  interfaces support it. If bounded querying requires schema/index work, report
  `INDEX_REQUIRED` with evidence instead of migrating.
- Preserve fail-closed binary delivery and move checksum validation out of the
  Media archive card hot path without weakening delivery validation.

## Non-goals

- No per-semantic-type or per-record templates.
- No standalone Media or atomic Knowledge SEO detail.
- No persisted canonical host changes.
- No destructive storage cleanup.
- No broad design rewrite unrelated to the family architecture.
