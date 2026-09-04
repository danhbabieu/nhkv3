<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

final class ArticleIntentOverlapPlanner
{
    public function classify(array $intent, array $inventory): string
    {
        $value = trim((string) ($intent['intent'] ?? ''));
        if ($value === '') return 'AMBIGUOUS_INTENT';
        if (in_array($value, (array) ($inventory['article_intents'] ?? []), true)) return 'ENRICH_EXISTING_ARTICLE';
        if (($inventory['video_primary'] ?? false) === true) return 'USE_EXISTING_VIDEO_PAGE';
        if (($inventory['entity_covered'] ?? false) === true) return 'ENRICH_ENTITY_PROJECTION';
        return 'CREATE_DIFFERENTIATED_ARTICLE';
    }
}
