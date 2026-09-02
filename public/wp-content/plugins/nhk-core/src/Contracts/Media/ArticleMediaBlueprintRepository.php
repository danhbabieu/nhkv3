<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\MediaSeoBlueprint;

interface ArticleMediaBlueprintRepository
{
    public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint;
    public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint;
    /** @return list<MediaSeoBlueprint> */
    public function listByPost(int $postId): array;
}
