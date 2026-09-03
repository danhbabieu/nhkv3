<?php
declare(strict_types=1);

namespace NHK\Core\Domain\WordPress;

final readonly class CategoryState
{
    public string $fingerprint;

    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public int $parent,
        public int $count = 0,
    ) {
        $this->fingerprint = hash('sha256', json_encode([$id, $name, $slug, $parent, $count], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'parent' => $this->parent, 'count' => $this->count, 'fingerprint' => $this->fingerprint];
    }
}
