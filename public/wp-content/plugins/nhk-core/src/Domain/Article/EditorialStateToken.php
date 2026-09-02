<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final class EditorialStateToken
{
    /** @param array<string,mixed> $state */
    public static function fromState(array $state): string
    {
        $ordered = [];
        foreach (['post_id', 'post_type', 'title', 'content', 'excerpt', 'status', 'slug', 'permalink', 'modified_gmt', 'latest_revision_id', 'revision_count'] as $field) {
            $ordered[$field] = $state[$field] ?? null;
        }
        return hash('sha256', json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
