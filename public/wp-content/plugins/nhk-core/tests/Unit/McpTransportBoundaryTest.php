<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpTransport};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class McpTransportBoundaryTest extends TestCase
{
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
}
