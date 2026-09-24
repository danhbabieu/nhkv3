<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, SemanticInputEnvelope, SemanticNeed};
use PHPUnit\Framework\TestCase;

final class EditorialClaimRetrievalServiceTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';
    private const MOVEMENT = '88746e58-1f8c-461c-a094-605e3c564706';

    public function test_direct_and_neighbor_claims_keep_identity_path_and_eligibility_diagnostics(): void
    {
        $service = $this->service([
            ['id' => 'direct', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có ba phiên bản vách máy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']],
            ['id' => 'movement', 'subject_id' => self::MOVEMENT, 'subject_type' => 'movement', 'text' => 'Máy Odo 36 liên quan đến vách máy.', 'scope' => 'movement', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relation_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'variant_of', 'target' => 'variant:vedette-37'], ['source' => 'variant:vedette-37', 'predicate' => 'uses_movement', 'target' => 'movement:' . self::MOVEMENT]]],
        ]);

        $result = $service->retrieve(
            ['id' => self::SUBJECT, 'type' => 'model'],
            '3 phiên bản vách máy của đồng hồ Odo 36',
            ['profile_hint' => 'technical']
        );

        self::assertSame('available', $result['status']);
        self::assertSame(['direct', 'movement'], array_column($result['eligible_claims'], 'claim_id'));
        self::assertSame('direct', $result['eligible_claims'][0]['retrieval_origin']);
        self::assertSame('neighborhood', $result['eligible_claims'][1]['retrieval_origin']);
        self::assertSame(self::MOVEMENT, $result['eligible_claims'][1]['original_subject']['id']);
        self::assertSame(1, $result['eligible_claims'][1]['claim_revision']);
        self::assertSame('uses_movement', $result['eligible_claims'][1]['graph_path'][1]['predicate']);
        self::assertSame(['source-1'], $result['eligible_claims'][0]['source_ids']);
        self::assertSame('eligible', $result['eligible_claims'][0]['eligibility']);
    }

    public function test_reachable_topic_drift_and_missing_evidence_are_not_eligible(): void
    {
        $service = $this->service([
            ['id' => 'drift', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Mặt số có họa tiết trang trí.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'],
            ['id' => 'missing', 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có vách xoáy.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'MISSING'],
        ]);

        $result = $service->retrieve(['id' => self::SUBJECT, 'type' => 'model'], '3 phiên bản vách máy Odo 36');

        self::assertSame([], $result['eligible_claims']);
        self::assertSame('ineligible', $result['items'][0]['eligibility']);
        self::assertContains('TOPIC_IRRELEVANT', $result['items'][0]['exclusion_reasons']);
        self::assertSame('missing', $result['items'][1]['evidence']['status']);
        self::assertContains('EVIDENCE_MISSING', $result['items'][1]['exclusion_reasons']);
    }

    public function test_result_limit_is_hard_bounded(): void
    {
        $rows = [];
        for ($index = 0; $index < 25; $index++) {
            $rows[] = ['id' => 'claim-' . $index, 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 vách máy ' . $index, 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE'];
        }

        $result = $this->service($rows)->retrieve(['id' => self::SUBJECT, 'type' => 'model'], 'vách máy', [], ['result_limit' => 7]);

        self::assertCount(7, $result['items']);
        self::assertLessThanOrEqual(7, $result['diagnostics']['result_limit']);
    }

    public function test_multi_need_service_exposes_need_trace_and_canonical_revision(): void
    {
        $service = $this->service([
            ['id' => 'facet-claim', 'revision' => 4, 'subject_id' => self::SUBJECT, 'subject_type' => 'model', 'text' => 'Odo 36 có vách máy.', 'scope' => 'model', 'facet' => 'configuration', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'source_ids' => ['source-1'], 'evidence_ids' => ['evidence-1']],
        ]);
        $envelope = SemanticInputEnvelope::fromArray(['raw_text' => 'vách máy', 'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'model']]]);
        $need = SemanticNeed::fromArray([
            'canonical_subject' => ['id' => self::SUBJECT, 'type' => 'model'],
            'facet_key' => 'configuration', 'concept_key' => 'machine_wall', 'scope' => 'model',
            'intent' => 'vách máy', 'origin' => 'USER_EXPLICIT', 'confidence' => 0.9,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ]);

        $result = $service->retrieveForNeeds($envelope, [$need], ['result_limit' => 5]);

        self::assertSame('available', $result['status']);
        self::assertSame($need->needId(), $result['items'][0]['need_id']);
        self::assertSame(4, $result['items'][0]['claim_revision']);
        self::assertSame(['source-1'], $result['items'][0]['source_ids']);
        self::assertArrayHasKey('need_diagnostics', $result);
    }

    private function service(array $rows): EditorialClaimRetrievalService
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => $rows,
        );

        return new EditorialClaimRetrievalService($engine);
    }
}
