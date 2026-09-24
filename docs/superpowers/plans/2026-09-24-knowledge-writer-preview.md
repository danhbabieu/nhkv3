# NHK Knowledge Writer Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `nhk.knowledge.writer.preview` as a system-wide, read-only façade over the existing Universal Enrichment + Editorial Intelligence pipeline and expose it through the executable MCP/Ability surface.

**Architecture:** A new `KnowledgeWriterPreviewService` will normalize a bounded request, reuse the existing semantic resolver and shared enrichment boundary, then project Reader Journey, Composer and Quality outputs into a sanitized preview result. MCP catalog, dispatch, transport and Ability wiring will inject that façade without giving it any write-capable dependency.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit, existing NHK V3 MCP catalog/transport/Ability registration, existing semantic application services.

**Spec:** `docs/superpowers/specs/2026-09-24-knowledge-writer-preview-design.md`

## Global Constraints

- The capability is read-only and must not create or mutate Capture, Post, Article, Media, MediaUsage, Video, Authority, Knowledge, Claim, Source/Evidence, Graph, Proposal, Governance, Public Identity, SEO, or publication state.
- `GRAPH_REACHABLE` remains distinct from `FACTUALLY_APPLICABLE`.
- Public assertion specificity must not exceed support specificity.
- User-supplied observations/context remain contextual input and never become canonical Knowledge or Evidence through preview.
- No new Brain, semantic owner, Knowledge writer, Article writer, Capture path, or owner-specific feature is permitted.
- Production code must not branch on fixture names, brand/model/variant names, UUIDs, YouTube IDs, WordPress post IDs, or fixture titles.
- No migration, seed, import, deployment, push, staging mutation, production action, or live publication is part of this slice.
- Sparse Knowledge must produce a short grounded answer or explicit gaps; it must not pad, invent, or repeat provenance.
- Diagnostics are bounded, deterministic, secret-safe, and must not leak internal orchestration language into `answer`.

## Review Focus

- Ambiguous textual subject resolution must stop before retrieval rather than selecting the first candidate; test in Task 1.
- Reverse-graph and broader-context Claims must retain persisted direction and contextual treatment; test in Task 1.
- User observations must influence contextual presentation only and must not enter the Knowledge proposal branch; test in Task 1.
- An unsupported/unknown purpose, facet, depth, or output constraint must fail closed with stable diagnostics; test in Task 1.
- MCP catalog/Ability exposure must be read-only and actually dispatch to the service rather than merely appearing in discovery; test in Tasks 3 and 4.

---

### Task 1: Build the read-only preview façade and result projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeWriterPreviewService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewSafetyTest.php`

**Interfaces:**
- Consumes: `McpSemanticContextResolver`, `SharedEnrichmentBoundary`, `ReaderJourneyPlanner`, `SharedEditorialComposer`, `EditorialQualityGate`, and a normalized associative request.
- Produces: `preview(array $request): array` with top-level `status`, `subject`, `answer`, `depth`, `semantic_needs`, `coverage`, `used_knowledge`, `excluded_knowledge`, `context_used`, `gaps`, `warnings`, `quality`, `diagnostics`, and `read_only`.

- [ ] **Step 1: Write the failing façade contract tests**

Add tests that construct the service with real transient semantic components and deterministic in-memory retrieval fixtures. Pin these behaviors:

