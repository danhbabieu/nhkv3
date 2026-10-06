<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{ArticleMediaCandidateSelector, ArticleMediaLegacyAudit, MediaService, SemanticSuitabilityPolicy};
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, ArticleMediaUsageInventory, MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoBlueprint, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ArticleMediaLegacyAuditTest extends TestCase
{
    public function test_audit_reports_missing_scope_and_invalid_auto_usage_without_mutation(): void
    {
        [$inventory, $media, $assets, $usages, $blueprints, $service] = $this->stores();
        $unscoped = $service->create('audit-unscoped', 'Audit unscoped', 'ready');
        $wrong = $service->create('audit-wrong', 'Audit wrong', 'ready', ['subject_id' => 'subject-other']);
        $this->publicAsset($service, $unscoped, 'audit-unscoped');
        $this->publicAsset($service, $wrong, 'audit-wrong');
        $missingScope = $service->addUsage($unscoped->canonicalId, 'wp_post', '1:101', 'featured_primary');
        $invalid = $service->addUsage($wrong->canonicalId, 'wp_post', '1:102', 'featured_primary');
        $blueprints->items['102:featured_primary'] = $this->blueprint(102, 'subject-target');

        $result = $this->audit($inventory, $media, $assets, $usages, $blueprints);

        self::assertSame('DRY_RUN', $result['status']);
        self::assertCount(2, $result['findings']);
        self::assertContains('MISSING_SUBJECT_SCOPE', array_column($result['findings'], 'reason'));
        self::assertContains('SUBJECT_SCOPE_MISMATCH', array_column($result['findings'], 'reason'));
        self::assertSame(0, $media->updates);
        self::assertSame(0, $usages->updates);
        self::assertSame($missingScope->mediaId, $usages->items[$missingScope->usageId]->mediaId);
        self::assertSame($invalid->mediaId, $usages->items[$invalid->usageId]->mediaId);
    }

    public function test_audit_protects_explicit_pinned_usage(): void
    {
        [$inventory, $media, $assets, $usages, $blueprints, $service] = $this->stores();
        $pinned = $service->create('audit-pinned', 'Audit pinned', 'ready');
        $this->publicAsset($service, $pinned, 'audit-pinned');
        $service->addUsage($pinned->canonicalId, 'wp_post', '1:103', 'featured_primary', 0, '', '', [], '', '', 'USER_EXPLICIT', 'PINNED');

        $result = $this->audit($inventory, $media, $assets, $usages, $blueprints);

        self::assertSame('EXPLICIT_PINNED_PROTECTED', $result['findings'][0]['reason']);
        self::assertSame('PROTECTED', $result['findings'][0]['status']);
        self::assertArrayNotHasKey('replacement_media_id', $result['findings'][0]);
    }

    public function test_audit_emits_replacement_only_when_selector_proves_one(): void
    {
        [$inventory, $media, $assets, $usages, $blueprints, $service] = $this->stores();
        $wrong = $service->create('audit-replace-wrong', 'Audit replace wrong', 'ready', ['subject_id' => 'subject-other']);
        $exact = $service->create('audit-replace-exact', 'Audit replace exact', 'ready', ['subject_id' => 'subject-target']);
        $this->publicAsset($service, $wrong, 'audit-replace-wrong');
        $this->publicAsset($service, $exact, 'audit-replace-exact');
        $usage = $service->addUsage($wrong->canonicalId, 'wp_post', '1:104', 'featured_primary');
        $blueprints->items['104:featured_primary'] = $this->blueprint(104, 'subject-target');

        $result = $this->audit($inventory, $media, $assets, $usages, $blueprints);
        $finding = $result['findings'][0];

        self::assertSame($exact->canonicalId, $finding['replacement_media_id']);
        self::assertSame('REPLACE_MEDIA_USAGE', $finding['repair_plan']['operation']);
        self::assertSame($usage->revision, $finding['repair_plan']['expected_usage_revision']);
        self::assertSame($wrong->revision, $finding['repair_plan']['expected_media_revision']);
        self::assertSame(0, $usages->updates);
    }

    public function test_audit_returns_no_safe_media_disposition(): void
    {
        [$inventory, $media, $assets, $usages, $blueprints, $service] = $this->stores();
        $wrong = $service->create('audit-no-safe', 'Audit no safe', 'ready', ['subject_id' => 'subject-other']);
        $this->publicAsset($service, $wrong, 'audit-no-safe');
        $service->addUsage($wrong->canonicalId, 'wp_post', '1:105', 'featured_primary');
        $blueprints->items['105:featured_primary'] = $this->blueprint(105, 'subject-target');

        $result = $this->audit($inventory, $media, $assets, $usages, $blueprints);

        self::assertSame('SUBJECT_SCOPE_MISMATCH', $result['findings'][0]['reason']);
        self::assertSame('NO_SAFE_MEDIA', $result['findings'][0]['repair_plan']['disposition']);
        self::assertArrayNotHasKey('replacement_media_id', $result['findings'][0]);
    }

    public function test_audit_cursor_and_limit_are_deterministic(): void
    {
        [$inventory, $media, $assets, $usages, $blueprints, $service] = $this->stores();
        foreach (['a', 'b'] as $suffix) {
            $item = $service->create('audit-cursor-' . $suffix, 'Audit cursor ' . $suffix, 'ready', ['subject_id' => 'other-' . $suffix]);
            $this->publicAsset($service, $item, 'audit-cursor-' . $suffix);
            $service->addUsage($item->canonicalId, 'wp_post', '1:10' . ($suffix === 'a' ? '6' : '7'), 'featured_primary');
            $blueprints->items['10' . ($suffix === 'a' ? '6' : '7') . ':featured_primary'] = $this->blueprint((int) ('10' . ($suffix === 'a' ? '6' : '7')), 'subject-target');
        }

        $first = $this->audit($inventory, $media, $assets, $usages, $blueprints, '', 1);
        $second = $this->audit($inventory, $media, $assets, $usages, $blueprints, (string) $first['next_cursor'], 1);
        $repeat = $this->audit($inventory, $media, $assets, $usages, $blueprints, '', 1);

        self::assertSame('DRY_RUN', $first['status']);
        self::assertNotSame('', (string) $first['next_cursor']);
        self::assertCount(1, $first['findings']);
        self::assertCount(1, $second['findings']);
        self::assertSame($first['findings'][0]['fingerprint'], $repeat['findings'][0]['fingerprint']);
    }

    private function audit(ArticleMediaUsageInventory $inventory, MediaRepository $media, MediaAssetRepository $assets, MediaUsageRepository $usages, ArticleMediaBlueprintRepository $blueprints, string $cursor = '', int $limit = 100): array
    {
        return (new ArticleMediaLegacyAudit($inventory, $media, $assets, $usages, $blueprints, new ArticleMediaCandidateSelector($media, $assets, $usages, new SemanticSuitabilityPolicy()), new SemanticSuitabilityPolicy()))->audit($cursor, $limit);
    }

    private function blueprint(int $postId, string $subjectId): MediaSeoBlueprint
    {
        return MediaSeoBlueprint::forPost($postId, 'featured_primary', ['subject_context' => ['subject_ids' => [$subjectId]]]);
    }

    private function publicAsset(MediaService $service, Media $media, string $stem): void
    {
        $service->addAsset($media->canonicalId, 'original', 'uploads/' . $stem . '.webp', hash('sha256', $stem), 'image/webp', 10, 1200, 675, 'PUBLIC', ['canonical_filename' => $stem . '.webp']);
    }

    /** @return array{0:ArticleMediaUsageInventory,1:object,2:object,3:object,4:object,5:MediaService} */
    private function stores(): array
    {
        $media = new class implements MediaRepository {
            public array $items = []; public int $updates = 0;
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $expectedRevision): Media { ++$this->updates; return $this->items[$item->canonicalId] = $item; }
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
        $usages = new class implements MediaUsageRepository {
            public array $items = []; public int $updates = 0;
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
        };
        $blueprints = new class implements ArticleMediaBlueprintRepository {
            public array $items = [];
            public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint { return $this->items[$postId . ':' . $slot] ?? null; }
            public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint { return $this->items[$blueprint->postId . ':' . $blueprint->slot] = $blueprint; }
            public function listByPost(int $postId): array { return array_values(array_filter($this->items, static fn (MediaSeoBlueprint $blueprint): bool => $blueprint->postId === $postId)); }
        };
        $inventory = new class($usages) implements ArticleMediaUsageInventory {
            public function __construct(private object $usages) {}
            public function page(string $endpointType, ?string $afterUsageId, int $limit): array
            {
                $items = array_values(array_filter($this->usages->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $endpointType));
                usort($items, static fn (MediaUsage $left, MediaUsage $right): int => $left->usageId <=> $right->usageId);
                if ($afterUsageId !== null && $afterUsageId !== '') $items = array_values(array_filter($items, static fn (MediaUsage $usage): bool => $usage->usageId > $afterUsageId));
                $page = array_slice($items, 0, $limit);
                return ['items' => $page, 'next_cursor' => count($items) > count($page) ? ($page[array_key_last($page)]->usageId ?? null) : null];
            }
        };
        return [$inventory, $media, $assets, $usages, $blueprints, new MediaService($media, $assets, $usages)];
    }
}
