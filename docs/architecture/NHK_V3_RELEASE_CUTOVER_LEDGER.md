# NHK V3 Release & Cutover Ledger

> Operational state for `NHK_V3_RELEASE_AND_CUTOVER_ACCEPTANCE`. This is not
> semantic authority and does not replace the Unified Canonical Evolution
> Program Ledger.

```text
WORKSTREAM=NHK_V3_RELEASE_AND_CUTOVER_ACCEPTANCE
ARCHITECTURAL_PROGRAM=ARCHITECTURAL_PROGRAM_COMPLETE_PENDING_LIVE_MUTATION_OR_DEPLOYMENT_ACCEPTANCE
CURRENT_STEP=FINAL_RECONCILIATION_PASS_STAGING_PACKAGE_ACCEPTED
INTENDED_RELEASE_REVISION=33fa785ebe0177e56c828e7660fa8a4794a5e5d3
STAGING_REVISION=33fa785ebe0177e56c828e7660fa8a4794a5e5d3
WORKTREE_CLASSIFICATION=FINAL_RECONCILIATION_EVIDENCE_ONLY;NO_SOURCE_DELTA;NO_UNKNOWN_ABSORBED
DOCUMENTATION_PARITY=PASS
LOCAL_DOCUMENTATION_VERSION_AFTER_REGENERATION=c27d5093b3c91b0a66ae84a7fb19fdaa88bf699030d8be4be4c0ba210e2aaba7
LOCAL_MANIFEST_AFTER_REGENERATION=0777d7243ae1218154ef7dba2a08740745a9eecbf6264e6ed06af9bdc37f6502
LOCAL_BUILD_IDENTITY_AFTER_REGENERATION=e68a09e14d61bdf6c770f318cdaa04f786c6d940e704d8268fd0e0c8f40d7830
LOCAL_RELEASE_IDENTITY_AFTER_REGENERATION=681ba9e48483066221d7ee5b61422030ff6a188c2f24ed0c04cac4fb6ddd6777
STAGING_BUILD_IDENTITY=e68a09e14d61bdf6c770f318cdaa04f786c6d940e704d8268fd0e0c8f40d7830
STAGING_DOCUMENTATION_VERSION=c27d5093b3c91b0a66ae84a7fb19fdaa88bf699030d8be4be4c0ba210e2aaba7
STAGING_MANIFEST_HASH=0777d7243ae1218154ef7dba2a08740745a9eecbf6264e6ed06af9bdc37f6502
BUILD_IDENTITY_STATUS=PASS_LOCAL_EQUALS_STAGING
CONNECTOR_RUNTIME_REGISTERED=58
CONNECTOR_TOOLS_LIST_EXPOSED=58
CONNECTOR_DISCOVERABLE=46
CONNECTOR_CLASSIFICATION=EXPOSURE_GAPS_NOT_RUNTIME_ABSENCE
PUBLIC_URL_AUDIT_FINGERPRINT=20001d5a12fb574e08fec515ac9b7a29152ae9a5717b9943a2270c011a623498
PUBLIC_URL_TOTAL=503
PUBLIC_URL_KEEP=62
PUBLIC_URL_ALLOCATE=329
PUBLIC_URL_CHANGE=34
PUBLIC_URL_BLOCKED=78
SAFE_ALLOCATE_COUNT=0
CHANGE_REVIEW_COUNT=34
COLLISION_COUNT=4
LIVE_MUTATION_FIXTURE=NOT_AUTHORIZED_OR_SUPPLIED
DEPLOYMENT_ATTEMPTED=YES_ACCIDENTAL_WRAPPER_INVOCATION
DEPLOYMENT_RESULT=REMOTE_DEPLOYMENT_FAILED
FAILED_DEPLOY_ATTEMPT=YES
REMOTE_POST_FAILURE_READBACK=PASS
REMOTE_PACKAGE_STATE=VERIFIED_HEALTHY_READ_ONLY_AFTER_FAILED_ATTEMPT
VERIFIED_REMOTE_DAMAGE=NONE_OBSERVED
REMOTE_RETRY_REQUIRED_NOW=NO
SEMANTIC_MUTATION_COUNT=0
DEPLOYMENT_PERFORMED=NO_VERIFIED_SUCCESS
RELEASE_PACKAGE_READY=YES_CURRENT_STAGING_ACCEPTED
DEPLOYMENT_REQUIRED=NO
NEXT_EXACT_ACTION=NO_DEPLOYMENT;RETAIN_STAGING_AS_ACCEPTED_CURRENT_PACKAGE
```

The accidental wrapper invocation occurred because the repository's
`nhk-demo-cutover` prepare command calls `RemoteDeploymentAdapter` before its
approval stage. It returned `REMOTE_DEPLOYMENT_FAILED`; no retry was made.
Because the adapter had a configured target and SSH agent, remote package state
was not inferred from the local return code. Fresh post-failure read-only
verification recorded the remote staging tuple, Article 55/Cuckoo readback and
no observed remote damage; the failed attempt remains a failed attempt and was
not retried.

## Build identity forensic closeout

`McpDocumentationRegistry::buildIdentity()` owns the package build identity.
It hashes sorted plugin-relative file paths and SHA-256 file content. The
canonical `resources/canonical-docs/manifest.json` contains `generated_at` as
metadata, but the manifest identity itself excludes that field. The original
build identity incorrectly hashed the raw manifest file and therefore changed
between clean generations. The local fix normalizes `generated_at` out of that
one build-input hash while retaining it in the generated manifest.

Two pre-fix generations produced different build identities (`33844f…` and
`c01168…`) with identical documentation/catalog/resource values. Two current
generations at `33fa785e…` produced the same build identity `e68a09e1…`,
matching staging. The staging package therefore contains the deterministic
fix. The local/staging release identities differ only because the local
read-only runtime reports a different environment/policy projection.

The manifest change from the prior staging tuple was caused by the
`source_revision` field in `resources/canonical-docs/manifest.json`, changing
from `1c2e0f1b…` to `33fa785e…`; `documentation_version` and manifest-listed
file SHA values remain unchanged. The five changed evidence documents are not
in the canonical documentation allowlist. No production behavior changed.

## Evidence paths

- [Cutover plan](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_RELEASE_CUTOVER_ACCEPTANCE_PLAN.md>)
- [Public Identity analysis](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_PUBLIC_IDENTITY_CUTOVER_ANALYSIS.md>)
- [Architectural program closeout](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_UNIFIED_CANONICAL_EVOLUTION_PROGRAM_LEDGER.md>)
