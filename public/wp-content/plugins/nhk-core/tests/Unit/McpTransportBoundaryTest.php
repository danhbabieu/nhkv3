<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class McpTransportBoundaryTest extends TestCase
{
    public function test_relationship_owner_read_discriminators_match_exactly_one_branch(): void
    {
        $transport = $this->transport($this->read());
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        foreach ([
            ['nhk.relationship.list', ['filters' => ['relationship_kind' => 'media_usage']], 'filters'],
            ['nhk.relationship.list', ['filters' => ['relationship_kind' => 'evidence']], 'filters'],
            ['nhk.relationship.get', ['id' => $uuid, 'relationship_kind' => 'media_usage'], 'arguments'],
            ['nhk.relationship.get', ['id' => $uuid, 'relationship_kind' => 'evidence'], 'arguments'],
        ] as [$name, $arguments, $scope]) {
            $response = $transport->dispatch([
                'jsonrpc' => '2.0', 'id' => $name, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);

            self::assertSame(200, $response['status']);
            self::assertArrayNotHasKey('error', $response['body']['result'], $name . ' rejected ' . $scope);
        }
    }

    public function test_graph_relationship_get_omitted_kind_remains_legacy_compatible(): void
    {
        $uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $response = $this->transport($this->read())->dispatch([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'nhk.relationship.get', 'arguments' => ['id' => $uuid]],
        ]);

        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('error', $response['body']['result']);
    }

    public function test_nested_capture_media_replace_matches_media_owner_not_evidence_owner(): void
    {
        $schema = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.capture.ingest'))[0]['inputSchema'];
        $arguments = [
            'idempotency_key' => 'oneof-media-replace',
            'documentation_checkpoint' => ['documentation_version' => str_repeat('b', 64), 'manifest_hash' => str_repeat('a', 64)],
            'relationship_operations' => [[
                'operation' => 'REPLACE',
                'relationship_kind' => 'media_usage',
                'media' => ['type' => 'media', 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
                'target' => ['type' => 'model', 'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
                'usage_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                'expected_usage_revision' => 1,
            ]],
        ];

        $this->invokeValidator($schema, $arguments);
        self::assertTrue(true);
    }

    public function test_capture_relationship_operations_use_graph_and_evidence_discriminators(): void
    {
        $schema = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.capture.ingest'))[0]['inputSchema'];
        $base = ['idempotency_key' => 'discriminator-contract', 'documentation_checkpoint' => ['documentation_version' => str_repeat('b', 64), 'manifest_hash' => str_repeat('a', 64)]];
        $graph = $base + ['relationship_operations' => [[
            'operation' => 'REMOVE', 'relationship_kind' => 'graph',
            'source' => ['type' => 'variant', 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
            'predicate' => 'configured_with_music',
            'target' => ['type' => 'music', 'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
            'current_relation_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'expected_edge_revision' => 1,
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
        ]]];
        $evidence = $base + ['relationship_operations' => [[
            'operation' => 'RETIRE', 'relationship_kind' => 'evidence',
            'evidence_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'claim_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'claim_revision' => 1,
            'source_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'source_revision' => 1,
        ]]];
        $this->invokeValidator($schema, $graph);
        $this->invokeValidator($schema, $evidence);
        $this->expectExceptionMessage('ONE_OF_NO_MATCH');
        $this->invokeValidator($schema, $base + ['relationship_operations' => [[
            'operation' => 'REMOVE', 'relationship_kind' => 'evidence',
            'source' => ['type' => 'variant', 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
            'predicate' => 'configured_with_music', 'target' => ['type' => 'music', 'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
        ]]]);
    }

    public function test_oneof_reports_deterministic_ambiguity_and_combined_no_match(): void
    {
        $ambiguous = [
            'oneOf' => [
                ['type' => 'object', 'properties' => ['kind' => ['enum' => ['x']]], 'required' => ['kind'], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['kind' => ['enum' => ['x']]], 'required' => ['kind'], 'additionalProperties' => false],
            ],
        ];
        $error = $this->captureValidationError($ambiguous, ['kind' => 'x']);
        self::assertSame('ONE_OF_AMBIGUOUS: value.', $error);
        self::assertSame($error, $this->captureValidationError(['oneOf' => array_reverse($ambiguous['oneOf'])], ['kind' => 'x']));

        $noMatch = [
            'oneOf' => [
                ['type' => 'object', 'properties' => ['kind' => ['const' => 'x']], 'required' => ['kind'], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['kind' => ['const' => 'y']], 'required' => ['kind'], 'additionalProperties' => false],
            ],
        ];
        $error = $this->captureValidationError($noMatch, ['kind' => 'z']);
        self::assertStringStartsWith('ONE_OF_NO_MATCH: value.', $error);
        self::assertStringContainsString('branch[0]', $error);
        self::assertStringContainsString('branch[1]', $error);
    }

    public function test_capture_get_unknown_is_structured_over_tools_call(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $read = $this->read(new class implements CaptureRepository {
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        });
        $response = $this->transport($read)->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.get', 'arguments' => ['id' => $id]]], ['Mcp-Name' => 'nhk.capture.get']);

        self::assertSame(200, $response['status'], json_encode($response, JSON_UNESCAPED_SLASHES));
        self::assertSame('not_found', $response['body']['result']['structuredContent']['status']);
        self::assertSame('CAPTURE_NOT_FOUND', $response['body']['result']['structuredContent']['reason']);
    }

    public function test_empty_mutation_result_is_structured_as_unknown_and_preserves_identity(): void
    {
        $method = new \ReflectionMethod(McpTransport::class, 'normalizeMutationResult');
        $result = $method->invoke($this->transport($this->read()), 'nhk.capture.ingest', [
            'idempotency_key' => 'same-request',
            'capture_id' => 'capture-1',
            'request_fingerprint' => 'fingerprint-1',
            'video' => ['external_video_id' => 'external-1'],
        ], true, []);

        self::assertSame('OUTCOME_UNKNOWN', $result['outcome']);
        self::assertSame('RECONCILE_ORIGINAL_IDENTITY', $result['resume_hint']);
        self::assertSame('same-request', $result['identity']['idempotency_key']);
        self::assertSame('capture-1', $result['identity']['capture_id']);
    }

    public function test_semantic_uuid_and_stable_key_are_resolved_over_tools_call(): void
    {
        $types = new EntityTypeRegistry();
        if ($types->all() === []) CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $entity = new AuthorityEntity('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'model', 'nhk:model:odo.36', 'Odo 36', 2, []);
        $authority->create($entity);
        $read = $this->read(null, $authority, $types);
        $transport = $this->transport($read);

        foreach ([['canonical_uuid' => $entity->canonicalId], ['stable_key' => $entity->stableKey]] as $context) {
            $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nhk.semantic.resolve', 'arguments' => ['context' => $context]]], ['Mcp-Name' => 'nhk.semantic.resolve']);
            self::assertSame($entity->canonicalId, $response['body']['result']['structuredContent']['resolved']['model']['id']);
            self::assertSame([], $response['body']['result']['structuredContent']['missing']);
        }
    }

    private function read(?CaptureRepository $captures = null, ?InMemoryAuthorityRepository $authority = null, ?EntityTypeRegistry $types = null): McpReadHandler
    {
        $authority ??= new InMemoryAuthorityRepository();
        $types ??= new EntityTypeRegistry();
        if ($types->all() === []) CanonicalEntityTypeCatalog::registerInto($types);
        return new McpReadHandler($authority, $types, $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class), $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class), $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class), $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class), $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class), resolver: new McpSemanticContextResolver($authority, $types), captures: $captures);
    }

    private function transport(McpReadHandler $read): McpTransport
    {
        return new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true);
    }

    private function invokeValidator(array $schema, array $arguments): void
    {
        $method = new \ReflectionMethod(McpTransport::class, 'validateArguments');
        $method->invoke($this->transport($this->read()), $schema, $arguments);
    }

    private function captureValidationError(array $schema, mixed $value): string
    {
        try {
            $method = new \ReflectionMethod(McpTransport::class, 'validateArgumentValue');
            $method->invoke($this->transport($this->read()), 'value', $value, $schema);
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
        self::fail('Expected schema validation to fail.');
    }
}