```php
public function test_preview_resolves_canonical_uuid_and_returns_reader_safe_trace(): void
{
    $result = $this->service()->preview([
        'subject' => ['canonical_uuid' => $this->subjectId, 'type' => 'variant'],
        'instruction' => 'Giải thích những điểm chính.',
        'purpose' => 'concise_answer',
    ]);

    self::assertSame('available', $result['status']);
    self::assertSame($this->subjectId, $result['subject']['canonical_id']);
    self::assertTrue($result['read_only']);
    self::assertNotEmpty($result['used_knowledge']);
    self::assertStringNotContainsString($this->subjectId, $result['answer']);
}

public function test_ambiguous_text_resolution_does_not_retrieve_or_guess(): void
{
    $result = $this->serviceWithAmbiguousResolver()->preview([
        'subject' => ['query' => 'đối tượng mơ hồ'],
        'instruction' => 'Tóm tắt chủ đề.',
    ]);

    self::assertSame('ambiguous', $result['status']);
    self::assertSame('', $result['answer']);
    self::assertContains('AMBIGUOUS_SUBJECT_REVIEW', $result['diagnostics']);
}

public function test_observations_are_context_only_and_not_knowledge_proposals(): void
{
    $result = $this->service()->preview([
        'subject' => ['canonical_uuid' => $this->subjectId, 'type' => 'variant'],
        'instruction' => 'Mô tả ngắn.',
        'observations' => [['value' => 'người dùng cho rằng mặt số sáng hơn']],
    ]);

    self::assertSame('context', $result['context_used'][0]['treatment']);
    self::assertSame([], $result['knowledge'] ?? []);
    self::assertStringNotContainsString('người dùng cho rằng', $result['answer']);
}
```

Add cases for Brand, Model, Variant and Classification locators, stable keys, requested/uncovered facets, each supported purpose, sparse Knowledge, duplicate Claims, broader contextual Claims, sibling Variant exclusion, reverse traversal, direct exact Claims, and unsupported observation.

- [ ] **Step 2: Run the focused tests and verify they fail for the missing service**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewSafetyTest.php
```

Expected: FAIL because `KnowledgeWriterPreviewService` and its preview projection do not exist yet. Correct fixture/setup errors until the failure is specifically about the missing behavior.

- [ ] **Step 3: Implement the minimal normalized request and purpose policy**

Implement `KnowledgeWriterPreviewService::preview(array $request): array` with a private bounded policy map:

```php
private const PURPOSES = [
    'concise_answer' => ['profile' => 'video', 'depth' => 'concise'],
    'collector_explanation' => ['profile' => 'article', 'depth' => 'deep'],
    'article_section' => ['profile' => 'article', 'depth' => 'deep'],
    'video_description' => ['profile' => 'video', 'depth' => 'concise'],
    'media_caption' => ['profile' => 'media', 'depth' => 'concise'],
    'media_alt' => ['profile' => 'image', 'depth' => 'concise'],
    'entity_summary' => ['profile' => 'article', 'depth' => 'deep'],
    'technical_explanation' => ['profile' => 'article', 'depth' => 'deep'],
];
```

Normalize `subject` into the resolver’s existing `context`/source shape, resolve UUID/stable-key/text in existing precedence order, and return `unresolved` or `ambiguous` before calling enrichment when no unique primary subject exists. Reject unknown purpose/depth/facet shapes with stable diagnostics. Convert observations to `UniversalInputEnvelope` context only; do not set `knowledge => true`, `observation`, or proposal-related options.

- [ ] **Step 4: Implement the shared pipeline call and projection**

Call the existing shared boundary with the resolved subject, instruction/topic, requested semantic needs/facets, observations, and selected profile. Use the existing `ReaderJourneyPlanner`, `SharedEditorialComposer`, and `EditorialQualityGate` objects for the transient presentation result. Derive `answer` only from the resulting `EditorialDraft` body/summary, then project:

```php
[
    'status' => $status,
    'subject' => $subjectPacket,
    'answer' => $readerText,
    'depth' => ['purpose' => $purpose, 'profile' => $profile, 'effective' => $depth],
    'semantic_needs' => $content['semantic_needs'] ?? [],
    'coverage' => $this->coverage($pack, $plan),
    'used_knowledge' => $this->traceUsedKnowledge($draft, $plan),
    'excluded_knowledge' => $this->traceExcludedKnowledge($retrieval, $pack),
    'context_used' => $this->contextUsed($envelope),
    'gaps' => $this->gaps($content, $plan),
    'warnings' => $this->warnings($quality, $content),
    'quality' => $this->quality($quality, $draft),
    'diagnostics' => $this->diagnostics($resolution, $content, $quality),
    'read_only' => true,
]
```

Preserve Claim/Knowledge ID, revision, original subject, target subject, facet, applicability, specificity, treatment, evidence/provenance trace and persisted graph path from existing plan/trace fields. Keep exclusion reasons bounded and deterministic. Use existing copy guards and a dedicated projection scrubber so identifiers and control metadata cannot enter `answer`.

- [ ] **Step 5: Run focused tests and make the façade pass**

Run the same PHPUnit command. Expected: PASS for canonical/textual resolution, purpose mapping, sparse/rich behavior, contextual exclusion, metadata scrubbing, deterministic replay, and the complete no-mutation service-level contract.

- [ ] **Step 6: Add mutation-safety assertions and refactor only after green**

Snapshot all in-memory repositories/fixtures used by the test harness before and after one preview call. Assert unchanged counts, revisions and serialized state for Capture, Post, Media/MediaUsage, Video, Authority, Knowledge/Claim, Source/Evidence, Graph, Proposal/Governance, Public Identity and SEO projection state. Confirm the façade constructor has no mutation dependency. Re-run focused tests after any cleanup.

- [ ] **Step 7: Commit the façade slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeWriterPreviewService.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewSafetyTest.php
git commit -m "feat: add read-only knowledge writer preview"
```

