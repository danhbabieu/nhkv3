<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntryMaterializationPlanner;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry};
use PHPUnit\Framework\TestCase;

final class DictionaryEntryMaterializationPlannerTest extends TestCase
{
    public function test_unmapped_concept_gets_safe_one_to_one_plan(): void
    {
        $concept = new DictionaryConcept(
            '11111111-1111-7111-8111-111111111111',
            'Côn',
            'Một nghĩa đã được duyệt',
            DictionaryConcept::APPROVED,
            'model',
            'model-1',
            '/stale-route/',
            ['domain' => 'đồng hồ'],
            7,
        );
        $repo = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->concept]; }
            public function listByStatus(string $status, int $limit = 500): array { return $status === $this->concept->status ? [$this->concept] : []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return [new DictionaryLabel($conceptId, 'Côn', 'côn', DictionaryLabel::PREFERRED, 'vi-VN', [], true)]; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { throw new \LogicException('planner must not create concepts'); }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { throw new \LogicException('planner must not update concepts'); }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { throw new \LogicException('planner must not write labels'); }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { throw new \LogicException('planner must not write labels'); }
        };
        $planner = new DictionaryEntryMaterializationPlanner(
            $repo,
            static fn (string $conceptId): ?LexicalEntry => null,
            static fn (string $conceptId): array => [new DictionaryLabel($conceptId, 'Côn', 'côn', DictionaryLabel::PREFERRED, 'vi-VN')],
        );

        $result = $planner->plan(['concept_id' => $concept->conceptId]);

        self::assertSame('READY', $result['status']);
        self::assertSame('UNMAPPED_CONCEPT', $result['items'][0]['classification']);
        self::assertSame('CREATE_ENTRY_AND_MAP_EXISTING_SENSE', $result['items'][0]['proposed_operation']);
        self::assertSame($concept->revision, $result['items'][0]['concept_revision']);
        self::assertSame('côn', $result['items'][0]['form']['normalized_form']);
        self::assertNotSame('', $result['fingerprint']);
    }

    public function test_empty_legacy_destination_fields_are_treated_as_unmapped_not_invalid(): void
    {
        $concept = new DictionaryConcept(
            '33333333-3333-7333-8333-333333333333',
            'Bộ nhớ cơ khí',
            'Một nghĩa đã được duyệt',
            DictionaryConcept::APPROVED,
            '',
            '',
            '',
            [],
            1,
        );
        $repo = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->concept]; }
            public function listByStatus(string $status, int $limit = 500): array { return $status === $this->concept->status ? [$this->concept] : []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { throw new \LogicException(); }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { throw new \LogicException(); }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { throw new \LogicException(); }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { throw new \LogicException(); }
        };
        $planner = new DictionaryEntryMaterializationPlanner(
            $repo,
            static fn (string $conceptId): ?LexicalEntry => null,
            static fn (string $conceptId): array => [new DictionaryLabel($conceptId, 'Bộ nhớ cơ khí', 'bộ nhớ cơ khí', DictionaryLabel::PREFERRED)],
            static fn (string $type, string $id, string $url): bool => false,
        );

        $item = $planner->plan(['concept_id' => $concept->conceptId])['items'][0];

        self::assertSame('UNMAPPED_CONCEPT', $item['classification']);
        self::assertSame('READY', $item['eligibility']);
        self::assertSame([], $item['warnings']);
        self::assertSame([], $item['conflicts']);
    }

    public function test_equal_labels_are_review_only_and_never_grouped(): void
    {
        $first = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Nghĩa A', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'A'], 1);
        $second = new DictionaryConcept('22222222-2222-7222-8222-222222222222', 'Côn', 'Nghĩa B', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'B'], 1);
        $repo = new class($first, $second) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->first->conceptId ? $this->first : ($conceptId === $this->second->conceptId ? $this->second : null); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->first, $this->second]; }
            public function listByStatus(string $status, int $limit = 500): array { return $this->listApproved($limit); }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return [new DictionaryLabel($conceptId, 'Côn', 'côn', DictionaryLabel::PREFERRED)]; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { throw new \LogicException(); }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { throw new \LogicException(); }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { throw new \LogicException(); }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { throw new \LogicException(); }
        };
        $planner = new DictionaryEntryMaterializationPlanner($repo, static fn (): ?LexicalEntry => null, static fn (string $id): array => [new DictionaryLabel($id, 'Côn', 'côn', DictionaryLabel::PREFERRED)]);

        $result = $planner->plan();

        self::assertSame('UNMAPPED_CONCEPT', $result['items'][0]['classification']);
        self::assertSame('UNMAPPED_CONCEPT', $result['items'][1]['classification']);
        self::assertNotEmpty($result['grouping_candidates']);
        self::assertSame('REVIEW_REQUIRED', $result['grouping_candidates'][0]['eligibility']);
        self::assertNotContains('MERGE', array_column($result['items'], 'proposed_operation'));
    }

    public function test_existing_mapping_and_retired_concept_are_not_applyable(): void
    {
        $mapped = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'A', 'A', DictionaryConcept::APPROVED, null, null, null, [], 2);
        $retired = new DictionaryConcept('22222222-2222-7222-8222-222222222222', 'B', 'B', DictionaryConcept::RETIRED, null, null, null, [], 3);
        $entry = new LexicalEntry('33333333-3333-7333-8333-333333333333', 'A', 'a', DictionaryConcept::APPROVED, 'vi-VN', [], 4, [$mapped->conceptId]);
        $repo = new class($mapped, $retired) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $mapped, private DictionaryConcept $retired) {}
            public function findById(string $id): ?DictionaryConcept { return $id === $this->mapped->conceptId ? $this->mapped : ($id === $this->retired->conceptId ? $this->retired : null); }
            public function findApprovedByNormalizedLabel(string $n, array $c = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->mapped, $this->retired]; }
            public function listByStatus(string $status, int $limit = 500): array { return [$this->mapped, $this->retired]; }
            public function listLabels(string $id, bool $all = false): array { return [new DictionaryLabel($id, $id === $this->mapped->conceptId ? 'A' : 'B', $id === $this->mapped->conceptId ? 'a' : 'b', DictionaryLabel::PREFERRED)]; }
            public function createConcept(DictionaryConcept $c): DictionaryConcept { throw new \LogicException(); }
            public function updateConcept(DictionaryConcept $c, int $r): DictionaryConcept { throw new \LogicException(); }
            public function addLabel(DictionaryLabel $l): DictionaryLabel { throw new \LogicException(); }
            public function saveLabel(DictionaryLabel $l, string $p, int $r): DictionaryLabel { throw new \LogicException(); }
        };
        $planner = new DictionaryEntryMaterializationPlanner($repo, static fn (string $id): ?LexicalEntry => $id === $mapped->conceptId ? $entry : null, static fn (string $id): array => []);

        $result = $planner->plan();

        self::assertSame('ALREADY_MAPPED', $result['items'][0]['classification']);
        self::assertSame('RETIRED', $result['items'][1]['classification']);
        self::assertSame('BLOCKED', $result['items'][1]['eligibility']);
    }
}
