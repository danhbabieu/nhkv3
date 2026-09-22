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
