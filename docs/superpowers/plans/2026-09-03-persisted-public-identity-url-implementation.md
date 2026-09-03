# Persisted Public Identity and URL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace read-time semantic slug derivation with a persisted, governed public-identity boundary, shared deterministic Vietnamese normalization, resource-specific URL policies, one-hop historic redirects and one canonical URL result for every eligible public consumer.

**Architecture:** Authority and Video retain ownership of their canonical identities; a new public-identity repository stores route ownership, current slug, scope, revision and append-only historic routes. A pure normalizer is shared by route allocation and new-upload filename generation, while resource policies construct paths from persisted identity and governed context. Eligibility, route resolution and SEO projection converge on one application result; Article, MediaAsset, Knowledge Claim, Source/Evidence and Album/Gallery remain within their existing ownership or explicit gap boundaries.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress rewrite/template hooks, existing WPDB repositories and `dbDelta` migration conventions, existing Authority/Graph/Video/Media/Knowledge contracts, `UuidCodec`, and the repository's guarded integration test harness.

**Spec:** `docs/superpowers/specs/2026-09-03-persisted-public-identity-url-design.md`

## Global Constraints

- Read and obey `AGENTS.md`, `docs/constitution/READ_FIRST.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/V3_EXECUTION_STATE.md`, and each referenced normative contract before execution.
- The execution preflight must also read `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`, `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md`, `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`, `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md`, and `docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md`.
- This planning checkpoint creates no migration, allocates no identity, assigns no slug, changes no URL, migrates no data, renames no physical asset, creates no Video, changes no UUID/relation, and mutates no production/staging/V2 data.
- `wp_posts` remains the sole owner of Article title/body/editorial slug/permalink; Article is never added to Authority public-identity storage.
- Runtime registries and the Constitution are the only authority for entity types, endpoint types, predicates, operations, eligibility and public projection; unknown values fail closed.
- The logical current-identity uniqueness key is `(route_type, collision_scope, current_slug)` plus one owner per identity; current-vs-historic collisions and required native WordPress route collisions are rejected.
- Historic resolution is exact and direct: `old route -> current identity -> current canonical path`; no chains, loops, UUID/stable-key destinations or 200 responses for old routes.
- Public URLs never contain UUIDs, stable keys, random suffixes or database IDs; display-name changes do not change a persisted slug.
- Media detail remains a constitutional gate: if standalone indexable MediaAsset/Media detail would violate the Constitution, record `CONSTITUTION_CONFLICT`, obtain Owner decision, and do not silently implement it.
- Atomic Claim, Source and Evidence pages are not indexable without an approved registry, Constitution and projection contract; Album/Gallery remains `REGISTRY_GAP`.
- Every task is TDD: write the named failing test, run the exact command and observe the feature-specific failure before implementation; then run the passing command and commit only that task's files.

## File map and ownership

Create the following focused boundaries during execution:

- `public/wp-content/plugins/nhk-core/src/Shared/Text/VietnameseSlugNormalizer.php`: pure Unicode-to-ASCII token normalizer with typed invalid/empty/too-long outcomes.
- `public/wp-content/plugins/nhk-core/src/Contracts/PublicIdentity/PublicIdentityRepository.php` and `HistoricPublicRouteResolver.php`: current/history storage and exact resolution contracts.
- `public/wp-content/plugins/nhk-core/src/Domain/PublicIdentity/PublicIdentity.php`, `HistoricPublicRoute.php`, `PublicIdentityMutationResult.php`, and `PublicUrlResult.php`: immutable/read-model and blocker vocabulary.
- `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicIdentityService.php`, `PublicUrlProjector.php`, `HistoricPublicRouteService.php`, and `PublicRoutePolicyRegistry.php`: allocation, CAS mutation, resolution and policy dispatch.
- `public/wp-content/plugins/nhk-core/src/Application/Entity/AuthorityUrlPolicy.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php`, and the registered namespace policy implementation: route shape and collision scope only.
- `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php`, `WpdbHistoricPublicRouteResolver.php`, and `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PublicIdentityMigration014.php`: future additive persistence implementation; migration is not created or run in this planning checkpoint.
- `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php`: one consumer-facing canonical URL package.
- `public/wp-content/plugins/nhk-core/src/Application/Migration/PublicIdentityReadinessAudit.php` and `tools/public-identity-readiness-audit.php`: read-only inventory and receipt.

