<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Dictionary\DictionaryDetailQuery;
use NHK\Core\Application\Dictionary\DictionaryRelatedTermProjection;
use NHK\Core\Domain\Dictionary\{DictionaryConcept,LexicalEntry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;
final class DictionaryPhase2ProjectionIntegrationTest extends TestCase
{
    public function test_detail_preserves_legacy_aliases_and_exposes_generic_relation_facets(): void
    {
        $entryId=UuidCodec::newV7();$senseId=UuidCodec::newV7();
        $entry=new LexicalEntry($entryId,'component','component','APPROVED',null,[],1,[$senseId]);
        $sense=new DictionaryConcept($senseId,'component','Definition','APPROVED',null,null,null,[],1);
        $query=new DictionaryDetailQuery(
            new class($sense) { public function __construct(private object $sense){} public function listLabels(string $id):array{return [];} },
            new class($entry,$sense) { public function __construct(private object $entry,private object $sense){} public function findByPublicSlug(string $slug):?object{return $this->entry;} public function listSenses(object $entry):array{return [$this->sense];} public function semanticReference(string $e,string $s):array{return ['status'=>'AVAILABLE','type'=>'component','id'=>'owner-1','revision'=>2];} public function listForms(object $entry):array{return [];} },
            null,
            static fn(string $type,string $id):array=>['identity'=>['id'=>$id],'relation_facets'=>['brands'=>['status'=>'AVAILABLE_WITH_ITEMS','items'=>[['target_id'=>'brand-1']]]],'relation_sections'=>['brands'=>[['id'=>'brand-1','title'=>'Brand']]]],
        );
        $result=$query->detail('component');
        self::assertArrayHasKey('relation_facets',$result['item']['senses'][0]);
        self::assertSame($result['item']['senses'][0]['relation_facets']['brands']['items'],$result['item']['senses'][0]['brands']['items']);
    }
    public function test_explicit_lexical_relation_is_marked_explicit_and_precedes_derived_duplicate(): void
    {
        $source=UuidCodec::newV7();$target=UuidCodec::newV7();
        $entries=new class($source,$target) { public function __construct(private string $source,private string $target){} public function findById(string $id):?object{return $id===$this->target?new LexicalEntry($this->target,'target','target','APPROVED',null,['public_slug'=>'target'],1,[]):null;} public function findEntriesBySemanticReference(string $t,string $id,int $limit):array{return [new LexicalEntry($this->target,'target','target','APPROVED',null,['public_slug'=>'target'],1,[])];} public function listForEntry(string $id,int $afterId=0,int $limit=100,bool $includeRetired=false):array{return ['items'=>[new \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation(UuidCodec::newV7(),$this->source,null,$this->target,null,'RELATED',[],'lexical-key')],'next_cursor'=>null];} };
        $projection=new DictionaryRelatedTermProjection($entries,static fn(string $t,string $id):array=>[['type'=>'brand','id'=>'brand-1','origin'=>['kind'=>'DERIVED','hop_count'=>2]]],$entries);
        $items=$projection->forReference($source,'brand','brand-1',12);
        self::assertSame('EXPLICIT_LEXICAL',$items[0]['origin']);
        self::assertSame('RELATED',$items[0]['relation_kind']);
    }
}
