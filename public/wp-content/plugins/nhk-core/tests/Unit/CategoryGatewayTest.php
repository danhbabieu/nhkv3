<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\WordPress\CategoryGateway;
use NHK\Core\Contracts\WordPress\CategoryStore;
use NHK\Core\Domain\WordPress\CategoryState;
use PHPUnit\Framework\TestCase;

final class CategoryGatewayTest extends TestCase
{
    public function test_create_is_idempotent_and_resolves_by_slug_and_exact_name(): void
    {
        $store = new FakeCategoryStore(); $gateway = new CategoryGateway($store);
        $first = $gateway->create('Âm thanh cổ', 'am-thanh-co'); $second = $gateway->create('Âm thanh cổ', 'am-thanh-co');
        self::assertFalse($first['idempotent']); self::assertTrue($second['idempotent']); self::assertSame($first['category']['id'], $second['category']['id']); self::assertSame(1, $store->created);
        self::assertTrue($gateway->resolve(['slug' => 'am-thanh-co'])['ok']); self::assertTrue($gateway->resolve(['name' => 'Âm thanh cổ'])['ok']);
    }

    public function test_identity_conflict_and_invalid_parent_fail_closed(): void
    {
        $store = new FakeCategoryStore(); $gateway = new CategoryGateway($store); $gateway->create('Một', 'mot'); $gateway->create('Hai', 'hai');
        self::assertSame('CATEGORY_IDENTITY_CONFLICT', $gateway->resolve(['slug' => 'mot', 'name' => 'Hai'])['reason']);
        self::assertSame('INVALID_PARENT', $gateway->create('Con', 'con', 99)['reason']);
    }

    public function test_update_uses_fingerprint_and_delete_is_guarded(): void
    {
        $store = new FakeCategoryStore(); $gateway = new CategoryGateway($store); $category = $gateway->create('Một', 'mot')['category'];
        self::assertSame('CATEGORY_STATE_CONFLICT', $gateway->update($category['id'], ['name' => 'Đã sửa'], str_repeat('0', 64))['reason']);
        self::assertSame('CATEGORY_DELETE_UNSAFE', $gateway->delete($category['id'])['reason']);
        $store->usage = 0; self::assertTrue($gateway->delete($category['id'])['ok']);
    }
}

final class FakeCategoryStore implements CategoryStore
{
    /** @var array<int,CategoryState> */ public array $rows = []; public int $created = 0; public int $usage = 1;
    public function findById(int $id): ?CategoryState { return $this->rows[$id] ?? null; }
    public function findBySlug(string $slug): ?CategoryState { foreach ($this->rows as $row) if ($row->slug === $slug) return $row; return null; }
    public function findByExactName(string $name): array { return array_values(array_filter($this->rows, static fn (CategoryState $row): bool => $row->name === $name)); }
    public function create(string $name, string $slug, int $parent): CategoryState { $this->created++; $row = new CategoryState(count($this->rows) + 1, $name, $slug, $parent, 0); return $this->rows[$row->id] = $row; }
    public function update(int $id, array $changes): CategoryState { $old = $this->rows[$id]; return $this->rows[$id] = new CategoryState($id, (string) ($changes['name'] ?? $old->name), (string) ($changes['slug'] ?? $old->slug), (int) ($changes['parent'] ?? $old->parent), $old->count); }
    public function assignPost(int $postId, int $termId): void { $this->usage++; }
    public function unassignPost(int $postId, int $termId): void { $this->usage = max(0, $this->usage - 1); }
    public function usageCount(int $termId): int { return $this->usage; }
    public function childCount(int $termId): int { return 0; }
    public function isDefault(int $termId): bool { return false; }
    public function delete(int $termId): void { unset($this->rows[$termId]); }
}
