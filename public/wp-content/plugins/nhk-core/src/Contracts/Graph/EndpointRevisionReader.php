<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Graph;

use NHK\Core\Domain\Graph\NodeReference;

interface EndpointRevisionReader extends EndpointResolver
{
    public function revision(NodeReference $reference): ?int;
}
