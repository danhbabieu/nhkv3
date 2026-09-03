<?php
declare(strict_types=1);

namespace NHK\Core\Application\WordPress;

use NHK\Core\Contracts\WordPress\CategoryStore;
use NHK\Core\Domain\WordPress\CategoryState;

final class CategoryGateway
{
    public function __construct(private CategoryStore $store) {}

    /** @return array<string,mixed> */
    public function resolve(array $selector): array
    {
        $matches = [];
        if (isset($selector['id'])) { $state = $this->store->findById((int) $selector['id']); if ($state !== null) $matches[] = $state; }
        if ($matches === [] && trim((string) ($selector['slug'] ?? '')) !== '') { $state = $this->store->findBySlug(trim((string) $selector['slug'])); if ($state !== null) $matches[] = $state; }
        if ($matches === [] && trim((string) ($selector['name'] ?? '')) !== '') $matches = $this->store->findByExactName(trim((string) $selector['name']));
        if (count($matches) > 1) return ['ok' => false, 'reason' => 'AMBIGUOUS_CATEGORY', 'candidates' => array_map(static fn (CategoryState $state): array => $state->toArray(), $matches)];
        if ($matches === []) return ['ok' => false, 'reason' => 'CATEGORY_NOT_FOUND', 'candidates' => []];
        $state = $matches[0];
        if (isset($selector['slug'], $selector['name'])) {
            $byName = $this->store->findByExactName(trim((string) $selector['name']));
            if ($byName !== [] && $byName[0]->id !== $state->id) return ['ok' => false, 'reason' => 'CATEGORY_IDENTITY_CONFLICT', 'candidates' => array_map(static fn (CategoryState $item): array => $item->toArray(), array_merge([$state], $byName))];
        }
        return ['ok' => true, 'category' => $state->toArray()];
    }

    /** @return array<string,mixed> */
    public function create(string $name, string $slug = '', int $parent = 0): array
    {
        $existing = $this->resolve(['slug' => $slug, 'name' => $name]);
        if (($existing['ok'] ?? false) === true) return ['ok' => true, 'idempotent' => true, 'category' => $existing['category']];
        if (($existing['reason'] ?? '') === 'CATEGORY_IDENTITY_CONFLICT') return $existing;
        if ($parent > 0 && $this->store->findById($parent) === null) return ['ok' => false, 'reason' => 'INVALID_PARENT'];
        $state = $this->store->create(trim($name), trim($slug), $parent);
        return ['ok' => true, 'idempotent' => false, 'category' => $state->toArray()];
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $changes, ?string $expectedFingerprint = null): array
    {
        $current = $this->store->findById($id);
        if ($current === null) return ['ok' => false, 'reason' => 'CATEGORY_NOT_FOUND'];
        if ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, $current->fingerprint)) return ['ok' => false, 'reason' => 'CATEGORY_STATE_CONFLICT', 'category' => $current->toArray()];
        if (isset($changes['parent']) && (int) $changes['parent'] > 0 && $this->store->findById((int) $changes['parent']) === null) return ['ok' => false, 'reason' => 'INVALID_PARENT'];
        return ['ok' => true, 'category' => $this->store->update($id, $changes)->toArray()];
    }

    /** @return array<string,mixed> */
    public function assign(int $postId, int $termId): array { $this->store->assignPost($postId, $termId); $state = $this->store->findById($termId); return ['ok' => $state !== null, 'idempotent' => true, 'category' => $state?->toArray(), 'post_id' => $postId]; }
    /** @return array<string,mixed> */
    public function unassign(int $postId, int $termId): array { $this->store->unassignPost($postId, $termId); $state = $this->store->findById($termId); return ['ok' => $state !== null, 'category' => $state?->toArray(), 'post_id' => $postId]; }
    /** @return array<string,mixed> */
    public function delete(int $termId, bool $allowReassign = false): array
    {
        $state = $this->store->findById($termId);
        if ($state === null) return ['ok' => false, 'reason' => 'CATEGORY_NOT_FOUND'];
        if (!$allowReassign && ($this->store->usageCount($termId) > 0 || $this->store->childCount($termId) > 0 || $this->store->isDefault($termId))) return ['ok' => false, 'reason' => 'CATEGORY_DELETE_UNSAFE'];
        $this->store->delete($termId); return ['ok' => true, 'deleted_id' => $termId];
    }
}
