<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\ArticlePreflightResult;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class ArticleIngestPreflight
{
    /** @param callable(string,string):bool|null $semanticTargetExists */
    public function __construct(private EndpointTypeRegistry $endpoints, private PredicateRegistry $predicates, private EntityTypeRegistry $types, private $semanticTargetExists = null) {}

    /** @param list<array<string,mixed>> $commands */
    public function check(string $endpointKey, string $intent, array $commands, string $endpointType = 'wp_post'): ArticlePreflightResult
    {
        $reasons = [];
        $details = ['endpoint_key' => $endpointKey];
        if ($intent !== 'reconcile') $reasons[] = 'UNSUPPORTED_OPERATION';
        if ($endpointType !== 'wp_post') $reasons[] = 'UNKNOWN_WP_ENDPOINT_TYPE';
        if (preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpointKey, $matches) !== 1 || (int) $matches[1] !== 55) $reasons[] = 'RECONCILIATION_CONFLICT';
        try {
            $this->endpoints->assertExists(new NodeReference('wp_post', $endpointKey));
        } catch (\Throwable $error) {
            $reasons[] = 'WP_ENDPOINT_UNAVAILABLE';
            $details['error'] = $error->getMessage();
            return ArticlePreflightResult::rejected($reasons, $details);
        }
        $slots = [];
        foreach ($commands as $command) {
            $slot = trim((string) ($command['slot'] ?? ''));
            if ($slot === '' || isset($slots[$slot])) { $reasons[] = 'DUPLICATE_OR_MISSING_SLOT'; continue; }
            $slots[$slot] = true;
            $operation = (string) ($command['operation'] ?? '');
            $entityType = (string) ($command['entity_type'] ?? '');
            $subjectId = trim((string) ($command['subject_id'] ?? ''));
            if (!in_array($operation, ['create', 'ingest', 'relation_create', 'rename', 'update', 'retire', 'reactivate', 'relation_retire', 'relation_reactivate'], true)) $reasons[] = 'UNKNOWN_OPERATION';
            if ($entityType !== 'relation' && !$this->types->has($entityType)) $reasons[] = 'UNKNOWN_ENTITY_TYPE';
            if ($subjectId === '') $reasons[] = 'SUBJECT_REQUIRED';
            if (isset($command['target_uuid']) && (string) $command['target_uuid'] !== '' && !UuidCodec::isValid((string) $command['target_uuid'])) $reasons[] = 'INVALID_TARGET_UUID';
            if ($this->semanticTargetExists !== null && !in_array($operation, ['create', 'ingest', 'relation_create'], true) && $entityType !== 'relation') {
                $targetId = trim((string) ($command['target_uuid'] ?? $subjectId));
                try {
                    if ($targetId !== '' && !(bool) ($this->semanticTargetExists)($entityType, $targetId)) $reasons[] = 'SEMANTIC_TARGET_UNAVAILABLE';
                } catch (\Throwable $error) {
                    $reasons[] = 'DEPENDENCY_UNAVAILABLE';
                    $details['semantic_target_error'] = $error->getMessage();
                }
            }
            if ((int) ($command['expected_revision'] ?? 0) < 1) $reasons[] = 'INVALID_EXPECTED_REVISION';
            $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
            if (str_starts_with($operation, 'relation_')) $this->checkRelation($payload, $reasons);
            if ($entityType === 'evidence' && in_array($operation, ['create', 'ingest'], true)) $reasons[] = 'EVIDENCE_IDEMPOTENCY_UNPROVEN';
            if ($entityType === 'evidence' && ($payload['claim_id'] ?? '') === '' && ($payload['source_id'] ?? '') === '') $reasons[] = 'SOURCE_EVIDENCE_MISSING';
            foreach ((array) ($command['dependency_slots'] ?? []) as $dependencySlot) {
                $dependencySlot = trim((string) $dependencySlot);
                if ($dependencySlot === '' || $dependencySlot === $slot) $reasons[] = 'INVALID_DEPENDENCY_SLOT';
                elseif (!array_key_exists($dependencySlot, $slots) && !array_key_exists($dependencySlot, array_column($commands, 'slot'))) $reasons[] = 'DEPENDENCY_SLOT_NOT_FOUND';
            }
        }
        $details['command_slots'] = array_keys($slots);
        return $reasons === [] ? ArticlePreflightResult::accepted($details) : ArticlePreflightResult::rejected($reasons, $details);
    }

    /** @param array<string,mixed> $payload @param list<string> $reasons */
    private function checkRelation(array $payload, array &$reasons): void
    {
        try {
            $predicate = $this->predicates->get((string) ($payload['predicate'] ?? ''));
            $source = new NodeReference((string) ($payload['source_type'] ?? ''), (string) ($payload['source_key'] ?? ''));
            $target = new NodeReference((string) ($payload['target_type'] ?? ''), (string) ($payload['target_key'] ?? ''));
            if (!$predicate->allows($source->endpoint_type, $target->endpoint_type)) $reasons[] = 'INVALID_RELATION_ENDPOINTS';
            $this->endpoints->assertExists($source);
            $this->endpoints->assertExists($target);
        } catch (\Throwable $error) {
            $message = strtolower($error->getMessage());
            $reasons[] = str_contains($message, 'predicate') ? 'UNKNOWN_PREDICATE' : 'INVALID_RELATION_ENDPOINT';
        }
    }
}
