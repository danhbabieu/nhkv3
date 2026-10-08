<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Contracts\Dictionary\{DictionaryCandidatePageReader, DictionaryCandidateRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbDictionaryCandidateRepository implements DictionaryCandidateRepository, DictionaryCandidatePageReader
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_dictionary_candidates';
    }

    public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate
    {
        $existing = $this->findByTermContext($candidate->normalizedTerm, $candidate->contextHash);
        if ($existing !== null) {
            if ($existing->suppressed()) return $existing;
            $rawForms = array_values(array_unique(array_filter([...$existing->rawForms, ...$candidate->rawForms], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
            $suggestions = array_values(array_unique([...$existing->suggestions, ...$candidate->suggestions], SORT_REGULAR));
            $lastSeen = trim($candidate->lastSeenAt) !== '' ? $candidate->lastSeenAt : gmdate('Y-m-d H:i:s.u');
            $ok = $this->database->query($this->database->prepare(
                "UPDATE {$this->table} SET raw_forms_json=%s,suggestions_json=%s,occurrences=occurrences+1,last_seen_at=%s,revision=revision+1 WHERE candidate_uuid=%s AND revision=%d",
                wp_json_encode($rawForms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                wp_json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $lastSeen,
                UuidCodec::toBinary($existing->candidateId),
                $existing->revision,
            ));
            if ($ok !== 1) throw new \RuntimeException('DICTIONARY_CANDIDATE_REVISION_CONFLICT');
            return $this->findById($existing->candidateId) ?? throw new \RuntimeException('DICTIONARY_CANDIDATE_READBACK_FAILED');
        }

        $firstSeen = trim($candidate->firstSeenAt) !== '' ? $candidate->firstSeenAt : gmdate('Y-m-d H:i:s.u');
        $lastSeen = trim($candidate->lastSeenAt) !== '' ? $candidate->lastSeenAt : $firstSeen;
        $ok = $this->database->query($this->database->prepare(
            "INSERT INTO {$this->table} (candidate_uuid,normalized_term,context_hash,raw_forms_json,candidate_state,context_json,suggestions_json,occurrences,revision,first_seen_at,last_seen_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s)",
            UuidCodec::toBinary($candidate->candidateId),
            $candidate->normalizedTerm,
            $candidate->contextHash,
            wp_json_encode($candidate->rawForms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $candidate->state,
            wp_json_encode($candidate->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($candidate->suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $candidate->occurrences,
            $candidate->revision,
            $firstSeen,
            $lastSeen,
        ));
        if ($ok === false) {
            $raceWinner = $this->findByTermContext($candidate->normalizedTerm, $candidate->contextHash);
            if ($raceWinner !== null) return $raceWinner->suppressed() ? $raceWinner : $this->upsertObservation($candidate);
            throw new \RuntimeException('DICTIONARY_CANDIDATE_CREATE_FAILED');
        }
        return $this->findById($candidate->candidateId) ?? $candidate;
    }

    public function suppressed(string $normalizedTerm, string $contextHash): bool
    {
        $item = $this->findByTermContext($normalizedTerm, $contextHash);
        return $item !== null && $item->state === DictionaryCandidateState::DO_NOT_SUGGEST;
    }

    public function listForReview(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $states = [DictionaryCandidateState::DETECTED, DictionaryCandidateState::NEEDS_REVIEW, DictionaryCandidateState::AMBIGUOUS, DictionaryCandidateState::PROPOSED_NEW];
        $quoted = implode(',', array_fill(0, count($states), '%s'));
        $query = $this->database->prepare("SELECT * FROM {$this->table} WHERE candidate_state IN ({$quoted}) ORDER BY occurrences DESC,last_seen_at DESC,id ASC LIMIT %d", ...[...$states, $limit]);
        $rows = $this->database->get_results($query, ARRAY_A) ?: [];
        return array_values(array_filter(array_map(fn (array $row): ?DictionaryCandidate => $this->hydrate($row), $rows)));
    }

    public function pageForReview(int $limit = 100, ?string $cursor = null, ?string $state = null): array
    {
        $limit = max(1, min(100, $limit));
        $state = $state !== null ? strtoupper(trim($state)) : null;
        if ($state !== null && !DictionaryCandidateState::valid($state)) throw new \InvalidArgumentException('DICTIONARY_CANDIDATE_STATE_INVALID');
        $reviewStates = [DictionaryCandidateState::DETECTED, DictionaryCandidateState::NEEDS_REVIEW, DictionaryCandidateState::AMBIGUOUS, DictionaryCandidateState::PROPOSED_NEW];
        $isReviewState = $state === null || in_array($state, $reviewStates, true);
        $baseWhere = $state === null ? 'candidate_state IN (' . implode(',', array_fill(0, count($reviewStates), '%s')) . ')' : 'candidate_state=%s';
        $baseArgs = $state === null ? $reviewStates : [$state];
        $decoded = $this->decodeReviewCursor($cursor, $state);
        if (!$isReviewState) return ['items' => [], 'total' => 0, 'has_more' => false, 'next_cursor' => null, 'diagnostics' => []];
        $where = $baseWhere;
        $args = $baseArgs;
        if ($decoded !== null) {
            $where .= ' AND (occurrences<%d OR (occurrences=%d AND candidate_uuid>%s))';
            array_push($args, $decoded['occurrences'], $decoded['occurrences'], UuidCodec::toBinary($decoded['candidate_id']));
        }
        $countQuery = $this->database->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$baseWhere}", ...$baseArgs);
        $total = (int) $this->database->get_var($countQuery);
        $queryArgs = [...$args, $limit + 1];
        $query = $this->database->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY occurrences DESC,candidate_uuid ASC LIMIT %d", ...$queryArgs);
        $rows = $this->database->get_results($query, ARRAY_A);
        if (!is_array($rows)) throw new \RuntimeException('DICTIONARY_CANDIDATE_PAGE_UNAVAILABLE');
        $hasMore = count($rows) > $limit;
        $pageRows = array_slice($rows, 0, $limit);
        $items = [];
        $diagnostics = [];
        foreach ($pageRows as $row) {
            $item = is_array($row) ? $this->hydrate($row) : null;
            if ($item instanceof DictionaryCandidate) {
                $items[] = $item;
                continue;
            }
            $diagnostics[] = ['code' => 'DICTIONARY_CANDIDATE_HYDRATION_FAILED'];
        }
        $last = $pageRows !== [] ? $pageRows[array_key_last($pageRows)] : null;
        $next = $hasMore && is_array($last) ? $this->reviewCursorFromRow($last, $state) : null;
        return ['items' => $items, 'total' => $total, 'has_more' => $hasMore, 'next_cursor' => $next, 'diagnostics' => $diagnostics];
    }

    public function findById(string $candidateId): ?DictionaryCandidate
    {
        try {
            $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE candidate_uuid=%s LIMIT 1", UuidCodec::toBinary($candidateId)), ARRAY_A);
            return $this->hydrate(is_array($row) ? $row : null);
        } catch (\Throwable) {
            return null;
        }
    }

    public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate
    {
        $ok = $this->database->query($this->database->prepare(
            "UPDATE {$this->table} SET raw_forms_json=%s,candidate_state=%s,context_json=%s,suggestions_json=%s,occurrences=%d,last_seen_at=%s,revision=revision+1 WHERE candidate_uuid=%s AND revision=%d",
            wp_json_encode($candidate->rawForms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $candidate->state,
            wp_json_encode($candidate->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode($candidate->suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $candidate->occurrences,
            trim($candidate->lastSeenAt) !== '' ? $candidate->lastSeenAt : gmdate('Y-m-d H:i:s.u'),
            UuidCodec::toBinary($candidate->candidateId),
            $expectedRevision,
        ));
        if ($ok !== 1) throw new \RuntimeException('DICTIONARY_CANDIDATE_REVISION_CONFLICT');
        return $this->findById($candidate->candidateId) ?? throw new \RuntimeException('DICTIONARY_CANDIDATE_READBACK_FAILED');
    }

    private function findByTermContext(string $normalizedTerm, string $contextHash): ?DictionaryCandidate
    {
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE normalized_term=%s AND context_hash=%s LIMIT 1", $normalizedTerm, $contextHash), ARRAY_A);
        return $this->hydrate(is_array($row) ? $row : null);
    }

    private function hydrate(?array $row): ?DictionaryCandidate
    {
        if ($row === null) return null;
        try {
            $raw = json_decode((string) $row['raw_forms_json'], true, 512, JSON_THROW_ON_ERROR);
            $context = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
            $suggestions = json_decode((string) $row['suggestions_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($raw) || !is_array($context) || !is_array($suggestions)) return null;
            return new DictionaryCandidate(
                UuidCodec::fromBinary($row['candidate_uuid']),
                (string) $row['normalized_term'],
                (string) $row['context_hash'],
                $raw,
                (string) $row['candidate_state'],
                $context,
                $suggestions,
                (int) $row['occurrences'],
                (string) $row['first_seen_at'],
                (string) $row['last_seen_at'],
                (int) $row['revision'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{version:int,sort:string,state:?string,occurrences:int,candidate_id:string}|null */
    private function decodeReviewCursor(?string $cursor, ?string $state): ?array
    {
        if ($cursor === null || trim($cursor) === '') return null;
        if (strlen($cursor) > 4096) throw new \InvalidArgumentException('DICTIONARY_CANDIDATE_CURSOR_INVALID');
        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $payload = is_string($decoded) && $decoded !== '' ? json_decode($decoded, true) : null;
        if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['sort'] ?? null) !== 'OCCURRENCES_DESC_UUID_ASC_V1' || ($payload['state'] ?? null) !== $state || !is_int($payload['occurrences'] ?? null) || $payload['occurrences'] < 1 || !is_string($payload['candidate_id'] ?? null) || !UuidCodec::isValid($payload['candidate_id'])) {
            throw new \InvalidArgumentException(($payload['state'] ?? null) !== $state ? 'DICTIONARY_CANDIDATE_CURSOR_FILTER_MISMATCH' : 'DICTIONARY_CANDIDATE_CURSOR_INVALID');
        }
        return ['version' => 1, 'sort' => 'OCCURRENCES_DESC_UUID_ASC_V1', 'state' => $state, 'occurrences' => $payload['occurrences'], 'candidate_id' => $payload['candidate_id']];
    }

    private function reviewCursorFromRow(array $row, ?string $state): ?string
    {
        try {
            $candidateId = UuidCodec::fromBinary((string) ($row['candidate_uuid'] ?? ''));
            $json = function_exists('wp_json_encode') ? wp_json_encode(['version' => 1, 'sort' => 'OCCURRENCES_DESC_UUID_ASC_V1', 'state' => $state, 'occurrences' => (int) ($row['occurrences'] ?? 0), 'candidate_id' => $candidateId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode(['version' => 1, 'sort' => 'OCCURRENCES_DESC_UUID_ASC_V1', 'state' => $state, 'occurrences' => (int) ($row['occurrences'] ?? 0), 'candidate_id' => $candidateId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
        } catch (\Throwable) {
            return null;
        }
    }
}
