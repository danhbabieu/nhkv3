<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Classifies an existing Capture continuation by the new request's delta. */
final class CaptureAuthorityContinuationPolicy
{
    public const EDITORIAL_CONTINUATION = 'EDITORIAL_CONTINUATION';
    public const AUTHORITY_MUTATION = 'AUTHORITY_MUTATION';
    public const AUTHORITY_REPLAY = 'AUTHORITY_REPLAY';

    /** @param array<string,mixed> $input */
    public static function classify(array $input, ?CaptureRecord $capture = null): string
    {
        $hasCapture = $capture instanceof CaptureRecord || trim((string) ($input['capture_id'] ?? '')) !== '';
        $intent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        $mode = strtoupper(trim((string) ($intent['mode'] ?? '')));

        if (CapturePurposePolicy::isRelationshipOnly($input)
            || (array) ($intent['requests'] ?? []) !== []
            || (array) ($intent['relation_intents'] ?? []) !== []
            || (array) ($intent['relations'] ?? []) !== []) {
            return self::AUTHORITY_MUTATION;
        }

        if ($mode === 'PLAN') return self::AUTHORITY_MUTATION;
        if ($mode === 'APPLY_APPROVED_PLAN') {
            if ($capture instanceof CaptureRecord && self::matchesAppliedPlan($capture, $intent)) return self::AUTHORITY_REPLAY;
            return self::AUTHORITY_MUTATION;
        }

        $purpose = strtoupper(trim((string) ($input['purpose'] ?? '')));
        if (!$hasCapture) return in_array($purpose, ['AUTHORITY', 'MIXED'], true) ? self::AUTHORITY_MUTATION : self::EDITORIAL_CONTINUATION;
        if ($purpose === 'AUTHORITY') return self::AUTHORITY_MUTATION;

        // MIXED is the historical purpose of a Capture that owns both the
        // Authority planning phase and its Article. Once the Authority phase
        // is complete, the absence of a new Authority delta means the
        // request is an editorial continuation, even when purpose is echoed.
        return self::EDITORIAL_CONTINUATION;
    }

    /** @param array<string,mixed> $intent */
    private static function matchesAppliedPlan(CaptureRecord $capture, array $intent): bool
    {
        $previous = is_array($capture->context['authority_result'] ?? null) ? $capture->context['authority_result'] : [];
        $fingerprint = trim((string) ($intent['approved_plan_fingerprint'] ?? ''));
        $ids = self::normalizeIds((array) ($intent['approved_candidate_ids'] ?? []));
        $previousFingerprint = trim((string) ($previous['approved_plan_fingerprint'] ?? ''));
        $previousIds = self::normalizeIds((array) ($previous['approved_candidate_ids'] ?? []));
        return $fingerprint !== ''
            && $previousFingerprint !== ''
            && hash_equals($previousFingerprint, $fingerprint)
            && $ids === $previousIds
            && strtoupper(trim((string) ($previous['result']['status'] ?? ''))) === 'APPLIED';
    }

    /** @param list<mixed> $ids @return list<string> */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn (string $id): bool => trim($id) !== '')));
        sort($ids, SORT_STRING);
        return $ids;
    }
}
