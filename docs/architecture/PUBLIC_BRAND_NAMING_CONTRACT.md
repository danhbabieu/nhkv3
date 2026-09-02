# NHK V3 Public Brand Naming Contract

> **NON-NORMATIVE implementation contract.** The Constitution controls if a
> conflict exists.

## Decision

Public website copy has one approved display spelling for each covered brand:

| Public spelling | Covered input aliases |
| --- | --- |
| `Odo` | `ô đo`, `ô-đô`, `o do`, `o-do`, `odo` |
| `Vedette` | `vê đét`, `vê-đét`, `ve det`, `ve-det`, `vedet`, `vedette` |
| `Junghans` | `junhan`, `jun hans`, `jun-hans`, `junghans` |

Matching is case-insensitive and Unicode-aware. Word boundaries are required,
so a larger unrelated word is not rewritten.

## Enforcement boundary

The theme applies the policy to WordPress titles, excerpts, post content and
RSS title/excerpt/content filters, then applies it again to reader-facing
Authority, Knowledge, Media, Video, comparison, search and related-card text.
JSON-LD names and descriptions use the same policy. HTML tags and attributes
are preserved; executable scripts, styles and form values are not rewritten.

This is a public projection rule. It does not rewrite `wp_posts`, semantic
records, aliases, source evidence or legacy article bodies. Canonical Authority
identity and governed names remain owned by their existing domain contracts.
Unknown or ambiguous spellings are not guessed into a brand; they require an
explicit addition to this contract and its tests.

## Acceptance

- No covered alias is emitted in visible public text, public metadata or JSON-LD.
- Public display uses exactly `Odo`, `Vedette` and `Junghans`.
- Admin/editorial screens retain source data and are outside this presentation
  filter.
- Unit and frontend contract tests cover aliases, boundaries, HTML preservation
  and every current public template surface.
