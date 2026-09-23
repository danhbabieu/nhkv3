<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Derives requirements from server-owned operation context only. */
final class PreparationDependencyRequirements
{
    /** @param array<string,mixed> $input @param array<string,mixed> $interpretation @param array<string,mixed> $context @return list<array<string,mixed>> */
    public function derive(array $input, array $interpretation, array $context = []): array
    {
        $requirements = [];
        foreach ((array) ($context['server_dependency_requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) continue;
            $code = trim((string) ($requirement['code'] ?? ''));
            $kind = PreparationDependencyClass::tryFrom(strtoupper(trim((string) ($requirement['kind'] ?? ''))));
            if ($code === '' || $kind === null) throw new \InvalidArgumentException('Server dependency requirement is invalid.');
            $requirements[] = [
                'code' => $code,
                'kind' => $kind->value,
                'readiness' => strtoupper(trim((string) ($requirement['readiness'] ?? 'INCOMPLETE'))),
                'escalation' => $requirement['escalation'] ?? null,
                'required' => ($requirement['required'] ?? false) === true,
                'reason' => trim((string) ($requirement['reason'] ?? 'Server-derived dependency is not ready.')),
            ];
        }
        return $requirements;
    }
}
