<?php

declare(strict_types=1);

// Just enough WordPress for functions.php to load in a bare PHP process, with the theme
// folder at $GLOBALS['sfx_theme_dir']. add_action() records hook names in
// $GLOBALS['sfx_actions']; nothing is ever run.

$GLOBALS['sfx_actions'] = [];
function add_action(string $hook, $cb, int $prio = 10, int $args = 1): bool { $GLOBALS['sfx_actions'][] = $hook; return true; }
function add_filter(string $hook, $cb, int $prio = 10, int $args = 1): bool { return true; }
function get_stylesheet_directory(): string { return $GLOBALS['sfx_theme_dir']; }
function get_stylesheet(): string { return 'sfx-bricks-child'; }
function is_admin(): bool { return false; }
class WP_Theme { public function get(string $k): string { return '0.0.0'; } }
function wp_get_theme($s = null): WP_Theme { return new WP_Theme(); }
