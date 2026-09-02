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
