<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryLinkPlanner;
use PHPUnit\Framework\TestCase;

final class DictionaryLinkPlannerTest extends TestCase
{
    public function test_longest_phrase_wins_and_nested_matches_are_not_emitted(): void
    {
        $planner = new DictionaryLinkPlanner();
        $plan = $planner->plan('Cơ chế ngắt chuông đêm giúp người dùng chủ động hơn.', [
            ['term' => 'chuông', 'concept_id' => 'bell', 'url' => '/tu-dien/chuong/'],
            ['term' => 'ngắt chuông', 'concept_id' => 'silence', 'url' => '/tu-dien/ngat-chuong/'],
            ['term' => 'ngắt chuông đêm', 'concept_id' => 'night-silence', 'url' => '/tu-dien/ngat-chuong-dem/'],
        ]);

        self::assertCount(1, $plan);
        self::assertSame('ngắt chuông đêm', mb_strtolower($plan[0]['text'], 'UTF-8'));
        self::assertSame('night-silence', $plan[0]['concept_id']);
    }

    public function test_only_first_occurrence_of_a_concept_is_linked(): void
    {
        $planner = new DictionaryLinkPlanner();
        $plan = $planner->plan('Westminster mở đầu. Sau đó Westminster lặp lại.', [
            ['term' => 'Westminster', 'concept_id' => 'music-westminster', 'url' => '/ban-nhac/westminster/'],
        ]);

        self::assertCount(1, $plan);
        self::assertSame(0, $plan[0]['start']);
    }

    public function test_ambiguous_or_unresolved_items_are_ignored(): void
    {
        $planner = new DictionaryLinkPlanner();
        $plan = $planner->plan('Côn và khóa ngựa.', [
            ['term' => 'Côn', 'concept_id' => 'ambiguous', 'url' => null],
            ['term' => 'Khóa ngựa', 'concept_id' => 'lock', 'url' => '/linh-kien/khoa-ngua/'],
        ]);

        self::assertCount(1, $plan);
        self::assertSame('lock', $plan[0]['concept_id']);
    }

    public function test_word_boundaries_prevent_partial_matches_inside_other_words(): void
    {
        $planner = new DictionaryLinkPlanner();
        $plan = $planner->plan('Một cụm giảwestminsterx không phải thuật ngữ.', [
            ['term' => 'Westminster', 'concept_id' => 'music-westminster', 'url' => '/ban-nhac/westminster/'],
        ]);

        self::assertSame([], $plan);
    }
}
