<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\CapturePurpose;

/** Validates the immutable Capture mode at the canonical input boundary. */
final class CapturePurposePolicy
{
    /** @param array<string,mixed> $input */
    public static function resolve(array $input): CapturePurpose
    {
        $declared = trim((string) ($input['purpose'] ?? ''));
        $intent = $input['authority_intent'] ?? null;
        $hasIntent = is_array($intent) && $intent !== [];
        $hasRelationshipOperations = is_array($input['relationship_operations'] ?? null) && $input['relationship_operations'] !== [];

        if ($declared === '') {
            if ($hasIntent) throw new \InvalidArgumentException('AUTHORITY_PURPOSE_REQUIRED');
            if ($hasRelationshipOperations) return CapturePurpose::AUTHORITY;
            return CapturePurpose::EDITORIAL;
        }

        $purpose = CapturePurpose::tryFrom(strtoupper($declared));
        if ($purpose === null || ($purpose === CapturePurpose::EDITORIAL && ($hasIntent || $hasRelationshipOperations))) throw new \InvalidArgumentException('AUTHORITY_PURPOSE_CONFLICT');

        return $purpose;
    }
}
