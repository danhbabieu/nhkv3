# KNOWLEDGE_DELTA semantic admission — defect and regression record

**Date:** 2026-10-08  
**Scope:** NHK V3 Capture → semantic interpretation → Knowledge planning → Governance → Graph planning  
**Status:** Fix committed and verified on remote main; STAGING deployment and runtime acceptance pending.

## Incident and canonical smoke readback

A STAGING smoke Capture with intent `KNOWLEDGE_DELTA`, confirmed canonical model `Odo 24`, and text:

> Smoke readback for existing canonical Odo 24 subject; no new fact asserted.

was marked `COMPLETE` and materialized an active Knowledge `fact` despite interpretation reporting `semantic_assertions=[]` and a candidate with `status=REVIEW_REQUIRED`. Its Graph `about` relation was then materialized.

Readback identifiers (for targeted audit, **not** permission to mutate):

- Capture: `01a11a43-af22-7ad2-bbb8-7debe452ea04`
- Knowledge: `01a11a43-b69b-722d-a087-2d10c80d5ee9`
- Graph relation: `01a11a43-baf6-7744-ad22-1fb5e8c1e91a`
- Knowledge proposal: `01a11a43-b4db-7e97-a3af-dffc464cfa51` (`applied`)
- Relation proposal: `01a11a43-bae2-7162-8d6c-66a7e834bca6` (`applied`)
- Canonical model UUID: `984658bf-19a6-4daa-a220-2a6c13af81ed`

The applied Knowledge proposal had `claim_type=fact`, provenance origin `EXPLICIT_USER_KNOWLEDGE`, and `staging_acceptance.approved=true`. The applied relation had `require_evidence=false` and `evidence_refs=[]`. These lifecycle/authorization checks did not establish semantic validity.

## Root cause

`SemanticClaimCandidateGuard` admitted candidates through two bypasses: empty `dictionary_owner_commands=[]` was treated as `ALLOWED`, and `CONTINUATION_DELTA` was allowed directly. A `REVIEW_REQUIRED` candidate was not rejected. Governance verified lifecycle, authorization and scope but did not independently re-evaluate semantic assertions; downstream Graph planning bound the resulting Knowledge to its subject.

## Correction and invariant

Fixed at the semantic admission boundary. Knowledge/Graph materialization requires a **non-empty semantic assertion**, an **exact candidate-to-assertion match**, and a candidate **not marked `REVIEW_REQUIRED`**. Neither an empty dictionary command list nor a continuation intent is sufficient evidence of a valid claim. Subject reconciliation alone does not authorize materialization. When Knowledge is blocked, the dependent Graph relation must not be created. Legitimate asserted facts remain eligible through the existing governed path.

Commits:

- `8811e83792a31bdae0f260518d51aaa6ab01e3db` — block unasserted Knowledge materialization.
- `37dbd73995b04afce3dac5e215624e1ccc629bfd` — reject review-required Knowledge candidates.

Changed implementation/tests:

- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticClaimCandidateGuard.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`

## Verification

Focused Capture/Semantic/Governance/Graph suite: **183 tests, 888 assertions, PASS**. Regression coverage includes empty assertions, no-fact continuation, missing observation fallback, `REVIEW_REQUIRED`, valid fact, subject reconciliation, and suppression of dependent Graph relations.

Local full Unit baseline (parent `d5fc4e878b1394138165c6bbeaaf40b5608fdc06`): 3,737 tests; 46 errors, 29 failures. Patched HEAD `37dbd739`: 3,743 tests; 46 errors, 29 failures. Test identities and normalized failure signatures matched: **0 new regressions, 0 changed failure signatures**. Focused lint and diff checks passed. Full-suite baseline issues remain unresolved; patched run had 58 deprecations vs 53 at baseline.

Remote `main` was independently read back at `a9c1fa904da747dd26822906b5b67f56796c5d05`, which contains both correction commits. This verifies source availability on GitHub, **not** STAGING deployment or acceptance.

## Pending operational work

1. Deploy an explicitly approved, pinned revision containing the fix to STAGING; do not accidentally include unrelated unreviewed local Music commits.
2. Verify bootstrap, source/build identity, signed bounded packet and governed acceptance on STAGING using **new** test identifiers; verify no-fact input creates neither Knowledge nor Graph, while a valid asserted fact remains eligible.
3. Review existing smoke Knowledge and relation for governed cleanup separately; **do not** silently retire, rewrite or replay the old Capture.
4. Keep production, Côn hoa thị, schema/migrations and unrelated semantic data outside this fix's scope.

This record describes a verified defect and local correction. It does not certify a deployed runtime fix.
