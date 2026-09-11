<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\{AuthorityIntentPlanner, AuthorityService};
use NHK\Core\Application\Capture\{AuthorityCaptureService, EditorialCaptureCoordinator};
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, CanonicalApplyReadBackVerifier, ControlledApplyService, GovernedAuthorityPlanExecutor, GovernanceService};
use NHK\Core\Application\Semantic\{ArticleComposer, CanonicalAuthoritySubjectResolver, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, GraphEdge};
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, InMemoryAuditSink};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class CoreCreationE2ETest extends TestCase
{
    public function test_mcp_brand_plan_apply_readback_and_replay_reuse_the_same_canonical_identity(): void
    {
        $runtime = $this->runtime();
        $plan = $this->dispatch($runtime, 'Tạo thương hiệu Test Brand.', 'brand-plan', 'AUTHORITY', ['mode' => 'PLAN']);

        self::assertSame('AUTHORITY_PLANNED', $plan['stage']);
        self::assertCount(1, $plan['context']['authority_plan']['create_authorities']);
        $candidate = $plan['context']['authority_plan']['create_authorities'][0];

        $applied = $this->dispatch($runtime, '', 'brand-apply', 'AUTHORITY', [
            'mode' => 'APPLY_APPROVED_PLAN',
            'approved_plan_fingerprint' => $plan['context']['plan_fingerprint'],
            'approved_candidate_ids' => [$candidate['candidate_id']],
        ], $plan['capture_id']);
        $readBack = $applied['context']['authority_result']['result']['apply_results'][0]['canonical_readback'];

        self::assertSame('AUTHORITY_APPLIED', $applied['stage']);
        self::assertSame('brand', $readBack['entity_type']);
        self::assertSame('Test Brand', $readBack['snapshot']['canonicalName']);
        self::assertTrue($readBack['active']);
        self::assertSame($plan['capture_id'], $applied['capture_id']);
        self::assertSame(1, count($runtime['authority']->list('brand')));

        $replay = $this->dispatch($runtime, '', 'brand-apply-replay', 'AUTHORITY', [
            'mode' => 'APPLY_APPROVED_PLAN',
            'approved_plan_fingerprint' => $plan['context']['plan_fingerprint'],
            'approved_candidate_ids' => [$candidate['candidate_id']],
        ], $plan['capture_id']);
        self::assertSame($applied['capture_id'], $replay['capture_id']);
        self::assertSame(1, count($runtime['authority']->list('brand')));
    }

    public function test_mcp_classification_plan_apply_readback_and_replay_reuse_clock_type(): void
    {
        $runtime = $this->runtime();
        $plan = $this->dispatch($runtime, 'Tạo loại Test Clock Type.', 'clock-type-plan', 'AUTHORITY', ['mode' => 'PLAN']);
        $candidate = $plan['context']['authority_plan']['create_authorities'][0];

        self::assertSame('classification', $candidate['entity_type']);
        self::assertSame('clock-type', $candidate['family']);

        $applied = $this->dispatch($runtime, '', 'clock-type-apply', 'AUTHORITY', [
            'mode' => 'APPLY_APPROVED_PLAN',
            'approved_plan_fingerprint' => $plan['context']['plan_fingerprint'],
            'approved_candidate_ids' => [$candidate['candidate_id']],
        ], $plan['capture_id']);
        $readBack = $applied['context']['authority_result']['result']['apply_results'][0]['canonical_readback'];

        self::assertSame('classification', $readBack['entity_type']);
        self::assertSame('clock-type', $readBack['snapshot']['payload']['family']);
        self::assertSame(1, count($runtime['authority']->list('classification')));

        $reuse = $runtime['planner']->plan(['text' => 'Tạo loại Test Clock Type.', 'authority_intent' => ['mode' => 'PLAN']], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);
        self::assertCount(1, $reuse['reuse']);
        self::assertSame([], $reuse['create_authorities']);
    }

    public function test_mcp_graph_relation_reuses_canonical_endpoints_and_reads_back_subtype_edge(): void
    {
        $runtime = $this->runtime();
        $table = $runtime['authority']->create('classification', 'nhk:classification:clock-type.table-clock', 'Đồng hồ để bàn', ['family' => 'clock-type']);
        $mantel = $runtime['authority']->create('classification', 'nhk:classification:clock-type.mantel-clock', 'Mantel Clock', ['family' => 'clock-type']);
        $plan = $this->dispatch($runtime, 'Mantel Clock nằm dưới Đồng hồ để bàn.', 'relation-plan', 'AUTHORITY', ['mode' => 'PLAN']);
        $relation = $plan['context']['authority_plan']['create_relations'][0];

        self::assertSame('subtype_of', $relation['predicate']);
        self::assertSame($mantel->canonicalId, $relation['source_uuid']);
        self::assertSame($table->canonicalId, $relation['target_uuid']);

        $applied = $this->dispatch($runtime, '', 'relation-apply', 'AUTHORITY', [
            'mode' => 'APPLY_APPROVED_PLAN',
            'approved_plan_fingerprint' => $plan['context']['plan_fingerprint'],
            'approved_candidate_ids' => [$relation['candidate_id']],
        ], $plan['capture_id']);
        $readBack = $applied['context']['authority_result']['result']['apply_results'][0]['canonical_readback'];

        self::assertSame('relation', $readBack['entity_type']);
        self::assertTrue($readBack['active']);
        self::assertSame('subtype_of', $readBack['snapshot']['predicate']);
        self::assertSame($mantel->canonicalId, $readBack['snapshot']['source']->reference->endpoint_key);
        self::assertSame($table->canonicalId, $readBack['snapshot']['target']->reference->endpoint_key);
    }

    public function test_mcp_graph_relation_binds_endpoints_created_in_the_same_approved_plan(): void
    {
        $runtime = $this->runtime();
        $plan = $this->dispatch($runtime, 'Tạo loại Mantel Clock nằm dưới Đồng hồ để bàn.', 'relation-create-plan', 'AUTHORITY', ['mode' => 'PLAN']);
        $authorities = $plan['context']['authority_plan']['create_authorities'];
        $relation = $plan['context']['authority_plan']['create_relations'][0];
        $approvedIds = array_merge(array_column($authorities, 'candidate_id'), [$relation['candidate_id']]);

        $applied = $this->dispatch($runtime, '', 'relation-create-apply', 'AUTHORITY', [
            'mode' => 'APPLY_APPROVED_PLAN',
            'approved_plan_fingerprint' => $plan['context']['plan_fingerprint'],
            'approved_candidate_ids' => $approvedIds,
        ], $plan['capture_id']);

        self::assertSame('APPLIED', $applied['context']['authority_result']['result']['status']);
        $results = $applied['context']['authority_result']['result']['apply_results'];
        self::assertCount(3, $results);
        $relationResult = $results[2]['canonical_readback'];
        self::assertSame('relation', $relationResult['entity_type']);
        self::assertSame('subtype_of', $relationResult['snapshot']['predicate']);
        self::assertNotNull($relationResult['snapshot']['source']->reference->endpoint_key);
        self::assertNotNull($relationResult['snapshot']['target']->reference->endpoint_key);
    }

    public function test_editorial_article_uses_one_capture_and_one_post(): void
    {
        $runtime = $this->runtime();
        $brand = $runtime['authority']->create('brand', 'nhk:brand:article-brand', 'Article Brand');
        $posts = 0;
        $article = new EditorialCaptureCoordinator(
            $runtime['captures'],
            static fn (array $input): array => [],
            static function (array $input) use (&$posts): array { ++$posts; return ['post_id' => 700 + $posts, 'state_token' => 'article-token-' . $posts]; },
            new TextInputInterpreter(),
            new SubjectResolutionService(new CanonicalAuthoritySubjectResolver($runtime['authorityRepo'], $runtime['types'])),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'verified'],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'verified'],
            static fn (array $context): array => ['eligible' => true, 'state_token' => 'article-token'],
            static fn (array $context): array => ['status' => 'verified'],
            static fn (array $input): array => ['ok' => true, 'state_token' => 'article-token-updated'],
        );
        $transport = new McpTransport($this->readHandler(), $runtime['mcpGovernance'], static fn (string $capability): bool => true, documentation: $runtime['documentation'], capture: $article);

        $first = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'article-brand', 'purpose' => 'EDITORIAL', 'text' => 'Bài giới thiệu Article Brand.', 'subject_hints' => ['Article Brand'], 'documentation_checkpoint' => $this->checkpoint($runtime),
        ]]]);
        $result = $first['body']['result']['structuredContent'];
        $replay = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'article-brand', 'purpose' => 'EDITORIAL', 'text' => 'Bài giới thiệu Article Brand.', 'subject_hints' => ['Article Brand'], 'documentation_checkpoint' => $this->checkpoint($runtime),
        ]]]);

        self::assertSame(200, $first['status']);
        self::assertSame('READY_FOR_PUBLICATION', $result['stage']);
        self::assertSame($result['capture_id'], $replay['body']['result']['structuredContent']['capture_id']);
        self::assertSame(1, $posts);
        self::assertSame($brand->canonicalId, $result['diagnostics']['subjects']['primary']['id']);
    }

    public function test_mixed_brand_article_keeps_one_capture_and_one_post_after_authority_readback(): void
    {
        $runtime = $this->runtime();
        $posts = 0;
        $reconciled = [];
        $runtime['authorityCapture'] = new AuthorityCaptureService(
            $runtime['captures'],
            $runtime['authorityPlannerCallback'],
            static function (array $input, CaptureRecord $capture) use (&$posts): array { ++$posts; return ['post_id' => 800 + $posts, 'state_token' => 'mixed-token']; },
            $runtime['authorityApplyCallback'],
            static function (CaptureRecord $capture, array $result) use (&$reconciled): array {
                $reconciled[] = $capture->captureId;
                return ['status' => 'RECONCILED', 'subject_resolution_rerun' => true, 'capture_id' => $capture->captureId];
            },
        );
        $transport = new McpTransport($this->readHandler(), $runtime['mcpGovernance'], static fn (string $capability): bool => true, documentation: $runtime['documentation'], authorityCapture: $runtime['authorityCapture']);
        $runtime['transport'] = $transport;

        $first = $this->dispatch($runtime, 'Tạo thương hiệu Test Mixed và một bài giới thiệu.', 'mixed-plan', 'MIXED', ['mode' => 'PLAN']);
        $candidate = $first['context']['authority_plan']['create_authorities'][0];
        $done = $this->dispatch($runtime, '', 'mixed-apply', 'MIXED', [
            'mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => $first['context']['plan_fingerprint'], 'approved_candidate_ids' => [$candidate['candidate_id']],
        ], $first['capture_id']);

        self::assertSame('MIXED', $first['context']['purpose']);
        self::assertSame($first['capture_id'], $done['capture_id']);
        self::assertSame('RECONCILED', $done['context']['mixed_editorial_reconciliation']['status']);
        self::assertSame([$first['capture_id']], $reconciled);
        self::assertSame(1, $posts);
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $endpoints = new EndpointTypeRegistry();
        foreach ($types->all() as $definition) $endpoints->register($definition->type, new AuthorityEndpointResolver($types, $authorityRepo));
        $graphRepo = new InMemoryGraphRepository();
        $graph = new \NHK\Core\Application\Graph\GraphService($graphRepo, $endpoints, new \NHK\Core\Domain\Graph\PredicateRegistry(), new InMemoryAuditSink(), new \NHK\Core\Application\Graph\ClassificationHierarchyPolicy($authorityRepo, $graphRepo), new \NHK\Core\Application\Graph\ClassifiedAsPolicy());
        $proposalRepo = new InMemoryProposalRepository();
        $governance = new GovernanceService($proposalRepo);
        $readBack = new CanonicalApplyReadBackVerifier(static function (string $type, string $id) use ($authorityRepo, $graphRepo): ?array {
            $record = $type === 'relation' ? $graphRepo->findByUuid($id) : $authorityRepo->findByCanonicalId($id);
            if ($record instanceof AuthorityEntity) return ['entity_type' => $record->entityType, 'canonical_id' => $record->canonicalId, 'active' => $record->active(), 'revision' => $record->revision, 'snapshot' => get_object_vars($record)];
            if ($record instanceof GraphEdge) return ['entity_type' => 'relation', 'canonical_id' => $record->edge_uuid, 'active' => $record->isActive(), 'revision' => $record->revision, 'snapshot' => get_object_vars($record)];
            return null;
        });
        $controlled = new ControlledApplyService($proposalRepo, new CoreE2EApplyAttemptRepository(), new CoreE2ETransactionManager(), new AuthorityProposalExecutor($authority, $graph), null, null, null, null, $readBack);
        $mcpGovernance = new McpGovernanceHandler($governance, null, $controlled, null, $endpoints);
        $planner = new AuthorityIntentPlanner($authorityRepo, $types);
        $authorityPlannerCallback = static fn (array $input, CaptureRecord $capture): array => $planner->plan($input, ['capture_id' => $capture->captureId, 'capture_revision' => (int) ($capture->context['planning_revision'] ?? $capture->revision)]);
        $planExecutor = new GovernedAuthorityPlanExecutor($mcpGovernance);
        $authorityApplyCallback = static fn (CaptureRecord $capture, array $plan, array $ids): array => $planExecutor->execute($plan, (string) $plan['plan_fingerprint'], (string) $plan['plan_fingerprint'], $ids, ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION, 'owner');
        $captures = new CoreE2ECaptureRepository();
        $authorityCapture = new AuthorityCaptureService($captures, $authorityPlannerCallback, null, $authorityApplyCallback);
        $documentation = new \NHK\Core\Application\Mcp\McpDocumentationRegistry();
        $transport = new McpTransport($this->readHandler(), $mcpGovernance, static fn (string $capability): bool => true, documentation: $documentation, authorityCapture: $authorityCapture);
        return ['types' => $types, 'authorityRepo' => $authorityRepo, 'authority' => $authority, 'captures' => $captures, 'planner' => $planner, 'authorityCapture' => $authorityCapture, 'authorityPlannerCallback' => $authorityPlannerCallback, 'authorityApplyCallback' => $authorityApplyCallback, 'mcpGovernance' => $mcpGovernance, 'documentation' => $documentation, 'transport' => $transport];
    }

    /** @param array<string,mixed> $runtime @return array<string,mixed> */
    private function dispatch(array $runtime, string $text, string $key, string $purpose, array $intent, ?string $captureId = null): array
    {
        $checkpoint = $runtime['documentation']->bootstrap();
        $arguments = ['idempotency_key' => $key, 'purpose' => $purpose, 'text' => $text, 'authority_intent' => $intent, 'documentation_checkpoint' => ['manifest_hash' => $checkpoint['manifest_hash'], 'documentation_version' => $checkpoint['documentation_version']]];
        if ($captureId !== null) $arguments['capture_id'] = $captureId;
        $response = $runtime['transport']->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => $arguments]]);
        if (!isset($response['body']['result']['structuredContent'])) throw new \RuntimeException(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response['body']['result']['structuredContent'];
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class), $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class), $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class), $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class), null, $this->createMock(\NHK\Core\Contracts\Knowledge\SourceRepository::class));
    }

    /** @return array{manifest_hash:string,documentation_version:string} */
    private function checkpoint(array $runtime): array
    {
        $bootstrap = $runtime['documentation']->bootstrap();
        return ['manifest_hash' => $bootstrap['manifest_hash'], 'documentation_version' => $bootstrap['documentation_version']];
    }
}