### Task 2: Add explicit contract coverage for universal factual safety and purpose behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewContractTest.php`

**Interfaces:**
- Consumes: `KnowledgeWriterPreviewService::preview(array $request): array`.
- Produces: regression coverage for the constitutional factual and editorial invariants required by the preview result.

- [ ] **Step 1: Write failing acceptance-matrix tests for safety and deterministic presentation**

Use generic synthetic Authority/Graph/Claim fixtures, not production names. Add one test per behavior group:

```php
public function test_reverse_graph_candidate_keeps_persisted_direction_and_context_treatment(): void
{
    $result = $this->serviceWithReverseTraversalFixture()->preview($this->request('entity_summary'));
    $used = $result['used_knowledge'];

    self::assertNotEmpty($used);
    self::assertSame('context', $used[0]['treatment']);
    self::assertSame('INVERSE_TRAVERSAL_OF_PERSISTED_EDGE', $used[0]['graph_path'][0]['direction_semantics']);
}

public function test_sparse_knowledge_does_not_pad_answer_with_provenance(): void
{
    $result = $this->serviceWithSparseKnowledge()->preview($this->request('concise_answer'));
    self::assertSame('sparse', $result['status']);
    self::assertLessThanOrEqual(2, substr_count($result['answer'], '.'));
    self::assertStringNotContainsString('provenance', strtolower($result['answer']));
}
```

Cover all eight purposes, requested and uncovered facets, rich/sparse/duplicate Claims, direct exact vs contextual broader Claims, incompatible sibling Variant, no internal-language leak, and deterministic replay (`json_encode($first) === json_encode($second)`).

- [ ] **Step 2: Run the contract tests and verify the missing projection behavior fails**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewContractTest.php
```

Expected: FAIL only for the new preview behavior that is not yet projected by the façade.

- [ ] **Step 3: Tighten projection using existing trace/quality fields**

Adjust only the preview façade/projection helpers, not the existing retrieval/applicability rules. Ensure exact facts can enter prose only when existing eligibility/applicability/public-composable checks pass; contextual or broader material remains qualified and cannot satisfy exact coverage. Ensure `quality` exposes existing dimensions for readability, repetition/information gain, grounding, internal-language leakage and specificity safety without inventing new semantic status values.

- [ ] **Step 4: Run focused semantic regressions**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist \
  public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewSafetyTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewContractTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyPlannerTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php
```

Expected: PASS with no new warnings or deprecations attributable to the slice.

- [ ] **Step 5: Commit the factual-safety regression slice**

```bash
git add public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewContractTest.php
git commit -m "test: cover knowledge writer preview safety"
```

