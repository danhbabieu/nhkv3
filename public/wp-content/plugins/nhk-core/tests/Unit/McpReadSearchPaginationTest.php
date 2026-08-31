<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class McpReadSearchPaginationTest extends TestCase
{
    public function test_mcp_search_bounds_semantic_groups_and_reports_totals(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        for ($index = 1; $index <= 14; $index++) $authority->create('brand', 'mcp-search-clock-' . $index, 'Clock ' . $index);

        $handler = new McpReadHandler(
            $authorityRepository,
            $types,
            new class implements MediaRepository {
                public function findByCanonicalId(string $id): ?Media { return null; }
                public function findByStableKey(string $key): ?Media { return null; }
                public function create(Media $item): Media { return $item; }
                public function update(Media $item, int $expectedRevision): Media { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            new class implements MediaAssetRepository {
                public function findByAssetId(string $id): ?MediaAsset { return null; }
                public function create(MediaAsset $item): MediaAsset { return $item; }
                public function update(MediaAsset $item, int $expectedRevision = 1): MediaAsset { return $item; }
                public function listByMediaId(string $id): array { return []; }
                public function findByChecksum(string $checksum): array { return []; }
            },
            new class implements MediaUsageRepository {
                public function create(MediaUsage $item): MediaUsage { return $item; }
                public function listByMediaId(string $id, ?string $role = null): array { return []; }
                public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
            },
            new class implements VideoRepository {
                public function findByCanonicalId(string $id): ?Video { return null; }
                public function findByExternalReference(string $platform, string $id): ?Video { return null; }
                public function create(Video $item): Video { return $item; }
                public function update(Video $item, int $expectedRevision): Video { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            new class implements KnowledgeRepository {
                public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
                public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
                public function create(KnowledgeClaim $item): KnowledgeClaim { return $item; }
                public function update(KnowledgeClaim $item, int $expectedRevision): KnowledgeClaim { return $item; }
                public function list(bool $includeRetired = false): array { return []; }
            },
            new class implements EvidenceRepository {
                public function findByCanonicalId(string $id): ?Evidence { return null; }
                public function create(Evidence $item): Evidence { return $item; }
                public function update(Evidence $item, int $expectedRevision): Evidence { return $item; }
                public function listByClaim(string $id, bool $includeRetired = false): array { return []; }
                public function listBySource(string $id, bool $includeRetired = false): array { return []; }
            },
        );

        $result = $handler->search('clock', 2, 5);

        self::assertSame(2, $result['page']);
        self::assertSame(5, $result['per_page']);
        self::assertCount(5, $result['groups']['entities']);
        self::assertSame('Clock 6', $result['groups']['entities'][0]['title']);
        self::assertSame(14, $result['semantic_totals']['entities']);
    }
}
