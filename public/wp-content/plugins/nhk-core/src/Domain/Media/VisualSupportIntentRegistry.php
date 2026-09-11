<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

final class VisualSupportIntentRegistry
{
    public const REPRESENTATIVE = 'representative';
    public const TECHNICAL_DETAIL = 'technical_detail';
    public const EVIDENCE_LIKE_ILLUSTRATION = 'evidence_like_illustration';
    public const CONTEXTUAL_ILLUSTRATION = 'contextual_illustration';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REPRESENTATIVE, self::TECHNICAL_DETAIL, self::EVIDENCE_LIKE_ILLUSTRATION, self::CONTEXTUAL_ILLUSTRATION];
    }

    public static function assertKnown(string $intent): void
    {
        if (!in_array($intent, self::all(), true)) throw new \InvalidArgumentException('Unknown visual support intent.');
    }
}