### Task 3: Expose the preview through the canonical MCP transport

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewMcpTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php` only if shared catalog assertions require the new read tool

**Interfaces:**
- Consumes: `KnowledgeWriterPreviewService::preview(array $request): array`.
- Produces: discoverable `nhk.knowledge.writer.preview` tool and an executable read-only `tools/call` result.

- [ ] **Step 1: Write failing catalog/dispatch/transport tests**

Add assertions like:

```php
public function test_preview_is_catalogued_as_read_only_and_executable(): void
{
    $tool = $this->tool('nhk.knowledge.writer.preview');
    self::assertSame('read', $tool['kind']);
    self::assertFalse($tool['governed']);
    self::assertSame('nhk.knowledge.writer.preview', McpDispatchRegistry::handlerKey('nhk.knowledge.writer.preview'));
    self::assertTrue(McpToolCatalog::hasExecutableDispatchHandler('nhk.knowledge.writer.preview'));
}

public function test_tools_call_dispatches_structured_preview_without_mutation_capability(): void
{
    $response = $this->transportWithPreview()->dispatch([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'nhk.knowledge.writer.preview', 'arguments' => $this->request()],
    ], $this->legacyHeaders());

    self::assertFalse($response['body']['result']['isError']);
    self::assertTrue($response['body']['result']['structuredContent']['read_only']);
}
```

Assert schema-required `instruction`, bounded purpose/facet/depth fields, and that the capability resolver only requires ordinary read permission.

- [ ] **Step 2: Run the MCP tests and verify the new tool is absent/unhandled**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewMcpTest.php
```

Expected: FAIL because catalog, dispatch and transport do not yet know the tool.

- [ ] **Step 3: Add the bounded catalog schema and dispatch key**

Add one `self::tool()` definition with `kind=read`, `governed=false`, required `instruction`, and bounded `subject`, `purpose`, `requested_facets`, `depth`, `observations`, and `output_constraints` properties. Add the exact canonical name to `McpDispatchRegistry::HANDLERS`. Do not add it to any mutation allowlist or internal-only list.

- [ ] **Step 4: Inject the façade into McpTransport and dispatch it**

Add a nullable `KnowledgeWriterPreviewService` constructor dependency after the existing read/planning dependencies, preserving existing positional call compatibility where possible. Add a `nhk.knowledge.writer.preview` match branch that calls `preview($arguments)` and returns structured content through the existing transport response path. The branch must remain before any mutation helper and must not call `normalizeMutationResult` as a mutation.

- [ ] **Step 5: Run MCP tests and the existing transport contract tests**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist \
  public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewMcpTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/McpTransportBoundaryTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/McpSchemaParityTest.php
```

Expected: PASS; existing mutation/read permission behavior remains unchanged.

- [ ] **Step 6: Commit the MCP transport slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewMcpTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php
git commit -m "feat: expose knowledge writer preview through MCP"
```

### Task 4: Wire the production composition root and WordPress Ability

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` only if the generic public read map does not derive the new Ability automatically
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewExposureTest.php`

**Interfaces:**
- Consumes: the already-created `McpSemanticContextResolver`, `$sharedEnrichment`, and existing semantic engine/retrieval dependencies from `Plugin.php`.
- Produces: local runtime construction, `tools/list` discovery, Ability registration, and Ability callback read-back for the preview.

- [ ] **Step 1: Write failing production wiring and Ability tests**

Assert that the composition root constructs a preview service from existing dependencies, passes it into `McpTransport`, and that `McpAbilityRegistration::operatorEnabledAbilityAllowlist()` includes the derived Ability name. Assert metadata includes `readonly=true`, `destructive=false`, `idempotent=true`, public read surface, and the same schema hash as the MCP descriptor.

