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
    public function update(int $postId, array $fields): EditorialPostState { if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_EDITORIAL_UNAVAILABLE'); $fields['ID'] = $postId; unset($fields['post_status']); $result = wp_update_post($fields, true); if (is_wp_error($result) || (int) $result !== $postId) throw new \RuntimeException('EDITORIAL_DRAFT_UPDATE_FAILED'); $state = $this->read($postId); if ($state === null) throw new \RuntimeException('EDITORIAL_DRAFT_READBACK_FAILED'); return $state; }
}
