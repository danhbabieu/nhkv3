<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\ArticleMediaBlueprintRepository;
use NHK\Core\Domain\Media\MediaSeoBlueprint;

final class WpdbArticleMediaBlueprintRepository implements ArticleMediaBlueprintRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_article_media_blueprints';
    }

    public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint
    {
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE post_id=%d AND slot=%s LIMIT 1", $postId, $slot), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint
    {
        $json = wp_json_encode($blueprint->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = $this->findByPostAndSlot($blueprint->postId, $blueprint->slot);
        if ($existing !== null && $existing->toArray() === $blueprint->toArray()) return $existing;
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (post_id,slot,state,blueprint_json,revision,created_at,updated_at) VALUES (%d,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE state=VALUES(state),blueprint_json=VALUES(blueprint_json),revision=revision+1,updated_at=VALUES(updated_at)", $blueprint->postId, $blueprint->slot, $blueprint->state, $json, $blueprint->revision, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) throw new \RuntimeException('ARTICLE_MEDIA_BLUEPRINT_SAVE_FAILED');
        return $this->findByPostAndSlot($blueprint->postId, $blueprint->slot) ?? $blueprint;
    }

    public function listByPost(int $postId): array
    {
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE post_id=%d ORDER BY slot", $postId), ARRAY_A);
        return array_values(array_filter(array_map(fn (array $row): ?MediaSeoBlueprint => $this->hydrate($row), $rows ?: [])));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ?MediaSeoBlueprint
    {
        try {
            $payload = json_decode((string) ($row['blueprint_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) return null;
            return new MediaSeoBlueprint(
                (int) ($payload['post_id'] ?? $row['post_id']),
                (string) ($payload['slot'] ?? $row['slot']),
                is_array($payload['subject_context'] ?? null) ? $payload['subject_context'] : [],
                isset($payload['preferred_view']) && $payload['preferred_view'] !== null ? (string) $payload['preferred_view'] : null,
                is_array($payload['keyword_groups'] ?? null) ? array_values(array_map('strval', $payload['keyword_groups'])) : [],
                (string) ($payload['planned_title'] ?? ''),
                (string) ($payload['planned_filename_stem'] ?? ''),
                (string) ($payload['planned_alt_intent'] ?? ''),
                (string) ($payload['preferred_aspect'] ?? ''),
                (int) ($payload['minimum_width'] ?? 0),
                (int) ($payload['minimum_height'] ?? 0),
                (bool) ($payload['focal_point_expected'] ?? false),
                (string) ($payload['state'] ?? $row['state'] ?? ''),
                (int) ($row['revision'] ?? $payload['revision'] ?? 1),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
