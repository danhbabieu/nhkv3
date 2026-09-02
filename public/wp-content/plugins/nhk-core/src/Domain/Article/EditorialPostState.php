<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class EditorialPostState
{
    public string $token;

    public function __construct(
        public int $postId,
        public string $endpointKey,
        public string $postType,
        public string $status,
        public string $title,
        public string $content,
        public string $excerpt,
        public string $slug,
        public string $permalink,
        public int $latestRevisionId,
        public int $revisionCount,
        public string $modifiedGmt = '',
    ) {
        $this->token = EditorialStateToken::fromState($this->snapshot());
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'post_id' => $this->postId,
            'post_type' => $this->postType,
            'title' => $this->title,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'status' => $this->status,
            'slug' => $this->slug,
            'permalink' => $this->permalink,
            'modified_gmt' => $this->modifiedGmt,
            'latest_revision_id' => $this->latestRevisionId,
            'revision_count' => $this->revisionCount,
        ];
    }
}
