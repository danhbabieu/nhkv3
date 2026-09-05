<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryMentionRepository;
use NHK\Core\Domain\Dictionary\DictionaryMention;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDictionaryMentionRepository implements DictionaryMentionRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_dictionary_mentions';
    }

    public function upsert(DictionaryMention $mention): DictionaryMention
    {
        $existing = $this->findByFingerprint($mention->fingerprint);
        if ($existing !== null) return $existing;
        $createdAt = trim($mention->createdAt) !== '' ? $mention->createdAt : gmdate('Y-m-d H:i:s.u');
        $concept = $mention->conceptId !== null && trim($mention->conceptId) !== '' ? UuidCodec::toBinary($mention->conceptId) : null;
        $ok = $this->database->query($this->database->prepare(
            "INSERT INTO {$this->table} (mention_uuid,fingerprint,source_kind,source_id,normalized_term,context_hash,concept_uuid,context_json,strength,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            UuidCodec::toBinary($mention->mentionId),
            $mention->fingerprint,
            $mention->sourceKind,
            $mention->sourceId,
            $mention->normalizedTerm,
            $mention->contextHash,
            $concept,
            wp_json_encode($mention->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $mention->strength,
            $createdAt,
        ));
        if ($ok === false) return $this->findByFingerprint($mention->fingerprint) ?? throw new \RuntimeException('DICTIONARY_MENTION_CREATE_FAILED');
        return $this->findByFingerprint($mention->fingerprint) ?? $mention;
    }

    public function listBySource(string $sourceKind, string $sourceId): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE source_kind=%s AND source_id=%s ORDER BY id", strtoupper(trim($sourceKind)), trim($sourceId)), ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?DictionaryMention => $this->hydrate($row), $rows)));
    }

    private function findByFingerprint(string $fingerprint): ?DictionaryMention
    {
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE fingerprint=%s LIMIT 1", $fingerprint), ARRAY_A);
        return $this->hydrate(is_array($row) ? $row : null);
    }

    private function hydrate(?array $row): ?DictionaryMention
    {
        if ($row === null) return null;
        try {
            $context = json_decode((string) ($row['context_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($context)) $context = [];
            $concept = ($row['concept_uuid'] ?? null) !== null ? UuidCodec::fromBinary($row['concept_uuid']) : null;
            return new DictionaryMention(
                UuidCodec::fromBinary($row['mention_uuid']),
                (string) $row['fingerprint'],
                (string) $row['source_kind'],
                (string) $row['source_id'],
                (string) $row['normalized_term'],
                (string) $row['context_hash'],
                $concept,
                $context,
                (string) $row['strength'],
                (string) $row['created_at'],
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
