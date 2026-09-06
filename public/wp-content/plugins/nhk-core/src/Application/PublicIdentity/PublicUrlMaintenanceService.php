<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

final class PublicUrlMaintenanceService
{
    private PublicUrlReprojectionPlanner $planner;

    /**
     * @param \Closure():array<int,array<string,mixed>> $inventory
     * @param \Closure(array<string,mixed>,string):bool $externallyOccupied
     * @param \Closure(array<string,mixed>,string):void $apply
     */
    public function __construct(
        private \Closure $inventory,
        private \Closure $externallyOccupied,
        private \Closure $apply,
        ?PublicUrlReprojectionPlanner $planner = null,
    ) {
        $this->planner = $planner ?? new PublicUrlReprojectionPlanner();
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        try {
            $inventory = ($this->inventory)();
            if (!is_array($inventory)) return ['status'=>'UNAVAILABLE','reason_code'=>'PUBLIC_URL_INVENTORY_UNAVAILABLE','items'=>[],'counts'=>['total'=>0,'change'=>0,'keep'=>0,'blocked'=>0]];
            return $this->planner->plan(array_values($inventory), $this->externallyOccupied);
        } catch (\Throwable) {
            return ['status'=>'UNAVAILABLE','reason_code'=>'PUBLIC_URL_INVENTORY_UNAVAILABLE','items'=>[],'counts'=>['total'=>0,'change'=>0,'keep'=>0,'blocked'=>0]];
        }
    }

    /** @return array<string,mixed> */
    public function reproject(string $idempotencyKey, bool $prePublicConfirmed): array
    {
        if (!$prePublicConfirmed) return ['status'=>'BLOCKED','reason_code'=>'PRE_PUBLIC_CONFIRMATION_REQUIRED','mutation_count'=>0];
        if (trim($idempotencyKey) === '') return ['status'=>'BLOCKED','reason_code'=>'IDEMPOTENCY_KEY_REQUIRED','mutation_count'=>0];

        $plan = $this->audit();
        if (($plan['status'] ?? '') !== 'READY') return [...$plan, 'mutation_count'=>0];

        $mutationCount = 0;
        foreach ((array)($plan['items'] ?? []) as $index => $item) {
            if (!in_array((string)($item['action'] ?? ''), ['ALLOCATE','CHANGE'], true)) continue;
            try {
                ($this->apply)($item, $idempotencyKey . ':' . $index);
                $mutationCount++;
            } catch (\Throwable $error) {
                return [
                    'status'=>'FAILED',
                    'reason_code'=>'PUBLIC_URL_REPROJECTION_WRITE_FAILED',
                    'failed_index'=>$index,
                    'mutation_count'=>$mutationCount,
                    'plan'=>$plan,
                ];
            }
        }

        $readback = $this->audit();
        if (($readback['status'] ?? '') !== 'READY' || (int)($readback['counts']['change'] ?? -1) !== 0 || (int)($readback['counts']['blocked'] ?? -1) !== 0) {
            return ['status'=>'FAILED','reason_code'=>'PUBLIC_URL_REPROJECTION_READBACK_FAILED','mutation_count'=>$mutationCount,'plan'=>$plan,'readback'=>$readback];
        }

        return ['status'=>'APPLIED','mutation_count'=>$mutationCount,'plan'=>$plan,'readback'=>$readback];
    }
}
