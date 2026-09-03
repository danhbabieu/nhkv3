# NHK V3 Persisted Public Identity and URL Design

> **NON-NORMATIVE DESIGN SPEC.** This document is subordinate to
> `docs/constitution/NHK_V3_CONSTITUTION.md`. It authorizes no schema
> migration, data population, slug assignment, redirect change or production
> mutation.

**Status:** Owner-approved direction, ready for implementation planning after
Owner review
**Decision:** Hướng B — persisted public identity, shared deterministic
Vietnamese normalizer, resource-specific URL policies and one-hop historic
redirects.

## 1. Goal and invariants

NHK V3 will give each eligible public semantic resource one durable public
identity. A public URL is a projection of that identity and its resource URL
policy; it is not a UUID, stable key, display-name derivation or semantic
relation.

The design preserves these invariants:

- WordPress native `wp_posts` remains the owner of Article identity, title,
  body, dates, editorial slug and permalink. A title change never silently
  changes a published Post URL.
- Authority remains the owner of Brand, Model, Variant, Movement, Music,
  Component, Classification, Specimen, Product and only other types explicitly
  enabled by the runtime registry and Constitution.
- Video remains an external-reference entity. Its identity is
  `(platform=youtube, external_video_id)` plus the existing canonical UUID;
  it is not a Post or MediaAsset.
- MediaAsset is binary/delivery metadata, not a semantic page identity.
- Atomic Knowledge Claim, Source and Evidence do not receive standalone
  indexable pages unless a later registry, Constitution and projection
  contract explicitly authorize one.
- UUIDs, stable keys, random suffixes and database identifiers never appear in
  canonical public URLs.
- Ambiguity, collision, missing parent, unavailable storage or failed
  eligibility fails closed.

## 2. Constitutional classification

The Constitution already makes persisted public slug/history normative in §15,
shared public eligibility and projection normative in §§16 and 18, and the
canonical route families normative in §17. This design therefore introduces no
new entity type, endpoint type, predicate, relation, semantic field or
operation.

Current implementation gaps are recorded rather than legalized:

| Area | Classification | Design consequence |
|---|---|---|
| Authority slug is derived from `canonical_name` at read time | `PUBLIC_IDENTITY_STORAGE_GAP` / `CODE_GAP` | Add a durable public-identity boundary before re-projection. |
| Historic Authority/Video slug lookup is not a general persisted resolver | `CODE_GAP` | Resolve exact historic records directly to the owning canonical identity. |
| Archive/detail consumers do not share one eligibility result | `PUBLIC_ELIGIBILITY_FAILURE` / `CODE_GAP` | Route, archive, detail, search, cards and SEO consume one application result. |
| Current `/media/{uuid}` or equivalent Media detail exposure | `CONSTITUTION_CONFLICT` at the route boundary if treated as an indexable Media page; otherwise `CODE_GAP` | Do not preserve it by implementation inertia. Retire, noindex or return honest 404 after route-ledger review; keep asset delivery separate. |
| Atomic `/tri-thuc/{slug}` Claim detail exposure | `CODE_GAP` unless a valid public projection contract exists | Keep Claims as bounded projection/provenance inputs, not standalone SEO pages. |
| Album/Gallery public type | `REGISTRY_GAP` | No route or identity is designed for it. |
| Model/Variant structural parent fields during transition | `DATA_COMPATIBILITY_GAP`, not Graph truth | The existing approved payload-parent transition remains bounded until governed structural cutover. |

No unresolved Constitution conflict is resolved by weakening the Constitution.

## 3. Boundaries and data flow

The public flow is:

```text
canonical domain read
  → persisted PublicIdentity read
  → PublicEligibilityPolicy
  → resource-specific CanonicalUrlPolicy
  → public collection/detail projection
  → links, breadcrumbs, SEO and sitemap
```

`VietnameseSlugNormalizer` is pure and deterministic. It only converts
Unicode text to an ASCII token stream, lowercases, filters allowed characters,
collapses separators and trims. It does not query storage, inspect routes,
decide SEO, mutate an entity or create a redirect.

`CanonicalUrlPolicy` receives a resource type, a persisted public identity and
the governed semantic context needed by that resource. It returns a canonical
path or a typed blocker. It owns route shape, namespace, parent segments,
external-ID suffix rules and route-policy version; it does not own
transliteration or persistence.

