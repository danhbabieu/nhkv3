# NHK V3 — Unified Capture Deployment Readiness Review

Date: 2026-09-15
Workspace: `/Users/imac24-2125d/Developer/nhk-v3`
Branch: `main`
Canonical design: `d39d8393`
Implementation plan: `f14cc310`
Current HEAD: `6ea3e704beb755c097babec90063451da0c32bcc`

## Decision

`NO-GO` for deployment or live acceptance. The local implementation is
reviewed and the executable unit slice is green, but the release is not
deployment-ready because the local WordPress/MySQL runtime cannot bootstrap,
the deployment configuration is absent, the known remote worktree is dirty,
and several plan requirements are deliberately still stop-gated. No staging,
production, server, SSH, rsync, or runtime/data mutation was performed.

The implementation branch was fast-forwarded/rebased onto the current
`origin/main`; the historical expected implementation hash `9ada380b` is not
the current tip. Its implementation tree is represented by the rewritten
implementation commits now contained in `main`.

## Review scope and evidence

- Required Constitution chain and relevant contracts were reread before this
  review; the Constitution remains the sole normative authority.
- Full implementation diff reviewed: `f14cc310..HEAD`, 45 files, 985 added
  lines and 85 removed lines. The current `main` also contains the intervening
  approved base changes through `ea50a750`.
- Design and plan ancestry: PASS.
- Unit suite: PASS — 1,573 tests, 7,622 assertions; 13 warnings and 14
  deprecations are existing test-suite issues, not failures.
- `composer lint`: PASS — all PHP files reported no syntax errors.
- `git diff --check`: PASS.
- `album.js`: syntax checked successfully with Node.
- Deployment preflight: FAIL closed — 11 checks, 5 failures, all caused by
  WordPress bootstrap/runtime availability (`WORDPRESS_BOOTSTRAP_FAILED`),
  including schema, authority hydration and REST checks.
- Exact integration target was attempted only as local
  `NHK_WP_TEST_PATH=public`, `NHK_WP_TEST_DB=nhk_v3_test`; WordPress could not
  establish the local database connection. No fallback database or remote
  environment was used.
- `NHK_DEMO_DEPLOY_CONFIG`: UNSET. The canonical wrapper was inspected but not
  invoked.
- Secret review: PASS for the reviewed diff and generated report; no
  credential, token, private-key or environment file was added.
- TODO/TBD review: no new unresolved implementation placeholder was added by
  this review. Historical plan gaps remain explicitly classified below.

## Required implementation review matrix

Status values are limited to `PASS`, `ENVIRONMENT_BLOCKED`,
`NOT_IMPLEMENTED`, and `NOT_APPLICABLE`. “Test” identifies the owning test or
static contract. “Evidence” is the actual local evidence available at this
stop gate; runtime claims are not inferred from source existence.

