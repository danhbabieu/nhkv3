<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaEnrichmentCompletionPolicy;
use PHPUnit\Framework\TestCase;

final class MediaEnrichmentCompletionPolicyTest extends TestCase
{
    private function policy(array $projection, array $public = []): MediaEnrichmentCompletionPolicy
    {
        return new MediaEnrichmentCompletionPolicy(
            static fn (string $type, string $id): array => ['projection_required' => true, 'public_required' => $type === 'model'],
            static fn (string $type, string $id, string $media, string $role): array => $projection,
            static fn (string $type, string $id, string $media, string $role): array => $public,
        );
    }

    private function receipt(): array
    {
        return ['status' => 'COMPLETE', 'readback' => ['status' => 'verified', 'media_id' => 'media-1', 'usage_id' => 'usage-1', 'target_type' => 'model', 'target_id' => 'model-1', 'role' => 'representative']];
    }

    public function test_canonical_verified_projection_and_public_surface_are_complete(): void
    {
        $result = $this->policy(['status' => 'verified', 'media_id' => 'media-1'], ['status' => 'verified', 'media_id' => 'media-1'])->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('verified', $result['status']);
        self::assertSame('CANONICAL_COMPLETE', $result['canonical_status']);
        self::assertSame('PROJECTED_COMPLETE', $result['projected_status']);
        self::assertSame('PUBLIC_COMPLETE', $result['completion']);
    }

    public function test_projection_failure_is_retryable_not_complete(): void
    {
        $result = $this->policy(['status' => 'stale', 'media_id' => 'other'])->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('unavailable', $result['status']);
        self::assertSame('PROJECTION_READBACK_STALE', $result['reason']);
    }

    public function test_public_surface_stale_is_retryable_not_complete(): void
    {
        $result = $this->policy(['status' => 'verified', 'media_id' => 'media-1'], ['status' => 'stale', 'media_id' => 'other'])->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('unavailable', $result['status']);
        self::assertSame('PUBLIC_SURFACE_READBACK_STALE', $result['reason']);
    }

    public function test_non_public_owner_does_not_require_frontend(): void
    {
        $policy = new MediaEnrichmentCompletionPolicy(
            static fn (): array => ['projection_required' => true, 'public_required' => false],
            static fn (): array => ['status' => 'verified', 'media_id' => 'media-1'],
            static fn (): array => ['status' => 'stale', 'media_id' => 'other'],
        );
        $result = $policy->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('verified', $result['status']);
        self::assertSame([], $result['public']);
    }

    public function test_canonical_only_owner_is_complete_without_projection_or_frontend_gate(): void
    {
        $policy = new MediaEnrichmentCompletionPolicy(
            static fn (): array => ['projection_required' => false, 'public_required' => false, 'frontend_required' => false],
            static fn (): array => ['status' => 'stale', 'media_id' => 'other'],
            static fn (): array => ['status' => 'stale', 'media_id' => 'other'],
        );
        $result = $policy->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('verified', $result['status']);
        self::assertSame([], $result['public']);
    }

    public function test_frontend_gate_is_distinct_and_fails_closed(): void
    {
        $policy = new MediaEnrichmentCompletionPolicy(
            static fn (): array => ['projection_required' => true, 'public_required' => false, 'frontend_required' => true],
            static fn (): array => ['status' => 'verified', 'media_id' => 'media-1'],
            static fn (): array => ['status' => 'stale', 'media_id' => 'other'],
        );
        $result = $policy->verify(['bindings' => [$this->receipt()]]);
        self::assertSame('unavailable', $result['status']);
        self::assertSame('FRONTEND_READBACK_STALE', $result['reason']);
    }
}
