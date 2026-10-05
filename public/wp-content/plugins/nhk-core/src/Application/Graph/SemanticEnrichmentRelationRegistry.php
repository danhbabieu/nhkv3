<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

final class SemanticEnrichmentRelationRegistry
{
    public const VERSION = '1.0.0';
    /** @var array<string,true> */
    private const ASSOCIATED_PAIRS = [
        'component:brand' => true,
        'component:movement' => true,
        'component:music' => true,
        'component:classification' => true,
        'movement:music' => true,
        'variant:classification' => true,
    ];

    private const STRONG = ['model_of', 'variant_of', 'uses_movement', 'supports_music', 'configured_with_music', 'observed_playing_music', 'classified_as', 'subtype_of', 'about', 'depicts'];

    public function allows(string $sourceType, string $predicate, string $targetType): bool
    {
        if ($predicate !== 'associated_with') return false;
        if ($sourceType === $targetType) return false;
        return isset(self::ASSOCIATED_PAIRS[$sourceType . ':' . $targetType]);
    }

    /** @param list<string> $candidates */
    public function preferredPredicate(string $sourceType, string $targetType, array $candidates): ?string
    {
        foreach (self::STRONG as $strong) if (in_array($strong, $candidates, true)) return $strong;
        return in_array('associated_with', $candidates, true) && $this->allows($sourceType, 'associated_with', $targetType) ? 'associated_with' : null;
    }

    /** @return list<array{source:string,target:string}> */
    public function associatedPairs(): array
    {
        return array_map(static function (string $pair): array { [$source, $target] = explode(':', $pair, 2); return ['source' => $source, 'target' => $target]; }, array_keys(self::ASSOCIATED_PAIRS));
    }

    public function version(): string { return self::VERSION; }
    public function hash(): string { return hash('sha256', json_encode(['version' => self::VERSION, 'pairs' => self::ASSOCIATED_PAIRS], JSON_UNESCAPED_SLASHES)); }
}