| # | Requirement | Test | Implementation | Status | Evidence |
|---:|---|---|---|---|---|
| 1 | Media-only single image | `ContentIntentRouterTest` | Router + Capture coordinator | PASS | `MEDIA_ENRICHMENT`; media-only path skips Article and reads back owner |
| 2 | Media-only multi-image | `EditorialCaptureConvergenceE2ETest` batch case | Coordinator + batch service | NOT_IMPLEMENTED | Ordered batch upload exists, but the exact one-Capture multi-image convergence case is not present |
| 3 | One-image Article | `ArticleMediaPolicyTest` | Article media coordinator + publication gate | PASS | One eligible Media can satisfy both mandatory Article roles under approved exception |
| 4 | Album Article | `ArticleMediaPolicyTest` | Article media coordinator + native Post composer | PASS | Ordered multi-usage policy and one native Post boundary are implemented locally |
| 5 | Image title anchor | `ArticleSemanticDossierTest` placement metadata | Composer + native Post | NOT_IMPLEMENTED | Contextual title exists; stable Article-scoped URL anchor/read-back is not implemented |
| 6 | Direct WebP click | `MediaLibraryFrontendContractTest` | Theme + canonical URL resolver | PASS | Canonical `/anh/{filename}.webp` link contract is present |
| 7 | Single lightbox | `MediaLibraryFrontendContractTest` dialog | `album.js` | PASS | Progressive dialog, open/close and focus behavior are contract-tested |
| 8 | Album lightbox | Frontend album contract | `album.js` + template | PASS | Navigation, caption, total and direct fallback are present |
| 9 | Idempotent replay | `EditorialCaptureContinuationTest` | Capture repositories/coordinator | PASS | Same key reuses Capture/owner state; changed payload conflicts |
| 10 | Changed-payload conflict | Router/convergence tests | Capture coordinator | PASS | Deterministic `IDEMPOTENCY_CONFLICT` path is tested |
| 11 | Checksum candidate only | `MediaServiceCompletionTest` | Media ingest/reconciliation | PASS | Checksum is a candidate; semantic scope prevents automatic unrelated merge |
| 12 | Technical detail not broad representative | `RepresentativeMediaReconcilerTest` | Representative reconciler | PASS | Role comparator keeps broad representative independent from technical detail |
| 13 | Visual support reverse reconcile | `VisualSupportReverseReconciliationTest` | Reverse reconciler | PASS | Exact subject/facet/feature/intent and revision-aware reconciliation are tested |
| 14 | Conflicting claim omitted | `ArticleResearchPreflightTest` | Research/preflight + compliance | PASS | Conflicting or unsupported claims are excluded/blocked from public copy |
| 15 | Internet unavailable, internal evidence sufficient | `ArticleResearchPreflightTest` | Internal evidence/preflight | PASS | Internal claims can continue without inventing external evidence |
| 16 | Ambiguity block | `ContentIntentRouterTest` | Router + subject resolver | PASS | Ambiguous input is review-required and does not write an owner |
| 17 | Rights block | `ArticlePublicationGateTest` | Publication gate | PASS | Revoked/ineligible media blocks publication while retaining source state |
| 18 | Corrupt image | `MediaBatchUploadServiceTest` | Batch/materializer/ingestor | PASS | Per-item diagnostics and MIME/decoder fail-closed boundaries exist |
| 19 | Route collision | `PublicMediaRouteGateTest` | Public identity/route gate | PASS | Collision and standalone detail route are fail-closed |
| 20 | Runtime unavailable | MCP integration contracts | Transport/control plane | ENVIRONMENT_BLOCKED | Local WordPress bootstrap is unavailable; typed runtime-unavailable paths remain unverified live |
| 21 | Managed section no duplicate | `ArticleSemanticDossierTest` replay | Composer | NOT_IMPLEMENTED | Structural Gutenberg managed-section parsing and ownership fingerprint are absent |
| 22 | User text preserved | Article/CAS unit contracts | Composer + Article CAS | PASS | Native editorial state remains owner-owned and CAS guarded; no body overwrite was introduced |
| 23 | Knowledge invalidation | Governance stale-dependency contracts | Knowledge/Governance | PASS | Dependency and revision drift reject eligibility/apply |
| 24 | One Post for N images | Article media contracts | Coordinator + draft gateway | PASS | Native WordPress Post remains the sole Article owner; N images bind as usages |
| 25 | No fake second Media | `ArticleMediaPolicyTest` | Media identity/reuse | PASS | Existing suitable Media is reused without duplicate semantic identity |
| 26 | Rendered read-back before publication | `RenderedArticleVerifierTest` | Publication service | PASS | Rendered verification is a required publication boundary |
| 27 | No direct owner writer | MCP Article contract | MCP catalog/transport | PASS | Capture-only route is governed; direct unauthorised writer is blocked |
| 28 | Widget upload reuses Media IDs | `CaptureMediaIdsReuseTest` | Widget + Capture | PASS | Existing Media IDs can be attached without a second physical upload |
| 29 | Physical success then Capture failure/retry | Continuation/receipt tests | Coordinator receipts | PASS | Physical receipt and continuation state support retry without re-upload |
| 30 | Partial N-image batch | `MediaBatchUploadServiceTest` | Batch + Capture | PASS | Per-item success/failure manifest and partial result are tested |
| 31 | Concurrent identical Capture | Convergence race contract | Capture repository | ENVIRONMENT_BLOCKED | Atomic reservation code exists; two independent live connections were unavailable |
| 32 | Human managed-section edit | `ArticleOperationReceiptTest` token race | Draft gateway + Composer | NOT_IMPLEMENTED | Managed-section fingerprint binding is not implemented |
| 33 | Manual album reorder | `ArticleSemanticDossierTest` reorder replay | Native Post owner | NOT_IMPLEMENTED | Explicit manual reorder/read-back ownership case is not implemented |
| 34 | Same Media in two Articles | MediaUsage/reuse contracts | Usage/anchor projection | PASS | Usage identity is Article-scoped and permits reuse without binary duplication |
| 35 | Global ambiguous Article target | Frontend multi-usage case | Gallery/link resolver | NOT_IMPLEMENTED | Gallery still selects the first published Article rather than representing equal targets safely |
| 36 | Private EXIF/GPS stripped | Media delivery metadata case | Derivative boundary | ENVIRONMENT_BLOCKED | WebP re-encoding is local code evidence; real binary metadata/read-back needs WordPress runtime |
| 37 | Pixel/decompression bomb | Batch resource-budget case | Materializer/ingestor | NOT_IMPLEMENTED | Byte/MIME limits exist, but decoded-pixel, memory, CPU and time budgets are not enforced |
| 38 | Active image rejected | Batch format policy | Format policy | PASS | SVG/active content is outside the allowlist; supported MIME is sniffed from bytes |
| 39 | MIME + nosniff | Public media route contract | Public route | PASS | WebP content type, length and `X-Content-Type-Options: nosniff` are implemented |
| 40 | External snippet not Evidence | Research preflight boundary | Research adapter | PASS | Snippet/transport material is not promoted to Source/Evidence automatically |
| 41 | Provider unavailable, sufficient evidence | Research fallback case | Preflight | PASS | Internal-evidence fallback remains available and bounded |
| 42 | Provider unavailable, insufficient evidence | Research mandatory-claim case | Preflight + publication gate | PASS | Missing evidence is a hard publication blocker |
| 43 | Automated compliance unavailable | Publication gate | Compliance gate | PASS | Compliance-unavailable state blocks/requires review; no auto-public bypass |
| 44 | Stale claim trace | Article semantic verifier contracts | Composer/verifier | PASS | Semantic read-back and dependency fingerprints prevent silent stale application |
| 45 | Contextual metadata isolation | `MediaUsageReconcilerTest` | Usage metadata | PASS | Contextual titles are usage-scoped and persisted with revision |
| 46 | Article featured != Entity representative | `ArticleMediaPolicyTest` | Article media + representative reconciler | PASS | Article featured state and entity representative decisions are independent |
| 47 | Rights revocation | Media completion/projection tests | Media projection | PASS | Inactive/private media is excluded from public preference and yields honest missing state |
| 48 | Public source-original leak prevention | Private asset delivery contracts | Delivery/storage boundary | NOT_IMPLEMENTED | Source copy is currently placed under public uploads with metadata marking it private; physical privacy is not guaranteed |
| 49 | No-JS image fallback | Frontend contract | Theme template | PASS | Anchor points directly to canonical WebP |
| 50 | Focus return after modal close | Frontend contract | `album.js` | PASS | Invoker focus is restored after close |
| 51 | Replay no duplicate partial children | Continuation manifest tests | Continuation + batch | PASS | Completed children and manifest are reused on replay |
| 52 | Capture idempotency reservation | Convergence reservation case | `WpdbCaptureRepository` | ENVIRONMENT_BLOCKED | Atomic/unique reservation path exists; integration read-back is blocked by DB bootstrap |
| 53 | WordPress state-token CAS | `ArticleOperationReceiptTest`/editorial CAS tests | Draft gateway | PASS | Stale state token is rejected locally |
| 54 | MediaUsage revision/CAS | Usage repository contracts | `WpdbMediaUsageRepository` | ENVIRONMENT_BLOCKED | CAS SQL path exists; independent database concurrency/read-back is unverified |
| 55 | Representative revision race | Representative concurrency case | Reconciler/usage updater | ENVIRONMENT_BLOCKED | Revision-aware implementation exists; live race proof needs integration DB |
| 56 | Visual requirement revision | `WpdbVisualSupportRequirementRepositoryTest` | Requirement repository | ENVIRONMENT_BLOCKED | Repository contract is covered, but live schema/CAS execution is blocked |
| 57 | Governance proposal binding | Governance apply contracts | Proposal/apply lifecycle | PASS | Eligibility binds content/dependency fingerprints and canonical read-back |
| 58 | Public identity collision | Public URL regression contracts | Public identity gate | PASS | Collision does not invent a suffix or silently change canonical identity |
| 59 | Managed dependency fingerprint | Article stale-dependency case | Composer + gateway | NOT_IMPLEMENTED | Dedicated managed-section dependency fingerprint is absent |
| 60 | Server result empty is transport symptom | `McpWidgetUploadTest` | MCP transport/adapter | PASS | Empty transport result is diagnostic, not semantic success |
| 61 | HTTP 200 WebP | Public asset route contract | `PublicMediaAssetRoutes` | ENVIRONMENT_BLOCKED | Local response contract is present; live HTTP status/type/length needs WordPress runtime |
| 62 | Canonical asset binding | Media canonical delivery contracts | Delivery selector | PASS | Canonical filename resolves through MediaAsset identity/checksum boundary |
| 63 | No standalone image HTML page | `PublicMediaRouteGateTest` | Theme/routes | PASS | Direct image route streams binary; standalone media detail is blocked |
| 64 | Lightbox focus trap/Escape | Frontend contract | Template + `album.js` | PASS | Dialog semantics now include `aria-modal="true"`, focus trap and Escape |
| 65 | Caption/navigation labels | Frontend album contract | Template + JS | PASS | Vietnamese labels, current/total status and caption projection are present |
| 66 | Remote URL excluded | `McpWidgetUploadTest`/materializer | Transport boundary | PASS | URL-only/non-trusted references are rejected; only bounded trusted file objects are accepted |
| 67 | Auto-public final sequence | `ArticlePublicationGateTest` | Owner publication service | NOT_IMPLEMENTED | Fail-closed gate exists, but automated final auto-public is intentionally unavailable |

