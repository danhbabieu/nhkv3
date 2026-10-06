<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

interface DictionaryDuplicateAuditReader
{
    /** @return list<array<string,mixed>> */
    public function read(int $limit = 1000): array;
}
