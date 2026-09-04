# Governed SEO Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Owner-approved Governed SEO Projection architecture as read-only, fail-closed projection services shared by public pages, REST/search consumers and sitemap adapters.

**Architecture:** Keep WordPress `wp_posts`, Authority, Public Identity, Knowledge, Source/Evidence, Graph, Media and Video as the existing canonical owners. Add a shared SEO readiness/indexability result boundary, type-specific entity and article planning projectors, canonical URL/sitemap adapters, media/video hardening and stable-core guards; no SEO service receives a semantic repository write capability.

**Tech Stack:** PHP 8+, WordPress, PHPUnit 11, Composer PSR-4 autoloading, existing NHK Core application/domain/contracts and in-memory test doubles.

**Spec:** `docs/superpowers/specs/2026-09-04-nhk-v3-seo-governance-design.md`

## Global Constraints

- Constitution is supreme; SEO/discovery/structured data are projection-only.
- WordPress `wp_posts` remains the sole owner of Article editorial title/body/excerpt/order/public editorial URL.
- Authority owns canonical entities; Knowledge owns atomic claims; Source/Evidence owns provenance/support; Graph owns typed relations; Media/MediaAsset/MediaUsage and Video retain separate boundaries.
- Do not create semantic identities, slugs, Knowledge, Source/Evidence, Graph edges, Media/Video identities or Product–Specimen relations from SEO inputs.
- Use the existing nine Authority types and runtime registries; do not invent types, predicates, endpoint types, fields or operations.
- No migration, backfill, repair, production/staging/V2 mutation, local MP4 download, live URL change, push, merge or deploy.
- Every non-ready decision has deterministic reason codes; numeric SEO scores are never publication truth.
- Every task follows RED → verify failure → minimal GREEN → regression → `git diff --check`; commit each coherent slice locally.

---

### Task 1: Synchronize SEO documentation contracts

**Files:**
- Create: `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`
- Create: `docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md`
- Create: `docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`
- Create: `docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md`
- Modify: `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md`
- Modify: `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`
- Modify: `docs/seo/LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`
- Modify: `docs/constitution/READ_FIRST.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SeoDocumentationContractTest.php`

**Interfaces:**
- Consumes: Owner-approved SEO design and current domain contracts.
- Produces: A documentation contract set that routes SEO through canonical owners and explicitly describes FAQ as optional projection.

- [ ] **Step 1: Write the failing documentation contract test**

  Assert all four new files exist, contain projection-only/no-writer language, list the five statuses and deterministic reason-code rule, and that READ_FIRST/status index route SEO through the new contracts. Assert no `SEO_CONSTITUTION`, AEO/GEO semantic store, or Product–Specimen fallback is introduced.

- [ ] **Step 2: Run the focused test to verify it fails**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoDocumentationContractTest.php`

  Expected: FAIL because the four contract files and routing references do not yet exist.

- [ ] **Step 3: Write the minimal documentation contracts and test helpers**

  Document only projection/publication rules subordinate to the Constitution; extend existing contracts without redefining ownership or external Google guidance as semantic law.

- [ ] **Step 4: Run focused documentation tests and text checks**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoDocumentationContractTest.php` and `rg -n "SEO Core|ENTITY_SEO|MEDIA_IMAGE|SITEMAP_INDEXABILITY|FAQ|projection-only" docs/constitution/READ_FIRST.md docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md docs/seo`

  Expected: PASS and all routing references resolve.

- [ ] **Step 5: Commit the documentation slice**

  Run: `git add docs/seo docs/constitution/READ_FIRST.md docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md public/wp-content/plugins/nhk-core/tests/Unit/SeoDocumentationContractTest.php && git commit -m "docs: define governed seo projection contracts"`

