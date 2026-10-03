<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntryMaterializationService;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry};
use PHPUnit\Framework\TestCase;

final class DictionaryEntryMaterializationServiceTest extends TestCase
{
    public function test_apply_reuses_existing_concept_and_is_idempotent(): void
    {
        $concept = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, [], 4);
        $concepts = new class($concept) implements DictionaryConceptRepository {
            public function __construct(public DictionaryConcept $concept) {}
            public function findById(string $id): ?DictionaryConcept { return $id === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $n, array $c = []): array { return []; }
            public function listApproved(int $l = 500): array { return [$this->concept]; }
            public function listLabels(string $id, bool $a = false): array { return [new DictionaryLabel($id, 'Côn', 'côn', DictionaryLabel::PREFERRED)]; }
            public function createConcept(DictionaryConcept $c): DictionaryConcept { throw new \LogicException('no concept writes'); }
            public function updateConcept(DictionaryConcept $c, int $r): DictionaryConcept { throw new \LogicException('no concept writes'); }
            public function addLabel(DictionaryLabel $l): DictionaryLabel { throw new \LogicException('no label writes'); }
            public function saveLabel(DictionaryLabel $l, string $p, int $r): DictionaryLabel { throw new \LogicException('no label writes'); }
        };
        $entries = new class($concept) {
            public int $writes = 0;
            public function __construct(private DictionaryConcept $concept) {}
            public function findForConcept(string $id): ?LexicalEntry { return null; }
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array
            {
                if ($this->concept->conceptId !== $sense->conceptId) throw new \LogicException('unexpected sense');
                $this->writes++;
                return ['entry' => $entry, 'sense' => $sense, 'forms' => []];
            }
        };
        $receipts = [];
        $audits = [];
        $service = new DictionaryEntryMaterializationService(
            $concepts,
            $entries,
            static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; },
            static function (array $event) use (&$audits): void { $audits[] = $event; },
        );
        $plan = ['status' => 'READY', 'items' => [[
            'concept_id' => $concept->conceptId,
            'concept_revision' => 4,
            'preferred_label' => 'Côn',
            'context' => [],
            'eligibility' => 'READY',
            'classification' => 'UNMAPPED_CONCEPT',
            'proposed_operation' => 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE',
            'form' => ['text' => 'Côn', 'normalized_form' => 'côn', 'locale' => 'vi-VN'],
        ]]];
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $first = $service->apply($plan, $plan['fingerprint'], 'materialize-1');
        $replay = $service->apply($plan, $plan['fingerprint'], 'materialize-1');

        self::assertSame(1, $entries->writes);
        self::assertSame($first, $replay);
        self::assertSame($concept->conceptId, $first['items'][0]['sense_id']);
        self::assertCount(1, $audits);
    }

    public function test_stale_plan_is_rejected_before_repository_write(): void
    {
        $concept = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, [], 5);
        $concepts = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $id): ?DictionaryConcept { return $this->concept; }
            public function findApprovedByNormalizedLabel(string $n, array $c = []): array { return []; }
            public function listApproved(int $l = 500): array { return [$this->concept]; }
            public function listLabels(string $id, bool $a = false): array { return []; }
            public function createConcept(DictionaryConcept $c): DictionaryConcept { return $c; }
            public function updateConcept(DictionaryConcept $c, int $r): DictionaryConcept { return $c; }
            public function addLabel(DictionaryLabel $l): DictionaryLabel { return $l; }
            public function saveLabel(DictionaryLabel $l, string $p, int $r): DictionaryLabel { return $l; }
        };
        $entries = new class { public int $writes = 0; public function createWithSense(LexicalEntry $e, DictionaryConcept $s, array $c): array { $this->writes++; return []; } };
        $service = new DictionaryEntryMaterializationService($concepts, $entries);
        $plan = ['status' => 'READY', 'items' => [['concept_id' => $concept->conceptId, 'concept_revision' => 4, 'eligibility' => 'READY', 'classification' => 'UNMAPPED_CONCEPT', 'proposed_operation' => 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE', 'form' => ['text' => 'Côn', 'normalized_form' => 'côn']]], 'fingerprint' => 'fp'];

        $this->expectExceptionMessage('PLAN_REAPPROVAL_REQUIRED');
        $service->apply($plan, 'fp', 'materialize-stale');
        self::assertSame(0, $entries->writes);
    }
}
