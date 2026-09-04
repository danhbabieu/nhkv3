<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\Seo\SeoReadinessResult;

final class EntitySeoProjection
{
    public function __construct(private EntitySeoProfileRegistry $profiles = new EntitySeoProfileRegistry(), private ?SeoReadinessPolicy $readiness = null, private ?SeoIndexabilityPolicy $indexability = null) {}

    /** @param array<string,mixed> $entity @param array<string,mixed> $dependencies @return array<string,mixed> */
    public function project(array $entity, array $dependencies = []): array
    {
        $type = trim((string) ($entity['type'] ?? ''));
        $profile = $this->profiles->has($type) ? $type : null;
        $snapshot = $entity + $dependencies;
        if (!array_key_exists('canonical_url', $snapshot)) $snapshot['canonical_url'] = $snapshot['public_url'] ?? '';
        if ($profile === null) $snapshot['canonical_identity'] = false;
        $ready = ($this->readiness ??= new SeoReadinessPolicy())->evaluate($snapshot);
        $index = ($this->indexability ??= new SeoIndexabilityPolicy())->evaluate([
            'readiness' => $ready->status(),
            'reasons' => $ready->reasons(),
            'public_eligible' => ($snapshot['public_eligible'] ?? false) === true,
            'canonical_url' => $snapshot['public_url'] ?? null,
            'rendered_url' => $snapshot['rendered_url'] ?? ($snapshot['public_url'] ?? null),
        ]);
        $profileData = ['name' => trim((string) ($entity['name'] ?? ''))];
        foreach (['aliases', 'summary', 'facts', 'parent', 'relations', 'articles', 'videos', 'media', 'observations'] as $key) if (array_key_exists($key, $entity)) $profileData[$key] = $entity[$key];
        if ($type === 'product') unset($profileData['specimen']);
        return [
            'profile' => $profile,
            'canonical_id' => $entity['canonical_id'] ?? null,
            'canonical' => $index->indexable() ? $entity['public_url'] : null,
            'profile_data' => $profileData,
            'readiness' => $ready->status(),
            'reasons' => array_values(array_unique([...$ready->reasons(), ...$index->reasons()])),
            'indexable' => $index->indexable(),
            'structured_data' => $ready->structuredDataNotApplicable() ? ['status' => SeoReadinessResult::NOT_APPLICABLE, 'reasons' => $ready->structuredDataReasons()] : ['status' => 'APPLICABLE'],
        ];
    }
}
