# NHK V3 SEO Core Contract

> **SUBORDINATE TO THE CONSTITUTION.** SEO, discovery and structured data are
> projection-only. This contract creates no semantic writer, identity, claim,
> evidence, relation or durable public route.

SEO may read eligible canonical Authority, Article, Knowledge, Media, Video and
approved Dictionary lexical projections to render title, H1, meta, canonical,
robots, Open Graph, structured data, internal links and sitemap entries. It
must never create or change canonical UUID, stable key, Authority, Knowledge,
Source/Evidence, Graph, Media, Video, Public Identity or Product–Specimen truth.

The owning public route is reused. Rendered URL, canonical URL, internal link,
Open Graph URL, structured-data page URL and sitemap URL must agree. Missing or
ambiguous ownership fails closed; SEO never derives a fallback slug.

## Dictionary canonical ownership

Dictionary follows `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` and is a lexical
resolver, not a URL factory.

- If an approved term resolves to an existing eligible Entity or canonical
  Article owner, search and internal links point directly to that owner.
- An owner-delegated Dictionary concept does not create a second indexable
  detail page. If an old Dictionary detail route exists after ownership moves,
  it must become one direct 301 and internal links must be recomputed to the
  final owner.
- A dedicated `/tu-dien/{slug}/` page is eligible only for one approved concept
  with no better existing canonical owner, sufficient visible definition and a
  unique stable public slug.
- Draft, ambiguous, rejected, ignored, suppressed, incomplete, redirected and
  duplicate Dictionary records are non-indexable.
- Auto-linking uses approved unambiguous labels/current canonical owners only;
  rendering a lexical link never changes semantic truth or the stored Article
  body.

Eligible dedicated Dictionary pages may project Schema.org `DefinedTerm`; the
`/tu-dien/` hub may project `DefinedTermSet`. Structured data describes visible
lexical content only and is not a semantic Authority writer.

## Shared readiness

Shared read-only policies use exactly these general statuses:

- `READY`
- `INCOMPLETE`
- `BLOCKED`
- `UNAVAILABLE`
- `NOT_APPLICABLE`

Every non-ready result carries deterministic reason codes. Implementations
prefer existing vocabulary and may use bounded codes such as
`MISSING_PUBLIC_IDENTITY`, `CANONICAL_URL_MISMATCH`,
`AMBIGUOUS_CANONICAL_SUBJECT`, `DUPLICATE_OR_CANNIBALIZED_INTENT`,
`INSUFFICIENT_PUBLIC_CONTENT`, `REPRESENTATIVE_IMAGE_MISSING`,
`VIDEO_THUMBNAIL_UNAVAILABLE`, `VIDEO_NOT_WATCH_PAGE_ELIGIBLE`,
`STRUCTURED_DATA_INAPPLICABLE`, `COMPLIANCE_BLOCKED`,
`DICTIONARY_DESTINATION_INCOMPLETE` and `RUNTIME_UNAVAILABLE`.

Indexability consumes canonical/public eligibility and the shared readiness
decision. Theme, REST, robots and sitemap consumers must not calculate separate
publication truth. Numeric SEO scores are advisory only.

Dictionary runtime/storage unavailability is `UNAVAILABLE`, never an honest
empty glossary/search result.

## Structured data and copy

Structured data describes visible eligible content and validated metadata only;
it does not invent ratings, reviews, commercial facts, relationships,
chronology, evidence strength or video facts. Public claims use the shared
advertising compliance contract. Generated text and Dictionary definitions are
never Evidence merely because they are public.

FAQ is an optional editorial/knowledge projection for reader usefulness. It is
not a semantic store and absence of FAQ is not a rich-result or publication
requirement.
