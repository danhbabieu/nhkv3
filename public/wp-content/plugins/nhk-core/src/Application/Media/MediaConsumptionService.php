<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Consumption\OwnerConsumptionPlan;

/**
 * Orchestrates a Media consumption packet around the existing MediaBindingService.
 * The injected callable is the bound service method; this class never writes a repository.
 */
final class MediaConsumptionService
{
    /** @param callable(array<string,mixed>):array<string,mixed> $apply @param callable(array<string,mixed>):array<string,mixed>|null $reconcile */
    public function __construct(private $apply, private $reconcile = null)
    {
        if (!is_callable($apply)) throw new \InvalidArgumentException('MEDIA_BINDING_APPLY_UNAVAILABLE');
    }

    public function prepare(OwnerConsumptionPlan $plan, array $target): array
    {
        $data = $plan->toArray();
        if (($data['quality']['status'] ?? 'READY') === 'BLOCKED') throw new \RuntimeException('MEDIA_CONSUMPTION_QUALITY_BLOCKED');
        $mediaId = trim((string) ($data['owner']['id'] ?? ''));
        $targetType = trim((string) ($target['type'] ?? ''));
        $targetId = trim((string) ($target['id'] ?? $target['canonical_id'] ?? ''));
        if ($mediaId === '' || $targetType === '' || $targetId === '') throw new \InvalidArgumentException('MEDIA_CONSUMPTION_TARGET_INVALID');
        return [
            'status' => 'PREPARED',
            'governance_required' => true,
            'approved' => false,
            'owner' => $data['owner'],
            'target' => ['type' => $targetType, 'id' => $targetId],
            'media' => ['id' => $mediaId],
            'seo' => ['caption' => (string) ($data['surfaces']['caption'] ?? ''), 'alt_text' => (string) ($data['surfaces']['alt_text'] ?? ''), 'description' => (string) ($data['surfaces']['description'] ?? '')],
            'dependencies' => $data['dependencies'],
            'trace' => $data['trace'],
            'idempotency_key' => (string) ($data['owner']['id'] . ':' . $targetType . ':' . $targetId . ':enrichment'),
        ];
    }

    public function apply(array $approvedPacket): array
    {
        if (($approvedPacket['approved'] ?? false) !== true || ($approvedPacket['governance_required'] ?? false) !== true) throw new \RuntimeException('GOVERNANCE_APPROVAL_REQUIRED');
        $request = ['idempotency_key' => (string) $approvedPacket['idempotency_key'], 'media' => $approvedPacket['media'], 'target' => $approvedPacket['target'], 'role' => 'representative', 'selection_source' => 'SYSTEM_AUTO', 'selection_policy' => 'AUTO', 'seo' => $approvedPacket['seo']];
        $result = ($this->apply)($request);
        $readback = $this->readback($approvedPacket, $result['readback'] ?? []);
        if ($readback['status'] === 'verified') return ['status' => 'COMPLETE', 'result' => $result, 'readback' => $readback];
        if (is_callable($this->reconcile)) {
            $reconciled = ($this->reconcile)($request);
            $verified = $this->readback($approvedPacket, $reconciled);
            if ($verified['status'] === 'verified') return ['status' => 'RECONCILED', 'result' => $result, 'readback' => $verified];
        }
        return ['status' => 'UNKNOWN', 'result' => $result, 'readback' => $readback];
    }

    public function readback(array $packet, array $readback): array
    {
        if (($readback['status'] ?? '') !== 'verified') return ['status' => 'unknown', 'reason' => 'MEDIA_CANONICAL_READBACK_UNAVAILABLE'];
        if ((string) ($readback['media_id'] ?? '') !== (string) ($packet['media']['id'] ?? '') || (string) ($readback['target_id'] ?? '') !== (string) ($packet['target']['id'] ?? '')) return ['status' => 'unknown', 'reason' => 'MEDIA_CANONICAL_READBACK_IDENTITY_MISMATCH'];
        return ['status' => 'verified', 'media_id' => (string) $readback['media_id'], 'target_id' => (string) $readback['target_id']];
    }
}
