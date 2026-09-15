<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

use NHK\Core\Domain\Article\ExternalResearchResult;

/** Read-only, bounded port; providers may not create Source/Evidence records. */
interface ExternalResearchProvider
{
    /** @param array<string,mixed> $context */
    public function research(string $topic, array $context = [], int $maxItems = 10): ExternalResearchResult;
}
