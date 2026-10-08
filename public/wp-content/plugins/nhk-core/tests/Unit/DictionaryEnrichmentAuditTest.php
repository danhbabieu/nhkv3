<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEnrichmentAudit;
use NHK\Core\Application\Dictionary\DictionaryEnrichmentCoverage;
use NHK\Core\Application\Dictionary\DictionaryDetailQuery;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentAuditTest extends TestCase
{
    public function test_audit_is_bounded_and_preserves_explicit_coverage_states(): void
    {
        $sense = new DictionaryConcept('sense-1', '400 ngày', 'Đồng hồ.', DictionaryConcept::APPROVED, 'classification', 'owner-1', null, ['approved_legacy_labels' => ['400-Day Clock']]);
        $entry = new LexicalEntry('entry-1', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 4, ['sense-1']);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function listEntries(int $limit): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return [new LexicalEntryForm($entry->entryId, '400 ngày', '400 ngày', LexicalEntryForm::PREFERRED, 'vi-VN')]; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'PRESENT_VALID', 'type' => 'classification', 'id' => 'owner-1', 'revision' => 3]; }
        };
        $audit = new DictionaryEnrichmentAudit(
            $entries,
            new class { public function listLabels(string $id, bool $active = true): array { return []; } },
            static fn (string $type, string $id, array $context = []): array => [
                'knowledge' => ['status' => 'EMPTY', 'count' => 0],
                'media' => ['status' => 'UNAVAILABLE', 'count' => 0],
                'video' => ['status' => 'AVAILABLE', 'count' => 2],
                'articles' => ['status' => 'AVAILABLE', 'count' => 1],
                'brands' => ['status' => 'EMPTY', 'count' => 0],
                'models' => ['status' => 'EMPTY', 'count' => 0],
                'specimens' => ['status' => 'EMPTY', 'count' => 0],
                'mentions' => ['status' => 'AVAILABLE', 'count' => 4, 'by_kind' => ['ARTICLE' => 2, 'VIDEO' => 2]],
                'related_terms' => ['status' => 'AVAILABLE', 'count' => 1],
            ],
            static fn (DictionaryConcept $sense): array => ['classification' => 'EXACT_UNIQUE', 'target' => ['type' => $sense->destinationType, 'id' => $sense->destinationId], 'evidence' => ['explicit_legacy_destination'], 'reason' => 'explicit destination'],
        );

        $result = $audit->audit(1);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertCount(1, $result['items']);
        self::assertSame('PRESENT_VALID', $result['items'][0]['semantic_reference']['status']);
        self::assertSame('UNAVAILABLE', $result['items'][0]['coverage']['media']['status']);
        self::assertSame(2, $result['items'][0]['mentions']['by_kind']['ARTICLE']);
        self::assertSame('OWNER_GAP', $result['items'][0]['classification']);
    }

    public function test_audit_cursor_continues_without_scanning_public_detail_unboundedly(): void
    {
        $make = static fn (string $id, string $label): LexicalEntry => new LexicalEntry($id, $label, strtolower($label), DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$id . '-sense']);
        $entries = new class($make('entry-1', 'Một'), $make('entry-2', 'Hai')) {
            public function __construct(private LexicalEntry $one, private LexicalEntry $two) {}
            public function listEntries(int $limit): array { return [$this->one, $this->two]; }
            public function listSenses(LexicalEntry $entry): array { return [new DictionaryConcept($entry->senseIds[0], $entry->preferredForm, 'Nghĩa', DictionaryConcept::APPROVED)]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'ABSENT']; }
        };
        $audit = new DictionaryEnrichmentAudit($entries, new class { public function listLabels(string $id, bool $active = true): array { return []; } }, static fn (): array => [], static fn (): array => ['classification' => 'NO_OWNER']);

        $first = $audit->audit(1);
        $second = $audit->audit(1, $first['next_cursor']);

        self::assertTrue($first['has_more']);
        self::assertSame('entry-2', $second['items'][0]['entry_id']);
        self::assertFalse($second['has_more']);
    }

    public function test_mapping_level_present_reference_is_strong_owner_evidence(): void
    {
        $sense = new DictionaryConcept('sense-map', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-map', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', [], 3, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function listEntries(int $limit): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'PRESENT_VALID', 'type' => 'classification', 'id' => 'owner-400', 'revision' => 9]; }
        };
        $audit = new DictionaryEnrichmentAudit($entries, new class { public function listLabels(string $id, bool $active = true): array { return []; } }, static fn (): array => [], static fn (DictionaryConcept $sense, array $context = []): array => (new \NHK\Core\Application\Dictionary\DictionaryEnrichmentOwnerResolver())->resolve($sense, $context));

        $item = $audit->audit(1)['items'][0];

        self::assertSame('EXACT_UNIQUE', $item['senses'][0]['owner_resolution']['classification']);
        self::assertSame('owner-400', $item['senses'][0]['owner_resolution']['target']['id']);
    }

    public function test_available_mapping_status_is_not_reclassified_as_invalid(): void
    {
        $sense = new DictionaryConcept('sense-available', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-available', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', [], 2, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function listEntries(int $limit): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'classification', 'id' => 'owner-400', 'revision' => 2]; }
        };
        $audit = new DictionaryEnrichmentAudit($entries, new class { public function listLabels(string $id, bool $active = true): array { return []; } }, static fn (): array => [], static fn (DictionaryConcept $sense, array $context = []): array => (new \NHK\Core\Application\Dictionary\DictionaryEnrichmentOwnerResolver())->resolve($sense, $context));

        $item = $audit->audit(1)['items'][0];

        self::assertSame('AVAILABLE', $item['semantic_reference']['status']);
        self::assertSame('EXACT_UNIQUE', $item['senses'][0]['owner_resolution']['classification']);
        self::assertSame('COMPLETE', $item['classification']);
    }

    public function test_audit_uses_the_same_semantic_reference_owner_and_bounded_knowledge_as_public_detail(): void
    {
        $sense = new DictionaryConcept('sense-400', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED, 'classification', 'owner-400');
        $entry = new LexicalEntry('entry-400', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, ['sense-400']);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function listEntries(int $limit): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'PRESENT_VALID', 'type' => 'classification', 'id' => 'owner-400', 'revision' => 7]; }
        };
        $ownerDossier = ['identity' => ['type' => 'classification', 'id' => 'owner-400'], 'knowledge' => ['status' => 'AVAILABLE', 'facets' => [
            'movement' => [['id' => 'k1'], ['id' => 'k2']],
            'material' => [['id' => 'k3']],
            'origin' => [['id' => 'k4'], ['id' => 'k5']],
        ]]];
        $audit = new DictionaryEnrichmentAudit(
            $entries,
            new class { public function listLabels(string $id, bool $active = true): array { return []; } },
            function (string $type, string $id, array $context = []) use ($ownerDossier): array { return ['knowledge' => DictionaryEnrichmentCoverage::knowledgeFromOwnerDossier($ownerDossier)]; },
            static fn (DictionaryConcept $sense, array $context = []): array => (new \NHK\Core\Application\Dictionary\DictionaryEnrichmentOwnerResolver())->resolve($sense, $context),
        );

        $item = $audit->audit(1)['items'][0];
        $public = (new DictionaryDetailQuery(
            new class { public function listLabels(string $id): array { return []; } },
            $entries,
            null,
            function (string $type, string $id) use ($ownerDossier): array { return $ownerDossier; },
        ))->detail('400-ngay')['item']['senses'][0]['knowledge'];

        self::assertSame('PRESENT_VALID', $item['semantic_reference']['status']);
        self::assertSame('owner-400', $item['senses'][0]['owner_resolution']['target']['id']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $item['coverage']['knowledge']['status']);
        self::assertSame(5, $item['coverage']['knowledge']['count']);
        self::assertSame(['k1', 'k2', 'k3', 'k4', 'k5'], array_column($item['coverage']['knowledge']['items'], 'id'));
        self::assertSame($public['items'], $item['coverage']['knowledge']['items']);
    }

    public function test_audit_exposes_context_hint_separately_from_absent_persisted_mapping(): void
    {
        $sense = new DictionaryConcept('sense-hint-audit', 'Westminster chime', 'Định nghĩa.', DictionaryConcept::APPROVED, null, null, null, [
            'semantic_reference' => ['status' => 'AVAILABLE', 'type' => 'music', 'id' => 'owner-hint', 'revision' => 7],
        ]);
        $entry = new LexicalEntry('entry-hint-audit', 'Westminster chime', 'westminster chime', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'westminster-chime'], 2, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function listEntries(int $limit): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'ABSENT', 'source' => 'MAPPING']; }
        };
        $audit = new DictionaryEnrichmentAudit(
            $entries,
            new class { public function listLabels(string $id, bool $active = true): array { return []; } },
            static fn (): array => [],
            static fn (DictionaryConcept $sense, array $context = []): array => (new \NHK\Core\Application\Dictionary\DictionaryEnrichmentOwnerResolver())->resolve($sense, $context),
        );

        $sensePacket = $audit->audit(1)['items'][0]['senses'][0];

        self::assertSame('ABSENT', $sensePacket['persisted_semantic_reference']['status']);
        self::assertSame('owner-hint', $sensePacket['owner_hint']['id']);
        self::assertSame('EXACT_UNIQUE', $sensePacket['owner_resolution']['classification']);
    }
}
