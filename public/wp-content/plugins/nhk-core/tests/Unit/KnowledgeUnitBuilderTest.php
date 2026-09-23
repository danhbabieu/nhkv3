<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\KnowledgeUnitBuilder;
use PHPUnit\Framework\TestCase;

final class KnowledgeUnitBuilderTest extends TestCase
{
    private const SUBJECT = '11111111-1111-4111-8111-111111111111';

    public function test_empty_or_provenance_only_candidates_do_not_create_reader_units(): void
    {
        $result = (new KnowledgeUnitBuilder())->build([
            $this->candidate('provenance', 'The source identifies this video as concerning the subject.', ['claim_type' => 'provenance']),
        ], ['id' => self::SUBJECT, 'type' => 'model'], 'subject overview', ['profile' => 'video']);

        self::assertSame([], $result->units);
        self::assertSame(['provenance'], array_column($result->grounding, 'claim_id'));
        self::assertSame('THIN', $result->diagnostics['coverage_status']);
    }

    public function test_near_duplicate_claims_form_one_unit_and_retain_support_trace(): void
    {
        $result = (new KnowledgeUnitBuilder())->build([
            $this->candidate('a', 'Odo 36 có ba phiên bản vách máy.'),
            $this->candidate('b', 'Odo 36 có ba phiên bản vách máy!', ['claim_revision' => 3]),
        ], ['id' => self::SUBJECT, 'type' => 'model'], 'vách máy Odo 36', ['profile' => 'article']);

        self::assertCount(1, $result->units);
        self::assertSame('a', $result->units[0]->claim()['claim_id']);
        self::assertSame(['a', 'b'], array_column($result->units[0]->toArray()['supporting_claims'], 'claim_id'));
        self::assertNotSame('', $result->units[0]->toArray()['semantic_fingerprint']);
        self::assertNotEmpty($result->units[0]->toArray()['coverage_aspects']);
    }

    public function test_five_hundred_duplicate_claims_are_bounded_to_one_unit_deterministically(): void
    {
        $candidates = [];
        for ($i = 0; $i < 500; $i++) {
            $candidates[] = $this->candidate('claim-' . $i, 'Odo 36 có ba phiên bản vách máy.');
        }

        $first = (new KnowledgeUnitBuilder())->build($candidates, ['id' => self::SUBJECT, 'type' => 'model'], 'vách máy', ['profile' => 'video']);
        $second = (new KnowledgeUnitBuilder())->build($candidates, ['id' => self::SUBJECT, 'type' => 'model'], 'vách máy', ['profile' => 'video']);

        self::assertCount(1, $first->units);
        self::assertCount(500, $first->units[0]->toArray()['supporting_claims']);
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame(500, $first->diagnostics['candidate_count']);
        self::assertSame(1, $first->diagnostics['unit_count']);
    }

    /** @param array<string,mixed> $extra */
    private function candidate(string $id, string $text, array $extra = []): array
    {
        return array_replace([
            'claim_id' => $id,
            'claim_revision' => 1,
            'text' => $text,
            'eligibility' => 'eligible',
            'publicly_composable' => true,
            'semantic_role' => 'READER_FACT',
            'original_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'resolved_primary_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'scope' => 'model',
            'facet' => 'configuration',
            'evidence' => ['status' => 'eligible'],
            'provenance_references' => ['source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']],
        ], $extra);
    }
}