### Task 2: Add shared SEO readiness and indexability core

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Seo/SeoReadinessStatus.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Seo/SeoReasonCode.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Seo/SeoReadinessResult.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Seo/SeoIndexabilityResult.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/SeoReadinessPolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/SeoIndexabilityPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SeoReadinessPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SeoIndexabilityPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php`

**Interfaces:**
- Consumes: Read-only candidate snapshots containing canonical/public eligibility, URL, content, media/video, compliance and runtime state.
- Produces: `SeoReadinessResult::status(): string`, `SeoReadinessResult::reasons(): array`, and `SeoIndexabilityPolicy::evaluate(array $snapshot): SeoIndexabilityResult`; `PublicSeoProjection` consumes the shared result.

- [ ] **Step 1: Write failing tests**

  Cover READY, INCOMPLETE, BLOCKED, UNAVAILABLE and NOT_APPLICABLE; deterministic reasons for missing identity, URL mismatch, ambiguity, thin content, missing representative image, unavailable thumbnail, compliance and runtime; runtime unavailable must not be empty success; sitemap/robots/projection consumers must receive one indexability decision.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoReadinessPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/SeoIndexabilityPolicyTest.php`

  Expected: FAIL because the shared status/result/policy classes are absent.

- [ ] **Step 3: Implement minimal immutable result objects and policies**

  Preserve existing reason vocabulary where possible, deduplicate reason codes deterministically, make structured-data inapplicability `NOT_APPLICABLE`, and give policy classes no repository or mutation dependency.

- [ ] **Step 4: Make existing public SEO projection consume the result**

  Preserve its existing output keys for compatibility while deriving canonical/indexable/open-graph/JSON-LD/sitemap/link output from the shared result and returning no URL when not indexable.

- [ ] **Step 5: Run focused and regression tests**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoReadinessPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/SeoIndexabilityPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php`

  Expected: PASS with existing projection behavior preserved and new fail-closed assertions green.

- [ ] **Step 6: Commit the shared core**

  Run: `git add public/wp-content/plugins/nhk-core/src/Domain/Seo public/wp-content/plugins/nhk-core/src/Application/Seo public/wp-content/plugins/nhk-core/tests/Unit/Seo*Test.php public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php && git commit -m "feat: add shared seo readiness and indexability policy"`

### Task 3: Implement type-specific Entity SEO projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/EntitySeoProjection.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/EntitySeoProfileRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEndpointEligibilityResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php`

**Interfaces:**
- Consumes: `AuthorityEntity`/read snapshots, `PublicIdentityService` read result, existing `EntityMediaProjection`, bounded `RelatedContentQuery`, and `SeoReadinessPolicy`.
- Produces: `EntitySeoProjection::project(array $entity, array $dependencies = []): array` with type profile, canonical identity, public URL package, visible summary/facts, related projections, structured-data applicability, readiness and indexability.

- [ ] **Step 1: Write failing tests**

  Cover all nine registered types; brand/model/variant parent and relation projections only from registered read data; specimen physical/evidence scope; product commercial scope with no inferred specimen; ambiguous/unroutable/thin entities fail closed; no slug is derived from name/title.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php`

  Expected: FAIL because the entity SEO projector/profile registry is absent.

- [ ] **Step 3: Implement explicit profiles and read-only projection**

  Register exactly `brand`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen`, `product`; return deterministic profile-specific fields and do not accept a semantic writer in any constructor.

- [ ] **Step 4: Integrate shared readiness/indexability and existing public route results**

  Ensure Authority existence alone is insufficient and missing Public Identity/route/compliance/content produces the correct reason-coded result.

- [ ] **Step 5: Run focused regression tests**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityRoutesTest.php`

  Expected: PASS.

- [ ] **Step 6: Commit the entity slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Seo/EntitySeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Seo/EntitySeoProfileRegistry.php public/wp-content/plugins/nhk-core/src/Application/Entity public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php && git commit -m "feat: project eligible authority entities for seo"`

