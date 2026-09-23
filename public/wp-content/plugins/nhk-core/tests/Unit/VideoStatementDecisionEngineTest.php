<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoEditorialAction;
use NHK\Core\Application\Video\VideoStatementClassification;
use NHK\Core\Application\Video\VideoStatementDecisionEngine;
use PHPUnit\Framework\TestCase;

final class VideoStatementDecisionEngineTest extends TestCase
{
    public function test_classifies_supported_and_scoped_statements_without_promoting_hints(): void
    {
        $result = (new VideoStatementDecisionEngine())->evaluate([
            ['id' => 'canonical', 'text' => 'Model A', 'canonical_match' => true, 'canonical' => ['id' => 'entity-a']],
            ['id' => 'observation', 'text' => 'mặt số xanh', 'observation' => true, 'scope' => 'depicted specimen', 'attribution' => 'trong video'],
            ['id' => 'source', 'text' => 'nguồn giới thiệu', 'source_supported' => true, 'source' => ['id' => 'source-1']],
            ['id' => 'inference', 'text' => 'có thể thuộc dòng này', 'inferable' => true, 'within_scope' => true],
            ['id' => 'hint', 'text' => 'gợi ý của người dùng', 'user_hint' => true],
        ], [], []);

        self::assertSame([
            'CANONICAL_SUPPORTED', 'USER_OBSERVATION', 'SOURCE_SUPPORTED',
            'INFERABLE_WITHIN_SCOPE', 'UNCERTAIN',
        ], array_column($result->items(), 'classification'));
        self::assertSame('ATTRIBUTE_AND_SCOPE', $result->items()[1]['action']);
        self::assertSame('QUALIFY_INFERENCE', $result->items()[3]['action']);
        self::assertSame('NARROW_SCOPE', $result->items()[4]['action']);
    }

    public function test_removes_unsupported_expansion_and_repairs_secondary_visual_gap(): void
    {
        $result = (new VideoStatementDecisionEngine())->evaluate([
            ['id' => 'unsupported', 'text' => 'luôn chính xác tuyệt đối'],
            ['id' => 'secondary-visual', 'text' => 'chi tiết phụ', 'visual_required' => true, 'visual_supported' => false, 'core' => false],
        ], [], [], []);

        self::assertSame('UNSUPPORTED_EXPANSION', $result->items()[0]['classification']);
        self::assertSame('REMOVE_UNSUPPORTED', $result->items()[0]['action']);
        self::assertContains('UNSUPPORTED_EXPANSION', array_column($result->findings(), 'code'));
        self::assertContains('VISUAL_SUPPORT_SECONDARY', array_column($result->findings(), 'code'));
        $visual = $result->findings()[1];
        self::assertSame('REPAIRABLE', $visual['severity']);
        self::assertSame(VideoEditorialAction::REMOVE_UNSUPPORTED, $visual['repair']);
    }

    public function test_prefers_canonical_for_non_core_conflict_but_reviews_core_conflict(): void
    {
        $result = (new VideoStatementDecisionEngine())->evaluate([
            ['id' => 'minor-conflict', 'text' => 'màu đỏ', 'conflicting' => true, 'canonical' => ['color' => 'xanh'], 'core' => false],
            ['id' => 'core-conflict', 'text' => 'Model B', 'conflicting' => true, 'canonical' => ['id' => 'entity-a'], 'core' => true],
        ], [], []);

        self::assertSame('CONFLICTING', $result->items()[0]['classification']);
        self::assertSame('PREFER_CANONICAL', $result->items()[0]['action']);
        self::assertSame('INFO', $result->findings()[0]['severity']);
        self::assertSame('REVIEW_REQUIRED', $result->findings()[1]['severity']);
    }
}
