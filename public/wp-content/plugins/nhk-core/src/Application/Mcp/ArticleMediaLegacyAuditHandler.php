<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Media\{ArticleMediaLegacyAudit, ArticleMediaLegacyRepairPlan};

/** MCP adapter for bounded Article Media audit and zero-mutation repair preview. */
final class ArticleMediaLegacyAuditHandler
{
    public function __construct(private ArticleMediaLegacyAudit $audit, private ArticleMediaLegacyRepairPlan $repairPlan) {}

    /** @return array<string,mixed> */
    public function audit(array $input): array
    {
        $limit = $input['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 200) throw new \InvalidArgumentException('ARTICLE_MEDIA_LEGACY_AUDIT_LIMIT_INVALID');
        $cursor = $input['cursor'] ?? null;
        if ($cursor !== null && (!is_string($cursor) || trim($cursor) === '' || strlen($cursor) > 191)) throw new \InvalidArgumentException('ARTICLE_MEDIA_LEGACY_AUDIT_CURSOR_INVALID');
        $result = $this->audit->audit($cursor ?? '', $limit);
        $items = array_values(array_filter((array) ($result['findings'] ?? []), 'is_array'));
        $counts = [];
        foreach ($items as $item) {
            $key = (string) ($item['disposition'] ?? 'REVIEW_REQUIRED');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        $next = $result['next_cursor'] ?? null;
        return [
            'status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false,
            'items' => $items, 'next_cursor' => $next, 'has_more' => $next !== null,
            'summary' => ['count' => count($items), 'counts_by_disposition' => $counts],
        ];
    }

    /** @return array<string,mixed> */
    public function repairPlan(array $input): array
    {
        return $this->repairPlan->preview($input);
    }
}
