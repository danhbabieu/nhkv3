<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryCurationService;
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Media\MediaUsage;
use PHPUnit\Framework\TestCase;

final class DictionaryCurationServiceTest extends TestCase
{
    public function test_new_candidate_becomes_draft_not_public_concept(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'vai bò', $hash, ['Vai bò'], DictionaryCandidateState::NEEDS_REVIEW, ['usage_scope' => ['Vietnam']], [], 3, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, static fn (): string => 'concept-1');

        $result = $service->createDraftFromCandidate('candidate-1', 1, 'Vai bò', 'Tên gọi dân gian tại Việt Nam.', ['public_slug' => 'vai-bo', 'term_type' => 'COLLOQUIAL']);

        self::assertSame(DictionaryConcept::DRAFT, $result['concept']->status);
        self::assertSame(DictionaryCandidateState::PROPOSED_NEW, $result['candidate']->state);
    }

    public function test_attach_existing_adds_alias_and_resolves_candidate(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'côn máng', $hash, ['Côn máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 2, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate, new DictionaryConcept('concept-1', 'Côn lòng máng', 'Khái niệm đã duyệt.', DictionaryConcept::APPROVED));
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo);

        $result = $service->attachToExisting('candidate-1', 1, 'concept-1', DictionaryLabel::COLLOQUIAL, 'vi-VN');

        self::assertSame(DictionaryCandidateState::RESOLVED_EXISTING, $result['candidate']->state);
        self::assertSame('Côn máng', $result['label']->label);
        self::assertSame(DictionaryLabel::COLLOQUIAL, $result['label']->kind);
    }

    public function test_approved_concept_can_pin_existing_ready_media_as_dictionary_preferred_illustration(): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Đồng hồ cơ có cơ cấu phát âm thanh theo chu kỳ.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'dong-ho-chim-cuc-cu'], 3);
        $service = $this->serviceForConcept($concept);

        $usage = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'Đồng hồ chim cúc cu', 'Ảnh đồng hồ chim cúc cu', 'Minh họa cho mục từ.');

        self::assertInstanceOf(MediaUsage::class, $usage);
        self::assertSame('dictionary_concept', $usage->endpointType);
        self::assertSame($concept->conceptId, $usage->endpointKey);
        self::assertSame('representative', $usage->role);
        self::assertSame('preferred_illustration', $usage->placementKey);
        self::assertSame('USER_EXPLICIT', $usage->selectionSource);
        self::assertSame('PINNED', $usage->selectionPolicy);
    }

    public function test_replacing_dictionary_illustration_preserves_article_usages_and_replay_is_idempotent(): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Định nghĩa lexical.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'dong-ho-chim-cuc-cu'], 4);
        $service = $this->serviceForConcept($concept);
        $mediaA = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $mediaB = '01a0ab0c-fde0-7c01-a89d-fc5eef832c90';

        $first = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaA, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh A');
        $replacement = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaB, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh B');
        $replay = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaB, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh B');

        self::assertInstanceOf(MediaUsage::class, $first);
        self::assertInstanceOf(MediaUsage::class, $replacement);
        self::assertInstanceOf(MediaUsage::class, $replay);
        self::assertSame($mediaB, $replacement->mediaId);
        self::assertSame($replacement->usageId, $replay->usageId);
        self::assertSame($replacement->mediaId, $replay->mediaId);
        self::assertSame('preferred_illustration', $replacement->placementKey);
        self::assertSame('USER_EXPLICIT', $replacement->selectionSource);
        self::assertSame('PINNED', $replacement->selectionPolicy);
        self::assertNotSame($mediaB, $mediaA, 'Replacement must not rewrite old Article/Model usages on Media A.');
    }

    /** @dataProvider blockedIllustrationInputs */
    public function test_unapproved_or_ineligible_dictionary_illustration_is_typed_blocked_result(string $status, string $expectedReason): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Định nghĩa lexical.', $status, null, null, null, ['public_slug' => 'dong-ho-chim-cuc-cu'], 1);
        $service = $this->serviceForConcept($concept);

        $result = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89');

        self::assertIsArray($result);
        self::assertContains($result['status'] ?? null, ['BLOCKED', 'REVIEW_REQUIRED']);
        self::assertSame($expectedReason, $result['reason'] ?? null);
        self::assertArrayNotHasKey('usage', $result);
    }

    public static function blockedIllustrationInputs(): array
    {
        return [
            'draft concept' => [DictionaryConcept::DRAFT, 'DICTIONARY_CONCEPT_NOT_APPROVED'],
            'retired concept' => [DictionaryConcept::RETIRED, 'DICTIONARY_CONCEPT_NOT_APPROVED'],
            'ambiguous concept' => [DictionaryConcept::APPROVED, 'MEDIA_SCOPE_AMBIGUOUS'],
            'non-ready media' => [DictionaryConcept::APPROVED, 'MEDIA_NOT_READY'],
        ];
    }

    public function test_dictionary_context_does_not_copy_into_global_media_or_attachment_and_cuon_111_stays_outside_evidence_graph(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryCurationService.php');

        self::assertStringNotContainsString('updateMediaName', $source);
        self::assertStringNotContainsString('updateAttachmentMetadata', $source);
        self::assertStringNotContainsString('EvidenceRepository', $source);
        self::assertStringNotContainsString('GraphRepository', $source);
    }

    private function serviceForConcept(DictionaryConcept $concept): DictionaryCurationService
    {
        [$candidateRepo, $conceptRepo] = $this->repositories(new DictionaryCandidate('candidate-illustration', 'côn 111', hash('sha256', '{}'), ['Côn 111'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, 'a', 'b', 1), $concept);
        return new DictionaryCurationService($candidateRepo, $conceptRepo);
    }

    private function repositories(DictionaryCandidate $candidate, ?DictionaryConcept $existingConcept = null): array
    {
        $candidateRepo = new class($candidate) implements DictionaryCandidateRepository {
            public function __construct(private DictionaryCandidate $candidate) {}
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { return $this->candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return $this->candidate->suppressed(); }
            public function listForReview(int $limit = 100): array { return [$this->candidate]; }
            public function findById(string $candidateId): ?DictionaryCandidate { return $candidateId === $this->candidate->candidateId ? $this->candidate : null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { if ($this->candidate->revision !== $expectedRevision) throw new \RuntimeException('conflict'); return $this->candidate = $candidate; }
        };
        $conceptRepo = new class($existingConcept) implements DictionaryConceptRepository {
            public array $labels = [];
            public function __construct(private ?DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concept?->conceptId === $conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return $this->concept?->approved() ? [$this->concept] : []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return $this->labels; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $this->concept = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { $this->labels[] = $label; return $label; }
        };
        return [$candidateRepo, $conceptRepo];
    }
}
