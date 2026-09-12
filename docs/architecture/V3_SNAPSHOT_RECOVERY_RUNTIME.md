# V3 Snapshot Recovery Runtime

Status: `RUNTIME_ADAPTERS_IMPLEMENTED / LOCAL_RUNTIME_PROVISIONED / SNAPSHOT_NOT_IMPORTED / READY_FOR_FIRST_RECOVERY_WAVE: NO`

This document defines the isolated restore boundary for the historical Video
backlog. `https://demo.1945.vn` is a staging source and remains read-only. It
must never be used as an import target or as a write-capable recovery runtime.

## Snapshot boundary

The application boundary is a normalized, logical repository snapshot with
schema version `v3-semantic-snapshot/1`. Physical table names remain adapter
owned. The required collections are:

`captures`, `capture_addenda`, `editorial_posts`, `editorial_post_meta`,
`post_taxonomy`, `authority_entities`, `knowledge`, `sources`, `claims`,
`evidence`, `proposals`, `proposal_approvals`, `proposal_audit_history`,
`apply_attempts`, `media`, `media_assets`, `media_usages`, `videos`,
`graph_nodes`, `graph_predicates`, `graph_edges`, `public_identities`,
`completion_state`, and `idempotency_state`.

Native draft Posts and only the related taxonomy/meta required for provenance
are included. Sessions, caches, auth tokens, credentials, secrets and unrelated
WordPress operational data are excluded. A source adapter must expose every
logical collection, including an empty collection; an absent collection is an
export failure, not an empty dataset.

## Export contract

`CanonicalSnapshotExportService` consumes a `CanonicalSnapshotSource`. The
adapter is read-only and must report current migrations, schema consistency,
documentation version, build identity, repository inventory and normalized
records. The service:

1. refuses a writable source, stale migrations, inconsistent schema or an
   unsupported schema version;
2. canonicalizes object keys and record ordering;
3. preserves UUIDs, stable keys, revisions, lifecycle states and timestamps;
4. computes per-collection count/content hashes and a manifest hash; and
5. returns a versioned artifact suitable for an explicitly controlled file
   transfer.

The manifest hash excludes only `manifest_hash` and the generated
`exported_at`, so repeated exports of the same data have the same content and
identity hashes. The old raw `backup/snapshot` maintenance branch is retired
and returns `LEGACY_RAW_SNAPSHOT_RETIRED`; it is not a semantic recovery path.

The maintenance entrypoint is:

```text
php public/wp-content/plugins/nhk-core/bin/nhk-core-maintenance.php \
  --operation=v3-snapshot-export --output=/path/to/artifact.json --json
```

It requires the registered `nhk_v3_snapshot_source` adapter. The production
`WpdbCanonicalSnapshotSource` binds the existing canonical WPDB stores through
a read-only composition seam; it has no Capture, Governance, Video, Knowledge,
Graph or Public Identity mutation methods. Live export still requires the
operator-controlled Demo deployment/authentication boundary.

## Import contract and guard

`CanonicalSnapshotImportService` validates the manifest, all collection hashes,
duplicate identities and the supported referential closure before opening the
writer. The writer is transactional and must use canonical application
services; it is not a SQL or generic writer. Import order preserves dependency
history: Authority and Capture/Post context, Knowledge and provenance, Proposal
history and ApplyAttempts, Media/Video, Graph, Public Identity, then completion
and idempotency state.

Import is allowed only when all conditions hold:

- explicit `--recovery-mode` confirmation is present;
- target runtime mode is exactly `recovery`;
- target name is not `staging`, `production` or `test`;
- target site is not Demo and target database is not the source database;
- target database is explicitly allow-listed; and
- target schema is current and consistent.

The writer stores the manifest hash as the restore receipt. Re-importing that
same hash is idempotent after a complete read-back. A different hash, a
non-empty namespace or any read-back count/content mismatch fails closed.
Public Identity paths, Graph edge UUIDs, Proposal history, ApplyAttempts and
Article draft IDs are therefore preserved rather than recreated.

The maintenance import entrypoint is:

```text
php public/wp-content/plugins/nhk-core/bin/nhk-core-maintenance.php \
  --operation=v3-snapshot-import --input=/path/to/artifact.json \
  --recovery-mode --json
```

