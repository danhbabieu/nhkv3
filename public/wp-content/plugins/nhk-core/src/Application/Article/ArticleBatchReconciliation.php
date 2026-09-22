<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

/** Batch read/plan mode that delegates every Article to the same orchestrator. */
final class ArticleBatchReconciliation
{
    public function __construct(private ArticleReconciliationOrchestrator $orchestrator) {}
    /** @param list<array<string,mixed>> $articles @return array<string,mixed> */
    public function classify(array $articles, int $limit = 50): array
    {
        $items = [];
        foreach (array_slice($articles, 0, max(0, $limit)) as $article) {
            $result = $this->orchestrator->reconcile($article + ['publish_requested' => false]);
            $classification = match ($result['status'] ?? '') { 'PASS' => 'READY', 'OWNER_REVIEW_REQUIRED' => 'OWNER_REVIEW', 'SYSTEM_BLOCKED' => 'SYSTEM_BLOCKED', default => 'AUTO_REPAIRABLE' };
            $items[] = ['post_id' => $article['post_id'] ?? null, 'classification' => $classification, 'result' => $result];
        }
        $counts = array_fill_keys(['READY', 'AUTO_REPAIRABLE', 'OWNER_REVIEW', 'SYSTEM_BLOCKED'], 0);
        foreach ($items as $item) $counts[$item['classification']]++;
        return ['items' => $items, 'counts' => $counts, 'processed' => count($items)];
    }
}
