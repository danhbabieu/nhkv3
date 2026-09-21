# NHK V3 Release & Cutover Acceptance Plan

> Operational release evidence only. This file does not amend the
> Constitution, ACTIVE contracts, or the closed architectural program.
> No deployment, public-identity reprojection, proposal apply or live data
> mutation was performed by this workstream.

## Intended release package

```text
CURRENT_STAGING_REVISION=33fa785ebe0177e56c828e7660fa8a4794a5e5d3
INTENDED_RELEASE_REVISION=33fa785ebe0177e56c828e7660fa8a4794a5e5d3
INTENDED_DOCUMENTATION_VERSION=c27d5093b3c91b0a66ae84a7fb19fdaa88bf699030d8be4be4c0ba210e2aaba7
INTENDED_MANIFEST_HASH=0777d7243ae1218154ef7dba2a08740745a9eecbf6264e6ed06af9bdc37f6502
INTENDED_CATALOG_VERSION=ac7bb409c18b9a3daea8e7f72aa47c95509b1d1234b082abc4d8015f99885650
INTENDED_RESOURCE_VERSION=2644fca6bc74621085ce7271d21053ce7bff1e6011e842eee945015bb8d10c03
STAGING_BUILD_IDENTITY=e68a09e14d61bdf6c770f318cdaa04f786c6d940e704d8268fd0e0c8f40d7830
STAGING_RELEASE_IDENTITY=69e7380280573744480bc24cf432ba8b69f0ac50e59960af313bfefd54d4d2cb
LOCAL_REGENERATED_BUILD_IDENTITY=e68a09e14d61bdf6c770f318cdaa04f786c6d940e704d8268fd0e0c8f40d7830
LOCAL_REGENERATED_RELEASE_IDENTITY=681ba9e48483066221d7ee5b61422030ff6a188c2f24ed0c04cac4fb6ddd6777
LOCAL_GENERATION_DETERMINISM=PASS;TWO_GENERATIONS_SAME_BUILD_IDENTITY
BUILD_IDENTITY_ROOT_CAUSE=TIMESTAMP_DEPENDENT_IDENTITY;FIXED_IN_LOCAL_BUILD_TOOLING
RELEASE_PACKAGE_READY=YES_CURRENT_STAGING_ACCEPTED
DEPLOYMENT_REQUIRED=NO
```

The current staging package contains the deterministic build-identity fix and
matches the locally regenerated documentation/build/catalog/resource tuple.
No further deployment is required. The local/staging release-identity strings
are environment-dependent projections and are not evidence of package drift.

The release verification wrapper also requires a clean/classified worktree.
Current modified files from the preceding architectural workstream are
classified as `PRE_EXISTING_PROGRAM_DOCUMENTATION`; they must not be silently
absorbed into a release package.

## Connector gap classification

| Tool | Runtime | Tools/list | Connector | Expected visibility | Classification | Normal alternative | Release blocker |
|---|---:|---:|---:|---|---|---|---|
| `nhk.relation.backfill.apply` | 1 | 1 | 0 | Admin/governed relation maintenance | `ADMIN_ONLY` / `INTENTIONALLY_INTERNAL` | Capture discovery + governed Proposal/Apply | No for normal operator; yes for this admin operation |
| `nhk.category.create` | 1 | 1 | 0 | Native editorial taxonomy admin | `ADMIN_ONLY` | Article/Capture category gateway | No |
| `nhk.category.update` | 1 | 1 | 0 | Native editorial taxonomy admin | `ADMIN_ONLY` | Article/Capture category gateway | No |
| `nhk.category.assign` | 1 | 1 | 0 | Native editorial taxonomy admin | `ADMIN_ONLY` | Article/Capture category gateway | No |
| `nhk.category.unassign` | 1 | 1 | 0 | Native editorial taxonomy admin | `ADMIN_ONLY` | Article/Capture category gateway | No |
| `nhk.category.delete` | 1 | 1 | 0 | Destructive taxonomy admin | `ADMIN_ONLY` | Human-reviewed WordPress admin boundary | No; unsafe to expose by default |
| `nhk.media.upload-batch` | 1 | 1 | 0 | Multipart/binary transport | `BINARY_OR_SPECIAL_TRANSPORT` | `nhk.media.widget-upload` for provided-file references; native multipart | Media upload acceptance only |
| `nhk.media.ingest` | 1 | 1 | 0 | Internal governed Media lifecycle | `INTENTIONALLY_INTERNAL` | Capture → attachment → governed Media ingest | No for normal operator |
| `nhk.video.ingest` | 1 | 1 | 0 | Internal governed Video lifecycle | `INTENTIONALLY_INTERNAL` | Capture with registered Video intent | No for normal operator |
| `nhk.video.source.refresh` | 1 | 1 | 0 | Admin-only source reconciliation | `ADMIN_ONLY` | Read-only source comparison; governed admin command | No for normal operator |
| `nhk.knowledge.ingest` | 1 | 1 | 0 | Internal governed Knowledge lifecycle | `INTENTIONALLY_INTERNAL` | Capture `KNOWLEDGE_DELTA` | No for normal operator |
| `nhk.proposal.reject` | 1 | 1 | 0 | Governance reviewer/admin | `ADMIN_ONLY` | Reviewer/admin Governance boundary | Yes only for full connector-based rejection workflow |

