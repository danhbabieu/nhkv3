<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaConsumptionService;
use NHK\Core\Application\Consumption\{OwnerCapability, OwnerConsumptionPlan};
use PHPUnit\Framework\TestCase;

final class MediaConsumptionServiceTest extends TestCase
{
    public function test_prepare_is_not_written_and_apply_requires_governed_approval(): void
    {
        $calls = 0;
        $service = new MediaConsumptionService(static function (array $request) use (&$calls): array { $calls++; return ['status' => 'COMPLETE', 'media_id' => $request['media']['id'], 'readback' => ['status' => 'verified', 'media_id' => $request['media']['id'], 'target_id' => $request['target']['id']]]; });
        $packet = $service->prepare($this->plan(), ['type' => 'classification', 'id' => 'target-1']);

        self::assertSame('PREPARED', $packet['status']);
        self::assertSame(0, $calls);
        $this->expectExceptionMessage('GOVERNANCE_APPROVAL_REQUIRED');
        $service->apply($packet);
    }

    public function test_approved_apply_requires_canonical_readback_and_reconciles_same_identity_when_uncertain(): void
    {
        $reconciled = 0;
        $service = new MediaConsumptionService(
            static fn (array $request): array => ['status' => 'COMPLETE', 'media_id' => $request['media']['id']],
            static function (array $request) use (&$reconciled): array { $reconciled++; return ['status' => 'verified', 'media_id' => $request['media']['id'], 'target_id' => $request['target']['id']]; },
        );
        $packet = array_replace($service->prepare($this->plan(), ['type' => 'classification', 'id' => 'target-1']), ['approved' => true]);
        $result = $service->apply($packet);

        self::assertSame('RECONCILED', $result['status']);
        self::assertSame(1, $reconciled);
        self::assertSame('media-1', $result['readback']['media_id']);
    }

    private function plan(): OwnerConsumptionPlan
    {
        return OwnerConsumptionPlan::forCapability(OwnerCapability::forOwner('media_image', ['caption', 'alt_text', 'description'], true, true), ['id' => 'media-1', 'type' => 'media_image'], ['caption' => 'Caption', 'alt_text' => 'Alt', 'description' => 'Description'], ['caption' => ['claim-1']], [], ['claim-1' => ['claim_id' => 'claim-1', 'claim_revision' => 2]], [], [], ['status' => 'READY']);
    }
}
