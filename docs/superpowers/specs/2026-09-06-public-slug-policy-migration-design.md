# NHK V3 Canonical Public Slug Policy and Existing-URL Migration Design

**Status:** Revised design, 2026-09-06; implementation pending contract
reconciliation and a separately governed activation gate.

## Goal and boundary

NHK V3 needs one public slug policy for new projections and for every existing
public URL projection already present on the demo site. Changing the policy is
therefore not a new-data-only change: the eventual migration/reprojection of
existing data is mandatory. This documentation checkpoint does not execute a
migration or deploy code.

The policy is presentation/public identity, never semantic identity. UUID,
stable key, `nhk:*` identity, database ID, external video ID, idempotency key,
source key, hash, revision and internal contract identifier remain unchanged.
No Graph edge, semantic record, title/body, V2/staging/production record or
legacy article body may be rewritten by this design.

### Contract reconciliation prerequisite

The current `VIDEO_SEMANTIC_INGEST_CONTRACT.md` describes an external-video-ID
URL suffix. The policy approved by this design is that an external ID remains
internal metadata for identity, lookup, dedupe, idempotency and source
resolution, but is not appended to the default canonical slug. This is not a
Constitution conflict: the Constitution requires the pair
`platform=youtube` + `youtube_video_id` as Video identity, not an ID-bearing
public slug. Before implementation, the Video contract and executable route
tests must be reconciled with this policy; until then, no implementation may
claim the new Video URL behavior is live.

## 1. Shared policy boundary

Use one `CanonicalPublicSlugPolicy` under the public-identity boundary. Its
pipeline is:

`Unicode input → NFC/NFD-safe normalization → Vietnamese transliteration → ASCII
token normalization → separator collapse → public-token policy → trim`.

It maps Vietnamese tone/diacritic variants, combining marks, `ư/Ư → u`,
`ơ/Ơ → o`, `đ/Đ → d`, whitespace, `/`, `_`, dash variants and punctuation;
repeated separators collapse and leading/trailing hyphens are removed. In
public tokens only, the exact `nhk` token becomes `nha-kho`. This never applies
to `nhk:*` or any other internal identifier. For example, `nhk:brand:odo`
remains exactly `nhk:brand:odo`; only a token in public slug material may
normalize from `nhk` to `nha-kho`.

`PublicRouteResolver`, `PublicIdentityService`, SEO projections, frontend link
builders and Video route generation must reuse this policy. No consumer may
slugify independently.

## 2. Public slug and Media filename are separate policies

`MediaFilenameNormalizer` and `CanonicalPublicSlugPolicy` may reuse a
transliteration/normalization primitive, but they are different policy
boundaries with different owners and outputs. A public URL migration must not
rename existing media files, storage keys, attachments or delivery URLs.

The rule “no legacy media filename migration” remains valid. It means exactly
that: no legacy media filename migration does **not** mean no existing public
URL migration. Existing public routes are in scope below; legacy media filename
renaming is not.

## 3. Canonical route material

Authority paths retain registered namespaces and parent structure. The policy
supplies normalized semantic tokens only; it does not invent endpoint types,
predicates, relations or route families. Native WordPress Post/Page/Category/Tag
permalinks remain WordPress-owned, and NHK semantic routing does not become a
second Article URL authority.

The default slug is the shortest meaningful semantic title/name-derived slug.
The following are never default public-slug material and never become a
technical migration suffix:

`stable_key`, `nhk:*`, UUID, database ID, external video ID, idempotency key,
source key, hash and internal contract identifier.

Video keeps YouTube/external ID in internal metadata for identity, lookup,
dedupe, idempotency and source resolution. It does not default to URLs such as
`...-nhk-tsqww2q6-hm`; without a collision, the slug contains only meaningful
semantic content. A collision may use only meaningful governed context under
the strategy below.

## 4. Persisted canonical freeze

Once a resource has a persisted public canonical slug, changing its display
title/name does not automatically change that slug. Normal projection and read
operations are non-mutating. A slug changes only through an explicit public-URL
migration operation, using the existing revision/state-token/fingerprint
convention and CAS where available; the change is audited and historized when
the architecture provides that facility.

Before public launch/cutover, a deliberate, governed bulk cleanup may migrate
existing public identities. After publication, canonical identity is stable
unless that explicit operation is invoked.

## 5. Existing-data migration and reprojection (mandatory)

The policy applies to all existing public data, not only future writes. The
eventual migration inventory must cover at minimum:

- Authority entities that have public routes: Brand, Model, Variant, Movement,
  Music and Component, plus every other registered Authority type with a
  public projection;
- public Knowledge projections;
- Video;
- native WordPress Post, Page, Category and Tag where the public URL policy
  governs their route;
- every other existing semantic public projection exposed by the current
  runtime.

The mandatory flow is:

`audit → dry-run → collision detection → apply → reprojection → read-back
verification`.

`apply` is a governed writer/executor, not direct SQL or blind DB
search/replace. It changes only eligible public identity/native route owners,
preserves semantic identity and relations, and is allowed only after the exact
environment, authorization, policy version and migration fingerprint are
bound. `reprojection` then rebuilds/refreshes all affected public consumers
from the persisted route source of truth. The demo site may cut over directly;
it is not required to preserve every ugly legacy URL. No broad redirect build
is required for this migration.

