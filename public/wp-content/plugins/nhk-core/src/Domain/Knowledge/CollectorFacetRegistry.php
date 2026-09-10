<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

/** Executable vocabulary for the Collector Profile projection. */
final class CollectorFacetRegistry
{
    public const METADATA_KEY = 'collector_facet';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'display_form', 'dimensions', 'dating', 'case_styles', 'motifs',
            'materials', 'craft_modes', 'production_scale', 'movement_family',
            'running_duration', 'drive_system', 'functions', 'sound', 'music',
            'automata', 'night_shutoff', 'condition_guidance', 'originality_guidance',
            'provenance', 'rarity', 'origin_certification',
        ];
    }

    public static function isValid(string $facet): bool
    {
        return in_array($facet, self::all(), true);
    }

    public static function isValidForScope(string $facet, string $scope): bool
    {
        if (!self::isValid($facet)) return false;
        return $facet !== 'automata' || in_array($scope, ['model', 'variant', 'specimen_observation'], true);
    }

    /** @param array<string,mixed> $metadata */
    public static function resolve(array $metadata): string
    {
        $requested = trim((string) ($metadata[self::METADATA_KEY] ?? ''));
        if ($requested !== '') {
            return self::isValidForScope($requested, (string) ($metadata['scope'] ?? '')) ? $requested : '';
        }
        return match ((string) ($metadata['facet'] ?? '')) {
            'chronology' => 'dating',
            'movement' => 'movement_family',
            'music' => 'music',
            'provenance' => 'provenance',
            'rarity_frequency' => 'rarity',
            'specimen_observation' => 'condition_guidance',
            default => '',
        };
    }
}
