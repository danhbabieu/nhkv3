<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\PublicSlugMigrationService;
use NHK\Core\Contracts\PublicIdentity\PublicSlugMigrationSource;
use PHPUnit\Framework\TestCase;

final class PublicSlugMigrationServiceTest extends TestCase
{
    public function test_dry_run_is_deterministic_and_read_only_for_all_public_owner_kinds(): void
    {
        $writes = 0;
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array
            {
                return [
                    ['type' => 'brand', 'id' => 'brand-1', 'title' => 'NHK Ô Đô', 'current_slug' => 'nhk-o-do', 'current_url' => '/nhk-o-do/', 'scope' => 'root', 'revision' => 2, 'fingerprint' => 'fp-1', 'meaningful_context' => [], 'route_owner' => 'semantic'],
                    ['type' => 'video', 'id' => 'video-1', 'title' => 'Tuổi thọ', 'current_slug' => 'tuoi-tho-p4kahx3lbow', 'current_url' => '/video/tuoi-tho-p4kahx3lbow/', 'scope' => 'video', 'revision' => 3, 'fingerprint' => 'fp-2', 'meaningful_context' => ['external_video_id' => 'P4KaHX3LBOw'], 'route_owner' => 'semantic'],
                    ['type' => 'wp_post', 'id' => 'post-1', 'title' => 'Bài viết', 'current_slug' => 'bai-viet', 'current_url' => '/bai-viet/', 'scope' => 'wordpress', 'revision' => 1, 'fingerprint' => 'fp-3', 'meaningful_context' => [], 'route_owner' => 'wordpress'],
                ];
            }
        };
        $service = new PublicSlugMigrationService($source, static function (array $row) use (&$writes): array { $writes++; return ['status' => 'CHANGED', 'row' => $row]; });

        $first = $service->dryRun();
        $second = $service->dryRun();

