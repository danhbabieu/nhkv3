<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

final class AdminReadBackPanel
{
    /** @param array<string,array{state:string,label:string,reason?:string}> $layers */
    public static function render(array $layers): void
    {
        echo '<section class="nhk-admin-readback" aria-labelledby="nhk-admin-readback-heading"><h3 id="nhk-admin-readback-heading">Đọc lại sau thao tác</h3><div class="nhk-admin-readback__grid">';
        foreach ($layers as $key => $layer) {
            $label = (string) ($layer['label'] ?? ucfirst((string) $key));
            self::item($label, (string) ($layer['state'] ?? 'unavailable'), isset($layer['reason']) ? (string) $layer['reason'] : null);
        }
        echo '</div></section>';
    }

    /** Render the derived owner-completion packet without collapsing it into ProposalState. */
    public static function renderCompletion(array $packet): void
    {
        $proposal = strtolower(trim((string) ($packet['proposal_state'] ?? '')));
        $layers = [];
        if ($proposal !== '') $layers['proposal'] = ['label' => 'Proposal', 'state' => $proposal === 'applied' ? 'applied' : $proposal, 'reason' => 'Lifecycle Governance'];
        $layers['canonical'] = ['label' => 'Canonical owner', 'state' => strtolower((string) ($packet['canonical_state'] ?? 'unavailable'))];
        $layers['dependencies'] = ['label' => 'Dependencies', 'state' => strtolower((string) ($packet['dependency_state'] ?? 'unavailable'))];
        $layers['relations'] = ['label' => 'Relations / MediaUsage', 'state' => strtolower((string) ($packet['relation_or_usage_state'] ?? 'unavailable'))];
        $layers['public'] = ['label' => 'Public projection', 'state' => strtolower((string) ($packet['public_state'] ?? 'unavailable'))];
        $layers['frontend'] = ['label' => 'Frontend', 'state' => strtolower((string) ($packet['frontend_state'] ?? 'unavailable'))];
        self::render($layers);
        $blockers = array_values(array_filter(array_map('strval', (array) ($packet['blockers'] ?? []))));
        if ($blockers !== []) echo '<p class="nhk-admin-readback__blockers"><strong>Blockers:</strong> ' . htmlspecialchars(implode(', ', $blockers), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    private static function item(string $label, string $state, ?string $reason): void
    {
        echo '<div class="nhk-admin-readback__item">';
        AdminStatusBadge::render($label, $state, $reason);
        echo '</div>';
    }
}
