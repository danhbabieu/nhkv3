<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Governance\Proposal;

final class AdminGovernanceAdapter
{
    /** @return array<string,mixed> */
    public function humanize(Proposal $proposal): array
    {
        $operation = $proposal->operation;
        $labels = ['create' => 'Tạo', 'ingest' => 'Tiếp nhận', 'update' => 'Cập nhật', 'relation_create' => 'Gắn quan hệ', 'relation_retire' => 'Gỡ quan hệ', 'retire' => 'Ngừng sử dụng', 'reactivate' => 'Kích hoạt lại'];
        $subject = $proposal->entityType !== null && $proposal->entityType !== '' ? $proposal->entityType : 'đối tượng';
        $state = $proposal->state->value;
        $userState = match (strtolower($state)) { 'submitted' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'applied' => 'Đã Apply', 'rejected' => 'Bị từ chối', 'draft' => 'Bản nháp', default => 'Bị chặn' };
        return ['id' => $proposal->id, 'subject' => $proposal->subjectId, 'operation' => $operation, 'summary' => ($labels[$operation] ?? $operation) . ' ' . $subject, 'state' => $state, 'state_label' => $userState, 'technical' => ['proposal_uuid' => $proposal->id, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint, 'expected_revision' => $proposal->expectedRevision, 'payload' => $proposal->payload]];
    }
}
