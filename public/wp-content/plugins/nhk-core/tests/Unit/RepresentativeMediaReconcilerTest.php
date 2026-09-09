<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaService, RepresentativeMediaReconciler};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use PHPUnit\Framework\TestCase;

final class RepresentativeMediaReconcilerTest extends TestCase
{
    public function test_empty_scope_gets_representative_and_better_candidate_demotes_old_without_deleting_it(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $old = $this->media($service, 'representative-old', 1);
        $better = $this->media($service, 'representative-better', 2);
        $galleryUsage = $service->addUsage($old->canonicalId, 'variant', 'variant-1', 'gallery');

        $reconciler = new RepresentativeMediaReconciler($media, $assets, $usages, $service);
        $first = $reconciler->reconcile('variant', 'variant-1', [$this->candidate($old, 5)]);
        $oldUsage = $usages->listByEndpoint('variant', 'variant-1', 'representative')[0];
        $second = $reconciler->reconcile('variant', 'variant-1', [$this->candidate($old, 5), $this->candidate($better, 8)]);

        self::assertSame('ADDED', $first['status']);
        self::assertSame('PROMOTED', $second['status']);
        self::assertSame($better->canonicalId, $second['media_id']);
        self::assertNotNull($media->findByCanonicalId($old->canonicalId));
        self::assertSame('technical_detail', $usages->listByEndpoint('variant', 'variant-1', 'technical_detail')[0]->role);
        self::assertSame($oldUsage->usageId, $usages->listByEndpoint('variant', 'variant-1', 'technical_detail')[0]->usageId);
        self::assertNotNull($usages->listByEndpoint('variant', 'variant-1', 'gallery')[0] ?? null);
    }

    public function test_wrong_scope_candidate_is_rejected_without_creating_usage(): void
    {
        [$media, $assets, $usages, $service] = $this->stores();
        $item = $this->media($service, 'brand-wide', 9);
        $result = (new RepresentativeMediaReconciler($media, $assets, $usages, $service)->reconcile('brand', 'brand-1', [$this->candidate($item, 99, false)]));

        self::assertSame('NO_SUITABLE_CANDIDATE', $result['status']);
        self::assertSame([], $usages->items);
    }

    /** @return array<string,mixed> */
    private function candidate(Media $media, int $specificity, bool $justified = true): array
    {
        return ['media_id' => $media->canonicalId, 'scope_justified' => $justified, 'semantic_specificity' => $specificity, 'visual_subject_coverage' => 5, 'technical_usefulness' => 5, 'clarity_resolution' => 5, 'provenance_confidence' => 5];
    }

    private function media(MediaService $service, string $key, int $specificity): Media
    {
        $media = $service->create($key, $key, 'ready', ['semantic_specificity' => $specificity]);
        $service->addAsset($media->canonicalId, 'original', 'uploads/' . $key . '.jpg', hash('sha256', $key), 'image/jpeg', 10, 1600, 900, 'PUBLIC');
        return $media;
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
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usages = new class implements MediaUsageRepository, MediaUsageUpdater {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function update(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        return [$media, $assets, $usages, new MediaService($media, $assets, $usages)];
    }
}
