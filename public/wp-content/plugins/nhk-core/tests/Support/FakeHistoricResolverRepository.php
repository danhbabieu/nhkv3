<?php
declare(strict_types=1);

namespace NHK\Tests\Support;

final class FakeHistoricResolverRepository
{
    public function __construct(private string $status = 'FOUND') {}

    /** @return array<string,mixed> */
    public function resolveHistoric(string $path): array
    {
        if ($this->status !== 'FOUND') return ['status' => $this->status];
        return ['status' => 'FOUND', 'target' => '/video/odo-36-10-gai-carillon/', 'hops' => 1];
    }
}
