# Generic Video Pipeline, Deployment, and Live Acceptance Design

**Date:** 2026-09-18  
**Status:** Design approved in chat; implementation pending written-spec review

## Goal

Complete the generic NHK V3 Video Capture pipeline so that arbitrary YouTube
Video submissions use the canonical `nhk.capture.ingest` flow, receive a
server-issued Capture-owned staging scope, pass Source/Evidence and governed
semantic attachment, and reach the public Video projection. Then deploy the
verified executable build and retry the supplied existing Capture through the
canonical continuation path until the Video is visible on the staging
frontend, or record an evidence-backed external blocker.

## Constitutional boundaries

- `wp_posts` remains the sole editorial source of truth; Video remains a
  distinct semantic owner.
- New Video creation uses `nhk.capture.ingest`; direct Video writers remain
  internal-only and fail closed for operator use.
- Staging semantic mutation is limited to the explicitly supplied existing
  Capture and exact canonical subject under the bounded acceptance contract.
- Every staging mutation uses a server-issued, signed, short-lived,
  Capture-owned exact packet. No client-supplied approval, object allowlist,
  Video-specific workaround or subject-specific exception is allowed.
- Source, Evidence, Graph, Governance, Public Identity, Search and frontend
  remain owned by their existing canonical services and contracts.
- Production remains fail-closed. No destructive migration, delete, direct
  SQL write, generic WordPress writer, fake Evidence or Governance bypass is
  permitted.

## Current evidence and working hypothesis

The repository contains the generic Video staging implementation and related
history, including dynamic scope issuance and Capture subject binding. The
execution state still records the implementation as local-ready and
deployment-pending. The live Capture previously failed with
`STAGING_SCOPE_REQUIRED`, while its subject and YouTube source were already
resolved and its technical content gate passed. The implementation must first
prove whether the deployed runtime is stale, scope issuance/propagation is
missing, an exact packet fingerprint is mismatched, registry admission is
missing, or the failure is a separate Source/Evidence handoff defect.

`NO_SEMANTIC_ATTACHMENT` is therefore treated as a downstream diagnostic until
the deployed generic staging path has been retried. If it remains, the root
cause must be traced through the immutable subject packet, Source/Evidence
dependencies, relation candidate, Proposal and Controlled Apply; it must not
be silenced or satisfied with fabricated evidence.

## Architecture and data flow

The implementation and acceptance flow is:

```text
nhk.capture.ingest
  → VIDEO intent and YouTube identity normalization
  → duplicate/reuse audit
  → immutable subject packet
  → Video plan and server-issued staging scope
  → Source/Evidence coordination
  → semantic attachment candidate
  → Proposal → Governance → Controlled Apply
  → VideoStagingAdmission → canonical Video mutation
  → canonical read-back
  → Graph about relation
  → Search/Public Identity/projections
  → canonical /video/{slug}/ frontend route
```

The scope verifier is the single packet validator. For Video ingest or
supported update/correction, the exact packet binds the persisted
Capture/request fingerprint, canonical Capture entrypoint, environment and
policy, registered operation family, platform, external Video identity,
canonical source URL, proposed or target Video UUID, operation, expected
revision, resolved immutable subject packet, plan fingerprint, Proposal/
command fingerprint, expiry and HMAC/signature. `VideoStagingAdmission` uses
the registry/provider boundary and rejects wrong Capture, identity, subject,
operation, revision, source, environment, duplicate or direct-writer inputs.

The supplied live relation is expected to be `Video → about → classification`
for the already-resolved subject. The target identity is input acceptance
data, not repository policy; the implementation must carry it through the
generic subject and evidence contracts without embedding its UUID, name or
source URL in production code.

## Work phases

### Phase 1 — executable audit and baseline

Inspect the current source, tests, runtime registries, Capture coordinator,
continuation path, Video source resolver, governed orchestration, Graph,
Search, Public Identity and frontend query. Compare the local revision with
the exact parent where relevant. Run focused tests before changing code and
classify any failure as current regression, parent-reproduced baseline or
new failure using output evidence.

### Phase 2 — generic regression coverage and minimal repair

Use at least two arbitrary YouTube fixtures with arbitrary existing subjects.
Cover server issuance, propagation, all identity/fingerprint bindings,
ingest/update CAS, continuation, duplicate/reuse, forged/expired/wrong-scope
rejection, direct-writer and production rejection, Source/Evidence handoff,
semantic attachment, governed `about` relation and canonical read-back.

If the current implementation already satisfies the contract, do not add a
patch. If a test reproduces a gap, write the failing regression first, then
make the smallest owner-local generic fix and rerun the focused suite.

### Phase 3 — verification, documentation and release

Run focused Video/Capture/staging/Governance/Source/Evidence/Graph/Authority/
Search/Public Identity/projection tests, applicable integration tests, the
full Unit suite, PHP lint, repository checks, `git diff --check` and a secret
review. Review the complete diff for hardcoded live identifiers, bypasses,
unrelated changes and stale status claims. Update the execution state with
the actual local verification and revision. Commit and push only source or
documentation changes that are necessary.

### Phase 4 — canonical deployment

Use the repository/server deployment mechanism already present. Do not invent
a second deploy path. After deployment, perform fresh runtime read-back and
verify a coherent release tuple: source revision, runtime version,
documentation version, manifest hash, build identity, catalog version,
resource version and release identity. A documentation snapshot or local
commit alone is not deployment evidence.

### Phase 5 — bounded live acceptance and frontend verification

Bootstrap live documentation, bind the fresh checkpoint, perform a read-only
duplicate audit, then retry the exact existing Capture with the current
runtime schema and `resume_mode=RETRY`/Video child continuation as applicable.
Do not create a replacement Capture. On failure, use the exact machine-readable
diagnostic to repair the generic owner, rerun tests, redeploy and retry.

After successful apply, verify canonical Video state, external identity,
source URL, title, thumbnail, revision, public identity, SEO projection,
Evidence and the active `about` Graph edge. Verify canonical Search, the
actual public Video route, HTTP 200, embed/source projection, absence of
internal jargon and no extra Article. Verify the Classification projection
when required by the active contract.

## Error handling and stop conditions

Missing, stale, forged or mismatched packets remain fail-closed. Empty data,
unavailable runtime, infrastructure failure and hydration loss must remain
distinct. A live mutation is never retried by creating a new Capture or by
weakening a guard. Stop and report an external blocker only after all local
source fixes, tests, commit/push where possible and the canonical deployment
attempt have been completed with the exact command and error recorded.

Final production cutover remains outside autonomous scope and requires a
Cutover Readiness Report.

## Acceptance criteria

The generic suite proves arbitrary Video A and B, retry/update where
applicable, server scope issuance and propagation, identity/subject/revision
binding, duplicate/reuse, forged/expired/wrong-scope rejection, direct-writer
and production protection, Source/Evidence handoff, semantic attachment,
Governance, Controlled Apply and canonical read-back without object-specific
allowlists.

The live acceptance proves the supplied Capture continuation, no
`STAGING_SCOPE_REQUIRED`, no unresolved `NO_SEMANTIC_ATTACHMENT`, canonical
Video, active evidence-backed `about` relation, duplicate audit, Search,
Public Identity, frontend route/HTTP 200, required classification projection,
no extra Article and a coherent release tuple.
