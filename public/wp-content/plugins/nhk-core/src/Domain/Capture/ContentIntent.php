<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

enum ContentIntent: string
{
    case VIDEO = 'VIDEO';
    case IMAGE_ARTICLE = 'IMAGE_ARTICLE';
    case TEXT_ARTICLE = 'TEXT_ARTICLE';
    case KNOWLEDGE_DELTA = 'KNOWLEDGE_DELTA';

    public function requiresArticle(): bool
    {
        return in_array($this, [self::IMAGE_ARTICLE, self::TEXT_ARTICLE], true);
    }
}
