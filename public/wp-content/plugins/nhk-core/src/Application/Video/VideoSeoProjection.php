<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Seo\PublicSeoProjection;

final class VideoSeoProjection
{
    /** @param array<string,mixed> $package @return array<string,mixed> */
    public function project(array $package, string|array $watchUrl): array
    {
        $urlResult = is_array($watchUrl) ? $watchUrl : (new PublicSeoProjection())->eligibleUrl($watchUrl);
        $watchPath = is_array($urlResult['path'] ?? null) ? '' : (string) ($urlResult['path'] ?? '');
        $seoProjection = (new PublicSeoProjection())->project($urlResult, ['title' => $package['seo']['title'] ?? $package['editorial']['title'] ?? '', 'description' => $package['seo']['description'] ?? $package['editorial']['summary'] ?? '', 'type' => 'VideoObject']);
        $source = is_array($package['source'] ?? null) ? $package['source'] : [];
        $editorial = is_array($package['editorial'] ?? null) ? $package['editorial'] : [];
        $seo = is_array($package['seo'] ?? null) ? $package['seo'] : [];
        $id = (string) ($source['external_video_id'] ?? '');
        $object = [
            '@context' => 'https://schema.org', '@type' => 'VideoObject',
            'name' => (string) ($editorial['title'] ?? $seo['title'] ?? ''),
            'description' => (string) ($editorial['summary'] ?? $seo['description'] ?? ''),
            'url' => $watchPath,
            'embedUrl' => preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? 'https://www.youtube-nocookie.com/embed/' . $id : null,
        ];
        if (($source['published_at'] ?? null) !== null && (string) $source['published_at'] !== '') $object['uploadDate'] = (string) $source['published_at'];
        if (isset($source['duration_seconds']) && (int) $source['duration_seconds'] > 0) $object['duration'] = $this->duration((int) $source['duration_seconds']);
        $thumbnail = is_array($source['thumbnail_urls'] ?? null) ? (string) ($source['thumbnail_urls'][0] ?? '') : '';
        if ($thumbnail !== '' && filter_var($thumbnail, FILTER_VALIDATE_URL) !== false) $object['thumbnailUrl'] = [$thumbnail];
        $chapters = is_array($package['chapters'] ?? null) ? $package['chapters'] : [];
        if ($chapters !== []) {
            $parts = [];
            foreach ($chapters as $index => $chapter) {
                if (!is_array($chapter) || trim((string) ($chapter['label'] ?? '')) === '' || !isset($chapter['start_seconds'])) continue;
                $end = isset($chapters[$index + 1]['start_seconds']) ? (int) $chapters[$index + 1]['start_seconds'] : (isset($source['duration_seconds']) ? (int) $source['duration_seconds'] : 0);
                $start = (int) $chapter['start_seconds'];
                if ($end <= $start) continue;
                $parts[] = ['@type' => 'Clip', 'name' => (string) $chapter['label'], 'startOffset' => $start, 'endOffset' => $end, 'url' => rtrim($watchPath, '#') . '#t=' . $start];
            }
            if ($parts !== []) $object['hasPart'] = $parts;
        }
        return [
            'title' => (string) ($seo['title'] ?? $editorial['title'] ?? ''),
            'description' => (string) ($seo['description'] ?? $editorial['summary'] ?? ''),
            'canonical' => $seoProjection['canonical'],
            'indexable' => $seoProjection['indexable'],
            'open_graph' => [...$seoProjection['open_graph'], 'type' => 'video.other'],
            'video_object' => $object,
        ];
    }

    private function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600); $minutes = intdiv($seconds % 3600, 60); $remaining = $seconds % 60;
        return 'PT' . ($hours > 0 ? $hours . 'H' : '') . ($minutes > 0 ? $minutes . 'M' : '') . ($remaining > 0 || ($hours === 0 && $minutes === 0) ? $remaining . 'S' : '');
    }
}
