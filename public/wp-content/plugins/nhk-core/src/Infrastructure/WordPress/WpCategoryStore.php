<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\WordPress;

use NHK\Core\Contracts\WordPress\CategoryStore;
use NHK\Core\Domain\WordPress\CategoryState;

final class WpCategoryStore implements CategoryStore
{
    public function findById(int $id): ?CategoryState { if ($id < 1 || !function_exists('get_term')) return null; $term = get_term($id, 'category'); return $this->state($term); }
    public function findBySlug(string $slug): ?CategoryState { if ($slug === '' || !function_exists('get_term_by')) return null; return $this->state(get_term_by('slug', $slug, 'category')); }
    public function findByExactName(string $name): array { if ($name === '' || !function_exists('get_terms')) return []; $terms = get_terms(['taxonomy' => 'category', 'name' => $name, 'hide_empty' => false]); if (is_wp_error($terms) || !is_array($terms)) return []; return array_values(array_filter(array_map(fn (mixed $term): ?CategoryState => $this->state($term), $terms))); }
    public function create(string $name, string $slug, int $parent): CategoryState { if (!function_exists('wp_insert_term')) throw new \RuntimeException('WORDPRESS_CATEGORY_UNAVAILABLE'); $args = ['parent' => $parent]; if ($slug !== '') $args['slug'] = $slug; $result = wp_insert_term($name, 'category', $args); if (is_wp_error($result)) { $existing = $this->findBySlug($slug); if ($existing !== null) return $existing; throw new \RuntimeException('CATEGORY_CREATE_FAILED:' . $result->get_error_code()); } $state = $this->findById((int) $result['term_id']); if ($state === null) throw new \RuntimeException('CATEGORY_READBACK_FAILED'); return $state; }
    public function update(int $id, array $changes): CategoryState { if (!function_exists('wp_update_term')) throw new \RuntimeException('WORDPRESS_CATEGORY_UNAVAILABLE'); $result = wp_update_term($id, 'category', array_intersect_key($changes, array_flip(['name', 'slug', 'parent']))); if (is_wp_error($result)) throw new \RuntimeException('CATEGORY_UPDATE_FAILED:' . $result->get_error_code()); $state = $this->findById($id); if ($state === null) throw new \RuntimeException('CATEGORY_READBACK_FAILED'); return $state; }
    public function assignPost(int $postId, int $termId): void { if (!function_exists('wp_get_post_categories') || !function_exists('wp_set_post_categories')) throw new \RuntimeException('WORDPRESS_CATEGORY_UNAVAILABLE'); $ids = array_map('intval', wp_get_post_categories($postId, ['fields' => 'ids'])); if (!in_array($termId, $ids, true)) { $ids[] = $termId; wp_set_post_categories($postId, $ids, false); } }
    public function unassignPost(int $postId, int $termId): void { if (!function_exists('wp_get_post_categories') || !function_exists('wp_set_post_categories')) throw new \RuntimeException('WORDPRESS_CATEGORY_UNAVAILABLE'); $ids = array_values(array_filter(array_map('intval', wp_get_post_categories($postId, ['fields' => 'ids'])), static fn (int $id): bool => $id !== $termId)); wp_set_post_categories($postId, $ids, false); }
    public function usageCount(int $termId): int { $state = $this->findById($termId); return $state?->count ?? 0; }
    public function childCount(int $termId): int { if (!function_exists('get_terms')) return 0; $terms = get_terms(['taxonomy' => 'category', 'parent' => $termId, 'hide_empty' => false, 'fields' => 'ids']); return is_wp_error($terms) || !is_array($terms) ? 0 : count($terms); }
    public function isDefault(int $termId): bool { return function_exists('get_option') && (int) get_option('default_category', 0) === $termId; }
    public function delete(int $termId): void { if (!function_exists('wp_delete_term')) throw new \RuntimeException('WORDPRESS_CATEGORY_UNAVAILABLE'); $result = wp_delete_term($termId, 'category'); if ($result === false || is_wp_error($result)) throw new \RuntimeException('CATEGORY_DELETE_FAILED'); }
    private function state(mixed $term): ?CategoryState { if (!$term instanceof \WP_Term) return null; return new CategoryState((int) $term->term_id, (string) $term->name, (string) $term->slug, (int) $term->parent, (int) $term->count); }
}
