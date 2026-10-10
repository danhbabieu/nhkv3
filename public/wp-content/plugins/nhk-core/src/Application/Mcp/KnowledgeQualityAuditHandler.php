<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Knowledge\KnowledgeQualityAuditCoordinator;
use NHK\Core\Shared\Uuid\UuidCodec;

/** MCP adapter for the bounded, read-only Knowledge quality audit planner. */
final class KnowledgeQualityAuditHandler
{
    private const FINDINGS = ['KEEP_CANONICAL', 'DUPLICATE_OR_REUSE', 'PROCESS_CONTAMINATION', 'EDITORIAL_FRAGMENT', 'SCOPE_PROBLEM', 'PROVENANCE_GAP', 'EVIDENCE_GAP', 'ATOMIZATION_NEEDED', 'SUBJECT_UNRESOLVED', 'SUBJECT_AMBIGUOUS', 'DICTIONARY_CANDIDATE', 'RELATION_CANDIDATE', 'CONTRADICTION_REVIEW', 'QUALIFICATION_REVIEW', 'DERIVED_CONTENT_CONTAMINATION', 'INTERNAL_WORKFLOW_KNOWLEDGE', 'UNRESOLVED'];
    private const SCOPES = ['entity', 'brand', 'model', 'variant', 'movement', 'specimen', 'specimen_observation', 'observation', 'editorial_experience', 'hypothesis', 'unresolved'];
    private const READINESS = ['READY', 'PARTIAL', 'BLOCKED'];

    private \Closure $diagnosticSink;

    public function __construct(private KnowledgeQualityAuditCoordinator $coordinator, ?callable $diagnosticSink = null)
    {
        $this->diagnosticSink = $diagnosticSink === null
            ? static function (array $diagnostic): void {
                error_log('NHK knowledge quality audit failure ' . json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            : \Closure::fromCallable($diagnosticSink);
    }

    /** @return array<string,mixed> */
    public function audit(array $input): array
    {
        $limit = $input['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_LIMIT_INVALID');
        $cursor = $input['cursor'] ?? null;
        if ($cursor !== null && (!is_string($cursor) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $cursor) !== 1)) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_CURSOR_INVALID');
        $filters = $this->filters($input);
        $includeItems = $input['include_items'] ?? true;
        $includeRepairs = $input['include_repair_candidates'] ?? true;
        if (!is_bool($includeItems) || !is_bool($includeRepairs)) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_BOOLEAN_INVALID');

        try {
            $batch = $this->coordinator->auditBatch($limit, $cursor, true, $filters);
        } catch (\Throwable $error) {
            $timeout = preg_match('/timeout|timed[ -]?out|maximum execution time/i', $error->getMessage()) === 1;
            $diagnostic = [
                'code' => $timeout ? 'KNOWLEDGE_QUALITY_AUDIT_TIMEOUT' : 'KNOWLEDGE_QUALITY_AUDIT_INTERNAL_ERROR',
                'correlation_id' => UuidCodec::newV7(),
                'exception_class' => get_class($error),
            ];
            ($this->diagnosticSink)($diagnostic);
            return [
                'status' => 'UNAVAILABLE',
                'result_state' => $timeout ? 'TIMEOUT' : 'RUNTIME_ERROR',
                'read_only' => true,
                'mutated' => false,
                'total' => null,
                'items' => [],
                'aggregate' => null,
                'next_cursor' => null,
                'has_more' => false,
                'reason' => $timeout ? 'KNOWLEDGE_QUALITY_AUDIT_TIMEOUT' : 'KNOWLEDGE_QUALITY_AUDIT_UNAVAILABLE',
                'diagnostics' => ['error' => $diagnostic],
            ];
        }
        $items = $batch['results'];
        if (!$includeRepairs) foreach ($items as &$item) unset($item['repair_candidates']);
        unset($item);
        return [
            'status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false,
            'total' => count($items), 'page_count' => 1,
            'items' => $includeItems ? $items : [],
            'aggregate' => $this->aggregate($items),
            'next_cursor' => $batch['next_cursor'], 'has_more' => $batch['has_more'],
            'facet_coverage' => $batch['facet_coverage'], 'diagnostics' => $batch['diagnostics'],
        ];
    }

    /** @return array<string,mixed> */
    private function filters(array $input): array
    {
        $findings = $input['finding_filters'] ?? [];
        if (!is_array($findings) || count($findings) > 10 || array_diff($findings, self::FINDINGS) !== []) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_FINDING_FILTER_INVALID');
        $subject = $input['subject_id'] ?? null;
        if ($subject !== null && $subject !== '' && (!is_string($subject) || !UuidCodec::isValid($subject))) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_SUBJECT_INVALID');
        $scope = $input['scope'] ?? null;
        if ($scope !== null && !in_array($scope, self::SCOPES, true)) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_SCOPE_INVALID');
        $readiness = $input['readiness'] ?? null;
        if ($readiness !== null && !in_array($readiness, self::READINESS, true)) throw new \InvalidArgumentException('KNOWLEDGE_QUALITY_AUDIT_READINESS_INVALID');
        return ['finding_filters' => array_values($findings), 'subject_id' => $subject, 'scope' => $scope, 'readiness' => $readiness];
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function aggregate(array $items): array
    {
        $aggregate = $this->emptyAggregate();
        foreach ($items as $item) {
            foreach ((array) ($item['quality_findings'] ?? []) as $finding) $aggregate['counts_by_finding'][$finding] = ($aggregate['counts_by_finding'][$finding] ?? 0) + 1;
            foreach ([['scope_assessment', 'scope', 'counts_by_scope'], ['provenance_assessment', 'class', 'counts_by_provenance'], ['facet_classification', 'facet', 'counts_by_facet'], ['writer_readiness', 'status', 'counts_by_readiness']] as [$section, $field, $target]) {
                $value = (string) ($item[$section][$field] ?? 'UNRESOLVED');
                $aggregate[$target][$value] = ($aggregate[$target][$value] ?? 0) + 1;
            }
            foreach ((array) ($item['duplicate_matches'] ?? []) as $match) { $key = (string) ($match['classification'] ?? 'UNRESOLVED'); $aggregate['top_duplicate_signatures'][$key] = ($aggregate['top_duplicate_signatures'][$key] ?? 0) + 1; }
        }
        foreach (['counts_by_finding', 'counts_by_scope', 'counts_by_provenance', 'counts_by_facet', 'counts_by_readiness'] as $key) $aggregate[$key] = $this->sortCounts($aggregate[$key]);
        $aggregate['top_duplicate_signatures'] = array_map(static fn (array $row): array => ['key' => $row['key'], 'count' => $row['count']], $this->sortCounts($aggregate['top_duplicate_signatures'], 10));
        return $aggregate;
    }

    /** @param array<string,int> $counts @return array<string,int>|list<array{key:string,count:int}> */
    private function sortCounts(array $counts, ?int $limit = null): array
    {
        uksort($counts, static fn (string $a, string $b): int => $a <=> $b);
        uasort($counts, static fn (int $a, int $b): int => $b <=> $a);
        if ($limit === null) return $counts;
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $key => $count) $rows[] = ['key' => (string) $key, 'count' => $count];
        return $rows;
    }

    /** @return array<string,mixed> */
    private function emptyAggregate(): array
    {
        return ['counts_by_finding' => [], 'counts_by_scope' => [], 'counts_by_provenance' => [], 'counts_by_facet' => [], 'counts_by_readiness' => [], 'top_duplicate_signatures' => []];
    }
}
