<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Deterministic family asset contract shared by the theme and tests. */
final class PublicTemplateFamilyAssetManifest
{
    /** @param array<string,mixed> $context @return array{styles:list<string>,scripts:list<string>} */
    public static function forContext(array $context): array
    {
        $family = strtolower(trim((string) ($context['family'] ?? 'base')));
        $mode = strtolower(trim((string) ($context['mode'] ?? '')));
        $styles = match ($family) {
            'entity' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-entity'],
            'dictionary' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-dictionary'],
            'video' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-media-video'],
            'media' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-media-video'],
            'knowledge' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-knowledge'],
            'article' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-entity', 'nhk-v3-media-video'],
            'comparison' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-comparison'],
            'homepage' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-entity', 'nhk-v3-media-video', 'nhk-v3-knowledge'],
            'search' => ['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-entity', 'nhk-v3-media-video'],
            default => ['nhk-v3-style'],
        };
        $scripts = ['nhk-v3-navigation'];
        if ($family === 'article' && ($context['album'] ?? false) === true) $scripts[] = 'nhk-v3-album';
        if ($family === 'article' && ($context['album'] ?? false) === true) $styles[] = 'nhk-v3-album-style';
        if ($family === 'video' && $mode === 'detail') $scripts[] = 'nhk-v3-video-player';
        return ['styles' => array_values(array_unique($styles)), 'scripts' => array_values(array_unique($scripts))];
    }

    /** @return array<string,list<string>> */
    public static function dependencies(): array
    {
        return [
            'nhk-v3-style' => [],
            'nhk-v3-presentation' => ['nhk-v3-style'],
            'nhk-v3-entity' => ['nhk-v3-style', 'nhk-v3-presentation'],
            'nhk-v3-media-video' => ['nhk-v3-style', 'nhk-v3-presentation'],
            'nhk-v3-knowledge' => ['nhk-v3-style', 'nhk-v3-presentation'],
            'nhk-v3-dictionary' => ['nhk-v3-style', 'nhk-v3-presentation'],
            'nhk-v3-comparison' => ['nhk-v3-style', 'nhk-v3-presentation'],
            'nhk-v3-album-style' => ['nhk-v3-style', 'nhk-v3-presentation'],
        ];
    }
}
