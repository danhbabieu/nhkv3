# Intent-Scoped Publication Gates and Canonical Media Reuse — Master Sequencing Plan

**Source design:** `c06bb88a` — `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

**Purpose:** sequence six independently reviewable implementation slices. This
file is the sequencing contract; the slice plans contain the TDD task details.

## Global constraints

- WordPress native `wp_posts` remains the sole owner of Article title, body, excerpt, editorial ordering, category and native permalink.
- Article prose is editorial input by default. It is not Knowledge, Evidence or Graph truth.
- Authority owns canonical subjects; Knowledge owns atomic claims; Source/Evidence owns provenance; Graph is the only relation store; Media, MediaAsset and MediaUsage remain separate.
- Governance remains mandatory for a real semantic mutation. `NOT_REQUIRED` is not a Governance bypass; it means no semantic write was requested.
- Every automatic repair is bounded, idempotent, revision-aware and followed by canonical read-back.
- No legacy Article-body parsing, semantic backfill, Graph repair, Media merge, attachment rewrite, Dictionary binary copy or public URL reallocation is part of this implementation sequence.
- No change to the physical image upload pipeline, private source-original, public WebP derivative policy, batch behavior, Video owner or existing published Article behavior.
- No staging/live mutation, deployment, SSH, direct database write or live Case 573/Attachment 572 repair is allowed while implementing these plans.

## Real codebase map

| Boundary | Existing executable owner | Plan dependency |
|---|---|---|
| Intent | `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php::route()` | Emits the persisted intent and intent source consumed by Slice 1. |
| Capture orchestration | `.../Application/Capture/EditorialCaptureCoordinator.php::execute()` and private `run()` | Carries requirement evidence, calls semantic continuation, calls the Article media callback, then publication. |
| Semantic continuation | `.../Application/Capture/GovernedCaptureContinuationService.php::execute()` and private `plans()` | Slice 1 removes implicit Article relation/claim planning while preserving governed explicit deltas. |
| Article research/gate | `.../Application/Article/ArticleResearchPreflight.php::research()` and `ArticlePublicationGate::check()` | Slice 1 consumes applicability; Slice 2 supplies post-reconcile media evidence. |
| Completion | `.../Application/Completion/CompletionCoordinator.php::aggregateCapture()` | Slice 1 makes non-applicable owners absent from completion requirements. |
| Article media | `.../Application/Media/ArticleMediaCoordinator.php::ensureForPost()` and `diagnoseForPost()` | Slice 2 owns Article slot usage reconciliation. |
| Usage reconciliation | `.../Application/Media/MediaUsageReconciler.php::plan()` and `MediaService::addUsage()` | Slice 2 preserves usage UUIDs and idempotency. |
| Contextual image projection | `ArticleMediaSeoProjection`, `EntityMediaProjection`, `PublicMediaGalleryQuery`, `VisualSupportPublicProjection` | Slice 3 applies deterministic Usage-first metadata selection. |
| Attachment adoption | `.../Infrastructure/Media/WordPressMediaAttachmentBridge.php::adoptAttachment()` | Slice 4 re-adopts edited files under the existing mapping. |
| Physical attachment read | `.../Infrastructure/Media/WordPressMediaAttachmentIngestor.php::read()` and `McpReadHandler::mediaAttachmentGet()` | Slice 4 returns typed readback state instead of mapping ambiguity. |
| Dictionary | `DictionaryRuntime`, `DictionaryPublicQuery`, `EntityMediaProjection`, `DictionaryCurationService` | Slice 5 adds preferred/replacement Dictionary usage without a binary store. |
| Exact binary adoption | `MediaAssetRepository::findByChecksum()`, `MediaBatchUploadService::upload()`, `ExistingMediaReferenceResolver::resolve()` | Slice 6 fills the missing checksum-to-canonical-owner decision before physical ingest. |

## Required slice order

### Slice 1 — Publication / semantic-delta gate

- **Prerequisite:** unified design `c06bb88a`; no implementation slice is required before it.
- **Output contract:** existing array-based Capture/publication evidence carries `intent`, `intent_source`, `semantic_delta`, and per-requirement `applicability`, `policy`, `state`, and `evidence`. Ordinary `IMAGE_ARTICLE`/`TEXT_ARTICLE` with no semantic delta returns semantic `NOT_REQUIRED`; explicit `KNOWLEDGE_DELTA`, Authority and approved MIXED semantic branches retain Governance.
- **Interfaces introduced/changed:** `GovernedCaptureContinuationService::execute()` keeps its signature; its context consumes `content_intent.semantic_delta`. `CaptureArticlePreflightHandoff::build()` keeps its signature and emits `requirements`. `ArticlePublicationGate::check()` keeps its signature and evaluates the packet.
- **Acceptance gate:** Case 573-equivalent fixture produces no Article `wp_post` relation, Knowledge proposal or Governance requirement; explicit delta tests still reach normal proposal/approval/readback states; identity/CAS conflicts remain hard blocks.
- **Regression suites:** `GovernedCaptureContinuationServiceTest`, `CaptureArticlePreflightHandoffTest`, `ArticlePublicationGateTest`, `EditorialCaptureConvergenceE2ETest`, relevant Governance contract tests.
- **Review/rollback boundary:** one implementation family, commit message `feat: scope publication requirements by intent`; revert only this family if the owner rejects the applicability packet.
- **Subsequent consumers:** Slice 2 consumes the `NOT_REQUIRED` semantic state and the Article media handoff ordering; following slices consume its requirement/reporting shape only through read evidence.

### Slice 2 — Article MediaUsage ordering

- **Prerequisite:** Slice 1 accepted; semantic skip must no longer abort ordinary Articles before Media reconciliation.
- **Output contract:** the committed Capture Media IDs are passed to `ArticleMediaCoordinator::ensureForPost()` before final Article research/gate evidence. Representative endpoint usages remain untouched; Article `featured_primary` and `inline_primary` are reconciled separately, with one real Media allowed for the narrow one-image Article exception.
- **Interfaces introduced/changed:** existing `ensureForPost()` context gains an explicit readback marker and returns reconciled slot usages in `ArticleMediaResult::toArray()`; no new role or endpoint is introduced.
- **Acceptance gate:** 573-equivalent Media 572 has its Model and Classification representative usages preserved and receives/reuses both Article mandatory usages without upload or duplicate Media.
- **Regression suites:** `ArticleMediaPolicyTest`, `CaptureMediaIdsReuseTest`, `ArticleResearchPreflightTest`, `EditorialCaptureConvergenceE2ETest`, guarded Media/Article integration when environment is available.
- **Review/rollback boundary:** `fix: reconcile article media before readiness`; rollback leaves existing placeholders and usages intact.
- **Subsequent consumers:** Slice 3 reads the exact Article Usage records; Slice 5 may use the same Media in Dictionary context.

### Slice 3 — Contextual Media SEO

- **Prerequisite:** Slice 2 accepted so Article Usage is trustworthy before projection changes.
- **Output contract:** image metadata precedence is exact contextual Usage, subject-specific representative Usage, verified neutral Media metadata, WordPress Attachment fallback, then explicit `MISSING`; public asset eligibility remains owned by `PublicMediaAssetSelector`/delivery.
- **Interfaces introduced/changed:** existing projection method return arrays gain `metadata_source`, `title`, `alt`, `caption`, and explicit missing state; method names remain unchanged.
- **Acceptance gate:** the same Media can render different Article/Entity/Dictionary contextual text without changing global Media identity or Attachment metadata.
- **Regression suites:** existing presentation/projection tests plus the new contextual SEO unit/contract coverage.
- **Review/rollback boundary:** `feat: project contextual media seo metadata`; no WordPress uploader changes.
- **Subsequent consumers:** Slice 4 verifies a repaired asset through the same projection; Slice 5 consumes Dictionary contextual output.

### Slice 4 — Image Editor re-adoption/readback

- **Prerequisite:** Slice 3 accepted; projection must distinguish missing asset from missing metadata.
- **Output contract:** `edit_attachment`/REST attachment hooks re-adopt the exact mapped attachment, preserve Media and all unrelated Usage UUIDs, reconcile source/public assets, and return a typed `VERIFIED`, `UNAVAILABLE`, `INCONSISTENT`, or `NOT_FOUND` read state.
- **Interfaces introduced/changed:** `WordPressMediaAttachmentBridge` gains a mapping read used by `WordPressMediaAttachmentIngestor`; `McpReadHandler::mediaAttachmentGet()` returns typed state. Existing `WordPressMediaAttachmentIngestor::ingest()` behavior is unchanged.
- **Acceptance gate:** edited 572-equivalent file retains one Media UUID, representative/Article/Dictionary/technical usages, one private source and one public derivative; stale mapping is not returned as null and never creates a second Media.
- **Regression suites:** `MediaServiceCompletionTest`, `WordPressMediaIngestIntegrationTest`, attachment MCP contract/read tests, guarded `nhk_v3_test` integration only.
- **Review/rollback boundary:** `fix: reconcile edited wordpress attachments with canonical media`; no live repair.
- **Subsequent consumers:** Slice 5 relies on stable Media identity; Slice 6 relies on mapping validation as an exact-reuse prerequisite.

### Slice 5 — Dictionary illustration reuse

- **Prerequisite:** Slice 3 accepted for contextual text and Slice 4 accepted for canonical asset/readback identity.
- **Output contract:** approved `dictionary_concept` projection can pin one preferred `representative` MediaUsage and optional supporting usages; replacement changes only Dictionary usage, not old Article usages; delegated concepts remain non-indexable competitors.
- **Interfaces introduced/changed:** a Dictionary application method accepts `(conceptId, mediaId, expectedConceptRevision, title, alt, caption)` and writes only the existing MediaUsage boundary; public query reads it through its current image resolver.
- **Acceptance gate:** Côn 111 reuses Media A, remains lexical/presentation-only, and no duplicate Media/Attachment/Graph/Evidence appears.
- **Regression suites:** `DictionaryPublicQueryTest`, `DictionaryRuntimeContractTest`, `DictionaryCurationServiceTest`, `DictionaryMediaObservationBoundaryTest`, projection/sitemap contract tests.
- **Review/rollback boundary:** `feat: reuse canonical media for dictionary illustrations`; replacement is reversible by selecting the prior Usage.
- **Subsequent consumers:** Slice 6 must preserve Dictionary usages when exact duplicate Media is reused.

### Slice 6 — Exact binary duplicate prevention

- **Prerequisite:** Slice 4 mapping/readback and Slice 5 Usage preservation accepted.
- **Output contract:** exact checksum candidates are reused only after active Media, eligible asset, attachment mapping and canonical readback validation; same filename, visual similarity and alternate views never prove identity.
- **Interfaces introduced/changed:** `MediaBatchUploadService` gets a bounded pre-ingest resolver dependency; `MediaAssetRepository::findByChecksum()` remains the lookup contract.
- **Acceptance gate:** exact bytes reuse one canonical Media/Attachment; different checksum or visual angle creates/retains a distinct Media; batch idempotency, private original and public WebP policies remain unchanged.
- **Regression suites:** new exact dedupe unit tests, `MediaServiceCompletionTest`, `ExistingMediaReferenceResolverTest`, `WordPressMediaIngestIntegrationTest`, batch contract tests.
- **Review/rollback boundary:** `fix: reuse exact duplicate media assets`; resolver can be disabled without changing the physical ingest adapter.

## Cross-slice handoff contract

```text
ContentIntentRouter::route()
  -> persisted content_intent {intent, source, signals, semantic_delta}
  -> GovernedCaptureContinuationService::execute(..., context)
  -> media callback -> ArticleMediaCoordinator::ensureForPost(...)
  -> ArticleResearchPreflight::research(...)
  -> CaptureArticlePreflightHandoff::build(...)
  -> ArticlePublicationGate::check(...)
```

Media projection consumers must use the same `MediaUsage` identity tuple:
`endpoint_type + endpoint_key + role + placement_key`; no slice may add a
`contextual` role, Graph endpoint, semantic relation or binary store.

## Sequencing rule

Do not start Slice N+1 until Slice N has focused tests PASS, relevant contract
tests PASS, PHP lint PASS, `git diff --check` PASS, secret review PASS where
the repository process requires it, and reviewer acceptance. Each slice is a
separate implementation family and commit; there is no combined end commit.
