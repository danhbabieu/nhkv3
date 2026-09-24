# Brain 2 Article + Video editorial pipeline closure

## Goal

Complete the existing Universal Enrichment Core through the Article and Video
editorial lifecycle without introducing a new architecture or touching Media
until the Brain 2 exit criteria pass.

## Constraints

- Preserve the existing runtime registry, transient read models and canonical
  Claim/Knowledge boundaries.
- No migration, database mutation, staging acceptance, deployment or push.
- No raw candidate bypass; final prose and SEO may consume only validated,
  publicly composable material.
- Preserve Claim identity/revision, subjects, scope, applicability, specificity,
  retrieval tier, treatment, context flags and provenance in diagnostics.

## Execution ledger

- [ ] Slice 2: Article/Video contract closure and metadata propagation
- [ ] Slice 3: sparse/rich content depth and reader journey parity
- [ ] Slice 4: quality diagnostics and bounded repair lifecycle
- [ ] Slice 5: final-package SEO boundary and revalidation
- [ ] Brain 2 verification and execution-state checkpoint
- [ ] Media lifecycle connection after Brain 2 passes
- [ ] Final verification and delivery report

## Verification

After each slice: focused production-shaped tests, relevant regression tests,
PHP lint for changed files, `git diff --check`, and an execution-state update.
At the end: NHK Unit with 512M memory, Contract, Composer validation/lint,
full PHP lint, diff check, special-case scan and secret review. Integration is
attempted only through the existing guarded test path and remains environment-
gated if WordPress/database prerequisites are unavailable.
