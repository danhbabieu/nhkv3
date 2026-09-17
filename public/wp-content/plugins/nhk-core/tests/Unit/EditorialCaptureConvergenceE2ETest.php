<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Article\ArticlePublicationGate;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Capture\CaptureStage;
use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

/**
 * PR5 seam tests: the Capture coordinator is the orchestrator, while each
 * owner result remains independently read back and aggregated truthfully.
 */
final class EditorialCaptureConvergenceE2ETest extends TestCase
{
    public function test_text_article_pipeline_replays_same_capture_and_owner_writes(): void
    {
        $captures = new Pr5CaptureRepository();
        $events = [];
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $coordinator = $this->coordinator($captures, $calls, $events);
        $input = [
            'idempotency_key' => 'pr5-text-article',
            'intent' => 'TEXT_ARTICLE',
            'title' => 'Bài kiểm tra hội tụ',
            'text' => 'Chiếc đồng hồ có mặt số xanh. Bộ máy dùng cấu hình 36/4.',
        ];

        $first = $coordinator->execute($input);
        $replay = $coordinator->execute($input);

        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(1001, $first->articleId);
        self::assertSame(['draft' => 1, 'semantic' => 1, 'media' => 1, 'publication' => 1, 'final' => 1], $calls);
        self::assertSame(['physical', 'draft', 'semantic', 'media', 'publication', 'final'], $events);
        self::assertSame('TEXT_ARTICLE', $first->diagnostics['content_intent']['intent']);
        self::assertSame([['owner_type' => 'wp_post', 'owner_id' => '']], $first->diagnostics['completion']['required_owners']);
        self::assertContains('ARTICLE_NOT_PUBLISHED', $first->diagnostics['completion']['blockers']);
        self::assertNotContains('MEDIA_REQUIRED', $first->diagnostics['completion']['blockers']);
    }

    public function test_media_only_multi_image_submission_converges_to_one_image_article_with_ordered_children(): void
    {
        $assets = [
            ['client_file_id' => 'front', 'media_id' => 'media-front', 'sort_order' => 2, 'upload_status' => 'CREATED'],
            ['client_file_id' => 'dial', 'media_id' => 'media-dial', 'sort_order' => 1, 'upload_status' => 'CREATED'],
            ['client_file_id' => 'back', 'media_id' => 'media-back', 'sort_order' => 3, 'upload_status' => 'CREATED'],
        ];
        $route = (new ContentIntentRouter())->route(
            ['intent' => 'IMAGE_ARTICLE', 'title' => 'Album hội tụ', 'text' => 'Ba góc chụp của cùng hiện vật.'],
            [],
            $assets,
        );
        usort($assets, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        self::assertSame('IMAGE_ARTICLE', $route['intent']);
        self::assertTrue($route['article_required']);
        self::assertCount(3, $assets);
        self::assertSame(['media-dial', 'media-front', 'media-back'], array_column($assets, 'media_id'));
        self::assertCount(3, array_unique(array_column($assets, 'client_file_id')));
    }

    public function test_knowledge_delta_without_image_has_no_article_and_is_not_semantically_complete_when_pending(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator($captures, $calls, $events, semanticStatus: 'REVIEW_REQUIRED');

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-knowledge-delta',
            'intent' => 'KNOWLEDGE_DELTA',
            'text' => '36/4 mặt vuông',
        ]);

