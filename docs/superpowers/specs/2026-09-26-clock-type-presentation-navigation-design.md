# Clock Type Presentation Navigation Design

## Status

Approved architectural design for the first reusable Presentation Navigation
slice. This document governs only the `clock_type` / `LOẠI` rollout; it does
not authorize semantic Classification mutation, Graph backfill, live/staging
data mutation, or production cutover.

## Goal

Replace the current all-`clock_type` archive/menu projection with a curated,
independent Presentation Navigation tree that controls the `/loai-dong-ho/`
index, Clock Type direct-child navigation, header/mobile/sidebar visibility,
homepage cards, and navigation breadcrumbs.

## Ownership boundary

`Classification` remains the canonical semantic owner. A Clock Type is still
`entity_type=classification` with exact `payload.family=clock_type`; its UUID,
stable key, revision, Graph relations, Public Identity, search eligibility and
semantic dossier remain owned by their existing boundaries.

Presentation Navigation is a separate read/write presentation boundary. A
navigation row references a canonical Authority by `canonical_uuid` and may
cache only presentation metadata required by the navigation store. It must not
copy or become the owner of canonical name, family, semantic parent, claims,
relations, URL identity, or lifecycle state. Canonical display name and public
URL are read through existing Authority/Public Identity services at projection
time.

Semantic `subtype_of` and navigation `parent_id` are independent. No
automatic conversion from Graph hierarchy to navigation hierarchy is allowed.

## Storage model

Add a dedicated table using the existing migration runner, with a new
monotonic migration version after 022:

```text
wp_nhk_presentation_navigation
id BIGINT UNSIGNED AUTO_INCREMENT
navigation_key VARCHAR(64) NOT NULL
canonical_type VARCHAR(64) NOT NULL
canonical_uuid BINARY(16) NOT NULL
parent_id BIGINT UNSIGNED NULL
sort_order INT UNSIGNED NOT NULL DEFAULT 0
enabled TINYINT(1) NOT NULL DEFAULT 1
show_in_type_index TINYINT(1) NOT NULL DEFAULT 0
show_in_header_menu TINYINT(1) NOT NULL DEFAULT 0
show_in_mobile_menu TINYINT(1) NOT NULL DEFAULT 0
show_in_sidebar TINYINT(1) NOT NULL DEFAULT 0
featured TINYINT(1) NOT NULL DEFAULT 0
revision INT UNSIGNED NOT NULL DEFAULT 1
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
PRIMARY KEY (id)
UNIQUE KEY navigation_canonical (navigation_key, canonical_uuid)
KEY navigation_parent_order (navigation_key, parent_id, enabled, sort_order, id)
KEY navigation_placement_order (navigation_key, enabled, sort_order, id)
```

`canonical_type` is retained for scoped validation and future reusable hubs;
the first slice accepts only `classification` plus resolved `clock_type`
profile targets. Foreign-key constraints are not introduced because existing
canonical stores and test doubles do not share a database-level FK boundary.

The repository validates UUID shape, canonical type, navigation key, target
existence/profile, parent existence/navigation key, self-parent/cycle rules,
non-negative sort order, and optimistic `revision` on writes.

## Projection contract

Introduce a reusable `NavigationRepository` and `NavigationTreeProjector`.
The repository exposes exact read/write operations for rows; the projector
exposes placement-aware read models:

```text
roots(navigation_key, placement)
children(navigation_key, canonical_uuid, placement)
breadcrumb(navigation_key, canonical_uuid, placement)
menu(navigation_key, placement)
```

Projection rules:

1. Only rows with `enabled=1` and the requested placement flag are visible.
2. Ordering is `sort_order ASC, id ASC`.
3. A visible node is attached to its nearest visible ancestor in the
   projection, even when one or more stored ancestors are disabled or hidden
   for that placement.
4. If no visible ancestor remains, the node is a projected root.
5. Hidden/disabled ancestors never produce empty menu groups.
6. `children()` returns only direct projected children; it never returns all
   descendants.
7. Canonical name, route and readiness are resolved from existing Authority,
   Entity Profile and Public Identity/query services. A stale/missing target is
   omitted with a diagnostic; it is not recreated.
8. Projection never mutates stored navigation rows or semantic data.

The public `clock_type` adapter is a thin profile-specific composition over
the generic projector. Other hubs are out of scope for this slice but must be
able to reuse the same contracts with a different `navigation_key` and target
resolver.

## Seed and migration

The migration creates only the presentation table and advances the existing
migration ledger through the established guarded UP path. It is idempotent and
must not execute DOWN, DROP, TRUNCATE, or semantic writes.

The initial seed is a separate idempotent service invoked only in an explicitly
authorized local/test seed path. It attempts the following canonical display
names for `navigation_key=clock_type`:

```text
Đồng hồ tủ
Đồng hồ treo tường
Đồng hồ Pháp
Đồng hồ Đức
Đồng hồ chim cúc cu
Đồng hồ 400 ngày
Đồng hồ công cộng
Đồng hồ vai bò
Đồng hồ để bàn
```

Seed resolution must confirm an existing active Classification with exact
`family=clock_type`. Unresolved, ambiguous, retired, or wrong-family results
are recorded as `REVIEW_REQUIRED`/`BLOCKED` diagnostics and never create a
Classification. Replaying the seed produces no duplicate navigation row and
does not reset administrator changes.

## Public consumers

- `/loai-dong-ho/` reads `roots(clock_type, type_index)` only.
- A Clock Type detail page reads `children(clock_type, current_uuid,
  type_index)` only; semantic dossier/hierarchy remains available separately.
- Header and mobile menus use the same menu source with their respective
  placement flags.
- Sidebar uses `show_in_sidebar`.
- Homepage Clock Type cards use a bounded curated placement projection, not
  `archiveProfile('clock_type')`.
- Clock Type breadcrumbs use the projected navigation breadcrumb and retain
  semantic relation sections independently.

The existing fixed hub definitions remain for non-LOẠI hubs. The LOẠI node
list and node entries are removed from the hard-coded `PublicNavigationDefinition`.

## Admin boundary

Add a dedicated LOẠI tree workbench under the existing admin/workbench
boundary. It is presentation-only and must not expose semantic mutation as a
side effect. It supports tree rendering, parent changes, drag/drop ordering,
enabled and per-placement toggles, featured, and revision-conflict feedback.
Writes use the existing authorized Governance/admin mutation boundary or the
smallest equivalent registered presentation operation; direct SQL from the UI
is prohibited.

## Failure and compatibility policy

Unavailable navigation storage is distinct from an empty curated tree. Public
surfaces use the existing honest unavailable/empty states. Semantic search,
related content, Knowledge Graph, semantic breadcrumbs outside LOẠI, and
canonical Classification routes remain unaffected when a node is not curated
or is hidden from a placement.

No template may query the semantic archive and filter it as a compatibility
hack. Every LOẠI presentation consumer receives data from the navigation
projection boundary.

## Verification contract

Tests must prove: multi-level root/direct-child behavior; sort order; every
visibility flag; disabled nodes; hidden parent/visible child nearest-ancestor
projection; root fallback; uncurated Classification exclusion; optimistic
revision conflicts; seed replay/idempotency; route/detail/home/header/mobile/
sidebar/breadcrumb consumers; and byte-level semantic Classification identity
and family preservation before/after navigation operations. Final verification
must include read-back of actual PHP templates and, where the local WordPress
runtime is available, desktop/mobile route checks. If MySQL/runtime remains
unavailable, report that as environment-gated rather than claiming frontend
success.
