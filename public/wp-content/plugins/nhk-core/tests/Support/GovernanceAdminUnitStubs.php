<?php
declare(strict_types=1);

if (!function_exists('current_user_can')) { function current_user_can($capability) { return $GLOBALS['nhk_test_caps'][$capability] ?? false; } }
if (!function_exists('wp_die')) { function wp_die($message, $title = '', $args = []) { throw new \RuntimeException(is_scalar($message) ? (string) $message : 'wp_die', (int) ($args['response'] ?? 500)); } }
if (!function_exists('check_admin_referer')) { function check_admin_referer($action) { if (($GLOBALS['nhk_test_nonce'] ?? true) !== true) throw new \RuntimeException('nonce', 403); return 1; } }
if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($location) { throw new \NHK\Tests\Unit\Admin\Redirected((string) $location); } }
if (!function_exists('admin_url')) { function admin_url($path = '') { return '/wp-admin/' . ltrim((string) $path, '/'); } }
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url = '') { return (string) $url . '?' . http_build_query((array) $args); } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce">'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('sanitize_key')) { function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($value) { return trim((string) $value); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($value) { return trim((string) $value); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); } }
if (!function_exists('esc_html')) { function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($value) { return esc_attr($value); } }
if (!function_exists('selected')) { function selected($selected, $current, $echo = true) { $value = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ($echo) echo $value; return $value; } }