Existing files expected to be modified by later execution tasks include `PublicRouteResolver.php`, `PublicEntityRoutes.php`, `PublicMediaVideoRoutes.php`, `MediaVideoPageQuery.php`, `MediaFilenameNormalizer.php`, `VideoSeoProjection.php`, `VideoSitemapProjection.php`, `SearchSemanticQuery.php`, `EntityPageQuery.php`, `PublicEntityCollectionQuery.php`, `ArticleResearchPreflight.php`, theme `functions.php`, `index.php`, `entity.php`, `video.php`, `media.php`, relevant tests, `Plugin.php`, and the migration status wiring. Exact task ownership below prevents unrelated edits from being mixed.

---

### Task 1: Deterministic Vietnamese normalizer

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Shared/Text/VietnameseSlugNormalizer.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/VietnameseSlugNormalizerTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/bootstrap.php` only if the existing autoloader does not discover `src/Shared/Text`.

**Interfaces:**
- Consumes: `string $input` and an explicit configured maximum length.
- Produces: `normalize(string $input): NormalizedSlugResult`, where the result exposes `isValid(): bool`, `value(): string`, and typed codes `EMPTY_INPUT`, `EMPTY_RESULT`, `TOO_LONG`, or `UNSUPPORTED_INPUT`; no storage or WordPress calls.

- [ ] **Step 1: Write the failing corpus and edge tests.** Assert `Ô Đô→odo`, `Đồng hồ cổ→dong-ho-co`, `được→duoc`, `người Việt→nguoi-viet`, `sưu tập→suu-tap`, `Âm thanh điểm nhạc→am-thanh-diem-nhac`, `Vì sao người Việt gọi là 54?→vi-sao-nguoi-viet-goi-la-54`, `Ô Đô 36/10 – Gai-Carillon→odo-36-10-gai-carillon`, `Frère Jacques→frere-jacques`, and `Đồng hồ Pháp & Đức→dong-ho-phap-duc`; also assert emoji/punctuation removal, slash separator, repeated spaces/hyphens collapse, case folding, combining marks, empty result, deterministic max-length behavior, and 100 repeated calls returning byte-identical output.
- [ ] **Step 2: Run the failing test.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VietnameseSlugNormalizerTest.php`; expected failure is the missing class/result API, not an environment error.
- [ ] **Step 3: Implement the minimum pure normalizer.** Use explicit mappings for `Đ/đ` and Vietnamese precomposed/combining characters, lowercase, allow only `[a-z0-9]`, convert every rejected run to one separator, collapse/trim separators, and reject empty or over-limit results without `iconv`, locale or `remove_accents`.
- [ ] **Step 4: Run the passing test and lint.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VietnameseSlugNormalizerTest.php` and `/opt/homebrew/bin/php -l public/wp-content/plugins/nhk-core/src/Shared/Text/VietnameseSlugNormalizer.php`; expected: all tests pass and lint is clean.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Shared/Text/VietnameseSlugNormalizer.php public/wp-content/plugins/nhk-core/tests/Unit/VietnameseSlugNormalizerTest.php public/wp-content/plugins/nhk-core/tests/bootstrap.php && git commit -m "feat: add deterministic Vietnamese slug normalizer"`.

