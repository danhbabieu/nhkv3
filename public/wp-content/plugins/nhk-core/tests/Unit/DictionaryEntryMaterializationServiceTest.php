<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntryMaterializationService;
use NHK\Core\Application\Dictionary\DictionaryPreCreateResolver;
use NHK\Core\Contracts\Dictionary\{DictionaryConceptRepository, DictionaryEntryRepository};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, DictionaryPreCreateResolution, LexicalEntry, LexicalEntryForm};
use PHPUnit\Framework\TestCase;

final class DictionaryEntryMaterializationServiceTest extends TestCase
{
    public function test_apply_reuses_existing_concept_and_is_idempotent(): void
    {
        $concept = new DictionaryConcept('01a10626-1317-77fc-9504-5800350cd07b', 'Côn hoa thị', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, [], 4);
        $concepts = new class($concept) implements DictionaryConceptRepository {
            public function __construct(public DictionaryConcept $concept) {}
            public function findById(string $id): ?DictionaryConcept { return $id === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $n, array $c = []): array { return []; }
            public function listApproved(int $l = 500): array { return [$this->concept]; }
            public function listLabels(string $id, bool $a = false): array { return [new DictionaryLabel($id, 'Côn hoa thị', 'côn hoa thị', DictionaryLabel::PREFERRED)]; }
            public function createConcept(DictionaryConcept $c): DictionaryConcept { throw new \LogicException('no concept writes'); }
            public function updateConcept(DictionaryConcept $c, int $r): DictionaryConcept { throw new \LogicException('no concept writes'); }
            public function addLabel(DictionaryLabel $l): DictionaryLabel { throw new \LogicException('no label writes'); }
            public function saveLabel(DictionaryLabel $l, string $p, int $r): DictionaryLabel { throw new \LogicException('no label writes'); }
        };
        $entries = new class($concept) implements DictionaryEntryRepository {
            public int $writes = 0;
            public function __construct(private DictionaryConcept $concept) {}
            public function findForConcept(string $id): ?LexicalEntry { return null; }
            public function findByForm(string $normalizedForm, array $context = []): array { return []; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return []; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array
            {
                if ($this->concept->conceptId !== $sense->conceptId) throw new \LogicException('unexpected sense');
                $this->writes++;
                return ['entry' => $entry, 'sense' => $sense, 'forms' => []];
            }
            public function createWithSenseResolved(LexicalEntry $entry, DictionaryConcept $sense, array $context, DictionaryPreCreateResolution $resolution): array
            {
                return $this->createWithSense($entry, $sense, $context);
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
            null,
            new DictionaryPreCreateResolver($entries),
        );
        $plan = ['status' => 'READY', 'items' => [[
            'concept_id' => $concept->conceptId,
            'concept_revision' => 4,
            'preferred_label' => 'Côn hoa thị',
            'context' => [],
            'eligibility' => 'READY',
            'classification' => 'UNMAPPED_CONCEPT',
            'proposed_operation' => 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE',
            'form' => ['text' => 'Côn hoa thị', 'normalized_form' => 'côn hoa thị', 'locale' => 'vi-VN'],
        ]]];
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $first = $service->apply($plan, $plan['fingerprint'], 'materialize-1');
        $replay = $service->apply($plan, $plan['fingerprint'], 'materialize-1');

        self::assertSame(1, $entries->writes);
        self::assertSame($first, $replay);
        self::assertSame($concept->conceptId, $first['items'][0]['sense_id']);
        self::assertSame('Côn hoa thị', $first['items'][0]['form']);
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
        $entries = new class implements DictionaryEntryRepository {
            public int $writes = 0;
            public function findByForm(string $normalizedForm, array $context = []): array { return []; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return []; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function createWithSense(LexicalEntry $e, DictionaryConcept $s, array $c): array { $this->writes++; return []; }
            public function createWithSenseResolved(LexicalEntry $e, DictionaryConcept $s, array $c, DictionaryPreCreateResolution $resolution): array { return $this->createWithSense($e, $s, $c); }
        };
        $service = new DictionaryEntryMaterializationService($concepts, $entries, null, null, null, null, new DictionaryPreCreateResolver($entries));
        $plan = ['status' => 'READY', 'items' => [['concept_id' => $concept->conceptId, 'concept_revision' => 4, 'eligibility' => 'READY', 'classification' => 'UNMAPPED_CONCEPT', 'proposed_operation' => 'CREATE_ENTRY_AND_MAP_EXISTING_SENSE', 'form' => ['text' => 'Côn', 'normalized_form' => 'côn']]], 'fingerprint' => 'fp'];

        $this->expectExceptionMessage('PLAN_REAPPROVAL_REQUIRED');
        $service->apply($plan, 'fp', 'materialize-stale');
        self::assertSame(0, $entries->writes);
    }
}
