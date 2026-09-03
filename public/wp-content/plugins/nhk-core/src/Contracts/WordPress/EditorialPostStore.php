<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\WordPress;

use NHK\Core\Domain\Article\EditorialPostState;

interface EditorialPostStore
{
    public function read(int $postId): ?EditorialPostState;
    /** @param array<string,mixed> $fields */
    public function createDraft(array $fields): EditorialPostState;
    /** @param array<string,mixed> $fields */
    public function update(int $postId, array $fields): EditorialPostState;
}