### Task 2: Public-identity domain and additive storage contract

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/PublicIdentity/PublicIdentity.php`, `HistoricPublicRoute.php`, `PublicIdentityMutationResult.php`, `PublicUrlResult.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/PublicIdentity/PublicIdentityRepository.php`, `HistoricPublicRouteResolver.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityDomainTest.php`, `PublicIdentityRepositoryContractTest.php`
- Plan-only future files, created only in the later persistence task after Owner/schema review: `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbHistoricPublicRouteResolver.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PublicIdentityMigration014.php`

**Interfaces:**
- Consumes: registered owner kind/id, route type, normalized slug, collision scope, policy version and expected revision.
- Produces: current record fields `identity_id`, `owner_kind`, `owner_id`, `route_type`, `current_slug`, `collision_scope`, `route_policy_version`, `revision`, timestamps; append-only historic record with owning `identity_id`, exact old route type/scope/path, old slug, replacement revision and timestamps; typed `PublicUrlResult` containing final path, eligibility, blockers/warnings and identity revision.

- [ ] **Step 1: Write failing contract tests.** Prove one owner/current identity, `(route_type, scope, slug)` uniqueness, owner mismatch rejection, current/history collision rejection, revision increment exactly once, policy-version preservation, and absence of UUID/stable key from generated paths.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityDomainTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityRepositoryContractTest.php`; expected: missing domain/repository contract failures.
- [ ] **Step 3: Implement domain types and repository interfaces only.** Keep interfaces independent from WPDB and WordPress; define exact exceptions/result codes for malformed owner, unknown route, conflict, stale revision, unavailable storage and ambiguous history. Describe Migration014 SQL in its future execution task without adding it now.
- [ ] **Step 4: Run passing tests and static checks.** Run the same PHPUnit command, `composer validate --no-check-publish`, and `git diff --check`; expected: pass, with no migration or database invocation.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Domain/PublicIdentity public/wp-content/plugins/nhk-core/src/Contracts/PublicIdentity public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityDomainTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityRepositoryContractTest.php && git commit -m "feat: define persisted public identity boundary"`.

### Task 3: Authority route policies and resolver adapter

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/AuthorityUrlPolicy.php`, `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicRoutePolicyRegistry.php`, `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicUrlProjector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php`
- Create/modify tests: `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverPersistedIdentityTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php`

**Interfaces:**
- Consumes: `AuthorityEntity`, `EntityTypeRegistry`, `PublicIdentityRepository`, `PublicEligibilityResult`, `StructuralContextQuery`, native-root probe and the shared normalizer only at allocation time.
- Produces: `AuthorityUrlPolicy::project(AuthorityEntity, PublicIdentity, StructuralContext): PublicUrlResult` for Brand root; parent-aware Model and Variant; namespace policies for Movement, Music, Component, Classification, Specimen and Product. Parent ambiguity, inactive/missing parent, duplicate scope, reserved/native collision, hydration loss and unavailable storage block.

- [ ] **Step 1: Write failing regression tests.** Cover all nine Authority types, Brand root/native collision, duplicate child names in separate parent scopes, missing/inactive/ambiguous Model/Variant parent chains, and proof that renaming `canonical_name` does not alter the persisted current slug.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverPersistedIdentityTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php`; expected: current read-time derivation or missing adapter failures.
- [ ] **Step 3: Implement the adapter.** Make `PublicRouteResolver` consume `PublicUrlProjector`; retain approved route shapes and compatibility parent diagnostics; remove request-time transliteration and any UUID/stable-key fallback. Do not add entity types or Graph predicates.
- [ ] **Step 4: Verify.** Run the focused command, `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php`, and `git diff --check`; expected: all focused tests pass and URL-less rows are excluded consistently.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Entity/AuthorityUrlPolicy.php public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicRoutePolicyRegistry.php public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicUrlProjector.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityEligibilityPolicy.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverPersistedIdentityTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicEndpointEligibilityResolverTest.php && git commit -m "feat: resolve Authority URLs from persisted identity"`.

