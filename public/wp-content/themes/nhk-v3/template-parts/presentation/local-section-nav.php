<?php
$sections = is_array($args['sections'] ?? null) ? $args['sections'] : [];
$links = [];
foreach ($sections as $id => $section) {
    if (is_string($section)) $section = ['label' => $section, 'available' => true];
    if (!is_array($section) || ($section['available'] ?? false) !== true) continue;
    $label = trim((string) ($section['label'] ?? ''));
    if ($label !== '') $links[(string) $id] = $label;
}
if ($links === []) return;
?>
<nav class="local-section-nav" aria-label="Đi tới các phần trong hồ sơ"><ul><?php foreach ($links as $id => $label): ?><li><a href="#<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></a></li><?php endforeach; ?></ul></nav>
