<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryDetailPresentationComposer, DictionaryDetailQuery, DictionaryPublicQuery};
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry};
use PHPUnit\Framework\TestCase;

final class DictionaryDelegatedProjectionTest extends TestCase
{
    public function test_exact_reverse_mapping_returns_the_shared_delegated_packet(): void
    {
        $sense = new DictionaryConcept('sense-1', 'Côn hoa thị', 'Linh kiện được tra cứu.', DictionaryConcept::APPROVED, 'component', 'owner-1');
        $entry = new LexicalEntry('entry-1', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con-hoa-thi'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findEntriesBySemanticReference(string $type, string $id, int $limit = 2): array { return [$this->entry]; }
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'component', 'id' => 'owner-1', 'source' => 'MAPPING']; }
        };
        $detail = new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, static fn (): ?string => '/linh-kien/con-hoa-thi/');
        $query = new DictionaryPublicQuery(
            $this->concepts(), null, static fn (): ?string => '/linh-kien/con-hoa-thi/', $entries,
            static fn (): bool => true, null, $detail, new DictionaryDetailPresentationComposer(),
        );

        $result = $query->detailForOwner('component', 'owner-1');

        self::assertSame('READY', $result['status']);
        self::assertSame('DELEGATED', $result['presentation']['route']['mode']);
        self::assertSame('/linh-kien/con-hoa-thi/', $result['presentation']['route']['canonical_url']);
        self::assertSame('Côn hoa thị', $result['presentation']['identity']['title']);
    }

    public function test_competing_reverse_entries_fail_closed_without_merging(): void
    {
        $entry = new LexicalEntry('entry-1', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con-hoa-thi']);
        $entries = new class($entry) {
            public function __construct(private LexicalEntry $entry) {}
            public function findEntriesBySemanticReference(string $type, string $id, int $limit = 2): array { return [$this->entry, new LexicalEntry('entry-2', 'Côn hoa thị khác', 'côn hoa thị khác', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con-hoa-thi-khac'])]; }
        };
        $query = new DictionaryPublicQuery($this->concepts(), null, static fn (): ?string => '/linh-kien/con-hoa-thi/', $entries);

        self::assertSame('AMBIGUOUS_LEXICAL_OVERLAY', $query->detailForOwner('component', 'owner-1')['status']);
    }

    private function concepts(): DictionaryConceptRepository
    {
        return new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
    }
}