`PublicIdentityRepository` owns current identity and historic route records.
`HistoricPublicRouteResolver` performs exact lookup and returns either a
canonical target or a fail-closed result. HTTP adapters may issue one 301 but
must not contain alternate slugification or semantic fallback logic.

`PublicSeoProjection` consumes the same final URL and eligibility result as the
page. It is the sole source for HTML canonical, `og:url`, JSON-LD `url`,
`mainEntityOfPage` when applicable, `VideoObject.url`, sitemap entries,
breadcrumbs, internal links, cards and search-result links.

## 4. Storage model and public-identity boundary

The implementation plan may choose repository/table names consistent with the
existing migration conventions, but the logical model is fixed:

### 4.1 Current identity record

One current record exists per public-identity owner:

| Field | Requirement |
|---|---|
| `identity_id` | Internal persistence identifier; never public. |
| `owner_kind` + `owner_id` | Canonical resource owner, e.g. Authority UUID or Video UUID; validated against registry/domain repository. |
| `route_type` | Registered public route/resource type. Unknown values fail closed. |
| `current_slug` | Normalized route segment, immutable between explicit slug operations. |
| `collision_scope` | Deterministic scope key for the policy, such as root, namespace or canonical parent chain; never global-string-only uniqueness. |
| `route_policy_version` | Version used when the route contract requires migration compatibility; not a substitute for history. |
| `revision` | Optimistic CAS revision, incremented once per accepted identity mutation. |
| timestamps | Created/updated audit timestamps. |

The uniqueness boundary is `(route_type, collision_scope, current_slug)` plus
owner uniqueness. The repository must also reject a current-vs-historic
collision in the same route scope and reject collision with a native
WordPress route where the public policy requires it.

### 4.2 Historic route record

Each explicit replacement retains a durable historic record containing the
owning `identity_id`, exact historic route type/scope/path, old slug,
replacement revision and audit timestamps. Historic records are append-only
for routing purposes: they are not rewritten to point at an intermediate
slug, and they are not treated as aliases for a different owner.

Resolution stores the canonical owner, then reads the current identity once.
Thus `old → current` is always one hop even after multiple approved slug
changes. Two historic records that resolve ambiguously, or a historic record
whose owner/current identity is missing or ineligible, fail closed.

### 4.3 Allocation and mutation

Identity allocation is an explicit application operation performed only for a
registered resource with a valid public projection. Initial slug allocation
uses the shared normalizer and the resource policy's collision scope. It does
not silently append a random suffix unless a separate approved contract
explicitly permits that strategy.

Display-name rename does not mutate `current_slug`. A public-slug change is a
separate explicit, governed, audited CAS operation that atomically:

1. validates the owner, route policy, scope and expected revision;
2. validates the new slug and native-route collision;
3. writes the new current identity;
4. appends the old route as historic; and
5. increments the identity revision exactly once.

No step creates a Graph edge, changes semantic identity, imports an Article,
renames a legacy physical asset or mutates production data.

## 5. Deterministic Vietnamese normalization contract

The pure normalizer must be environment-independent and tested without relying
on `iconv` behavior. Explicit mappings are required where Unicode folding is
not guaranteed to be stable across CLI, PHPUnit and PHP-FPM. The minimum
acceptance set is:

| Input | Output |
|---|---|
| `Ô Đô` | `odo` |
| `Đồng hồ cổ` | `dong-ho-co` |
| `được` | `duoc` |
| `người Việt` | `nguoi-viet` |
| `sưu tập` | `suu-tap` |
| `Âm thanh điểm nhạc` | `am-thanh-diem-nhac` |

The contract must also define punctuation, repeated separators, empty output,
already-ASCII input, uppercase input, combining marks, unsupported symbols and
maximum-length handling. Maximum length must be deterministic and must not
truncate into an ambiguous or empty token. The normalizer returns a value or a
typed invalid-input result; it never queries collision state.

`MediaAsset` filename normalization is a separate consumer of the shared
normalizer. It applies only to new uploads under the filename policy; legacy
physical filenames are not renamed in this design. A filename is not alt text,
semantic identity or an SEO claim.

## 6. Route ownership matrix

The following is the canonical ownership matrix. Every consumer must obtain
its path from the owning policy/application service.

