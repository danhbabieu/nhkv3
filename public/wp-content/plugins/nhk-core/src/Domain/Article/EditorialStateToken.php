<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final class EditorialStateToken
{
    public static function matches(string $expected, EditorialPostState $current): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) return false;

        return hash_equals($current->token, $expected);
    }

    /** @param array<string,mixed> $state */
    public static function fromState(array $state): string
    {
        $ordered = [];
        foreach (['post_id', 'post_type', 'title', 'content', 'excerpt', 'status', 'slug', 'permalink', 'modified_gmt', 'latest_revision_id', 'revision_count', 'category_ids', 'featured_attachment_id'] as $field) {
            $ordered[$field] = $state[$field] ?? null;
        }
        $ordered['category_ids'] = array_values(array_unique(array_map('intval', (array) ($ordered['category_ids'] ?? []))));
        sort($ordered['category_ids'], SORT_NUMERIC);
        $ordered['featured_attachment_id'] = max(0, (int) ($ordered['featured_attachment_id'] ?? 0));
        return hash('sha256', json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
