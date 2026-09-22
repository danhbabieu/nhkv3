<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicUrlMaintenanceService
{
    private PublicUrlReprojectionPlanner $planner;

    /**
     * @param \Closure():array<int,array<string,mixed>> $inventory
     * @param \Closure(array<string,mixed>,string):bool $externallyOccupied
     * @param \Closure(array<string,mixed>,string):void $apply
     * @param \Closure(array<string,mixed>):array<string,mixed> $deliveryVerifier
     */
    public function __construct(
        private \Closure $inventory,
        private \Closure $externallyOccupied,
        private \Closure $apply,
        ?PublicUrlReprojectionPlanner $planner = null,
        private ?\Closure $deliveryVerifier = null,
    ) {
        $this->planner = $planner ?? new PublicUrlReprojectionPlanner();
    }

    /** @return array<string,mixed> */
    public function audit(?string $ownerId = null): array
    {
        if ($ownerId !== null) {
            $ownerId = trim($ownerId);
            if ($ownerId === '') return $this->ownerFailure('PUBLIC_URL_OWNER_REQUIRED');
            if (!UuidCodec::isValid($ownerId)) return $this->ownerFailure('PUBLIC_URL_OWNER_INVALID');
        }
        try {
            $inventory = ($this->inventory)();
            if (!is_array($inventory)) return ['status'=>'UNAVAILABLE','reason_code'=>'PUBLIC_URL_INVENTORY_UNAVAILABLE','items'=>[],'counts'=>['total'=>0,'change'=>0,'keep'=>0,'blocked'=>0]];
            $inventory = array_values($inventory);
            if ($ownerId !== null) {
                $inventory = array_values(array_filter($inventory, static fn (mixed $item): bool => is_array($item) && (string) ($item['owner_id'] ?? '') === $ownerId));
                if (count($inventory) === 0) return $this->ownerFailure('PUBLIC_URL_OWNER_NOT_FOUND');
                if (count($inventory) > 1) return $this->ownerFailure('PUBLIC_URL_OWNER_AMBIGUOUS');
            }
            $plan = $this->planner->plan($inventory, $this->externallyOccupied);
            foreach ($plan['items'] as $index => $item) {
                if (($item['kind'] ?? '') !== 'media_asset') continue;
                $action = (string) ($item['action'] ?? 'BLOCKED');
                $plan['items'][$index]['url_identity_status'] = match ($action) {
                    'KEEP' => 'URL_IDENTITY_KEEP',
                    'ALLOCATE' => 'URL_IDENTITY_ALLOCATE',
                    'CHANGE' => 'URL_IDENTITY_CHANGE',
                    default => 'URL_IDENTITY_BLOCKED',
                };
                $plan['items'][$index]['binary_delivery'] = $this->deliveryVerifier === null
                    ? ['status' => 'UNVERIFIED', 'reason_code' => 'BINARY_DELIVERY_VERIFIER_UNAVAILABLE']
                    : ($this->deliveryVerifier)($item);
            }
            $plan['owner_id'] = $ownerId;
            $plan['plan_fingerprint'] = $this->fingerprint($plan['items']);
            return $plan;
        } catch (\Throwable) {
            return ['status'=>'UNAVAILABLE','reason_code'=>'PUBLIC_URL_INVENTORY_UNAVAILABLE','items'=>[],'counts'=>['total'=>0,'change'=>0,'keep'=>0,'blocked'=>0]];
        }
    }

    /** @return array<string,mixed> */
    public function reproject(string $idempotencyKey, bool $prePublicConfirmed, ?string $ownerId = null, int $batchSize = 25, int $cursor = 0): array
    {
        if (!$prePublicConfirmed) return ['status'=>'BLOCKED','reason_code'=>'PRE_PUBLIC_CONFIRMATION_REQUIRED','mutation_count'=>0];
        if (trim($idempotencyKey) === '') return ['status'=>'BLOCKED','reason_code'=>'IDEMPOTENCY_KEY_REQUIRED','mutation_count'=>0];

        if ($ownerId !== null) return $this->reprojectOwner($idempotencyKey, $ownerId);

        $plan = $this->audit();
        if (($plan['status'] ?? '') !== 'READY') return [...$plan, 'mutation_count'=>0];

        $mutationCount = 0;
        $outcomes = [];
        $batchSize = max(1, min(100, $batchSize));
        $attempted = 0;
        $eligibleIndex = 0;
        $cursor = max(0, $cursor);
        foreach ((array)($plan['items'] ?? []) as $index => $item) {
            if (!in_array((string)($item['action'] ?? ''), ['ALLOCATE','CHANGE'], true)) continue;
            if ($eligibleIndex++ < $cursor) continue;
            if ($attempted >= $batchSize) break;
            $attempted++;
            $owner = (string) ($item['owner_id'] ?? '');
            $idempotencyOwner = UuidCodec::isValid($owner) ? $owner : (string) $index;
            try {
                ($this->apply)($item, $idempotencyKey . ':' . $idempotencyOwner);
                $mutationCount++;
                $outcomes[] = ['owner_id' => $owner, 'status' => 'APPLIED'];
            } catch (\Throwable $error) {
                $outcomes[] = ['owner_id' => $owner, 'status' => $this->isRetryable($error) ? 'RETRYABLE' : 'FAILED', 'reason_code' => $this->scopedWriteFailureCode($error)];
            }
        }

        $totalEligible = count(array_filter((array) ($plan['items'] ?? []), static fn (mixed $item): bool => is_array($item) && in_array((string) ($item['action'] ?? ''), ['ALLOCATE', 'CHANGE'], true)));
        $nextCursor = min($totalEligible, $cursor + $attempted);
        $remaining = $nextCursor < $totalEligible;
        if (array_filter($outcomes, static fn (array $outcome): bool => in_array($outcome['status'], ['FAILED', 'RETRYABLE'], true)) !== [] || $remaining) {
            return ['status' => $remaining ? 'PARTIAL' : 'FAILED', 'reason_code' => $remaining ? 'PUBLIC_URL_BATCH_CHECKPOINT_REQUIRED' : 'PUBLIC_URL_REPROJECTION_WRITE_FAILED', 'mutation_count' => $mutationCount, 'outcomes' => $outcomes, 'next_cursor' => $nextCursor, 'readback' => $this->audit(), 'plan' => $plan];
        }

        $readback = $this->audit();
        if (($readback['status'] ?? '') !== 'READY' || (int)($readback['counts']['change'] ?? -1) !== 0 || (int)($readback['counts']['blocked'] ?? -1) !== 0) {
            return ['status'=>'FAILED','reason_code'=>'PUBLIC_URL_REPROJECTION_READBACK_FAILED','mutation_count'=>$mutationCount,'plan'=>$plan,'readback'=>$readback];
        }

        return ['status'=>'APPLIED','mutation_count'=>$mutationCount,'outcomes' => $outcomes, 'plan'=>$plan,'readback'=>$readback];
    }

    /** @return array<string,mixed> */
    private function reprojectOwner(string $idempotencyKey, string $ownerId): array
    {
        $ownerId = trim($ownerId);
        if ($ownerId === '') return $this->ownerFailure('PUBLIC_URL_OWNER_REQUIRED', ['mutation_count' => 0]);
        if (!UuidCodec::isValid($ownerId)) return $this->ownerFailure('PUBLIC_URL_OWNER_INVALID', ['mutation_count' => 0]);

        $plan = $this->audit($ownerId);
        if (($plan['status'] ?? '') !== 'READY') return [...$plan, 'mutation_count' => 0];
        if ((int) ($plan['counts']['total'] ?? 0) !== 1 || !isset($plan['items'][0]) || !is_array($plan['items'][0])) {
            return $this->ownerFailure('PUBLIC_URL_OWNER_NOT_FOUND', ['mutation_count' => 0]);
        }

        // The receipt is deliberately re-planned immediately before apply. A
        // global audit is not a prerequisite: only this owner's deterministic
        // decision and current revision can authorize the scoped mutation.
        $currentPlan = $this->audit($ownerId);
        if (($currentPlan['status'] ?? '') !== 'READY' || ($currentPlan['plan_fingerprint'] ?? '') !== ($plan['plan_fingerprint'] ?? '')) {
            return ['status' => 'BLOCKED', 'reason_code' => 'PUBLIC_URL_DECISION_STALE', 'mutation_count' => 0, 'plan' => $plan, 'current_plan' => $currentPlan, 'blockers' => $this->blockers($currentPlan)];
        }

        $item = $currentPlan['items'][0];
        $action = (string) ($item['action'] ?? 'BLOCKED');
        if (!in_array($action, ['KEEP', 'ALLOCATE', 'CHANGE'], true)) {
            return ['status' => 'BLOCKED', 'reason_code' => 'PUBLIC_URL_SCOPED_DECISION_INELIGIBLE', 'mutation_count' => 0, 'plan' => $currentPlan, 'blockers' => $this->blockers($currentPlan)];
        }

        $mutationCount = 0;
        if (in_array($action, ['ALLOCATE', 'CHANGE'], true)) {
            try {
                ($this->apply)($item, $idempotencyKey);
                $mutationCount = 1;
            } catch (\Throwable $error) {
                $reason = $this->scopedWriteFailureCode($error);
                return ['status' => 'BLOCKED', 'reason_code' => $reason, 'mutation_count' => 0, 'plan' => $currentPlan, 'blockers' => [$reason]];
            }
        }

        $readback = $this->audit($ownerId);
        $readbackItem = isset($readback['items'][0]) && is_array($readback['items'][0]) ? $readback['items'][0] : [];
        if (($readback['status'] ?? '') !== 'READY' || (int) ($readback['counts']['total'] ?? 0) !== 1 || !in_array((string) ($readbackItem['action'] ?? ''), ['KEEP'], true)) {
            return ['status' => 'FAILED', 'reason_code' => 'PUBLIC_URL_REPROJECTION_READBACK_FAILED', 'mutation_count' => $mutationCount, 'plan' => $currentPlan, 'readback' => $readback, 'blockers' => $this->blockers($readback)];
        }

        return [
            'status' => 'APPLIED',
            'action' => $action,
            'owner_id' => $ownerId,
            'identity_id' => (string) ($readbackItem['identity_id'] ?? ''),
            'route_type' => (string) ($readbackItem['route_type'] ?? $item['route_type'] ?? ''),
            'previous_path' => (string) ($item['current_path'] ?? ''),
            'previous_slug' => (string) ($item['current_slug'] ?? ''),
            'final_path' => (string) ($readbackItem['current_path'] ?? ''),
            'final_slug' => (string) ($readbackItem['current_slug'] ?? ''),
            'revision' => (int) ($readbackItem['revision'] ?? 0),
            'idempotency_key' => $idempotencyKey,
            'canonical_read_back' => $readbackItem,
            'blockers' => [],
            'mutation_count' => $mutationCount,
            'plan' => $currentPlan,
            'readback' => $readback,
        ];
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function ownerFailure(string $reason, array $extra = []): array
    {
        return array_merge(['status' => 'BLOCKED', 'reason_code' => $reason, 'owner_id' => null, 'items' => [], 'counts' => ['total' => 0, 'change' => 0, 'keep' => 0, 'blocked' => 1], 'blockers' => [$reason]], $extra);
    }

    /** @param list<array<string,mixed>> $items */
    private function fingerprint(array $items): string
    {
        return hash('sha256', (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<string,mixed> $plan @return list<string> */
    private function blockers(array $plan): array
    {
        $blockers = [];
        foreach ((array) ($plan['items'] ?? []) as $item) {
            if (is_array($item) && trim((string) ($item['blocker'] ?? '')) !== '') $blockers[] = (string) $item['blocker'];
        }
        return array_values(array_unique($blockers));
    }

    private function scopedWriteFailureCode(\Throwable $error): string
    {
        return match ($error->getMessage()) {
            'STALE_REVISION', 'CAS_MISMATCH' => 'PUBLIC_URL_REVISION_CAS_MISMATCH',
            'NATIVE_ROUTE_CONFLICT', 'PUBLIC_SLUG_COLLISION' => 'COLLISION_REQUIRES_RECONCILIATION',
            'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_CONFLICT' => 'IDEMPOTENCY_CONFLICT',
            default => 'PUBLIC_URL_REPROJECTION_WRITE_FAILED',
        };
    }

    private function isRetryable(\Throwable $error): bool
    {
        return in_array($this->scopedWriteFailureCode($error), ['PUBLIC_URL_REPROJECTION_WRITE_FAILED', 'PUBLIC_URL_REVISION_CAS_MISMATCH'], true);
    }
}
