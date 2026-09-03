<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Authority;

use NHK\Core\Domain\Authority\SemanticMergeReceipt;

interface SemanticMergeReceiptRepository
{
    public function findByIdempotencyKey(string $key): ?SemanticMergeReceipt;
    public function append(SemanticMergeReceipt $receipt): void;
}
