<?php
declare(strict_types=1);

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\DemoCutoverRunner;
use NHK\Core\Infrastructure\Demo\LocalCutoverAdapters;

$root = dirname(__DIR__);
$target = null;
$pack = null;
$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo "Usage: nhk-demo-cutover --target=demo.1945.vn --pack=<id> [--root=<repo>] [--json]\n"; exit(0); }
    if ($argument === '--json') { $json = true; continue; }
    if (str_starts_with($argument, '--target=')) { $target = substr($argument, 9); continue; }
    if (str_starts_with($argument, '--pack=')) { $pack = substr($argument, 7); continue; }
    if (str_starts_with($argument, '--root=')) { $root = rtrim(substr($argument, 7), '/'); continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n"); exit(64);
}
if ($target === null || $pack === null) { fwrite(STDERR, "TARGET_AND_PACK_REQUIRED\n"); exit(64); }

require_once $root . '/vendor/autoload.php';
$context = new DemoCutoverContext($target, $pack, trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null')), bin2hex(random_bytes(8)));
$result = (new DemoCutoverRunner(LocalCutoverAdapters::forRepository($root)))->prepare($context);
$payload = ['status' => $result->status, 'reason_code' => $result->reasonCode, 'proposal_id' => $result->proposalId, 'proposal_fingerprint' => $result->proposalFingerprint];
echo $json ? json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL : strtoupper($result->status) . ': ' . ($result->reasonCode ?? 'AWAITING_APPROVAL') . PHP_EOL;
exit($result->status === 'awaiting_approval' ? 0 : 2);
