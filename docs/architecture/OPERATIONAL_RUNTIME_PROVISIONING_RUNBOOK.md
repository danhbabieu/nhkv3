# NHK V3 Operational Runtime Provisioning Runbook

**Status:** HANDOFF PACKAGE — no provisioning or semantic mutation performed

**Owner:** Infrastructure owner, with NHK V3 Governance owner for the first
semantic operation

**Scope:** Provision and prove one persistent, non-test, non-staging runtime for
the actual NHK V3 website. This runbook does not create Clock Types, allocate
Public Identity, import snapshots, backfill, run PR7 or change staging.

## 1. Acceptance target

The target is accepted only when all of the following describe the same
deployment and datastore:

- dedicated persistent database, not `nhk_v3_test`, not a staging Demo
  database, and not the recovery-only database;
- WordPress and the current NHK Core build are booted from the sanctioned
  deployment path;
- current UP-only migrations and schema verification pass;
- Authority, Graph, Knowledge, Media, Video, Public Identity and Governance
  owners are bound to that same datastore;
- a read-after-write proof is available for a later governed smoke, without
  creating a business entity in this package;
- backup, restore ownership and recovery boundary are recorded;
- a dedicated authenticated MCP connector can read the required documentation,
  read surfaces and governed mutation surfaces; and
- the connector, deployment and datastore identities are recorded together.

The final status is `PERSISTENT_CANONICAL_RUNTIME_READY` only after the
acceptance gates in section 7 pass. Otherwise use one of:

- `CANONICAL_RUNTIME_NOT_PROVISIONED`: no qualifying target exists;
- `CANONICAL_RUNTIME_EXISTS_CONNECTOR_NOT_BOUND`: target exists but no
  authenticated connector is bound to it;
- `CANONICAL_RUNTIME_WRITE_POLICY_BLOCKED`: target and connector exist but the
  active policy does not allow the governed write lifecycle.

## 2. Runtime separation

| Runtime | Purpose | Operational status |
|---|---|---|
| `nhk_v3` | Local development, health, schema inspection and permitted UP-only maintenance | Not an operational website semantic target |
| `nhk_v3_test` | Guarded PHPUnit integration database | Test-only; reset/cleanup exposure; forbidden as operational datastore |
| `https://demo.1945.vn` | Staging/demo read and reconciliation target | Read-only for this handoff; never provision or write here |
| `nhk_v3_video_recovery` | Isolated recovery bootstrap | Recovery-only; must not be promoted to operational data by workaround |
| operational target | Actual persistent NHK V3 website data | Must be separately provisioned and connector-bound |

`RemoteDeploymentAdapter` and `RemoteRuntimeAdapter` remain Demo-target
artifact/maintenance transport. They do not create a database, establish an
operational binding or grant semantic write authorization.

## 3. Environment contract

These are configuration names, not a request to commit values. Values belong in
the infrastructure secret/configuration manager or process environment outside
Git.

### REQUIRED_PUBLIC_CONFIG

- `DB_NAME` — dedicated operational database name; never `nhk_v3_test`.
- `DB_HOST` — deployment-local database endpoint; expose only if the provider
  permits it, otherwise use an opaque binding ID.
- `DB_PREFIX` — WordPress table prefix, normally `wp_`.
- `WP_ENVIRONMENT_TYPE` — registered non-test, non-staging operational value.
- `WP_HOME` and `WP_SITEURL` — the canonical operational site URL, not
  `https://demo.1945.vn`.
- `NHK_RUNTIME_ENVIRONMENT` — stable deployment/runtime marker.
- `NHK_RUNTIME_MODE` — operational mode registered by the deployment owner;
  it must not be `recovery`.

### REQUIRED_SECRET_CONFIG

- `DB_USER` and `DB_PASSWORD`.
- WordPress authentication keys and salts: `AUTH_KEY`, `SECURE_AUTH_KEY`,
  `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`,
  `LOGGED_IN_SALT`, `NONCE_SALT`.
- MCP authentication material at the connector/provider boundary.
- `NHK_YOUTUBE_API_KEY` only if the operational Video source capability needs
  it.

Secret values, DSNs, private keys and tokens must never enter Git, docs,
generated snapshots, REST/MCP payloads or acceptance logs.

### OPTIONAL_CONFIG

- `DB_CHARSET` and `DB_COLLATE`.
- `WP_DEBUG=0` or an unset debug flag for the operational target.
- Provider-specific process supervision, cache and PHP-FPM settings kept
  outside the repository.

