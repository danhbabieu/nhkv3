<?php
declare(strict_types=1);

namespace NHK\Core\Application\Collector;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;

/**
 * Read-only resolver for the approved Collector seed batch.
 * Creation is intentionally outside this class until evidence and a governed
 * semantic operation are available; an unresolved candidate is never a node.
 */
final class CollectorAuthoritySeedReconciler
{
    /** @var list<array{stable_key:string,facet:string}> */
    private const CANDIDATES = [
        ['stable_key' => 'nhk:classification:case-style.chalet', 'facet' => 'case_styles'],
        ['stable_key' => 'nhk:classification:case-style.jagdstueck', 'facet' => 'case_styles'],
        ['stable_key' => 'nhk:classification:case-style.postmodern', 'facet' => 'case_styles'],
        ['stable_key' => 'nhk:classification:production-scale.series', 'facet' => 'production_scale'],
        ['stable_key' => 'nhk:classification:production-scale.small-series', 'facet' => 'production_scale'],
        ['stable_key' => 'nhk:classification:craft-mode.machine-assisted-hand-finished', 'facet' => 'craft_modes'],
        ['stable_key' => 'nhk:classification:night-shutoff.manual', 'facet' => 'night_shutoff'],
        ['stable_key' => 'nhk:classification:night-shutoff.automatic-mechanical', 'facet' => 'night_shutoff'],
        ['stable_key' => 'nhk:classification:night-shutoff.light-sensor', 'facet' => 'night_shutoff'],
    ];

    public function __construct(private AuthorityRepository $authority) {}

    /** @return list<array<string,mixed>> */
    public function reconcile(): array
    {
        $existing = $this->authority->listByType('classification', true);
        $rows = [];
        foreach (self::CANDIDATES as $candidate) {
            $exact = $this->findExact($existing, $candidate['stable_key']);
            if ($exact instanceof AuthorityEntity) {
                $rows[] = $candidate + ['status' => 'REUSE', 'canonical_id' => $exact->canonicalId, 'name' => $exact->canonicalName];
                continue;
            }
            $near = $this->nearMatches($existing, $candidate['stable_key']);
            $rows[] = $candidate + [
                'status' => $near === [] ? 'NO_MATCH_CREATE_REQUIRES_EVIDENCE' : 'REVIEW_NEAR_MATCH',
                'near_matches' => $near,
                'canonical_id' => null,
            ];
        }
        return $rows;
    }

    /** @param list<AuthorityEntity> $entities */
    private function findExact(array $entities, string $stableKey): ?AuthorityEntity
    {
        foreach ($entities as $entity) if ($entity instanceof AuthorityEntity && $entity->stableKey === $stableKey) return $entity;
        return null;
    }

    /** @param list<AuthorityEntity> $entities @return list<array<string,string>> */
    private function nearMatches(array $entities, string $stableKey): array
    {
        $needle = $this->tokens($stableKey);
        $matches = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof AuthorityEntity) continue;
            $haystack = array_merge($this->tokens($entity->stableKey), $this->tokens($entity->canonicalName));
            $overlap = array_intersect($needle, $haystack);
            if ($overlap === []) continue;
            $matches[] = ['canonical_id' => $entity->canonicalId, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'matched_tokens' => implode(',', array_values(array_unique($overlap)))];
        }
        usort($matches, static fn (array $left, array $right): int => [$left['stable_key'], $left['canonical_id']] <=> [$right['stable_key'], $right['canonical_id']]);
        return array_slice($matches, 0, 10);
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $value = strtolower((string) preg_replace('/^nhk:classification:/', '', trim($value)));
        $structural = ['case', 'style', 'production', 'scale', 'craft', 'mode', 'night', 'shutoff', 'classification'];
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $value) ?: [], static fn (string $token): bool => strlen($token) > 2 && !in_array($token, $structural, true)));
    }
}
