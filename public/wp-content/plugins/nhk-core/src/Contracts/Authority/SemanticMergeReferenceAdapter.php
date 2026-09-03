<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** A registered owner-boundary adapter used by the generic semantic merge. */
interface SemanticMergeReferenceAdapter
{
    /** @return list<array<string,mixed>> */
    public function enumerate(AuthorityEntity $source, AuthorityEntity $target): array;

    /** @param list<array<string,mixed>> $references @return list<array<string,mixed>> */
    public function plan(AuthorityEntity $source, AuthorityEntity $target, array $references): array;

    /** @param array<string,mixed> $planned @return array{action:string,reference:string} */
    public function apply(array $planned): array;
}
