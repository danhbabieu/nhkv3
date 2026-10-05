<?php
declare(strict_types=1);
namespace NHK\Core\Application\Dictionary;
final class DictionarySemanticEnrichmentQuery
{
    public function __construct(private DictionaryRelationFacetRegistry $facets, private $ownerReader, private $relationReader) {}
    /** @return array<string,mixed> */
    public function forOwner(string $sourceType, string $sourceId, array $options = []): array
    {
        $limit = max(1, min(50, (int) ($options['limit'] ?? 12)));
        $packets = [];
        foreach ($this->facets->all() as $key => $_) $packets[$key] = ['status'=>'AVAILABLE_EMPTY','items'=>[],'has_more'=>false,'truncation_reason'=>null];
        try { $relations = ($this->relationReader)($sourceType, $sourceId, $limit * 4); } catch (\Throwable) { return ['status'=>'UNAVAILABLE','relation_facets'=>$packets,'registry_version'=>$this->facets->version(),'registry_hash'=>$this->facets->hash()]; }
        $chosen = [];
        foreach ((array) $relations as $relation) {
            if (!is_array($relation) || strtoupper((string) ($relation['state'] ?? 'ACTIVE')) !== 'ACTIVE') continue;
            $origin = is_array($relation['origin'] ?? null) ? $relation['origin'] : ['kind'=>'DIRECT','hop_count'=>0];
            if ((int) ($origin['hop_count'] ?? 99) > 2) continue;
            $targetType = trim((string) ($relation['target_type'] ?? ''));
            $targetId = trim((string) ($relation['target_id'] ?? ''));
            if ($targetType === '' || $targetId === '') continue;
            $owner = ($this->ownerReader)($targetType, $targetId);
            if (!is_array($owner) || ($owner['public'] ?? false) !== true) continue;
            $family = (string) ($owner['family'] ?? ($owner['payload']['family'] ?? ''));
            $facet = $this->facets->facetFor($targetType, $family !== '' ? $family : null);
            if ($facet === null) continue;
            $item = ['target_type'=>$targetType,'target_id'=>$targetId,'payload'=>$owner['payload'] ?? null,'public_identity'=>$owner['public_identity'] ?? ['id'=>$targetId],'public_url'=>$owner['url'] ?? null,'origin'=>$origin,'predicates'=>array_values((array) ($relation['predicates'] ?? [$relation['predicate'] ?? ''])),'via_types'=>array_values((array) ($relation['via_types'] ?? [])),'scope'=>$relation['scope'] ?? null,'availability'=>'AVAILABLE'];
            $rank = [(($origin['kind'] ?? 'DIRECT') === 'DIRECT' ? 0 : 1),(int) ($origin['hop_count'] ?? 99),json_encode($item, JSON_UNESCAPED_SLASHES)];
            $identity = $targetType . ':' . $targetId;
            if (!isset($chosen[$facet][$identity]) || $rank < $chosen[$facet][$identity]['rank']) $chosen[$facet][$identity] = ['rank'=>$rank,'item'=>$item];
        }
        foreach ($chosen as $facet => $items) {
            uasort($items, static fn (array $a,array $b): int => $a['rank'] <=> $b['rank']);
            $values = array_values(array_column($items, 'item'));
            $hasMore = count($values) > $limit;
            $packets[$facet] = ['status'=>$values === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS','items'=>array_slice($values,0,$limit),'has_more'=>$hasMore,'truncation_reason'=>$hasMore ? 'PER_FACET_LIMIT' : null];
        }
        return ['status'=>'AVAILABLE','relation_facets'=>$packets,'registry_version'=>$this->facets->version(),'registry_hash'=>$this->facets->hash()];
    }
}
