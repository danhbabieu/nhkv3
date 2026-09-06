<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Governance\Exception\GovernancePermissionDenied;

final class GovernanceCapabilities
{
    public const ALL = ['nhk_view_governance','nhk_create_proposals','nhk_submit_proposals','nhk_approve_proposals','nhk_apply_proposals','nhk_ingest_articles','nhk_curate_dictionary','nhk_manage_public_urls'];
    public static function register(): void {
        foreach (self::ALL as $capability) {
            $role = get_role('administrator');
            if ($role) $role->add_cap($capability);
        }
    }
    public static function require(string $capability): void {
        if (!current_user_can($capability)) throw new GovernancePermissionDenied('Governance capability required: '.$capability);
    }
}
