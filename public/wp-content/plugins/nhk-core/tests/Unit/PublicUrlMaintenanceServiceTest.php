<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\PublicUrlMaintenanceService;
use PHPUnit\Framework\TestCase;

final class PublicUrlMaintenanceServiceTest extends TestCase
{
    private const OWNER_CLOCK_TYPE = '01a07614-832d-7f27-959c-74eb0cd63f3e';
    private const OWNER_BRAND = '01a07614-832d-7f27-959c-74eb0cd63f4e';

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

    public function test_media_keep_is_distinguished_from_binary_delivery_evidence(): void
    {
        $service = new PublicUrlMaintenanceService(
            static fn(): array => [[
                'kind' => 'media_asset', 'owner_id' => 'asset-1', 'route_type' => 'media_image', 'scope' => 'root', 'name' => 'Ảnh ví dụ', 'current_slug' => 'anh-vi-du', 'qualifiers' => [],
            ]],
            static fn(array $item, string $slug): bool => false,
            static function(array $item, string $key): void {},
            deliveryVerifier: static fn(array $item): array => ['status' => 'PASS', 'reason_code' => 'BINARY_DELIVERY_PASS'],
        );

        $item = $service->audit()['items'][0];

        self::assertSame('KEEP', $item['action']);
        self::assertSame('URL_IDENTITY_KEEP', $item['url_identity_status']);
        self::assertSame('BINARY_DELIVERY_PASS', $item['binary_delivery']['reason_code']);
    }

    public function test_scoped_audit_only_plans_one_owner_even_when_global_audit_is_blocked(): void
    {
        $inventory = static fn(): array => [
            ['kind' => 'authority', 'owner_id' => self::OWNER_BRAND, 'route_type' => 'brand', 'scope' => 'root', 'name' => 'Trùng', 'current_slug' => 'old-brand', 'qualifiers' => []],
            ['kind' => 'authority', 'owner_id' => self::OWNER_CLOCK_TYPE, 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => '', 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root'],
            ['kind' => 'authority', 'owner_id' => '01a07614-832d-7f27-959c-74eb0cd63f4f', 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => 'old-clock', 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root'],
        ];
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, static function(): void {});

        self::assertSame('BLOCKED', $service->audit()['status']);
        $scoped = $service->audit(self::OWNER_CLOCK_TYPE);

        self::assertSame('READY', $scoped['status']);
        self::assertSame(1, $scoped['counts']['total']);
        self::assertSame(self::OWNER_CLOCK_TYPE, $scoped['items'][0]['owner_id']);
        self::assertSame('dong-ho-chim-cuc-cu', $scoped['items'][0]['desired_slug']);
    }

    public function test_scoped_reproject_changes_exactly_one_owner_and_returns_canonical_evidence(): void
    {
        $state = [
            self::OWNER_CLOCK_TYPE => ['slug' => '', 'path' => '', 'revision' => 0, 'identity_id' => ''],
            self::OWNER_BRAND => ['slug' => 'old-brand', 'path' => '/old-brand/', 'revision' => 2, 'identity_id' => 'identity-brand'],
        ];
        $writes = [];
        $inventory = static function() use (&$state): array {
            return [
                ['kind' => 'authority', 'owner_id' => self::OWNER_CLOCK_TYPE, 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => $state[self::OWNER_CLOCK_TYPE]['slug'], 'current_path' => $state[self::OWNER_CLOCK_TYPE]['path'], 'identity_id' => $state[self::OWNER_CLOCK_TYPE]['identity_id'], 'revision' => $state[self::OWNER_CLOCK_TYPE]['revision'], 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root'],
                ['kind' => 'authority', 'owner_id' => self::OWNER_BRAND, 'route_type' => 'brand', 'scope' => 'root', 'name' => 'Odo', 'current_slug' => $state[self::OWNER_BRAND]['slug'], 'current_path' => $state[self::OWNER_BRAND]['path'], 'identity_id' => $state[self::OWNER_BRAND]['identity_id'], 'revision' => $state[self::OWNER_BRAND]['revision'], 'qualifiers' => []],
            ];
        };
        $apply = static function(array $item, string $key) use (&$state, &$writes): void {
            $writes[] = [$item['owner_id'], $key];
            $state[$item['owner_id']]['slug'] = (string) $item['desired_slug'];
            $state[$item['owner_id']]['path'] = '/' . $state[$item['owner_id']]['slug'] . '/';
            $state[$item['owner_id']]['revision']++;
            $state[$item['owner_id']]['identity_id'] = 'identity-clock';
        };
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, $apply);

        $result = $service->reproject('clock-reproject-1', true, self::OWNER_CLOCK_TYPE);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame('ALLOCATE', $result['action']);
        self::assertSame(self::OWNER_CLOCK_TYPE, $result['owner_id']);
        self::assertSame('', $result['previous_slug']);
        self::assertSame('dong-ho-chim-cuc-cu', $result['final_slug']);
        self::assertSame('/dong-ho-chim-cuc-cu/', $result['final_path']);
        self::assertSame('identity-clock', $result['identity_id']);
        self::assertSame(1, $result['revision']);
        self::assertSame('clock-reproject-1', $result['idempotency_key']);
        self::assertTrue(is_array($result['canonical_read_back']));
        self::assertSame([[self::OWNER_CLOCK_TYPE, 'clock-reproject-1']], $writes);
        self::assertSame('old-brand', $state[self::OWNER_BRAND]['slug']);
    }

    public function test_scoped_owner_validation_and_blockers_fail_closed_without_writes(): void
    {
        $writes = 0;
        $service = new PublicUrlMaintenanceService(static fn(): array => [[
            'kind' => 'authority', 'owner_id' => self::OWNER_CLOCK_TYPE, 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => '', 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root',
        ]], static fn(array $item, string $slug): bool => $slug === 'dong-ho-chim-cuc-cu', static function() use (&$writes): void { $writes++; });

        self::assertSame('PUBLIC_URL_OWNER_REQUIRED', $service->reproject('missing-owner', true, '')['reason_code']);
        self::assertSame('PUBLIC_URL_OWNER_INVALID', $service->audit('not-a-uuid')['reason_code']);
        self::assertSame('PUBLIC_URL_OWNER_NOT_FOUND', $service->audit(self::OWNER_BRAND)['reason_code']);
        self::assertSame('COLLISION_REQUIRES_RECONCILIATION', $service->reproject('collision-1', true, self::OWNER_CLOCK_TYPE)['items'][0]['blocker']);
        self::assertSame(0, $writes);
    }

    public function test_scoped_decision_staleness_blocks_before_apply(): void
    {
        $auditCalls = 0;
        $writes = 0;
        $inventory = static function() use (&$auditCalls): array {
            $auditCalls++;
            return [[
                'kind' => 'authority', 'owner_id' => self::OWNER_CLOCK_TYPE, 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => $auditCalls === 1 ? '' : 'changed-by-concurrent-owner', 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root',
            ]];
        };
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $slug): bool => false, static function() use (&$writes): void { $writes++; });

