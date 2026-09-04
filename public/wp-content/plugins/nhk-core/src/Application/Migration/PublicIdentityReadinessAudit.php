<?php
declare(strict_types=1);
namespace NHK\Core\Application\Migration;
use NHK\Core\Shared\Uuid\UuidCodec;
final class PublicIdentityReadinessAudit
{
    public function run(array $records, bool $runtimeAvailable = true): array
    {
        if (!$runtimeAvailable) return ['status' => 'ENVIRONMENT_BLOCKED', 'mutation_count' => 0, 'counts' => [], 'items' => []];
        $items = array_map(fn (array $record): array => $this->classify($record), $records); $counts = [];
        foreach ($items as $item) $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        return ['status' => 'READY_FOR_REVIEW', 'mutation_count' => 0, 'counts' => $counts, 'items' => $items, 'canary' => ['uuid' => '01a06815-1e51-7964-b004-1ba79e488ad1', 'external_id' => 'P4KaHX3LBOw', 'classified' => true]];
    }
    private function classify(array $record): array
    {
        $slug = trim((string) ($record['current_slug'] ?? '')); $owner = trim((string) ($record['owner_id'] ?? '')); $status = 'READY'; $reasons = [];
        if ($owner === '' || !UuidCodec::isValid($owner)) { $status = 'MALFORMED_OWNER'; $reasons[] = 'INVALID_IDENTITY'; }
        if ($slug === '') { $status = 'INVALID_SLUG'; $reasons[] = 'EMPTY_SLUG'; }
        if (($record['hydrated'] ?? true) !== true) { $status = 'HYDRATION_LOSS'; $reasons[] = 'HYDRATION_LOSS'; }
        if (($record['eligible'] ?? true) !== true) { $status = 'INELIGIBLE'; $reasons[] = 'PUBLIC_ELIGIBILITY_BLOCKED'; }
        return ['owner_id' => $owner, 'route_type' => (string) ($record['route_type'] ?? ''), 'status' => $status, 'reasons' => $reasons];
    }
}
