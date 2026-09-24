<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Maps safe owner admission to the current intent without changing truth. */
final class PreparationPhaseAdmissionPolicy
{
    public function mayAdmitMinimumOwner(array $intent, ContentPreparationResult $preparation): bool
    {
        if ($preparation->status === 'PREPARED') return true;
        if ($preparation->blockers !== []) return false;
        if (!in_array(strtoupper(trim((string) ($intent['intent'] ?? ''))), ['VIDEO', 'TEXT_ARTICLE', 'IMAGE_ARTICLE'], true)) return false;
        if ($preparation->continuationDecision?->mayContinue === true) return true;
        if ($preparation->subjectResolutionPacket?->status !== 'resolved') return false;
        foreach ($preparation->dependencyFindings as $finding) {
            if (!is_array($finding)) continue;
            $escalation = strtoupper(trim((string) ($finding['escalation'] ?? '')));
            $class = strtoupper(trim((string) ($finding['dependency_class'] ?? '')));
            if ($escalation !== '' && $class !== 'OPTIONAL_ENRICHMENT' && $class !== 'PUBLICATION_ONLY') return false;
        }
        return true;
    }
}
