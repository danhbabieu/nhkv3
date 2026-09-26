# Universal Media Target URL Design

## Status

Design approved in conversation on 2026-09-26. This document is the
implementation specification for the Universal Media Target/Representative
flow. It does not authorize staging, production or legacy-data mutation.

## Goal

Allow an operator to express a Media binding using natural public URLs, for
example “Dùng ảnh X làm đại diện cho Y”, while resolving both URLs to existing
canonical owners and preserving the existing Media, MediaAsset, MediaUsage,
WordPress and Governance boundaries.

## Governing constraints

- URL is a locator only; canonical identity is a Media UUID or a registered
  endpoint identity.
- WordPress `wp_posts` remains the sole owner of Article title, body, dates and
  editorial URL.
- MediaUsage remains the sole persistence owner of Media-to-consumer placement.
- Runtime endpoint, entity and Media capability registries are authoritative;
  no new hard-coded semantic owner list is permitted.
- A canonical Media may have independent usages for multiple targets.
- Shared Media usage never creates a Graph edge.
- Ambiguity, inactive owners, external hosts, route drift and unavailable
  canonical identity fail closed.
- No duplicate Media, owner creation, data migration, seed, backfill,
  production/staging mutation or deployment is in scope.

## Current root cause

The existing compiler resolves URL targets through the native Post resolver,
so semantic public routes cannot be used. Draft PR #15 adds an Authority route
scan, but it still treats route-derived lookup as sufficient, does not provide
a canonical Media-target resolver boundary, and wires the new resolver without
the Media URL/canonical Public Identity dependencies needed by the contract.
Its Authority scan also risks using compatibility route derivation instead of
the persisted Public Identity/current-route boundary.

## Design

### 1. First-party public target resolver

Introduce a reusable application-facing resolver with a narrow result:

```text
resolve(string $url): { type: string, id: string }
```

The resolver must:

1. parse an absolute HTTPS URL;
2. require the configured first-party host and effective port;
3. reject credentials, query, fragment, duplicate separators and traversal;
4. resolve native WordPress Posts through the existing WordPress URL owner;
5. resolve semantic entities through the registered public-route/identity
   boundary and registered endpoint/entity types;
6. require the resolved object to be active and canonical;
7. compare the normalized requested route with the owner’s exact canonical
   route;
8. reject zero or multiple candidates with typed diagnostics.

The resolver must not infer type from a slug, filename, name, keyword or URL
shape. It must not persist the URL, generate a UUID, use fuzzy matching or
silently accept a historic route. Historic routes are accepted only through an
existing explicit Public Identity historic-route contract that returns one
canonical owner and exact route evidence.

After URL resolution, `MediaEnrichmentIntentCompiler` must pass the result
through `MediaTargetNormalizer`, which validates endpoint registration,
existence, active state, canonical UUID/stable key and revision snapshot.

### 2. Natural-language operation normalization

The operator-facing `representative_bind` intent remains one registered
operation vocabulary. The compiler maps it by normalized target capability:

- `wp_post`: `set_featured`, role and placement `featured_primary`;
- any registered endpoint with representative Media capability:
  `representative_bind`, role `representative`.

An unsupported endpoint or invalid role fails closed. Existing canonical
operation vocabulary is reused; no second natural-language operation family is
introduced.

Media references continue through `MediaBindingService::resolveMediaReference`.
An existing public Media URL must resolve to the existing canonical Media; it
must never cause a new Media identity.

### 3. Usage and projection semantics

Each target receives an independent MediaUsage. Adding a usage for Model B
must not move or replace Brand A’s usage. Replacing one representative only
touches the exact target slot and its CAS-bound usage revision. Existing
replacement/demotion policy remains the owner’s policy; no Media is deleted.

`EntityMediaProjection` reads direct representative usage from the target
scope. `MediaUsageRelationshipAdapter` and the Media read path expose the
reverse list of active and retired usages according to their existing bounded
contract. Neither projection creates semantic relations.

### 4. Article completion boundary

For `wp_post`, the governed execution and final read-back must confirm the same
canonical Media through this chain:

```text
Media → MediaUsage(featured_primary) → attachment bridge → native featured
image → Article/public projection → final read-back
```

Usage-only success, attachment-only success or native thumbnail drift is
partial/failure and cannot be reported as complete. Existing CAS, state-token,
revision and idempotency behavior remains unchanged.

### 5. Runtime wiring

The production composition must construct the resolver from the existing
runtime registries and Public Identity/route owners, alongside the existing
WordPress Post resolver and Media binding service. Unit tests may inject small
resolver doubles, but production code may not fall back to a test-only route
algorithm or a fixed entity list.

## Error contract

At minimum, preserve typed diagnostics for:

- `MEDIA_TARGET_URL_INVALID`
- `MEDIA_TARGET_URL_UNAVAILABLE`
- `MEDIA_TARGET_NOT_FOUND`
- `MEDIA_TARGET_URL_NOT_FOUND`
- `MEDIA_TARGET_URL_AMBIGUOUS`
- `MEDIA_TARGET_TYPE_MISMATCH`
- `MEDIA_TARGET_TYPE_INVALID`
- `MEDIA_FEATURED_TARGET_INVALID`
- `MEDIA_FEATURED_ROLE_INVALID`

No catch-all success, fuzzy fallback or partial completion is allowed.

## Verification contract

Tests must prove:

- exact native Article URL resolution;
- exact Brand, Model, Variant, Classification/Clock Type and all currently
  registered Media-capable endpoint resolution without a new hard-coded list;
- URL host/port/scheme/query/fragment/traversal rejection;
- route drift, inactive owner, ambiguity and unsupported capability rejection;
- canonical UUID/stable-key normalization and no URL-as-identity persistence;
- natural representative wording for Post and semantic targets;
- existing Media reuse and idempotent replay;
- multiple independent usages for one Media;
- bidirectional MediaUsage/entity read-back;
- Article native featured-image and MediaUsage convergence, including drift
  failure.

Verification remains read-only against development data and uses exact
integration guards for any destructive test setup.

## Out of scope

- merging or repairing existing semantic identities;
- public slug allocation or route reprojection;
- importing Article bodies;
- new Graph predicates or edges;
- Media upload, binary deduplication or attachment migration;
- staging acceptance, production cutover, deployment or PR merge.
