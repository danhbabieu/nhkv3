<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\CaptureFeatureBindingCoordinator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Media\MediaBindingPort;
use PHPUnit\Framework\TestCase;

final class CaptureFeatureBindingCoordinatorTest extends TestCase
{
    public function test_empty_feature_requests_are_explicitly_skipped_without_resolution(): void
    {
        $resolutionCalls = 0;
        $resolver = new SubjectResolutionService(static function () use (&$resolutionCalls): array {
            $resolutionCalls++;
            return [];
        });

        $result = (new CaptureFeatureBindingCoordinator($resolver, new CaptureFeatureBindingTestPort()))->execute([
            ['media_id' => 'media-empty-feature', 'upload_status' => 'REUSED', 'capture_asset_input' => ['feature_requests' => []]],
        ], 'capture-empty-feature');

        self::assertSame([], $result['feature_results']);
        self::assertSame('NOT_REQUESTED', $result['status']);
        self::assertSame('COMPLETE', $result['assets'][0]['disposition']);
        self::assertSame(0, $resolutionCalls);
    }

    public function test_resolves_each_feature_independently_and_binds_multiple_targets(): void
    {
        $first = '11111111-1111-4111-8111-111111111111';
        $second = '22222222-2222-4222-8222-222222222222';
        $port = new CaptureFeatureBindingTestPort();
        $resolver = new SubjectResolutionService(static fn (string $value): array => match ($value) {
            'Exact' => [['id' => $first, 'type' => 'model', 'name' => 'Exact', 'match' => 'exact_name_or_alias']],
            'Second' => [['id' => $second, 'type' => 'variant', 'name' => 'Second', 'match' => 'stable_key_exact']],
            'Ambiguous' => [['id' => $first, 'type' => 'model', 'name' => 'A'], ['id' => $second, 'type' => 'model', 'name' => 'B']],
            default => [],
        });
        $result = (new CaptureFeatureBindingCoordinator($resolver, $port))->execute([
            ['media_id' => 'media-a', 'upload_status' => 'REUSED', 'capture_asset_input' => ['feature_requests' => ['Exact', 'Ambiguous', 'Second']]],
        ], 'capture-1');

        self::assertSame(['COMPLETE', 'NEEDS_REVIEW', 'COMPLETE'], array_column($result['feature_results'], 'disposition'));
        self::assertCount(2, $port->requests);
        self::assertSame(['model', 'variant'], array_column($port->requests, 'target_type'));
        self::assertSame('PARTIAL', $result['assets'][0]['disposition']);
    }

    public function test_failed_binding_isolated_and_replay_key_is_stable(): void
    {
        $id = '33333333-3333-4333-8333-333333333333';
        $port = new CaptureFeatureBindingTestPort(true);
        $resolver = new SubjectResolutionService(static fn (string $value): array => [['id' => $id, 'type' => 'model', 'name' => $value, 'match' => 'uuid_exact']]);
        $coordinator = new CaptureFeatureBindingCoordinator($resolver, $port);
        $assets = [['media_id' => 'media-a', 'upload_status' => 'REUSED', 'capture_asset_input' => ['feature_requests' => ['one', 'two']]]];
        $first = $coordinator->execute($assets, 'capture-retry');
        $second = $coordinator->execute($assets, 'capture-retry');

        self::assertSame('FAILED_RETRYABLE', $first['feature_results'][0]['disposition']);
        self::assertSame('COMPLETE', $first['feature_results'][1]['disposition']);
        self::assertSame($first['feature_results'][1]['usage_id'], $second['feature_results'][1]['usage_id']);
        self::assertSame($port->requests[1]['idempotency_key'], $port->requests[3]['idempotency_key']);
    }
}

final class CaptureFeatureBindingTestPort implements MediaBindingPort
{
    public array $requests = [];
    public function __construct(private bool $failFirst = false) {}
    public function bindMany(array $bindings, string $idempotencyKey, array $assets = [], array $context = []): array
    {
        $binding = $bindings[0];
        $this->requests[] = ['idempotency_key' => $binding['idempotency_key'], 'target_type' => $binding['target']['type']];
        if ($this->failFirst && count($this->requests) % 2 === 1) throw new \RuntimeException('TRANSIENT_BINDING_FAILURE');
        $usageId = 'usage-' . substr(hash('sha256', (string) $binding['idempotency_key']), 0, 12);
        return ['status' => 'COMPLETE', 'bindings' => [['status' => 'COMPLETE', 'readback' => ['status' => 'verified', 'media_id' => 'media-a', 'usage_id' => $usageId]]]];
    }
}