final class CoreE2ECaptureRepository implements \NHK\Core\Contracts\Capture\CaptureRepository
{
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}

final class CoreE2ETransactionManager implements \NHK\Core\Contracts\Shared\TransactionManager
{
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function transactional(callable $callback): mixed { return $callback(); }
    public function run(callable $callback): mixed { return $callback(); }
}

final class CoreE2EApplyAttemptRepository implements \NHK\Core\Contracts\Governance\ApplyAttemptRepository
{
    private array $items = [];
    public function nextAttemptNumberLocked(string $proposalId): int { return count($this->findByProposal($proposalId)) + 1; }
    public function createRunning(\NHK\Core\Domain\Governance\ApplyAttempt $attempt): \NHK\Core\Domain\Governance\ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function markSucceeded(string $attemptId, ?string $resultEntityUuid): \NHK\Core\Domain\Governance\ApplyAttempt { $item = $this->items[$attemptId]; return $this->items[$attemptId] = new \NHK\Core\Domain\Governance\ApplyAttempt($item->id, $item->proposalId, $item->number, 'succeeded', $resultEntityUuid, startedAt: $item->startedAt, finishedAt: 'now'); }
    public function persistFailed(\NHK\Core\Domain\Governance\ApplyAttempt $attempt): \NHK\Core\Domain\Governance\ApplyAttempt { return $this->items[$attempt->id] = $attempt; }
    public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, static fn (\NHK\Core\Domain\Governance\ApplyAttempt $item): bool => $item->proposalId === $proposalId)); }
    public function findSuccessful(string $proposalId): ?\NHK\Core\Domain\Governance\ApplyAttempt { foreach ($this->findByProposal($proposalId) as $item) if ($item->state === 'succeeded') return $item; return null; }
}
