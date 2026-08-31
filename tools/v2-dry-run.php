<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use NHK\Core\Application\Migration\DryRunService;

$input = null;
foreach (array_slice($argv, 1) as $argument) if (str_starts_with($argument, '--input=')) $input = substr($argument, 8);
if ($input === null || $input === '' || ($input !== '-' && !is_readable($input))) {
    fwrite(STDERR, "Usage: php tools/v2-dry-run.php --input=/path/to/read-only-inventory.json\n");
    exit(2);
}
$decoded = json_decode((string) file_get_contents($input === '-' ? 'php://stdin' : $input), true);
if (!is_array($decoded) || !is_array($decoded['records'] ?? null)) {
    fwrite(STDERR, "Input must be JSON object with a records array.\n");
    exit(2);
}
echo json_encode((new DryRunService())->run($decoded['records']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
