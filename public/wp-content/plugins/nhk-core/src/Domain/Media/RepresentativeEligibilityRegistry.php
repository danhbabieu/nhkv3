<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

/**
 * Typed recipes for bounded representative discovery. Graph reachability is
 * deliberately absent: callers must provide an explicit scoped candidate and
 * suitability evidence before this registry can admit it.
 */
final class RepresentativeEligibilityRegistry
{
    /** @return array<string,array{minimum_scope:string,allow_auto:bool}> */
    public function recipes(): array
    {
        return [
            'specimen' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'variant' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'model' => ['minimum_scope' => 'broader', 'allow_auto' => true],
            'classification' => ['minimum_scope' => 'representative', 'allow_auto' => true],
            'product' => ['minimum_scope' => 'exact', 'allow_auto' => true],
        ];
    }

    /** @param array<string,mixed> $candidate */
    public function isEligible(string $targetType, array $candidate): bool
    {
        $recipe = $this->recipes()[strtolower(trim($targetType))] ?? null;
        if (!is_array($recipe) || ($recipe['allow_auto'] ?? false) !== true) return false;
        if (($candidate['scope_justified'] ?? false) !== true) return false;
        $scope = strtolower(trim((string) ($candidate['scope'] ?? $candidate['scope_strength'] ?? '')));
        if ($scope !== '' && $scope !== $recipe['minimum_scope'] && !($recipe['minimum_scope'] === 'broader' && $scope === 'exact')) return false;
        if (($candidate['representative_relevance'] ?? true) !== true) return false;
        return true;
    }
}
