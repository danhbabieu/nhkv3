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
        if (strtoupper(trim((string) ($intent['intent'] ?? ''))) === 'VIDEO'
            && $this->hasOptionalUnresolvedSubjectGap($preparation)) return true;
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

    private function hasOptionalUnresolvedSubjectGap(ContentPreparationResult $preparation): bool
    {
        if ($preparation->subjectResolutionPacket !== null) return false;
        if (!in_array('PRIMARY_SUBJECT_NOT_RESOLVED', $preparation->reviewReasons, true)) return false;
        foreach ($preparation->dependencyFindings as $finding) {
            if (!is_array($finding)) continue;
            $escalation = strtoupper(trim((string) ($finding['escalation'] ?? '')));
            $class = strtoupper(trim((string) ($finding['dependency_class'] ?? '')));
            if ($escalation !== '' && !in_array($class, ['OPTIONAL_ENRICHMENT', 'PUBLICATION_ONLY'], true)) return false;
        }
        return true;
    }

}
