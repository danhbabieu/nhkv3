# NHK V3 Public URL / Slug Contract

> **CURRENT implementation contract, subordinate to the Constitution.** If this
> document conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the
> Constitution controls. This contract is projection-only: it does not authorize
> semantic rekey, UUID change, Graph mutation, editorial rewrite, Media rename or
> direct database repair.

## 1. Ownership boundary

A public slug is a presentation/public-identity projection. It is not semantic
identity. Authority/Knowledge/Media/Video UUIDs, scoped stable keys, governance
proposal/idempotency keys, source identifiers and database identities remain
owned by their existing domains and are never rewritten to make a URL prettier.

Native WordPress Post/Page/taxonomy permalink ownership remains WordPress-owned.
NHK semantic route code consumes those native permalinks; it does not introduce
a second Article slug authority.

## 2. One canonical normalization policy

All NHK-managed semantic/title-derived public slugs use
`Application\PublicIdentity\CanonicalPublicSlugPolicy`.

The order is normative for this implementation boundary:

`Unicode name/title → Vietnamese transliteration → combining-mark cleanup → token/separator normalization → public semantic cleanup → collision resolution → canonical public path`.

Unicode must not be filtered to ASCII before transliteration. Vietnamese letters
including `đ`, `ơ`, `ư` and every supported tone form normalize to their ASCII
base. NFC and decomposed/NFD input must converge on the same deterministic
output. Punctuation, whitespace, slash, dash variants and underscore collapse to
one `-`; no leading, trailing or repeated separator is emitted.

The standalone public token `nhk` normalizes to `nha-kho`. Larger unrelated
strings containing those letters are not rewritten merely by substring match.
This is public projection behavior only and never changes a semantic stable key
such as an existing `nhk:*` identity.

## 3. Slug material and technical identifiers

The default semantic slug is the shortest meaningful title/name-derived slug.
UUIDs, database IDs, hashes, source keys and external platform IDs are not
semantic/title-derived SEO slug material and must not be appended merely to
avoid a collision.

Video routing keeps the external platform ID in internal identity metadata for
lookup, dedupe and source resolution, but the default canonical public slug is
semantic-only. A collision may use meaningful governed context; an external ID
is never a default technical suffix.

## 4. Collision strategy

Collision handling is deterministic and scoped to the route domain. The base
slug wins when unique. A suffix is considered only after an actual collision and
must come from meaningful governed entity data. Current compatibility resolver
priorities include, where applicable, year, model/reference, caliber,
manufacturer, artist/album, component kind/family, specimen serial/date, vendor
or listing title.

A candidate may not use UUID, stable key, hash, source/external ID or another
opaque technical identifier as its normal collision escape. If two records
cannot be distinguished by available meaningful data, public routing fails
closed and the condition is classified as duplicate/reconciliation work rather
than hidden behind a random suffix.

## 5. Canonical stability / freeze

Before the public launch/cutover, an explicitly governed cleanup may allocate or
migrate canonical public identities to the current normalization policy. After a
public identity has been allocated and published, later name/title edits do not
silently change the canonical slug. A slug change is an explicit Public Identity
migration using optimistic revision/CAS and existing one-hop historic-route
behavior.

The compatibility `PublicRouteResolver` remains name-derived where the current
runtime has not yet completed durable Public Identity consumer cutover. This
contract does not claim that cutover complete.

## 6. Projection consumers

Canonical, OpenGraph URL, structured-data URL/`@id`, sitemap, breadcrumb, card,
search and internal-link surfaces consume the same resolved canonical path.
They must not independently slugify titles or reconstruct identity from names,
stable keys or metadata.

Media assets/attachments keep their separate filename/storage projection
contract. This URL policy does not rename image files or alter Media identity.
Atomic Knowledge claims remain non-canonical public detail objects under the
current route contracts unless a separately approved public route exists.

## 7. Migration and repair boundary

Generator/policy code is fixed before any persisted-data rewrite. Bulk direct
SQL/search-replace is forbidden. Existing persisted Public Identity data may be
reprojected only through an approved writer/executor that preserves owner UUID,
records revision/history as required and performs read-back/collision checks.
The current `CanaryPublicIdentityProjection` is read-only and therefore cannot
be treated as authorization for bulk mutation.

## 8. Acceptance

Regression evidence must cover Vietnamese NFC/NFD normalization, separator
collapse, standalone public-token cleanup, deterministic ASCII preservation,
meaningful collision suffixing, fail-closed unreconciled duplicates, identity
preservation and reuse of the same canonical path by SEO/link surfaces. Any
Video assertion must also respect the current constitutional route exception
until that contract is amended.
