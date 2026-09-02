<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

final readonly class ArticleMediaResult
{
    /** @param array<string,string> $slotMedia @param array<string,array<string,mixed>> $slots @param list<array{code:string,slot?:string,media_id?:string}> $diagnostics */
    public function __construct(
        public int $postId,
        public string $endpointKey,
        public string $state,
        public array $slotMedia,
        public array $slots,
        public array $diagnostics = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['post_id' => $this->postId, 'endpoint_key' => $this->endpointKey, 'state' => $this->state, 'slot_media' => $this->slotMedia, 'slots' => $this->slots, 'diagnostics' => $this->diagnostics];
    }
}
