<?php

declare(strict_types=1);

// Custom Scripts print raw JS on every page, so every capability of the post type must be admin-level.

define('ABSPATH', dirname(__DIR__) . '/');

$failures = 0;
$registered = [];
$filters = [];
$posts = [];

class WP_Post
{
    public function __construct(public int $ID, public string $post_type)
    {
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
    }
}
function __($text, $domain = 'default')
{
    return $text;
}
function add_action(...$args): bool
{
    return true;
}
function add_filter(string $hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $filters;
    $filters[$hook][] = $callback;
    return true;
}
function register_post_type(string $post_type, array $args = []): void
{
    global $registered;
    $registered[$post_type] = $args;
}
function register_meta(...$args): bool
{
    return true;
}
function get_post($id)
{
    global $posts;
    return $posts[$id] ?? null;
}

require dirname(__DIR__) . '/inc/MetaFieldManager.php';
require dirname(__DIR__) . '/inc/CustomScriptsManager/PostType.php';

use SFX\CustomScriptsManager\PostType;

PostType::init();
PostType::register_post_type();

// 1. Every primitive capability of the post type is manage_options — including the ones an Author holds.
$caps = $registered['sfx_custom_script']['capabilities'] ?? [];
foreach (['edit_posts', 'create_posts', 'publish_posts', 'edit_published_posts', 'delete_posts', 'delete_published_posts', 'edit_others_posts', 'delete_others_posts', 'read_private_posts', 'edit_private_posts', 'delete_private_posts'] as $cap) {
    assert_same('manage_options', $caps[$cap] ?? null, "1: {$cap} -> manage_options");
}

// 2. Meta caps on a custom script need manage_options, even for the script's own author.
$posts[7] = new WP_Post(7, 'sfx_custom_script');
$posts[8] = new WP_Post(8, 'post');
$map = $filters['map_meta_cap'][0] ?? null;
assert_same(true, is_callable($map), '2: map_meta_cap filter registered');
if (is_callable($map)) {
    foreach (['edit_post', 'read_post', 'delete_post', 'publish_post'] as $cap) {
        assert_same(['manage_options'], $map(['edit_posts'], $cap, 3, [7]), "2: {$cap} on a custom script");
    }
    assert_same(['edit_posts'], $map(['edit_posts'], 'edit_post', 3, [8]), '2: other post types untouched');
    assert_same(['edit_posts'], $map(['edit_posts'], 'edit_post', 3, []), '2: no post ID untouched');
}

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "custom-scripts-capabilities-test: PASS\n";
