# P6 Media + Video Foundation

> **NON-NORMATIVE.** This is implementation evidence. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Current status

The domain boundary and schema migration are implemented and migration 004 is
applied to `nhk_v3`. Persistence services and Media/Video Graph endpoint
resolvers are now implemented and covered by focused unit evidence; WordPress
database integration and legacy data mapping remain in progress.

## Media contract

`Media` is semantic identity. `MediaAsset` owns a binary storage key, checksum,
MIME and technical dimensions. `MediaUsage` owns placement and role. A checksum
is indexed as a duplicate candidate only; it never merges semantic Media
identities. The schema keeps Media, asset and usage in separate tables and does
not make an attachment or asset a Graph endpoint by default.

## Video contract

`Video` is an external reference. The initial adapter normalizes YouTube watch,
short, embed and `youtu.be` URLs to platform plus external ID and canonical URL.
It stores metadata and an optional thumbnail Media reference; it does not
download MP4 files or create a local binary implicitly.

## Migration

Migration `004` creates `nhk_media`, `nhk_media_assets`, `nhk_media_usages` and
`nhk_videos`. It is idempotent, UP-only on `nhk_v3`, and DOWN-guarded to
`nhk_v3_test`. No V2 data has been migrated.

## Persistence slice evidence

`MediaService` owns creation, idempotent identity lookup, optimistic updates,
lifecycle changes, asset registration and usage registration. `VideoService`
owns canonical external-reference ingestion, deduplication by platform/id,
optimistic updates and lifecycle changes. `Wpdb*Repository` implementations
persist the four P6 tables without treating checksum matches as merges.
`MediaEndpointResolver` and `VideoEndpointResolver` make canonical UUIDs
available to the shared Graph endpoint registry while retaining retired
records as resolvable identities.

Focused P6 evidence: 8 tests, 24 assertions; all unit evidence: 46 tests, 106
assertions; PHP lint and `git diff --check` pass. WordPress integration tests
require `NHK_WP_TEST_PATH` and were not runnable in the current shell.
