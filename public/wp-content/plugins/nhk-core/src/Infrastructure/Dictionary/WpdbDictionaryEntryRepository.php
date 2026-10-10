<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Application\Dictionary\DictionaryEntryPublicIdentityWriter;
use NHK\Core\Contracts\Dictionary\{DictionaryConceptRepository, DictionaryDuplicateAuditReader, DictionaryEntryRepository};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryPreCreateResolution, LexicalEntry, LexicalEntryForm};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDictionaryEntryRepository implements DictionaryEntryRepository, DictionaryDuplicateAuditReader
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

    /** Read every non-deleted lexical collision for pre-create decisions, including DRAFT and RETIRED entries. */
    public function findPreCreateCandidates(string $normalizedForm, array $context = []): array
    {
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT DISTINCT e.* FROM {$this->entries} e INNER JOIN {$this->forms} f ON f.entry_uuid=e.entry_uuid WHERE f.normalized_form=%s ORDER BY e.id",
            $normalizedForm,
        ), ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?LexicalEntry => $this->hydrateEntry($row), $rows)));
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

    public function read(int $limit = 1000): array
    {
        $limit = max(1, min(10000, $limit));
        try {
            $rows = $this->database->get_results($this->database->prepare(
                "SELECT e.entry_uuid,e.preferred_form,e.status AS entry_status,e.revision AS entry_revision,f.id AS form_id,f.form_text,f.normalized_form,f.state,s.concept_uuid,s.context_json AS sense_context_json,s.semantic_reference_type,s.semantic_reference_id,s.state AS sense_state,c.status AS sense_status,c.revision AS sense_revision,c.context_json AS concept_context_json,c.destination_type,c.destination_id FROM {$this->entries} e INNER JOIN {$this->forms} f ON f.entry_uuid=e.entry_uuid INNER JOIN {$this->senses} s ON s.entry_uuid=e.entry_uuid INNER JOIN {$this->conceptsTable()} c ON c.concept_uuid=s.concept_uuid ORDER BY f.normalized_form,e.id,s.id LIMIT %d",
                $limit,
            ), ARRAY_A);
            if (!is_array($rows)) throw new \RuntimeException('DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE');
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                try {
                    $entryId = UuidCodec::fromBinary($row['entry_uuid']);
                    $senseId = UuidCodec::fromBinary($row['concept_uuid']);
                } catch (\Throwable) {
                    continue;
                }
                $senseContext = $this->decode((string) ($row['sense_context_json'] ?? '{}'));
                $out[] = [
                    'entry_id' => $entryId,
                    'form_id' => (string) ($row['form_id'] ?? ''),
                    'sense_id' => $senseId,
                    'form_text' => (string) ($row['form_text'] ?? ''),
                    'normalized_form' => (string) ($row['normalized_form'] ?? ''),
                    'entry_status' => (string) ($row['entry_status'] ?? ''),
                    'sense_status' => (string) ($row['sense_status'] ?? ''),
                    'entry_revision' => (int) ($row['entry_revision'] ?? 0),
                    'sense_revision' => (int) ($row['sense_revision'] ?? 0),
                    'context' => $senseContext !== [] ? $senseContext : $this->decode((string) ($row['concept_context_json'] ?? '{}')),
                    'destination_type' => ($row['semantic_reference_type'] ?? null) ?: (($row['destination_type'] ?? null) ?: null),
                    'destination_id' => ($row['semantic_reference_id'] ?? null) ?: (($row['destination_id'] ?? null) ?: null),
                    'state' => ((int) ($row['state'] ?? 0) === 1 && (int) ($row['sense_state'] ?? 0) === 1) ? 1 : 0,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            throw new \RuntimeException('DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE', 0, $e);
        }
    }

    /** @return array{rows:list<array<string,mixed>>,next_cursor:?string} */
    public function readPage(int $limit = 1000, ?string $cursor = null): array
    {
        $limit = max(1, min(10000, $limit));
        $after = $this->decodeDuplicateAuditCursor($cursor);
        $where = $after !== null
            ? " WHERE (f.normalized_form>%s OR (f.normalized_form=%s AND e.id>%d) OR (f.normalized_form=%s AND e.id=%d AND s.id>%d))"
            : '';
        $args = $after !== null ? [$after['normalized_form'], $after['normalized_form'], $after['entry_id'], $after['normalized_form'], $after['entry_id'], $after['sense_id']] : [];
        $args[] = $limit + 1;
        $sql = "SELECT e.id AS _entry_audit_id,s.id AS _sense_audit_id,e.entry_uuid,e.preferred_form,e.status AS entry_status,e.revision AS entry_revision,f.id AS form_id,f.form_text,f.normalized_form,f.state,s.concept_uuid,s.context_json AS sense_context_json,s.semantic_reference_type,s.semantic_reference_id,s.state AS sense_state,c.status AS sense_status,c.revision AS sense_revision,c.context_json AS concept_context_json,c.destination_type,c.destination_id FROM {$this->entries} e INNER JOIN {$this->forms} f ON f.entry_uuid=e.entry_uuid INNER JOIN {$this->senses} s ON s.entry_uuid=e.entry_uuid INNER JOIN {$this->conceptsTable()} c ON c.concept_uuid=s.concept_uuid{$where} ORDER BY f.normalized_form,e.id,s.id LIMIT %d";
        $rows = $this->database->get_results($this->database->prepare($sql, ...$args), ARRAY_A);
        if (!is_array($rows)) throw new \RuntimeException('DICTIONARY_DUPLICATE_AUDIT_UNAVAILABLE');
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            try { $entryId = UuidCodec::fromBinary($row['entry_uuid']); $senseId = UuidCodec::fromBinary($row['concept_uuid']); } catch (\Throwable) { continue; }
            $senseContext = $this->decode((string) ($row['sense_context_json'] ?? '{}'));
            $mapped[] = [
                'entry_id' => $entryId, 'form_id' => (string) ($row['form_id'] ?? ''), 'sense_id' => $senseId, 'form_text' => (string) ($row['form_text'] ?? ''), 'normalized_form' => (string) ($row['normalized_form'] ?? ''),
                'entry_status' => (string) ($row['entry_status'] ?? ''), 'sense_status' => (string) ($row['sense_status'] ?? ''), 'entry_revision' => (int) ($row['entry_revision'] ?? 0), 'sense_revision' => (int) ($row['sense_revision'] ?? 0),
                'context' => $senseContext !== [] ? $senseContext : $this->decode((string) ($row['concept_context_json'] ?? '{}')),
                'destination_type' => ($row['semantic_reference_type'] ?? null) ?: (($row['destination_type'] ?? null) ?: null),
                'destination_id' => ($row['semantic_reference_id'] ?? null) ?: (($row['destination_id'] ?? null) ?: null),
                'state' => ((int) ($row['state'] ?? 0) === 1 && (int) ($row['sense_state'] ?? 0) === 1) ? 1 : 0,
            ];
        }
        $next = $hasMore && is_array($rows[array_key_last($rows)] ?? null) ? $this->encodeDuplicateAuditCursor($rows[array_key_last($rows)]) : null;
        return ['rows' => $mapped, 'next_cursor' => $next];
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

    /**
     * Read one bounded public archive batch. Sense, form and label hydration is
     * batched so the public archive does not perform one query per entry.
     *
     * @return array{rows:list<array{kind:string,entry:LexicalEntry,senses:list<DictionaryConcept>,forms:list<LexicalEntryForm>,labels:array<string,list<\NHK\Core\Domain\Dictionary\DictionaryLabel>>}>,next_cursor:?string}
     */
    public function readArchiveCandidates(int $limit = 100, ?string $cursor = null, string $query = ''): array
    {
        $limit = max(1, min(500, $limit));
        $after = $this->decodeArchiveCursor($cursor);
        $where = "e.status=%s AND EXISTS (SELECT 1 FROM {$this->senses} es INNER JOIN {$this->conceptsTable()} ec ON ec.concept_uuid=es.concept_uuid WHERE es.entry_uuid=e.entry_uuid AND es.state=1 AND ec.status=%s)";
        $args = [DictionaryConcept::APPROVED, DictionaryConcept::APPROVED];
        if ($after !== null) {
            $where .= ' AND (e.preferred_form>%s OR (e.preferred_form=%s AND e.id>%d))';
            array_push($args, $after['label'], $after['label'], $after['id']);
        }
        $args[] = $limit + 1;
        $rows = $this->database->get_results($this->database->prepare("SELECT e.* FROM {$this->entries} e WHERE {$where} ORDER BY e.preferred_form,e.id LIMIT %d", ...$args), ARRAY_A);
        if (!is_array($rows)) throw new \RuntimeException('DICTIONARY_ARCHIVE_ENTRY_READ_FAILED');
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        if ($rows === []) return ['rows' => [], 'next_cursor' => null];

        $entryIds = [];
        $entryBinaries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            try {
                $entryId = UuidCodec::fromBinary($row['entry_uuid']);
                $entryIds[$entryId] = true;
                $entryBinaries[$entryId] = UuidCodec::toBinary($entryId);
            } catch (\Throwable) { continue; }
        }
        if ($entryBinaries === []) return ['rows' => [], 'next_cursor' => null];
        $placeholders = implode(',', array_fill(0, count($entryBinaries), '%s'));
        $senseRows = $this->database->get_results($this->database->prepare("SELECT es.entry_uuid,es.concept_uuid FROM {$this->senses} es INNER JOIN {$this->conceptsTable()} ec ON ec.concept_uuid=es.concept_uuid WHERE es.entry_uuid IN ({$placeholders}) AND es.state=1 AND ec.status=%s ORDER BY es.entry_uuid,es.id", ...array_merge(array_values($entryBinaries), [DictionaryConcept::APPROVED])), ARRAY_A);
        if (!is_array($senseRows)) throw new \RuntimeException('DICTIONARY_ARCHIVE_SENSE_READ_FAILED');
        $senseIdsByEntry = array_fill_keys(array_keys($entryIds), []);
        $conceptIds = [];
        foreach ($senseRows as $senseRow) {
            if (!is_array($senseRow)) continue;
            try {
                $entryId = UuidCodec::fromBinary($senseRow['entry_uuid']);
                $conceptId = UuidCodec::fromBinary($senseRow['concept_uuid']);
            } catch (\Throwable) { continue; }
            $senseIdsByEntry[$entryId][] = $conceptId;
            $conceptIds[$conceptId] = true;
        }
        $concepts = method_exists($this->concepts, 'findByIds') ? $this->concepts->findByIds(array_keys($conceptIds)) : [];
        if (!method_exists($this->concepts, 'findByIds')) foreach (array_keys($conceptIds) as $conceptId) { $concept = $this->concepts->findById($conceptId); if ($concept instanceof DictionaryConcept) $concepts[$conceptId] = $concept; }
        $labels = method_exists($this->concepts, 'listLabelsForConcepts') ? $this->concepts->listLabelsForConcepts(array_keys($conceptIds)) : [];
        $forms = $this->listFormsForEntries(array_keys($entryIds));
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            try { $entryId = UuidCodec::fromBinary($row['entry_uuid']); } catch (\Throwable) { continue; }
            $senseIds = array_values(array_unique($senseIdsByEntry[$entryId] ?? []));
            $entry = $this->hydrateEntry($row, $senseIds);
            if (!$entry instanceof LexicalEntry) continue;
            $senses = [];
            foreach ($senseIds as $senseId) if (($concepts[$senseId] ?? null) instanceof DictionaryConcept && $concepts[$senseId]->approved()) $senses[] = $concepts[$senseId];
            if ($senses === []) continue;
            $mapped[] = ['kind' => 'ENTRY', 'entry' => $entry, 'senses' => $senses, 'forms' => $forms[$entryId] ?? [], 'labels' => $labels];
        }
        $last = $rows[array_key_last($rows)] ?? null;
        return ['rows' => $mapped, 'next_cursor' => $hasMore && is_array($last) ? $this->encodeArchiveCursor((string) ($last['preferred_form'] ?? ''), (int) ($last['id'] ?? 0)) : null];
    }

    /** @return array<string,list<LexicalEntryForm>> */
    public function listFormsForEntries(array $entryIds): array
    {
        $binaries = [];
        foreach ($entryIds as $entryId) {
            try { $binaries[(string) $entryId] = UuidCodec::toBinary((string) $entryId); } catch (\Throwable) { continue; }
        }
        if ($binaries === []) return [];
        $placeholders = implode(',', array_fill(0, count($binaries), '%s'));
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->forms} WHERE entry_uuid IN ({$placeholders}) AND state=1 ORDER BY entry_uuid,id", ...array_values($binaries)), ARRAY_A) ?: [];
        $out = array_fill_keys(array_keys($binaries), []);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            try { $entryId = UuidCodec::fromBinary($row['entry_uuid']); } catch (\Throwable) { continue; }
            $form = trim((string) ($row['form_text'] ?? ''));
            $normalized = trim((string) ($row['normalized_form'] ?? ''));
            if ($form === '' || $normalized === '') continue;
            $out[$entryId][] = new LexicalEntryForm($entryId, $form, $normalized, (string) ($row['form_kind'] ?? LexicalEntryForm::ALTERNATE), ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, $this->decode((string) ($row['context_json'] ?? '{}')), true);
        }
        return $out;
    }

    public function findByPublicSlug(string $slug): ?LexicalEntry
    {
        try {
            $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->entries} WHERE status=%s AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.public_slug'))=%s ORDER BY id LIMIT 2", DictionaryConcept::APPROVED, trim($slug)), ARRAY_A) ?: [];
            if (count($rows) !== 1 || !is_array($rows[0])) return null;
            return $this->hydrateEntry($rows[0]);
        } catch (\Throwable) { return null; }
    }

    public function publicSlugTaken(string $slug, ?string $excludeEntryId = null): bool
    {
        $sql = "SELECT e.id FROM {$this->entries} e WHERE e.status<>%s AND JSON_UNQUOTE(JSON_EXTRACT(e.context_json,'$.public_slug'))=%s";
        $args = [DictionaryConcept::RETIRED, trim($slug)];
        if ($excludeEntryId !== null && UuidCodec::isValid($excludeEntryId)) {
            $sql .= ' AND e.entry_uuid<>%s';
            $args[] = UuidCodec::toBinary($excludeEntryId);
        }
        $sql .= ' LIMIT 1';
        return $this->database->get_var($this->database->prepare($sql, ...$args)) !== null;
    }

    public function syncStatusForSense(string $senseId, string $status): ?LexicalEntry
    {
        if (!in_array($status, [DictionaryConcept::DRAFT, DictionaryConcept::APPROVED, DictionaryConcept::RETIRED], true)) throw new \InvalidArgumentException('DICTIONARY_ENTRY_STATUS_INVALID');
        $entry = $this->findDurableForConcept($senseId);
        if (!$entry instanceof LexicalEntry) return null;
        // Sense lifecycle is derived from all eligible senses. An explicit
        // Entry retirement is sticky until the curator reactivates the Entry.
        $explicit = (($entry->context['_nhk_entry_lifecycle'] ?? '') === 'EXPLICIT_RETIRED');
        $approved = $this->database->get_var($this->database->prepare(
            "SELECT c.concept_uuid FROM {$this->senses} s INNER JOIN {$this->conceptsTable()} c ON c.concept_uuid=s.concept_uuid WHERE s.entry_uuid=%s AND s.state=1 AND c.status=%s LIMIT 1",
            UuidCodec::toBinary($entry->entryId), DictionaryConcept::APPROVED,
        ));
        $derived = $approved !== null ? DictionaryConcept::APPROVED : DictionaryConcept::RETIRED;
        if ($explicit && $derived === DictionaryConcept::APPROVED) $derived = DictionaryConcept::RETIRED;
        if ($entry->status === $derived) return $entry;
        $ok = $this->database->query($this->database->prepare(
            "UPDATE {$this->entries} SET status=%s,revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d",
            $derived, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entry->entryId), $entry->revision,
        ));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $updated = $this->findById($entry->entryId);
        if (!$updated instanceof LexicalEntry || $updated->status !== $derived) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
        return $updated;
    }

    /** @return array<string,mixed> */
    public function curatorRead(string $entryId): array
    {
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        $senses = [];
        foreach ($this->listSenses($entry) as $sense) {
            $senses[] = [
                'sense_id' => $sense->conceptId,
                'preferred_label' => $sense->preferredLabel,
                'definition' => $sense->definition,
                'status' => $sense->status,
                'context' => $this->boundedCuratorContext($sense->context),
                'revision' => $sense->revision,
                'semantic_reference' => $this->semanticReference($entry->entryId, $sense->conceptId),
            ];
        }
        return [
            'entry_id' => $entry->entryId,
            'preferred_form' => $entry->preferredForm,
            'normalized_preferred_form' => $entry->normalizedPreferredForm,
            'status' => $entry->status,
            'locale' => $entry->locale,
            'context' => $this->boundedCuratorContext($entry->context),
            'revision' => $entry->revision,
            'forms' => array_map(static fn (LexicalEntryForm $form): array => ['form' => $form->form, 'normalized_form' => $form->normalizedForm, 'kind' => $form->kind, 'locale' => $form->locale, 'context' => $form->context], $this->listForms($entry)),
            'senses' => $senses,
            'public_identity' => ['slug' => $entry->context['public_slug'] ?? null],
        ];
    }

    /** Apply Entry and optional Sense changes in one transaction. */
    public function updateCuration(array $input): array
    {
        $entryId = (string) $input['entry_id'];
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        $expectedEntry = (int) $input['expected_entry_revision'];
        if ($entry->revision !== $expectedEntry) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $sensePatch = is_array($input['sense_patch'] ?? null) ? $input['sense_patch'] : null;
        $sense = null;
        if ($sensePatch !== null) {
            $sense = $this->concepts->findById((string) ($sensePatch['sense_id'] ?? ''));
            if (!$sense instanceof DictionaryConcept || !in_array($sense->conceptId, $entry->senseIds, true)) throw new \RuntimeException('DICTIONARY_SENSE_NOT_MAPPED_TO_ENTRY');
            if ($sense->revision !== (int) ($sensePatch['expected_revision'] ?? 0)) throw new \RuntimeException('DICTIONARY_SENSE_REVISION_CONFLICT');
        }
        $this->database->query('START TRANSACTION');
        try {
            $preferred = array_key_exists('preferred_form', $input) ? trim((string) $input['preferred_form']) : $entry->preferredForm;
            $normalized = $this->normalize($preferred);
            if ($preferred === '' || $normalized === '') throw new \InvalidArgumentException('DICTIONARY_ENTRY_PREFERRED_FORM_REQUIRED');
            if ($normalized !== $entry->normalizedPreferredForm) {
                $collision = $this->database->get_var($this->database->prepare("SELECT f.id FROM {$this->forms} f INNER JOIN {$this->entries} e ON e.entry_uuid=f.entry_uuid WHERE f.normalized_form=%s AND f.state=1 AND e.entry_uuid<>%s LIMIT 1 FOR UPDATE", $normalized, UuidCodec::toBinary($entryId)));
                if ($collision !== null) throw new \RuntimeException('DICTIONARY_ENTRY_PREFERRED_FORM_COLLISION');
            }
            $locale = array_key_exists('locale', $input) ? $input['locale'] : $entry->locale;
            $context = array_key_exists('entry_context', $input) ? (array) $input['entry_context'] : $entry->context;
            $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET preferred_form=%s,normalized_preferred_form=%s,locale=%s,context_json=%s,revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", $preferred, $normalized, $locale, $this->json($context), gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedEntry));
            if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            // The old preferred projection is retired, never silently reclassified.
            $this->database->query($this->database->prepare("UPDATE {$this->forms} SET state=0,updated_at=%s WHERE entry_uuid=%s AND form_kind=%s AND state=1", gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), LexicalEntryForm::PREFERRED));
            $this->addForm(new LexicalEntryForm($entryId, $preferred, $normalized, LexicalEntryForm::PREFERRED, $locale !== null ? (string) $locale : null, $context));
            if ($sense instanceof DictionaryConcept) {
                $label = array_key_exists('preferred_label', $sensePatch) ? trim((string) $sensePatch['preferred_label']) : $sense->preferredLabel;
                $definition = array_key_exists('definition', $sensePatch) ? trim((string) $sensePatch['definition']) : $sense->definition;
                if ($label === '') throw new \InvalidArgumentException('DICTIONARY_SENSE_PREFERRED_LABEL_REQUIRED');
                $senseContext = array_key_exists('context', $sensePatch) ? (array) $sensePatch['context'] : $sense->context;
                $updated = $this->database->query($this->database->prepare("UPDATE {$this->conceptsTable()} SET preferred_label=%s,definition=%s,context_json=%s,revision=revision+1,updated_at=%s WHERE concept_uuid=%s AND revision=%d", $label, $definition, $this->json($senseContext), gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($sense->conceptId), $sense->revision));
                if ($updated !== 1) throw new \RuntimeException('DICTIONARY_SENSE_REVISION_CONFLICT');
            }
            $read = $this->curatorRead($entryId);
            $this->database->query('COMMIT');
            return $read;
        } catch (\Throwable $e) { $this->database->query('ROLLBACK'); throw $e; }
    }

    /** @return array<string,mixed> */
    public function lifecycleCuration(string $entryId, int $expectedRevision, string $status): array
    {
        if (!in_array($status, [DictionaryConcept::DRAFT, DictionaryConcept::APPROVED, DictionaryConcept::RETIRED], true)) throw new \InvalidArgumentException('DICTIONARY_ENTRY_STATUS_INVALID');
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        if ($status !== DictionaryConcept::RETIRED) {
            $collision = $this->database->get_var($this->database->prepare("SELECT f.id FROM {$this->forms} f INNER JOIN {$this->entries} e ON e.entry_uuid=f.entry_uuid WHERE f.normalized_form=%s AND f.state=1 AND e.entry_uuid<>%s LIMIT 1", $entry->normalizedPreferredForm, UuidCodec::toBinary($entryId)));
            if ($collision !== null) throw new \RuntimeException('DICTIONARY_ENTRY_PREFERRED_FORM_COLLISION');
            $slug = trim((string) ($entry->context['public_slug'] ?? ''));
            if ($slug !== '' && $this->publicSlugTaken($slug, $entryId)) throw new \RuntimeException('DICTIONARY_ENTRY_PUBLIC_IDENTITY_COLLISION');
        }
        $context = $entry->context;
        if ($status === DictionaryConcept::RETIRED) $context['_nhk_entry_lifecycle'] = 'EXPLICIT_RETIRED';
        else unset($context['_nhk_entry_lifecycle']);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET status=%s,context_json=%s,revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", $status, $this->json($context), gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $read = $this->curatorRead($entryId);
        if (($read['status'] ?? null) !== $status) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
        return $read;
    }

    public function ensurePublicIdentityForSense(string $senseId): ?LexicalEntry
    {
        $entry = $this->findDurableForConcept($senseId);
        if (!$entry instanceof LexicalEntry) return null;
        $writer = new DictionaryEntryPublicIdentityWriter(fn (string $slug, ?string $entryId = null): bool => $this->publicSlugTaken($slug, $entryId));
        $updated = $writer->assign($entry);
        if ($updated->context === $entry->context) return $entry;
        $ok = $this->database->query($this->database->prepare(
            "UPDATE {$this->entries} SET context_json=%s,revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d",
            $this->json($updated->context), gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entry->entryId), $entry->revision,
        ));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $read = $this->findById($entry->entryId);
        if (!$read instanceof LexicalEntry || trim((string) ($read->context['public_slug'] ?? '')) === '') throw new \RuntimeException('DICTIONARY_ENTRY_PUBLIC_IDENTITY_READBACK_FAILED');
        return $read;
    }

    /** @return list<LexicalEntry> */
    public function findEntriesBySemanticReference(string $type, string $id, int $limit = 13): array
    {
        try {
            $rows = $this->database->get_results($this->database->prepare("SELECT DISTINCT e.* FROM {$this->entries} e INNER JOIN {$this->senses} s ON s.entry_uuid=e.entry_uuid WHERE e.status=%s AND s.state=1 AND s.semantic_reference_type=%s AND s.semantic_reference_id=%s ORDER BY e.preferred_form,e.id LIMIT %d", DictionaryConcept::APPROVED, trim($type), trim($id), max(1, min(13, $limit))), ARRAY_A) ?: [];
            return array_values(array_filter(array_map(fn (array $row): ?LexicalEntry => $this->hydrateEntry($row), $rows)));
        } catch (\Throwable) { return []; }
    }

    public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context = []): array
    {
        return $this->createWithSenseInternal($entry, $sense, $context, null);
    }

    public function createWithSenseResolved(LexicalEntry $entry, DictionaryConcept $sense, array $context, DictionaryPreCreateResolution $resolution): array
    {
        return $this->createWithSenseInternal($entry, $sense, $context, $resolution);
    }

    public function assertPreCreateStillValid(DictionaryPreCreateResolution $resolution, array $context = []): void
    {
        if (!$resolution->canCreate()) throw new \RuntimeException('DICTIONARY_PRE_CREATE_STALE');
        $contextHash = hash('sha256', $this->json($this->sort($context)));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT f.id FROM {$this->forms} f INNER JOIN {$this->entries} e ON e.entry_uuid=f.entry_uuid WHERE f.normalized_form=%s AND f.state=1 AND e.status<>%s LIMIT 1 FOR UPDATE",
            $resolution->normalizedForm,
            DictionaryConcept::RETIRED,
        ), ARRAY_A) ?: [];
        if ($rows !== []) throw new \RuntimeException('DICTIONARY_PRE_CREATE_STALE');
    }

    private function createWithSenseInternal(LexicalEntry $entry, DictionaryConcept $sense, array $context, ?DictionaryPreCreateResolution $resolution): array
    {
        $writer = new DictionaryEntryPublicIdentityWriter(fn (string $slug, ?string $entryId = null): bool => $this->publicSlugTaken($slug, $entryId));
        $entry = $writer->assign($entry);
        if ($this->findById($entry->entryId) instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_DUPLICATE');
        $existingSense = $this->concepts->findById($sense->conceptId);
        if ($existingSense instanceof DictionaryConcept) $sense = $existingSense;
        $now = gmdate('Y-m-d H:i:s.u');
        $this->database->query('START TRANSACTION');
        try {
            if ($resolution instanceof DictionaryPreCreateResolution) $this->assertPreCreateStillValid($resolution, $context);
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
        $this->database->query('START TRANSACTION');
        try {
            $duplicate = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->forms} WHERE entry_uuid=%s AND normalized_form=%s AND context_hash=%s LIMIT 1 FOR UPDATE", UuidCodec::toBinary($entryId), $form->normalizedForm, $hash));
            if ($duplicate !== null) { $this->database->query('COMMIT'); return ['entry' => $entry, 'form' => $form, 'duplicate' => true]; }
            $collision = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->forms} WHERE entry_uuid<>%s AND normalized_form=%s AND state=1 LIMIT 1 FOR UPDATE", UuidCodec::toBinary($entryId), $form->normalizedForm));
            if ($collision !== null) throw new \RuntimeException('DICTIONARY_PRE_CREATE_STALE');
            $this->addForm($form);
            $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision));
            if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            $updated = $this->findById($entryId);
            if (!$updated instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            $this->database->query('COMMIT');
            return ['entry' => $updated, 'form' => $form];
        } catch (\Throwable $e) { $this->database->query('ROLLBACK'); throw $e; }
    }

    public function addSenseToEntry(string $entryId, int $expectedRevision, DictionaryConcept $sense, array $context = [], ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): array
    {
        $entry = $this->findById($entryId);
        if (!$entry instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_NOT_FOUND');
        if ($entry->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
        $this->database->query('START TRANSACTION');
        try {
            $duplicate = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->senses} WHERE entry_uuid=%s AND concept_uuid=%s AND state=1 LIMIT 1 FOR UPDATE", UuidCodec::toBinary($entryId), UuidCodec::toBinary($sense->conceptId)));
            if ($duplicate !== null) { $this->database->query('COMMIT'); return ['entry' => $entry, 'sense' => $sense, 'duplicate' => true]; }
            $mapped = $this->database->get_var($this->database->prepare("SELECT id FROM {$this->senses} WHERE concept_uuid=%s AND state=1 LIMIT 1 FOR UPDATE", UuidCodec::toBinary($sense->conceptId)));
            if ($mapped !== null) throw new \RuntimeException('DICTIONARY_PRE_CREATE_STALE');
            if ($this->concepts->findById($sense->conceptId) === null) $this->concepts->createConcept($sense);
            $this->insertSense($entryId, $sense, $context, $semanticType, $semanticId, $semanticRevision);
            $ok = $this->database->query($this->database->prepare("UPDATE {$this->entries} SET revision=revision+1,updated_at=%s WHERE entry_uuid=%s AND revision=%d", gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($entryId), $expectedRevision));
            if ($ok !== 1) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
            $updated = $this->findById($entryId);
            if (!$updated instanceof LexicalEntry) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
            $this->database->query('COMMIT');
            return ['entry' => $updated, 'sense' => $sense];
        } catch (\Throwable $e) { $this->database->query('ROLLBACK'); throw $e; }
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

    private function hydrateEntry(array $row, ?array $senseIdsOverride = null): ?LexicalEntry
    {
        try {
            $entryId = UuidCodec::fromBinary($row['entry_uuid']);
            $senseIds = $senseIdsOverride;
            if ($senseIds === null) {
                $senseRows = $this->database->get_results($this->database->prepare("SELECT concept_uuid FROM {$this->senses} WHERE entry_uuid=%s AND state=1 ORDER BY id", UuidCodec::toBinary($entryId)), ARRAY_A) ?: [];
                $senseIds = [];
                foreach ($senseRows as $senseRow) {
                    try { $senseIds[] = UuidCodec::fromBinary($senseRow['concept_uuid']); } catch (\Throwable) { continue; }
                }
            }
            return new LexicalEntry($entryId, (string) $row['preferred_form'], (string) $row['normalized_preferred_form'], (string) $row['status'], ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, $this->decode((string) ($row['context_json'] ?? '{}')), (int) ($row['revision'] ?? 1), array_values(array_unique($senseIds)));
        } catch (\Throwable) { return null; }
    }

    /** @return array{label:string,id:int}|null */
    private function decodeArchiveCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') return null;
        $value = json_decode((string) base64_decode($cursor, true), true);
        return is_array($value) && isset($value['label'], $value['id']) ? ['label' => (string) $value['label'], 'id' => (int) $value['id']] : null;
    }

    /** @return array{normalized_form:string,entry_id:int,sense_id:int}|null */
    private function decodeDuplicateAuditCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') return null;
        if (strlen($cursor) > 4096) throw new \InvalidArgumentException('DICTIONARY_DUPLICATE_CURSOR_INVALID');
        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $payload = is_string($decoded) && $decoded !== '' ? json_decode($decoded, true) : null;
        if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['sort'] ?? null) !== 'NORMALIZED_FORM_ENTRY_SENSE_ASC_V1' || !is_string($payload['normalized_form'] ?? null) || !is_int($payload['entry_id'] ?? null) || $payload['entry_id'] < 1 || !is_int($payload['sense_id'] ?? null) || $payload['sense_id'] < 1) {
            throw new \InvalidArgumentException('DICTIONARY_DUPLICATE_CURSOR_INVALID');
        }
        return ['normalized_form' => $payload['normalized_form'], 'entry_id' => $payload['entry_id'], 'sense_id' => $payload['sense_id']];
    }

    private function encodeDuplicateAuditCursor(array $row): ?string
    {
        $entryId = (int) ($row['_entry_audit_id'] ?? 0);
        $senseId = (int) ($row['_sense_audit_id'] ?? 0);
        $normalized = (string) ($row['normalized_form'] ?? '');
        if ($entryId < 1 || $senseId < 1 || $normalized === '') return null;
        $payload = ['version' => 1, 'sort' => 'NORMALIZED_FORM_ENTRY_SENSE_ASC_V1', 'normalized_form' => $normalized, 'entry_id' => $entryId, 'sense_id' => $senseId];
        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function encodeArchiveCursor(string $label, int $id): string
    {
        return base64_encode((string) json_encode(['label' => $label, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function conceptsTable(): string
    {
        return $this->database->prefix . 'nhk_dictionary_concepts';
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
    private function boundedCuratorContext(array $context): array { $out = []; foreach ($context as $key => $value) if (is_string($key) && !str_starts_with($key, '_') && strlen($key) <= 64) $out[$key] = $value; return $out; }
    private function sort(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->sort($item); return $value; }
}
