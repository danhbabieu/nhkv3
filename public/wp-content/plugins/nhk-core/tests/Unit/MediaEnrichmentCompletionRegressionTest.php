<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Media\MediaEnrichmentCompletionPolicy;
use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Domain\Capture\CaptureRecord;
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

    public function test_completion_children_preserve_target_capability_evidence_from_final_readback(): void
    {
        $mediaId = '01a0d3d5-3ded-7553-87c8-eeed409465a1';
        $targetId = 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a';
        $capture = new CaptureRecord(
            '01a0d3d5-b2c4-7a0f-a261-39c7cef8f0ee',
            'media-enrichment-retry',
            hash('sha256', 'media-enrichment-retry'),
            'MEDIA_RECONCILED',
            'IN_PROGRESS',
        );
        $coordinator = (new \ReflectionClass(EditorialCaptureCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'completionChildren');
        $method->setAccessible(true);
        $children = $method->invoke($coordinator, $capture, [], [
            'status' => 'RECONCILED',
            'media_ids' => [$mediaId],
            'media_complete' => true,
            'bindings' => [[
                'status' => 'COMPLETE',
                'readback' => [
                    'status' => 'verified',
                    'media_id' => $mediaId,
                    'target_type' => 'model',
                    'target_id' => $targetId,
                    'usage_id' => '70700000-0000-7000-8000-000000000001',
                ],
            ]],
        ], [], [], [
            'status' => 'verified',
            'public' => [['status' => 'verified', 'target_type' => 'model', 'target_id' => $targetId, 'media_id' => $mediaId]],
            'frontend' => [['status' => 'verified', 'target_type' => 'model', 'target_id' => $targetId, 'media_id' => $mediaId]],
        ], false);

        $modelChild = array_values(array_filter($children, static fn (array $child): bool => ($child['owner_type'] ?? '') === 'model'))[0] ?? [];
        self::assertSame($targetId, $modelChild['owner_id'] ?? null);
        self::assertSame('COMPLETE', $modelChild['relation_or_usage_state'] ?? null);
        self::assertTrue($modelChild['public_eligible'] ?? false);
        self::assertTrue($modelChild['frontend_verified'] ?? false);
    }

    public function test_old_media_enrichment_retry_rehydrates_persisted_bindings_without_changing_capture_identity(): void
    {
        $captureId = '01a0d3d5-b2c4-7a0f-a261-39c7cef8f0ee';
        $binding = [
            'selection_source' => 'USER_EXPLICIT',
            'target' => ['type' => 'model', 'id' => 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a'],
            'media_id' => '01a0d3d5-3ded-7553-87c8-eeed409465a1',
        ];
        $capture = new CaptureRecord(
            $captureId,
            'media-enrichment-old-retry',
            hash('sha256', 'media-enrichment-old-retry'),
            'FINAL_READBACK',
            'FAILED_RETRYABLE',
            null,
            null,
            [['media_id' => $binding['media_id']]],
            [
                'raw_input' => 'Media enrichment retry.',
                'content_intent' => ['intent' => 'MEDIA_ENRICHMENT'],
                'media_bindings' => [$binding],
                'media_operations' => [],
            ],
            ['failure' => ['code' => 'CAPTURE_FINAL_READBACK_UNAVAILABLE']],
            [],
        );
        $coordinator = (new \ReflectionClass(EditorialCaptureCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'rehydrateRetryInput');
        $method->setAccessible(true);

        $retryInput = $method->invoke($coordinator, $capture, ['existing_capture_retry' => true]);

        self::assertSame($captureId, $capture->captureId);
        self::assertSame([$binding], $retryInput['media_bindings']);
        self::assertSame([], $retryInput['media_operations']);
    }
}
