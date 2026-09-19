<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\WordPress;

use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Infrastructure\Article\WpEditorialStateReader;
use NHK\Core\Domain\Article\EditorialPostState;

final class WpEditorialPostStore implements EditorialPostStore
{
    public function __construct(private ?WpEditorialStateReader $reader = null) {}
    public function read(int $postId): ?EditorialPostState { return ($this->reader ?? new WpEditorialStateReader())->read($postId); }
    public function createDraft(array $fields): EditorialPostState { if (!function_exists('wp_insert_post')) throw new \RuntimeException('WORDPRESS_EDITORIAL_UNAVAILABLE'); $fields['post_status'] = 'draft'; $fields['post_type'] = (string) ($fields['post_type'] ?? 'post'); $result = wp_insert_post($fields, true); if (is_wp_error($result) || (int) $result < 1) throw new \RuntimeException('EDITORIAL_DRAFT_CREATE_FAILED'); $state = $this->read((int) $result); if ($state === null || $state->status !== 'draft') throw new \RuntimeException('EDITORIAL_DRAFT_READBACK_FAILED'); return $state; }
    public function update(int $postId, array $fields): EditorialPostState
    {
        if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_EDITORIAL_UNAVAILABLE');
        $featured = array_key_exists('featured_media_id', $fields) ? max(0, (int) $fields['featured_media_id']) : null;
        unset($fields['featured_media_id']);
        if (array_key_exists('category_ids', $fields)) { $fields['post_category'] = array_values(array_map('intval', (array) $fields['category_ids'])); unset($fields['category_ids']); }
        $fields['ID'] = $postId; unset($fields['post_status']);
        $result = wp_update_post($fields, true);
        if (is_wp_error($result) || (int) $result !== $postId) throw new \RuntimeException('EDITORIAL_DRAFT_UPDATE_FAILED');
        if ($featured !== null) {
            if (!function_exists('set_post_thumbnail')) throw new \RuntimeException('EDITORIAL_FEATURED_MEDIA_UNAVAILABLE');
            if ($featured === 0) delete_post_thumbnail($postId); elseif (!set_post_thumbnail($postId, $featured)) throw new \RuntimeException('EDITORIAL_FEATURED_MEDIA_UPDATE_FAILED');
        }
        $state = $this->read($postId); if ($state === null) throw new \RuntimeException('EDITORIAL_DRAFT_READBACK_FAILED'); return $state;
    }
    public function publish(int $postId): EditorialPostState { return $this->transition($postId, 'publish', 'EDITORIAL_PUBLISH_FAILED'); }
    public function trash(int $postId): EditorialPostState { return $this->transition($postId, 'trash', 'EDITORIAL_TRASH_FAILED'); }
    public function restore(int $postId): EditorialPostState { return $this->transition($postId, 'draft', 'EDITORIAL_RESTORE_FAILED'); }
    private function transition(int $postId, string $status, string $failure): EditorialPostState
    {
        if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_EDITORIAL_UNAVAILABLE');
        $result = wp_update_post(['ID' => $postId, 'post_status' => $status], true);
        if (is_wp_error($result) || (int) $result !== $postId) throw new \RuntimeException($failure);
        $state = $this->read($postId);
        if ($state === null || $state->status !== $status) throw new \RuntimeException('EDITORIAL_STATUS_READBACK_FAILED');
        return $state;
    }
}
