<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, EditorialKnowledgeSelector, ReaderJourneyPlanner, SharedEditorialComposer, TopicFulfillment};
use PHPUnit\Framework\TestCase;

final class TopicFulfillmentTest extends TestCase
{
    public function test_enumeration_requires_supported_members_not_just_the_count(): void
    {
        $result = (new TopicFulfillment())->evaluate(
            '3 types of instrument case',
            [['text' => 'The instrument has three types of case.', 'eligibility' => 'eligible']],
            'The instrument has three types of case.'
        );

        self::assertSame('enumeration', $result['promise']['kind']);
        self::assertFalse($result['fulfilled']);
        self::assertSame('ENUMERATION_PROMISE_UNFULFILLED', $result['diagnostic']);
    }

    public function test_enumeration_is_fulfilled_by_explicit_supported_members(): void
    {
        $result = (new TopicFulfillment())->evaluate(
            '3 types of instrument case',
            [['text' => 'The instrument has three types of case: open, closed, and carved.', 'eligibility' => 'eligible']],
            'The instrument has three types of case: open, closed, and carved.'
        );

        self::assertTrue($result['fulfilled']);
    }

    public function test_topic_completion_claim_is_not_redundant_when_it_adds_a_missing_member(): void
    {
        $claims = [
            $this->claim('overview', 'The instrument has three types of case.'),
            $this->claim('members', 'The three types are open, closed, and carved.', 'direct'),
        ];
        $pack = (new EditorialKnowledgeSelector())->select(
            ['status' => 'available', 'eligible_claims' => $claims, 'items' => $claims],
            '3 types of instrument case',
            ['id' => 'subject-1', 'type' => 'model'],
            ['profile' => 'article', 'selection_limit' => 2]
        );

        self::assertSame(['overview', 'members'], array_column($pack->selectedClaims, 'claim_id'));
        self::assertNotContains('REDUNDANT_INFORMATION', $pack->excludedCandidates[0]['exclusion_reasons'] ?? []);
    }

    public function test_video_profile_label_is_not_public_lead_metadata(): void
    {
        $pack = new EditorialContextPack('available', [], 'A useful topic', ['profile' => 'video'], 'available', [], [], ['raw_input' => 'A useful topic.']);
        $draft = (new SharedEditorialComposer())->compose((new ReaderJourneyPlanner())->plan($pack));

        self::assertStringNotContainsString('Video:', $draft->body);
        self::assertStringContainsString('A useful topic.', $draft->body);
    }

    private function claim(string $id, string $text, string $origin = 'neighborhood'): array
    {
        return ['claim_id' => $id, 'claim_revision' => 1, 'text' => $text, 'eligibility' => 'eligible', 'retrieval_origin' => $origin, 'original_subject' => ['id' => 'subject-1', 'type' => 'model']];
    }
}
