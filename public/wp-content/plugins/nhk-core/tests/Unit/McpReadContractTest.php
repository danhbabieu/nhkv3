<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class McpReadContractTest extends TestCase
{
    public function test_entity_read_adapter_is_non_mutating_and_type_safe(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, ['country']));
        $authority = new \NHK\Core\Application\Authority\AuthorityService($authorityRepository = new InMemoryAuthorityRepository(), $types);
        $entity = $authorityRepository->create(new AuthorityEntity(UuidCodec::newV7(), 'brand', 'odo', 'Odo', 1, ['country' => 'Switzerland', 'private_note' => 'internal']));
        $mcpMedia = new Media(UuidCodec::newV7(), 'mcp-ready-media', 'MCP ready media', 'ready', ['private' => 'provenance']);
        $mcpAsset = new MediaAsset(UuidCodec::newV7(), $mcpMedia->canonicalId, 'original', 'private/storage.webp', hash('sha256', 'mcp'), 'image/webp', 3, 10, 10, 'PUBLIC', ['private' => 'metadata']);
        $mcpUsage = new MediaUsage(UuidCodec::newV7(), $mcpMedia->canonicalId, 'wp_post', '1:42', 'gallery', 1);
        $mcpVideo = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'MCP video', ['private' => 'metadata']);
        $media = new class($mcpMedia) implements MediaRepository {
            public function __construct(private Media $item) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->item->canonicalId ? $this->item : null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $item): Media { return $item; }
            public function update(Media $item, int $expectedRevision): Media { return $item; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $assets = new class($mcpAsset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $item) {}
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $item): MediaAsset { return $item; }
            public function update(MediaAsset $item, int $expectedRevision = 1): MediaAsset { return $item; }
            public function listByMediaId(string $id): array { return $id === $this->item->mediaId ? [$this->item] : []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usages = new class($mcpUsage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $item) {}
            public function create(MediaUsage $item): MediaUsage { return $item; }
            public function listByMediaId(string $id, ?string $role = null): array { return $id === $this->item->mediaId ? [$this->item] : []; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
        };
        $videos = new class($mcpVideo) implements VideoRepository {
            public function __construct(private Video $item) {}
            public function findByCanonicalId(string $id): ?Video { return $id === $this->item->canonicalId ? $this->item : null; }
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
        self::assertSame(['country' => 'Switzerland'], $handler->entityGet('brand', $entity->canonicalId)['payload']);
        $mediaRead = $handler->mediaGet($mcpMedia->canonicalId);
        self::assertArrayNotHasKey('provenance', $mediaRead);
        self::assertArrayNotHasKey('storage_key', $mediaRead['assets'][0]);
        self::assertArrayNotHasKey('endpoint_type', $mediaRead['usages'][0]);
        $videoRead = $handler->videoGet($mcpVideo->canonicalId);
        self::assertArrayNotHasKey('metadata', $videoRead);
        self::assertNull($handler->entityGet('model', $entity->canonicalId));
        $retired = $authority->create('brand', 'retired', 'Retired');
        $authority->retire($retired->canonicalId, 1);
        self::assertNull($handler->entityGet('brand', $retired->canonicalId));
        self::assertSame($entity->canonicalId, $authorityRepository->findByCanonicalId($entity->canonicalId)?->canonicalId);
    }
}