### FORBIDDEN_TEST_CONFIG

- `NHK_WP_TEST_DB=nhk_v3_test`.
- `NHK_WP_TEST_PATH=public` for operational execution.
- `NHK_AUTHORIZED_MIGRATION_DATABASE=nhk_v3_test`.
- `NHK_RUNTIME_MODE=recovery` and
  `NHK_RECOVERY_ALLOWED_DATABASES` on the operational target.
- Any Demo/staging database binding or reuse of the recovery prepend as an
  operational identity.

## 4. Provisioning sequence

The repository does not own infrastructure provisioning. The infrastructure
owner performs the external steps below and supplies evidence; no ad-hoc SQL,
manual table copy or test reset is an accepted substitute.

1. Create one dedicated persistent database externally and record its opaque
   `database_binding_id`. Establish backup ownership before application boot.
2. Bind WordPress runtime secrets and non-secret environment markers through
   the approved deployment configuration. Keep all secret values outside Git.
3. Bootstrap WordPress at the operational site and verify the site URL and
   deployment/build identity.
4. Install and activate the exact NHK Core artifact through the approved
   deployment path. Do not use `RemoteDeploymentAdapter` as a database
   provisioning mechanism; its current allowlist is Demo-only.
5. Run the existing maintenance entrypoint with the sanctioned UP-only
   migration path. Never run DOWN, DROP, TRUNCATE or reset on the operational
   database.
6. Verify current migrations, required tables and schema columns through
   `MigrationStatus`/health read surfaces.
7. Run fresh canonical documentation bootstrap and record
   `documentation_version`, `manifest_hash` and `build_identity`.
8. Verify that Authority, Graph, Knowledge, Media, Video, Public Identity and
   Governance all read from the same binding. Empty data is distinct from an
   unavailable reader.
9. Register a dedicated authenticated MCP connector for this deployment.
   Record runtime capability, connector exposure and write authorization as
   separate facts.
10. Run the non-destructive acceptance procedure in section 7. Only after all
    gates pass may the Governance owner run the separate PLAN-only smoke.

## 5. Runtime identity proof

The infrastructure owner must produce one redacted identity packet proving:

```text
connector_id
→ site_url
→ deployment/build_identity + runtime_version
→ database_binding_id
→ migration_current / migration_target
→ documentation_version / manifest_hash
→ write_policy
```

`database_binding_id` is an opaque deterministic deployment identifier or an
architecture-sanctioned equivalent. It is not a semantic field and must not
contain a password, DSN or raw credential. The acceptance CLI
`tools/operational-runtime-acceptance.php` checks the local runtime side of
this packet and compares optional expected connector/binding values supplied
by the infrastructure wrapper; it does not register a connector or impersonate
one.

Required evidence fields:

| Field | Evidence rule |
|---|---|
| environment | Must identify the registered operational runtime, not staging/test/recovery |
| site URL | Must match the operational WordPress binding |
| build identity | Must come from the deployed NHK Core/documentation bootstrap |
| runtime version | Must match the active plugin/runtime |
| database binding | Opaque ID or sanctioned equivalent; no secrets |
| migrations | `current == target` and schema read-back ready |
| documentation | Fresh bootstrap with matching version/hash |
| write policy | Explicit external policy evidence; tool availability alone is insufficient |

## 6. MCP connector registration checklist

Register one connector whose endpoint and authentication boundary are owned by
the operational deployment. Do not reuse the staging connector or the absent
recovery connector.

Record separately:

- endpoint URL and TLS boundary;
- authentication mechanism and secret owner, without storing the secret;
- allowed runtime/environment and `connector_id`;
- read permissions for documentation, search, inventory, entity, Graph,
  Knowledge, Media, Video and Public Identity;
- governed write permissions for Capture ingest, Proposal review/submit,
  Approval, Eligibility and Controlled Apply;
- canonical read-back permission after every governed operation;
- file capability only when an owning Media contract requires it; and
- denial of direct database, generic WordPress, direct Authority/Graph/
  Knowledge/Media/Video/Public Identity writers.

The executable capability names to verify against the deployed catalog are:

```text
nhk.docs.bootstrap
nhk.docs.get
nhk.documentation.list
nhk.search
nhk.canonical.inventory
nhk.entity.get
nhk.graph.inventory
nhk.capture.ingest
nhk.proposal.review
nhk.proposal.approve
nhk.proposal.eligibility
nhk.proposal.apply
```

