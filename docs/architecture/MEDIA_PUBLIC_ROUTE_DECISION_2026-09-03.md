# Media public-route Owner decision — 2026-09-04

> **NON-NORMATIVE.** The Constitution remains the authority.

The Owner ruling for Task 9 is recorded here. `MediaAsset` has no standalone
indexable public detail page by default. Physical/delivery URLs identify asset
delivery only; filename, MediaAsset UUID, alt text, caption, semantic relation,
public slug and public page identity remain separate concepts.

The existing `/media/{slug}/` detail behavior was a `CODE_GAP` against the
Constitution and is retired. `/media/` and `/media/page/{n}/` remain archive
surfaces, while `/media/asset/{uuid}/` remains the delivery-only boundary with
an explicit `noindex, nofollow` response header. No legacy asset was renamed,
no physical URL was changed, and no production data was mutated.

A future Media semantic/editorial page requires a registered Authority/entity
type, Constitution allowance, public eligibility contract, projection contract
and persisted Public Identity where applicable. No such entity is invented in
Task 9.
