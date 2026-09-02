<?php
declare(strict_types=1);

namespace NHK\Tests\Support;

use NHK\Core\Contracts\Graph\GraphDistributionReader;

final class InMemoryGraphDistributionReader implements GraphDistributionReader
{
    public function __construct(private array $values) {}
    public function rows(): array { return $this->values; }
}
