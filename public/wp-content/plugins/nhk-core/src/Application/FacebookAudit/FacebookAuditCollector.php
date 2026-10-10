<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use NHK\Core\Contracts\FacebookAudit\{FacebookAuditCheckpointStore, FacebookAuditReadAdapter};
use NHK\Core\Domain\FacebookAudit\{FacebookAuditScope, ReadPage};

final class FacebookAuditCollector
{
    public function __construct(
        private FacebookAuditReadAdapter $adapter,
        private FacebookAuditNormalizer $normalizer,
        private ?FacebookAuditCheckpointStore $checkpointStore = null,
        private int $maxAttempts = 3,
        private int $pageSize = 50,
    ) {}

    public function collect(FacebookAuditScope $scope): FacebookAuditCollectionResult
    {
        $scope->assertCollectionReady();
        $checkpoint = $this->checkpointStore?->load($scope) ?? FacebookAuditCheckpoint::initial($scope);
        [$pagePosts, $checkpoint, $pageBlockers] = $this->collectSource($scope, 'PAGE', $checkpoint, $checkpoint->pageCursor, $checkpoint->pageRowKeys);
        [$groupPosts, $checkpoint, $groupBlockers] = $this->collectSource($scope, 'GROUP', $checkpoint, $checkpoint->groupCursor, $checkpoint->groupRowKeys);
        $blockers = array_values(array_unique([...$pageBlockers, ...$groupBlockers]));
        return new FacebookAuditCollectionResult($pagePosts, $groupPosts, $blockers === [] ? 'COMPLETE' : 'BLOCKED', $checkpoint, $blockers);
    }

    /** @return array{0:list<array<string,mixed>>,1:FacebookAuditCheckpoint,2:list<string>} */
    private function collectSource(FacebookAuditScope $scope, string $source, FacebookAuditCheckpoint $checkpoint, ?string $cursor, array $rowKeys): array
    {
        $rows = [];
        $known = array_fill_keys($rowKeys, true);
        $seenRows = [];
        $seenCursors = [];
        $blockers = [];
        do {
            $cursorKey = $cursor ?? '__START__';
            if (isset($seenCursors[$cursorKey])) { $blockers[] = $source . '_REPEATED_CURSOR'; break; }
            $seenCursors[$cursorKey] = true;
            $page = $this->readWithRetry($scope, $source, $cursor);
            if ($page->status !== 'OK') { $blockers[] = $source . '_' . ($page->reason ?? strtolower($page->status)); break; }
            foreach ($page->rows as $raw) {
                $row = $this->normalizer->post($raw, $source, $scope->verifiedPageId);
                $key = trim((string) ($row['post_id'] ?? ''));
                if ($key === '' || isset($seenRows[$key])) continue;
                $seenRows[$key] = true;
                if (!isset($known[$key])) { $known[$key] = true; $rowKeys[] = $key; }
                $rows[] = $row;
            }
            $cursor = $page->nextCursor;
            $checkpoint = $source === 'PAGE' ? $checkpoint->withPage($cursor, $rowKeys) : $checkpoint->withGroup($cursor, $rowKeys);
            $this->checkpointStore?->save($checkpoint);
        } while ($cursor !== null);
        return [$rows, $checkpoint, $blockers];
    }

    private function readWithRetry(FacebookAuditScope $scope, string $source, ?string $cursor): ReadPage
    {
        $attempts = 0;
        do {
            $attempts++;
            $page = $source === 'PAGE' ? $this->adapter->pagePosts($scope, $cursor, $this->pageSize) : $this->adapter->groupPosts($scope, $cursor, $this->pageSize);
            if ($page->status === 'OK' || !$page->retryable || $attempts >= max(1, $this->maxAttempts)) return $page;
        } while (true);
    }
}
