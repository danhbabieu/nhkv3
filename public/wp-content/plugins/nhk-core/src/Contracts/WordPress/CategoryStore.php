<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\WordPress;

use NHK\Core\Domain\WordPress\CategoryState;

interface CategoryStore
{
    public function findById(int $id): ?CategoryState;
    public function findBySlug(string $slug): ?CategoryState;
    /** @return list<CategoryState> */
    public function findByExactName(string $name): array;
    public function create(string $name, string $slug, int $parent): CategoryState;
    public function update(int $id, array $changes): CategoryState;
    public function assignPost(int $postId, int $termId): void;
    public function unassignPost(int $postId, int $termId): void;
    public function usageCount(int $termId): int;
    public function childCount(int $termId): int;
    public function isDefault(int $termId): bool;
    public function delete(int $termId): void;
}