`nhk.proposal.apply` is a governed boundary, not permission to bypass approval
or eligibility. A connector exposing a tool in the catalog but lacking the
required authenticated client exposure is not ready.

## 7. Non-destructive acceptance gates

Run `tools/operational-runtime-acceptance.php` from the target deployment (or
through an approved read-only wrapper) with an explicit expected environment
and site. The tool must exit non-zero for any unavailable, forbidden or
ambiguous gate.

### Gate A — runtime identity

PASS only when environment, site, build/runtime identity and the opaque
database binding match the expected deployment. `demo.1945.vn`, test and
recovery values fail closed.

### Gate B — migrations

PASS only when `migration_current == migration_target` and existing schema
readiness checks pass. The acceptance command never runs a migration.

### Gate C — documentation

PASS only after fresh `McpDocumentationRegistry::bootstrap()` and manifest
validation. Record documentation version and manifest hash.

### Gate D — read surfaces

PASS only when existing read boundaries for Authority, Graph, Knowledge, Media,
Video and Public Identity are available on the same runtime. A zero-record
result is valid only when the reader is available; it is not a substitute for
an unavailable reader.

### Gate E — governance

The package verifies that the deployed catalog exposes the governed
`nhk.capture.ingest` and Proposal review/approval/eligibility/apply surfaces.
The first operational smoke is PLAN-only and must be run separately by the
Governance owner after this package passes. It must not Apply a business entity.

### Gate F — persistence

Read the identity packet across two request/process boundaries and compare the
same site, build, migration and opaque database binding. A restart or request
boundary that changes the binding fails closed.

### Gate G — test isolation

Prove the operational configuration does not set `NHK_WP_TEST_DB=nhk_v3_test`
and that the integration `TestDatabaseGuard` cannot authorize this datastore.
Do not run destructive tests against the operational database.

### Gate H — backup/recovery

Record backup owner, retention, encrypted storage boundary, restore drill,
checksum/identity validation and rollback authority. A recovery runtime is not
an operational website target, and `nhk_v3_test` is never a restore target.

Only A–H PASS permits `PERSISTENT_CANONICAL_RUNTIME_READY`.

## 8. First operational business action — document only

After the runtime gate passes, the separate owner-approved action is:

```text
Search/reuse
→ PLAN “Đồng hồ công cộng” through nhk.capture.ingest
→ owner approval
→ Proposal review / eligibility
→ governed Controlled Apply
→ canonical read-back
→ separate Public Identity plan/apply
→ separate Knowledge / Article / Media / Video workflows
```

This package does not execute any step above and does not recreate the prior
test-runtime UUID. Any operational entity must be created or reused on the
verified operational runtime through the current governed lifecycle.

## 9. Rollback and recovery checklist

- Stop the workflow on identity mismatch, migration drift, connector mismatch,
  policy denial or read-after-write mismatch.
- Do not repair by direct SQL, row copy, UUID regeneration, test reset or
  staging replay.
- For infrastructure failure, use the provider-owned backup/recovery path and
  record the restored deployment/database binding before enabling the connector.
- For semantic failure after a governed operation, use the existing Proposal,
  ApplyAttempt and canonical read-back/retry boundaries; do not call a generic
  writer.
- A restore must preserve canonical UUIDs, stable keys, revisions, Proposal
  history, Graph edge identity and Public Identity history according to the
  applicable snapshot contract.
- Re-run all gates A–H after recovery. Recovery success is not operational
  readiness until the target is explicitly registered as the operational site.

## 10. Handoff completion record

Infrastructure owner fills this record without secrets:

```text
runtime_name:
environment:
site_url:
connector_id:
deployment_build_identity:
runtime_version:
database_binding_id:
migration_current:
migration_target:
documentation_version:
manifest_hash:
write_policy:
backup_owner:
recovery_owner:
gate_A:
gate_B:
gate_C:
gate_D:
gate_E:
gate_F:
gate_G:
gate_H:
status:
```

Until this record is complete and independently verified, the status remains
`CANONICAL_RUNTIME_NOT_PROVISIONED`, `CANONICAL_RUNTIME_EXISTS_CONNECTOR_NOT_BOUND`
or `CANONICAL_RUNTIME_WRITE_POLICY_BLOCKED` as applicable.

**No semantic mutation:** PASS. This runbook creates no Authority, Capture,
Proposal, Graph edge, Knowledge, Evidence, Source, Media, Video, Article,
Public Identity, route or Clock Type.
