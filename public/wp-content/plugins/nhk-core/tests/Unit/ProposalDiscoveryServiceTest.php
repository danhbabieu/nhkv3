<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Governance\ProposalDiscoveryService;
use NHK\Core\Contracts\Governance\ProposalDiscoveryReader;
use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Infrastructure\Http\AdminWorkbenchReadApi;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use PHPUnit\Framework\TestCase;

final class ProposalDiscoveryServiceTest extends TestCase
{
    public function test_exact_selectors_return_only_the_bounded_projection(): void
    {
        $reader = new class implements ProposalDiscoveryReader {
            public array $calls = [];
            public function discover(array $selectors, int $limit): array { $this->calls[] = [$selectors, $limit]; return [['proposal_id' => 'p', 'entity_type' => 'source', 'operation' => 'create', 'state' => 'draft', 'capture_id' => 'c', 'entity_id' => 'e', 'idempotency_key' => 'k', 'payload' => ['secret' => 'no']]]; }
        };
        $service = new ProposalDiscoveryService($reader);
        $result = $service->discover(['capture_id' => 'c', 'limit' => 500]);
        self::assertSame('found', $result['status']);
        self::assertSame(50, $result['limit']);
        self::assertArrayNotHasKey('payload', $result['items'][0]);
        self::assertSame([['capture_id' => 'c'], 50], $reader->calls[0]);
    }

    public function test_all_required_lookup_shapes_and_not_found_are_read_only(): void
    {
        $reader = new class implements ProposalDiscoveryReader {
            public int $reads = 0;
            public function discover(array $selectors, int $limit): array { $this->reads++; return $selectors === ['proposal_id' => 'missing'] ? [] : [['proposal_id' => 'p']]; }
        };
        $service = new ProposalDiscoveryService($reader);
        foreach ([['proposal_id' => 'p'], ['capture_id' => 'c'], ['entity_type' => 'knowledge', 'entity_id' => 'e'], ['idempotency_key' => 'k']] as $selector) self::assertSame('found', $service->discover($selector)['status']);
        self::assertSame('not_found', $service->discover(['proposal_id' => 'missing'])['status']);
        self::assertSame(5, $reader->reads);
    }

    public function test_selector_must_be_exact(): void
    {
        $service = new ProposalDiscoveryService(new class implements ProposalDiscoveryReader { public function discover(array $selectors, int $limit): array { return []; } });
        $this->expectException(\InvalidArgumentException::class);
        $service->discover([]);
    }

    public function test_admin_and_mcp_delegate_to_the_same_read_projection(): void
    {
        $reader = new class implements ProposalDiscoveryReader {
            public function discover(array $selectors, int $limit): array { return [['proposal_id' => 'p', 'entity_type' => 'e', 'operation' => 'o', 'state' => 'draft']]; }
        };
        $service = new ProposalDiscoveryService($reader);
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            proposalDiscovery: $service,
        );
        $admin = new AdminWorkbenchReadApi(
            $this->createMock(MediaRepository::class), $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(AuthorityRepository::class),
            proposalDiscovery: $service,
        );
        self::assertSame($read->proposalDiscover(['proposal_id' => 'p']), $admin->proposalDiscover(['proposal_id' => 'p']));
    }
}
