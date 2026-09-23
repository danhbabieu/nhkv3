<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Maps safe owner admission to the current intent without changing truth. */
final class PreparationPhaseAdmissionPolicy
{
    public function mayAdmitMinimumOwner(array $intent, ContentPreparationResult $preparation): bool
    {
        if ($preparation->status === 'PREPARED') return true;
        if ($preparation->continuationDecision?->mayContinue !== true) return false;
        return in_array(strtoupper(trim((string) ($intent['intent'] ?? ''))), ['VIDEO', 'TEXT_ARTICLE', 'IMAGE_ARTICLE'], true);
    }
}
