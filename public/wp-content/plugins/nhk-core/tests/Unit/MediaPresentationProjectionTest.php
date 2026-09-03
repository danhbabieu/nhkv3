<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use PHPUnit\Framework\TestCase;

final class MediaPresentationProjectionTest extends TestCase
{
    public function test_entity_projection_returns_representative_and_evidence_separately(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $representative = $service->create('entity-front', 'Entity front', 'ready');
        $evidence = $service->create('entity-serial', 'Entity serial', 'ready');
        $service->addAsset($representative->canonicalId, 'original', 'uploads/entity-front.webp', hash('sha256', 'front'), 'image/webp', 10, 1600, 900, 'PUBLIC', ['canonical_filename' => 'entity-front.webp']);
        $service->addAsset($evidence->canonicalId, 'original', 'uploads/entity-serial.webp', hash('sha256', 'serial'), 'image/webp', 10, 1200, 800, 'PUBLIC', ['canonical_filename' => 'entity-serial.webp']);
        $service->addUsage($representative->canonicalId, 'variant', 'variant-36-10', 'representative', 0, 'Ảnh đại diện 36/10');
        $service->addUsage($evidence->canonicalId, 'variant', 'variant-36-10', 'evidence', 0, 'Ảnh serial 36/10');

        $projection = new \NHK\Core\Application\Entity\EntityMediaProjection($media, $assets, $usages);
        $result = $projection->forEntity('variant', 'variant-36-10');

        self::assertSame($representative->canonicalId, $result['representative']['media_id']);
        self::assertSame('Ảnh đại diện 36/10', $result['representative']['alt']);
        self::assertCount(1, $result['evidence']);
        self::assertSame($evidence->canonicalId, $result['evidence'][0]['media_id']);
        self::assertNotSame($result['representative']['media_id'], $result['evidence'][0]['media_id']);
    }

    /** @return array{0:object,1:object,2:object,3:MediaService} */
    private function stores(): array
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { return $this->items[$item->canonicalId] = $item; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->checksum === $checksum)); }
        };
        $usages = new class implements MediaUsageRepository {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        return [$media, $assets, $usages, new MediaService($media, $assets, $usages)];
    }
}
