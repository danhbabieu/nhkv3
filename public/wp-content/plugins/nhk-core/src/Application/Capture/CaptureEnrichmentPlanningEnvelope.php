<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

/**
 * Capture-scoped planning envelope. It aggregates owner plans and receipts;
 * it is not a semantic owner and is safe to persist in Capture context JSON.
 */
final readonly class CaptureEnrichmentPlanningEnvelope
{
    public const VERSION = 'capture-enrichment-envelope-1';

    private function __construct(
        public string $version,
        public string $captureId,
        public string $requestFingerprint,
        public array $sourceManifest,
        public string $interpretationFingerprint,
        public array $ownerTracks,
        public array $selectedCandidates,
        public array $dependencyClosure,
        public array $casBindings,
        public array $reviewDecisions,
        public array $blockers,
        public array $diagnostics,
        public array $applyReceipts,
        public array $readbackReceipts,
        public string $completionState,
    ) {
    }

    public static function fromArray(array $value): self
    {
        $captureId = trim((string) ($value['capture_id'] ?? ''));
        $requestFingerprint = trim((string) ($value['request_fingerprint'] ?? ''));
        if ($captureId === '' || !preg_match('/^[a-f0-9]{64}$/i', $requestFingerprint)) {
            throw new \InvalidArgumentException('Capture enrichment envelope identity is invalid.');
        }
        $tracks = [];
        foreach ((array) ($value['owner_tracks'] ?? []) as $owner => $track) {
            if (!is_array($track)) continue;
            $tracks[strtolower(trim((string) $owner))] = $track;
        }
        ksort($tracks, SORT_STRING);
        return new self(
            self::VERSION,
            $captureId,
            $requestFingerprint,
            array_values(array_filter((array) ($value['source_manifest'] ?? []), 'is_array')),
            trim((string) ($value['interpretation_fingerprint'] ?? '')),
            $tracks,
            array_values(array_filter((array) ($value['selected_candidates'] ?? []), 'is_array')),
            self::normalizeMap($value['dependency_closure'] ?? []),
            self::normalizeMap($value['cas_bindings'] ?? self::casBindingsFromTracks($tracks)),
            self::normalizeMap($value['review_decisions'] ?? []),
            array_values(array_unique(array_map('strval', (array) ($value['blockers'] ?? [])))),
            is_array($value['diagnostics'] ?? null) ? $value['diagnostics'] : [],
            self::normalizeMap($value['apply_receipts'] ?? []),
            self::normalizeMap($value['readback_receipts'] ?? []),
            strtoupper(trim((string) ($value['completion_state'] ?? 'INCOMPLETE'))) ?: 'INCOMPLETE',
        );
    }

    /** Build the aggregate from existing Capture diagnostics without copying owner payloads. */
    public static function fromState(
        string $captureId,
        string $requestFingerprint,
        array $input,
        array $interpretation,
        array $assets,
        array $diagnostics,
        array $phaseReceipts = [],
    ): self {
        $intent = is_array($diagnostics['content_intent'] ?? null) ? $diagnostics['content_intent'] : (is_array($input['content_intent'] ?? null) ? $input['content_intent'] : []);
        $preparation = is_array($diagnostics['content_preparation'] ?? null) ? $diagnostics['content_preparation'] : [];
        $resolution = is_array($diagnostics['subjects'] ?? null) ? $diagnostics['subjects'] : [];
        $hasVideo = strtoupper(trim((string) ($intent['intent'] ?? $input['intent'] ?? ''))) === 'VIDEO'
            || array_reduce($assets, static fn (bool $found, mixed $asset): bool => $found || (is_array($asset) && strtolower((string) ($asset['kind'] ?? '')) === 'video'), false);
        $hasMedia = $assets !== [] || isset($diagnostics['media_enrichment'], $diagnostics['media_usage']);
        $tracks = [
            'lexical' => self::track($diagnostics['dictionary_observation'] ?? $diagnostics['dictionary'] ?? [], []),
            'authority' => self::authorityTrack($resolution, []),
            'source_evidence' => self::track($diagnostics['source_evidence'] ?? [], ['authority']),
            'knowledge' => self::track($diagnostics['semantic_write_back'] ?? [], ['authority', 'source_evidence']),
            'relations' => self::track($diagnostics['relations'] ?? [], ['authority', 'knowledge']),
            'media' => self::track($diagnostics['media_adoption'] ?? [], []),
            'media_usage' => self::track($diagnostics['media_enrichment'] ?? $diagnostics['media_usage'] ?? [], ['media']),
            'video' => self::track($diagnostics['video_publication'] ?? [], $hasVideo ? ['media'] : []),
            'article' => self::track($diagnostics['final_read_back'] ?? $diagnostics['draft'] ?? [], $hasMedia ? ['media_usage', 'knowledge'] : ['knowledge']),
        ];
        foreach ($tracks as $owner => &$track) {
            if ($owner === 'lexical' && !array_key_exists('dictionary_observation', $diagnostics) && !array_key_exists('dictionary', $diagnostics)) $track['status'] = 'NOT_APPLICABLE';
            if ($owner === 'media' && !$hasMedia) $track['status'] = 'NOT_APPLICABLE';
            if ($owner === 'media_usage' && !$hasMedia) $track['status'] = 'NOT_APPLICABLE';
            if ($owner === 'video' && !$hasVideo) $track['status'] = 'NOT_APPLICABLE';
            if ($owner === 'article' && !in_array(strtoupper(trim((string) ($intent['intent'] ?? ''))), ['TEXT_ARTICLE', 'IMAGE_ARTICLE'], true)) $track['status'] = 'NOT_APPLICABLE';
        }
        unset($track);
        $preCreateResolution = $diagnostics['dictionary_pre_create_resolution'] ?? null;
        if (is_array($preCreateResolution)) {
            $tracks['lexical']['pre_create_resolution'] = $preCreateResolution;
            $tracks['lexical']['request_fingerprint'] = $requestFingerprint;
            $tracks['lexical']['expected_revision'] = (int) ($preCreateResolution['expected_revision'] ?? $tracks['lexical']['expected_revision'] ?? 0);
            $tracks['lexical']['dependency_revisions'] = (array) ($preCreateResolution['dependency_revisions'] ?? $tracks['lexical']['dependency_revisions'] ?? []);
        }
        $completion = is_array($diagnostics['completion'] ?? null) ? $diagnostics['completion'] : [];
        return self::fromArray([
            'capture_id' => $captureId,
            'request_fingerprint' => $requestFingerprint,
            'source_manifest' => array_values(array_filter($assets, 'is_array')),
            'interpretation_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize($interpretation)),
            'owner_tracks' => $tracks,
            'selected_candidates' => array_values(array_filter((array) ($preparation['candidates'] ?? []), 'is_array')),
            'dependency_closure' => array_map(static fn (string $owner): array => ['owner' => $owner, 'depends_on' => (array) ($tracks[$owner]['depends_on'] ?? [])], array_keys($tracks)),
            'cas_bindings' => array_map(static fn (array $track): array => [
                'plan_fingerprint' => (string) ($track['plan_fingerprint'] ?? ''),
                'expected_revision' => (int) ($track['expected_revision'] ?? 0),
                'dependency_revisions' => (array) ($track['dependency_revisions'] ?? []),
            ], $tracks),
            'review_decisions' => $input['review_decisions'] ?? [],
            'blockers' => (array) ($completion['blockers'] ?? []),
            'diagnostics' => ['phase_receipts' => $phaseReceipts],
            'apply_receipts' => self::receiptMap($phaseReceipts, ['APPLIED', 'COMPLETED', 'VERIFIED']),
            'readback_receipts' => self::receiptMap($phaseReceipts, ['VERIFIED', 'COMPLETED']),
            'completion_state' => ($completion['complete'] ?? false) === true ? 'COMPLETE' : 'INCOMPLETE',
        ]);
    }

    public function fingerprint(): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize($this->mutationPacket()));
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'capture_id' => $this->captureId,
            'request_fingerprint' => $this->requestFingerprint,
            'source_manifest' => $this->sourceManifest,
            'interpretation_fingerprint' => $this->interpretationFingerprint,
            'owner_tracks' => $this->ownerTracks,
            'selected_candidates' => $this->selectedCandidates,
            'dependency_closure' => $this->dependencyClosure,
            'cas_bindings' => $this->casBindings,
            'review_decisions' => $this->reviewDecisions,
            'blockers' => $this->blockers,
            'diagnostics' => $this->diagnostics,
            'apply_receipts' => $this->applyReceipts,
            'readback_receipts' => $this->readbackReceipts,
            'completion_state' => $this->completionState,
            'envelope_fingerprint' => $this->fingerprint(),
        ];
    }

    private function mutationPacket(): array
    {
        return [
            'version' => $this->version,
            'capture_id' => $this->captureId,
            'request_fingerprint' => $this->requestFingerprint,
            'source_manifest' => $this->sourceManifest,
            'interpretation_fingerprint' => $this->interpretationFingerprint,
            'owner_tracks' => $this->ownerTracks,
            'selected_candidates' => $this->selectedCandidates,
            'dependency_closure' => $this->dependencyClosure,
            'cas_bindings' => $this->casBindings,
            'review_decisions' => $this->reviewDecisions,
        ];
    }

    private static function normalizeMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function casBindingsFromTracks(array $tracks): array
    {
        $bindings = [];
        foreach ($tracks as $owner => $track) {
            if (!is_array($track)) continue;
            $bindings[(string) $owner] = [
                'plan_fingerprint' => (string) ($track['plan_fingerprint'] ?? ''),
                'expected_revision' => (int) ($track['expected_revision'] ?? 0),
                'dependency_revisions' => (array) ($track['dependency_revisions'] ?? []),
            ];
        }
        return $bindings;
    }

    private static function track(mixed $result, array $dependsOn): array
    {
        $result = is_array($result) ? $result : [];
        if ($result === []) return ['status' => 'NOT_APPLICABLE', 'depends_on' => $dependsOn, 'plan_fingerprint' => '', 'expected_revision' => 0, 'dependency_revisions' => [], 'canonical_readback' => [], 'diagnostics' => []];
        $outcome = CaptureOwnerOutcome::fromDomainResult('', $result);
        return [
            'status' => $outcome->status,
            'depends_on' => $dependsOn,
            'plan_fingerprint' => (string) ($result['plan_fingerprint'] ?? ''),
            'expected_revision' => (int) ($result['expected_revision'] ?? $result['revision'] ?? 0),
            'dependency_revisions' => $outcome->dependencyRevisions,
            'canonical_readback' => $outcome->canonicalReadback,
            'diagnostics' => $outcome->diagnostics,
        ];
    }

    private static function authorityTrack(mixed $result, array $dependsOn): array
    {
        $result = is_array($result) ? $result : [];
        $primary = is_array($result['primary'] ?? null) ? $result['primary'] : [];
        if (strtolower(trim((string) ($result['status'] ?? ''))) === 'resolved'
            && trim((string) ($primary['id'] ?? '')) !== '') {
            $result['orchestration_status'] = 'READ_BACK_VERIFIED';
            $result['canonical_readback'] = [
                'canonical_id' => (string) $primary['id'],
                'revision' => (int) ($primary['revision'] ?? 0),
            ];
        }
        return self::track($result, $dependsOn);
    }

    private static function receiptMap(array $receipts, array $statuses): array
    {
        $result = [];
        foreach ($receipts as $phase => $receipt) {
            if (!is_array($receipt)) continue;
            $latest = is_array($receipt['latest'] ?? null) ? $receipt['latest'] : $receipt;
            if (in_array(strtoupper(trim((string) ($latest['status'] ?? $latest['result'] ?? ''))), $statuses, true)) $result[(string) $phase] = $latest;
        }
        return $result;
    }
}
