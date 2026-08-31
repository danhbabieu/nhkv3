<?php
declare(strict_types=1);

$base = 'http://localhost';
$optionalRoutes = [];
$optionalRedirects = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--base-url=')) {
        $base = rtrim(substr($argument, 11), '/');
        continue;
    }
    foreach (['post-url', 'brand-url', 'model-url', 'movement-url', 'music-url', 'component-url', 'specimen-url', 'product-url', 'claim-url', 'media-url', 'video-url', 'comparison-url'] as $option) {
        $prefix = "--{$option}=";
        if (str_starts_with($argument, $prefix)) {
            $route = trim(substr($argument, strlen($prefix)));
            if ($route !== '') $optionalRoutes[$route] = 200;
            break;
        }
    }
    foreach (['brand-alias', 'model-alias'] as $option) {
        $prefix = "--{$option}=";
        if (str_starts_with($argument, $prefix)) {
            [$route, $target] = array_pad(explode('|', substr($argument, strlen($prefix)), 2), 2, '');
            $route = trim($route);
            $target = trim($target);
            if ($route !== '' && $target !== '') $optionalRedirects[$route] = $target;
            break;
        }
    }
}

$routes = [
    '/' => 200,
    '/tri-thuc/' => 200,
    '/tri-thuc/page/2/' => 200,
    '/goc-chia-se/' => 200,
    '/goc-chia-se/page/2/' => 200,
    '/brand/' => 200,
    '/thuong-hieu/' => 200,
    '/model/' => 200,
    '/movement/' => 200,
    '/music/' => 200,
    '/am-nhac/' => 200,
    '/component/' => 200,
    '/specimen/' => 200,
    '/hien-vat/' => 200,
    '/product/' => 200,
    '/knowledge/' => 200,
    '/knowledge/page/2/' => 200,
    '/video/' => 200,
    '/video/page/2/' => 200,
    '/thu-vien/' => 200,
    '/media/page/2/' => 200,
    '/wp-sitemap.xml' => 200,
    '/feed/' => 200,
    '/?s=watch' => 200,
    '/?s=odo&paged=2' => 200,
    '/category/uncategorized/' => 200,
    '/tim-kiem/?q=odo' => 301,
    '/comparison/' => 200,
    '/media/asset/00000000-0000-4000-8000-000000000000/' => 404,
    '/__nhk-route-must-404__/' => 404,
];
$routes = array_merge($routes, $optionalRoutes);
$routes = array_merge($routes, array_fill_keys(array_keys($optionalRedirects), 301));
$contentMarkers = [
    '/wp-sitemap.xml' => '<sitemapindex',
    '/feed/' => '<rss',
];
$locationMarkers = [
    '/tim-kiem/?q=odo' => '/?s=odo',
    ...$optionalRedirects,
];
$metadataMarkers = [
    '/tri-thuc/' => ['<title>Tri thức — Đồng Hồ Nhà Kho</title>', '<link rel="canonical" href="' . $base . '/tri-thuc/"'],
    '/goc-chia-se/' => ['<title>Góc chia sẻ — Đồng Hồ Nhà Kho</title>', '<link rel="canonical" href="' . $base . '/goc-chia-se/"'],
    '/category/uncategorized/' => ['<title>Chủ đề: Chưa phân loại — Đồng Hồ Nhà Kho</title>', '<link rel="canonical" href="' . $base . '/category/uncategorized/"'],
    '/__nhk-route-must-404__/' => ['<title>Không tìm thấy trang — Đồng Hồ Nhà Kho</title>', 'noindex, follow'],
];
$failures = 0;
foreach ($routes as $route => $expected) {
    $url = $base . $route;
    $handle = curl_init($url);
    if ($handle === false) {
        fwrite(STDERR, "curl_init failed for {$url}\n");
        exit(2);
    }
    $location = '';
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false, CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int { if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9)); return strlen($header); }, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($error !== '') {
        fwrite(STDERR, "FAIL {$route}: {$error}\n");
        $failures++;
        continue;
    }
    $marker = $contentMarkers[$route] ?? null;
    $hasMarker = $marker === null || (is_string($body) && str_contains($body, $marker));
    $expectedLocation = $locationMarkers[$route] ?? null;
    $hasLocation = $expectedLocation === null || str_contains($location, $expectedLocation);
    $metadataFailures = [];
    foreach ($metadataMarkers[$route] ?? [] as $metadataMarker) {
        if (!is_string($body) || !str_contains($body, $metadataMarker)) $metadataFailures[] = $metadataMarker;
    }
    $hasMetadata = $metadataFailures === [];
    $ok = $status === $expected && $hasMarker && $hasLocation && $hasMetadata;
    $detail = $status === $expected ? "expected {$expected}, got {$status}" : "expected {$expected}, got {$status}";
    if (!$hasMarker) $detail .= ", missing content marker {$marker}";
    if (!$hasLocation) $detail .= ", expected Location containing {$expectedLocation}, got {$location}";
    if (!$hasMetadata) $detail .= ', missing metadata marker(s) ' . implode(', ', $metadataFailures);
    echo ($ok ? 'PASS' : 'FAIL') . " {$route}: {$detail}\n";
    if (!$ok) $failures++;
}
exit($failures === 0 ? 0 : 1);