### Task 4: Video URL policy and canonical canary shape

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php`, `VideoPublicContextSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoService.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSearchDocument.php`, `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php`
- Test: extend `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`; create `public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php`

**Interfaces:**
- Consumes: Video UUID, `(platform=youtube, external_video_id)`, persisted identity, available source snapshot, NHK editorial package, one controlled Hub, provenance, embed state, governed semantic attachments and explicit user hint.
- Produces: `/video/{semantic-slug}-{lowercase-external-video-id}/`; `VideoUrlPolicy` returns a typed blocker when source is malformed/private/unavailable/non-embeddable, editorial context is incomplete, Hub/provenance/attachment eligibility fails, or context is ambiguous.

- [ ] **Step 1: Write failing tests.** Assert canary UUID `01a06815-1e51-7964-b004-1ba79e488ad1` with `P4KaHX3LBOw` projects to `/video/odo-36-10-gai-carillon-p4kahx3lbow/`; source title changes preserve URL; fallback order is confirmed Variant → Model → Brand → Music → editorial context → explicit user hint, with no raw marketing-title fallback when governed context exists.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`; expected: current title-derived result and missing policy failures.
- [ ] **Step 3: Implement minimally.** Normalize only the selected semantic context, append the validated lowercase 11-character external ID, and pass the persisted URL result into Video SEO/sitemap/search. Preserve existing Video UUID, source identity, relation candidates and governed ingest/apply boundaries; never create a Video.
- [ ] **Step 4: Verify.** Run the same command plus `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php`; expected: available/indexable Video only, no UUID URL.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Video public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php && git commit -m "feat: add governed persisted Video URLs"`.

### Task 5: Historic route storage, exact resolver and one-hop HTTP redirect

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicIdentityService.php`, `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/HistoricPublicRouteService.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbHistoricPublicRouteResolver.php`
- Create after the migration gate: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PublicIdentityMigration014.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/LegacyUrlRedirects.php`
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityServiceTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/HistoricPublicRouteResolverTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteRedirectTest.php`

**Interfaces:**
- Consumes: repository contracts, `PublicRoutePolicyRegistry`, native WordPress route collision probe and CAS expected revision.
- Produces: first allocation; explicit governed slug change that atomically writes current, appends old route and increments revision once; exact historic lookup returning one current owner or fail-closed `NOT_FOUND`, `AMBIGUOUS`, `INELIGIBLE`, `NATIVE_ROUTE_CONFLICT` or `STORAGE_UNAVAILABLE`.

- [ ] **Step 1: Write failing tests.** Cover display rename preservation, explicit slug change, replay/idempotency, stale CAS, invalid input, two sequential changes resolving old→current directly, old malformed P4KaHX3LBOw URL 301→new canary, no chain/loop/200, ambiguous history, missing/ineligible owner and native conflict.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/HistoricPublicRouteResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteRedirectTest.php`; expected: absent persistence/resolver failures.
- [ ] **Step 3: Implement the minimum repository and adapter.** Add Migration014 only during the later implementation checkpoint, using additive tables and guarded migration conventions; wire `Plugin.php` only after migration review. Reuse the existing HTTP redirect boundary and send one 301 directly to the current projected path; old paths never render.
- [ ] **Step 4: Verify.** Run the focused tests, `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityRoutesTest.php`, and `git diff --check`; expected: no redirect-chain or alternate slugification path.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicIdentityService.php public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/HistoricPublicRouteService.php public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbHistoricPublicRouteResolver.php public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PublicIdentityMigration014.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/LegacyUrlRedirects.php public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/HistoricPublicRouteResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteRedirectTest.php && git commit -m "feat: resolve historic public routes in one hop"`.

### Task 6: SEO single-source and consumer parity

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php`, `public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`, `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSearchDocument.php`, `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/SearchApi.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/ReadApi.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicVideoSitemapRoutes.php`, `public/wp-content/themes/nhk-v3/functions.php`, `public/wp-content/themes/nhk-v3/index.php`, `public/wp-content/themes/nhk-v3/entity.php`, `public/wp-content/themes/nhk-v3/video.php`, `public/wp-content/themes/nhk-v3/media.php`, `public/wp-content/themes/nhk-v3/template-parts/article-card.php`, and `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

**Interfaces:**
- Consumes: one `PublicUrlResult` plus page eligibility, visible editorial data and allowed asset/evidence projection.
- Produces: identical final URL for HTML canonical, `og:url`, JSON-LD `url`, `mainEntityOfPage`, `VideoObject.url`, sitemap location, breadcrumbs, cards, search and internal links; excludes technical/historic/private/non-ready/placeholder/non-indexable routes.

