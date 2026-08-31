<?php
declare(strict_types=1);

$base = 'http://localhost';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--base-url=')) $base = rtrim(substr($argument, 11), '/');
}

$routes = [
    '/' => 200,
    '/tri-thuc/' => 200,
    '/goc-chia-se/' => 200,
    '/brand/' => 200,
    '/model/' => 200,
    '/movement/' => 200,
    '/music/' => 200,
    '/component/' => 200,
    '/specimen/' => 200,
    '/product/' => 200,
    '/video/' => 200,
    '/thu-vien/' => 200,
    '/?s=watch' => 200,
    '/__nhk-route-must-404__/' => 404,
];
$failures = 0;
foreach ($routes as $route => $expected) {
    $url = $base . $route;
    $handle = curl_init($url);
    if ($handle === false) {
        fwrite(STDERR, "curl_init failed for {$url}\n");
        exit(2);
    }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($error !== '') {
        fwrite(STDERR, "FAIL {$route}: {$error}\n");
        $failures++;
        continue;
    }
    $ok = $status === $expected;
    echo ($ok ? 'PASS' : 'FAIL') . " {$route}: expected {$expected}, got {$status}\n";
    if (!$ok) $failures++;
}
exit($failures === 0 ? 0 : 1);
