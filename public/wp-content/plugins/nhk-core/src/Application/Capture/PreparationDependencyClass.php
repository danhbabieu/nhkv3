<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

enum PreparationDependencyClass: string
{
    case CRITICAL_IDENTITY = 'CRITICAL_IDENTITY';
    case REQUIRED_FACTUAL_DEPENDENCY = 'REQUIRED_FACTUAL_DEPENDENCY';
    case OPTIONAL_ENRICHMENT = 'OPTIONAL_ENRICHMENT';
    case PUBLICATION_ONLY = 'PUBLICATION_ONLY';
}
