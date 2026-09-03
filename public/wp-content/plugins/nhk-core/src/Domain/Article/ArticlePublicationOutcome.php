<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

enum ArticlePublicationOutcome: string
{
    case PASS = 'PASS';
    case OWNER_REVIEW_REQUIRED = 'OWNER_REVIEW_REQUIRED';
    case SYSTEM_BLOCKED = 'SYSTEM_BLOCKED';
}
