<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\EditorialKnowledgeSelector;
use PHPUnit\Framework\TestCase;

final class EditorialKnowledgeSelectorTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_selection_uses_only_eligible_candidates_and_preserves_canonical_context(): void
    {
        $pack = $this->selector()->select(
            ['status' => 'available', 'eligible_claims' => [
                $this->claim('core', 'Odo 36 có ba phiên bản vách máy.', 2, 'direct'),
                $this->claim('neighbor', 'Vách xoáy là một dạng cấu hình của máy Odo 36.', 1, 'neighborhood', 2, 'movement-1'),
            ], 'items' => [
                $this->claim('blocked', 'Odo 36 có vách hở.', 4, 'direct', 1, self::SUBJECT, 'ineligible', ['EVIDENCE_MISSING']),
            ]],
            '3 phiên bản vách máy của đồng hồ Odo 36',
            ['id' => self::SUBJECT, 'type' => 'model'],
            ['profile' => 'article']
        );

        self::assertSame(['core', 'neighbor'], array_column($pack->selectedClaims, 'claim_id'));
        self::assertSame(2, $pack->selectedClaims[0]['claim_revision']);
        self::assertSame(self::SUBJECT, $pack->selectedClaims[0]['original_subject']['id']);
        self::assertSame('uses_movement', $pack->selectedClaims[1]['graph_path'][0]['predicate']);
        self::assertSame(['CORE', 'EXPLANATION'], array_column($pack->selectedClaims, 'editorial_role'));
        self::assertSame(['blocked'], array_column($pack->excludedCandidates, 'claim_id'));
        self::assertContains('EVIDENCE_MISSING', $pack->excludedCandidates[0]['exclusion_reasons']);
    }

    public function test_information_gain_and_redundancy_produce_deterministic_selection(): void
    {
        $candidates = [
            $this->claim('repeat', 'Odo 36 có ba phiên bản vách máy.', 1, 'direct'),
            $this->claim('explain', 'Vách xoáy giúp nhận biết cấu hình máy Odo 36.', 1, 'neighborhood'),
            $this->claim('duplicate', 'Odo 36 có ba phiên bản vách máy.', 3, 'direct'),
        ];

        $first = $this->selector()->select(['status' => 'available', 'eligible_claims' => $candidates, 'items' => $candidates], 'vách máy Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => 'video', 'selection_limit' => 2], ['raw_input' => 'Đây là 3 phiên bản vách máy của Odo 36.']);
        $second = $this->selector()->select(['status' => 'available', 'eligible_claims' => $candidates, 'items' => $candidates], 'vách máy Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => 'video', 'selection_limit' => 2], ['raw_input' => 'Đây là 3 phiên bản vách máy của Odo 36.']);

        self::assertSame(['repeat', 'explain'], array_column($first->selectedClaims, 'claim_id'));
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('REDUNDANT_INFORMATION', $first->excludedCandidates[0]['exclusion_reasons'][0]);
        self::assertGreaterThan($first->selectedClaims[0]['utility']['information_gain'], $first->selectedClaims[1]['utility']['information_gain']);
    }

    public function test_profiles_share_selection_logic_and_unsupported_profile_fails_closed(): void
    {
        $candidate = $this->claim('core', 'Odo 36 có ba vách máy.', 1, 'direct');
        $retrieval = ['status' => 'available', 'eligible_claims' => [$candidate], 'items' => [$candidate]];

        foreach (['article', 'video', 'image', 'media'] as $profile) {
            $pack = $this->selector()->select($retrieval, 'vách máy Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => $profile]);
            self::assertSame(['core'], array_column($pack->selectedClaims, 'claim_id'), $profile);
        }

        $pack = $this->selector()->select($retrieval, 'vách máy Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => 'podcast']);
        self::assertSame('review', $pack->status);
        self::assertContains('PROFILE_UNSUPPORTED', $pack->blockers);
        self::assertSame([], $pack->selectedClaims);
    }

    public function test_visual_support_is_preserved_as_unresolved_and_representative_media_is_not_feature_support(): void
    {
        $candidate = $this->claim('visual', 'Vách cam giúp nhận diện cấu hình Odo 36.', 1, 'direct');
        $candidate['visual_support_required'] = true;
        $candidate['representative_media'] = ['media_id' => 'representative-1'];

        $pack = $this->selector()->select(['status' => 'available', 'eligible_claims' => [$candidate], 'items' => [$candidate]], 'vách cam Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => 'image']);

        self::assertSame('UNRESOLVED', $pack->visualSupport[0]['status']);
        self::assertSame('representative-1', $pack->visualSupport[0]['representative_media']['media_id']);
        self::assertSame('FEATURE_SUPPORT_REQUIRED', $pack->visualSupport[0]['reason']);
    }

    public function test_unavailable_retrieval_does_not_become_an_empty_successful_pack(): void
    {
        $pack = $this->selector()->select(['status' => 'unavailable', 'eligible_claims' => [], 'items' => [], 'blockers' => ['GRAPH_RESEARCH_UNAVAILABLE']], 'vách máy Odo 36', ['id' => self::SUBJECT, 'type' => 'model'], ['profile' => 'article']);

        self::assertSame('unavailable', $pack->status);
        self::assertSame(['GRAPH_RESEARCH_UNAVAILABLE'], $pack->blockers);
        self::assertSame([], $pack->selectedClaims);
    }

    private function selector(): EditorialKnowledgeSelector
    {
        return new EditorialKnowledgeSelector();
    }

    private function claim(string $id, string $text, int $revision, string $origin, int $pathLength = 0, string $subject = self::SUBJECT, string $eligibility = 'eligible', array $reasons = []): array
    {
        $path = $pathLength > 0 ? [['source' => 'model:' . self::SUBJECT, 'predicate' => 'uses_movement', 'target' => 'movement:' . $subject]] : [];
        return [
            'claim_id' => $id, 'claim_revision' => $revision, 'text' => $text,
            'original_subject' => ['id' => $subject, 'type' => $origin === 'direct' ? 'model' : 'movement'],
            'resolved_primary_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'retrieval_origin' => $origin, 'graph_path' => $path,
            'eligibility' => $eligibility, 'scope_compatibility' => 'compatible',
            'evidence' => ['status' => 'eligible'], 'provenance_references' => ['source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']],
            'source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1'], 'exclusion_reasons' => $reasons,
        ];
    }
}
