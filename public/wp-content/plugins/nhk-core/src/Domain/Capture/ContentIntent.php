<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

enum ContentIntent: string
{
    case VIDEO = 'VIDEO';
    case IMAGE_ARTICLE = 'IMAGE_ARTICLE';
    case TEXT_ARTICLE = 'TEXT_ARTICLE';
    case KNOWLEDGE_DELTA = 'KNOWLEDGE_DELTA';
    case MEDIA_ENRICHMENT = 'MEDIA_ENRICHMENT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $intent): string => $intent->value, self::cases());
    }

    public function requiresArticle(): bool
    {
        return in_array($this, [self::IMAGE_ARTICLE, self::TEXT_ARTICLE], true);
    }

    public function requiresMedia(): bool
    {
        return $this === self::MEDIA_ENRICHMENT;
    }
}
