<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface MediaBindingPort
{
    /** @param list<array<string,mixed>> $bindings @param list<array<string,mixed>> $assets @return array<string,mixed> */
    public function bindMany(array $bindings, string $idempotencyKey, array $assets = []): array;
}
