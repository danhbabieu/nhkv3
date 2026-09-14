<?php
$items = is_array($args['items'] ?? null) ? $args['items'] : [];
if ($items === []) return;
?>
<nav class="breadcrumbs" aria-label="Đường dẫn"><ol><li><a href="<?php echo esc_url(home_url('/')); ?>">Trang chủ</a></li><?php foreach ($items as $item): if (!is_array($item) || trim((string) ($item['label'] ?? $item['name'] ?? '')) === '') continue; $label = (string) ($item['label'] ?? $item['name']); $url = nhk_v3_public_url($item['url'] ?? null); ?><li aria-current="<?php echo $url === '' ? 'page' : 'false'; ?>"><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a><?php else: ?><span><?php echo esc_html($label); ?></span><?php endif; ?></li><?php endforeach; ?></ol></nav>
