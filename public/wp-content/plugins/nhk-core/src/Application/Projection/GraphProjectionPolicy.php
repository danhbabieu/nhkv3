<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Graph\{NodeReference, PredicateRegistry};
use NHK\Core\Domain\Projection\ProjectionRule;

final class GraphProjectionPolicy
{
    public const REVISION = 1;

    /** @var list<ProjectionRule> */
    private array $rules;

    public function __construct(private ?PredicateRegistry $predicates = null)
    {
        $all = ['identity', 'mechanism', 'configuration', 'dial_and_hands', 'music_and_strike', 'identification_rule', 'history', 'classification', 'provenance', 'component', 'sound', 'operation', 'exception', 'other'];
        $this->rules = [
            new ProjectionRule('variant', 'model', ['variant_of'], 'incoming', $all, 1),
            new ProjectionRule('model', 'brand', ['model_of'], 'incoming', array_values(array_diff($all, ['other'])), 1),
            new ProjectionRule('variant', 'brand', ['variant_of', 'model_of'], 'incoming', $all, 2),
        ];
    }

    public function revision(): int { return self::REVISION; }

    public function allowsTraversal(NodeReference $current, string $direction, NodeReference $other, string $predicate): bool
    {
        if (!in_array($direction, ['incoming', 'outgoing'], true)) return false;
        try {
            $definition = ($this->predicates ??= new PredicateRegistry())->get($predicate);
            return $definition->active && $definition->allows(
                $direction === 'outgoing' ? $current->endpoint_type : $other->endpoint_type,
                $direction === 'outgoing' ? $other->endpoint_type : $current->endpoint_type,
            );
        } catch (\Throwable) { return false; }
    }

    /**
     * @param list<array<string,mixed>> $path Path is ordered from claim subject to requested node.
     */
    public function allowsPath(string $sourceType, string $targetType, array $path, string $category, string $state, bool $hasEligibleEvidence = true): bool
    {
        if ($path === [] || count($path) > 2 || !$hasEligibleEvidence && $state !== 'APPROVED') return false;
        $rulePath = $this->rulesForPath($sourceType, $targetType, $path, $category, $state);
        if ($rulePath === null) return false;
        foreach ($path as $index => $hop) {
            if (!$rulePath[$index]->allows(
                (string) ($hop['source_type'] ?? ''),
                (string) ($hop['target_type'] ?? ''),
                (string) ($hop['predicate'] ?? ''),
                (string) ($hop['direction'] ?? 'incoming'),
                $category,
                1,
                $state,
            )) return false;
        }
        return true;
    }

    public function allows(string $sourceType, string $targetType, string $predicate, string $direction, string $category, int $distance, string $state, bool $hasEligibleEvidence = true): bool
    {
        foreach ($this->rules as $rule) if ($rule->allows($sourceType, $targetType, $predicate, $direction, $category, $distance, $state) && ($hasEligibleEvidence || $state === 'APPROVED')) return true;
        return false;
    }

    /** @return list<ProjectionRule>|null */
    private function rulesForPath(string $sourceType, string $targetType, array $path, string $category, string $state): ?array
    {
        if (count($path) === 1) {
            foreach ($this->rules as $rule) if ($rule->sourceType === $sourceType && $rule->targetType === $targetType && count($rule->predicates) === 1) return [$rule];
        }
        if (count($path) === 2 && $sourceType === 'variant' && $targetType === 'brand' && $category !== 'other' && $state === 'APPROVED') {
            $first = $path[0]['predicate'] ?? null;
            $second = $path[1]['predicate'] ?? null;
            $rules = [];
            foreach ($this->rules as $rule) {
                if ($rule->sourceType === 'variant' && $rule->targetType === 'model' && $rule->predicates === ['variant_of']) $rules[0] = $rule;
                if ($rule->sourceType === 'model' && $rule->targetType === 'brand' && $rule->predicates === ['model_of']) $rules[1] = $rule;
            }
            if ($first === 'variant_of' && $second === 'model_of' && count($rules) === 2) return [$rules[0], $rules[1]];
        }
        return null;
    }
}
