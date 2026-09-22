<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\{AuthorityIntentPlanner, AuthorityPlanFingerprint};
use NHK\Core\Application\Graph\RelationshipReadService;
use NHK\Core\Application\Capture\AuthorityCaptureService;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpDocumentationRegistry, McpGovernanceHandler, McpReadHandler, McpToolCatalog, McpTransport};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ConversationalAuthorityMcpTest extends TestCase
{
    public function test_capture_schema_exposes_typed_purpose_authority_intent_and_preserves_files(): void
    {
        $capture = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.capture.ingest'))[0];
        $schema = $capture['inputSchema'];

        self::assertSame(['EDITORIAL', 'AUTHORITY', 'MIXED'], $schema['properties']['purpose']['enum']);
        self::assertSame(['PLAN', 'APPLY_APPROVED_PLAN'], $schema['properties']['authority_intent']['properties']['mode']['enum']);
        self::assertSame('array', $schema['properties']['authority_intent']['properties']['requests']['type']);
        self::assertSame('array', $schema['properties']['authority_intent']['properties']['relation_intents']['type']);
        self::assertSame('array', $schema['properties']['authority_intent']['properties']['relations']['type']);
        self::assertSame(['source_uuid', 'predicate', 'target_uuid'], array_keys($schema['properties']['authority_intent']['properties']['relations']['items']['properties']));
        self::assertSame(['source_uuid', 'predicate', 'target_uuid'], $schema['properties']['authority_intent']['properties']['relations']['items']['required']);
        self::assertTrue($schema['properties']['authority_intent']['properties']['relations']['items']['additionalProperties'] === false);
        self::assertSame([
            'source_type',
            'source_uuid',
            'predicate',
            'target_type',
            'target_uuid',
            'provenance',
            'reason',
        ], array_keys($schema['properties']['authority_intent']['properties']['relation_intents']['items']['properties']));
        self::assertSame(['source_type', 'source_uuid', 'predicate', 'target_type', 'target_uuid'], $schema['properties']['authority_intent']['properties']['relation_intents']['items']['required']);
        self::assertTrue($schema['properties']['authority_intent']['properties']['relation_intents']['items']['additionalProperties'] === false);
        self::assertSame([
            'entity_type',
            'canonical_uuid',
            'name',
            'family',
            'payload_delta',
            'allow_create',
        ], array_keys($schema['properties']['authority_intent']['properties']['requests']['items']['properties']));
        self::assertTrue($schema['properties']['authority_intent']['properties']['requests']['items']['additionalProperties'] === false);
        self::assertSame(['idempotency_key', 'documentation_checkpoint'], $schema['required']);
        self::assertSame(['files'], $capture['connectorMeta']['openai/fileParams']);
        self::assertArrayHasKey('capture_id', $schema['properties']);
        self::assertNotNull(McpAbilityRegistration::abilityNameForTool('nhk.capture.ingest'));
        $request = ['entity_type' => 'brand', 'name' => 'Hermle', 'payload_delta' => ['country' => 'Germany']];
        self::assertSame(
            ['authority_intent' => ['mode' => 'PLAN', 'requests' => [$request]]],
            McpAbilityRegistration::canonicalTransportArguments('nhk.capture.ingest', ['authority_intent' => ['mode' => 'PLAN', 'requests' => [$request]]]),
        );
        $relation = [
            'source_type' => 'classification',
            'source_uuid' => '01a07cbc-3595-7e63-8c1b-5b308c644125',
            'predicate' => 'about',
            'target_type' => 'knowledge',
            'target_uuid' => '01a08156-c400-7739-a40f-61185cd62fcd',
            'provenance' => 'EXPLICIT_USER_RELATION',
            'reason' => 'Canonical relation reconciliation.',
        ];
        self::assertSame(
            ['authority_intent' => ['mode' => 'PLAN', 'relation_intents' => [$relation]]],
            McpAbilityRegistration::canonicalTransportArguments('nhk.capture.ingest', ['authority_intent' => ['mode' => 'PLAN', 'relation_intents' => [$relation]]]),
        );
        self::assertSame([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 1,
            'items' => ['type' => 'string', 'enum' => ['video']],
        ], $schema['properties']['resume_children']);
    }

    public function test_resume_children_requires_an_existing_capture_id_at_transport_boundary(): void
    {
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => $capability === 'nhk_ingest_articles' || $capability === 'read',
            documentation: $documentation,
        );

        $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'resume-without-capture',
            'resume_children' => ['video'],
            'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);

        self::assertSame(400, $result['status']);
        self::assertSame('CAPTURE_RESUME_REQUIRES_CAPTURE_ID', $result['body']['error']['message']);
    }

    public function test_actual_tools_call_dispatches_plan_and_same_capture_apply(): void
    {
        $captures = new TransportCaptureRepository();
        $planner = static function (array $input, CaptureRecord $capture): array {
            $plan = ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-hermle', 'action' => 'CREATE', 'entity_type' => 'brand', 'proposed_canonical_name' => 'Hermle', 'stable_key_preview' => 'nhk:brand:hermle', 'dependencies' => []]], 'update_candidates' => [], 'relation_candidates' => [], 'blockers' => []];
            $plan['plan_fingerprint'] = AuthorityPlanFingerprint::compute($capture->captureId, (int) ($capture->context['planning_revision'] ?? $capture->revision), $plan, (array) ($input['documentation_checkpoint'] ?? []));
            return $plan;
        };
        $applied = [];
        $authorityCapture = new AuthorityCaptureService($captures, $planner, null, static function (CaptureRecord $capture, array $plan, array $ids) use (&$applied): array {
            $applied = $ids;
            return ['status' => 'APPLIED', 'canonical_readback' => ['brand' => 'verified']];
        });
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())), static fn (string $capability): bool => $capability === 'nhk_ingest_articles' || $capability === 'read', documentation: $documentation, authorityCapture: $authorityCapture);

        $plan = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'transport-hermle-plan', 'purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN'], 'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);
        $planned = $plan['body']['result']['structuredContent'];
        self::assertSame(200, $plan['status']);
        self::assertSame('AUTHORITY_PLANNED', $planned['stage']);
        self::assertNull($planned['article_id']);
        $captureId = $planned['capture_id'];
        $fingerprint = $planned['context']['plan_fingerprint'];

        $apply = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'transport-hermle-approval', 'capture_id' => $captureId, 'purpose' => 'AUTHORITY', 'authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => $fingerprint, 'approved_candidate_ids' => ['candidate-hermle']], 'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);
        self::assertSame(200, $apply['status']);
        self::assertFalse($apply['body']['result']['isError']);
        self::assertSame('AUTHORITY_APPLIED', $apply['body']['result']['structuredContent']['stage']);
        self::assertSame(['candidate-hermle'], $applied);
        self::assertSame($captureId, $apply['body']['result']['structuredContent']['capture_id']);
    }

    public function test_capture_transport_preserves_structured_authority_request_through_capture_and_planner(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = new AuthorityEntity($canonicalId, 'brand', 'nhk:brand:hermle', 'Hermle', 1, [], AuthorityState::ACTIVE, 1);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $planner = new AuthorityIntentPlanner(new AuthorityCapturePlannerRepository([$entity]), $types);
        $captures = new TransportCaptureRepository();
        $forwarded = [];
        $authorityCapture = new AuthorityCaptureService($captures, static function (array $input, CaptureRecord $capture) use ($planner, &$forwarded): array {
            $forwarded = $input['authority_intent']['requests'] ?? [];
            return $planner->plan($input, ['capture_id' => $capture->captureId, 'capture_revision' => $capture->revision]);
        });
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => $capability === 'nhk_ingest_articles' || $capability === 'read',
            documentation: $documentation,
            authorityCapture: $authorityCapture,
        );
        $request = [
            'entity_type' => 'brand',
            'canonical_uuid' => $canonicalId,
            'name' => 'Hermle',
            'payload_delta' => [
                'country' => 'Germany',
                'founded_year' => 1922,
                'aliases' => ['Franz Hermle & Sohn'],
                'description' => 'German clock manufacturer founded in 1922.',
            ],
        ];

        $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'transport-structured-authority-update',
            'purpose' => 'AUTHORITY',
            'text' => 'Bổ sung thông tin cho Hermle.',
            'subject_hints' => ['Hermle', $canonicalId],
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [$request]],
            'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);

        self::assertSame(200, $result['status']);
        self::assertSame([$request], $forwarded);
        $plan = $result['body']['result']['structuredContent']['context']['authority_plan'];
        self::assertCount(1, $plan['update_candidates']);
        self::assertSame($canonicalId, $plan['update_candidates'][0]['canonical_uuid']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_capture_plan_and_apply_replan_preserve_uuid_only_update_delta(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = new AuthorityEntity($canonicalId, 'brand', 'nhk:brand:hermle', 'Hermle', 1, [], AuthorityState::ACTIVE, 1);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $planner = new AuthorityIntentPlanner(new AuthorityCapturePlannerRepository([$entity]), $types);
        $captures = new TransportCaptureRepository();
        $forwarded = [];
        $appliedPlan = null;
        $authorityCapture = new AuthorityCaptureService(
            $captures,
            static function (array $input, CaptureRecord $capture) use ($planner, &$forwarded): array {
                $forwarded[] = $input['authority_intent']['requests'] ?? [];
                return $planner->plan($input, [
                    'capture_id' => $capture->captureId,
                    'capture_revision' => (int) ($capture->context['planning_revision'] ?? $capture->revision),
                ]);
            },
            null,
            static function (CaptureRecord $capture, array $plan, array $ids) use (&$appliedPlan): array {
                $appliedPlan = [$plan, $ids];
                return ['status' => 'APPLIED', 'apply_results' => [['canonical_id' => '01a090fd-9a71-7665-af5f-08f6e25b533e']]];
            },
        );
        $request = [
            'entity_type' => 'brand',
            'canonical_uuid' => $canonicalId,
            'payload_delta' => ['country' => 'Germany', 'founded_year' => 1922],
            'allow_create' => false,
        ];

        $first = $authorityCapture->execute([
            'idempotency_key' => 'uuid-only-capture-update',
            'purpose' => 'AUTHORITY',
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [$request]],
        ]);
        $planned = $first->context['authority_plan'];
        $updateId = $planned['update_candidates'][0]['candidate_id'];
        $done = $authorityCapture->continueWithApproval($first->captureId, [
            'authority_intent' => [
                'mode' => 'APPLY_APPROVED_PLAN',
                'approved_plan_fingerprint' => $first->context['plan_fingerprint'],
                'approved_candidate_ids' => [$updateId],
            ],
        ]);

        self::assertSame([[$request], [$request]], $forwarded);
        self::assertSame('APPLIED', $done->status);
        self::assertSame([$planned, [$updateId]], $appliedPlan);
        self::assertSame($canonicalId, $appliedPlan[0]['update_candidates'][0]['canonical_uuid']);
        self::assertSame($request['payload_delta'], $appliedPlan[0]['update_candidates'][0]['payload_patch']);
    }

    public function test_unauthorized_authority_plan_is_blocked_before_dispatch(): void
    {
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => false,
            documentation: $documentation,
        );

        $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'unauthorized-authority-plan', 'purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN'], 'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);

        self::assertSame(403, $result['status']);
        self::assertSame(-32003, $result['body']['error']['code']);
    }

    public function test_relationship_operations_only_dry_run_routes_to_shared_preview_for_all_purposes(): void
    {
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $read = $this->readHandler($this->relationshipReadService());
        $transport = new McpTransport(
            $read,
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => in_array($capability, ['nhk_ingest_articles', 'read'], true),
            documentation: $documentation,
        );

        foreach ([null, 'AUTHORITY', 'MIXED'] as $ordinal => $purpose) {
            $arguments = [
                'idempotency_key' => 'relationship-dry-run-' . $ordinal,
                'dry_run' => true,
                'relationship_operations' => [[
                    'operation' => 'ADD',
                    'relationship_kind' => 'graph',
                    'source' => ['type' => 'model', 'id' => '11111111-1111-4111-8111-111111111111'],
                    'predicate' => 'model_of',
                    'target' => ['type' => 'brand', 'id' => '22222222-2222-4222-8222-222222222222'],
                ]],
                'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
            ];
            if ($purpose !== null) $arguments['purpose'] = $purpose;

            $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => $ordinal, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => $arguments]], []);

            self::assertSame(200, $result['status']);
            self::assertFalse($result['body']['result']['isError'] ?? false);
            self::assertSame('PREVIEW', $result['body']['result']['structuredContent']['status']);
            self::assertSame('RELATION_NO_OP', $result['body']['result']['structuredContent']['preview']['relationship_operations'][0]['planned_transition'][0]['action']);
        }
    }

    public function test_relationship_only_mixed_capture_does_not_create_an_article(): void
    {
        $captures = new TransportCaptureRepository();
        $editorialCalls = 0;
        $authorityCapture = new AuthorityCaptureService(
            $captures,
            static fn (array $input, CaptureRecord $capture): array => [
                'relation_candidates' => [[
                    'candidate_id' => 'relationship-only',
                    'entity_type' => 'relation',
                    'action' => 'CREATE',
                ]],
                'relation_reuse' => [],
                'plan_fingerprint' => str_repeat('a', 64),
                'blockers' => [],
            ],
            static function () use (&$editorialCalls): array {
                ++$editorialCalls;
                throw new \LogicException('relationship-only Capture must not create an Article');
            },
        );

        $record = $authorityCapture->execute([
            'idempotency_key' => 'relationship-only-mixed',
            'purpose' => 'MIXED',
            'relationship_operations' => [['operation' => 'ADD']],
        ]);

        self::assertSame(0, $editorialCalls);
        self::assertNull($record->articleId);
        self::assertSame('MIXED', $record->context['purpose']);
    }

    public function test_knowledge_repair_dry_run_still_requires_its_own_preview_service(): void
    {
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport(
            $this->readHandler(),
            new McpGovernanceHandler(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())),
            static fn (string $capability): bool => in_array($capability, ['nhk_ingest_articles', 'read'], true),
            documentation: $documentation,
        );

        $result = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => [
            'idempotency_key' => 'knowledge-repair-preview-required',
            'dry_run' => true,
            'intent' => 'KNOWLEDGE_REPAIR',
            'knowledge_repair' => ['canonical_knowledge_uuid' => '11111111-1111-4111-8111-111111111111', 'expected_revision' => 1, 'operation' => 'update', 'reason' => 'cleanup', 'provenance' => ['origin' => 'test'], 'cleanup_class' => 'PROCESS_CONTAMINATION', 'delta' => ['text' => 'clean']],
            'documentation_checkpoint' => ['documentation_version' => $checkpoint['documentation_version'], 'manifest_hash' => $checkpoint['manifest_hash']],
        ]]], []);

        self::assertSame(400, $result['status']);
        self::assertSame('KNOWLEDGE_REPAIR_PREVIEW_REQUIRED', $result['body']['error']['message']);
    }

    private function readHandler(?RelationshipReadService $relationships = null): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), null, $this->createMock(SourceRepository::class), relationships: $relationships);
    }

    private function relationshipReadService(): RelationshipReadService
    {
        $source = '11111111-1111-4111-8111-111111111111';
        $target = '22222222-2222-4222-8222-222222222222';
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('model', new FakeEndpointResolver('model', [$source]));
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$target]));
        $graph = new InMemoryGraphRepository();
        $predicates = new PredicateRegistry();
        $graph->createEdge($graph->resolveNode(new NodeReference('model', $source)), $predicates->get('model_of'), $graph->resolveNode(new NodeReference('brand', $target)));
        return new RelationshipReadService($endpoints, $predicates, $graph, static fn (NodeReference $reference): array => ['exists' => true, 'active' => true, 'revision' => 1]);
    }
}

final class TransportCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */ private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}

final class AuthorityCapturePlannerRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $items */
    public function __construct(private array $items = []) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type && ($includeRetired || $item->active()))); }
}
