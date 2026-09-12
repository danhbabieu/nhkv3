<?php
declare(strict_types=1);

if (getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test' || getenv('NHK_WP_TEST_PATH') !== 'public') {
    fwrite(STDERR, "P4 acceptance requires NHK_WP_TEST_DB=nhk_v3_test and NHK_WP_TEST_PATH=public; refusing to run with skipped DB tests.\n");
    exit(2);
}
$host = getenv('NHK_WP_TEST_DB_HOST') ?: '127.0.0.1';
$user = getenv('NHK_WP_TEST_DB_USER') ?: 'root';
$password = getenv('NHK_WP_TEST_DB_PASSWORD') ?: '';
$connection = function_exists('mysqli_init') ? mysqli_init() : false;
if ($connection === false || !@mysqli_real_connect($connection, $host, $user, $password, 'nhk_v3_test')) {
    fwrite(STDERR, "P4 acceptance database preflight failed: unable to connect to nhk_v3_test at {$host}.\n");
    exit(3);
}
mysqli_close($connection);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/bin/phpunit').' --configuration '.escapeshellarg(__DIR__.'/../phpunit.xml.dist').' --testsuite '.escapeshellarg('NHK Integration');
passthru($command, $status);
exit($status);
