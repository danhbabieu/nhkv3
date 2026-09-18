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
            // These recipes are the shared representative-media policy for
            // registered Authority endpoints.  Capture staging admission and
            // bounded representative discovery consume the same table; do not
            // duplicate this allow-list in an entrypoint or environment guard.
            'brand' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'specimen' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'variant' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'model' => ['minimum_scope' => 'broader', 'allow_auto' => true],
            'classification' => ['minimum_scope' => 'representative', 'allow_auto' => true],
            'movement' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'music' => ['minimum_scope' => 'exact', 'allow_auto' => true],
            'component' => ['minimum_scope' => 'exact', 'allow_auto' => true],
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
        // Exact UUID/stable-key binding is the strongest bounded evidence and
        // satisfies every less-specific representative recipe.  This keeps
        // Capture admission and discovery on the same monotonic policy.
        if ($scope !== '' && $scope !== 'exact' && $scope !== $recipe['minimum_scope']) return false;
        if (($candidate['representative_relevance'] ?? true) !== true) return false;
        return true;
    }
}
