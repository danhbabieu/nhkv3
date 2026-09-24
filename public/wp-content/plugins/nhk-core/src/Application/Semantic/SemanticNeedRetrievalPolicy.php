<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Bounded, registry-filtered retrieval policy for one transient need. */
final class SemanticNeedRetrievalPolicy
{
    public const TIERS = ['EXACT', 'SUBJECT_BROADENED', 'CONCEPT_BROADENED', 'APPLICABLE_RELATED', 'BACKGROUND_CONTEXT'];

    /** @param list<string> $registeredTiers @return array<string,mixed> */
    public static function defaults(array $registeredTiers = self::TIERS): array
    {
        $registered = array_fill_keys(array_map(static fn (mixed $tier): string => strtoupper(trim((string) $tier)), $registeredTiers), true);
        $tiers = array_values(array_filter(self::TIERS, static fn (string $tier): bool => isset($registered[$tier])));
        return [
            'opportunity_budget' => 20,
            'result_limit' => 50,
            'expansion_budget' => 50,
            'max_expansion_rounds' => min(4, count($tiers)),
            'tiers' => $tiers,
        ];
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public static function normalize(array $policy): array
    {
        $defaults = self::defaults();
        $tiers = array_values(array_unique(array_map(static fn (mixed $tier): string => strtoupper(trim((string) $tier)), (array) ($policy['tiers'] ?? $defaults['tiers']))));
        foreach ($tiers as $tier) {
            if (!in_array($tier, self::TIERS, true)) {
                throw new \InvalidArgumentException('Unknown semantic relaxation tier.');
            }
        }
        foreach (['opportunity_budget', 'result_limit', 'expansion_budget', 'max_expansion_rounds'] as $key) {
            if (isset($policy[$key]) && (!is_int($policy[$key]) || $policy[$key] < 0)) {
                throw new \InvalidArgumentException('Semantic retrieval budgets must be non-negative integers.');
            }
        }
        return [
            'opportunity_budget' => min(200, (int) ($policy['opportunity_budget'] ?? $defaults['opportunity_budget'])),
            'result_limit' => min(200, max(1, (int) ($policy['result_limit'] ?? $defaults['result_limit']))),
            'expansion_budget' => min(200, (int) ($policy['expansion_budget'] ?? $defaults['expansion_budget'])),
            'max_expansion_rounds' => min(4, (int) ($policy['max_expansion_rounds'] ?? $defaults['max_expansion_rounds'])),
            'tiers' => $tiers,
        ];
    }
}
