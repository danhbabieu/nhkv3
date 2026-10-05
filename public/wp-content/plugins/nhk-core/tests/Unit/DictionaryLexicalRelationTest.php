<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryLexicalRelationPolicy;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class DictionaryLexicalRelationTest extends TestCase
{
    public function test_entry_level_related_relation_contains_ids_only(): void
    {
        $source = UuidCodec::newV7();
        $target = UuidCodec::newV7();
        $relation = DictionaryLexicalRelation::create(
            UuidCodec::newV7(),
            $source,
            null,
            $target,
            null,
            DictionaryLexicalRelation::RELATED,
            ['source' => 'CURATOR'],
            'lex-1',
        );

        $payload = $relation->toArray();

        self::assertSame(DictionaryLexicalRelation::RELATED, $payload['relation_kind']);
        self::assertSame($source, $payload['source_entry_uuid']);
        self::assertNull($payload['source_sense_uuid']);
        self::assertArrayNotHasKey('label', $payload);
        self::assertArrayNotHasKey('definition', $payload);
        self::assertArrayNotHasKey('url', $payload);
    }

    public function test_broader_requires_sense_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DICTIONARY_LEXICAL_RELATION_SENSE_REQUIRED');

        DictionaryLexicalRelationPolicy::assertPair(
            UuidCodec::newV7(),
            null,
            UuidCodec::newV7(),
            null,
            DictionaryLexicalRelation::BROADER,
        );
    }

    public function test_relation_kinds_and_symmetric_policy_are_registered(): void
    {
        self::assertSame([
            'RELATED',
            'SAME_TERM_FAMILY',
            'BROADER',
            'NARROWER',
            'NEAR_SYNONYM',
        ], DictionaryLexicalRelationPolicy::kinds());
        self::assertTrue(DictionaryLexicalRelationPolicy::isSymmetric(DictionaryLexicalRelation::RELATED));
        self::assertFalse(DictionaryLexicalRelationPolicy::isSymmetric(DictionaryLexicalRelation::BROADER));
    }
}