The 12 gaps are not evidence of runtime absence. Runtime registration and
`tools/list` both report 58; connector exposure reports 46. No dangerous
mutation tool should be exposed solely to obtain 58/58 connector visibility.

## Public Identity cutover policy

The supplied audit fingerprint is:

```text
AUDIT_FINGERPRINT=20001d5a12fb574e08fec515ac9b7a29152ae9a5717b9943a2270c011a623498
TOTAL=503; KEEP=62; ALLOCATE=329; CHANGE=34; BLOCKED=78
```

`KEEP` is read-only confirmation. `ALLOCATE` and `CHANGE` are recommendations,
not authorization. Existing durable identity is preserved; no convenience
suffix, merge, bulk reproject or guessed route is allowed.

### Cutover waves

| Wave | Scope | Current status | Preconditions | Rollback |
|---|---|---|---|---|
| Wave 0 | 62 `KEEP` owners | Read-only only | Current identity, route and consumer read-back | None; no mutation |
| Wave 1 | Small bounded `ALLOCATE` subset | 0 approved owners | Exact owner IDs, eligible route profile, no collision, no historic identity, expected revision, fresh fingerprint | Owner-scoped governed inverse/history |
| Wave 2 | Reviewed `CHANGE` subset | Deferred | Explicit owner decision preserving existing route/history | Owner-scoped identity history; no bulk reverse rewrite |
| Wave 3 | Remaining complex/blocked cases | Deferred | Collision/inventory/semantic review complete | Manual owner-scoped recovery |

Because the live receipt supplied only aggregate counts and examples, no exact
owner roster was supplied for all 78 blocked or 329 allocate records. Therefore
`SAFE_ALLOCATE_COUNT=0` for this local planning checkpoint; no Wave 1 owner is
authorized.

## Live mutation acceptance protocol — design only

No fixture was authorized in the supplied evidence. The first staging mutation
requires a dedicated, disposable staging fixture or an explicit human-selected
existing owner. It must execute exactly:

`Proposal → Submit → Review → Approve → Eligibility → Controlled Apply → canonical readback → projection readback → frontend readback → idempotent retry`.

Forbidden targets include Article 55, Article 575, Article 548, the Cuckoo
Clock route, Hermle stable routes and all collision owners. Expected mutation
count must be explicit before approval; any unrelated-owner mutation, stale
revision, binding mismatch or route change stops the run.

## Deployment runbook — not executed

1. Confirm exact release revision and clean/classified worktree.
2. Run `composer install --no-interaction --prefer-dist --no-progress`.
3. Run `composer generate:mcp-docs`.
4. Verify source revision, documentation version, manifest, catalog, resource,
   build and release identities.
5. Run deployment preflight and package secret review.
6. Transfer only through the existing allowlisted deployment wrapper.
7. Verify remote bootstrap, `tools/list`, documentation bootstrap and manifest.
8. Run read-only golden cases, route/frontend smoke and rollback-readiness checks.

The wrapper must stop on any identity mismatch, worktree ambiguity, runtime
parity regression or read-back mismatch. This plan does not authorize step 6.

## Rollback plan

- Code/package: restore the previously verified deployable package/revision;
  do not use an unverified local tree.
- Documentation: restore the previous immutable documentation snapshot and
  verify manifest/build/release identities together.
- Public Identity: use the owner-scoped durable history/governed inverse; never
  run a bulk reverse rewrite.
- Semantic mutation: use only the domain-governed inverse where a contract
  defines one; never edit SQL directly.
- URL collisions: preserve both owners and existing history; stop for manual
  ownership/route reconciliation.

## Cutover stop conditions

`BUILD_IDENTITY_MISMATCH`, `DOCUMENTATION_MANIFEST_MISMATCH`,
`RUNTIME_TOOL_PARITY_REGRESSION`, `CANONICAL_READBACK_MISMATCH`,
`PUBLIC_URL_COLLISION`, `OWNER_REVISION_STALE`, `PROPOSAL_BINDING_STALE`,
`FRONTEND_ROUTE_MISMATCH`, `UNEXPECTED_URL_CHANGE`,
`UNRELATED_OWNER_MUTATION`.
