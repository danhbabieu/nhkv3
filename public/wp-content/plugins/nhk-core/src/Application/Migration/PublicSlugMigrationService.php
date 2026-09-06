<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Contracts\PublicIdentity\PublicSlugMigrationSource;

/**
 * Governed public-route migration coordinator. It owns planning and safety
 * classification; the injected writer owns domain-specific persistence.
 */
final class PublicSlugMigrationService
{
    /** @param callable(array<string,mixed>):array<string,mixed> $writer */
    public function __construct(private PublicSlugMigrationSource $source, private $writer) {}

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $rows = $this->source->candidates();
        return ['candidate_count' => count($rows), 'types' => array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['type'] ?? ''), $rows)))];
    }

    /** @return array<string,mixed> */
    public function dryRun(): array
    {
        $candidates = $this->source->candidates();
        usort($candidates, static fn (array $left, array $right): int => strcmp(self::key($left), self::key($right)));
        $baseRows = [];
        foreach ($candidates as $candidate) $baseRows[] = $this->planRow($candidate);

        $groups = [];
        foreach ($baseRows as $index => $row) {
            if ($row['proposed_public_slug'] === null) continue;
            $groups[$row['scope'] . '|' . $row['proposed_public_slug']][] = $index;
        }
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $resolved = [];
            foreach ($indexes as $index) {
                $row = $baseRows[$index];
                $context = is_array($row['meaningful_context'] ?? null) ? $row['meaningful_context'] : [];
                foreach ($context as $key => $value) {
                    if (in_array((string) $key, ['uuid', 'canonical_id', 'stable_key', 'external_video_id', 'database_id', 'source_key', 'hash', 'idempotency_key'], true)) continue;
                    if (!is_scalar($value) || trim((string) $value) === '') continue;
                    $candidate = CanonicalPublicSlugPolicy::normalize((string) $row['base_slug'] . ' ' . (string) $value);
                    if ($candidate !== $row['proposed_public_slug'] && !in_array($candidate, $resolved, true)) {
                        $resolved[] = $candidate;
                        $row['proposed_public_slug'] = $candidate;
                        $row['proposed_url'] = $this->replaceSlug((string) $row['proposed_url'], (string) $baseRows[$index]['base_slug'], $candidate);
                        $row['status'] = 'CHANGED';
                        $row['collision'] = true;
                        $row['collision_reason'] = 'ROUTE_SCOPE_COLLISION';
                        $row['resolution'] = 'MEANINGFUL_CONTEXT';
                        break;
                    }
                }
                if (!isset($row['resolution'])) {
                    $row['status'] = 'COLLISION';
                    $row['collision'] = true;
                    $row['collision_reason'] = 'NO_UNIQUE_MEANINGFUL_DISCRIMINATOR';
                    $row['resolution'] = 'MANUAL_REVIEW_REQUIRED';
                }
                $baseRows[$index] = $row;
            }
        }

        $changed = count(array_filter($baseRows, static fn (array $row): bool => $row['status'] === 'CHANGED'));
        $collisions = count(array_filter($baseRows, static fn (array $row): bool => $row['collision'] === true));
        $manual = count(array_filter($baseRows, static fn (array $row): bool => in_array($row['status'], ['COLLISION', 'AMBIGUOUS'], true)));
        $countBy = static fn (string $status): int => count(array_filter($baseRows, static fn (array $row): bool => $row['status'] === $status));
        return [
            'candidate_count' => count($baseRows),
            'changed' => $changed,
            'no_op' => $countBy('NOOP'),
            'collisions' => $collisions,
            'manual_review' => $manual,
            'counts' => [
                'candidate_count' => count($baseRows),
                'changed' => $changed,
                'no_op' => $countBy('NOOP'),
                'collision' => $countBy('COLLISION'),
                'ambiguous' => $countBy('AMBIGUOUS'),
                'missing_identity' => $countBy('MISSING_IDENTITY'),
                'invalid_route' => count(array_filter($baseRows, static fn (array $row): bool => ($row['invalid_route'] ?? false) === true)),
                'unavailable' => $countBy('UNAVAILABLE'),
                'blocked' => count(array_filter($baseRows, static fn (array $row): bool => ($row['write_eligibility'] ?? '') === 'BLOCKED')),
            ],
            'fingerprint' => hash('sha256', (string) json_encode($baseRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'rows' => $baseRows,
        ];
    }

    /** @param array<string,mixed> $dryRun @return array<string,mixed> */
    public function apply(array $dryRun, string $authorization, string $fingerprint): array
    {
        if (trim($authorization) === '') return ['status' => 'AUTHORIZATION_REQUIRED', 'rows' => []];
        if (!hash_equals((string) ($dryRun['fingerprint'] ?? ''), $fingerprint)) return ['status' => 'STALE_DRY_RUN', 'rows' => []];
        $results = [];
        foreach (($dryRun['rows'] ?? []) as $row) {
            if (!is_array($row) || $row['status'] !== 'CHANGED') continue;
            $result = ($this->writer)([
                'resource_type' => $row['resource_type'], 'resource_id' => $row['resource_id'],
                'current_slug' => $row['current_public_slug'], 'proposed_slug' => $row['proposed_public_slug'],
                'current_url' => $row['current_url'], 'proposed_url' => $row['proposed_url'],
                'expected_revision' => $row['revision'], 'source_fingerprint' => $row['source_fingerprint'],
                'idempotency_key' => hash('sha256', $fingerprint . '|' . $row['resource_type'] . '|' . $row['resource_id']),
            ]);
            if (!is_array($result)) $result = ['status' => 'UNAVAILABLE'];
            $results[] = $result;
        }
        $failed = array_filter($results, static fn (array $row): bool => !in_array(($row['status'] ?? ''), ['CHANGED', 'NOOP'], true));
        return [
            'status' => $failed === [] ? 'APPLIED' : 'PARTIAL_FAILURE',
            'rows' => $results,
            'changed' => count(array_filter($results, static fn (array $row): bool => ($row['status'] ?? '') === 'CHANGED')),
            'no_op' => count(array_filter($results, static fn (array $row): bool => ($row['status'] ?? '') === 'NOOP')),
            'failed' => count($failed),
            'collisions' => (int) ($dryRun['collisions'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function planRow(array $candidate): array
    {
        $title = trim((string) ($candidate['title'] ?? ''));
        $base = CanonicalPublicSlugPolicy::normalize($title);
        $current = trim((string) ($candidate['current_slug'] ?? ''));
        $proposed = $base !== '' ? $base : null;
        $status = $proposed === null ? 'MISSING_IDENTITY' : ($proposed === $current ? 'NOOP' : 'CHANGED');
        $currentUrl = trim((string) ($candidate['current_url'] ?? ''));
        $proposedUrl = $proposed === null ? null : $this->replaceSlug($currentUrl, $current, $proposed);
        $invalidRoute = $currentUrl !== '' && (str_starts_with($currentUrl, '/') === false || str_ends_with($currentUrl, '/') === false || $proposedUrl === $currentUrl && $proposed !== $current);
        $availability = strtolower(trim((string) ($candidate['availability'] ?? 'available')));
        if ($availability !== 'available') $status = 'UNAVAILABLE';
        if (($candidate['ambiguous'] ?? false) === true) $status = 'AMBIGUOUS';
        if ($status === 'CHANGED' && $invalidRoute) $status = 'INVALID_ROUTE';
        return [
            'resource_type' => (string) ($candidate['type'] ?? ''), 'resource_id' => (string) ($candidate['id'] ?? ''),
            'current_public_slug' => $current, 'proposed_public_slug' => $proposed,
            'current_url' => $currentUrl, 'proposed_url' => $proposedUrl,
            'changed' => $status === 'CHANGED', 'collision' => false, 'collision_reason' => null,
            'resolution' => $status === 'NOOP' ? 'NOOP' : null, 'status' => $status,
            'write_eligibility' => $status === 'CHANGED' ? 'ELIGIBLE' : ($status === 'NOOP' ? 'NOOP' : 'BLOCKED'),
            'scope' => (string) ($candidate['scope'] ?? ''), 'revision' => (int) ($candidate['revision'] ?? 0),
            'source_fingerprint' => (string) ($candidate['fingerprint'] ?? ''), 'route_owner' => (string) ($candidate['route_owner'] ?? ''),
            'invalid_route' => $invalidRoute,
            'base_slug' => $base, 'meaningful_context' => $candidate['meaningful_context'] ?? [],
        ];
    }

    /** @param array<string,mixed> $row */
    private static function key(array $row): string { return (string) ($row['type'] ?? '') . '|' . (string) ($row['scope'] ?? '') . '|' . (string) ($row['id'] ?? ''); }
    private function replaceSlug(string $url, string $old, string $new): string { return $old !== '' && str_ends_with($url, '/' . $old . '/') ? substr($url, 0, -strlen('/' . $old . '/')) . '/' . $new . '/' : $url; }
}
