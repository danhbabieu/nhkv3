# Intent-Scoped Publication and Semantic-Delta Gates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make publication requirements depend on persisted content intent and
an explicit semantic delta, while preserving Governance for genuine semantic
mutations and hard-blocking identity/CAS failures.

**Architecture:** The Capture pipeline remains the composition boundary.
`GovernedCaptureContinuationService` decides whether a semantic plan exists;
`CaptureArticlePreflightHandoff` publishes an applicability packet;
`ArticlePublicationGate` evaluates that packet; `CompletionCoordinator` counts
only owners applicable to the requested intent. Ordinary Article prose remains
WordPress editorial truth and does not create a Graph relation merely because a
subject or candidate is present.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, Symfony UID, existing NHK V3 array
contracts and domain enums.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Use only the persisted intent and registered semantic-delta contract. Do not
  infer semantic mutation from a fixture, candidate count, UI field or legacy
  V2 shape.
- Use the exact requirement classifications `REQUIRED`, `CONDITIONAL`,
  `OPTIONAL`, `NOT_APPLICABLE`, policies `VERIFY`, `AUTO_RECONCILE`,
  `HUMAN_REVIEW`, `HARD_BLOCK`, and observed states
  `VERIFIED`, `PENDING`, `RECONCILE`, `REVIEW_REQUIRED`, `BLOCKED`.
- Keep `GovernedCaptureContinuationService::execute()` and
  `CaptureArticlePreflightHandoff::build()` callable with their current public
  signatures; extend returned evidence rather than introducing a parallel
  publication protocol.
- Do not change Article title/body/author/date/category/permalink ownership,
  the physical media uploader, Video orchestration, Graph storage, or
  Authority identity rules.
- No live or staging mutation, direct database write, attachment repair, or
  Case 573 execution is part of this plan.

## Task 1: Lock the intent-scoped semantic contract with failing tests

- [ ] **Component:** Governed continuation, handoff and publication gate
  contract tests.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
  - Do not create a production file in this task.

  **Interfaces:** The tests consume the current service constructors and array
  contexts. They produce the required `semantic_delta` and `requirements`
  assertions for the implementation task.

  **TDD steps:**

  1. Add one failing test for an ordinary `IMAGE_ARTICLE` with a valid subject,
     Article ID and one claim candidate. The test must prove that no
     Governance plan or Article subject relation is created:

     ```php
     public function testOrdinaryImageArticleWithNoSemanticDeltaSkipsSemanticMutation(): void
     {
         $governance = $this->governanceDoubleThatFailsIfCalled();
         $service = $this->continuationService($governance);

         $result = $service->execute('capture-573-fixture', 'resume-1', [
             'content_intent' => [
                 'intent' => 'IMAGE_ARTICLE',
                 'source' => 'CAPTURE',
                 'semantic_delta' => ['status' => 'NONE'],
             ],
             'article' => ['id' => 573, 'endpoint_key' => '1:573'],
             'primary_subject' => ['id' => 'subject-vedette-37'],
             'claim_candidates' => [['predicate' => 'invented_candidate']],
         ]);

         self::assertSame('NOT_REQUIRED', $result['requirements']['semantic_delta']['applicability']);
         self::assertSame('SKIPPED', $result['requirements']['semantic_delta']['state']);
         self::assertSame([], $result['plans']);
         self::assertNotContains('wp_post --about--> subject', $this->relationTypes($result));
     }
     ```

  2. Add a failing explicit-delta test using `KNOWLEDGE_DELTA` with
     `semantic_delta.status=REQUIRED`. Assert that the normal proposal,
     approval and canonical readback lifecycle remains requested. Add a
     `MIXED` test with an approved semantic branch and assert the same governed
     path. Add a stale subject revision/CAS test and assert `HARD_BLOCK` plus
     `BLOCKED`; identity and concurrency safety must not become optional.

  3. Add a failing handoff/gate test that passes a mixed packet containing
     semantic `NOT_APPLICABLE`, Article media `REQUIRED`, and route/render
     `REQUIRED`. Assert the gate skips the semantic owner and still evaluates
     all Article/publication owners.

  4. Run the focused suite and record the expected pre-implementation failure:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
     ```

     Expected failure: the ordinary Article currently emits a semantic plan or
     returns `SEMANTIC_SUBJECT_OR_DELTA_REQUIRED`, and the handoff has no
     applicability packet.

  5. Do not implement until the new tests fail for the stated reason. Run
     `git diff --check` on the test-only diff.

  6. Commit the red contract tests with:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
     git commit -m "test: define intent-scoped publication regressions"
     ```

## Task 2: Implement applicability-aware semantic planning and publication evidence

