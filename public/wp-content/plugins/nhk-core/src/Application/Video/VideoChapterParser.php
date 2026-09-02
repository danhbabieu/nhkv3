<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoChapterParser
{
    /** @return list<array{label:string,start_seconds:int,evidence:array{kind:string,locator:string}}> */
    public function parse(string $description, ?int $durationSeconds = null): array
    {
        $chapters = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $lineNumber => $line) {
            if (preg_match('/^\s*(?:[-*]\s*)?(?:(\d{1,2}):)?(\d{1,3}):(\d{2})\s+(.{1,200})\s*$/u', $line, $match) !== 1) continue;
            $seconds = (int) (($match[1] !== '' ? (int) $match[1] * 3600 : 0) + (int) $match[2] * 60 + (int) $match[3]);
            if ($seconds < 0 || ($durationSeconds !== null && $seconds >= $durationSeconds)) continue;
            $label = trim($match[4]);
            if ($label === '' || ($chapters !== [] && $seconds <= $chapters[count($chapters) - 1]['start_seconds'])) continue;
            $chapters[] = ['label' => $label, 'start_seconds' => $seconds, 'evidence' => ['kind' => 'YOUTUBE_DESCRIPTION', 'locator' => 'line:' . ((int) $lineNumber + 1)]];
            if (count($chapters) >= 50) break;
        }
        return $chapters;
    }
}
