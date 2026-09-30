<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use PHPUnit\Framework\TestCase;

final class UniversalStructuredSemanticAdapterParityTest extends TestCase
{
    /** @dataProvider sourceKinds */
    public function test_source_kinds_share_lexical_normalization_and_query_seeds(string $sourceKind): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'Mẫu Alpha-Beta có 17 alpha 19 beta, âm trầm và rất lực.',
            'locale' => 'vi-VN',
            'source_kind' => $sourceKind,
            'source_identifier' => 'source-' . $sourceKind,
            'raw_or_derived' => 'RAW',
            'observation_strength' => 'WEAK',
            'hints' => [
                ['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha'],
                ['kind' => 'STRUCTURAL_UNIT', 'term' => 'beta'],
            ],
            'metadata' => [
                'editorial_signals' => [['term' => 'âm trầm', 'kind' => 'evaluation']],
            ],
        ])->toArray();

        self::assertSame(['alpha-beta', '17 alpha 19 beta'], array_column($packet['semantic_query_seeds'], 'normalized_form'));
        self::assertSame($sourceKind, $packet['source_context']['source_kind']);
        self::assertSame('RAW', $packet['source_context']['raw_or_derived']);
        self::assertSame('WEAK', $packet['source_context']['observation_strength']);
        self::assertContains('âm trầm', array_column($packet['editorial_signals'], 'term'));
    }

    /** @return iterable<string,array{string}> */
    public static function sourceKinds(): iterable
    {
        yield 'human_chat' => ['human_chat'];
        yield 'article' => ['article'];
        yield 'video_transcript' => ['video_transcript'];
        yield 'media_caption' => ['media_caption'];
    }
}
