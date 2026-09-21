# NHK V3 Release & Cutover Ledger

> Operational state for `NHK_V3_RELEASE_AND_CUTOVER_ACCEPTANCE`. This is not
> semantic authority and does not replace the Unified Canonical Evolution
> Program Ledger.

```text
WORKSTREAM=NHK_V3_RELEASE_AND_CUTOVER_ACCEPTANCE
ARCHITECTURAL_PROGRAM=ARCHITECTURAL_PROGRAM_COMPLETE_PENDING_LIVE_MUTATION_OR_DEPLOYMENT_ACCEPTANCE
CURRENT_STEP=BUILD_IDENTITY_FORENSIC_COMPLETE_LOCAL_TOOLING_FIX_COMMITTED
INTENDED_RELEASE_REVISION=93657305b56a976f6aa6d08144a7b249005dbd69
STAGING_REVISION=1c2e0f1b0c32bed1f6de4fe5523635171d481cc0
WORKTREE_CLASSIFICATION=PRE_EXISTING_PROGRAM_DOCUMENTATION;LOCAL_BUILD_TOOLING_FIX_COMMITTED;NO_UNKNOWN_ABSORBED
DOCUMENTATION_PARITY=PASS
LOCAL_DOCUMENTATION_VERSION_AFTER_REGENERATION=c27d5093b3c91b0a66ae84a7fb19fdaa88bf699030d8be4be4c0ba210e2aaba7
LOCAL_MANIFEST_AFTER_REGENERATION=f36975022556e1113807b1a551e87dd5bd6a58bd685675561046bcc294fe2314
LOCAL_BUILD_IDENTITY_AFTER_REGENERATION=9483119213d590aa3f3397403ef30302e099998b21f0dd299fd67e49ccaf31b9
LOCAL_RELEASE_IDENTITY_AFTER_REGENERATION=adf13cc2eaf13e27a84247fa0f7b7fadf14d0d3610ff48cda3325167f4cb459a
STAGING_BUILD_IDENTITY=df86683644fd55e34ddc81f36e206002ede1085d2c66195815ce73e4a3b45ead
STAGING_DOCUMENTATION_VERSION=c27d5093b3c91b0a66ae84a7fb19fdaa88bf699030d8be4be4c0ba210e2aaba7
STAGING_MANIFEST_HASH=2e23279179cf60d537571e4d8dac78d9c41f19f4fbcd041e82f21cac3b4b7ed5
BUILD_IDENTITY_STATUS=LOCAL_DETERMINISTIC_NEW_TUPLE;STAGING_REQUIRES_ACTIVATION_OF_93657305
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
RELEASE_PACKAGE_READY=YES_LOCAL_PASS_B
DEPLOYMENT_REQUIRED=YES_TO_ACTIVATE_93657305
NEXT_EXACT_ACTION=HUMAN_AUTHORIZATION_FOR_DEPLOYMENT_OF_93657305;NO_AUTONOMOUS_DEPLOYMENT
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
`c01168…`) with identical documentation/catalog/resource values. Two post-fix
generations after final commit `93657305…` produced the same build identity
`94831192…` while `generated_at` changed. This is a local tooling fix, not a
staging verification or deployment claim.

## Evidence paths

- [Cutover plan](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_RELEASE_CUTOVER_ACCEPTANCE_PLAN.md>)
- [Public Identity analysis](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_PUBLIC_IDENTITY_CUTOVER_ANALYSIS.md>)
- [Architectural program closeout](</Users/imac24-2125d/Developer/nhk-v3/docs/architecture/NHK_V3_UNIFIED_CANONICAL_EVOLUTION_PROGRAM_LEDGER.md>)
