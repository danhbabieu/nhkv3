<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Resolves the persisted Capture content intent for staging admission. */
final class CaptureVideoIntent
{
    /** @param array<string,mixed> $input */
    public static function matches(CaptureRecord $capture, array $input = []): bool
    {
        $contextIntent = is_array($capture->context['content_intent'] ?? null)
            ? $capture->context['content_intent']
            : [];
        $planningIntent = is_array($capture->context['planning_input'] ?? null)
            ? $capture->context['planning_input']['intent'] ?? null
            : null;
        $intent = $contextIntent['intent'] ?? $planningIntent ?? ($input['intent'] ?? '');
        return strtoupper(trim((string) $intent)) === 'VIDEO';
    }
}
