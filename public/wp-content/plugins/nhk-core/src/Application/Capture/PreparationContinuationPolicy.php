<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Decides workflow continuation without changing semantic preparation truth. */
final class PreparationContinuationPolicy
{
    /** @param list<array<string,mixed>> $findings @param array<string,mixed> $context */
    public function decide(array $findings, array $context = []): PreparationContinuationDecision
    {
        $phase = strtoupper(trim((string) ($context['phase'] ?? 'EDITORIAL_WORKING')));
        $blocking = [];
        $deferred = [];
        $trace = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) continue;
            $code = trim((string) ($finding['code'] ?? ''));
            if ($code === '') continue;
            $class = strtoupper(trim((string) ($finding['dependency_class'] ?? '')));
            $escalation = strtoupper(trim((string) ($finding['escalation'] ?? '')));
            $publicationGate = $class === PreparationDependencyClass::PUBLICATION_ONLY->value;
            $mustBlock = $escalation !== '' || ($phase === 'PUBLICATION' && $publicationGate && !in_array(strtoupper((string) ($finding['readiness'] ?? '')), ['READY', 'NOT_APPLICABLE'], true));
            if ($mustBlock) $blocking[] = $code;
            else $deferred[] = $code;
            $trace[] = ['code' => $code, 'dependency_class' => $class, 'phase' => $phase, 'decision' => $mustBlock ? 'DENY' : 'DEFER'];
        }

        $blocking = array_values(array_unique($blocking));
        $deferred = array_values(array_unique($deferred));
        return new PreparationContinuationDecision(
            $blocking === [],
            $blocking,
            $deferred,
            $blocking === [] ? ($deferred === [] ? 'NO_BLOCKING_DEPENDENCIES' : 'ONLY_DEFERRED_DEPENDENCIES') : 'BLOCKING_DEPENDENCY_REQUIRES_REVIEW',
            $trace,
        );
    }
}
