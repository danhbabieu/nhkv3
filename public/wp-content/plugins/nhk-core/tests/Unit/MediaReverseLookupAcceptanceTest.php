<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaReverseLookupAcceptanceTest extends TestCase
{
    public function test_media_get_returns_each_independent_active_target_usage(): void
    {
        $mediaId = '01a0d7ee-3e33-7366-88c6-287112b34936';
        $media = $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class);
        $media->method('findByCanonicalId')->with($mediaId)->willReturn(new Media($mediaId, 'wp-attachment:1:567', 'Article image', 'ready'));
        $assets = $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class);
        $assets->method('listByMediaId')->willReturn([]);
        $usages = $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class);
        $usages->method('listByMediaId')->with($mediaId)->willReturn([
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:18', MediaUsageRoleRegistry::FEATURED_PRIMARY, placementKey: 'featured_primary', revision: 2),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:19', MediaUsageRoleRegistry::FEATURED_PRIMARY, placementKey: 'featured_primary', revision: 1),
            new MediaUsage(UuidCodec::newV7(), $mediaId, 'model', UuidCodec::newV7(), MediaUsageRoleRegistry::REPRESENTATIVE, activeSlot: 'retired'),
        ]);
        $handler = new McpReadHandler(
            $this->createMock(\NHK\Core\Contracts\Authority\AuthorityRepository::class),
            new EntityTypeRegistry(), $media, $assets, $usages,
            $this->createMock(\NHK\Core\Contracts\Video\VideoRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\KnowledgeRepository::class),
            $this->createMock(\NHK\Core\Contracts\Knowledge\EvidenceRepository::class),
        );

        $result = $handler->mediaGet($mediaId);
        self::assertCount(2, $result['usages']);
        self::assertSame(['1:18', '1:19'], array_column($result['usages'], 'target_id'));
        self::assertSame([2, 1], array_column($result['usages'], 'revision'));
    }
}