- [ ] **Step 1: Write failing parity tests.** Build one projected result and assert every consumer receives the exact same path; scan the listed theme/serializer/search/SEO files to prove no local normalizer, parent reconstruction or UUID/stable-key link remains. Assert native WordPress editorial sitemap/permalink remains independent.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`; expected: divergence/local slugification failures.
- [ ] **Step 3: Implement the shared projection.** Thread `PublicUrlResult` through page, archive, REST/search, breadcrumb/card and SEO paths; keep empty success distinct from unavailable runtime, hydration loss, malformed row, collision and infrastructure failure.
- [ ] **Step 4: Verify.** Run the same PHPUnit command, `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`, and `git diff --check`; expected: all canonical/OG/JSON-LD/sitemap/link outputs converge.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoSearchDocument.php public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/SearchApi.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/ReadApi.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicVideoSitemapRoutes.php public/wp-content/themes/nhk-v3/functions.php public/wp-content/themes/nhk-v3/index.php public/wp-content/themes/nhk-v3/entity.php public/wp-content/themes/nhk-v3/video.php public/wp-content/themes/nhk-v3/media.php public/wp-content/themes/nhk-v3/template-parts/article-card.php public/wp-content/plugins/nhk-core/tests/Unit/PublicSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php && git commit -m "feat: unify public canonical URL projection"`.

### Task 7: Article integration boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`, `public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php` only where new-slug creation passes through the shared normalizer.
- Test: extend `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`, `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`, and `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`.

**Interfaces:**
- Consumes: native WordPress post state/post_name and the shared normalizer.
- Produces: new Article slug proposal normalized once; existing published `post_name` preserved; explicit slug change retains the native/governed historic redirect; no Authority public identity is allocated.

- [ ] **Step 1: Write failing tests.** Prove title changes keep published `post_name`, new Article slug uses shared Vietnamese behavior when appropriate, explicit slug change produces one old→new redirect, and no Authority identity is created.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`; expected: local Article slugifier or missing boundary failures.
- [ ] **Step 3: Implement minimally.** Route only new-slug creation through the shared normalizer; preserve existing published `post_name` and native WordPress ownership; keep explicit changes behind the existing governed redirect boundary.
- [ ] **Step 4: Verify.** Run the same command and `git diff --check`; expected: Article tests pass with no semantic mutation.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php && git commit -m "test: preserve WordPress Article URL ownership"`.

### Task 8: New-upload Media filename policy

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaFilenameNormalizer.php`, `public/wp-content/plugins/nhk-core/src/Application/Media/MediaIngestGateway.php`
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/MediaFilenameNormalizerTest.php`; extend `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`.

**Interfaces:**
- Consumes: shared normalizer, new-upload subject/view/original filename inputs and existing extension/suffix rules.
- Produces: deterministic new-upload filename only; filename remains distinct from Media identity, alt text, caption and semantic relation; legacy physical filenames are unchanged.

- [ ] **Step 1: Write failing tests.** Prove `Ô Đô` upload output is deterministic, supported extensions are preserved, empty subject/view handling is bounded, and a legacy physical path is never renamed.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MediaFilenameNormalizerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`; expected: duplicate `iconv` transliteration behavior or missing shared-normalizer injection.
- [ ] **Step 3: Implement minimally.** Remove duplicate transliteration, inject `VietnameseSlugNormalizer`, preserve suffix/extension behavior and limit invocation to new upload generation.
- [ ] **Step 4: Verify.** Run the same command, `/opt/homebrew/bin/php -l public/wp-content/plugins/nhk-core/src/Application/Media/MediaFilenameNormalizer.php`, and `git diff --check`; expected: pass with no rename operation.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Media/MediaFilenameNormalizer.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaIngestGateway.php public/wp-content/plugins/nhk-core/tests/Unit/MediaFilenameNormalizerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php && git commit -m "feat: normalize only new Media upload filenames"`.

### Task 9: Media route constitutional gate

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php`, `PublicMediaAssetRoutes.php`, `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- Test: extend `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php` and `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`; create `public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaRouteGateTest.php`.
- Create only after Owner gate: `docs/architecture/MEDIA_PUBLIC_ROUTE_DECISION_2026-09-03.md`

**Interfaces:**
- Consumes: Media/MediaAsset delivery contracts, `MediaDetailTypeRegistry`, route ledger and Constitution §17.
- Produces: delivery-only MediaAsset URLs and a documented non-indexable/404 outcome for standalone Media detail unless an explicit constitutional/editorial contract authorizes it.

