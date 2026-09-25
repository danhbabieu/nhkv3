# Canonical Media Target Binding and MediaUsage Reuse Design

**Date:** 2026-09-25
**Status:** Owner-approved conversational design; spec awaiting review
**Scope:** NHK V3 Media binding, MediaUsage mutation, MCP target normalization,
staging admission, Governance apply and WordPress Article featured projection

## 1. Goal

Make one active canonical Media reusable through the governed MCP/MediaUsage
boundary for many independent targets, including multiple WordPress Posts and
registered Authority targets, without creating a second Media or attachment,
without creating a Graph edge merely because an image is used, and without
recomposing or rewriting Article editorial content when only an image slot is
changed.

The minimum concrete case is binding existing Media
`01a0d7ee-3e33-7366-88c6-287112b34936` to WordPress Post `1:18` through the
canonical governed MCP flow.

## 2. Constitution and contract constraints

The implementation must preserve these existing laws:

- WordPress `wp_posts` remains the sole owner of editorial title, body,
  excerpt, slug, category and publication state.
- Media, MediaAsset, MediaUsage and WordPress attachment remain separate
  boundaries.
- Semantic durable mutations remain Proposal → Approval → Eligibility →
  Controlled Apply → canonical owner → durable audit.
- Unknown type, endpoint, role, placement, operation or field fails closed.
- Canonical UUID/stable-key, revision, provenance, idempotency and final
  read-back remain mandatory.
- A MediaUsage is contextual presentation/use state. It is not automatically a
  Graph relation, Evidence record or Knowledge claim.
- Existing Media and attachment identities are reused; checksum, filename and
  URL are not identity merge proofs.
- Staging acceptance remains server-issued, exact, signed, capture-bound and
  fail-closed. Production does not use a staging packet.
- No migration, backfill, V2 mutation, production mutation or live staging
  acceptance is part of this implementation phase.

## 3. Findings that shape the design

### 3.1 Current failure

`MediaBindingService::resolveTarget()` currently validates a binding target
against `EntityTypeRegistry`. `wp_post` is intentionally absent from the
Authority entity catalog because WordPress Posts are editorial endpoints, not
Authority entities. The same service therefore emits
`MEDIA_BINDING_TARGET_TYPE_INVALID` for a `wp_post` target.

The runtime already has a `wp_post` endpoint in `EndpointTypeRegistry`, with
the canonical key form `blog_id:post_id` such as `1:18`. The MCP schemas and
staging code do not consistently use that endpoint registry representation:
`nhk.media.bind` advertises UUID-only targets, while `nhk.media.usage` accepts
Post fields and normalizes them only later in the mutation path.

### 3.2 Current reuse and uniqueness evidence

`MediaUsage` persistence already permits one Media to appear at multiple
targets. The placement uniqueness boundary is
`media_id + endpoint_type + endpoint_key + usage_role + placement_key`, while
the active-slot constraint is scoped to the target endpoint/role/slot. The
design must retain this direction and ensure replacement logic does not turn a
target-local slot rule into a Media-global rule.

### 3.3 Current WordPress projection boundary

`WordPressMediaAttachmentBridge` owns the native attachment/featured-image
projection. The existing broad article synchronization path can also reconcile
inline content. A MediaUsage-only featured replacement must use a narrow
featured-only projection path, after canonical usage apply, so that content
composition is not entered.

### 3.4 Existing retry commit

Commit `674ed41763748d6319bd96a3c37f45080d474e7d` repairs image-widget retry
dispatch after Media materialization. It does not repair target registry
selection, target normalization, Governance payload identity or the
MediaUsage owner, so it remains complementary evidence rather than the fix.

## 4. Canonical target model

Introduce one runtime target registry/normalizer for MediaUsage consumers. It
must consume the already-registered endpoint/resolver capability; it must not
invent a second semantic endpoint registry.

Conceptual value object:

```php
final class MediaTargetReference
{
    public function __construct(
        public readonly string $endpointType,
        public readonly string $endpointKey,
        public readonly ?string $canonicalUuid,
        public readonly ?string $stableKey,
        public readonly int $revision,
    ) {}
}
```

Normalization rules:

| Input | Canonical result |
|---|---|
| Authority `{type, id: UUID}` | endpoint type + Authority UUID |
| Authority `{type, stable_key}` | resolve unique active Authority, then endpoint type + UUID |
| `wp_post {type: "wp_post", blog_id: 1, post_id: 18}` | `wp_post + 1:18` |
| `wp_post {type: "wp_post", id: "1:18"}` | `wp_post + 1:18` |

The normalizer must:

1. lowercase and validate the endpoint type;
2. reject arbitrary endpoint strings;
3. resolve the registered endpoint and verify existence/active state;
4. return one canonical endpoint key before fingerprinting or proposal creation;
5. preserve Authority UUID/stable-key/revision when the endpoint is an
   Authority target;
6. validate a WordPress Post through its endpoint resolver and reject a missing
   or trashed Post;
7. expose a machine-readable diagnostic for invalid type, malformed reference
   and missing target separately.

The normalizer is used by `nhk.media.bind` only when its role/capability
supports that target. `representative` remains an Authority representative
operation unless an existing active contract explicitly registers a different
owner capability. WordPress Article slots use `nhk.media.usage` with Article
roles.

## 5. MCP and Governance flow

The canonical `nhk.media.usage` request flow becomes:

```text
MCP schema
  → transport validation
  → MediaTargetNormalizer
  → canonical proposal payload/fingerprint
  → Proposal → Approval → Eligibility → Controlled Apply
  → MediaBindingService::mutate()
  → MediaUsage owner
  → target-specific projection
  → MediaUsage + projection canonical read-back
```

