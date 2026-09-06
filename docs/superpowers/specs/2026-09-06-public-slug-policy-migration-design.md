# NHK V3 Canonical Public Slug Policy and Legacy URL Audit Design

**Status:** Approved design, 2026-09-06

## Goal

Create one Constitution-compliant public slug policy for new and existing
projection consumers, fix Vietnamese transliteration and technical Video URL
leakage, and provide a deterministic, read-only legacy URL audit/dry-run that
can later be activated through the required governance/runtime gate.

## Constitutional boundary

Public Slug is presentation identity, not canonical semantic identity. UUID,
stable key, Graph edge, Evidence/Source identity, idempotency key and external
Video identity remain unchanged. SEO and route consumers read the owning public
identity/native editorial URL; they do not create semantic truth or fallback
identity.

The current Constitution and Public Identity contracts do not authorize an
autonomous repair/backfill of existing public slugs or live semantic records.
Therefore this slice implements the shared policy, audit, dry-run and tests.
It must not execute `--apply` against legacy data. An eventual apply path must
be separately gated, idempotent, collision-aware, historic-route preserving,
transactional where supported, and verified by owner read-back.

## Design

### 1. Shared policy boundary

Add one `PublicSlugPolicy` under the application/public-identity boundary. Its
normalization pipeline is:

`Unicode input → NFC/NFD-safe normalization → Vietnamese transliteration →
ASCII token normalization → separator collapse → public token policy → trim`.

The policy must map `ư/Ư → u`, `ơ/Ơ → o`, `đ/Đ → d`, all Vietnamese tone and
diacritic variants, Unicode combining marks, whitespace, `/`, `_`, en/em dash
and punctuation. It collapses repeated separators and removes leading/trailing
hyphens. In public route tokens only, the exact token `nhk` becomes
`nha-kho`; stable keys such as `nhk:brand:odo` are never passed through this
policy.

`PublicRouteResolver`, `PublicIdentityService`, `MediaSeoBlueprint` and Video
route generation must delegate to this boundary. No module-local transliteration
or slug implementation remains for public URL generation.

### 2. Canonical route generation

Authority route paths retain the registered namespaces and parent structure.
The policy supplies only normalized tokens; it does not invent endpoint types,
predicates, parent links or route families. Persisted Public Identity remains
the durable owner when present. Compatibility name-derived routing remains
read-only and must fail closed on ambiguity.

Video public paths use the editorial/title context and registered `/video/`
namespace. The external YouTube ID remains in Video metadata and dedupe/
idempotency boundaries but is not appended by default. A collision may add
only a deterministic meaningful discriminator available from governed data,
such as year, model, variant or a registered content context. A UUID, hash,
database ID or external ID is never the default discriminator.

Media filename/SEO stems use the same normalization policy, while MediaAsset
delivery URLs remain delivery identities and are not converted into standalone
SEO pages.

### 3. Audit and dry-run

Add a read-only application service that emits one row per registered public
candidate. Its minimum row shape is:

```text
entity_type, entity_id, old_url, new_url, changed,
collision, collision_reason, resolution
```

The inventory covers native Post/Page, indexable Category/Tag, all nine
Authority types, public Knowledge claims, Video and registered public route
families. It reads through owning repositories/WordPress APIs; it does not
search/replace database values or infer semantic identity from a name alone.

Rows are classified as no-op, changed, collision, ambiguous, missing public
identity, invalid route, or unavailable runtime. Duplicate semantic identity
or unresolved collision is a review outcome, never an ID suffix workaround.

Expose the report through the repository's existing WP-CLI registration
convention as `wp nhk-v3 url migrate --dry-run` (or the exact established
command namespace if runtime discovery shows another canonical name). The
command must be explicitly read-only and must report counts plus row detail.
An `--apply` option may be reserved/documented but must fail closed with a
typed governance/not-authorized result until a later approved activation.

### 4. Projection consistency

All public URL consumers must use the same resolved path: frontend links,
canonical, Open Graph `og:url`, Schema.org, VideoObject, breadcrumbs, cards,
related links, search results, sitemap and RSS where applicable. Non-ready,
ambiguous, unavailable, technical and historic routes remain excluded or
redirect-only according to the existing SEO/indexability contracts.

## Data-flow and safety

1. Read canonical owner and current public/native route.
2. Compute policy-normalized candidate without mutating storage.
3. Resolve route scope and existing candidates.
4. Classify no-op/change/collision/review/unavailable.
5. Emit deterministic report and diagnostics.
6. Only after a future gate: allocate/change Public Identity, record historic
   route, rebuild projections, and verify rendered consumers/read-back.

No operation in this design rekeys semantic records, changes title/body,
creates Graph edges, merges identities, rewrites legacy article bodies, or
mutates V2/staging/production.

## Testing and acceptance

- Unit tests prove Vietnamese mappings including `tuổi → tuoi`, `tư/ừ/Ư/Ơ/Đ`,
  NFC/NFD input, separators, punctuation, repeated hyphens and exact-token
  `nhk → nha-kho` without changing `nhk:*` stable keys.
- Unit tests prove all current route and Media/Video consumers delegate to the
  shared policy and Video URLs omit YouTube IDs by default.
- Collision tests prove meaningful deterministic resolution, duplicate review,
  no UUID/hash/external-ID default, and fail-closed ambiguity.
- Dry-run tests prove the required row fields, no-op behavior, typed reasons,
  native WordPress candidates, semantic candidates, Video metadata retention,
  and zero writes.
- Integration tests prove route read-back/redirect behavior only for existing
  governed fixtures and never apply legacy mutation.
- Verification includes focused PHPUnit, PHP lint, `git diff --check`, secret
  review and the repository's route/SEO smoke checks when runtime is available.

## Out of scope

Legacy `--apply`, automatic Public Identity allocation for every existing row,
Graph/identity reconciliation, production cutover, redirect bulk-write,
article-body migration, and any new semantic type, endpoint, predicate or
relation.