| Resource/surface | Canonical route | Owner of identity/URL | Public eligibility and notes |
|---|---|---|---|
| Brand | `/{brand-slug}/` | Authority + `AuthorityUrlPolicy` | Active, unique, valid slug, no reserved/native-root collision. |
| Model | `/{brand-slug}/{model-slug}/` | Authority + parent-aware policy | Exactly one valid active Brand parent; transition payload parent may carry `DATA_COMPATIBILITY_GAP`. |
| Variant | `/{brand-slug}/{model-slug}/{variant-slug}/` | Authority + parent-aware policy | Exactly one valid active Model/Brand chain; ambiguity blocks. |
| Movement | `/bo-may/{slug}/` | Authority + namespace policy | Does not require Brand. |
| Music | `/ban-nhac/{slug}/` | Authority + namespace policy | Does not require Brand. |
| Component | `/linh-kien/{slug}/` | Authority + namespace policy | Registered active type only. |
| Classification | `/phan-loai/{slug}/` | Authority + namespace policy | Registered active type only. |
| Specimen | `/hien-vat/{slug}/` | Authority + namespace policy | Separate physical-object identity; no Product substitution. |
| Product | `/san-pham/{slug}/` | Authority/commerce boundary + namespace policy | Separate offer identity; optional Specimen context never becomes identity. |
| Video | `/video/{semantic-slug}-{external-video-id}/` | Video + `VideoUrlPolicy` | Valid available source, NHK editorial package, one controlled Hub, provenance, embed and governed semantic attachment. Slug context is governed, not raw YouTube title. |
| WordPress Post/Article | Native WordPress permalink | `wp_posts`/WordPress | No Authority public identity; explicit canonical slug change follows native WP plus governed old-URL 301 policy. |
| Media | No standalone detail route by default | Media domain + asset delivery policy | Current `/media/{uuid}` detail is not preserved as SEO truth without an approved public semantic/editorial contract. |
| MediaAsset | Delivery URL only when allowed | MediaAsset/storage boundary | No standalone indexable identity page; no legacy filename migration. |
| Knowledge Claim | No atomic canonical detail route | Knowledge + projection consumer | May appear in eligible related/provenance projections only. |
| Source/Evidence | No standalone HTML route | Source/Evidence | Provenance/support projection only; no invented route. |
| Album/Gallery | None | Registry boundary | `REGISTRY_GAP`; no route or identity. |
| Comparison | `/so-sanh/?a=…&b=…` | Query-driven projection | No persisted comparison identity; inputs resolve to eligible public Authority references. |

Native WordPress route ownership always wins at a collision boundary. A
semantic route cannot claim a root that WordPress owns, and a legacy technical
route cannot appear in canonical links, breadcrumbs, sitemap or search output.

## 7. Video URL policy

The policy formats the canonical path as:

```text
/video/{semantic-slug}-{lowercase-external-video-id}/
```

For the approved canary context, the expected path is
`/video/odo-36-10-gai-carillon-p4kahx3lbow/`. The semantic portion is selected
from confirmed Variant, Model, Brand, Music, editorial context or explicit
user hint according to the governed Video contract. It never blindly copies a
source-platform marketing title when better NHK context exists.

The external ID supplies deterministic uniqueness within the Video route
scope. A YouTube title update does not change a public Video URL. If the
semantic context itself changes, a slug change still requires the explicit
public-identity operation and historic one-hop redirect; it is not a side
effect of source synchronization.

## 8. Historic redirect lifecycle

Request handling is ordered as follows:

1. Resolve the exact current route under its resource policy.
2. If absent, resolve the exact historic route in the same route scope.
3. If exactly one eligible owner is found, issue HTTP 301 directly to that
   owner's current canonical path.
4. If no owner, an ambiguous owner, a collision, a native-route conflict or a
   broken eligibility chain is found, fail closed with the route's honest 404
   or non-indexable outcome.

The old URL must never render 200, redirect through a technical/UUID URL, or
redirect through an intermediate historic slug. The destination emits a
self-canonical URL. Redirects preserve no query semantics except parameters
explicitly allowed by the resource contract.

For the first bounded cutover, the old malformed P4KaHX3LBOw URL must resolve
directly with 301 to the expected canonical path. The existing Video UUID
`01a06815-1e51-7964-b004-1ba79e488ad1`, YouTube identity, current semantic
relations and Video record are preserved. No new Video is created.

## 9. SEO single-source contract

Every public consumer receives a `PublicUrlResult` (or repository-equivalent)
that contains the final canonical path, eligibility, blockers/warnings and
identity revision. The same result is passed to:

