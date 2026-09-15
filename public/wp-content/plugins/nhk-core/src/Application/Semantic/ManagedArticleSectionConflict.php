<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

final class ManagedArticleSectionConflict extends \RuntimeException
{
    public function __construct(string $sectionId)
    {
        parent::__construct('EDITORIAL_CONFLICT:' . $sectionId);
    }
}
