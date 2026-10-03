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
            $row = $this->database->get_row($this->database->prepare("SELECT e.* FROM {$this->entries} e INNER JOIN {$this->senses} s ON s.entry_uuid=e.entry_uuid WHERE s.concept_uuid=%s AND s.state=1 LIMIT 1", UuidCodec::toBinary($conceptId)), ARRAY_A);
            if (is_array($row)) return $this->hydrateEntry($row);
            $concept = $this->concepts->findById($conceptId);
            if (!$concept instanceof DictionaryConcept) return null;
            return new LexicalEntry($conceptId, $concept->preferredLabel, $this->normalize($concept->preferredLabel), $concept->status, null, [], $concept->revision, [$conceptId]);
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
            if ($concept instanceof DictionaryConcept) $out[$concept->conceptId] = $concept;
        }
        return array_values($out);
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
        try { return new LexicalEntry(UuidCodec::fromBinary($row['entry_uuid']), (string) $row['preferred_form'], (string) $row['normalized_preferred_form'], (string) $row['status'], ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, $this->decode((string) ($row['context_json'] ?? '{}')), (int) ($row['revision'] ?? 1)); }
        catch (\Throwable) { return null; }
    }

    private function normalize(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); }
    private function json(array $value): string { return function_exists('wp_json_encode') ? (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); }
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }
    private function sort(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->sort($item); return $value; }
}
