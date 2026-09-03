<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

final readonly class PublicationPrincipal
{
    public function __construct(public string $id, public string $channel, public string $requestReference)
    {
        if ($id === '' || $channel === '') throw new \InvalidArgumentException('Authenticated publication principal is required.');
    }
}
