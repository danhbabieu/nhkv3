<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

use InvalidArgumentException;

final readonly class SemanticNeighborhoodProfile
{
    public function __construct(public string $name, public array $targetTypes, public int $maxHops = 2)
    {
        if ($name === '' || $targetTypes === [] || $maxHops < 1 || $maxHops > RelatedSemanticQuery::MAX_HOPS) {
            throw new InvalidArgumentException('Semantic neighborhood profile is invalid.');
        }
    }

    /** @return array<string,self> */
    public static function defaults(): array
    {
        $all = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'knowledge', 'media', 'video', 'wp_post'];
        return array_fill_keys(['brand', 'model', 'variant', 'classification', 'specimen'], new self('semantic', $all));
    }
}
