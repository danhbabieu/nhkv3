<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

require_once __DIR__ . '/KnowledgeWriterPreviewServiceTest.php';

use NHK\Core\Application\Semantic\KnowledgeWriterPreviewService;
use PHPUnit\Framework\TestCase;

/** Acceptance contract for the transient, reader-facing Knowledge preview. */
final class KnowledgeWriterPreviewContractTest extends TestCase
{
    use KnowledgeWriterPreviewFixture;

    public function test_preview_contract_is_read_only_bounded_and_has_no_mutation_output(): void
    {
        $result = $this->service()->preview($this->request());

        self::assertTrue($result['read_only']);
        self::assertSame('available', $result['status']);
        self::assertNotSame('', $result['answer']);
        self::assertArrayNotHasKey('proposals', $result);
        self::assertArrayNotHasKey('writes', $result);
        self::assertArrayNotHasKey('mutations', $result);
        self::assertLessThanOrEqual(50, count($result['used_knowledge']));
        self::assertLessThanOrEqual(50, count($result['excluded_knowledge']));
        self::assertLessThanOrEqual(30, count($result['diagnostics']));
        self::assertLessThanOrEqual(30, count($result['gaps']));
    }

    public function test_purpose_registry_is_exhaustive_and_keeps_its_existing_profile_and_depth(): void
    {
        $expected = [
            'concise_answer' => ['video', 'concise'],
            'collector_explanation' => ['article', 'deep'],
            'article_section' => ['article', 'deep'],
            'video_description' => ['video', 'concise'],
            'media_caption' => ['media', 'concise'],
            'media_alt' => ['image', 'concise'],
            'entity_summary' => ['article', 'deep'],
            'technical_explanation' => ['article', 'deep'],
        ];

        foreach ($expected as $purpose => [$profile, $depth]) {
            $result = $this->service()->preview($this->request(['purpose' => $purpose]));
            self::assertSame($profile, $result['depth']['profile'], $purpose);
            self::assertSame($depth, $result['depth']['effective'], $purpose);
            self::assertSame($purpose, $result['depth']['purpose'], $purpose);
        }

        $method = new \ReflectionMethod(KnowledgeWriterPreviewService::class, 'preview');
        self::assertSame('array', (string) $method->getReturnType());
        self::assertSame('array', (string) $method->getParameters()[0]->getType());
    }

    public function test_sparse_and_unsafe_factual_inputs_never_become_reader_claims(): void
    {
        $this->rows = [];
        $sparse = $this->service()->preview($this->request());
        self::assertSame('sparse', $sparse['status']);
        self::assertSame('', $sparse['answer']);
        self::assertSame([], $sparse['used_knowledge']);

        $this->rows = [[
            'id' => 'claim-unsafe', 'revision' => 1, 'subject_id' => $this->subjectId,
            'subject_type' => 'variant', 'facet' => 'recognition',
            'text' => 'Chủ thể variant có mặt số với vòng chỉ giờ. evidence_ids=private-evidence-42',
            'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
        ]];
        $unsafe = $this->service()->preview($this->request());
        self::assertSame('blocked', $unsafe['status']);
        self::assertSame('', $unsafe['answer']);
        self::assertSame([], $unsafe['used_knowledge']);
        self::assertContains('PUBLIC_COPY_UNSAFE', $unsafe['diagnostics']);
    }
}