- [ ] **Step 2: Run the exposure tests and verify the wiring is absent**

Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewExposureTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php
```

Expected: FAIL because the production `Plugin.php` composition root does not inject the façade and no generated Ability callback can dispatch the tool.

- [ ] **Step 3: Wire the façade once from existing shared services**

Construct `KnowledgeWriterPreviewService` near the existing `$sharedEnrichment`/Article/Video adapter setup, passing the existing resolver and shared services. Pass it through the `McpTransport` construction at the single `new McpTransport(...)` call. Do not instantiate new repositories, engines, or mutation services for the preview.

- [ ] **Step 4: Verify Ability registration derives the new read tool**

Use the existing generic read-ability registration and `abilityNameForTool()` convention. Only add a targeted map change if tests prove the new tool is not covered. Keep the Ability public read-only and outside explicit internal admin mutation allowlists.

- [ ] **Step 5: Run exposure tests and verify local invocation**

Run the focused exposure tests, then perform a local PHP/MCP invocation through the existing test harness or WordPress bootstrap to verify `tools/list` contains the descriptor and `tools/call` returns structured preview data. If the connector cannot be reached, record `CLIENT_EXPOSURE_GAP` without substituting another path.

- [ ] **Step 6: Commit the composition/exposure slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewExposureTest.php
git commit -m "feat: wire knowledge writer preview ability"
```

### Task 5: Update active documentation, execution state, and run the verification gates

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` only if the active index needs a one-paragraph current capability entry
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: repository existing suites; no new production capability in this task

**Interfaces:**
- Consumes: the implemented read-only runtime capability and its focused test evidence.
- Produces: current documentation stating that `nhk.knowledge.writer.preview` consumes Universal Enrichment + Editorial Intelligence and is not a write path, plus a dated execution checkpoint.

- [ ] **Step 1: Write the documentation/runtime assertions before editing docs**

Add or extend a contract test that scans the active MCP documentation for the exact capability name, read-only classification, no-mutation boundary, and the distinction between runtime registration and connector exposure.

- [ ] **Step 2: Run the documentation test and verify the active docs lack the entry**

Run the focused documentation/contract test and confirm it fails only because the current active docs do not yet mention the capability.

- [ ] **Step 3: Add the minimal active documentation note**

Document the capability in the current MCP content operations contract and append a dated execution-state checkpoint containing focused test results, no-mutation evidence, and any integration/client environment gate. Do not copy the design/spec into normative documentation or introduce a new semantic vocabulary.

- [ ] **Step 4: Run the complete verification matrix**

Run, in order:

```bash
vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewSafetyTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewMcpTest.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeWriterPreviewExposureTest.php
vendor/bin/phpunit -c phpunit.xml.dist --testsuite "NHK Unit"
vendor/bin/phpunit -c phpunit.xml.dist --testsuite "NHK Contract"
vendor/bin/php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeWriterPreviewService.php
composer validate --no-check-publish
git diff --check
```

Attempt the guarded NHK Integration suite only when the configured `nhk_v3_test` environment is available. If WordPress/database bootstrap fails, report `INTEGRATION_ENVIRONMENT_GATED`; do not mutate or retry with another database.

- [ ] **Step 5: Run the special-case and secret scans**

Run:

```bash
rg -n "Odo|Jacquemart|YouTube|youtube|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89ABab][0-9a-fA-F]{3}-[0-9a-fA-F]{12}" public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeWriterPreviewService.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php
git diff -- . ':!public/wp-content/plugins/nhk-core/tests/fixtures' | rg -n "BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]" || true
```

Expected: no new fixture-specific production branch, credential, token, private key, or UUID-specific branch.

- [ ] **Step 6: Commit documentation and verification evidence**

```bash
git add docs/mcp/MCP_V3_CONTENT_OPERATIONS.md docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md docs/architecture/V3_EXECUTION_STATE.md
git commit -m "docs: record knowledge writer preview acceptance"
```

## Completion gate

Do not claim `KNOWLEDGE_WRITER_PREVIEW_READY` until focused tests, NHK Unit,
NHK Contract, PHP lint, Composer validation, diff check, special-case scan and
mutation-safety regression have passed. If runtime or connector invocation is
not verifiable, use `KNOWLEDGE_WRITER_PREVIEW_BLOCKED` and report the exact
environment gate while preserving the local implementation evidence.