- [ ] **Component:** Semantic-delta classifier, governed continuation planner,
  preflight handoff, publication gate and completion aggregation.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` only where it carries the requirement packet.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php` if its required-owner aggregation currently assumes semantic owners for every Article.
  - Test files remain the four files from Task 1.

  **Interfaces:** `execute()` consumes `context['content_intent']['semantic_delta']`
  and produces `requirements.semantic_delta`. `build()` consumes semantic
  status and produces a requirement map without changing its argument list.
  `check()` consumes that map through its existing `$evidence` argument and
  produces the existing gate result plus explicit applicability evidence.

  **TDD steps:**

  1. Keep the Task 1 tests as the single failing specification and add a small
     private classifier in the test fixture for the exact input forms being
     accepted. Do not broaden the fixture to accept arbitrary truthy values.

  2. Implement the smallest internal decision function in
     `GovernedCaptureContinuationService`, for example:

     ```php
     private function semanticDeltaRequested(array $context): bool
     {
         $intent = strtoupper((string) ($context['content_intent']['intent'] ?? ''));
         $status = strtoupper((string) ($context['content_intent']['semantic_delta']['status'] ?? 'NONE'));

         if ($status === 'REQUIRED') {
             return in_array($intent, ['KNOWLEDGE_DELTA', 'AUTHORITY', 'MIXED'], true);
         }

         return false;
     }
     ```

     The implementation must reject the current `count($variants) === 1`
     shortcut for ordinary Article intents. Gate the Article relation plan and
     Knowledge/claim child plans behind this decision. Preserve the existing
     governed lifecycle when it returns true.

  3. When an Article has no requested semantic delta, return an empty semantic
     plan result with `applicability=NOT_REQUIRED`, `policy=VERIFY`,
     `state=SKIPPED`, and evidence identifying the persisted intent and
     semantic-delta status. It must not be reported as a missing required
     subject. Preserve `HARD_BLOCK/BLOCKED` for identity ambiguity, stale
     revision and CAS failure before this skip is applied.

  4. Extend `CaptureArticlePreflightHandoff::build()` with a stable packet
     shape:

     ```php
     'requirements' => [
         'semantic_delta' => [
             'applicability' => 'NOT_REQUIRED',
             'policy' => 'VERIFY',
             'state' => 'SKIPPED',
             'evidence' => ['intent' => 'IMAGE_ARTICLE', 'status' => 'NONE'],
         ],
         // Existing Article/media/route requirements remain present.
     ],
     ```

     Map a genuine semantic writeback to `VERIFIED` only when its governed
     readback is `APPLIED`; do not use `APPLIED` as a substitute for
     `NOT_REQUIRED`.

  5. Update `ArticlePublicationGate::check()` to evaluate only applicable
     requirements. `NOT_APPLICABLE` and semantic `NOT_REQUIRED` are skipped;
     `REQUIRED` still needs `VERIFIED`; `HUMAN_REVIEW` and `HARD_BLOCK` retain
     their existing failure behavior. Keep CAS, identity, duplicate and route
     checks independent from semantic applicability.

  6. Update completion aggregation so an ordinary Article does not wait for a
     semantic owner that was explicitly skipped. The completion report must
     preserve owner evidence and distinguish an absent owner from an
     unavailable runtime.

  7. Run the exact focused suite and require PASS:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
     ```

  8. Run PHP lint on every modified production file and `git diff --check`:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php
     git diff --check
     ```

  9. Commit the implementation family exactly:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php
     git commit -m "feat: scope publication requirements by intent"
     ```

## Task 3: Prove Article, explicit-delta and identity non-regression

- [ ] **Component:** Capture convergence and contract regression boundary.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php` if its persisted intent fixture does not include the semantic-delta status.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php` only to assert that research remains read-only for ordinary Article prose.
  - Modify `public/wp-content/plugins/nhk-core/tests/Contract/` tests only when an existing contract test owns the returned requirement packet; do not create a duplicate contract.
  - No production file is expected unless a test exposes a missing handoff field.

  **Interfaces:** Tests consume `ContentIntentRouter::route()`, the governed
  continuation result and the publication handoff. They produce evidence that
  the public Article path remains editorial and the explicit semantic path
  remains governed.

  **TDD steps:**

  1. Add the Case 573-equivalent fixture using post `573`, endpoint `1:573`,
     subject `Vedette 37`, `IMAGE_ARTICLE`, and `semantic_delta=NONE`; use an
     isolated in-memory relation/proposal double, never the staging object.
     Assert no relation, no Knowledge write, no Governance requirement and no
     publication failure caused solely by semantic absence.

  2. Add explicit `KNOWLEDGE_DELTA` and approved `MIXED` fixtures and assert
     that proposal creation, governed approval and final canonical readback are
     still represented. Add a conflicting identity and stale CAS fixture and
     assert the final status remains blocked.

  3. Run focused and relevant contract suites:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php \
       public/wp-content/plugins/nhk-core/tests/Contract
     ```

     Expected result: PASS with ordinary Article and explicit semantic paths
     both covered; no test may require an external database or live URL.

  4. Run the production lint and whole unit suite before the slice boundary:

     ```text
     composer lint
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     git diff --check
     ```

  5. Review the diff for absence of new entity types, predicates, endpoint
     types, media roles, uploader changes and Video branches. Run a targeted
     secret review over changed files; conceptual state-token names are not
     credentials.

  6. Commit only the final regression tests with:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Contract
     git commit -m "test: verify publication intent boundaries"
     ```

## Slice completion gate

- [ ] Focused tests, relevant contract tests, unit suite, PHP lint and
  `git diff --check` are PASS.
- [ ] The requirement packet is documented in the implementation diff and is
  consumed by the existing publication gate rather than a second gate.
- [ ] No ordinary Article creates `wp_post --about--> subject` without an
  explicit semantic delta.
- [ ] Explicit Knowledge/Authority/MIXED semantic mutations still use the
  complete Governance lifecycle.
- [ ] Identity, CAS, duplicate, route and rendering failures remain hard
  blockers.
- [ ] Reviewer accepts this family before Slice 2 begins.
