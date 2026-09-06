<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\PublicIdentity\PublicUrlReprojectionPlanner;
use PHPUnit\Framework\TestCase;

final class PublicUrlReprojectionPlannerTest extends TestCase
{
    public function test_plan_uses_short_base_until_real_collision_then_meaningful_qualifier(): void
    {
        $items = [
            ['kind'=>'authority','owner_id'=>'b','route_type'=>'movement','scope'=>'root','name'=>'Bộ máy thử','current_slug'=>'bo-may-thu','qualifiers'=>['caliber-b']],
            ['kind'=>'authority','owner_id'=>'a','route_type'=>'movement','scope'=>'root','name'=>'Bộ máy thử','current_slug'=>'legacy-a','qualifiers'=>['caliber-a']],
        ];
        $plan = (new PublicUrlReprojectionPlanner())->plan($items, static fn(array $item, string $slug): bool => false);

        self::assertSame('READY', $plan['status']);
        self::assertSame('bo-may-thu', $plan['items'][0]['desired_slug']);
        self::assertSame('bo-may-thu-caliber-b', $plan['items'][1]['desired_slug']);
        self::assertSame('CHANGE', $plan['items'][0]['action']);
        self::assertSame('CHANGE', $plan['items'][1]['action']);
    }

    public function test_parent_scope_prevents_false_collision_and_nhk_is_public_only_token_expansion(): void
    {
        $items = [
            ['kind'=>'authority','owner_id'=>'m1','route_type'=>'model','scope'=>'brand:11111111-1111-4111-8111-111111111111','name'=>'NHK Mẫu tuổi trẻ','current_slug'=>'','qualifiers'=>[]],
            ['kind'=>'authority','owner_id'=>'m2','route_type'=>'model','scope'=>'brand:22222222-2222-4222-8222-222222222222','name'=>'NHK Mẫu tuổi trẻ','current_slug'=>'','qualifiers'=>[]],
        ];
        $plan = (new PublicUrlReprojectionPlanner())->plan($items, static fn(array $item, string $slug): bool => false);

        self::assertSame('READY', $plan['status']);
        self::assertSame('nha-kho-mau-tuoi-tre', $plan['items'][0]['desired_slug']);
        self::assertSame('nha-kho-mau-tuoi-tre', $plan['items'][1]['desired_slug']);
    }

    public function test_unresolvable_collision_is_blocked_instead_of_using_hash_timestamp_or_external_id(): void
    {
        $items = [
            ['kind'=>'video','owner_id'=>'v1','route_type'=>'video','scope'=>'root','name'=>'Cùng tên','current_slug'=>'','qualifiers'=>[],'technical_ids'=>['P4KaHX3LBOw']],
            ['kind'=>'video','owner_id'=>'v2','route_type'=>'video','scope'=>'root','name'=>'Cùng tên','current_slug'=>'','qualifiers'=>[],'technical_ids'=>['dQw4w9WgXcQ']],
        ];
        $plan = (new PublicUrlReprojectionPlanner())->plan($items, static fn(array $item, string $slug): bool => false);

        self::assertSame('BLOCKED', $plan['status']);
        self::assertSame('COLLISION_REQUIRES_RECONCILIATION', $plan['items'][1]['blocker']);
        self::assertStringNotContainsString('p4kahx3lbow', strtolower((string)($plan['items'][0]['desired_slug'] ?? '')));
        self::assertStringNotContainsString('dqw4w9wgxcq', strtolower((string)($plan['items'][1]['desired_slug'] ?? '')));
    }

    public function test_plan_is_deterministic_for_same_inventory(): void
    {
        $items = [
            ['kind'=>'native_post','owner_id'=>'42','route_type'=>'post','scope'=>'root','name'=>'Vì sao người Việt gọi là 54?','current_slug'=>'vi-sao-nguoi-viet-goi-la-54','qualifiers'=>['2026']],
        ];
        $planner = new PublicUrlReprojectionPlanner();
        self::assertSame($planner->plan($items, static fn(array $item, string $slug): bool => false), $planner->plan($items, static fn(array $item, string $slug): bool => false));
    }
}
