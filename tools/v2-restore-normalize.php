<?php
declare(strict_types=1);

/**
 * Stream a V2 SQL backup through the reviewed MariaDB compatibility transform.
 * The source dump is never modified and the normalized SQL is written to
 * stdout so it can be reviewed or piped to a guarded staging database.
 */
$input = null;
$quiet = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--input=')) $input = substr($argument, 8);
    if ($argument === '--quiet') $quiet = true;
}
if ($input === null || $input === '' || !is_readable($input)) {
    fwrite(STDERR, "Usage: php tools/v2-restore-normalize.php --input=/path/to/v2.sql [--quiet]\n");
    exit(2);
}

$handle = fopen($input, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open V2 backup.\n");
    exit(2);
}
$removedGtid = 0;
$normalizedDefaults = 0;
while (($line = fgets($handle)) !== false) {
    if (stripos($line, 'GTID_PURGED') !== false) {
        $removedGtid++;
        continue;
    }
    $original = $line;
    $line = str_replace(
        ["`proposal` longtext NOT NULL DEFAULT 'PENDING_REVIEW'", "`definition` longtext NOT NULL DEFAULT 'ACTIVE'"],
        ["`proposal` longtext NOT NULL", "`definition` longtext NOT NULL"],
        $line,
    );
    if ($line !== $original) $normalizedDefaults++;
    if (preg_match('/`[^`]+`\s+(?:tinytext|text|mediumtext|longtext|blob|json)[^,]*\s+NOT NULL\s+DEFAULT\s+(?!NULL)/i', $line) === 1) {
        fclose($handle);
        fwrite(STDERR, "Unsupported non-NULL text/blob/json default remains in the normalized dump.\n");
        exit(1);
    }
    fwrite(STDOUT, $line);
}
fclose($handle);
if (!$quiet) fwrite(STDERR, "Normalized {$normalizedDefaults} text defaults; removed {$removedGtid} GTID metadata lines.\n");
