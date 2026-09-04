<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

final class EntitySeoProfileRegistry
{
    /** @return list<string> */
    public function types(): array
    {
        return ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];
    }

    public function has(string $type): bool { return in_array($type, $this->types(), true); }
}
