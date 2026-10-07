<?php
$title = trim((string) ($args['title'] ?? '')) ?: 'Chưa có nội dung phù hợp';
$message = trim((string) ($args['message'] ?? ''));
$class = trim((string) ($args['class'] ?? 'empty')) ?: 'empty';
?>
<div class="<?php echo esc_attr($class); ?>" role="status"><h2><?php echo esc_html($title); ?></h2><?php if ($message !== ''): ?><p><?php echo esc_html($message); ?></p><?php endif; ?></div>