- [ ] **Step 1: Write failing tests.** Assert current `/media/{uuid}` cannot become indexable by inertia, MediaAsset delivery remains usable, and route behavior exposes the constitutional conflict rather than selecting a hidden policy.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaRouteGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php`; expected: current Media route exposure or missing diagnostic failures.
- [ ] **Step 3: Reconcile before implementation.** Compare Constitution §17, `V3_PUBLIC_ROUTE_AUDIT.md`, media detail registries and runtime routes. Record `CONSTITUTION_CONFLICT`, obtain explicit Owner decision, and do not wire standalone detail until the decision is recorded.
- [ ] **Step 4: Verify.** Run the focused tests and `git diff --check`; expected: no silent route choice and asset delivery remains separate.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaAssetRoutes.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaRouteGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php docs/architecture/MEDIA_PUBLIC_ROUTE_DECISION_2026-09-03.md && git commit -m "test: gate standalone Media routes constitutionally"`.

### Task 10: Knowledge public eligibility and registry gaps

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicKnowledgeRoutes.php`, `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgePageQuery.php`, `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEndpointEligibilityResolver.php`
- Test: extend `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgePageQueryTest.php`; create `PublicKnowledgeEligibilityTest.php` and `PublicProjectionGapTest.php`.

**Interfaces:**
- Consumes: runtime registries, Constitution, `PublicEndpointEligibilityResolver`, Knowledge Claim/Source/Evidence repositories and projection contracts.
- Produces: no atomic Claim/Source/Evidence standalone route; eligible bounded provenance/related projections only; Album/Gallery remains `REGISTRY_GAP`; unknown registry or eligibility values fail closed.

- [ ] **Step 1: Write failing tests.** Assert Claim/Source/Evidence UUID and stable-key inputs cannot produce indexable HTML routes, eligible evidence may appear only through bounded projections, and Album/Gallery reports `REGISTRY_GAP`.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicKnowledgeEligibilityTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicProjectionGapTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgePageQueryTest.php`; expected: missing or over-broad route eligibility failures.
- [ ] **Step 3: Implement minimally.** Enforce registry + Constitution + eligibility + projection contract before returning public links; preserve provenance-only use and honest unavailable/empty outcomes.
- [ ] **Step 4: Verify.** Run the same command and `git diff --check`; expected: no invented route, entity, predicate or projection type.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicKnowledgeRoutes.php public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgePageQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEndpointEligibilityResolver.php public/wp-content/plugins/nhk-core/tests/Unit/PublicKnowledgeEligibilityTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicProjectionGapTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgePageQueryTest.php && git commit -m "test: enforce Knowledge public eligibility boundaries"`.

### Task 11: Read-only public-identity readiness audit

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Migration/PublicIdentityReadinessAudit.php`, `public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityReadinessAuditTest.php`, `tools/public-identity-readiness-audit.php`, `docs/architecture/PUBLIC_IDENTITY_READINESS_AUDIT_2026-09-03.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` only after the audit checkpoint is complete.

**Interfaces:**
- Consumes: read-only Authority/Video/Media/MediaAsset/Knowledge/Source/Evidence/WordPress route repositories and public identity repository; exact runtime/database guard.
- Produces: counts and per-row diagnostics for physical/hydrated/current/history/duplicate/collision/invalid-slug/eligibility state, native-route collisions, malformed canary source and canary redirect readiness; zero writes and a machine-readable receipt.

