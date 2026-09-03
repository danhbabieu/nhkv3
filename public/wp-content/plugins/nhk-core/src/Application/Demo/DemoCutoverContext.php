<?php
declare(strict_types=1);

namespace NHK\Core\Application\Demo;

use InvalidArgumentException;

final readonly class DemoCutoverContext
{
    public function __construct(public string $target, public string $pack, public string $sourceRevision, public string $runId)
    {
        if ($target !== 'demo.1945.vn' || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $pack) || $sourceRevision === '' || $runId === '') {
            throw new InvalidArgumentException('Invalid DEMO cutover context.');
        }
    }
}