## 6. Dry-run contract

Dry-run is strictly read-only: it must not write public identity, WordPress
permalinks, revisions, history, redirects, projections, options, caches or
semantic records. It reads through owning repositories/WordPress APIs and
emits one deterministic row per registered public candidate with at least:

```text
resource/entity type
resource/entity id
current public slug
proposed public slug
current URL
proposed URL
changed: yes/no
collision: yes/no
collision reason
proposed resolution
write eligibility / blocker
```

Rows also classify no-op, changed, collision, ambiguous, missing identity,
invalid route and unavailable runtime. A collision or missing information must
remain visible; it must not be turned into an empty success or a technical
suffix.

## 7. Idempotent and CAS-safe apply

The apply contract is retry-safe and idempotent:

- the first apply makes only the required eligible changes;
- a second apply against the same desired state makes no changes;
- if the record, persisted public identity, revision/state token or source
  fingerprint changed after dry-run, apply fails closed with a typed CAS/stale
  result;
- silent overwrite is forbidden;
- UUID, stable key, internal identity and all relations are preserved.

Where the repository already has a revision/state-token/fingerprint
convention, the migration must reuse it rather than inventing a parallel
concurrency model. Historic-route recording, if eligible, is part of the same
governed outcome and must be read back before success.

## 8. Collision resolution

Collision resolution must be deterministic, human-readable, semantic and
stable. The base slug wins when unique. Only after an actual route-scope
collision may the executor try meaningful governed discriminators, preferably:

`model/reference → year → variant/configuration → movement → music → entity
type/context → canonical parent context`.

The applicable discriminator must be present in canonical governed data, not
inferred from an opaque identifier. UUID, hash, database ID, external ID,
stable key, random value and internal identifier are forbidden as default
collision suffixes. If meaningful discriminators still collide, fail closed
and classify the records as duplicate/reconciliation/manual review. Do not
hide semantic duplication behind a technical suffix.

## 9. Historic slugs and redirects

The demo migration may cut over directly and need not retain every malformed
legacy URL. The future contract after canonical freeze is:

`explicit public slug migration → historic slug record → one-hop 301 redirect`.

Redirect chains are forbidden. If the redirect subsystem does not yet exist,
that is an explicit backlog item; do not build a large redirect subsystem just
to complete the demo migration. No bulk redirect write is part of this design.

## 10. One public URL source of truth

One persisted/public route source of truth must be resolved once and reused by:

- frontend entity links and cards;
- canonical tag and `og:url`;
- structured-data URL and `@id`;
- breadcrumbs;
- sitemap;
- search result links and related/internal links.

Native WordPress editorial surfaces continue to use WordPress-owned URLs where
the contract assigns ownership. No SEO, frontend or sitemap consumer may
independently concatenate route fragments, slugify a title, use an internal
identifier, or fall back to a name-derived URL when a persisted canonical
route exists.

## 11. Safety and data flow

1. Audit the canonical owner and current public/native route.
2. Compute the policy candidate without mutation.
3. Resolve route scope and collisions.
4. Emit the dry-run eligibility/blocker report.
5. After separate governance authorization, apply with CAS/idempotency.
6. Record eligible historic identity, reproject consumers and read back the
   canonical owner, route, redirect behavior and affected surfaces.

No step rekeys semantic records, changes UUID/stable key, rewrites article
bodies, creates Graph edges, merges identities, renames legacy media files or
mutates V2/staging/production autonomously.

## 12. Testing and acceptance

- unit tests cover Vietnamese NFC/NFD, separators, punctuation, `tuổi → tuoi`,
  `tư/ừ/Ư/Ơ/Đ`, exact-token `nhk → nha-kho`, and preservation of `nhk:*`;
- policy-boundary tests prove Media filename normalization is separate and no
  existing media file is renamed;
- route tests prove external Video IDs remain internal metadata and are absent
  from the default canonical slug, subject to the required Video-contract
  reconciliation;
- collision tests prove deterministic meaningful discriminators, stable
  repeatability and fail-closed manual review;
- dry-run tests prove every required field, native WordPress and semantic
  inventory, typed blockers and zero writes;
- apply tests prove first-run change, second-run no-op, stale/CAS rejection,
  no silent overwrite, relation/UUID/stable-key preservation and read-back;
- reprojection tests prove frontend, canonical, Open Graph, structured data,
  breadcrumb, sitemap and search links reuse one canonical route;
- integration tests use only governed fixtures and exact guarded test
  databases; they do not mutate demo, staging or production data;
- verification includes focused PHPUnit, PHP lint, migration checks where
  applicable, `git diff --check`, secret review and route/SEO smoke checks
  when the runtime is available.

## Explicitly out of scope for this design checkpoint

Production or demo `--apply`, automatic activation, direct SQL/search-replace,
legacy media filename migration, article-body migration, Graph/identity
reconciliation, bulk redirect creation, new semantic vocabulary and any
implementation of the Video contract reconciliation. Those are follow-up work
requiring the governed plan, authorization and runtime read-back described
above.