`nhk.media.bind` remains the representative binding compatibility surface and
must use the same target registry when it handles an eligible Authority target.
It must not silently broaden `representative` to WordPress Article slots.

For staging, the packet binds the normalized endpoint type/key, Media UUID,
operation, role, placement, expected usage revision, Capture identity,
idempotency key, payload fingerprint, capability, expiry and signature. The
verifier, admission filter and direct service guard all consume the normalized
shape. A client-provided `approved` flag or alternate target representation is
never sufficient.

## 6. MediaUsage semantics and replacement

Each link is an independent MediaUsage. The same Media may have, for example:

```text
Media A
├─ representative → model B
├─ technical_detail → variant C
├─ representative → product D
├─ featured_primary → wp_post 1:18
└─ featured_primary → wp_post 1:19
```

Replacement is scoped to `(endpoint_type, endpoint_key, role,
placement_key)`:

- `replace` requires the current usage UUID and expected revision;
- the old active usage is logically retired/demoted according to the
  registered role policy;
- a new active usage is created for the replacement Media;
- the old usage history remains readable;
- unrelated usages of either Media remain unchanged;
- one Media can be active in the same slot role on many different targets;
- replay of the same idempotency key returns the original canonical result;
- replay with changed Media, target, role, placement or payload returns an
  idempotency conflict.

The implementation must preserve existing representative demotion vocabulary
and must not invent a new role or Graph predicate. If the current persistence
contract requires usage identity updates for a particular non-replacement
metadata operation, that operation remains distinct from slot replacement.

## 7. WordPress featured projection isolation

For `wp_post + featured_primary`:

1. resolve the existing canonical Media and its existing attachment mapping;
2. apply the MediaUsage mutation through Governance and the MediaUsage owner;
3. read back the active usage and Media-to-attachment mapping;
4. update only the native featured attachment through the existing attachment
   projection owner;
5. read back the Post featured attachment and editorial state token;
6. assert title, body, excerpt, slug, categories and semantic subject are
   byte-for-byte/identity unchanged;
7. return the combined canonical MediaUsage and projection read-back.

This path must not invoke Article recomposition, semantic subject
reconciliation, inline-content composition or generic WordPress write APIs.
The native WordPress function used by the projection owner is allowed only as
the final projection step after MediaUsage ownership has succeeded; it is not
an alternative writer or bypass.

## 8. Reverse lookup and read-back

`nhk.media.get` must return all active and historical usage records according
to its existing reader-safe policy, including endpoint type/key, role,
placement and revision. The canonical reverse lookup for a Media is the
MediaUsage repository's `listByMediaId`; target lookup uses
`listByEndpoint(endpoint_type, endpoint_key)`.

The final bind/usage result must verify:

- exactly one expected active usage for the requested target slot;
- the returned Media UUID is the requested canonical Media UUID;
- the usage revision is the post-apply revision;
- no duplicate usage was created on replay;
- for `wp_post`, the expected native attachment is reflected;
- for replacement, the old usage is retired/demoted and remains in history;
- reverse lookup returns every independent target using the same Media.

## 9. Explicit non-goals and conflict handling

- No new generic relationship table.
- No Graph edge for ordinary featured/inline/representative MediaUsage.
- No Media clone, attachment clone or URL-based identity merge.
- No target-specific Post-ID branch.
- No fixture/test special case.
- No direct database write or raw `_thumbnail_id` bypass.
- No Article title/body/excerpt/slug/category/semantic rewrite for a
  Media-only request.
- No expansion of the Authority catalog to include `wp_post`.
- No automatic support for unregistered endpoint types or roles.
- No live acceptance mutation until the required documentation, build,
  credentials, exact object scope and duplicate audit gates are available.

If an active Constitution or contract explicitly forbids a required target or
projection behavior, implementation stops with `CONSTITUTION_CONFLICT`; the
contract is not weakened to make the test pass.

## 10. Acceptance matrix

The implementation is complete only when the following are covered by tests:

| Case | Required result |
|---|---|
| Existing Media → Post 18 → featured | one active usage, correct attachment, canonical read-back |
| Same Media → Post 19 → featured | Post 18 unchanged, Post 19 has independent usage, reverse lookup returns both |
| Same Media → Model → representative | Authority usage exists and Post usages remain unchanged |
| Same Media → Variant → technical detail | coexists with Model representative |
| OLD → Media A on Post 18 | old slot retired/demoted, A active, unrelated usages unchanged |
| Same request replay | same result, no duplicate row |
| Same key, changed payload | idempotency conflict |
| Invalid target type | fail closed with target-type diagnostic |
| Missing Post | fail closed before mutation |
| Missing/inactive/unreadable Media | fail closed before mutation |
| Featured-only Article mutation | title/body/excerpt/slug/category/semantic subject unchanged |

Focused verification must include MediaBinding/MediaUsage, Capture media
continuation, Article media reconciliation, MCP schema/transport, reverse
lookup, N=1 and one-Media-to-many-target coverage. PHP lint, `git diff --check`
and secret review are required. Full Unit and guarded integration are reported
separately; unavailable integration infrastructure is an environment blocker,
not a pass.

## 11. Planned implementation boundaries

The later implementation plan should keep these units independently testable:

1. Canonical target reference value object and registry/normalizer.
2. MediaBinding/MediaUsage normalization and replacement semantics.
3. MCP schema/transport and Governance payload convergence.
4. Staging packet/admission/guard convergence.
5. WordPress featured-only projection and Article immutability regression.
6. Reverse lookup/read-back and full verification evidence.

No code is changed by this spec. Existing unrelated worktree changes remain
untouched.
