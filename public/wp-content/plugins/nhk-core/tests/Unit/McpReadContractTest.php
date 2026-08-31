<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

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

final class McpReadContractTest extends TestCase
{
    public function test_entity_read_adapter_is_non_mutating_and_type_safe(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new \NHK\Core\Application\Authority\AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $entity = $authority->create('brand', 'odo', 'Odo');
        $media = new class implements MediaRepository {
            public function findByCanonicalId(string $id): ?Media { return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $item): Media { return $item; }
            public function update(Media $item, int $expectedRevision): Media { return $item; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $assets = new class implements MediaAssetRepository {
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $item): MediaAsset { return $item; }
            public function update(MediaAsset $item, int $expectedRevision = 1): MediaAsset { return $item; }
            public function listByMediaId(string $id): array { return []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usages = new class implements MediaUsageRepository {
            public function create(MediaUsage $item): MediaUsage { return $item; }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
        };
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $item): Video { return $item; }
            public function update(Video $item, int $expectedRevision): Video { return $item; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $item): KnowledgeClaim { return $item; }
            public function update(KnowledgeClaim $item, int $expectedRevision): KnowledgeClaim { return $item; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $item): Evidence { return $item; }
            public function update(Evidence $item, int $revision): Evidence { return $item; }
            public function listByClaim(string $id, bool $includeRetired = false): array { return []; }
            public function listBySource(string $id, bool $includeRetired = false): array { return []; }
        };
        $handler = new McpReadHandler($authorityRepository, $types, $media, $assets, $usages, $videos, $claims, $evidence);
        self::assertSame($entity->canonicalId, $handler->entityGet('brand', $entity->canonicalId)['id']);
        self::assertNull($handler->entityGet('model', $entity->canonicalId));
        $retired = $authority->create('brand', 'retired', 'Retired');
        $authority->retire($retired->canonicalId, 1);
        self::assertNull($handler->entityGet('brand', $retired->canonicalId));
        self::assertSame($entity->canonicalId, $authorityRepository->findByCanonicalId($entity->canonicalId)?->canonicalId);
    }
}
