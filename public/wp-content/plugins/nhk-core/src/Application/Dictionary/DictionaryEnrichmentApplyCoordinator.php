<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/**
 * Applies one exact plan sequentially while keeping each action bound to the
 * canonical Entry revision. Receipts make a retry resume safely after a
 * transport or action failure; the plan fingerprint and action order never
 * change during resume.
 */
final class DictionaryEnrichmentApplyCoordinator
{
    public function __construct(
        private $applyAction,
        private $readReceipt,
        private $writeReceipt,
        private $readRevision,
    ) {}

    /** @param list<array<string,mixed>> $actions */
    public function apply(array $actions, string $idempotencyKey): array
    {
        $items = [];
        $entryRevisions = [];
        $readyCount = 0;
        foreach ($actions as $index => $action) {
            if (($action['status'] ?? '') !== 'READY') continue;
            $readyCount++;
            $entryId = trim((string) ($action['entry_id'] ?? ''));
            $key = $idempotencyKey . ':' . $index;
            try {
                $receipt = is_callable($this->readReceipt) ? ($this->readReceipt)($key) : null;
                if (is_array($receipt)) {
                    $result = $receipt;
                } else {
                    $expected = $entryRevisions[$entryId] ?? (is_callable($this->readRevision) ? ($this->readRevision)($entryId) : null);
                    if (!is_int($expected)) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_UNAVAILABLE');
                    if ($expected !== (int) ($action['current_revision'] ?? 0) && !array_key_exists($entryId, $entryRevisions)) {
                        throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
                    }
                    $result = ($this->applyAction)($action, $expected, $key);
                    if (!is_array($result)) throw new \RuntimeException('DICTIONARY_ENTRY_READBACK_FAILED');
                    if (is_callable($this->writeReceipt)) ($this->writeReceipt)($key, $result);
                }
                $items[] = $result + ['action_index' => $index, 'idempotency_key' => $key];
                if (isset($result['entry_revision']) && is_int($result['entry_revision'])) $entryRevisions[$entryId] = $result['entry_revision'];
                elseif (is_callable($this->readRevision)) $entryRevisions[$entryId] = (int) ($this->readRevision)($entryId);
            } catch (\Throwable $e) {
                return [
                    'status' => $items === [] ? 'blocked' : 'partial',
                    'applied_count' => count($items),
                    'ready_count' => $readyCount,
                    'items' => $items,
                    'entry_revisions' => $entryRevisions,
                    'error' => ['code' => $e->getMessage(), 'action_index' => $index, 'idempotency_key' => $key],
                ];
            }
        }
        return ['status' => 'applied', 'applied_count' => count($items), 'ready_count' => $readyCount, 'items' => $items, 'entry_revisions' => $entryRevisions];
    }
}
