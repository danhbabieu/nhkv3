# Dictionary Lexical Knowledge Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a governed lexical dictionary layer that detects terms across Article, Knowledge, Media/Image and Video workflows, reuses existing canonical destinations, creates private review candidates for unresolved terms, supports approved aliases/search/autolinking, and exposes a public `/tu-dien/` hub without duplicating semantic truth.

**Architecture:** Add a bounded Dictionary domain beside Knowledge rather than a new Authority entity type. Persistence is isolated into concept/label/candidate/mention tables; read-only detector/resolver/link planner are reused by Article/Video/Search/public projection. Curation writes remain dedicated lexical writes with revision/idempotency/read-back; any secondary semantic mutation remains in existing Governance.

**Tech Stack:** PHP 8.x, WordPress plugin architecture, wpdb repositories/migrations, PHPUnit-style unit/integration tests used by the repository, WordPress REST/rewrite/public route hooks.

**Spec:** `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`

## Global Constraints

- Constitution remains supreme; this feature creates no new Authority type or Graph predicate.
- `SEARCH FIRST → RESOLVE → REUSE → CREATE CANDIDATE ONLY IF UNRESOLVED`.
- Article body, Media binary, Video identity and Knowledge/Evidence remain owned by their current bounded contexts.
- OCR/filename/caption/recognition/Video metadata/transcript observations are candidate signals only, not automatic Evidence or Graph truth.
- Unknown/ambiguous dictionary terms do not by themselves block Article ingest/publication.
- Only approved unambiguous labels can auto-link; stored WordPress body must not be silently rewritten.
- Owner-delegated dictionary concepts do not create duplicate indexable pages.
- Runtime unavailability is explicit and never an empty success.
- No generic WordPress Post/CPT/taxonomy/postmeta semantic fallback.

---

### Task 1: Lock documentation routing and contracts

**Files:**
- Modify: `docs/constitution/READ_FIRST.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`
- Modify: `docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md`
- Create: `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` (already created by design approval)

**Interfaces:**
- Consumes: current Constitution and bounded-context contracts.
- Produces: one current documentation route for all dictionary-aware operations.

- [ ] Add Dictionary/Lexical concern to `READ_FIRST.md` and cross-link from Article, Knowledge, Media, Video, MCP and SEO sections.
- [ ] Add Dictionary row to current status index, explicitly marking it a lexical owner and not semantic Authority.
- [ ] Add per-domain integration clauses matching the spec, without copying the entire spec into each contract.
- [ ] Verify no modified contract claims runtime READY before implementation/read-back.

### Task 2: Domain model and pure resolver behavior (TDD)

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryConcept.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryLabel.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryCandidate.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryMention.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryResolution.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryCandidateState.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermNormalizer.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryResolver.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLinkPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryResolverTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryLinkPlannerTest.php`

**Interfaces:**
- Consumes: approved concept/label rows and existing-owner resolver callback.
- Produces: `DictionaryResolution`, normalized term keys and projection-only link plans.

- [ ] Write failing tests for approved exact/alternate label reuse, ambiguity, unknown term, suppression, longest-phrase-first and first-occurrence-only linking.
- [ ] Verify RED with a local lightweight PHP assertion harness when PHPUnit is unavailable.
- [ ] Implement minimal domain/value objects and resolver/link planner.
- [ ] Verify GREEN and PHP syntax for every new class.

### Task 3: Persistence, migration and repositories (TDD)

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryConceptRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryCandidateRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryMentionRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryConceptRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryCandidateRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryMentionRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/DictionaryMigration015.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMigration015Test.php`

**Interfaces:**
- Consumes: domain objects from Task 2 and wpdb.
- Produces: dedicated lexical persistence and migration version 15.

- [ ] Write failing schema/repository contract tests for uniqueness, revision, candidate idempotency, suppression and mention deduplication.
- [ ] Implement migration tables/indexes and repository adapters.
- [ ] Wire migration target/run/activation behind existing guarded migration policy.
- [ ] Verify syntax and repository test expectations.

### Task 4: Detector and candidate/mention orchestration (TDD)

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPlanningService.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPlanningServiceTest.php`

**Interfaces:**
- Consumes: text observations with source kind/id/context, repositories, resolver.
- Produces: planning packet `{resolved_terms, ambiguous_terms, candidate_terms, internal_link_candidates, warnings}`.

- [ ] Write failing tests proving unknown observations create/update one private candidate, suppressed terms are not recreated, and observations never create semantic data.
- [ ] Implement deterministic detector/normalizer and planning orchestration.
- [ ] Add source-strength/context metadata and mention upsert.
- [ ] Verify GREEN and syntax.

### Task 5: Article and Living Knowledge integration (TDD)

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleResearchResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create/Modify tests: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php` only if required to expose lexical reuse input without changing Knowledge ownership.

