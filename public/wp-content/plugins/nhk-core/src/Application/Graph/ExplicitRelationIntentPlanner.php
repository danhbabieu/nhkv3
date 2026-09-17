<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Plans only explicitly typed relation intents against canonical registries. */
final class ExplicitRelationIntentPlanner
{
    /**
     * @param callable(NodeReference):array<string,mixed>|null $endpointState
     * @param callable(array<string,mixed>):array<string,mixed>|null $relationState
     */
    public function __construct(
        private EndpointTypeRegistry $endpoints,
        private PredicateRegistry $predicates,
        private $endpointState,
        private $relationState,
    ) {}

    /** @param list<array<string,mixed>> $relationIntents @return array<string,mixed> */
    public function plan(array $relationIntents): array
    {
        $result = ['relation_candidates' => [], 'relation_reuse' => [], 'blockers' => [], 'ambiguities' => []];
        if (!array_is_list($relationIntents)) {
            $result['blockers'][] = ['code' => 'RELATION_INTENTS_MALFORMED'];
            return $result;
        }

        $normalized = [];
        foreach ($relationIntents as $intent) {
            if (!is_array($intent)) {
                $result['blockers'][] = ['code' => 'RELATION_INTENT_MALFORMED'];
                continue;
            }
            $sourceType = strtolower(trim((string) ($intent['source_type'] ?? '')));
            $sourceUuid = trim((string) ($intent['source_uuid'] ?? ''));
            $predicate = strtolower(trim((string) ($intent['predicate'] ?? '')));
            $targetType = strtolower(trim((string) ($intent['target_type'] ?? '')));
            $targetUuid = trim((string) ($intent['target_uuid'] ?? ''));
            if ($sourceType === '' || $sourceUuid === '' || $predicate === '' || $targetType === '' || $targetUuid === '') {
                $result['blockers'][] = ['code' => 'RELATION_INTENT_IDENTITY_REQUIRED'];
                continue;
            }
            $normalized[] = [
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'predicate' => $predicate,
                'target_type' => $targetType,
                'target_uuid' => $targetUuid,
                'provenance' => trim((string) ($intent['provenance'] ?? '')) ?: 'EXPLICIT_USER_RELATION',
                'reason' => trim((string) ($intent['reason'] ?? '')),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => strcmp(self::identity($left), self::identity($right)));
        $seen = [];
        foreach ($normalized as $intent) {
            $identity = self::identity($intent);
            if (isset($seen[$identity])) continue;
            $seen[$identity] = true;
            $this->planOne($intent, $result);
        }
        return $result;
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $result */
    private function planOne(array $intent, array &$result): void
    {
        foreach (['source', 'target'] as $side) {
            $type = (string) $intent[$side . '_type'];
            $uuid = (string) $intent[$side . '_uuid'];
            if ($type !== 'wp_post' && !UuidCodec::isValid($uuid)) {
                $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_UUID_INVALID', 'endpoint_type' => $type, 'endpoint_uuid' => $uuid];
                return;
            }
        }

        try {
            $definition = $this->predicates->get((string) $intent['predicate']);
        } catch (\Throwable) {
            $result['blockers'][] = ['code' => 'RELATION_PREDICATE_UNSUPPORTED', 'predicate' => $intent['predicate']];
            return;
        }
        if (!$definition->allows((string) $intent['source_type'], (string) $intent['target_type'])) {
            $result['blockers'][] = [
                'code' => 'RELATION_ENDPOINT_TYPES_UNSUPPORTED',
                'predicate' => $intent['predicate'],
                'source_type' => $intent['source_type'],
                'target_type' => $intent['target_type'],
            ];
            return;
        }

        $references = [];
        foreach (['source', 'target'] as $side) {
            try {
                $reference = new NodeReference((string) $intent[$side . '_type'], (string) $intent[$side . '_uuid']);
                $resolver = $this->endpoints->resolver($reference->endpoint_type);
                $reference = $this->endpoints->assertExists($reference);
                $state = is_callable($this->endpointState) ? ($this->endpointState)($reference) : null;
                if (!is_array($state)) {
                    $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_NOT_FOUND', 'endpoint_type' => $reference->endpoint_type, 'endpoint_uuid' => $reference->endpoint_key];
                    return;
                }
                if (($state['active'] ?? false) !== true) {
                    $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_INACTIVE', 'endpoint_type' => $reference->endpoint_type, 'endpoint_uuid' => $reference->endpoint_key];
                    return;
                }
                if (!$resolver instanceof EndpointRevisionReader) throw new \RuntimeException('RELATION_ENDPOINT_REVISION_UNAVAILABLE');
                $revision = (int) ($state['revision'] ?? $resolver->revision($reference) ?? 0);
                if ($revision < 1) throw new \RuntimeException('RELATION_ENDPOINT_REVISION_UNAVAILABLE');
                $references[$side] = ['reference' => $reference, 'revision' => $revision];
            } catch (\Throwable $error) {
                $code = strtoupper(trim($error->getMessage()));
                $result['blockers'][] = [
                    'code' => $code === 'RELATION_ENDPOINT_REVISION_UNAVAILABLE'
                        ? 'RELATION_' . strtoupper($side) . '_REVISION_UNAVAILABLE'
                        : 'RELATION_' . strtoupper($side) . '_UNRESOLVED',
                    'endpoint_type' => $intent[$side . '_type'],
                    'endpoint_uuid' => $intent[$side . '_uuid'],
                ];
                return;
            }
        }

        $packet = $intent + [
            'source_revision' => $references['source']['revision'],
            'target_revision' => $references['target']['revision'],
        ];
        $existing = is_callable($this->relationState) ? ($this->relationState)($packet) : null;
        if (is_array($existing) && strtoupper((string) ($existing['status'] ?? '')) === 'RETIRED') {
            $result['blockers'][] = ['code' => 'RELATION_RETIRED_REQUIRES_EXPLICIT_REACTIVATION'] + $packet;
            return;
        }
        if (is_array($existing) && (strtoupper((string) ($existing['status'] ?? '')) === 'ACTIVE' || ($existing['active'] ?? false) === true)) {
            $edgeId = trim((string) ($existing['canonical_id'] ?? $existing['id'] ?? ''));
            if ($edgeId === '') {
                $result['blockers'][] = ['code' => 'RELATION_ACTIVE_READBACK_ID_UNAVAILABLE'] + $packet;
                return;
            }
            $result['relation_reuse'][] = [
                'candidate_id' => 'relation-reuse-' . hash('sha256', self::identity($packet) . '|' . $edgeId),
                'status' => 'EXISTING',
                'action' => 'REUSE',
                'canonical_id' => $edgeId,
                'revision' => (int) ($existing['revision'] ?? 0),
                'idempotent' => true,
            ] + $packet;
            return;
        }

        $result['relation_candidates'][] = [
            'candidate_id' => 'relation-candidate-' . hash('sha256', self::identity($packet) . '|' . $packet['source_revision'] . '|' . $packet['target_revision']),
            'action' => 'CREATE',
            'entity_type' => 'relation',
            'predicate' => $packet['predicate'],
            'source_type' => $packet['source_type'],
            'source_uuid' => $packet['source_uuid'],
            'source_revision' => $packet['source_revision'],
            'target_type' => $packet['target_type'],
            'target_uuid' => $packet['target_uuid'],
            'target_revision' => $packet['target_revision'],
            'provenance' => $packet['provenance'],
            'reason' => $packet['reason'],
            'scope' => 'capture',
            'dependencies' => [],
            'review_diagnostics' => [],
        ];
    }

    /** @param array<string,mixed> $packet */
    private static function identity(array $packet): string
    {
        return strtolower(implode('|', [
            trim((string) ($packet['source_type'] ?? '')),
            trim((string) ($packet['source_uuid'] ?? '')),
            trim((string) ($packet['predicate'] ?? '')),
            trim((string) ($packet['target_type'] ?? '')),
            trim((string) ($packet['target_uuid'] ?? '')),
        ]));
    }
}
