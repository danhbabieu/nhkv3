<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\SubjectResolutionPacket;

/**
 * Shared adapter-to-Capture subject handoff boundary.
 *
 * Adapters may discover a canonical subject while enriching a child owner,
 * but only Capture may adopt that immutable packet for continuation. This
 * class validates the typed packet and rejects drift from an already locked
 * subject; it does not resolve names, create semantic owners or write data.
 */
final class CaptureSubjectHandoff
{
    /**
     * @param array<string,mixed> $currentResolution
     * @param array<string,mixed> $adapterManifest
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    public function adopt(
        array $currentResolution,
        array $adapterManifest,
        array $items = [],
        bool $preferCurrent = false,
        string $failureCode = 'CAPTURE_SUBJECT_HANDOFF_INVARIANT_FAILED',
    ): ?array {
        $current = is_array($currentResolution['primary'] ?? null) ? $currentResolution['primary'] : [];
        $currentId = strtolower(trim((string) ($current['id'] ?? '')));
        $currentType = strtolower(trim((string) ($current['type'] ?? '')));
        if ($preferCurrent && $currentId !== '' && $currentType !== '') return $currentResolution;

        $previewWasReturned = array_key_exists('video_preview', $adapterManifest)
            || array_key_exists('preview', $adapterManifest)
            || $this->containsPreview($items);
        $packet = $this->findPacket($adapterManifest, $failureCode);
        if ($packet === null) {
            if ($previewWasReturned && $currentId !== '') throw new \RuntimeException($failureCode);
            return null;
        }

        if ($currentId !== '' && ($currentId !== strtolower($packet->canonicalSubjectId) || $currentType !== strtolower($packet->entityType))) {
            throw new \RuntimeException($failureCode);
        }
        if ($currentId !== '') return $currentResolution;

        return $packet->toResolution();
    }

    /** @param array<string,mixed> $value */
    private function findPacket(array $value, string $failureCode): ?SubjectResolutionPacket
    {
        foreach ($value as $key => $child) {
            if ($key === 'subject_resolution_packet' && is_array($child)) {
                $packetInput = $child;
                if (trim((string) ($packetInput['status'] ?? '')) === ''
                    && trim((string) ($packetInput['id'] ?? $packetInput['canonical_subject_id'] ?? '')) !== ''
                    && trim((string) ($packetInput['type'] ?? $packetInput['entity_type'] ?? '')) !== '') {
                    $packetInput['status'] = 'resolved';
                }
                $packet = SubjectResolutionPacket::fromArray($packetInput);
                if ($packet === null || $packet->status !== 'resolved') throw new \RuntimeException($failureCode);
                return $packet;
            }
            if (is_array($child)) {
                $packet = $this->findPacket($child, $failureCode);
                if ($packet !== null) return $packet;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $items */
    private function containsPreview(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && (array_key_exists('video_preview', $item) || array_key_exists('preview', $item))) return true;
        }
        return false;
    }
}
