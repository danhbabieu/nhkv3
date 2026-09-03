<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Authority;

use NHK\Core\Contracts\Authority\SemanticMergeReceiptRepository;
use NHK\Core\Domain\Authority\SemanticMergeReceipt;

/** Durable append-only receipts in the existing Governance audit store. */
final class WpdbSemanticMergeReceiptRepository implements SemanticMergeReceiptRepository
{
    public function __construct(private ?object $database = null) {}

    private function db(): object { global $wpdb; return $this->database ?? $wpdb; }

    public function findByIdempotencyKey(string $key): ?SemanticMergeReceipt
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(
            'SELECT context_json FROM ' . $db->prefix . 'nhk_audit_events WHERE event_type=%s AND object_type=%s AND object_key=%s ORDER BY id DESC LIMIT 1',
            'SemanticMergeReceipt', 'semantic_merge', $key
        ), defined('ARRAY_A') ? ARRAY_A : 1);
        if (!is_array($row)) return null;
        $data = json_decode((string) ($row['context_json'] ?? ''), true);
        return is_array($data) ? $this->hydrate($data) : null;
    }

    public function append(SemanticMergeReceipt $receipt): void
    {
        (new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($this->database))->recordEvent(
            'SemanticMergeReceipt', 'semantic_merge', $receipt->idempotencyKey, null, $receipt->toArray()
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrate(array $data): ?SemanticMergeReceipt
    {
        try {
            return new SemanticMergeReceipt(
                (string) $data['sourceUuid'], (string) $data['targetUuid'], (int) $data['sourceRevision'], (int) $data['targetRevision'],
                (string) $data['planFingerprint'], is_array($data['references'] ?? null) ? $data['references'] : [],
                (int) ($data['moved'] ?? 0), (int) ($data['deduped'] ?? 0), (int) ($data['remaining'] ?? 0),
                (string) ($data['sourceLifecycle'] ?? ''), (bool) ($data['readBackVerified'] ?? false),
                (string) ($data['operation'] ?? 'merge'), (string) ($data['status'] ?? 'completed'),
                (int) ($data['referencesDiscovered'] ?? count($data['references'] ?? [])), (int) ($data['referencesMoved'] ?? ($data['moved'] ?? 0)),
                (int) ($data['referencesDeduped'] ?? ($data['deduped'] ?? 0)), (int) ($data['referencesRemaining'] ?? ($data['remaining'] ?? 0)),
                (string) ($data['applyAttemptId'] ?? ''), isset($data['createdAt']) ? (string) $data['createdAt'] : null,
                isset($data['updatedAt']) ? (string) $data['updatedAt'] : null, (string) ($data['idempotencyKey'] ?? '')
            );
        } catch (\Throwable) { return null; }
    }
}
