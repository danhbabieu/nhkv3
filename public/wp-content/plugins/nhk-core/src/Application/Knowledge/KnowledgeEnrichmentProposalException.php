<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

final class KnowledgeEnrichmentProposalException extends \InvalidArgumentException
{
    public function __construct(public readonly string $diagnosticCode, string $message)
    {
        parent::__construct($diagnosticCode . ': ' . $message);
    }
}
