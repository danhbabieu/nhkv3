<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Semantic\EditorialSemanticRolePolicy;
use PHPUnit\Framework\TestCase;

final class EditorialSemanticRolePolicyTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_direct_supported_provenance_is_grounding_not_public_core(): void
    {
        $decision = $this->policy()->classify($this->candidate([
            'text' => 'The source identifies the video as concerning the resolved canonical subject.',
            'provenance' => 'EXTERNAL_RESEARCH',
            'claim_type' => 'provenance',
        ]), $this->subject(), ['profile' => 'video', 'topic' => 'Odo 36/8']);

        self::assertSame('PROVENANCE_ONLY', $decision['semantic_role']);
        self::assertSame('APPLICABLE', $decision['state']);
        self::assertFalse($decision['publicly_composable']);
        self::assertSame('applicable', $decision['applicability']);
    }

    public function test_direct_supported_domain_fact_is_reader_fact_and_publicly_composable(): void
    {
        $decision = $this->policy()->classify($this->candidate([
            'text' => 'Odo 36/8 dùng máy ba vách với cấu hình chuông Westminster.',
            'scope' => 'variant',
            'claim_type' => 'configuration',
        ]), $this->subject(), ['profile' => 'video', 'topic' => 'Odo 36/8']);

        self::assertSame('READER_FACT', $decision['semantic_role']);
        self::assertSame('APPLICABLE', $decision['state']);
        self::assertTrue($decision['publicly_composable']);
        self::assertSame($this->candidate()['claim_id'], $decision['claim_id']);
    }

    public function test_specimen_claim_cannot_broaden_to_variant_reader_fact(): void
    {
        $candidate = $this->candidate([
            'subject_id' => 'specimen-1',
            'subject_type' => 'specimen',
            'scope' => 'specimen-only',
            'original_subject' => ['id' => 'specimen-1', 'type' => 'specimen'],
            'text' => 'Chiếc đồng hồ trong video có vết xước ở mặt số.',
        ]);
        $decision = $this->policy()->classify($candidate, $this->subject(), ['profile' => 'video', 'topic' => 'Odo 36/8']);

        self::assertSame('SPECIMEN_CONTEXT', $decision['semantic_role']);
        self::assertSame('INAPPLICABLE', $decision['state']);
        self::assertFalse($decision['publicly_composable']);
        self::assertSame('inapplicable', $decision['applicability']);
    }

    public function test_registered_neighbor_with_incompatible_scope_is_not_applicable(): void
    {
        $candidate = $this->candidate([
            'subject_id' => 'other-variant',
            'subject_type' => 'variant',
            'scope' => 'variant',
            'original_subject' => ['id' => 'other-variant', 'type' => 'variant'],
            'retrieval_origin' => 'neighborhood',
            'graph_path' => [['source' => 'model:' . self::SUBJECT, 'predicate' => 'variant_of', 'target' => 'variant:other-variant']],
            'text' => 'Một biến thể khác có mặt số màu xanh.',
        ]);
        $decision = $this->policy()->classify($candidate, $this->subject(), ['profile' => 'article', 'topic' => 'Odo 36/8']);

        self::assertSame('INAPPLICABLE', $decision['state']);
        self::assertFalse($decision['publicly_composable']);
        self::assertStringContainsString('scope', strtolower($decision['reason']));
    }

    public function test_direct_technical_fact_is_publicly_composable_without_language_specific_matching(): void
    {
        $decision = $this->policy()->classify($this->candidate([
            'text' => 'Cấu hình máy gồm ba vách và bộ điều tốc riêng.',
            'claim_type' => 'technical_explanation',
            'scope' => 'model',
        ]), $this->subject(), ['profile' => 'article', 'topic' => 'cấu hình máy']);

        self::assertSame('READER_FACT', $decision['semantic_role']);
        self::assertTrue($decision['publicly_composable']);
        self::assertArrayHasKey('editorial_utility', $decision);
    }

    private function policy(): EditorialSemanticRolePolicy
    {
        return new EditorialSemanticRolePolicy();
    }

    private function subject(): array
    {
        return ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8'];
    }

    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'claim_id' => 'claim-1',
            'claim_revision' => 1,
            'text' => 'Thông tin canonical hữu ích cho người đọc.',
            'subject_id' => self::SUBJECT,
            'subject_type' => 'variant',
            'scope' => 'variant',
            'claim_type' => 'fact',
            'provenance' => 'CATALOG_SUPPORTED',
            'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            'retrieval_origin' => 'direct',
            'original_subject' => ['id' => self::SUBJECT, 'type' => 'variant'],
            'resolved_primary_subject' => $this->subject(),
            'graph_path' => [],
            'eligibility' => 'eligible',
            'evidence' => ['status' => 'eligible'],
            'utility' => ['total' => 10.0, 'information_gain' => 0.7],
        ], $overrides);
    }
}
