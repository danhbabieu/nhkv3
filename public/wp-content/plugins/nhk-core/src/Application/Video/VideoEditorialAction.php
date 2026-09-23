<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoEditorialAction
{
    public const USE_AS_IS = 'USE_AS_IS';
    public const ATTRIBUTE_AND_SCOPE = 'ATTRIBUTE_AND_SCOPE';
    public const QUALIFY_INFERENCE = 'QUALIFY_INFERENCE';
    public const NARROW_SCOPE = 'NARROW_SCOPE';
    public const REMOVE_UNSUPPORTED = 'REMOVE_UNSUPPORTED';
    public const PREFER_CANONICAL = 'PREFER_CANONICAL';
    public const REPLACE_ALTERNATE_KNOWLEDGE = 'REPLACE_ALTERNATE_KNOWLEDGE';
    public const NARROW_TITLE = 'NARROW_TITLE';
    public const RESTRUCTURE_COPY = 'RESTRUCTURE_COPY';
    public const REDUCE_SPECIFICITY = 'REDUCE_SPECIFICITY';
    public const REGENERATE_SECTION = 'REGENERATE_SECTION';
    public const REPAIR_PUBLIC_COPY = 'REPAIR_PUBLIC_COPY';

    public static function all(): array
    {
        return [self::USE_AS_IS, self::ATTRIBUTE_AND_SCOPE, self::QUALIFY_INFERENCE, self::NARROW_SCOPE, self::REMOVE_UNSUPPORTED, self::PREFER_CANONICAL, self::REPLACE_ALTERNATE_KNOWLEDGE, self::NARROW_TITLE, self::RESTRUCTURE_COPY, self::REDUCE_SPECIFICITY, self::REGENERATE_SECTION, self::REPAIR_PUBLIC_COPY];
    }

    public static function assert(string $value): string
    {
        if (!in_array($value, self::all(), true)) throw new \InvalidArgumentException('Video editorial action is invalid.');
        return $value;
    }
}