**Interfaces:**
- Consumes: `DictionaryPlanningService` read-only planner.
- Produces: Article research `dictionary_plan`; Knowledge planning may consume approved lexical resolution only as a matching aid.

- [ ] Add failing test that unresolved candidate appears in `dictionary_plan` but does not make `ready_for_draft=false` by itself.
- [ ] Add failing test that approved existing term produces canonical internal-link candidate.
- [ ] Implement optional dictionary planner dependency to preserve backwards compatibility.
- [ ] Wire runtime planner in Plugin with current Article research inventory.
- [ ] Verify existing Article behavior remains unchanged when planner is absent/unavailable except for explicit warning.

### Task 6: Media/Image and Video detection seams (TDD)

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php` or add focused `Application/Dictionary/DictionaryMediaObserver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoIntakeService.php` or add focused `Application/Dictionary/DictionaryVideoObserver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObserverTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryVideoObserverTest.php`

**Interfaces:**
- Consumes: permitted editorial metadata/authorized transcript plus explicit Video context.
- Produces: lexical candidates/mentions only.

- [ ] Write failing tests proving OCR/filename/caption are weak signals and cannot create semantic relations/evidence.
- [ ] Write failing tests proving Video explicit target stays unchanged while detected terms remain lexical candidates.
- [ ] Implement focused observers rather than broadening Media/Video ownership.
- [ ] Wire observers at preview/post-ingest planning seams without changing existing governed mutation order.

### Task 7: Public dictionary hub, canonical owner delegation and autolink projection (TDD)

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicDictionaryRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify relevant theme/router/template files discovered under `public/wp-content/themes/` to render `/tu-dien/` and dedicated concept pages.
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PublicDictionaryRoutesTest.php`

**Interfaces:**
- Consumes: approved concepts, labels, public eligibility/canonical route callbacks.
- Produces: `/tu-dien/` hub and `/tu-dien/{slug}/` only for dictionary-owned concepts.

- [ ] Write failing tests for hub visibility, owner-delegated direct destination, dedicated page eligibility, no draft candidate exposure and one-hop historic redirect behavior.
- [ ] Implement public query/routes and projection-only autolink filter.
- [ ] Increment rewrite version when routes are added.
- [ ] Verify owner-delegated concepts do not create duplicate indexable pages.

### Task 8: Search and SEO integration (TDD)

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Seo/DictionarySeoProjection.php`
- Modify sitemap/public route wiring only where a dictionary-owned page is eligible.
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeoProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php`

**Interfaces:**
- Consumes: approved dictionary labels and resolved destinations.
- Produces: alias-expanded search and projection-only `DefinedTerm` structured data for dictionary-owned pages.

- [ ] Write failing tests that only approved aliases expand search.
- [ ] Write failing tests that delegated concepts canonicalize to existing owner and are excluded from dedicated sitemap output.
- [ ] Implement search expansion and SEO projection without semantic writes.
- [ ] Verify canonical/OG/internal-link/sitemap destination agreement.

### Task 9: Admin/MCP review inbox and bounded curation (TDD)

**Files:**
- Create focused Dictionary application services for review decisions.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create Dictionary MCP/Admin unit tests.

**Interfaces:**
- Produces read actions for dictionary search/candidate inbox/concept detail and dedicated authorized lexical curation actions with revision/idempotency/read-back.

- [ ] Write failing tool-catalog/read tests before adding tool definitions.
- [ ] Implement read-only dictionary tools first.
- [ ] Implement dedicated lexical curation operations; never call generic WordPress semantic writers.
- [ ] Add review actions attach-label/create-draft/approve/ambiguous/reject/ignore/do-not-suggest.
- [ ] Verify any secondary Knowledge/Graph mutation remains a separate Governance proposal.

### Task 10: Dry-run backfill and final verification

**Files:**
- Create a read-only/backfill planner under `Application/Dictionary` and bounded CLI/Admin entrypoint if the repository pattern supports it.
- Add unit tests for resolved/candidate/ambiguous/suppressed counts and no-write confirmation.
- Update `docs/architecture/V3_EXECUTION_STATE.md` with dated, explicitly non-normative evidence only after verification.

**Interfaces:**
- Consumes: existing Article/Knowledge/Media/Video inventories.
- Produces: dry-run report and optional candidate/mention persistence only after explicit governed apply.

- [ ] Write failing no-write dry-run test.
- [ ] Implement bounded batch planner with deterministic counters and source breakdown.
- [ ] Run PHP syntax checks for all changed PHP files.
- [ ] Run focused local assertion harness for new pure components.
- [ ] Run repository CI/workflow if available; otherwise record CI unavailability honestly.
- [ ] Compare feature branch with `main`, inspect every changed file and verify no out-of-scope semantic writer or new Authority/Graph vocabulary slipped in.
- [ ] Record final blockers and runtime-unverified items without claiming live readiness.
