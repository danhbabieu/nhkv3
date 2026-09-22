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
        private ?ClassifiedAsPolicy $classifiedAs = null,
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
            if ($sourceUuid === '' || $predicate === '' || $targetUuid === '') {
                $result['blockers'][] = ['code' => 'RELATION_INTENT_IDENTITY_REQUIRED'];
                continue;
            }
            $provenance = trim((string) ($intent['provenance'] ?? ''));
            // A typed user relation is explicit user knowledge unless the
            // caller supplies another registered provenance.  The former
            // EXPLICIT_USER_RELATION value was never in the canonical
            // provenance registry and made valid classified_as intents fail
            // closed as CLASSIFICATION_SCOPE_UNSUPPORTED.
            if ($provenance === '' && $predicate === 'classified_as') $provenance = 'EXPLICIT_USER_KNOWLEDGE';
            $normalized[] = [
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'predicate' => $predicate,
                'target_type' => $targetType,
                'target_uuid' => $targetUuid,
                'provenance' => $provenance !== '' ? $provenance : 'EXPLICIT_USER_RELATION',
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
        try {
            $definition = $this->predicates->get((string) $intent['predicate']);
        } catch (\Throwable) {
            $result['blockers'][] = ['code' => 'RELATION_PREDICATE_UNSUPPORTED', 'predicate' => $intent['predicate']];
            return;
        }
        foreach (['source', 'target'] as $side) {
            $requestedType = strtolower(trim((string) ($intent[$side . '_type'] ?? '')));
            $allowedTypes = $side === 'source' ? $definition->allowed_source_types : $definition->allowed_target_types;
            if ($requestedType !== '' && !in_array($requestedType, $allowedTypes, true)) {
                $result['blockers'][] = ['code' => 'RELATION_ENDPOINT_TYPES_UNSUPPORTED', 'predicate' => $intent['predicate'], 'source_type' => $intent['source_type'] ?? '', 'target_type' => $intent['target_type'] ?? ''];
                return;
            }
        }

        $references = [];
        foreach (['source', 'target'] as $side) {
            $reference = $this->resolveEndpoint($side, $intent, $definition, $result);
            if ($reference === null) return;
            $references[$side] = $reference;
        }
        $intent['source_type'] = $references['source']['reference']->endpoint_type;
        $intent['target_type'] = $references['target']['reference']->endpoint_type;

        if (!$definition->allows((string) $intent['source_type'], (string) $intent['target_type'])) {
            $result['blockers'][] = [
                'code' => 'RELATION_ENDPOINT_TYPES_UNSUPPORTED',
                'predicate' => $intent['predicate'],
                'source_type' => $intent['source_type'],
                'target_type' => $intent['target_type'],
            ];
            return;
        }
        if (!$definition->allow_self_relation
            && $intent['source_type'] === $intent['target_type']
            && strtolower((string) $intent['source_uuid']) === strtolower((string) $intent['target_uuid'])) {
            $result['blockers'][] = ['code' => 'RELATION_SELF_FORBIDDEN', 'predicate' => $intent['predicate'], 'source_type' => $intent['source_type'], 'target_type' => $intent['target_type'], 'source_uuid' => $intent['source_uuid'], 'target_uuid' => $intent['target_uuid']];
            return;
        }

        $packet = $intent + [
            'source_revision' => $references['source']['revision'],
            'target_revision' => $references['target']['revision'],
        ];
        if ($intent['predicate'] === 'classified_as') {
            try {
                ($this->classifiedAs ?? new ClassifiedAsPolicy())->assertCandidate([
                    'source_type' => $packet['source_type'],
                    'scope' => $packet['source_type'],
                    'provenance' => $packet['provenance'],
                    'target_type' => $packet['target_type'],
                    'target_family' => $references['target']['family'] ?? null,
                    'target_active' => $references['target']['active'] ?? null,
                ]);
            } catch (\Throwable $error) {
                $result['blockers'][] = ['code' => $error->getMessage(), 'predicate' => 'classified_as', 'source_uuid' => $packet['source_uuid'], 'target_uuid' => $packet['target_uuid']];
                return;
            }
        }
        $existing = is_callable($this->relationState) ? ($this->relationState)($packet) : null;
        if (is_array($existing) && strtoupper((string) ($existing['status'] ?? '')) === 'CARDINALITY_CONFLICT') {
            $result['blockers'][] = ['code' => 'RELATION_CARDINALITY_CONFLICT'] + $packet + $existing;
            return;
        }
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
            'target_family' => $references['target']['family'] ?? null,
            'provenance' => $packet['provenance'],
            'reason' => $packet['reason'],
            'scope' => $packet['predicate'] === 'classified_as' ? $packet['source_type'] : 'capture',
            'dependencies' => [],
            'review_diagnostics' => [],
        ];
    }

    /** @param array<string,mixed> $intent @param \NHK\Core\Domain\Graph\PredicateDefinition $definition @param array<string,mixed> $result @return array{reference:NodeReference,revision:int,family:?string,active:bool}|null */
    private function resolveEndpoint(string $side, array $intent, \NHK\Core\Domain\Graph\PredicateDefinition $definition, array &$result): ?array
    {
        $uuid = trim((string) ($intent[$side . '_uuid'] ?? ''));
        $requestedType = strtolower(trim((string) ($intent[$side . '_type'] ?? '')));
        if ($uuid === '' || !UuidCodec::isValid($uuid)) {
            $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_UUID_INVALID', 'endpoint_type' => $requestedType, 'endpoint_uuid' => $uuid];
            return null;
        }

        $types = $side === 'source' ? $definition->allowed_source_types : $definition->allowed_target_types;
        $matches = [];
        foreach ($types as $type) {
            if ($requestedType !== '' && $requestedType !== $type) continue;
            try {
                $resolver = $this->endpoints->resolver($type);
                $reference = $this->endpoints->assertExists(new NodeReference($type, $uuid));
                $state = is_callable($this->endpointState) ? ($this->endpointState)($reference) : null;
                if (!is_array($state)) continue;
                if (($state['active'] ?? false) !== true) {
                    if ($requestedType !== '') $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_INACTIVE', 'endpoint_type' => $type, 'endpoint_uuid' => $uuid];
                    continue;
                }
                if (!$resolver instanceof EndpointRevisionReader) continue;
                $revision = (int) ($state['revision'] ?? $resolver->revision($reference) ?? 0);
                if ($revision > 0) $matches[] = ['reference' => $reference, 'revision' => $revision, 'family' => isset($state['family']) ? (string) $state['family'] : null, 'active' => true];
            } catch (\Throwable) {
                continue;
            }
        }
        if (count($matches) === 1) return $matches[0];
        if (count($matches) > 1) {
            $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_TYPE_AMBIGUOUS', 'endpoint_uuid' => $uuid, 'candidate_types' => array_values(array_map(static fn (array $match): string => $match['reference']->endpoint_type, $matches))];
            return null;
        }
        $result['blockers'][] = ['code' => 'RELATION_' . strtoupper($side) . '_NOT_FOUND', 'endpoint_type' => $requestedType, 'endpoint_uuid' => $uuid];
        return null;
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
