<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ClaimProjectionCategory;

final class NodeProjectionProfileRegistry
{
    /** @var array<string,list<string>> */
    private const PROFILES = [
        'brand' => ['identity', 'history', 'classification', 'mechanism', 'configuration', 'component', 'dial_and_hands', 'case_and_decoration', 'music_and_strike', 'sound', 'identification_rule', 'provenance'],
        'model' => ['identity', 'history', 'mechanism', 'configuration', 'dial_and_hands', 'case_and_decoration', 'music_and_strike', 'sound', 'identification_rule'],
        'variant' => ['identity', 'mechanism', 'configuration', 'component', 'dial_and_hands', 'music_and_strike', 'sound', 'user_experience', 'operation', 'identification_rule', 'exception'],
        'movement' => ['identity', 'mechanism', 'component', 'configuration', 'music_and_strike', 'operation', 'identification_rule'],
        'component' => ['identity', 'mechanism', 'configuration', 'material', 'sound', 'operation', 'identification_rule'],
        'classification' => ['identity', 'classification', 'history', 'identification_rule', 'comparison'],
        'specimen' => ['identity', 'provenance', 'identification_rule', 'exception', 'other'],
        'product' => ['identity', 'provenance', 'comparison', 'other'],
    ];

    /** @return list<string> */
    public function categoriesFor(string $nodeType): array
    {
        return self::PROFILES[$nodeType] ?? ClaimProjectionCategory::VALUES;
    }

    public function allows(string $nodeType, string $category): bool
    {
        return in_array($category, $this->categoriesFor($nodeType), true);
    }
}