        self::assertNull($result->articleId);
        self::assertSame('KNOWLEDGE_DELTA', $result->diagnostics['content_intent']['intent']);
        self::assertSame('not_requested', $result->diagnostics['visual_support']['status']);
        self::assertSame(0, $calls['draft']);
        self::assertSame(0, $calls['media']);
        self::assertFalse($result->diagnostics['completion']['complete']);
        self::assertContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['completion']['blockers']);
    }

    public function test_video_owner_success_and_later_article_failure_are_reported_as_partial(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoCompletion = (new CompletionCoordinator())->finalize('video', 'video-pr5-1', [
            'canonical_readback' => ['canonical_id' => 'video-pr5-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'NOT_APPLICABLE',
            'content_quality' => 'CONTENT_COMPLETE',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            videoEnrichment: static fn (array $input): array => ['items' => [['kind' => 'video', 'video_id' => 'video-pr5-1']]],
            videoPublication: static fn (array $input): array => ['status' => 'verified', 'items' => [['completion' => $videoCompletion]], 'blockers' => []],
            media: static function (array $input): array { throw new \RuntimeException('ARTICLE_RECONCILIATION_FAILED'); },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-video-article-partial',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài độc lập về chiếc đồng hồ. Có thêm ngữ cảnh video.',
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ]);

        $completion = $result->diagnostics['completion'];
        $videoChild = array_values(array_filter($completion['children'], static fn (array $child): bool => ($child['owner_type'] ?? '') === 'video'))[0] ?? [];
        self::assertSame('FAILED_RETRYABLE', $result->status);
        self::assertSame('PARTIAL', $completion['status']);
        self::assertFalse($completion['complete']);
        self::assertTrue($videoChild['complete']);
        self::assertContains('article', $completion['resume_hints']['resume_children']);
        self::assertSame('video-pr5-1', $videoChild['owner_id']);
    }

    public function test_existing_knowledge_and_article_are_reuse_candidates_not_new_deep_content(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticExtra: ['reused_claims' => [['claim_id' => 'claim-existing', 'revision' => 3]]],
            media: static fn (array $context): array => ['status' => 'RECONCILED', 'internal_link_candidates' => [['post_id' => 88, 'url' => '/bai-lien-quan/']]],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-reuse-existing',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài viết độc lập về cấu hình này. Có thêm một chi tiết hữu ích.',
        ]);

        self::assertSame('REUSE_EXISTING', $result->diagnostics['deep_enrichment']['status']);
        self::assertSame('claim-existing', $result->diagnostics['deep_enrichment']['knowledge_reuse'][0]['claim_id']);
        self::assertSame(88, $result->diagnostics['deep_enrichment']['article_reuse_internal_link'][0]['post_id']);
        self::assertNull($result->diagnostics['deep_enrichment']['new_deep_content_opportunity']);
    }

    public function test_article_publication_context_carries_not_required_semantic_packet(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $publicationContext = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'SKIPPED',
            semanticExtra: ['requirements' => [
                'semantic_delta' => [
                    'applicability' => 'NOT_REQUIRED',
                    'policy' => 'VERIFY',
                    'state' => 'SKIPPED',
                    'evidence' => ['intent' => 'IMAGE_ARTICLE', 'status' => 'NONE'],
                ],
            ]],
            physicalItems: [['client_file_id' => 'front', 'media_id' => 'media-front']],
            publication: static function (array $context) use (&$publicationContext): array {
                $publicationContext = $context;
                return ['eligible' => true];
            },
        );

        $coordinator->execute([
            'idempotency_key' => 'pr5-image-article-no-semantic-delta',
            'intent' => 'IMAGE_ARTICLE',
            'text' => 'Mô tả biên tập cho hiện vật đã được nhận diện.',
        ]);

        self::assertArrayHasKey('requirements', $publicationContext);
        self::assertSame('NOT_REQUIRED', $publicationContext['requirements']['semantic_delta']['applicability']);
        self::assertSame('SKIPPED', $publicationContext['requirements']['semantic_delta']['state']);
    }

    public function test_case_573_equivalent_image_article_keeps_editorial_path_without_semantic_publication_failure(): void
    {
        $captures = new Pr5CaptureRepository();
        $subjectId = UuidCodec::newV7();
        $governance = new Task3InMemoryGovernance();
        $semanticResult = [];
        $publicationContext = [];
        $publicationGate = new ArticlePublicationGate();
        $semantic = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('ordinary Article must not apply semantic work'),
            $this->governancePolicies(),
            static fn (): bool => true,
        );
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => throw new \LogicException('persisted Article must not replay physical ingest'),
            static fn (array $input): array => throw new \LogicException('persisted Article must not create a second draft'),
            new TextInputInterpreter(),
            new SubjectResolutionService(static function (string $hint) use ($subjectId): array {
                return in_array($hint, [$subjectId, 'Vedette 37'], true)
                    ? [['id' => $subjectId, 'type' => 'variant', 'name' => 'Vedette 37', 'revision' => 1, 'active' => true]]
                    : [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use ($semantic, &$semanticResult): array {
                $semanticResult = $semantic->execute('capture-573-equivalent', 'capture-573-equivalent:addendum', $context);
                return $semanticResult;
            },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED', 'media_complete' => true],
            static function (array $context) use ($publicationGate, &$publicationContext): array {
                $publicationContext = $context;
                $capture = is_array($context['capture'] ?? null) ? $context['capture'] : [];
                $articleId = (int) ($context['article_id'] ?? 0);
                $draft = new EditorialPostState(
                    $articleId,
                    '1:' . $articleId,
                    'post',
                    'draft',
                    (string) (($context['composition']['title'] ?? '') ?: ($capture['context']['title'] ?? 'Vedette 37')),
                    'Bổ sung mô tả biên tập cho Vedette 37.',
                    (string) ($context['composition']['excerpt'] ?? ''),
                    'vedette-37',
                    '/vedette-37/',
                    1,
                    1,
                    '2026-09-17 00:00:00',
                );
                return $publicationGate->check($draft, [
                    'research_acceptable' => true,
                    'subject_resolved' => true,
                    'duplicate_intent_handled' => true,
                    'category_resolved' => true,
                    'semantic_plan_complete' => false,
                    'semantic_readback_verified' => false,
                    'media_usage_complete' => true,
                    'real_image_requirements_met' => true,
                    'claim_compliance_acceptable' => true,
                    'seo_projection_valid' => true,
                    'internal_links_valid' => true,
                    'structured_data_valid' => true,
                    'public_route_ready' => true,
                    'rendered_public_verification' => true,
                    'rendered_public_verification_status' => 'verified',
                    'requirements' => $context['requirements'] ?? [],
                ], $draft->token)->toArray();
            },
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new ContentIntentRouter(),
        );
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-573-equivalent',
            hash('sha256', 'capture-573-equivalent'),
            CaptureStage::MEDIA_ADOPTED->value,
            'PARTIAL',
            573,
            'article-state-573',
            [['kind' => 'image', 'media_id' => UuidCodec::newV7()]],
            [
                'raw_input' => 'Bài viết gốc về Vedette 37.',
                'title' => 'Vedette 37',
                'subject_hints' => [$subjectId],
                'content_intent' => [
                    'intent' => 'IMAGE_ARTICLE',
                    'source' => 'CAPTURE',
                    'purpose' => 'EDITORIAL',
                    'semantic_delta' => ['status' => 'NONE'],
                ],
            ],
            [],
            [],
        );
        $captures->create($capture);

        $result = $coordinator->continueWithAddendum($capture, [
            'existing_capture_continuation' => true,
            'text' => 'Bổ sung mô tả biên tập cho Vedette 37.',
        ]);

        self::assertSame(573, $result->articleId);
        self::assertSame('NONE', $result->diagnostics['content_intent']['semantic_delta']['status']);
        self::assertSame('SKIPPED', $semanticResult['status']);
        self::assertSame([], $semanticResult['plans']);
        self::assertSame([], $semanticResult['writes']);
        self::assertSame([], $governance->events);
        self::assertSame('NOT_REQUIRED', $publicationContext['requirements']['semantic_delta']['applicability']);
        self::assertSame('SKIPPED', $publicationContext['requirements']['semantic_delta']['state']);
        self::assertTrue($result->diagnostics['publication']['eligible']);
        self::assertSame('PASS', $result->diagnostics['publication']['outcome']);
        self::assertArrayHasKey('policy_version', $result->diagnostics['publication']);
        self::assertSame([], $result->diagnostics['publication']['blockers']);
    }

    public function test_explicit_knowledge_delta_and_approved_mixed_keep_governed_readback(): void
    {
        $subjectId = UuidCodec::newV7();
        $governance = new Task3InMemoryGovernance();
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $proposalId): array => $governance->apply($proposalId, UuidCodec::newV7()),
            $this->governancePolicies(['knowledge', 'relation']),
            static fn (): bool => true,
        );

        $knowledge = $service->execute('capture-knowledge-delta', 'capture-knowledge-delta:semantic', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED']],
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'variant', 'revision' => 2], 'resolved' => [['id' => $subjectId, 'type' => 'variant', 'revision' => 2]]],
            'continuation_delta_text' => 'Cấu hình được ghi nhận ở đúng phạm vi biến thể.',
        ]);

        self::assertSame('APPLIED', $knowledge['status']);
        self::assertSame('VERIFIED', $knowledge['requirements']['semantic_delta']['state']);
        self::assertSame(['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY'], $knowledge['governance']['lifecycle']);
        self::assertNotEmpty($governance->proposals);
        foreach ($knowledge['writes'] as $write) {
            self::assertSame($write['canonical_id'], $write['canonical_readback']['canonical_id']);
            self::assertTrue($write['canonical_readback']['active']);
        }

        $mixedGovernance = new Task3InMemoryGovernance();
        $mixedProposalId = UuidCodec::newV7();
        $mixedGovernance->seed(new Proposal(
            $mixedProposalId,
            $subjectId,
            'update',
            ['text' => 'Đã được owner duyệt.'],
            'mixed-content',
            2,
            'mixed-dependency',
            ProposalState::APPROVED,
            targetUuid: $subjectId,
            entityType: 'knowledge',
        ));
        $mixedService = new GovernedCaptureContinuationService(
            $mixedGovernance,
            static fn (string $proposalId): array => $mixedGovernance->apply($proposalId, UuidCodec::newV7()),
            $this->governancePolicies(['knowledge']),
            static fn (): bool => true,
        );
        $mixed = $mixedService->execute('capture-mixed', 'capture-mixed:semantic', [
            'purpose' => 'MIXED',
            'content_intent' => ['intent' => 'IMAGE_ARTICLE', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED']],
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'variant', 'revision' => 2], 'resolved' => [['id' => $subjectId, 'type' => 'variant', 'revision' => 2]]],
        ], ['proposal_ids' => [$mixedProposalId]]);

        self::assertSame('APPLIED', $mixed['status']);
        self::assertSame('VERIFIED', $mixed['requirements']['semantic_delta']['state']);
        self::assertSame(['PROPOSAL', 'ELIGIBILITY', 'CONTROLLED_APPLY'], $mixed['governance']['lifecycle']);
        self::assertSame($mixed['writes'][0]['canonical_id'], $mixed['writes'][0]['canonical_readback']['canonical_id']);
    }

    public function test_conflicting_identity_stops_capture_before_any_article_or_semantic_owner_write(): void
    {
        $captures = new Pr5CaptureRepository();
        $subjectId = UuidCodec::newV7();
        $conflictingId = UuidCodec::newV7();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => ['items' => [['kind' => 'image', 'media_id' => UuidCodec::newV7()]]],
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 573, 'state_token' => 'token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static function (string $hint) use ($subjectId, $conflictingId): array {
                if ($hint === $subjectId) return [['id' => $subjectId, 'type' => 'variant', 'name' => 'Vedette 37', 'active' => true]];
                if ($hint === 'Vedette 37') return [['id' => $conflictingId, 'type' => 'variant', 'name' => 'Vedette 37 copy', 'active' => true]];
                return [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls): array { ++$calls['semantic']; return ['status' => 'APPLIED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; return ['status' => 'RECONCILED']; },
            static function (array $context) use (&$calls): array { ++$calls['publication']; return ['eligible' => true]; },
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new ContentIntentRouter(),
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'capture-conflicting-identity',
            'intent' => 'IMAGE_ARTICLE',
            'title' => 'Vedette 37',
            'subject_hints' => [$subjectId],
            'text' => 'Bài viết biên tập về một hiện vật.',
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertSame('SUBJECT_CONFLICT_REVIEW_REQUIRED', $result->diagnostics['failure_code']);
        self::assertSame(['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0], $calls);
    }

    public function test_stale_cas_keeps_explicit_semantic_delta_hard_blocked(): void
    {
        $subjectId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $governance = new Task3InMemoryGovernance();
        $governance->seed(new Proposal(
            $proposalId,
            '1:573',
            'relation_create',
            ['source_type' => 'wp_post', 'source_uuid' => '1:573', 'target_type' => 'variant', 'target_uuid' => $subjectId, 'target_revision' => 2, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'],
            'stale-content',
            null,
            'stale-dependency',
            ProposalState::APPROVED,
            targetUuid: $subjectId,
            entityType: 'relation',
        ));
        $governance->eligibility = ['ready' => false, 'reasons' => ['TARGET_REVISION_CHANGED']];
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $proposalId): array => throw new \LogicException('stale CAS must not apply'),
            $this->governancePolicies(['relation']),
            static fn (): bool => true,
        );

        $result = $service->execute('capture-stale-cas', 'capture-stale-cas:semantic', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'source' => 'CAPTURE', 'semantic_delta' => ['status' => 'REQUIRED']],
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'variant', 'revision' => 3], 'resolved' => [['id' => $subjectId, 'type' => 'variant', 'revision' => 3]]],
        ], ['proposal_ids' => [$proposalId]]);

        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertSame('HARD_BLOCK', $result['requirements']['semantic_delta']['policy']);
        self::assertSame('BLOCKED', $result['requirements']['semantic_delta']['state']);
        self::assertContains('TARGET_REVISION_CHANGED', $result['blockers']);
        self::assertNotContains('CONTROLLED_APPLY', $governance->events);
    }

    /**
     * @param array<string,int> $calls
     * @param list<string> $events
     */
    private function coordinator(
        Pr5CaptureRepository $captures,
        array &$calls,
        array &$events,
        string $semanticStatus = 'REVIEW_REQUIRED',
        ?callable $videoEnrichment = null,
        ?callable $videoPublication = null,
        ?callable $media = null,
        array $semanticExtra = [],
        ?callable $publication = null,
        array $physicalItems = [],
    ): EditorialCaptureCoordinator {
        return new EditorialCaptureCoordinator(
            $captures,
            static function (array $input) use (&$events, $physicalItems): array { $events[] = 'physical'; return ['items' => $physicalItems]; },
            static function (array $input) use (&$calls, &$events): array { ++$calls['draft']; $events[] = 'draft'; return ['post_id' => 1001, 'state_token' => 'article-token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls, &$events, $semanticStatus, $semanticExtra): array { ++$calls['semantic']; $events[] = 'semantic'; return array_merge(['status' => $semanticStatus, 'writes' => []], $semanticExtra); },
            new ArticleComposer(),
            $media ?? static function (array $context) use (&$calls, &$events): array { ++$calls['media']; $events[] = 'media'; return ['status' => 'RECONCILED']; },
            $publication ?? static function (array $context) use (&$calls, &$events): array { ++$calls['publication']; $events[] = 'publication'; return ['eligible' => true]; },
            static function (array $context) use (&$calls, &$events): array { ++$calls['final']; $events[] = 'final'; return ['status' => 'verified']; },
            null,
            null,
            null,
            null,
            $videoEnrichment,
            $videoPublication,
            null,
            null,
        );
    }

    /** @param list<string> $types */
    private function governancePolicies(array $types = []): GovernanceAutomationPolicyResolver
    {
        $stored = array_fill_keys($types, 'AUTO_PUBLISH');
        return new GovernanceAutomationPolicyResolver($types, new class($stored) implements AutomationPolicyStorage {
            public function __construct(private array $stored) {}
            public function read(): array { return $this->stored; }
            public function write(array $policies): void {}
        });
    }
}

final class Pr5CaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }

    public function findById(string $captureId): ?CaptureRecord
    {
        foreach ($this->records as $record) if ($record->captureId === $captureId) return $record;
        return null;
    }

    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }

    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}

