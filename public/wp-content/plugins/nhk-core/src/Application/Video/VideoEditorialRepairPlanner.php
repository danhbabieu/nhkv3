<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoEditorialRepairPlanner
{
    public function plan(array $findings, array $package, int $round): array
    {
        if ($round >= 3) return [];
        $operations = [];
        foreach ($findings as $finding) {
            if (!is_array($finding) || ($finding['severity'] ?? '') !== VideoConstraintSeverity::REPAIRABLE) continue;
            $repair = (string) ($finding['repair'] ?? '');
            if (!in_array($repair, VideoEditorialAction::all(), true)) continue;
            $operations[] = ['action' => $repair, 'claim_id' => $finding['claim_id'] ?? null, 'code' => $finding['code'] ?? '', 'reason' => $finding['reason'] ?? ''];
        }
        return $operations;
    }

    public function apply(array $package, array $operations): array
    {
        $package['repair_log'] = array_values((array) ($package['repair_log'] ?? []));
        foreach ($operations as $operation) {
            $action = (string) ($operation['action'] ?? '');
            if ($action === VideoEditorialAction::REMOVE_UNSUPPORTED) {
                $claimId = (string) ($operation['claim_id'] ?? '');
                foreach ((array) ($package['claims'] ?? []) as $claim) {
                    if (!is_array($claim) || (string) ($claim['id'] ?? $claim['claim_id'] ?? '') !== $claimId) continue;
                    $text = trim((string) ($claim['text'] ?? ''));
                    if ($text !== '') $package['body'] = trim((string) preg_replace('/[^.!?。！？]*' . preg_quote($text, '/') . '[^.!?。！？]*[.!?。！？]?/u', '', (string) ($package['body'] ?? '')));
                }
            } elseif ($action === VideoEditorialAction::NARROW_TITLE) {
                $title = trim((string) ($package['title'] ?? ''));
                if ($title !== '' && !str_starts_with($title, 'Video về')) $package['title'] = 'Video về ' . $title;
            } elseif ($action === VideoEditorialAction::REPLACE_ALTERNATE_KNOWLEDGE && isset($package['alternate_knowledge'])) {
                $package['selected_knowledge'] = $package['alternate_knowledge'];
            } elseif ($action === VideoEditorialAction::ATTRIBUTE_AND_SCOPE || $action === VideoEditorialAction::NARROW_SCOPE || $action === VideoEditorialAction::QUALIFY_INFERENCE) {
                $package['body'] = trim('Theo phạm vi được ghi nhận, ' . (string) ($package['body'] ?? ''));
            }
            $package['repair_log'][] = ['action' => $action, 'code' => (string) ($operation['code'] ?? ''), 'round' => count($package['repair_log']) + 1];
        }
        return $package;
    }
}