- HTML `<link rel="canonical">`;
- `og:url` and JSON-LD `url`;
- `mainEntityOfPage` and `VideoObject.url` when the contract applies;
- sitemap inclusion and emitted location;
- breadcrumb URLs, internal links, cards and search results.

No theme/template, REST serializer, search adapter, breadcrumb builder or SEO
helper calls the normalizer directly or reconstructs a parent path. Technical
routes, historic URLs, UUID/stable-key routes, private/non-ready records,
placeholders and non-indexable Media/Claim pages are excluded from canonical
SEO output and sitemaps. WordPress editorial sitemaps remain native and
independent; Video sitemap output follows the existing Video SEO contract.

## 10. Test matrix

The implementation must add focused tests before any data cutover:

| Area | Required proof |
|---|---|
| Normalizer | All minimum Vietnamese examples; Unicode/combining marks; punctuation; separator collapse; ASCII; empty/invalid; identical CLI/PHPUnit/PHP-FPM behavior. |
| Identity storage | Owner uniqueness; route-scope uniqueness; CAS revision; current/history collision; policy-version handling; no UUID/stable-key URL. |
| Allocation/mutation | First allocation; display rename preserves slug; explicit slug change appends history; replay/idempotency; stale revision and invalid input fail closed. |
| Historic resolver | Exact one-hop 301; multiple sequential changes still redirect old→current; no chain; two historic slugs ambiguous; missing/ineligible owner; native route collision. |
| Hierarchy | Brand root collision; Model parent; Variant parent chain; duplicate child names in separate scopes; missing/inactive/ambiguous parent. |
| Video | Governed semantic context; deterministic external-ID suffix; title update stability; malformed/unavailable/private/non-embeddable source blocked; canary old→new route. |
| Article | Native WP permalink remains authoritative; title change does not alter slug; explicit old URL→new URL behavior is one hop; no Authority identity is created. |
| Media/Knowledge | MediaAsset delivery distinct from Media page; current Media UUID route is not accidentally indexable; atomic Claim/Source/Evidence routes fail closed absent approved projection; registry gaps remain diagnostics. |
| SEO parity | One URL reused by canonical, OG, JSON-LD, VideoObject, sitemap, breadcrumbs, cards, search and internal links; no historic/technical URL emission. |
| Failure semantics | Empty successful query differs from unavailable runtime, hydration loss, malformed row, collision and infrastructure failure. |

## 11. Phased implementation and migration policy

### Phase 1 — contract and storage design

Implement the normative contract/spec, logical storage design, shared pure
normalizer, resource URL-policy interfaces and focused tests. This phase does
not allocate identities for existing rows, migrate data, alter routes or run a
schema migration without a separately reviewed implementation plan.

### Phase 2 — consumer parity and resolver

Wire Authority route consumers, Video, SEO/internal-link consumers and the
historic redirect resolver through the shared application result. Remove
consumer-local slugification. Resolve Media and Knowledge route retirement or
non-indexability through the route ledger before wiring any public consumer.

### Phase 3 — read-only inventory

Run a bounded inventory against the explicitly authorized runtime only. Report
physical/hydrated/current/history/duplicate/collision/eligibility counts and
per-row blockers. Produce no slug assignments, identity repairs, redirects,
Graph edges, WordPress changes or asset renames.

### Phase 4 — Owner-approved bounded re-projection

Re-project or migrate one explicitly approved group at a time, with receipts,
read-back, collision reports and rollback/readiness evidence. The first bounded
production cutover is the existing Video P4KaHX3LBOw canary described in §8;
it must preserve UUID, external identity and current relations and must not
create a duplicate Video. Legacy MediaAsset physical filename migration is
outside this design.

No final production cutover is authorized by this document. A Cutover
Readiness Report and Owner approval remain mandatory.

## 12. Self-review result

- No `TBD` or unresolved placeholder remains in the design.
- No new semantic entity, predicate, relation, endpoint type or operation is
  invented.
- Article, Authority, Video, MediaAsset, Media, Knowledge and Source/Evidence
  ownership boundaries are explicit and non-overlapping.
- No bulk migration, legacy article-body import, physical filename rename,
  Graph repair or production mutation is hidden in the phased plan.
- Current implementation gaps are classified as `CODE_GAP`, `REGISTRY_GAP`,
  `PUBLIC_IDENTITY_STORAGE_GAP` or `CONSTITUTION_CONFLICT`; the Constitution
  is not changed.
