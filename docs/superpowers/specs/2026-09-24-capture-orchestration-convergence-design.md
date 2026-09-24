# Capture Orchestration Convergence Design

## Goal

Make the canonical NHK V3 Capture runtime converge systemically for Article,
shared enrichment, Media binding and completion outcomes without creating new
owners, bypassing Governance, weakening staging admission or depending on
object-specific data.

## Constraints

- `nhk.capture.ingest` remains the only normal new-content entry point.
- `IMAGE_ARTICLE` and `TEXT_ARTICLE` may create at most one native WordPress
  draft; `MEDIA_ENRICHMENT` remains Media-only.
- Existing canonical UUIDs, stable keys, revisions and governed read-backs are
  reused; no hard-coded fixture identity enters production logic.
- Direct unscoped Media binding continues to fail closed with
  `STAGING_SCOPE_REQUIRED`.
- Required owners contain only non-empty canonical IDs. Optional enrichment
  debt is represented by typed diagnostics, not blank owners.
- `COMPLETE` requires canonical owner read-back and final convergence evidence.
- Capture `dry_run` is removed from the canonical Capture schema because no
  general Capture dry-run contract exists; dedicated preview operations remain.

## Design

`EditorialCaptureCoordinator` will classify preparation outcomes before the
owner pipeline. A resolved subject with only deferrable enrichment review may
continue to draft, shared enrichment, composition and final reconciliation.
Identity ambiguity, explicit conflict, hard blocker or unavailable required
dependency remains `REVIEW_REQUIRED`/`BLOCKED`, but the response exposes the
existing preparation reason, candidate/review packet and a valid continuation
path rather than silently stopping.

Shared enrichment is invoked from the server-owned intent/needs path whenever
the resolved profile is applicable. The result and completion receipt preserve
the existing envelope, retrieval, KnowledgeUnit/Coverage and provenance
boundaries. Subject-unresolved outcomes are reported as the actual typed
diagnostic already available in the preparation vocabulary.

Required-owner construction will normalize and deterministically deduplicate
only non-empty canonical identities. Missing Media for an IMAGE_ARTICLE stays
in the Article/media diagnostic family and remains a publication blocker when
the contract requires it; it is never represented as `media` with an empty
ID.

The existing Capture Media fast path remains the only normal representative
binding path. Tests will prove that it issues/propagates a server-created
scope and performs final read-back, while direct unscoped compatibility calls
remain blocked.

The Capture schema will be made truthful across Catalog, Ability and Easy MCP
projections by removing the unsupported `dry_run` field. Dispatcher behavior
for dedicated preview tools is unchanged.

Article composition will consume the existing shared editorial boundary and
retain claim trace internally, while filtering workflow diagnostics, source
identification boilerplate and Video-only fragments from non-Video public
Article prose.

## Verification

Tests will cover the generic matrix requested in the task, with focused unit
regressions first and the full PHPUnit suite plus lint, diff check and secret
review before the checkpoint. Guarded WordPress integration will be attempted
only against exact `nhk_v3_test`; no staging/production mutation is performed.