        self::assertSame($first, $second);
        self::assertSame(0, $writes);
        self::assertSame(3, $first['candidate_count']);
        self::assertSame(2, $first['changed']);
        self::assertSame('nha-kho-odo', $first['rows'][0]['proposed_public_slug']);
        self::assertSame('tuoi-tho', $first['rows'][1]['proposed_public_slug']);
        self::assertSame('NOOP', $first['rows'][2]['status']);
        self::assertSame('video', $first['rows'][1]['resource_type']);
    }

    public function test_collision_resolution_is_meaningful_stable_and_fail_closed_without_discriminator(): void
    {
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array
            {
                return [
                    ['type' => 'model', 'id' => 'model-1', 'title' => 'Mẫu Chung', 'current_slug' => 'mau-chung-a', 'current_url' => '/acme/mau-chung-a/', 'scope' => 'acme', 'revision' => 1, 'fingerprint' => 'a', 'meaningful_context' => ['reference' => 'Ref A'], 'route_owner' => 'semantic'],
                    ['type' => 'model', 'id' => 'model-2', 'title' => 'Mẫu Chung', 'current_slug' => 'mau-chung-b', 'current_url' => '/acme/mau-chung-b/', 'scope' => 'acme', 'revision' => 1, 'fingerprint' => 'b', 'meaningful_context' => ['reference' => 'Ref B'], 'route_owner' => 'semantic'],
                    ['type' => 'model', 'id' => 'model-3', 'title' => 'Mẫu Chung', 'current_slug' => 'mau-chung-c', 'current_url' => '/acme/mau-chung-c/', 'scope' => 'acme', 'revision' => 1, 'fingerprint' => 'c', 'meaningful_context' => [], 'route_owner' => 'semantic'],
                    ['type' => 'model', 'id' => 'model-4', 'title' => 'Mẫu Chung', 'current_slug' => 'mau-chung-d', 'current_url' => '/acme/mau-chung-d/', 'scope' => 'acme', 'revision' => 1, 'fingerprint' => 'd', 'meaningful_context' => [], 'route_owner' => 'semantic'],
                ];
            }
        };
        $report = (new PublicSlugMigrationService($source, static fn (array $row): array => ['status' => 'CHANGED', 'row' => $row]))->dryRun();

        self::assertSame('mau-chung-ref-a', $report['rows'][0]['proposed_public_slug']);
        self::assertSame('mau-chung-ref-b', $report['rows'][1]['proposed_public_slug']);
        self::assertSame('COLLISION', $report['rows'][2]['status']);
        self::assertSame('MANUAL_REVIEW_REQUIRED', $report['rows'][2]['resolution']);
        self::assertSame(4, $report['collisions']);
        self::assertSame(2, $report['manual_review']);
    }

    public function test_apply_requires_authorization_and_rejects_stale_or_replayed_state(): void
    {
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array { return [['type' => 'brand', 'id' => 'brand-1', 'title' => 'Ô Đô', 'current_slug' => 'o-do', 'current_url' => '/o-do/', 'scope' => 'root', 'revision' => 2, 'fingerprint' => 'fp', 'meaningful_context' => [], 'route_owner' => 'semantic']]; }
        };
        $calls = 0;
        $service = new PublicSlugMigrationService($source, static function (array $row) use (&$calls): array { $calls++; return ['status' => $calls === 1 ? 'CHANGED' : 'NOOP', 'row' => $row]; });
        $dry = $service->dryRun();

        self::assertSame('AUTHORIZATION_REQUIRED', $service->apply($dry, '', 'migration-fp')['status']);
        self::assertSame('CHANGED', $service->apply($dry, 'approved', $dry['fingerprint'])['rows'][0]['status']);
        self::assertSame('NOOP', $service->apply($dry, 'approved', $dry['fingerprint'])['rows'][0]['status']);
        self::assertSame('STALE_DRY_RUN', $service->apply($dry, 'approved', 'other-fingerprint')['status']);
        self::assertSame(2, $calls);
    }

    public function test_dry_run_emits_route_owner_and_typed_blockers_without_writing(): void
    {
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array
            {
                return [
                    ['type' => 'video', 'id' => 'video-1', 'title' => 'Video', 'current_slug' => 'old', 'current_url' => '/video/old/', 'scope' => 'video', 'revision' => 1, 'fingerprint' => 'fp', 'meaningful_context' => [], 'route_owner' => 'semantic'],
                    ['type' => 'brand', 'id' => 'brand-1', 'title' => '', 'current_slug' => '', 'current_url' => '', 'scope' => 'root', 'revision' => 0, 'fingerprint' => '', 'meaningful_context' => [], 'route_owner' => 'semantic'],
                    ['type' => 'wp_post', 'id' => 'post-1', 'title' => 'Bài', 'current_slug' => 'bai', 'current_url' => '/bai/', 'scope' => 'wordpress', 'revision' => 1, 'fingerprint' => 'fp-post', 'meaningful_context' => [], 'route_owner' => 'wordpress', 'availability' => 'unavailable'],
                    ['type' => 'model', 'id' => 'model-1', 'title' => 'Mẫu', 'current_slug' => 'mau', 'current_url' => 'not-a-route', 'scope' => 'brand-1', 'revision' => 1, 'fingerprint' => 'fp-model', 'meaningful_context' => [], 'route_owner' => 'semantic', 'ambiguous' => true],
                ];
            }
        };
        $writes = 0;
        $report = (new PublicSlugMigrationService($source, static function () use (&$writes): array { $writes++; return []; }))->dryRun();

        self::assertSame(4, $report['candidate_count']);
        self::assertSame(4, $report['counts']['candidate_count']);
        self::assertSame(1, $report['counts']['ambiguous']);
        self::assertSame(1, $report['counts']['unavailable']);
        self::assertSame(1, $report['counts']['missing_identity']);
        self::assertSame(1, $report['counts']['invalid_route']);
        self::assertSame('semantic', $report['rows'][0]['route_owner']);
        self::assertSame('UNAVAILABLE', $report['rows'][3]['status']);
        self::assertSame(0, $writes);
    }

    public function test_apply_propagates_writer_readback_and_typed_failure(): void
    {
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array
            {
                return [['type' => 'brand', 'id' => 'brand-1', 'title' => 'Ô Đô', 'current_slug' => 'o-do', 'current_url' => '/o-do/', 'scope' => 'root', 'revision' => 2, 'fingerprint' => 'fp', 'meaningful_context' => [], 'route_owner' => 'semantic']];
            }
        };
        $service = new PublicSlugMigrationService($source, static fn (array $row): array => ['status' => 'STALE', 'persisted' => ['revision' => 3, 'current_slug' => 'other'], 'request' => $row]);
        $dry = $service->dryRun();

        $result = $service->apply($dry, 'approved', $dry['fingerprint']);

        self::assertSame('PARTIAL_FAILURE', $result['status']);
        self::assertSame('STALE', $result['rows'][0]['status']);
        self::assertSame(['revision' => 3, 'current_slug' => 'other'], $result['rows'][0]['persisted']);
    }

    public function test_resolved_collision_is_noop_when_persisted_slug_already_matches(): void
    {
        $source = new class implements PublicSlugMigrationSource {
            public function candidates(): array
            {
                return [
                    ['type' => 'brand', 'id' => 'a', 'title' => 'Trùng', 'current_slug' => 'trung', 'current_url' => '/trung/', 'scope' => 'root', 'revision' => 1, 'fingerprint' => 'a', 'meaningful_context' => ['country' => 'Việt Nam']],
                    ['type' => 'brand', 'id' => 'b', 'title' => 'Trùng', 'current_slug' => 'trung-nhat-ban', 'current_url' => '/trung-nhat-ban/', 'scope' => 'root', 'revision' => 2, 'fingerprint' => 'b', 'meaningful_context' => ['country' => 'Nhật Bản']],
                ];
            }
        };

        $report = (new PublicSlugMigrationService($source, static function (): array { throw new \LogicException('NO_WRITE_EXPECTED'); }))->dryRun();

        self::assertSame(0, $report['changed']);
        self::assertSame(2, $report['no_op']);
        self::assertSame('NOOP', $report['rows'][1]['status']);
        self::assertFalse($report['rows'][1]['changed']);
    }
}
