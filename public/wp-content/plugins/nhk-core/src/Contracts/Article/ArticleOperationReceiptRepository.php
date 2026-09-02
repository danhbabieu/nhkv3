<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

use NHK\Core\Domain\Article\ArticleOperationReceipt;

interface ArticleOperationReceiptRepository
{
    public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt;
    public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt;
    public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt;
}
