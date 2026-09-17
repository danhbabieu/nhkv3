<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\{AuthorityIntentPlanner, AuthorityPlanFingerprint};
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
        $planner = new AuthorityIntentPlanner(new CaptureAuthorityRepository([$entity]), $types);
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

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler($this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), null, $this->createMock(SourceRepository::class));
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

final class CaptureAuthorityRepository implements AuthorityRepository
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
