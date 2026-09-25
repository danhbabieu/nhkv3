<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Media\MediaBindingPort;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Resolves user feature locators independently and hands only canonical,
 * capability-checked targets to the governed MediaUsage boundary.
 */
final class CaptureFeatureBindingCoordinator
{
    public function __construct(
        private SubjectResolutionService $subjects,
        private MediaBindingPort $bindings,
    ) {
    }

    /** @param list<array<string,mixed>> $assets @return array{assets:list<array<string,mixed>>,feature_results:list<array<string,mixed>>,status:string} */
    public function execute(array $assets, string $captureId): array
    {
        $results = [];
        foreach ($assets as $assetIndex => &$asset) {
            if (!is_array($asset)) continue;
            $input = is_array($asset['capture_asset_input'] ?? null) ? $asset['capture_asset_input'] : [];
            $requests = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), (array) ($input['feature_requests'] ?? [])), static fn (string $value): bool => $value !== ''));
            $assetResults = [];
            $seen = [];
            $completed = [];
            foreach ((array) ($asset['feature_results'] ?? []) as $previous) {
                if (!is_array($previous) || (string) ($previous['disposition'] ?? '') !== 'COMPLETE') continue;
                $completed[$this->normalize((string) ($previous['input'] ?? ''))] = $previous;
            }
            foreach ($requests as $featureIndex => $request) {
                $normalized = $this->normalize($request);
                $result = [
                    'asset_index' => $assetIndex,
                    'feature_index' => $featureIndex,
                    'input' => $request,
                    'normalized_input' => $normalized,
                    'resolution_status' => 'unresolved',
                    'target_type' => '',
                    'target_id' => '',
                    'resolution_method' => '',
                    'evidence' => [],
                    'binding_status' => 'NOT_ATTEMPTED',
                    'usage_id' => '',
                    'disposition' => 'NEEDS_REVIEW',
                    'reason' => 'FEATURE_NOT_FOUND',
                ];
                if (isset($seen[$normalized])) {
                    $result['disposition'] = 'DUPLICATE_INPUT';
                    $result['reason'] = 'DUPLICATE_FEATURE_REQUEST';
                    $assetResults[] = $result;
                    continue;
                }
                $seen[$normalized] = true;
                if (isset($completed[$normalized])) {
                    $reused = $completed[$normalized];
                    $reused['feature_index'] = $featureIndex;
                    $reused['disposition'] = 'COMPLETE';
                    $reused['reason'] = 'IDEMPOTENT_COMPLETE_REUSED';
                    $assetResults[] = $reused;
                    continue;
                }
                try {
                    $resolution = $this->subjects->resolve([$request]);
                    $result['resolution_status'] = strtoupper((string) ($resolution['status'] ?? 'unresolved'));
                    $result['evidence'] = [
                        'primary_source' => (string) ($resolution['primary_source'] ?? ''),
                        'diagnostics' => array_values((array) ($resolution['diagnostics'] ?? [])),
                        'candidates' => array_values((array) ($resolution['candidates'] ?? [])),
                    ];
                    $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
                    if ($result['resolution_status'] !== 'RESOLVED' || !UuidCodec::isValid((string) ($primary['id'] ?? ''))) {
                        $result['reason'] = $result['resolution_status'] === 'AMBIGUOUS' ? 'FEATURE_AMBIGUOUS' : 'FEATURE_NOT_FOUND';
                        $assetResults[] = $result;
                        continue;
                    }
                    $result['target_type'] = strtolower(trim((string) ($primary['type'] ?? '')));
                    $result['target_id'] = (string) $primary['id'];
                    $result['resolution_method'] = $this->resolutionMethod($primary, $resolution);
                    $result['binding_status'] = 'IN_PROGRESS';
                    $binding = $this->bindings->bindMany([[
                        'idempotency_key' => $this->idempotencyKey($captureId, $assetIndex, $normalized, $result['target_type'], $result['target_id']),
                        'media_ref' => ['media_id' => trim((string) ($asset['media_id'] ?? ''))],
                        'target' => ['type' => $result['target_type'], 'id' => $result['target_id'], 'stable_key' => trim((string) ($primary['stable_key'] ?? ''))],
                        'role' => 'representative',
                        'selection_source' => 'USER_EXPLICIT',
                        'selection_policy' => 'PINNED',
                    ]], $captureId . ':feature:' . $assetIndex . ':' . $featureIndex, [$asset]);
                    $bindingResult = is_array($binding['bindings'][0] ?? null) ? $binding['bindings'][0] : [];
                    $readback = is_array($bindingResult['readback'] ?? null) ? $bindingResult['readback'] : [];
                    if (strtoupper((string) ($binding['status'] ?? '')) !== 'COMPLETE' || strtolower((string) ($readback['status'] ?? '')) !== 'verified' || trim((string) ($readback['usage_id'] ?? '')) === '') {
                        $result['binding_status'] = 'FAILED_RETRYABLE';
                        $result['disposition'] = 'FAILED_RETRYABLE';
                        $result['reason'] = 'MEDIA_USAGE_READBACK_REQUIRED';
                    } else {
                        $result['binding_status'] = 'COMPLETE';
                        $result['usage_id'] = (string) $readback['usage_id'];
                        $result['disposition'] = 'COMPLETE';
                        $result['reason'] = 'CANONICAL_MEDIA_USAGE_VERIFIED';
                        $result['binding'] = $bindingResult;
                    }
                } catch (\Throwable $error) {
                    $result['binding_status'] = 'FAILED_RETRYABLE';
                    $result['disposition'] = 'FAILED_RETRYABLE';
                    $result['reason'] = $this->errorCode($error);
                }
                $assetResults[] = $result;
            }
            $asset['feature_results'] = $assetResults;
            $asset['disposition'] = $this->assetDisposition($asset, $assetResults);
            foreach ($assetResults as $featureResult) $results[] = $featureResult;
        }
        unset($asset);
        $statuses = array_map(static fn (array $item): string => (string) ($item['disposition'] ?? ''), $results);
        return ['assets' => array_values($assets), 'feature_results' => $results, 'status' => $this->overallStatus($statuses)];
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function resolutionMethod(array $primary, array $resolution): string
    {
        return (string) ($primary['match'] ?? $primary['match_class'] ?? $resolution['primary_source'] ?? 'canonical_resolution');
    }

    private function idempotencyKey(string $captureId, int $assetIndex, string $request, string $type, string $id): string
    {
        return $captureId . ':feature-binding:' . $assetIndex . ':' . substr(hash('sha256', $request . '|' . $type . '|' . $id), 0, 32);
    }

    private function errorCode(\Throwable $error): string
    {
        return preg_replace('/[^A-Z0-9_:-]+/', '_', strtoupper(trim($error->getMessage()))) ?: 'FEATURE_BINDING_FAILED';
    }

    /** @param list<array<string,mixed>> $results */
    private function assetDisposition(array $asset, array $results): string
    {
        if ($results === []) {
            $physical = strtoupper(trim((string) ($asset['upload_status'] ?? '')));
            $readback = strtolower(trim((string) ($asset['attachment_readback_status'] ?? '')));
            if (in_array($physical, ['FAILED', 'FAILED_RETRYABLE', 'ERROR'], true) || $readback === 'failed') return 'FAILED_RETRYABLE';
            return in_array($physical, ['COMPLETE', 'REUSED', 'VERIFIED'], true) || $readback === 'verified' ? 'COMPLETE' : 'NEEDS_REVIEW';
        }
        $dispositions = array_column($results, 'disposition');
        if (count(array_filter($dispositions, static fn (mixed $value): bool => $value === 'COMPLETE')) === count($dispositions)) return 'COMPLETE';
        if (in_array('FAILED_RETRYABLE', $dispositions, true)) return in_array('COMPLETE', $dispositions, true) ? 'PARTIAL' : 'FAILED_RETRYABLE';
        return in_array('COMPLETE', $dispositions, true) ? 'PARTIAL' : 'NEEDS_REVIEW';
    }

    /** @param list<string> $statuses */
    private function overallStatus(array $statuses): string
    {
        if ($statuses === []) return 'NOT_REQUESTED';
        if (count(array_filter($statuses, static fn (string $status): bool => $status === 'COMPLETE')) === count($statuses)) return 'COMPLETE';
        return in_array('FAILED_RETRYABLE', $statuses, true) ? 'PARTIAL' : 'REVIEW_REQUIRED';
    }
}
