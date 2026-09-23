<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Converts the small Markdown subset accepted by Capture into WP blocks. */
final class EditorialContentProjection
{
    public static function toWordPressBlocks(string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/u', trim($content));
        if (!is_array($lines)) return $content;

        $projected = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*$/u', $line, $match) === 1) {
                $level = min(6, max(1, strlen((string) $match[1])));
                $heading = htmlspecialchars(trim((string) $match[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $projected[] = '<!-- wp:heading {"level":' . $level . '} --><h' . $level . '>' . $heading . '</h' . $level . '><!-- /wp:heading -->';
                continue;
            }
            $projected[] = $line;
        }

        return trim(implode("\n", $projected));
    }
}
