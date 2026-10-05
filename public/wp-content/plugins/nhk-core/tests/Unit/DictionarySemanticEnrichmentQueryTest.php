<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Dictionary\{DictionaryRelationFacetRegistry,DictionarySemanticEnrichmentQuery};
use PHPUnit\Framework\TestCase;
final class DictionarySemanticEnrichmentQueryTest extends TestCase
{
    public function test_direct_relations_win_dedupe_and_retired_or_private_targets_are_excluded(): void
    {
        $query = new DictionarySemanticEnrichmentQuery(new DictionaryRelationFacetRegistry(), static fn(string $type,string $id): array => ['public'=>!in_array($id,['private','retired'],true),'revision'=>1,'payload'=>['id'=>$id,'title'=>'LIVE '.$id],'url'=>'/entity/'.$id], static fn(string $type,string $id,int $limit): array => [
            ['target_type'=>'brand','target_id'=>'direct','state'=>'ACTIVE','origin'=>['kind'=>'DIRECT','hop_count'=>1],'predicate'=>'about'],
            ['target_type'=>'brand','target_id'=>'direct','state'=>'ACTIVE','origin'=>['kind'=>'DERIVED','hop_count'=>2],'predicate'=>'associated_with'],
            ['target_type'=>'brand','target_id'=>'private','state'=>'ACTIVE','origin'=>['kind'=>'DIRECT','hop_count'=>1],'predicate'=>'about'],
            ['target_type'=>'brand','target_id'=>'retired','state'=>'RETIRED','origin'=>['kind'=>'DIRECT','hop_count'=>1],'predicate'=>'about'],
        ]);
        $result = $query->forOwner('component','component-1', ['limit'=>10]);
        self::assertSame('AVAILABLE_WITH_ITEMS', $result['relation_facets']['brands']['status']);
        self::assertCount(1, $result['relation_facets']['brands']['items']);
        self::assertSame('DIRECT', $result['relation_facets']['brands']['items'][0]['origin']['kind']);
        self::assertSame('LIVE direct', $result['relation_facets']['brands']['items'][0]['payload']['title']);
    }
}
