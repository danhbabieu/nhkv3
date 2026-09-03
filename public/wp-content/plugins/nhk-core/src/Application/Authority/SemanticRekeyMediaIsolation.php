<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

/** Explicit boundary: semantic stable-key operations have no media path side effects. */
final class SemanticRekeyMediaIsolation
{
    /** @return array{old_stable_key:string,new_stable_key:string} */
    public static function plan(string $oldStableKey, string $newStableKey): array
    {
        self::assertSemanticOnly(['old_stable_key' => $oldStableKey, 'new_stable_key' => $newStableKey]);
        return ['old_stable_key' => $oldStableKey, 'new_stable_key' => $newStableKey];
    }

    /** @param array<string,mixed> $changes */
    public static function assertSemanticOnly(array $changes): void
    {
        foreach (['file_path', 'attached_file', 'attachment_path', 'filesystem_path', 'metadata', 'derivatives'] as $field) {
            if (array_key_exists($field, $changes)) throw new \InvalidArgumentException('Semantic rekey cannot mutate WordPress media paths.');
        }
    }
}
