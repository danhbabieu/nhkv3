# Video runtime hotfix upstream audit — 2026-09-07

> Non-normative execution evidence. The Constitution and current Video/Public Identity contracts remain authoritative.

## Finding

The dirty DEMO/server checkout was not evidence of an unrelated server-only feature. The two blocking files were the same Video frontend compatibility area later committed on `main` as `88c2d35`:

- `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php`

The runtime repair added fallback support for persisted Video packets that store source state in `metadata.source` with provenance nested under that source, while preserving `source_snapshot` as the preferred shape when present. No Video re-ingest, UUID replacement, Graph shortcut or second semantic writer is required by this compatibility read path.

## Regression boundary

`MediaVideoPageQueryTest` now directly covers the persisted runtime shape through the public page-query boundary. The test requires canonical `/video/{slug}/` projection, the privacy-enhanced YouTube embed, available source state and nested provenance read-back without exposing raw metadata or requiring re-ingest.

`VideoUrlPolicyTest` already covers the same persisted shape at the URL-policy boundary. Together, the tests lock both the eligibility decision and the reader-facing page-query consumer.

## Deployment checkout rule

The DEMO/server checkout is a deployment/runtime target, not an implementation workspace. Application code changes should be authored in the repository workspace, verified, committed and pushed first; the server should consume them through a clean fast-forward deployment/pull. If a runtime emergency hotfix is unavoidable, preserve a patch/snapshot and upstream that exact change before the deployment checkout is cleaned.

Do not resolve dirty deployment state with destructive reset/clean commands unless the changed files have first been proven to be safely upstreamed or intentionally disposable.