        $result = $service->reproject('stale-decision-1', true, self::OWNER_CLOCK_TYPE);

        self::assertSame('BLOCKED', $result['status']);
        self::assertSame('PUBLIC_URL_DECISION_STALE', $result['reason_code']);
        self::assertSame(0, $writes);
    }

    public function test_repeating_scoped_idempotency_after_readback_is_safe_and_does_not_rewrite(): void
    {
        $slug = '';
        $writes = 0;
        $inventory = static function() use (&$slug, &$writes): array { return [[
            'kind' => 'authority', 'owner_id' => self::OWNER_CLOCK_TYPE, 'route_type' => 'classification', 'scope' => 'root', 'name' => 'Đồng hồ chim cúc cu', 'current_slug' => $slug, 'current_path' => $slug === '' ? '' : '/' . $slug . '/', 'revision' => $writes, 'identity_id' => $writes === 0 ? '' : 'identity-clock', 'qualifiers' => [], 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ ', 'route_namespace' => 'root',
        ]]; };
        $service = new PublicUrlMaintenanceService($inventory, static fn(array $item, string $candidate): bool => false, static function(array $item, string $key) use (&$slug, &$writes): void { $slug = (string) $item['desired_slug']; $writes++; });

        self::assertSame('APPLIED', $service->reproject('same-key', true, self::OWNER_CLOCK_TYPE)['status']);
        self::assertSame('APPLIED', $service->reproject('same-key', true, self::OWNER_CLOCK_TYPE)['status']);
        self::assertSame(1, $writes);
    }
}