### Task 4: Unify canonical, indexability and sitemap output

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/SitemapIndexabilityProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressImageSitemapProvider.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicVideoSitemapRoutes.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php`

**Interfaces:**
- Consumes: canonical owner URL/public eligibility, shared `SeoIndexabilityResult`, historic-route resolver and native WordPress editorial URLs.
- Produces: `SitemapIndexabilityProjection::include(array $snapshot): array` and `lastmod(?string $previous, string $ownerRevision, string $projectionFingerprint): ?string`; all sitemap/robots/meta/link surfaces consume the same result.

- [ ] **Step 1: Write failing tests**

  Assert canonical/internal/sitemap/Open Graph/structured-data page URLs agree; historic/redirect/noindex/private/unavailable/ambiguous/technical/asset/retired/incomplete/compliance-blocked URLs are excluded; database existence alone is excluded; meaningful owner/projection changes alter `lastmod`, request time does not.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php`

  Expected: FAIL because the shared sitemap adapter and unified behavior are absent.

- [ ] **Step 3: Implement the single sitemap inclusion adapter**

  Apply the required flow canonical owner → public identity/native URL → public eligibility → indexability → canonical projection → sitemap and return deterministic exclusion reasons.

- [ ] **Step 4: Route Video/image sitemap and theme canonical output through it**

  Preserve native WordPress article URLs and exclude MediaAsset delivery URLs; do not change existing public identities or routes.

- [ ] **Step 5: Run focused tests and diff checks**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php` and `git diff --check`.

  Expected: PASS.

- [ ] **Step 6: Commit the sitemap slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Seo public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressImageSitemapProvider.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicVideoSitemapRoutes.php public/wp-content/themes/nhk-v3/functions.php public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php && git commit -m "feat: unify seo canonical and sitemap eligibility"`

### Task 5: Harden Media/image SEO projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/PreferredImageSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PreferredImageSeoProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php`

**Interfaces:**
- Consumes: Media/MediaAsset/MediaUsage read repositories and current deterministic role precedence.
- Produces: `PreferredImageSeoProjection::forEndpoint(string $type, string $key): array` with selected representative, contextual alt/caption, public derivative delivery data and exclusion reasons; no semantic write capability.

- [ ] **Step 1: Write failing tests**

  Cover representative precedence independent of recency/resolution, separate evidence/technical outputs, private/placeholder/technical exclusion, source-original/derivative same Media identity, contextual accessibility-first alt/caption, and no Knowledge/Evidence/Graph mutation from annotations.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PreferredImageSeoProjectionTest.php`

  Expected: FAIL because the shared preferred-image service is absent.

- [ ] **Step 3: Implement preferred-image projection over existing role registry**

  Use existing role contracts and return `eligible=false` with deterministic reason codes for absent real representatives; never select an evidence/technical image as representative due to upload time, dimensions or filename.

- [ ] **Step 4: Reuse it from Article/entity/image sitemap consumers**

  Preserve WordPress editorial placement ownership and Media/MediaAsset/MediaUsage boundaries; delivery URLs remain delivery identities, not SEO pages.

- [ ] **Step 5: Run focused media regression**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PreferredImageSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaAssetDeliveryTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`

  Expected: PASS.

- [ ] **Step 6: Commit the media slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Media public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/PreferredImageSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php && git commit -m "feat: harden governed preferred image seo projection"`

### Task 6: Add Article create/update SEO gate and intent planning

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleSeoChangeType.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleSeoPlanningOutcome.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleSeoGate.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIntentOverlapPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePreflightResult.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleSeoGateTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleIntentOverlapPlannerTest.php`

**Interfaces:**
- Consumes: existing Article semantic/SEO research packet, native WordPress editorial state, canonical subject resolver, Media/Video/compliance/readiness snapshots.
- Produces: `ArticleIntentOverlapPlanner::classify(array $intent, array $inventory): string` using the five planning outcomes, and `ArticleSeoGate::evaluate(array $preflight): array`; outcomes remain planning values, never semantic operations.

