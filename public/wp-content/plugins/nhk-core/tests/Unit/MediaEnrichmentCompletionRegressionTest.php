<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Media\MediaEnrichmentCompletionPolicy;
use PHPUnit\Framework\TestCase;

final class MediaEnrichmentCompletionRegressionTest extends TestCase
{
    public function test_verified_media_usage_readback_survives_final_policy_and_capture_completion(): void
    {
        $mediaId = '01a0d3d5-3ded-7553-87c8-eeed409465a1';
        $targetId = 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a';
        $usageId = '70700000-0000-7000-8000-000000000001';
        $binding = [
            'status' => 'COMPLETE',
            'media_id' => $mediaId,
            'readback' => [
                'status' => 'verified',
                'media_id' => $mediaId,
                'target_type' => 'model',
                'target_id' => $targetId,
                'usage_id' => $usageId,
                'role' => 'representative',
            ],
        ];
        $policy = new MediaEnrichmentCompletionPolicy(
            static fn (string $type, string $id): array => [
                'projection_required' => true,
                'public_required' => $type === 'model' && $id === $targetId,
                'frontend_required' => $type === 'model' && $id === $targetId,
            ],
            static fn (string $type, string $id, string $actualMediaId, string $role): array => [
                'status' => 'verified',
                'media_id' => $actualMediaId,
            ],
            static fn (string $type, string $id, string $actualMediaId, string $role): array => [
                'status' => 'verified',
                'media_id' => $actualMediaId,
                'route' => '/grand-seiko/model-odo-30/',
            ],
        );

        $final = $policy->verify(['bindings' => [$binding]]);
        self::assertSame('verified', $final['status']);

        $coordinator = new CompletionCoordinator(static fn (string $type): ?array => $type === 'model'
            ? ['public_capable' => true]
            : null);
        $completion = $coordinator->aggregateCapture('01a0d3d5-b2c4-7a0f-a261-39c7cef8f0ee', [
            [
                'owner_type' => 'model',
                'owner_id' => $targetId,
                'canonical_readback' => [
                    'canonical_id' => $targetId,
                    'media_id' => $mediaId,
                    'usage_id' => $usageId,
                    'status' => 'verified',
                ],
                'relation_or_usage_state' => 'COMPLETE',
                'public_state' => 'READY',
                'frontend_state' => 'VERIFIED',
            ],
            [
                'owner_type' => 'media',
                'owner_id' => $mediaId,
                'canonical_readback' => ['id' => $mediaId],
                'relation_or_usage_state' => 'COMPLETE',
                'public_projection_owner' => false,
                'owner_role' => 'semantic_dependency',
            ],
        ], [
            'canonical_readback' => ['canonical_id' => '01a0d3d5-b2c4-7a0f-a261-39c7cef8f0ee'],
            'required_owners' => [
                ['owner_type' => 'model', 'owner_id' => $targetId],
                ['owner_type' => 'media', 'owner_id' => $mediaId],
            ],
        ]);

        self::assertTrue($completion['canonical_readback_verified']);
        self::assertSame('COMPLETE', $completion['relation_or_usage_state']);
        self::assertSame('READY', $completion['public_state']);
        self::assertSame('VERIFIED', $completion['frontend_state']);
        self::assertTrue($completion['complete']);
        self::assertNotContains('CANONICAL_READBACK_UNVERIFIED', $completion['blockers']);
    }
}
