<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Inventory\CanonicalInventoryService;
use PHPUnit\Framework\TestCase;

final class CanonicalInventoryServiceTest extends TestCase
{
    public function test_filters_before_pagination_and_returns_safe_canonical_identity_fields(): void
    {
        $service = new CanonicalInventoryService([
            'brand' => static fn (): array => [
                ['uuid' => 'b-1', 'stable_key' => 'brand:one', 'revision' => 1, 'state' => 'ACTIVE', 'provenance' => ['source' => 'catalog'], 'visibility' => 'PUBLIC'],
                ['uuid' => 'b-2', 'stable_key' => 'brand:two', 'revision' => 2, 'state' => 'RETIRED', 'provenance' => ['source' => 'catalog'], 'visibility' => 'PRIVATE'],
                ['uuid' => 'b-3', 'stable_key' => 'brand:three', 'revision' => 3, 'state' => 'ACTIVE', 'provenance' => ['source' => 'archive'], 'visibility' => 'PUBLIC'],
            ],
        ]);

        $page = $service->inventory(['type' => 'brand', 'state' => 'ACTIVE'], 1, null);

        self::assertSame(2, $page->total);
        self::assertSame('b-1', $page->items[0]['uuid']);
        self::assertSame('brand:one', $page->items[0]['stable_key']);
        self::assertSame(1, $page->items[0]['revision']);
        self::assertTrue($page->items[0]['active']);
        self::assertSame(['source' => 'catalog'], $page->items[0]['provenance']);
        self::assertSame('PUBLIC', $page->items[0]['visibility']);
        self::assertSame('brand:b-1', $page->next);
    }

    public function test_unknown_type_is_empty_and_does_not_fallback_to_another_domain(): void
    {
        $service = new CanonicalInventoryService(['brand' => static fn (): array => [['uuid' => 'b-1']]]);

        self::assertSame(['items' => [], 'total' => 0, 'next' => null], $service->inventory(['type' => 'model'], 10, null)->toArray());
    }
}
