<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

enum ArticleIngestStage: string
{
    case RECEIPT = 'receipt';
    case PREFLIGHT = 'preflight';
    case GOVERNANCE = 'governance';
    case SEMANTIC_APPLY = 'semantic_apply';
    case VERIFICATION = 'verification';
    case COMPLETE = 'complete';
}
