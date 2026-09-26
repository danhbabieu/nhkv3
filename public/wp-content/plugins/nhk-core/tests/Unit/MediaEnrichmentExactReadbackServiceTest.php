<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaEnrichmentExactReadbackService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\MediaUsage;
use PHPUnit\Framework\TestCase;

final class MediaEnrichmentExactReadbackServiceTest extends TestCase
{
    private const MEDIA_A = '018f2f1e-7b2c-7abc-8def-0123456789aa';
    private const MEDIA_B = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const USAGE_OLD = '018f2f1e-7b2c-7abc-8def-0123456789ac';
    private const USAGE_NEW = '018f2f1e-7b2c-7abc-8def-0123456789ad';

    public function test_governance_failure_never_reports_complete(): void
    {
        $service = $this->service([
            $this->usage(self::USAGE_NEW, self::MEDIA_A, 2),
        ]);
        $result = $service->verify([
            $this->operation('add', self::MEDIA_A, '', 0),
        ], [['status' => 'blocked', 'blockers' => ['CAS_CONFLICT']]]);

        self::assertFalse($result['complete']);
        self::assertContains('GOVERNANCE_OPERATION_BLOCKED', $result['blockers']);
    }

    public function test_add_returns_actual_usage_uuid_and_featured_projection(): void
    {
        $service = $this->service([
            $this->usage(self::USAGE_NEW, self::MEDIA_A, 1),
        ], self::MEDIA_A);
        $result = $service->verify([
            $this->operation('add', self::MEDIA_A, '', 0),
        ], [['status' => 'applied', 'canonical_readback' => ['canonical_id' => self::MEDIA_A]]]);

        self::assertTrue($result['complete']);
        self::assertSame(self::USAGE_NEW, $result['media_usage'][0]['usage_id']);
        self::assertSame(1, $result['media_usage'][0]['revision']);
    }

    public function test_replace_reports_retired_old_usage_and_active_new_usage(): void
    {
        $service = $this->service([
            $this->usage(self::USAGE_OLD, self::MEDIA_B, 2, 'retired'),
            $this->usage(self::USAGE_NEW, self::MEDIA_A, 1),
            $this->usage('018f2f1e-7b2c-7abc-8def-0123456789ae', self::MEDIA_B, 1, null, 'inline_primary', 'inline'),
        ], self::MEDIA_A);
        $result = $service->verify([
            $this->operation('replace', self::MEDIA_A, self::USAGE_OLD, 1),
        ], [['status' => 'applied', 'canonical_readback' => ['canonical_id' => self::MEDIA_A]]]);

        self::assertTrue($result['complete']);
        self::assertSame(self::USAGE_NEW, $result['media_usage'][0]['usage_id']);
        self::assertSame('retired', $result['media_usage'][0]['replaced_usage']['active_slot']);
        self::assertCount(1, $result['media_usage']);
    }

    public function test_keep_requires_fresh_matching_revision(): void
    {
        $service = $this->service([$this->usage(self::USAGE_OLD, self::MEDIA_A, 3)], self::MEDIA_A);
        $result = $service->verify([
            $this->operation('keep', self::MEDIA_A, self::USAGE_OLD, 3),
        ], []);

        self::assertTrue($result['complete']);
        self::assertSame('KEEP', $result['media_usage'][0]['operation']);
    }

    public function test_keep_drift_is_not_complete(): void
    {
        $service = $this->service([$this->usage(self::USAGE_NEW, self::MEDIA_B, 4)]);
        $result = $service->verify([
            $this->operation('keep', self::MEDIA_A, self::USAGE_OLD, 3),
        ], []);

        self::assertFalse($result['complete']);
        self::assertContains('MEDIA_USAGE_CAS_CONFLICT', $result['blockers']);
    }

    public function test_featured_projection_mismatch_is_not_complete(): void
    {
        $service = $this->service([$this->usage(self::USAGE_NEW, self::MEDIA_A, 1)], self::MEDIA_B);
        $result = $service->verify([
            $this->operation('add', self::MEDIA_A, '', 0),
        ], [['status' => 'applied', 'canonical_readback' => ['canonical_id' => self::MEDIA_A]]]);

        self::assertFalse($result['complete']);
        self::assertContains('FEATURED_PROJECTION_READBACK_MISMATCH', $result['blockers']);
    }

    /** @param list<MediaUsage> $usages */
    private function service(array $usages, ?string $featuredMedia = null): MediaEnrichmentExactReadbackService
    {
        $repository = new class($usages) implements MediaUsageRepository {
            public function __construct(private array $usages) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array
            {
                return array_values(array_filter($this->usages, static fn (MediaUsage $usage): bool => $usage->endpointType === $endpointType && $usage->endpointKey === $endpointKey && ($role === null || $usage->role === $role)));
            }
        };
        $adapter = new class($featuredMedia) implements WordPressArticleMediaAdapter {
            public function __construct(private ?string $featuredMedia) {}
            public function read(int $postId): array { return ['featured_media_id' => $this->featuredMedia, 'inline_media_ids' => [], 'managed_inline_media_id' => null, 'featured_attachment_id' => 1, 'inline_attachment_ids' => [], 'content' => '']; }
            public function synchronize(int $postId, array $result): array { return []; }
            public function attachmentForMedia(\NHK\Core\Domain\Media\Media $media, \NHK\Core\Domain\Media\MediaAsset $asset, string $contextualAlt = '', array $context = []): array { return []; }
            public function adoptAttachment(int $attachmentId): ?string { return null; }
        };
        return new MediaEnrichmentExactReadbackService($repository, $adapter);
    }

    private function operation(string $operation, string $mediaId, string $usageId, int $revision): array
    {
        return ['operation' => $operation, 'media' => ['id' => $mediaId], 'usage_id' => $usageId, 'expected_usage_revision' => $revision, 'target' => ['type' => 'wp_post', 'id' => '1:18'], 'role' => 'featured_primary', 'placement_key' => 'featured_primary'];
    }

    private function usage(string $usageId, string $mediaId, int $revision, ?string $activeSlot = null, string $role = 'featured_primary', string $placement = 'featured_primary'): MediaUsage
    {
        return new MediaUsage($usageId, $mediaId, 'wp_post', '1:18', $role, revision: $revision, placementKey: $placement, activeSlot: $activeSlot);
    }
}