### Matrix totals

| Status | Count |
|---|---:|
| PASS | 49 |
| ENVIRONMENT_BLOCKED | 8 |
| NOT_IMPLEMENTED | 10 |
| NOT_APPLICABLE | 0 |
| Total | 67 |

## Focused readiness findings

### Media enrichment and Article exception

`MEDIA_ENRICHMENT` is registered in the content-intent and MCP contracts. Its
Capture path completes Media reconciliation/read-back without creating an
Article. The approved `IMAGE_ARTICLE` single-real-image amendment is narrow:
one eligible real Media may satisfy the two mandatory Article roles only when
the explicit exception is present. No broad “one image is enough” fallback was
introduced.

### Migration 021

`MediaUsageMetadataMigration021` is an additive local UP migration for
contextual title/revision metadata. It was not executed against any runtime in
this review. `MIGRATION_021_RUNTIME_EXECUTED=NO`.

### Security and privacy

Input transport has HTTPS/host/redirect/private-IP, byte, MIME and filename
guards, and the managed derivative is WebP. The review does not claim full
image security readiness: decoded-resource limits are absent, and the source
original is currently copied into the public uploads tree even though the
semantic asset visibility is marked `PRIVATE`. This is a release blocker and
requires an approved storage/delivery contract before deployment; it was not
silently “fixed” by inventing a new architecture in this review.

