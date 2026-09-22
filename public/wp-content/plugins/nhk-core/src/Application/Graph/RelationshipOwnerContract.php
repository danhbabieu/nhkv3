<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

/** Shared connector boundary normalization. It does not perform owner writes. */
final class RelationshipOwnerContract
{
    public const GRAPH = 'graph';
    public const MEDIA_USAGE = 'media_usage';
    public const EVIDENCE = 'evidence';

    /** @param array<string,mixed> $operation */
    public static function normalize(array $operation): array
    {
        if (!array_key_exists('relationship_kind', $operation) || trim((string) $operation['relationship_kind']) === '') {
            $operation['relationship_kind'] = self::GRAPH;
        }
        return $operation;
    }

    /** @param array<string,mixed> $input */
    public static function normalizeCapture(array $input): array
    {
        if (is_array($input['relationship_operations'] ?? null)) {
            $input['relationship_operations'] = array_map(
                static fn (mixed $operation): mixed => is_array($operation) ? self::normalize($operation) : $operation,
                $input['relationship_operations'],
            );
        }
        return $input;
    }

    /** @param array<string,mixed> $operation */
    public static function assertCaptureOperation(array $operation): void
    {
        $kind = trim((string) ($operation['relationship_kind'] ?? self::GRAPH));
        $operationName = strtoupper(trim((string) ($operation['operation'] ?? '')));
        $required = match ($kind) {
            self::GRAPH => ['ADD', 'REPLACE', 'REMOVE', 'REACTIVATE'],
            self::MEDIA_USAGE => ['ADD', 'REPLACE', 'REMOVE', 'REPRESENTATIVE_BIND'],
            self::EVIDENCE => ['CREATE', 'UPDATE', 'RETIRE', 'REACTIVATE'],
            default => throw new \InvalidArgumentException('RELATIONSHIP_KIND_UNSUPPORTED'),
        };
        if (!in_array($operationName, $required, true)) throw new \InvalidArgumentException('RELATIONSHIP_OPERATION_INVALID');
        if ($kind === self::GRAPH && (!is_array($operation['source'] ?? null) || !is_array($operation['target'] ?? null) || trim((string) ($operation['predicate'] ?? '')) === '')) {
            throw new \InvalidArgumentException('GRAPH_RELATION_OPERATION_FIELDS_REQUIRED');
        }
        if ($kind === self::EVIDENCE && (!isset($operation['claim_uuid'], $operation['claim_revision'], $operation['source_uuid'], $operation['source_revision'])) ) {
            throw new \InvalidArgumentException('EVIDENCE_RELATION_OPERATION_FIELDS_REQUIRED');
        }
    }

    /** @param array<string,mixed> $input */
    public static function assertCaptureOperations(array $input): void
    {
        foreach ((array) ($input['relationship_operations'] ?? []) as $operation) {
            if (!is_array($operation)) throw new \InvalidArgumentException('RELATION_OPERATION_MALFORMED');
            self::assertCaptureOperation($operation);
        }
    }

    /** Route unified MediaUsage input through the established MediaBindingService input. */
    public static function routeMediaCompatibility(array $input): array
    {
        $operations = is_array($input['relationship_operations'] ?? null) ? $input['relationship_operations'] : [];
        if ($operations === []) return $input;
        $graphOrEvidence = [];
        $media = is_array($input['media_operations'] ?? null) ? $input['media_operations'] : [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || ($operation['relationship_kind'] ?? self::GRAPH) !== self::MEDIA_USAGE) {
                $graphOrEvidence[] = $operation;
                continue;
            }
            $compatibility = $operation;
            unset($compatibility['relationship_kind']);
            $compatibility['operation'] = strtolower((string) ($compatibility['operation'] ?? ''));
            if ($compatibility['operation'] === 'representative_bind') $compatibility['operation'] = 'representative_bind';
            $media[] = $compatibility;
        }
        $input['relationship_operations'] = $graphOrEvidence;
        if ($media !== []) $input['media_operations'] = array_values($media);
        return $input;
    }
}
