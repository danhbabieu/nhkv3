<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Explicit owner dependency DAG used for partial retry decisions. */
final class CaptureOwnerDag
{
    private array $dependencies = [];

    public function __construct(array $tracks)
    {
        foreach ($tracks as $owner => $track) {
            $owner = strtolower(trim((string) $owner));
            if ($owner === '') continue;
            $dependencies[$owner] = array_values(array_unique(array_map(
                static fn (mixed $dependency): string => strtolower(trim((string) $dependency)),
                (array) ($track['depends_on'] ?? []),
            )));
        }
        $this->dependencies = $dependencies ?? [];
        foreach (array_keys($this->dependencies) as $owner) $this->assertAcyclic($owner, [], []);
    }

    public function statusFor(string $owner, array $outcomes): string
    {
        $owner = strtolower(trim($owner));
        $outcome = $outcomes[$owner] ?? null;
        foreach ($this->dependencies[$owner] ?? [] as $dependency) {
            $dependencyOutcome = $outcomes[$dependency] ?? null;
            if (!$dependencyOutcome instanceof CaptureOwnerOutcome || !$dependencyOutcome->isSuccessful()) return 'BLOCKED_ON_DEPENDENCY';
        }
        if (!$outcome instanceof CaptureOwnerOutcome) return 'PLANNED';
        foreach ($this->dependencies[$owner] ?? [] as $dependency) {
            $dependencyOutcome = $outcomes[$dependency];
            $expected = $outcome->dependencyRevisions[$dependency] ?? null;
            $actual = $dependencyOutcome->canonicalReadback['revision'] ?? $dependencyOutcome->expectedRevision;
            if ($expected !== null && (int) $expected !== (int) $actual) return 'REPLAN_REQUIRED';
        }
        return $outcome->status;
    }

    /** @return list<string> */
    public function retryableTracks(array $outcomes): array
    {
        $retry = [];
        foreach ($this->dependencies as $owner => $_) {
            $status = $this->statusFor($owner, $outcomes);
            if (in_array($status, ['PLANNED', 'READY', 'FAILED_RETRYABLE', 'REPLAN_REQUIRED'], true)) $retry[] = $owner;
        }
        return $retry;
    }

    /** @return list<string> */
    public function dependencyClosure(string $owner): array
    {
        $owner = strtolower(trim($owner));
        $seen = [];
        $visit = function (string $current) use (&$visit, &$seen): void {
            foreach ($this->dependencies[$current] ?? [] as $dependency) {
                if (isset($seen[$dependency])) continue;
                $seen[$dependency] = true;
                $visit($dependency);
            }
        };
        $visit($owner);
        return array_keys($seen);
    }

    /**
     * Return the minimal topologically ordered set that must execute for the
     * requested owner tracks. Verified independent tracks are reusable.
     * @return list<string>
     */
    public function executionPlan(array $requestedOwners, array $outcomes): array
    {
        $needed = [];
        $visit = function (string $owner) use (&$visit, &$needed, $outcomes): void {
            if (isset($needed[$owner])) return;
            $needed[$owner] = true;
            foreach ($this->dependencies[$owner] ?? [] as $dependency) {
                $status = $this->statusFor($dependency, $outcomes);
                if (!$this->isReusable($status)) $visit($dependency);
            }
            if ($this->statusFor($owner, $outcomes) === 'REPLAN_REQUIRED') {
                foreach ($this->dependencies[$owner] ?? [] as $dependency) $visit($dependency);
            }
        };
        foreach ($requestedOwners as $owner) {
            $owner = strtolower(trim((string) $owner));
            if ($owner !== '' && array_key_exists($owner, $this->dependencies)) $visit($owner);
        }
        $ordered = [];
        $append = function (string $owner) use (&$append, &$ordered, $needed): void {
            if (!isset($needed[$owner]) || in_array($owner, $ordered, true)) return;
            foreach ($this->dependencies[$owner] ?? [] as $dependency) $append($dependency);
            $ordered[] = $owner;
        };
        foreach (array_keys($needed) as $owner) $append($owner);
        return $ordered;
    }

    private function isReusable(string $status): bool
    {
        return $status === 'READ_BACK_VERIFIED';
    }

    private function assertAcyclic(string $owner, array $visiting, array $visited): void
    {
        if (isset($visiting[$owner])) throw new \InvalidArgumentException('Capture owner dependency cycle detected.');
        if (isset($visited[$owner])) return;
        $visiting[$owner] = true;
        foreach ($this->dependencies[$owner] ?? [] as $dependency) $this->assertAcyclic($dependency, $visiting, $visited);
        unset($visiting[$owner]);
        $visited[$owner] = true;
    }
}
