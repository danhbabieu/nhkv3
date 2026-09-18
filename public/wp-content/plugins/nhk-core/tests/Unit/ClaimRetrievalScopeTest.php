<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\ClaimRetrievalEngine;
use PHPUnit\Framework\TestCase;

final class ClaimRetrievalScopeTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';
    private const MOVEMENT = '88746e58-1f8c-461c-a094-605e3c564706';
    private const WESTMINSTER = 'westminster-claim-subject';

    public function test_reachable_lexically_overlapping_unrelated_claim_is_rejected(): void
    {
        $engine = $this->engine([
            ['id' => 'westminster', 'subject_id' => self::WESTMINSTER, 'subject_type' => 'classification', 'text' => 'Chuông và búa Westminster điểm chuông.', 'scope' => 'classification', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'about', 'target' => 'classification:' . self::WESTMINSTER]]],
        ]);

        $result = $engine->retrieve($this->context('Chuông đêm Vedette 37'));

        self::assertSame([], $result['selected_claims']);
        self::assertSame('exclude', $result['items'][0]['decision']);
        self::assertContains('SEMANTIC_SCOPE_NOT_APPLICABLE', $result['items'][0]['warnings']);
    }

    public function test_direct_and_registered_related_claims_are_selected_with_identity_and_path(): void
    {
        $engine = $this->engine([
            ['id' => 'direct', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Vedette 37 có cơ chế ngắt chuông đêm.', 'scope' => 'model', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
            ['id' => 'movement', 'subject_id' => self::MOVEMENT, 'subject_type' => 'movement', 'text' => 'Máy Vedette 37 liên quan đến chuông.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'variant_of', 'target' => 'variant:vedette-37'], ['source' => 'variant:vedette-37', 'predicate' => 'uses_movement', 'target' => 'movement:' . self::MOVEMENT]]],
        ]);

        $result = $engine->retrieve($this->context('chuông'));

        self::assertSame(['direct', 'movement'], array_column($result['selected_claims'], 'claim_id'));
        self::assertSame(self::SUBJECT, $result['selected_claims'][0]['subject_id']);
        self::assertSame('uses_movement', $result['selected_claims'][1]['relation_path'][1]['predicate']);
    }

    public function test_reachable_claim_without_original_subject_path_is_rejected_even_when_words_overlap(): void
    {
        $engine = $this->engine([
            ['id' => 'lost-subject', 'subject_id' => self::WESTMINSTER, 'subject_type' => 'classification', 'text' => 'Cơ chế chuông và búa.', 'scope' => 'classification', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => []],
        ]);

        self::assertSame([], $engine->retrieve($this->context('chuông búa'))['selected_claims']);
    }

    private function engine(array $rows): ClaimRetrievalEngine
    {
        return new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static function (array $subject, array $neighborhood) use ($rows): array { return $rows; },
        );
    }

    private function context(string $input): array
    {
        return ['raw_input' => $input, 'subject_resolution' => ['subjects' => [['id' => self::SUBJECT, 'type' => 'model']]]];
    }
}
