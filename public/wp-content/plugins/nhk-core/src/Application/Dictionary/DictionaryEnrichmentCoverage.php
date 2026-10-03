<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/**
 * Composes bounded owner projections for diagnostics. It owns no persistence
 * and treats infrastructure absence as distinct from an empty result.
 */
final class DictionaryEnrichmentCoverage
{
    private const PACKETS = ['knowledge', 'media', 'video', 'articles', 'brands', 'models', 'specimens', 'mentions', 'related_terms'];

    /** @param array<string,callable> $providers */
    public function __construct(private array $providers = []) {}

    /** @return array<string,mixed> */
    public function forReference(string $type, string $id, array $context = []): array
    {
        $ownerState = strtoupper(trim((string) ($context['owner_state'] ?? '')));
        if (($ownerState === '' || !in_array($ownerState, ['AVAILABLE', 'EMPTY', 'UNAVAILABLE', 'BLOCKED', 'AMBIGUOUS'], true)) && (trim($type) === '' || trim($id) === '')) $ownerState = 'AMBIGUOUS';
        if ($ownerState !== '' && $ownerState !== 'AVAILABLE') {
            $result = ['status' => $ownerState, 'type' => $type, 'id' => $id];
            foreach (self::PACKETS as $packet) $result[$packet] = ['status' => $ownerState, 'count' => 0, 'items' => []];
            return $result;
        }
        $result = ['status' => 'AVAILABLE', 'type' => $type, 'id' => $id];
        foreach (self::PACKETS as $packet) {
            $provider = $this->providers[$packet] ?? null;
            if (!is_callable($provider)) { $result[$packet] = ['status' => 'UNAVAILABLE', 'count' => 0, 'items' => []]; continue; }
            try {
                $packetResult = (array) $provider($type, $id, $context);
                $result[$packet] = $this->normalizePacket($packetResult);
            } catch (\Throwable) { $result[$packet] = ['status' => 'UNAVAILABLE', 'count' => 0, 'items' => []]; }
        }
        return $result;
    }

    /** @param array<string,mixed> $packet */
    private function normalizePacket(array $packet): array
    {
        $status = strtoupper(trim((string) ($packet['status'] ?? 'UNAVAILABLE')));
        if (!in_array($status, ['AVAILABLE', 'EMPTY', 'UNAVAILABLE', 'BLOCKED', 'AMBIGUOUS'], true)) $status = 'UNAVAILABLE';
        $items = is_array($packet['items'] ?? null) ? array_slice($packet['items'], 0, 20) : [];
        $out = $packet + ['count' => count($items), 'items' => $items];
        $out['status'] = $status;
        $out['count'] = max(0, min(20, (int) ($packet['count'] ?? count($items))));
        $out['items'] = $items;
        return $out;
    }
}
