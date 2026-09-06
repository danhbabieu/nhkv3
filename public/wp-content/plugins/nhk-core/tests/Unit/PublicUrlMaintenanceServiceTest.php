<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\PublicUrlMaintenanceService;
use PHPUnit\Framework\TestCase;

final class PublicUrlMaintenanceServiceTest extends TestCase
{
    public function test_audit_is_read_only_and_reports_changes(): void
    {
        $writes = [];
        $inventory = static fn(): array => [[
            'kind'=>'video','owner_id'=>'v1','route_type'=>'video','scope'=>'root','name'=>'NHK tuổi','current_slug'=>'nhk-tu-i','qualifiers'=>[],
        ]];
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, static function(array $item, string $key) use (&$writes): void { $writes[] = [$item,$key]; });

        $audit = $service->audit();

        self::assertSame('READY', $audit['status']);
        self::assertSame('nha-kho-tuoi', $audit['items'][0]['desired_slug']);
        self::assertSame([], $writes);
    }

    public function test_reproject_requires_explicit_pre_public_confirmation(): void
    {
        $service = new PublicUrlMaintenanceService(static fn(): array => [], static fn(array $item, string $slug): bool => false, static fn(array $item, string $key) => null);
        self::assertSame('PRE_PUBLIC_CONFIRMATION_REQUIRED', $service->reproject('run-1', false)['reason_code']);
    }

    public function test_reproject_applies_only_ready_plan_and_requires_readback_keep(): void
    {
        $current = 'legacy';
        $writes = [];
        $inventory = static function() use (&$current): array { return [[
            'kind'=>'authority','owner_id'=>'a1','route_type'=>'movement','scope'=>'root','name'=>'Bộ máy ư ơ đ','current_slug'=>$current,'qualifiers'=>[],
        ]]; };
        $apply = static function(array $item, string $key) use (&$current, &$writes): void {
            $writes[] = $key;
            $current = (string)$item['desired_slug'];
        };
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, $apply);

        $result = $service->reproject('run-2', true);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['run-2:0'], $writes);
        self::assertSame('bo-may-u-o-d', $current);
        self::assertSame(0, $result['readback']['counts']['change']);
    }

    public function test_blocked_collision_performs_zero_writes(): void
    {
        $writes = 0;
        $inventory = static fn(): array => [
            ['kind'=>'video','owner_id'=>'v1','route_type'=>'video','scope'=>'root','name'=>'Trùng','current_slug'=>'a','qualifiers'=>[]],
            ['kind'=>'video','owner_id'=>'v2','route_type'=>'video','scope'=>'root','name'=>'Trùng','current_slug'=>'b','qualifiers'=>[]],
        ];
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, static function() use (&$writes): void { $writes++; });

        $result = $service->reproject('run-3', true);

        self::assertSame('BLOCKED', $result['status']);
        self::assertSame(0, $writes);
    }
}
