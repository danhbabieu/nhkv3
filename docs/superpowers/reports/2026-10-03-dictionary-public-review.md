# Public Dictionary review — 2026-10-03

## Review conclusion

The existing Dictionary Entry/Sense read model is the correct source for the
public glossary. The patch stays projection-only: no schema, materialization,
semantic owner, Graph edge, mention, or database data was changed.

## What already fit

- Entry-centric public items and Sense-aware detail data already exist.
- Delegated Senses resolve to the canonical owner URL and do not create a
  competing Dictionary page.
- JSON-LD already projects `DefinedTermSet`/`DefinedTerm` from the public
  packet, and the sitemap provider consumes eligibility.
- Dynamic initials were already derived from the returned public set.

## What was wrong

- The public query accepted no search or initial request, so the template could
  not provide the requested search-first behavior.
- Hidden labels were present in the same public labels packet used by detail
  rendering, even though they were supposed to remain lookup-only.
- The empty-state branch did not distinguish an empty dictionary from a search
  or initial filter with no matches.
- Plugin fallback and theme presentation ownership was implicit rather than
  documented at the route boundary.

The reported deployed contradiction (JSON-LD populated while visible HTML was
empty) cannot be reproduced from this checkout without the deployed runtime.
The deterministic local fix makes the route packet the sole input to the
active theme template, keeps the plugin template as fallback only, and adds
query-level tests so a non-empty packet cannot be represented as a dictionary
empty state by the theme branch.

## What changed

- Added bounded GET `q` and `initial` inputs at the route/query boundary.
- Added exact, alias, prefix, contains and low-priority definition ranking,
  using `DictionaryTermNormalizer`.
- Kept HIDDEN labels searchable through an internal packet field while omitting
  them from public labels.
- Added accessible search form and distinct no-match messaging.
- Made theme-first template selection explicit.
- Added focused search and hidden-label regression tests.

## Deliberately not implemented

- No new lexical synonym/hypernym store.
- No Dictionary Graph endpoint or inferred relation.
- No facet UI without curated facet data.
- No curator Sense ordering field.
- No Mention/content-discovery expansion without an existing canonical public
  projection.
- No materialization, migration, seed, repair, or database write.

## Future gaps

The deployed runtime still needs a read-only browser acceptance check for the
exact live SHA and the four requested search/detail URLs. That check is not
claimed by local PHPUnit evidence.
