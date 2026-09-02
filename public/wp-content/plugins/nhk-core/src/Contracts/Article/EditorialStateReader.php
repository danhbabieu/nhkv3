<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

use NHK\Core\Domain\Article\EditorialPostState;

interface EditorialStateReader
{
    public function read(int $postId): ?EditorialPostState;
}
