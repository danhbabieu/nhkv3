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
        $appliedCount = 0;
        $readyCount = 0;
        $noopCount = 0;
        $skippedReviewRequiredCount = 0;
        $skippedBlockedCount = 0;
        $failedCount = 0;
        foreach ($actions as $index => $action) {
            $actionStatus = strtoupper((string) ($action['status'] ?? ''));
            if ($actionStatus !== 'READY') {
                $receiptStatus = match ($actionStatus) {
                    'NOOP' => 'noop',
                    'REVIEW_REQUIRED' => 'skipped_review_required',
                    'BLOCKED' => 'skipped_blocked',
                    default => 'skipped',
                };
                if ($receiptStatus === 'noop') $noopCount++;
                if ($receiptStatus === 'skipped_review_required') $skippedReviewRequiredCount++;
                if ($receiptStatus === 'skipped_blocked') $skippedBlockedCount++;
                $items[] = [
                    'status' => $receiptStatus,
                    'action_index' => $index,
                    'action_type' => (string) ($action['action_type'] ?? ''),
                    'idempotency_key' => $idempotencyKey . ':' . $index,
                ];
                continue;
            }
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
                $appliedCount++;
                $items[] = $result + ['status' => 'applied', 'action_index' => $index, 'idempotency_key' => $key];
                if (isset($result['entry_revision']) && is_int($result['entry_revision'])) $entryRevisions[$entryId] = $result['entry_revision'];
                elseif (is_callable($this->readRevision)) $entryRevisions[$entryId] = (int) ($this->readRevision)($entryId);
            } catch (\Throwable $e) {
                $hadPriorItems = $items !== [];
                $failedCount++;
                $items[] = [
                    'status' => 'failed',
                    'action_index' => $index,
                    'action_type' => (string) ($action['action_type'] ?? ''),
                    'idempotency_key' => $key,
                    'error' => ['code' => $e->getMessage()],
                ];
                return [
                    'status' => $hadPriorItems ? 'partial' : 'blocked',
                    'applied_count' => $appliedCount,
                    'ready_count' => $readyCount,
                    'noop_count' => $noopCount,
                    'skipped_review_required_count' => $skippedReviewRequiredCount,
                    'skipped_blocked_count' => $skippedBlockedCount,
                    'failed_count' => $failedCount,
                    'items' => $items,
                    'entry_revisions' => $entryRevisions,
                    'error' => ['code' => $e->getMessage(), 'action_index' => $index, 'idempotency_key' => $key],
                ];
            }
        }
        $status = $appliedCount === 0 ? 'noop' : (($skippedReviewRequiredCount + $skippedBlockedCount) > 0 ? 'partial' : 'applied');
        return [
            'status' => $status,
            'applied_count' => $appliedCount,
            'ready_count' => $readyCount,
            'noop_count' => $noopCount,
            'skipped_review_required_count' => $skippedReviewRequiredCount,
            'skipped_blocked_count' => $skippedBlockedCount,
            'failed_count' => $failedCount,
            'items' => $items,
            'entry_revisions' => $entryRevisions,
        ];
    }
}