It requires a registered `nhk_v3_snapshot_writer` adapter. The production
`WpdbCanonicalSnapshotWriter` is bound only when the runtime is explicitly
recovery, uses the allow-listed database and a transaction, preserves source
identities/revisions/history and is followed by application read-back
verification. It is never registered on Demo/staging or production.

## Recovery runtime lifecycle

The intended isolated deployment is:

| Field | Required value/policy |
|---|---|
| Environment | `v3-video-recovery-1309` |
| Runtime mode | `recovery` |
| Site | dedicated recovery hostname or isolated local URL, never Demo |
| Database | dedicated non-production DB, proposed `nhk_v3_video_recovery` |
| Connector | dedicated `@V3-Recovery`, never the Demo connector |
| Writes | governed Proposal → eligibility → Controlled Apply → read-back |
| Articles | required native Posts remain draft; no Article auto-publish |
| Migrations | normal UP migrations only, current repository level 20 |

Provisioning requires infrastructure credentials outside Git and a running
WordPress/MySQL service. The local MySQL endpoint is available and the
dedicated database `nhk_v3_video_recovery` has been provisioned. WordPress and
NHK Core boot on an isolated local site at `http://127.0.0.1:8090` using the
non-secret `tools/recovery-runtime-prepend.php` bootstrap; read-back reports
the recovery database identity, runtime `v3-video-recovery-1309`, mode
`recovery`, active NHK Core and guarded UP migration marker `20/20`. This is a
real empty bootstrap only: no historical data was copied into it. No approved
snapshot artifact or registered `@V3-Recovery` connector has been provided, so
this local runtime cannot pass the golden gate yet. The runtime adapter
registration is now present in code; live source export remains operator-gated
and the recovery writer remains dormant until an artifact is approved.

Raw DB backup/restore may be used by infrastructure solely to provision the
isolated database, subject to its own backup controls. It does not replace the
normalized semantic export/import contract and cannot be used for backlog
mutation or repair.

## Golden acceptance

Before enabling recovery writes, a fresh isolated bootstrap must read back:

- Capture `01a094df-6e43-7226-a3a5-78a6c4c05c7e`;
- Video `01a094df-6ff2-7872-9bbe-ca4e843a68ef`;
- Variant `852da54d-457a-4397-a16d-52d9452ba766`;
- active Video → `about` → Variant edge;
- Public Identity `01a094df-72cb-7a30-bb1e-bc783f014fd6`;
- `/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/`;
- matching detail, `/video/` collection and draft Post 454; and
- only the warning `TRANSCRIPT_UNAVAILABLE`.

Any mismatch is `GOLDEN_IDENTITY_MISMATCH` and stops the process. This gate is
`NOT RUN` in the current checkout because the isolated runtime is not present.

## Editorial enrichment gate

The existing `VideoEditorialEnrichmentService` builds one immutable bounded
context from `SPECIMEN`, `SOURCE_FACT`, `CANONICAL_CONTEXT` and related
canonical Knowledge/Entity IDs. Direct Graph neighborhood is preferred. The
service only projects editorial content and never creates Knowledge, Evidence,
Graph, Media, Authority or Public Identity objects.

`CONTENT_COMPLETE` requires meaningful summary/body/why-this-matters, preserved
specimen scope, no unsupported universal claim, useful canonical context when
available, and related canonical references when relevant. Final Video status
is derived as:

`TECHNICAL_COMPLETE + CONTENT_COMPLETE + PUBLIC_COMPLETE = COMPLETE_VERIFIED`.

The context service passes code-side tests. Restored-data enrichment against
golden #372 and another Video remains pending the isolated runtime.

## Wave process and release strategy

The first wave is preview-only until golden acceptance passes. It contains the
five safest already-applied owners:

1. `P4KaHX3LBOw`
2. `TsQWw2Q6-HM`
3. `4d4oxh35cT8`
4. `truOChTNbwA`
5. `V18Me9TdnkU`

Process at most five items per wave. For each item: canonical read, dependency
reuse, governed prerequisite recovery, post-apply reconciliation, enrichment,
completeness recomputation, Graph/Public Identity reconciliation and full
frontend read-back. Applied Proposals are immutable; associated Articles remain
draft. Conflicts, orphan owners, missing private provenance or any identity
ambiguity remain fail-closed and do not stop unrelated items.

Recovery is not production cutover. After isolated waves pass technical,
content, public and frontend verification, produce a separate cutover
readiness report. No deploy, push or production cutover is automatic.