- [ ] **Step 1: Write failing tests**

  Cover differentiated create, duplicate reuse/enrich recommendations, ambiguous intent, complete preflight fields, title update preserving canonical URL, Knowledge update not rewriting body, and stable-core change requiring the existing Living Knowledge guard/human gate.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleSeoGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleIntentOverlapPlannerTest.php`

  Expected: FAIL because the typed SEO gate/planner is absent.

- [ ] **Step 3: Implement intent outcomes and change classification**

  Compare Article intents, Entity hub coverage and eligible Video pages; preserve native WordPress URL ownership and do not derive slug changes from title changes.

- [ ] **Step 4: Integrate the gate into read-only preflight**

  Require intent, subject, information gain, title/H1/meta plan, canonical URL, internal links, Media/Video completeness, compliance, structured-data applicability and indexability before `ready_for_draft`; do not write a Post or semantic record from the gate.

- [ ] **Step 5: Run Article regressions**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleSeoGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleIntentOverlapPlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php`

  Expected: PASS.

- [ ] **Step 6: Commit the Article slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Domain/Article public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/tests/Unit/ArticleSeoGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleIntentOverlapPlannerTest.php && git commit -m "feat: gate article seo planning by intent and readiness"`

### Task 7: Harden Video watch-page and VideoObject projection

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoPublicContextSelector.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`

**Interfaces:**
- Consumes: validated Video identity/metadata, explicit `about` target, existing completeness and public context policies, shared URL/indexability result.
- Produces: watch-page projection only for eligible active Videos; `VideoObject` fields from validated metadata; no WordPress Post or local binary fallback.

- [ ] **Step 1: Write failing tests**

  Cover active valid watch page, missing thumbnail, missing visible embed/editorial package/semantic attachment/compliance, explicit Variant target preservation, no guessed duration/timestamps/chapters, transcript/source description/AI summary not Evidence, and sitemap parity.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php`

  Expected: FAIL on the new eligibility and metadata assertions.

- [ ] **Step 3: Implement the minimal fail-closed Video hardening**

  Reuse validated Video identity `(platform, external_id)`, preserve explicit semantic target, require stable HTTPS thumbnail and visible watch-page inputs, and omit unsupported structured-data fields.

- [ ] **Step 4: Route Video sitemap through shared indexability**

  Keep native Article sitemap independent and exclude unavailable/private/placeholder/incomplete/compliance-blocked Video pages.

- [ ] **Step 5: Run focused Video regressions**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php`

  Expected: PASS.

- [ ] **Step 6: Commit the Video slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Video public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSitemapProjectionTest.php && git commit -m "feat: harden eligible video seo projection"`

### Task 8: Integrate Living Knowledge stable-core protection

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Seo/LivingKnowledgeSeoStabilityGuard.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeFragmentProjector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeFragmentProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeFragmentProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php`

**Interfaces:**
- Consumes: existing Knowledge fragment/facet planning and stable-core fields.
- Produces: read-only fragment/facet projection with optional FAQ, LOW/MEDIUM/HIGH risk classification, unchanged canonical URL/slug/H1/identity/intent/robots/indexability/schema ID/redirect rules unless an explicit human-approved gate exists.

- [ ] **Step 1: Write failing tests**

  Cover same-topic enrichment, evidence-backed media, optional FAQ, stable-core preservation, HIGH identity change rejection, no automatic Article body rewrite, no FAQ rich-result dependency, and unavailable synthesis distinct from empty.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeFragmentProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php`

  Expected: FAIL on newly required protected fields/facet behavior.

- [ ] **Step 3: Implement minimal stable-core and optional FAQ integration**

  Keep enrichment read/plan/resolve-only; generated copy and candidate observations remain non-evidence, and no Knowledge/Article writer is invoked.

- [ ] **Step 4: Run focused Knowledge regressions**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeFragmentProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedLivingKnowledgeE2ETest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the Living Knowledge slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Seo/LivingKnowledgeSeoStabilityGuard.php public/wp-content/plugins/nhk-core/src/Application/Knowledge public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeFragmentProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php && git commit -m "feat: preserve living knowledge seo stable core"`

