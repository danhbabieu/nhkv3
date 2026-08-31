# P6 Media + Video Foundation

## Current status

The domain boundary and schema migration are implemented and migration 004 is
applied to `nhk_v3`; persistence services, Graph relations and legacy data
mapping remain in progress.

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
