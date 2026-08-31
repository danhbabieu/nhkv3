<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Governance\GovernanceAuditSink;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbAuditSink implements GovernanceAuditSink
{
    public function record(string $event, Proposal $proposal): void
    {
        $events = ['created'=>'ProposalCreated','submitted'=>'ProposalSubmitted','approved'=>'ProposalApproved','rejected'=>'ProposalRejected','cancelled'=>'ProposalCancelled','superseded'=>'ProposalSuperseded','applied'=>'ApplySucceeded'];
        $this->recordEvent($events[$event] ?? $event, 'proposal', $proposal->id, $proposal->actor !== null ? (int) $proposal->actor : null, ['revision' => $proposal->revision, 'state' => $proposal->state->value, 'fingerprint' => $proposal->contentFingerprint]);
    }

    public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'nhk_audit_events (event_uuid,event_type,object_type,object_key,actor_user_id,context_json,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', UuidCodec::toBinary(UuidCodec::newV7()), substr($eventType, 0, 96), substr($objectType, 0, 64), substr($objectKey, 0, 191), $actorUserId, wp_json_encode($context, JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s.u')));
    }
}
