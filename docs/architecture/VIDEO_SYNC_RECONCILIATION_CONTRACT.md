# Video Source Sync and Reconciliation Contract

> Non-normative implementation contract under the sole NHK V3 Constitution.

Sync fetches a new YouTube source snapshot and compares it with the stored
snapshot. It is not an overwrite operation. The comparison reports
`NO_CHANGE`, `SOURCE_CHANGED`, `SOURCE_UNAVAILABLE` or `REVIEW_REQUIRED`, with
changed source fields and a reconciliation-required signal.

A changed title, description, thumbnail, tag set, duration, availability or
embed state never overwrites NHK editorial title/body or Graph relations.
Availability changes suppress the embed/normal public projection and remove
the item from the video sitemap while preserving the Video identity,
provenance and historical relations.

The current `VideoSyncService` is a read-only comparison boundary. Applying a
new snapshot or reconciliation proposal remains subject to the existing
Proposal → human approval → eligibility → Controlled Apply lifecycle.

## Staging acceptance — Performance Phase 3.10

On staging, `nhk.video.source.refresh` additionally requires the existing
server-issued `staging_acceptance` packet. The packet is signed by the
`StagingAcceptanceScopeVerifier` and is bound to `environment=staging`,
`operation_family=video_source_refresh`, `entity_type=video`,
`operation=source_refresh`, the exact Video UUID, expected Video/source
revisions, idempotency key and request fingerprint, with `issued_at` and
`expires_at` validity. The packet is carried only on the internal governed
Proposal path; MCP input does not expose a client-supplied acceptance field.

The generic `OperationScopedStagingGuard` still requires proposal-apply
capability plus the source-refresh proposal capability. Missing, forged,
expired, retargeted, wrong-operation, wrong-environment or tampered packets
fail closed before Proposal creation/apply. Production rejects staging
acceptance. CAS, idempotency, source-only mutation and canonical read-back
remain unchanged.

## Governed source refresh — Performance Phase 3.8

The registered internal/admin command `nhk.video.source.refresh` creates a
`video + source_refresh` Proposal for exactly one canonical Video. It accepts
only the Video UUID, expected Video revision, optional expected source revision
and an idempotency key. The command resolves the Video's canonical YouTube
identity, fetches the official API snapshot through `YouTubeDataApiClient`,
validates the bounded `YouTubeSourceSnapshot`, compares it with persisted source
metadata and returns a Proposal; it never writes directly.

Controlled Apply may replace only the existing `source` or `source_snapshot`
metadata field, including `thumbnail_candidates`, `thumbnail_presentation`,
canonical thumbnail selection and other fields owned by that snapshot. Video
title, editorial package, subject handoff, Graph relations, Media identity and
active/publication state are protected. Both Video revision and source revision
are checked; stale bindings fail with zero mutation. Source/API failure and
malformed snapshots create no Proposal and preserve the old snapshot.

Same idempotency key and request binding replays the same Proposal. A changed
binding conflicts. Controlled Apply returns the canonical Video read-back; a
no-change comparison is represented as a governed no-op Proposal and does not
increment source or Video revision.
