<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

use NHK\Core\Domain\Dictionary\DictionaryMention;

interface DictionaryMentionRepository
{
    public function upsert(DictionaryMention $mention): DictionaryMention;
    public function listBySource(string $sourceKind, string $sourceId): array;
}
