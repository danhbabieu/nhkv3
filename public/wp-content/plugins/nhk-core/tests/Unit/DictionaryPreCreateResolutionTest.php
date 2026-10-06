<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;
use PHPUnit\Framework\TestCase;

final class DictionaryPreCreateResolutionTest extends TestCase
{
    public function test_fingerprint_is_deterministic_and_serialization_retains_resolution_evidence(): void
    {
        $left = DictionaryPreCreateResolution::fromDecision(
            DictionaryPreCreateResolution::REVIEW_REQUIRED,
            'côn hoa thị',
            ['domain' => 'đồng hồ', 'locale' => 'vi-VN'],
            [
                ['entry_id' => 'entry-1', 'sense_id' => 'sense-1', 'revision' => 2],
                ['entry_id' => 'entry-2', 'sense_id' => 'sense-2', 'revision' => 4],
            ],
            ['entry-1' => 2, 'entry-2' => 4],
            ['reason' => 'MULTIPLE_CONTEXTUAL_SENSES'],
        );
        $right = DictionaryPreCreateResolution::fromDecision(
            DictionaryPreCreateResolution::REVIEW_REQUIRED,
            'côn hoa thị',
            ['locale' => 'vi-VN', 'domain' => 'đồng hồ'],
            [
                ['sense_id' => 'sense-1', 'revision' => 2, 'entry_id' => 'entry-1'],
                ['revision' => 4, 'entry_id' => 'entry-2', 'sense_id' => 'sense-2'],
            ],
            ['entry-2' => 4, 'entry-1' => 2],
            ['reason' => 'MULTIPLE_CONTEXTUAL_SENSES'],
        );

        self::assertSame($left->fingerprint(), $right->fingerprint());
        self::assertFalse($left->canCreate());
        self::assertSame('REVIEW_REQUIRED', $left->toArray()['action']);
        self::assertSame('côn hoa thị', $left->toArray()['normalized_form']);
        self::assertSame(['entry-1' => 2, 'entry-2' => 4], $left->toArray()['dependency_revisions']);
        self::assertSame('MULTIPLE_CONTEXTUAL_SENSES', $left->toArray()['diagnostics']['reason']);
    }

    public function test_create_new_is_the_only_create_eligible_action(): void
    {
        $resolution = DictionaryPreCreateResolution::fromDecision(
            DictionaryPreCreateResolution::CREATE_NEW,
            'kính rào',
            [],
            [],
            [],
            ['reason' => 'NO_APPLICABLE_CANDIDATE'],
        );

        self::assertTrue($resolution->canCreate());
        self::assertSame(DictionaryPreCreateResolution::CREATE_NEW, $resolution->action);
    }

    public function test_invalid_action_and_empty_normalized_form_fail_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DictionaryPreCreateResolution::fromDecision('CREATE_WHATEVER', 'côn', [], [], [], []);
    }
}
