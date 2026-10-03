<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Contracts\Dictionary\{DictionaryConceptRepository, DictionaryEntryRepository};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDictionaryEntryRepository implements DictionaryEntryRepository
{
    private string $entries;
    private string $forms;
    private string $senses;

    public function __construct(private object $database, private DictionaryConceptRepository $concepts)
    {
        $this->entries = $database->prefix . 'nhk_dictionary_entries';
        $this->forms = $database->prefix . 'nhk_dictionary_forms';
        $this->senses = $database->prefix . 'nhk_dictionary_entry_senses';
    }

    public function findByForm(string $normalizedForm, array $context = []): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT DISTINCT e.* FROM {$this->entries} e INNER JOIN {$this->forms} f ON f.entry_uuid=e.entry_uuid WHERE f.normalized_form=%s AND f.state=1 AND e.status<>%s ORDER BY e.id", $normalizedForm, DictionaryConcept::RETIRED), ARRAY_A) ?: [];
        $out = array_values(array_filter(array_map(fn (array $row): ?LexicalEntry => $this->hydrateEntry($row), $rows)));
        if ($out !== []) return $out;
        $legacy = $this->concepts->findApprovedByNormalizedLabel($normalizedForm, $context);
        foreach ($legacy as $row) {
            $id = trim((string) ($row['concept_id'] ?? ''));
            $label = trim((string) ($row['label'] ?? $row['preferred_label'] ?? ''));
            if ($id === '' || $label === '') continue;
            $out[] = new LexicalEntry($id, $label, $normalizedForm, DictionaryConcept::APPROVED, (string) ($row['locale'] ?? ''), [], 1, [$id]);
        }
        return $out;
    }

    public function findForConcept(string $conceptId): ?LexicalEntry
    {
        try {
            $durable = $this->findDurableForConcept($conceptId);
            if ($durable instanceof LexicalEntry) return $durable;
            $concept = $this->concepts->findById($conceptId);
            if (!$concept instanceof DictionaryConcept) return null;
            return new LexicalEntry($conceptId, $concept->preferredLabel, $this->normalize($concept->preferredLabel), $concept->status, null, [], $concept->revision, [$conceptId]);
        } catch (\Throwable) { return null; }
    }

    /** Read only a persisted Entry→Sense mapping; never returns the compatibility fallback. */
    public function findDurableForConcept(string $conceptId): ?LexicalEntry
    {
        try {
            $row = $this->database->get_row($this->database->prepare("SELECT e.* FROM {$this->entries} e INNER JOIN {$this->senses} s ON s.entry_uuid=e.entry_uuid WHERE s.concept_uuid=%s AND s.state=1 LIMIT 1", UuidCodec::toBinary($conceptId)), ARRAY_A);
            return is_array($row) ? $this->hydrateEntry($row) : null;
        } catch (\Throwable) { return null; }
    }

    public function listSenses(LexicalEntry $entry, array $context = []): array
    {
        $ids = $entry->senseIds;
        if ($ids === []) {
            $rows = $this->database->get_results($this->database->prepare("SELECT concept_uuid FROM {$this->senses} WHERE entry_uuid=%s AND state=1 ORDER BY id", UuidCodec::toBinary($entry->entryId)), ARRAY_A) ?: [];
            $ids = array_map(static fn (array $row): string => UuidCodec::fromBinary($row['concept_uuid']), $rows);
        }
        $out = [];
        foreach ($ids as $id) {
            $concept = $this->concepts->findById((string) $id);
            if ($concept instanceof DictionaryConcept && $this->matchesContext($concept->context, $context)) $out[$concept->conceptId] = $concept;
        }
        return array_values($out);
    }

    /** @return list<LexicalEntryForm> */
    public function listForms(LexicalEntry $entry): array
    {
        try {
            $rows = $this->database->get_results($this->database->prepare("SELECT form_text,normalized_form,form_kind,locale,context_json,state FROM {$this->forms} WHERE entry_uuid=%s AND state=1 ORDER BY id", UuidCodec::toBinary($entry->entryId)), ARRAY_A) ?: [];
            $forms = [];
            foreach ($rows as $row) {
                $form = trim((string) ($row['form_text'] ?? ''));
                $normalized = trim((string) ($row['normalized_form'] ?? ''));
                if ($form === '' || $normalized === '') continue;
                $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
                $forms[] = new LexicalEntryForm($entry->entryId, $form, $normalized, (string) ($row['form_kind'] ?? LexicalEntryForm::ALTERNATE), ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, is_array($context) ? $context : [], true);
            }
            return $forms;
        } catch (\Throwable) { return []; }
    }

    /** @return array<string,mixed> */
    public function semanticReference(string $entryId, string $senseId): array
    {
        try {
            $row = $this->database->get_row($this->database->prepare(
                "SELECT semantic_reference_type,semantic_reference_id,semantic_reference_revision FROM {$this->senses} WHERE entry_uuid=%s AND concept_uuid=%s AND state=1 LIMIT 1",
                UuidCodec::toBinary($entryId),
                UuidCodec::toBinary($senseId),
            ), ARRAY_A);
            if (!is_array($row)) return ['status' => 'ABSENT', 'source' => 'NONE', 'type' => null, 'id' => null, 'revision' => null];
            $type = trim((string) ($row['semantic_reference_type'] ?? ''));
            $id = trim((string) ($row['semantic_reference_id'] ?? ''));
            if ($type === '' && $id === '') return ['status' => 'ABSENT', 'source' => 'MAPPING', 'type' => null, 'id' => null, 'revision' => null];
            if ($type === '' || $id === '') return ['status' => 'INVALID', 'source' => 'MAPPING', 'type' => $type !== '' ? $type : null, 'id' => $id !== '' ? $id : null, 'revision' => null];
            return ['status' => 'AVAILABLE', 'source' => 'MAPPING', 'type' => $type, 'id' => $id, 'revision' => ($row['semantic_reference_revision'] ?? null) !== null ? (int) $row['semantic_reference_revision'] : null];
        } catch (\Throwable) {
            return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'source' => 'MAPPING', 'type' => null, 'id' => null, 'revision' => null];
        }
    }

    /** @return array<string,mixed> */
    public function setSenseSemanticReference(string $entryId, string $senseId, int $expectedRevision, string $semanticType, string $semanticId, ?int $semanticRevision): array
    {
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $mapping = $this->database->get_var($this->database->prepare(
            "SELECT id FROM {$this->senses} WHERE entry_uuid=%s AND concept_uuid=%s AND state=1 LIMIT 1",
            UuidCodec::toBinary($entryId), UuidCodec::toBinary($senseId),
        ));
        if ($mapping === null) throw new \RuntimeException('DICTIONARY_SENSE_MAPPING_NOT_FOUND');
        $this->database->query('START TRANSACTION');
        try {
            $updated = $this->database->query($this->database->prepare(
                "UPDATE {$this->senses} SET semantic_reference_type=%s,semantic_reference_id=%s,semantic_reference_revision=%s,updated_at=%s WHERE id=%d AND state=1",
                $semanticType, $semanticId, $semanticRevision, gmdate('Y-m-d H:i:s.u'), (int) $mapping,
            ));
            if ($updated !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_UPDATE_FAILED');
            $revisionUpdated = $this->database->query($this->database->prepare(
                "UPDATE {$this->entries} SET revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d",
                gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision,
            ));
            if ($revisionUpdated !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            $read = $this->semanticReference($entryId, $senseId);
            $updatedEntry = $this->findById($entryId);
            if (($read['status'] ?? '') !== 'AVAILABLE' || !$updatedEntry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_READBACK_FAILED');
            $this->database->query('COMMIT');
            return ['entry' => $updatedEntry, 'sense_id' => $senseId, 'semantic_reference' => $read, 'entry_revision' => $updatedEntry->revision];
        } catch (\Throwable $e) {
            $this->database->query('ROLLBACK');
            throw $e;
        }
    }

    public function findById(string $entryId): ?LexicalEntry
    {
        try {
            $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->entries} WHERE entry_uuid=%s LIMIT 1", UuidCodec::toBinary($entryId)), ARRAY_A);
            return is_array($row) ? $this->hydrateEntry($row) : null;
        } catch (\Throwable) { return null; }
    }

    /** @return list<LexicalEntry> */
    public function listEntries(int $limit = 500): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->entries} WHERE status=%s ORDER BY preferred_form,id LIMIT %d", DictionaryConcept::APPROVED, max(1, min(2000, $limit))), ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?LexicalEntry => $this->hydrateEntry($row), $rows)));
    }

    public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context = []): array
    {
        if ($this->findById($entry->entryId) instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_DUPLICATE');
        $existingSense = $this->concepts->findById($sense->conceptId);
        if ($existingSense instanceof DictionaryConcept) $sense = $existingSense;
        $now = gmdate('Y-m-d H:i:s.u');
        $this->database->query('START TRANSACTION');
        try {
            $insert = $this->database->query($this->database->prepare("INSERT INTO {$this->entries} (entry_uuid,preferred_form,normalized_preferred_form,status,locale,context_json,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%d,%s,%s)", UuidCodec::toBinary($entry->entryId), $entry->preferredForm, $entry->normalizedPreferredForm, $entry->status, $entry->locale, $this->json($entry->context), $entry->revision, $now, $now));
            if ($insert === false) throw new \RuntimeException('DICTIONARY_ENTRY_CREATE_FAILED');
            if (!$existingSense instanceof DictionaryConcept) $this->concepts->createConcept($sense);
            $this->addForm($this->form($entry, LexicalEntryForm::PREFERRED));
            $this->insertSense($entry->entryId, $sense, $context);
            $read = $this->findById($entry->entryId);
            $senseRead = $this->concepts->findById($sense->conceptId);
            if (!$read instanceof LexicalEntry || !$senseRead instanceof DictionaryConcept) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            $this->database->query('COMMIT');
            return ['entry' => new LexicalEntry($read->entryId, $read->preferredForm, $read->normalizedPreferredForm, $read->status, $read->locale, $read->context, $read->revision, [$senseRead->conceptId]), 'sense' => $senseRead, 'forms' => [$this->form($read, LexicalEntryForm::PREFERRED)]];
        } catch (\Throwable $e) {
            $this->database->query('ROLLBACK');
            throw $e;
        }
    }

    public function addFormToEntry(string $entryId, int $expectedRevision, LexicalEntryForm $form): array
    {
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $hash = hash('sha256', $this->json($this->sort($form->context)));
        $duplicate = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->forms} WHERE entry_uuid=%s AND normalized_form=%s AND context_hash=%s LIMIT 1", UuidCodec::toBinary($entryId), $form->normalizedForm, $hash));
        if ($duplicate !== null) return ['entry' => $entry, 'form' => $form, 'duplicate' => true];
        $this->addForm($form);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $updated = $this->findById($entryId);
        if (!$updated instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
        return ['entry' => $updated, 'form' => $form];
    }

    public function addSenseToEntry(string $entryId, int $expectedRevision, DictionaryConcept $sense, array $context = [], ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): array
    {
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $duplicate = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->senses} WHERE entry_uuid=%s AND concept_uuid=%s LIMIT 1", UuidCodec::toBinary($entryId), UuidCodec::toBinary($sense->conceptId)));
        if ($duplicate !== null) return ['entry' => $entry, 'sense' => $sense, 'duplicate' => true];
        if ($this->concepts->findById($sense->conceptId) === null) $this->concepts->createConcept($sense);
        $this->insertSense($entryId, $sense, $context, $semanticType, $semanticId, $semanticRevision);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $updated = $this->findById($entryId);
        if (!$updated instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
        return ['entry' => $updated, 'sense' => $sense];
    }

    public function addForm(LexicalEntryForm $form): LexicalEntryForm
    {
        $hash = hash('sha256', json_encode($this->sort($form->context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $now = gmdate('Y-m-d H:i:s.u');
        $existing = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->forms} WHERE entry_uuid=%s AND normalized_form=%s AND context_hash=%s LIMIT 1", UuidCodec::toBinary($form->entryId), $form->normalizedForm, $hash));
        if ($existing !== null) return $form;
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->forms} (entry_uuid,form_text,normalized_form,form_kind,locale,context_hash,context_json,state,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%s,%s)", UuidCodec::toBinary($form->entryId), $form->form, $form->normalizedForm, $form->kind, $form->locale, $hash, $this->json($form->context), $form->active ? 1 : 0, $now, $now));
        if ($ok === false) throw new \RuntimeException('DICTIONARY_ENTRY_FORM_CREATE_FAILED');
        return $form;
    }

    private function hydrateEntry(array $row): ?LexicalEntry
    {
        try {
            $entryId = UuidCodec::fromBinary($row['entry_uuid']);
            $senseRows = $this->database->get_results($this->database->prepare("SELECT concept_uuid FROM {$this->senses} WHERE entry_uuid=%s AND state=1 ORDER BY id", UuidCodec::toBinary($entryId)), ARRAY_A) ?: [];
            $senseIds = [];
            foreach ($senseRows as $senseRow) {
                try { $senseIds[] = UuidCodec::fromBinary($senseRow['concept_uuid']); } catch (\Throwable) { continue; }
            }
            return new LexicalEntry($entryId, (string) $row['preferred_form'], (string) $row['normalized_preferred_form'], (string) $row['status'], ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, $this->decode((string) ($row['context_json'] ?? '{}')), (int) ($row['revision'] ?? 1), array_values(array_unique($senseIds)));
        } catch (\Throwable) { return null; }
    }

    private function insertSense(string $entryId, DictionaryConcept $sense, array $context, ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): void
    {
        $hash = hash('sha256', $this->json($this->sort($context)));
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->senses} (entry_uuid,concept_uuid,sense_context_hash,semantic_reference_type,semantic_reference_id,semantic_reference_revision,context_json,state,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,1,%s,%s)", UuidCodec::toBinary($entryId), UuidCodec::toBinary($sense->conceptId), $hash, $semanticType, $semanticId, $semanticRevision, $this->json($context), gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) throw new \RuntimeException('DICTIONARY_ENTRY_SENSE_CREATE_FAILED');
    }

    private function form(LexicalEntry $entry, string $kind): LexicalEntryForm
    {
        return new LexicalEntryForm($entry->entryId, $entry->preferredForm, $entry->normalizedPreferredForm, $kind, $entry->locale, $entry->context);
    }

    private function matchesContext(array $senseContext, array $requested): bool
    {
        foreach (['domain', 'locale', 'region', 'community', 'usage_scope'] as $key) {
            if (array_key_exists($key, $requested) && $requested[$key] !== null && $requested[$key] !== '' && ($senseContext[$key] ?? null) !== $requested[$key]) return false;
        }
        return true;
    }

    private function normalize(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); }
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }
    private function sort(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->sort($item); return $value; }
}
