<?php
declare(strict_types=1);
namespace NHK\Core\Application\Dictionary;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation as R;
use NHK\Core\Shared\Uuid\UuidCodec;
final class DictionaryLexicalRelationPolicy
{
    public static function kinds():array { return [R::RELATED,R::SAME_TERM_FAMILY,R::BROADER,R::NARROWER,R::NEAR_SYNONYM]; }
    public static function isSymmetric(string $kind):bool { return in_array($kind,[R::RELATED,R::SAME_TERM_FAMILY,R::NEAR_SYNONYM],true); }
    public static function assertPair(string $sourceEntry,?string $sourceSense,string $targetEntry,?string $targetSense,string $kind):void
    { foreach([$sourceEntry,$targetEntry] as $id) if(!UuidCodec::isValid($id)) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_ENTRY_UUID_INVALID'); if($sourceSense!==null&&!UuidCodec::isValid($sourceSense)||$targetSense!==null&&!UuidCodec::isValid($targetSense)) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_SENSE_UUID_INVALID'); if(!in_array($kind,self::kinds(),true)) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_KIND_INVALID'); if($sourceEntry===$targetEntry&&$sourceSense===$targetSense) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_SELF_FORBIDDEN'); if(in_array($kind,[R::BROADER,R::NARROWER,R::NEAR_SYNONYM],true)&&($sourceSense===null||$targetSense===null)) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_SENSE_REQUIRED'); if(($sourceSense===null)!==($targetSense===null)) throw new \InvalidArgumentException('DICTIONARY_LEXICAL_RELATION_GRANULARITY_MISMATCH'); }
}