### Task 9: Add runtime read-back and observability boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/SeoRuntimeReadback.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Seo/SeoRuntimeReadbackResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/RenderedArticleVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleVerificationReader.php`
- Do not modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoDiagnosticReader.php` (the class is absent in the current runtime; read-back remains in the shared SEO boundary)
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SeoRuntimeReadbackTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RenderedArticleVerifierTest.php`

**Interfaces:**
- Consumes: an injectable read-only HTTP/runtime adapter and shared SEO projection snapshots.
- Produces: `SeoRuntimeReadback::verify(array $expected, callable $reader): SeoRuntimeReadbackResult` with `PASS`, `ENVIRONMENT_BLOCKED`/`UNAVAILABLE`, `MISMATCH` and field-level evidence for final URL/redirects/canonical/robots/title/H1/meta/image/structured data/sitemap/video/duplicate diagnostics.

- [ ] **Step 1: Write failing tests**

  Cover successful field-level read-back, redirect/canonical mismatch, unavailable infrastructure distinct from empty success, and no claim about Google indexing/ranking/canonical selection.

- [ ] **Step 2: Run focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoRuntimeReadbackTest.php`

  Expected: FAIL because the read-back result boundary is absent.

- [ ] **Step 3: Implement read-only runtime result and verifier**

  Preserve unavailable/error distinctions, redact secrets, return evidence rather than mutating canonical stores, and make the public diagnostic vocabulary explicit.

- [ ] **Step 4: Integrate Article/Video verification and update execution evidence**

  Record only locally verified contract/unit/runtime evidence in `V3_EXECUTION_STATE.md`; report unavailable exact runtime as `ENVIRONMENT_BLOCKED`, never as empty success.

- [ ] **Step 5: Run focused and complete verification**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SeoRuntimeReadbackTest.php public/wp-content/plugins/nhk-core/tests/Unit/RenderedArticleVerifierTest.php`; `composer test`; `composer lint`; `composer validate --no-check-publish`; `git diff --check`; and the repository secret-scan convention.

  Expected: focused tests and all applicable commands pass; integration is reported `ENVIRONMENT_BLOCKED` if the exact guarded runtime is unavailable.

- [ ] **Step 6: Commit the observability slice**

  Run: `git add public/wp-content/plugins/nhk-core/src/Application/Seo public/wp-content/plugins/nhk-core/src/Domain/Seo public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/src/Application/Video docs/architecture/V3_EXECUTION_STATE.md public/wp-content/plugins/nhk-core/tests/Unit/SeoRuntimeReadbackTest.php public/wp-content/plugins/nhk-core/tests/Unit/RenderedArticleVerifierTest.php && git commit -m "feat: add seo runtime readback diagnostics"`

## Plan self-review

- Spec coverage: tasks 1–9 map respectively to documentation sync, shared readiness/indexability, entity profiles, canonical/sitemap unification, Media/image, Article, Video, Living Knowledge and runtime observability; regression requirements are explicit in each task.
- Placeholder scan: no `TODO`, `TBD`, “implement later”, or unspecified “write tests” step is used. Task 9 explicitly requires confirming whether an optional diagnostic class exists before modifying it.
- Type consistency: shared results are `SeoReadinessResult` and `SeoIndexabilityResult`; `EntitySeoProjection::project`, `ArticleIntentOverlapPlanner::classify`, `ArticleSeoGate::evaluate`, `SitemapIndexabilityProjection::include`, `PreferredImageSeoProjection::forEndpoint` and `SeoRuntimeReadback::verify` are the cross-task interfaces.
- Authority review: no task adds an entity/predicate/operation/relation or writer; Product–Specimen remains fail-closed; FAQ remains optional projection; external search guidance remains non-normative.
- Safety review: no task authorizes migration, backfill, repair, production/staging/V2 mutation, local Video download, live URL change, push, merge or deploy.