### Frontend, SEO and accessibility

The gallery has canonical direct WebP fallback, no standalone image HTML page,
responsive image containment, progressive dialog lightbox, navigation/caption
labels, Escape, focus trap and focus return. The final review added the missing
explicit `aria-modal="true"` declaration and its contract assertion. Live
HTTP/WebP and browser acceptance remain environment-blocked.

### Deployment and server safety

The canonical wrapper requires `NHK_DEMO_DEPLOY_CONFIG`, may rsync plugin,
mu-plugin and theme files, and does not establish a safe remote dirty-worktree
handoff. Because the known server has local modifications/untracked files, it
remains exactly:

`SERVER_WORKTREE_DIRTY — OUT_OF_SCOPE — PRESERVED`

No command in this task inspected or mutated that server. No deployment,
remote Git operation, SSH, rsync, staging mutation, production mutation or
migration was performed.

## Stop gate

`GO_OR_NO_GO=NO-GO`

Blockers for the next gate are:

1. Restore an authorized local WordPress/MySQL test runtime using the exact
   `nhk_v3_test` guard, then run integration migration, attachment, CAS and
   HTTP read-back tests.
2. Resolve the approved private source-original storage/delivery contract and
   add decoded-resource and metadata-stripping runtime evidence.
3. Implement and test the remaining managed-section/anchor/multi-target and
   explicit multi-image convergence requirements.
4. Provide deployment configuration and a reviewed remote handoff protocol
   that preserves the dirty server worktree; do not use the current rsync
   wrapper against it.
5. Keep auto-public disabled until compliance capability, rendered read-back,
   rights, identity and owner gates are runtime-proven.

One-JPEG acceptance: `NOT_READY`
Multi-image acceptance: `NOT_READY`
Server status: `SERVER_WORKTREE_DIRTY — OUT_OF_SCOPE — PRESERVED`
