<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaEnrichmentFinalReadbackPolicy;
use PHPUnit\Framework\TestCase;

final class MediaEnrichmentFinalReadbackPolicyTest extends TestCase
{
    public function test_verified_media_usage_is_sufficient_without_article_or_frontend_projection(): void
    {
        $result = (new MediaEnrichmentFinalReadbackPolicy())->verify([
            'status' => 'RECONCILED',
            'media_ids' => ['fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a'],
            'bindings' => [[
                'status' => 'COMPLETE',
                'media_id' => 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a',
                'readback' => [
                    'status' => 'verified',
                    'media_id' => 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a',
                    'usage_id' => '01a0d3d5-3ded-7553-87c8-eeed409465a1',
                    'target_type' => 'model',
                    'target_id' => '01a0d3d5-b2c4-7a0f-a261-39c7cef8f0ee',
                    'role' => 'representative',
                ],
            ]],
        ]);

        self::assertSame('verified', $result['status']);
        self::assertSame('MediaUsage', $result['canonical_owner']);
        self::assertNull($result['frontend_verified']);
        self::assertSame('NOT_REQUIRED', $result['article_owner']);
    }

    public function test_unverified_media_usage_remains_fail_closed(): void
    {
        $result = (new MediaEnrichmentFinalReadbackPolicy())->verify([
            'status' => 'RECONCILED',
            'media_ids' => ['media-1'],
            'bindings' => [[
                'status' => 'COMPLETE',
                'media_id' => 'media-1',
                'readback' => ['status' => 'pending', 'usage_id' => 'usage-1'],
            ]],
        ]);

        self::assertSame('unavailable', $result['status']);
        self::assertSame('MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED', $result['reason']);
    }
}
