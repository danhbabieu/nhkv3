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
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'nhk_audit_events (event_uuid,event_type,object_type,object_key,actor_user_id,context_json,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', UuidCodec::toBinary(UuidCodec::newV7()), $event, 'proposal', $proposal->id, $proposal->actor, wp_json_encode(['revision' => $proposal->revision, 'state' => $proposal->state->value, 'fingerprint' => $proposal->contentFingerprint], JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s.u')));
    }
}