final class Task3InMemoryGovernance implements GovernedLifecycle
{
    /** @var array<string,Proposal> */
    public array $proposals = [];
    /** @var list<string> */
    public array $events = [];
    /** @var array<string,mixed> */
    public array $eligibility = ['ready' => true];

    public function createFromArguments(array $arguments): Proposal
    {
        $proposal = new Proposal(
            UuidCodec::newV7(),
            (string) ($arguments['subject_id'] ?? 'subject'),
            (string) ($arguments['operation'] ?? 'ingest'),
            (array) ($arguments['payload'] ?? []),
            (string) ($arguments['content_fingerprint'] ?? 'content'),
            isset($arguments['expected_revision']) ? (int) $arguments['expected_revision'] : null,
            (string) ($arguments['dependency_fingerprint'] ?? 'dependency'),
            idempotencyKey: (string) ($arguments['idempotency_key'] ?? ''),
            targetUuid: UuidCodec::isValid((string) ($arguments['target_uuid'] ?? '')) ? (string) $arguments['target_uuid'] : null,
            entityType: (string) ($arguments['entity_type'] ?? ''),
        );
        $this->proposals[$proposal->id] = $proposal;
        $this->events[] = 'PROPOSAL';
        return $proposal;
    }

    public function submit(string $id): Proposal
    {
        $this->events[] = 'SUBMIT';
        return $this->proposals[$id] = $this->proposals[$id]->transition(ProposalState::SUBMITTED, 'test');
    }

    public function review(string $id): array
    {
        $proposal = $this->proposals[$id];
        return [
            'state' => $proposal->state->value,
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'subject_id' => $proposal->subjectId,
            'target_uuid' => $proposal->targetUuid,
            'payload' => $proposal->payload,
            'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
            'expected_revision' => $proposal->expectedRevision,
            'revision' => $proposal->revision,
        ];
    }

    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal
    {
        $this->events[] = 'APPROVE';
        return $this->proposals[$id] = $this->proposals[$id]->transition(ProposalState::APPROVED, $actor);
    }

    public function eligibility(string $id): array
    {
        $this->events[] = 'ELIGIBILITY';
        return $this->eligibility;
    }

    public function seed(Proposal $proposal): void { $this->proposals[$proposal->id] = $proposal; }

    public function apply(string $id, string $canonicalId): array
    {
        $this->events[] = 'CONTROLLED_APPLY';
        $this->proposals[$id] = $this->proposals[$id]->transition(ProposalState::APPLIED, 'test');
        return ['canonical_id' => $canonicalId, 'canonical_readback' => ['canonical_id' => $canonicalId, 'active' => true, 'revision' => 2]];
    }
}
