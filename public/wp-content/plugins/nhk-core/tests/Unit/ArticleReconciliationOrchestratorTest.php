<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\{ArticleBatchReconciliation, ArticleReconciliationOrchestrator, ArticleRemediationPlanner};
use NHK\Core\Application\Capture\CaptureSubjectBindingRecovery;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class ArticleReconciliationOrchestratorTest extends TestCase
{
    public function test_repair_loop_reads_back_then_publishes_and_verifies(): void
    {
        $repairs = 0;
        $reviews = 0;
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => ['post_id' => $input['post_id'], 'slug' => '', 'subject_packet' => ['id' => 'subject']],
            static fn (array $state): array => ['intent' => 'IMAGE_ARTICLE'],
            static fn (array $state): array => $state['subject_packet'],
            static function (array $state) use (&$repairs): array { return $repairs++ === 0 ? ['diagnostics' => ['PUBLIC_ROUTE_NOT_READY']] : ['diagnostics' => []]; },
            static function (array $state, array $actions): array { return ['slug' => 'article-slug', 'permalink' => '/article-slug/']; },
            static function (array $state) use (&$reviews): array { $reviews++; return ['outcome' => 'PASS']; },
            static fn (array $state): array => ['status' => 'published'],
            static fn (array $state): array => ['status' => 'verified'],
        );

        $result = $orchestrator->reconcile(['post_id' => 42, 'publish_requested' => true]);

        self::assertSame('PASS', $result['status']);
        self::assertSame(1, $reviews);
        self::assertSame('ALLOCATE_SLUG', $result['actions'][0]['action']);
        self::assertSame('published', $result['published']['status']);
        self::assertSame('verified', $result['verification']['status']);
    }

    public function test_repeated_blocker_is_system_blocked_and_loop_is_bounded(): void
    {
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => [], static fn (array $state): array => [], static fn (array $state): array => [],
            static fn (array $state): array => ['diagnostics' => ['PUBLIC_ROUTE_NOT_READY']],
            static fn (array $state, array $actions): array => [], static fn (array $state): array => ['outcome' => 'PASS'],
            static fn (array $state): array => [], static fn (array $state): array => [],
        );

        $result = $orchestrator->reconcile(['post_id' => 7]);

        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertContains('REPEATED_REMEDIATION_BLOCKER', $result['review']['blockers']);
        self::assertLessThanOrEqual(ArticleReconciliationOrchestrator::MAX_PASSES, count($result['passes']));
    }

    public function test_batch_delegates_and_isolates_classification(): void
    {
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => $input,
            static fn (array $state): array => [], static fn (array $state): array => [],
            static fn (array $state): array => $state['post_id'] === 1 ? ['diagnostics' => []] : ['diagnostics' => ['SUBJECT_UNRESOLVED']],
            static fn (array $state, array $actions): array => [], static fn (array $state): array => ['outcome' => $state['post_id'] === 1 ? 'PASS' : 'OWNER_REVIEW_REQUIRED'],
            static fn (array $state): array => [], static fn (array $state): array => ['status' => 'verified'],
        );
        $result = (new ArticleBatchReconciliation($orchestrator))->classify([['post_id' => 1], ['post_id' => 2]], 10);
        self::assertSame(2, $result['processed']);
        self::assertSame(1, $result['counts']['READY']);
        self::assertSame(1, $result['counts']['OWNER_REVIEW']);
    }

    public function test_planner_exposes_owner_action_and_dependencies(): void
    {
        $action = (new ArticleRemediationPlanner())->plan(['desired_media' => ['media_id' => 'current']], ['MEDIAUSAGE_INCOMPLETE'])[0]->toArray();
        self::assertSame('media', $action['owner']);
        self::assertSame('RECONCILE_INLINE_MEDIA', $action['action']);
        self::assertTrue($action['auto_repair_safe']);
        self::assertContains('capture-child-admission', $action['dependencies']);
    }

    public function test_current_exact_subject_supersedes_stale_packet_once_and_preserves_owner_boundary(): void
    {
        $repairs = 0;
        $old = ['status' => 'resolved', 'canonical_subject_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'entity_type' => 'music', 'stable_key' => 'music:old', 'revision' => 1];
        $current = ['status' => 'resolved', 'canonical_subject_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'entity_type' => 'model', 'stable_key' => 'model:current', 'revision' => 2];
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => ['post_id' => 91, 'subject_resolution_packet' => $old],
            static fn (array $state): array => ['intent' => 'TEXT_ARTICLE'],
            static fn (array $state): array => $current,
            static fn (array $state): array => ['diagnostics' => []],
            static function (array $state, array $actions) use (&$repairs, $current): array {
                $repairs++;
                self::assertSame('SUPERSEDE_SUBJECT_PACKET', $actions[0]->action);
                return ['subject_resolution_packet' => $current, 'subject_packet_supersession' => null];
            },
            static fn (array $state): array => ['outcome' => 'PASS'],
            static fn (array $state): array => [],
            static fn (array $state): array => ['status' => 'verified'],
        );

        $result = $orchestrator->reconcile(['post_id' => 91]);

        self::assertSame('PASS', $result['status']);
        self::assertSame(1, $repairs);
        self::assertSame('SUBJECT_PACKET_SUPERSESSION', $result['actions'][0]['code']);
    }

    public function test_about_relation_mismatch_is_a_governed_capture_child_action(): void
    {
        $action = (new ArticleRemediationPlanner())->plan([], ['ARTICLE_ABOUT_RELATION_MISMATCH'])[0]->toArray();
        self::assertSame('graph', $action['owner']);
        self::assertSame('CONVERGE_PRIMARY_ABOUT', $action['action']);
        self::assertTrue($action['auto_repair_safe']);
        self::assertContains('capture-child-admission', $action['dependencies']);
        self::assertContains('governed-proposal', $action['dependencies']);
    }

    public function test_capture_binding_unavailable_routes_to_bounded_subject_repair(): void
    {
        $action = (new ArticleRemediationPlanner())->plan(['subject_packet' => ['status' => 'resolved']], ['CAPTURE_SUBJECT_BINDING_UNAVAILABLE'])[0]->toArray();

        self::assertSame('graph', $action['owner']);
        self::assertSame('RESOLVE_PRIMARY_SUBJECT', $action['action']);
        self::assertTrue($action['auto_repair_safe']);
    }

    public function test_mixed_capture_subject_binding_recovery_clears_reconcile_and_publication_context_blockers(): void
    {
        $repository = new class implements CaptureRepository {
            /** @var array<string,CaptureRecord> */
            public array $records = [];
            public function findByIdempotencyKey(string $key): ?CaptureRecord { foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record; return null; }
            public function findById(string $captureId): ?CaptureRecord { return $this->records[$captureId] ?? null; }
            public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
        };
        $capture = new CaptureRecord('01a10bdb-918d-7c6d-a94e-0dd4baa5f391', 'mixed-odo', hash('sha256', 'mixed-odo'), 'AUTHORITY_PLANNED', 'PLANNED', 753, 'article-token', [], ['purpose' => 'MIXED', 'subject_hints' => ['d2af7739-3d1b-4666-ad0a-aeda0758f4d8']]);
        $repository->create($capture);
        $resolution = ['status' => 'resolved', 'primary' => ['id' => 'd2af7739-3d1b-4666-ad0a-aeda0758f4d8', 'type' => 'brand', 'stable_key' => 'nhk:brand:odo', 'name' => 'Odo', 'revision' => 1, 'match' => 'uuid_exact'], 'primary_source' => 'canonical_uuid'];
        $recovery = new CaptureSubjectBindingRecovery($repository);
        $reviewCalls = 0;
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => ['post_id' => 753, 'capture' => $repository->findById($capture->captureId), 'subject_resolution_packet' => ['status' => 'unresolved']],
            static fn (array $state): array => ['intent' => 'TEXT_ARTICLE'],
            static function (array $state) use ($repository, $capture, $recovery, $resolution): array {
                $current = $repository->findById($capture->captureId);
                $packet = $recovery->resolve($current, [], [], static fn (array $hints): array => $resolution);
                return $packet?->toArray() ?? [];
            },
            static function (array $state) use ($repository, $capture): array {
                $current = $repository->findById($capture->captureId);
                return $current?->context['subject_resolution_packet'] ?? null
                    ? ['diagnostics' => [], 'evidence' => ['capture_subject_binding_verified' => true]]
                    : ['diagnostics' => ['SUBJECT_NOT_PERSISTED']];
            },
            static function (array $state, array $actions) use ($repository, $recovery, $capture): array {
                self::assertSame('RESOLVE_PRIMARY_SUBJECT', $actions[0]->action);
                $current = $repository->findById($capture->captureId);
                $repaired = $recovery->persist($current, 753, $state['subject_packet']);
                return ['capture' => $repaired, 'subject_resolution_packet' => $state['subject_packet']];
            },
            static function (array $state) use (&$reviewCalls): array {
                $reviewCalls++;
                self::assertNotContains('PUBLICATION_CONTEXT_UNAVAILABLE', $state['inspection']['diagnostics'] ?? []);
                return ['outcome' => 'PASS'];
            },
            static fn (array $state): array => ['status' => 'not_requested'],
            static fn (array $state): array => ['status' => 'verified'],
        );

        $result = $orchestrator->reconcile(['post_id' => 753]);

        self::assertSame('PASS', $result['status']);
        self::assertSame(1, $reviewCalls);
        self::assertSame('d2af7739-3d1b-4666-ad0a-aeda0758f4d8', $repository->findById($capture->captureId)?->context['subject_resolution_packet']['canonical_subject_id']);
        self::assertNotContains('CAPTURE_SUBJECT_BINDING_UNAVAILABLE', $result['review']['blockers'] ?? []);
    }
}
