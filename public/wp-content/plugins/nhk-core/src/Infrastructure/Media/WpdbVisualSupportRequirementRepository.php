<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Media\{VisualSupportRequirement, VisualSupportRequirementStateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbVisualSupportRequirementRepository implements VisualSupportRequirementRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_visual_support_requirements';
    }

    public function save(VisualSupportRequirement $requirement, int $expectedRevision = 0): VisualSupportRequirement
    {
        $json = static fn (array $value): string => function_exists('wp_json_encode') ? (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $media = $requirement->mediaId === null ? null : UuidCodec::toBinary($requirement->mediaId);
        $mediaSql = $media === null ? 'NULL' : '%s';
        $mediaRevisionSql = $requirement->mediaRevision === null ? 'NULL' : '%d';
        if ($expectedRevision === 0) {
            $sql = "INSERT INTO {$this->table} (requirement_uuid,subject_type,subject_uuid,scope,facet,feature_key,visual_intent,state,selected_media_uuid,selected_media_revision,context_json,provenance_json,unresolved_reason,semantic_fingerprint,idempotency_fingerprint,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,{$mediaSql},{$mediaRevisionSql},%s,%s,%s,%s,%s,%d,%s,%s)";
            $args = [UuidCodec::toBinary($requirement->canonicalId), $requirement->subjectType, UuidCodec::toBinary($requirement->subjectId), $requirement->scope, $requirement->facet, $requirement->featureKey, $requirement->visualIntent, $requirement->state];
            if ($media !== null) $args[] = $media;
            if ($requirement->mediaRevision !== null) $args[] = $requirement->mediaRevision;
            array_push($args, $json($requirement->context), $json($requirement->provenance), $requirement->unresolvedReason, $requirement->semanticFingerprint, $requirement->idempotencyFingerprint, $requirement->revision, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u'));
            $ok = $this->database->query($this->database->prepare($sql, ...$args));
            if ($ok === false) return $this->findBySemanticFingerprint($requirement->semanticFingerprint) ?? $this->findByIdempotencyFingerprint($requirement->idempotencyFingerprint) ?? throw new \RuntimeException('VISUAL_REQUIREMENT_WRITE_FAILED');
        } else {
            $sql = "UPDATE {$this->table} SET state=%s,selected_media_uuid={$mediaSql},selected_media_revision={$mediaRevisionSql},context_json=%s,provenance_json=%s,unresolved_reason=%s,revision=revision+1,updated_at=%s WHERE requirement_uuid=%s AND revision=%d";
            $args = [$requirement->state];
            if ($media !== null) $args[] = $media;
            if ($requirement->mediaRevision !== null) $args[] = $requirement->mediaRevision;
            array_push($args, $json($requirement->context), $json($requirement->provenance), $requirement->unresolvedReason, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($requirement->canonicalId), $expectedRevision);
            $ok = $this->database->query($this->database->prepare($sql, ...$args));
            if ($ok !== 1) throw new \RuntimeException('VISUAL_REQUIREMENT_REVISION_CONFLICT');
        }
        return $this->findById($requirement->canonicalId) ?? $requirement;
    }

    public function findById(string $id): ?VisualSupportRequirement
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE requirement_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), $this->output()));
    }

    public function findBySemanticFingerprint(string $fingerprint): ?VisualSupportRequirement
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE semantic_fingerprint=%s LIMIT 1", $fingerprint), $this->output()));
    }

    public function findByIdempotencyFingerprint(string $fingerprint): ?VisualSupportRequirement
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE idempotency_fingerprint=%s LIMIT 1", $fingerprint), $this->output()));
    }

    public function findCandidatesForMedia(array $context, int $limit = 100): array
    {
        $limit = max(1, min($limit, 100));
        $states = [VisualSupportRequirementStateRegistry::MISSING, VisualSupportRequirementStateRegistry::REVIEW_REQUIRED];
        $sql = "SELECT * FROM {$this->table} WHERE state IN (%s,%s) AND subject_type=%s AND subject_uuid=%s AND scope=%s AND facet=%s AND feature_key=%s AND visual_intent=%s ORDER BY revision,id LIMIT %d";
        $rows = $this->database->get_results($this->database->prepare($sql, $states[0], $states[1], (string) ($context['subject_type'] ?? ''), UuidCodec::toBinary((string) ($context['subject_id'] ?? '')), (string) ($context['scope'] ?? ''), (string) ($context['facet'] ?? ''), (string) ($context['feature_key'] ?? ''), (string) ($context['visual_intent'] ?? ''), $limit), $this->output());
        return $this->hydrateList(is_array($rows) ? $rows : []);
    }

    public function findReplacementCandidatesForMedia(array $context, int $limit = 100): array
    {
        $limit = max(1, min($limit, 100));
        $sql = "SELECT * FROM {$this->table} WHERE state=%s AND subject_type=%s AND subject_uuid=%s AND scope=%s AND facet=%s AND feature_key=%s AND visual_intent=%s ORDER BY revision,id LIMIT %d";
        $rows = $this->database->get_results($this->database->prepare($sql, VisualSupportRequirementStateRegistry::RESOLVED, (string) ($context['subject_type'] ?? ''), UuidCodec::toBinary((string) ($context['subject_id'] ?? '')), (string) ($context['scope'] ?? ''), (string) ($context['facet'] ?? ''), (string) ($context['feature_key'] ?? ''), (string) ($context['visual_intent'] ?? ''), $limit), $this->output());
        return $this->hydrateList(is_array($rows) ? $rows : []);
    }

    public function listForAdmin(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min($limit, 100));
        $where = [];
        $args = [];
        foreach (['state', 'subject_type', 'scope', 'facet', 'feature_key', 'visual_intent'] as $key) {
            if (!isset($filters[$key]) || trim((string) $filters[$key]) === '') continue;
            $where[] = "{$key}=%s";
            $args[] = (string) $filters[$key];
        }
        $sql = "SELECT * FROM {$this->table}" . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
        $args[] = $limit;
        $rows = $this->database->get_results($this->database->prepare($sql, ...$args), $this->output());
        return $this->hydrateList(is_array($rows) ? $rows : []);
    }

    private function output(): mixed { return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'; }

    private function hydrate(mixed $row): ?VisualSupportRequirement
    {
        if (!is_array($row)) return null;
        try {
            $context = json_decode((string) ($row['context_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $provenance = json_decode((string) ($row['provenance_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($context) || !is_array($provenance)) return null;
            $media = $row['selected_media_uuid'] ?? null;
            return new VisualSupportRequirement(UuidCodec::fromBinary((string) $row['requirement_uuid']), (string) $row['subject_type'], UuidCodec::fromBinary((string) $row['subject_uuid']), (string) $row['scope'], (string) $row['facet'], (string) $row['feature_key'], (string) $row['visual_intent'], (string) $row['state'], is_string($media) && strlen($media) === 16 ? UuidCodec::fromBinary($media) : null, ((int) ($row['selected_media_revision'] ?? 0)) > 0 ? (int) $row['selected_media_revision'] : null, $context, $provenance, (string) ($row['unresolved_reason'] ?? ''), (string) $row['semantic_fingerprint'], (string) $row['idempotency_fingerprint'], (int) $row['revision']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<mixed> $rows @return list<VisualSupportRequirement> */
    private function hydrateList(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) { $item = $this->hydrate($row); if ($item !== null) $items[] = $item; }
        return $items;
    }
}
