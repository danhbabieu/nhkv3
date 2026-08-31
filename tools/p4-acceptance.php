<?php
declare(strict_types=1);

if (getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test' || getenv('NHK_WP_TEST_PATH') !== 'public') {
    fwrite(STDERR, "P4 acceptance requires NHK_WP_TEST_DB=nhk_v3_test and NHK_WP_TEST_PATH=public; refusing to run with skipped DB tests.\n");
    exit(2);
}
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/bin/phpunit').' --configuration '.escapeshellarg(__DIR__.'/../phpunit.xml.dist');
passthru($command, $status);
exit($status);
