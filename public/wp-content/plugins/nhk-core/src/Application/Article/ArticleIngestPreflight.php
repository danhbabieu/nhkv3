<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\ArticlePreflightResult;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};

final class ArticleIngestPreflight
{
    public function __construct(private EndpointTypeRegistry $endpoints, private PredicateRegistry $predicates, private EntityTypeRegistry $types) {}

    /** @param list<array<string,mixed>> $commands */
    public function check(string $endpointKey, string $intent, array $commands): ArticlePreflightResult
    {
        $reasons = [];
        if ($intent !== 'reconcile') $reasons[] = 'UNSUPPORTED_OPERATION';
        if (preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpointKey, $matches) !== 1 || (int) $matches[1] !== 55) $reasons[] = 'RECONCILIATION_CONFLICT';
        try {
            $this->endpoints->assertExists(new NodeReference('wp_post', $endpointKey));
        } catch (\Throwable $error) {
            $reasons[] = 'WP_ENDPOINT_UNAVAILABLE';
            $details = ['error' => $error->getMessage()];
            return ArticlePreflightResult::rejected($reasons, $details);
        }
        $slots = [];
        foreach ($commands as $command) {
            $slot = trim((string) ($command['slot'] ?? ''));
            if ($slot === '' || isset($slots[$slot])) { $reasons[] = 'DUPLICATE_OR_MISSING_SLOT'; continue; }
            $slots[$slot] = true;
            $operation = (string) ($command['operation'] ?? '');
            $entityType = (string) ($command['entity_type'] ?? '');
            if (!in_array($operation, ['create', 'ingest', 'relation_create', 'rename', 'update', 'retire', 'reactivate', 'relation_retire', 'relation_reactivate'], true)) $reasons[] = 'UNKNOWN_OPERATION';
            if ($entityType !== 'relation' && !$this->types->has($entityType)) $reasons[] = 'UNKNOWN_ENTITY_TYPE';
            if ((int) ($command['expected_revision'] ?? 0) < 1) $reasons[] = 'INVALID_EXPECTED_REVISION';
            $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
            if ($operation === 'relation_create') $this->checkRelation($payload, $reasons);
            if ($entityType === 'evidence' && ($payload['claim_id'] ?? '') === '' && ($payload['source_id'] ?? '') === '') $reasons[] = 'SOURCE_EVIDENCE_MISSING';
        }
        return $reasons === [] ? ArticlePreflightResult::accepted(['endpoint_key' => $endpointKey, 'command_slots' => array_keys($slots)]) : ArticlePreflightResult::rejected($reasons);
    }

    /** @param array<string,mixed> $payload @param list<string> $reasons */
    private function checkRelation(array $payload, array &$reasons): void
    {
        try {
            $predicate = $this->predicates->get((string) ($payload['predicate'] ?? ''));
            $source = new NodeReference((string) ($payload['source_type'] ?? ''), (string) ($payload['source_key'] ?? ''));
            $target = new NodeReference((string) ($payload['target_type'] ?? ''), (string) ($payload['target_key'] ?? ''));
            if (!$predicate->allows($source->endpoint_type, $target->endpoint_type)) $reasons[] = 'INVALID_RELATION_ENDPOINTS';
        } catch (\Throwable $error) {
            $reasons[] = str_contains(strtolower($error->getMessage()), 'predicate') ? 'UNKNOWN_PREDICATE' : 'INVALID_RELATION_ENDPOINT';
        }
    }
}
