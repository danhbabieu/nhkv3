<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\{Media, MediaAsset};

/**
 * Infrastructure bridge for native WordPress editorial image state.
 *
 * The adapter owns WordPress attachment/editorial APIs. It never becomes a
 * second Media repository and returns only state that can be reconciled by
 * the Article media coordinator.
 */
interface WordPressArticleMediaAdapter
{
    /** @return array{featured_media_id:?string,inline_media_ids:list<string>,managed_inline_media_id:?string,featured_attachment_id:int,inline_attachment_ids:list<int>,content:string,state_token?:string,unmapped_attachment_ids?:list<int>} */
    public function read(int $postId): array;

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function synchronize(int $postId, array $result): array;

    /** @return array<string,mixed> */
    public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array;

    public function adoptAttachment(int $attachmentId): ?string;
}