- [ ] **Step 1: Write failing tests.** Assert empty-success vs unavailable runtime, hydration loss, malformed row, collision, historic/current conflict, ineligible owner and infrastructure failure remain distinct; assert canary UUID and external ID are classified without mutation.
- [ ] **Step 2: Run the failing tests.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityReadinessAuditTest.php`; expected: missing audit service/result failures.
- [ ] **Step 3: Implement bounded read-only audit.** Require the configured authorized runtime, use no INSERT/UPDATE/DELETE/DDL, do not assign slugs or create redirects/edges, and emit redacted diagnostics only. Include the exact malformed canary path from the spec as input evidence.
- [ ] **Step 4: Verify.** Run the focused PHPUnit command and `php tools/public-identity-readiness-audit.php --help`; then run the audit only with an explicitly authorized runtime command. Expected: receipt identifies readiness/blockers and confirms mutation count zero.
- [ ] **Step 5: Commit.** `git add public/wp-content/plugins/nhk-core/src/Application/Migration/PublicIdentityReadinessAudit.php public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityReadinessAuditTest.php tools/public-identity-readiness-audit.php docs/architecture/PUBLIC_IDENTITY_READINESS_AUDIT_2026-09-03.md docs/architecture/V3_EXECUTION_STATE.md && git commit -m "audit: add read-only public identity readiness report"`.

### Task 12: Owner-approved canary re-projection and cutover readiness

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Migration/CanaryPublicIdentityProjection.php`, `public/wp-content/plugins/nhk-core/tests/Integration/CanaryPublicIdentityProjectionIntegrationTest.php`, `docs/architecture/PUBLIC_IDENTITY_CUTOVER_READINESS_REPORT_2026-09-03.md`
- Modify only after explicit bounded approval: `public/wp-content/plugins/nhk-core/src/Plugin.php`, migration target/status wiring and the approved canary execution adapter.
- Do not modify: Video UUID, YouTube identity, relation rows, legacy Article bodies, physical MediaAsset filenames or unrelated semantic records.

**Interfaces:**
- Consumes: completed Migration014, owner-approved scope, readiness receipt, persisted identity/CAS service, Video URL policy, exact canary UUID `01a06815-1e51-7964-b004-1ba79e488ad1`, YouTube ID `P4KaHX3LBOw`.
- Produces: only the bounded canonical projection `/video/odo-36-10-gai-carillon-p4kahx3lbow/` and one direct 301 from `/video/kham-pha-odo-36-10-con-10-bua-d-c-nhieu-ng-i-ch-i-vi-nh-n-hoang-trong-cac-bo-s-u-tap-dong-h-o-c-cung-nhk-p4kahx3lbow/`; receipt/read-back proves UUID, external identity, relations and no duplicate Video.

- [ ] **Step 1: Write failing guarded integration tests.** Against exact `nhk_v3_test`, assert current/history records, one-hop redirect, canonical self-link, no old 200, preserved Video identity/relations, and rollback/no mutation on collision or failed eligibility.
- [ ] **Step 2: Run the failing tests.** Run `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Integration/CanaryPublicIdentityProjectionIntegrationTest.php`; expected: absent Migration014/identity wiring failure or guarded skip when environment is unavailable, never a production mutation.
- [ ] **Step 3: Implement only after gates.** First produce the Cutover Readiness Report and obtain Owner approval; then apply one bounded canary operation through the governed identity boundary. Do not call this task complete if runtime is unavailable or if the Media constitutional gate remains unresolved.
- [ ] **Step 4: Verify.** Re-run the exact guarded test, full relevant Unit suite, `git diff --check`, PHP lint, Composer validation, and a secret review; expected: evidence-backed pass or explicit `ENVIRONMENT_BLOCKED`, not an inferred success.
- [ ] **Step 5: Commit.** `git add` only the canary adapter, integration test, readiness report and explicitly approved wiring, then `git commit -m "feat: add owner-gated public identity canary"`; no final production cutover, push or merge is included.

## Plan self-review

- Tasks 1–2 cover the deterministic normalizer, owner/canonical UUID boundary, route type, current slug, scope, revision/CAS, historic slugs, uniqueness, lifecycle and fail-closed storage semantics.
- Task 3 covers Brand, Model, Variant, Movement, Music, Component, Classification, Specimen, Product and native WordPress collision behavior.
- Task 4 covers governed Video context, external-ID suffix, title stability and the P4KaHX3LBOw expected path without creating a Video.
- Task 5 covers exact historic lookup and one-hop redirect semantics; Task 6 covers every required SEO/link consumer and excludes local slugification.
- Task 7 covers Article ownership/title stability; Task 8 covers new-upload-only filename normalization; Tasks 9–10 explicitly gate Media routes, Knowledge eligibility and Album/Gallery registry gap.
- Task 11 is read-only readiness inventory; Task 12 is the later Owner-approved bounded canary and requires a Cutover Readiness Report.
- No placeholder is used; no task authorizes migration or production mutation during plan writing.
