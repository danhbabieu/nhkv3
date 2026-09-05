<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDictionaryConceptRepository implements DictionaryConceptRepository
{
    private string $concepts;
    private string $labels;

    public function __construct(private object $database)
    {
        $this->concepts = $database->prefix . 'nhk_dictionary_concepts';
        $this->labels = $database->prefix . 'nhk_dictionary_labels';
    }

    public function findById(string $conceptId): ?DictionaryConcept
    {
        try {
            $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->concepts} WHERE concept_uuid=%s LIMIT 1", UuidCodec::toBinary($conceptId)), ARRAY_A);
            return $this->hydrateConcept(is_array($row) ? $row : null);
        } catch (\Throwable) {
            return null;
        }
    }

    public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array
    {
        $hashes = array_values(array_unique([$this->contextHash($context), $this->contextHash([])]));
        $out = [];
        foreach ($hashes as $hash) {
            $rows = $this->database->get_results($this->database->prepare(
                "SELECT c.*,l.label_text,l.label_kind,l.locale,l.context_json AS label_context_json FROM {$this->labels} l INNER JOIN {$this->concepts} c ON c.concept_uuid=l.concept_uuid WHERE l.normalized_label=%s AND l.context_hash=%s AND l.state=1 AND c.status=%s ORDER BY c.id,l.id",
                $normalizedLabel,
                $hash,
                DictionaryConcept::APPROVED,
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $concept = $this->hydrateConcept($row);
                if ($concept === null) continue;
                $out[$concept->conceptId] = [
                    'concept_id' => $concept->conceptId,
                    'preferred_label' => $concept->preferredLabel,
                    'definition' => $concept->definition,
                    'destination_type' => $concept->destinationType,
                    'destination_id' => $concept->destinationId,
                    'destination_url' => $concept->destinationUrl,
                    'label' => (string) ($row['label_text'] ?? ''),
                    'label_kind' => (string) ($row['label_kind'] ?? ''),
                    'locale' => ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null,
                    'context' => $this->decode((string) ($row['label_context_json'] ?? '{}')),
                ];
            }
            if ($out !== [] && $hash !== $this->contextHash([])) break;
        }
        return array_values($out);
    }

    public function listApproved(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->concepts} WHERE status=%s ORDER BY preferred_label,id LIMIT %d", DictionaryConcept::APPROVED, $limit), ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?DictionaryConcept => $this->hydrateConcept($row), $rows)));
    }

    public function listLabels(string $conceptId, bool $includeInactive = false): array
    {
        try { $uuid = UuidCodec::toBinary($conceptId); } catch (\Throwable) { return []; }
        $sql = "SELECT * FROM {$this->labels} WHERE concept_uuid=%s" . ($includeInactive ? '' : ' AND state=1') . ' ORDER BY id';
        $rows = $this->database->get_results($this->database->prepare($sql, $uuid), ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?DictionaryLabel => $this->hydrateLabel($row, $conceptId), $rows)));
    }

    public function createConcept(DictionaryConcept $concept): DictionaryConcept
    {
        $existing = $this->findById($concept->conceptId);
        if ($existing !== null) return $existing;
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->database->query($this->database->prepare(
            "INSERT INTO {$this->concepts} (concept_uuid,preferred_label,definition_text,status,destination_type,destination_id,destination_url,context_json,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s)",
            UuidCodec::toBinary($concept->conceptId),
            $concept->preferredLabel,
            $concept->definition,
            $concept->status,
            $concept->destinationType,
            $concept->destinationId,
            $concept->destinationUrl,
            wp_json_encode($concept->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $concept->revision,
            $now,
            $now,
        ));
        if ($ok === false) throw new \RuntimeException('DICTIONARY_CONCEPT_CREATE_FAILED');
        return $this->findById($concept->conceptId) ?? $concept;
    }

    public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept
    {
        $ok = $this->database->query($this->database->prepare(
            "UPDATE {$this->concepts} SET preferred_label=%s,definition_text=%s,status=%s,destination_type=%s,destination_id=%s,destination_url=%s,context_json=%s,revision=revision+1,updated_at=%s WHERE concept_uuid=%s AND revision=%d",
            $concept->preferredLabel,
            $concept->definition,
            $concept->status,
            $concept->destinationType,
            $concept->destinationId,
            $concept->destinationUrl,
            wp_json_encode($concept->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            gmdate('Y-m-d H:i:s.u'),
            UuidCodec::toBinary($concept->conceptId),
            $expectedRevision,
        ));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_CONCEPT_REVISION_CONFLICT');
        return $this->findById($concept->conceptId) ?? throw new \RuntimeException('DICTIONARY_CONCEPT_READBACK_FAILED');
    }

    public function addLabel(DictionaryLabel $label): DictionaryLabel
    {
        $hash = $this->contextHash($label->context);
        $now = gmdate('Y-m-d H:i:s.u');
        $existing = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->labels} WHERE concept_uuid=%s AND normalized_label=%s AND context_hash=%s LIMIT 1", UuidCodec::toBinary($label->conceptId), $label->normalizedLabel, $hash), ARRAY_A);
        if (is_array($existing)) {
            $hydrated = $this->hydrateLabel($existing, $label->conceptId);
            if ($hydrated !== null) return $hydrated;
        }
        $ok = $this->database->query($this->database->prepare(
            "INSERT INTO {$this->labels} (concept_uuid,label_text,normalized_label,label_kind,locale,context_hash,context_json,state,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%s,%s)",
            UuidCodec::toBinary($label->conceptId),
            $label->label,
            $label->normalizedLabel,
            $label->kind,
            $label->locale,
            $hash,
            wp_json_encode($label->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $label->active ? 1 : 0,
            $now,
            $now,
        ));
        if ($ok === false) throw new \RuntimeException('DICTIONARY_LABEL_CREATE_FAILED');
        return $label;
    }

    private function hydrateConcept(?array $row): ?DictionaryConcept
    {
        if ($row === null) return null;
        try {
            return new DictionaryConcept(
                UuidCodec::fromBinary($row['concept_uuid']),
                (string) $row['preferred_label'],
                (string) $row['definition_text'],
                (string) $row['status'],
                ($row['destination_type'] ?? null) !== null ? (string) $row['destination_type'] : null,
                ($row['destination_id'] ?? null) !== null ? (string) $row['destination_id'] : null,
                ($row['destination_url'] ?? null) !== null ? (string) $row['destination_url'] : null,
                $this->decode((string) ($row['context_json'] ?? '{}')),
                (int) $row['revision'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function hydrateLabel(array $row, string $conceptId): ?DictionaryLabel
    {
        try {
            return new DictionaryLabel($conceptId, (string) $row['label_text'], (string) $row['normalized_label'], (string) $row['label_kind'], ($row['locale'] ?? null) !== null ? (string) $row['locale'] : null, $this->decode((string) ($row['context_json'] ?? '{}')), (int) ($row['state'] ?? 0) === 1);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decode(string $json): array
    {
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (\Throwable) { return []; }
    }

    private function contextHash(array $context): string
    {
        return hash('sha256', json_encode($this->sort($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
