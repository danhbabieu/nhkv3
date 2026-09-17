<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Media\{MediaBatchUploadService, MediaBindingService};
use NHK\Core\Application\Mcp\McpGovernanceHandler;

/** Admin adapter for the existing MediaUsage owner and Governance pipeline. */
final class AdminMediaUsageApi
{
    public function __construct(private MediaBindingService $mediaBinding, private McpGovernanceHandler $governance, private ?MediaBatchUploadService $uploads = null) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/admin/media/upload', ['methods' => 'POST', 'permission_callback' => [$this, 'canMutate'], 'callback' => [$this, 'upload']]);
        register_rest_route('nhk/v1', '/admin/media/usage', ['methods' => 'POST', 'permission_callback' => [$this, 'canMutate'], 'callback' => [$this, 'usage']]);
    }

    public function canMutate(): bool
    {
        return current_user_can('upload_files') && current_user_can('nhk_internal_content_operations') && current_user_can('nhk_create_proposals');
    }

    public function upload(\WP_REST_Request $request): array|\WP_Error
    {
        if (!$this->uploads instanceof MediaBatchUploadService) return new \WP_Error('nhk_media_upload_unavailable', 'Media upload boundary chưa sẵn sàng.', ['status' => 503]);
        try {
            $params = $request->get_json_params();
            $params = is_array($params) ? $params : $request->get_params();
            return $this->uploads->upload((string) ($params['idempotency_key'] ?? 'admin-media-' . wp_generate_uuid4()), (array) ($params['metadata'] ?? ['description' => 'NHK V3 Media']), $request->get_file_params(), (array) ($params['items'] ?? [['title' => 'NHK V3 Media']]));
        } catch (\Throwable $error) {
            return new \WP_Error('nhk_media_upload_failed', $error->getMessage(), ['status' => 400]);
        }
    }

    public function usage(\WP_REST_Request $request): array|\WP_Error
    {
        $input = $request->get_json_params();
        if (!is_array($input)) return new \WP_Error('nhk_media_usage_invalid', 'MediaUsage request không hợp lệ.', ['status' => 400]);
        try {
            $media = $this->mediaBinding->resolveMediaReference((array) ($input['media'] ?? []));
            $target = is_array($input['target'] ?? null) ? $input['target'] : [];
            $targetType = strtolower(trim((string) ($target['type'] ?? '')));
            $targetUuid = null;
            if ($targetType !== 'wp_post') {
                $resolved = $this->mediaBinding->resolveTargetReference($target);
                $target = ['type' => $resolved->entityType, 'id' => $resolved->canonicalId];
                $targetUuid = $resolved->canonicalId;
            }
            $operation = strtolower(trim((string) ($input['operation'] ?? '')));
            $proposal = $this->governance->createFromArguments([
                'operation' => $operation,
                'entity_type' => 'media',
                'subject_id' => $media->canonicalId,
                'target_uuid' => $targetUuid,
                'expected_revision' => $operation === 'representative_bind' ? $media->revision : null,
                'idempotency_key' => (string) ($input['idempotency_key'] ?? ''),
                'target' => $target,
                'payload' => array_replace($input, ['media' => ['id' => $media->canonicalId], 'target' => $target]),
            ]);
            return $this->governance->ingestFromArguments([
                'operation' => $proposal->operation, 'entity_type' => $proposal->entityType, 'subject_id' => $proposal->subjectId,
                'target_uuid' => $proposal->targetUuid, 'expected_revision' => $proposal->expectedRevision, 'payload' => $proposal->payload,
                'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint,
                'idempotency_key' => $proposal->idempotencyKey, 'target' => $target,
            ]);
        } catch (\Throwable $error) {
            return new \WP_Error('nhk_media_usage_failed', $error->getMessage(), ['status' => 400]);
        }
    }
}
