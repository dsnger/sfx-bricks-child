<?php

declare(strict_types=1);

// Schema: the administrator-only per-page JSON-LD field (spec § "Per-page custom field", § "Output encoding").
// Plugin constants cannot be undefined, so every case runs in its own PHP process:
// `php tests/contact-schema-page-field-test.php <case>` runs one case with that case's constants.

use SFX\ContactInfos\Schema\PageField;
use SFX\ContactInfos\Schema\Settings;

/** case => the plugin constants defined in its process */
const CASES = [
    'admin_can_save'                          => [],
    'editor_with_unfiltered_html_cannot_save' => [],
    'author_cannot_save'                      => [],
    'save_without_nonce_keeps_value'          => [],
    'save_for_another_post_ignored'           => [],
    'autosave_and_revision_ignored'           => [],
    'whitespace_deletes'                      => [],
    'invalid_json_rejected_old_kept'          => [],
    'scalar_json_rejected'                    => [],
    'array_with_non_object_rejected'          => [],
    'over_64kb_rejected'                      => [],
    'output_hex_escapes'                      => [],
    'password_protected_prints_nothing'       => [],
    'draft_prints_nothing'                    => [],
    'non_singular_prints_nothing'             => [],
    'feature_off_prints_nothing'              => [],
    'conflict_mode_still_prints'              => ['SEOPRESS_VERSION'],
    'meta_box_only_for_admins'                => [],
    'meta_registered_without_rest'            => [],
];

$case = $argv[1] ?? null;
if ($case === null) {
    $failed = 0;
    foreach (array_keys(CASES) as $name) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name) . ' 2>&1', $out, $code);
        if ($code !== 0 || end($out) !== "ok {$name}") {
            $failed++;
            echo "FAIL case {$name} (exit {$code}):\n  " . implode("\n  ", $out) . "\n";
        }
    }
    if ($failed > 0) {
        echo "Tests failed: {$failed}\n";
        exit(1);
    }
    echo 'contact-schema-page-field-test: PASS (' . count(CASES) . " cases)\n";
    exit(0);
}
if (!array_key_exists($case, CASES)) {
    echo "unknown case {$case}\n";
    exit(1);
}

foreach (CASES[$case] as $constant) {
    define($constant, '1.0');
}
require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Settings.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Mode.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Output.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/PageField.php';

const ROLES = [
    'administrator'          => ['manage_options', 'unfiltered_html', 'edit_post'],
    'editor_unfiltered_html' => ['unfiltered_html', 'edit_post'],
    'author'                 => ['edit_post'],
];
$test_role = 'administrator';
function current_user_can(string $cap, ...$args): bool
{
    global $test_role;
    return in_array($cap, ROLES[$test_role], true);
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'good' && $action === 'sfx_page_schema_save' ? 1 : false;
}
function get_current_user_id(): int
{
    return 7;
}
// Core: wp_unslash() = stripslashes_deep(), wp_slash() = addslashes(); update_post_meta() unslashes its value.
function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_slash($value)
{
    return is_string($value) ? addslashes($value) : $value;
}
function update_post_meta($post_id, $key, $value)
{
    global $test_meta;
    $test_meta[$post_id][$key] = [wp_unslash($value)];
    return true;
}
function delete_post_meta($post_id, $key)
{
    global $test_meta;
    unset($test_meta[$post_id][$key]);
    return true;
}
function wp_is_post_revision($id)
{
    return $GLOBALS['test_revision'] ?? false;
}
function wp_is_post_autosave($id)
{
    return $GLOBALS['test_autosave'] ?? false;
}
function wp_json_encode($value, $flags = 0, $depth = 512)
{
    return json_encode($value, $flags, $depth);
}
function is_singular(): bool
{
    return $GLOBALS['test_singular'] ?? true;
}
function get_queried_object_id(): int
{
    return 200;
}
function post_password_required($post = null): bool
{
    return $GLOBALS['test_password_required'] ?? false;
}
function wp_nonce_field($action, $name): void
{
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="nonce-for-' . esc_attr($action) . '" />';
}
function esc_textarea($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default'): void
{
    $GLOBALS['test_meta_boxes'][$id] = ['title' => $title, 'screen' => $screen];
}
function register_post_meta($post_type, $meta_key, array $args): bool
{
    $GLOBALS['test_registered_meta'][$post_type][$meta_key] = $args;
    return true;
}

const OLD = '{"@context":"https://schema.org","@type":"Event","name":"Old"}';
const NEW_VALUE = '{"@context":"https://schema.org","@type":"Event","name":"Say \"hi\" \\\\ there"}';

function fixture(): void
{
    global $test_posts, $test_meta, $test_options;
    $test_posts[200] = sfx_make_post(200, 'page', 'publish', 'Page');
    $test_meta[200] = [PageField::META => [OLD]];
    $test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
    $_POST = [];
}

/** Simulates the edit form: PHP's request superglobals arrive slashed in WordPress. */
function submit(string $value, ?string $nonce = 'good'): void
{
    $_POST = ['sfx_page_schema' => addslashes($value), 'post_ID' => '200'];
    if ($nonce !== null) {
        $_POST['sfx_page_schema_nonce'] = $nonce;
    }
    PageField::save(200);
}

function stored(): ?string
{
    global $test_meta;
    return isset($test_meta[200][PageField::META]) ? $test_meta[200][PageField::META][0] : null;
}

function notice(): string
{
    ob_start();
    PageField::render_meta_box($GLOBALS['test_posts'][200]);
    return (string) ob_get_clean();
}

function assert_rejected(string $value, string $label): void
{
    submit($value);
    assert_same(OLD, stored(), "{$label}: old value kept");
    assert_same('reject', PageField::validate($value)['action'], "{$label}: validate rejects");
    $out = notice();
    assert_contains('notice-error', $out, "{$label}: message shown in the meta box");
    assert_contains('<textarea', $out, "{$label}: inside the meta box");
    assert_true(!str_contains(notice(), 'notice-error'), "{$label}: notice shown once");
}

function case_admin_can_save(): void
{
    submit("  " . NEW_VALUE . "\n");
    assert_same(NEW_VALUE, stored(), 'admin: trimmed value stored byte for byte (slashes survive)');
    assert_true(!str_contains(notice(), 'notice-error'), 'admin: no notice');
    submit('[{"@type":"Event"},{"@type":"Place"}]');
    assert_same('[{"@type":"Event"},{"@type":"Place"}]', stored(), 'admin: array of objects stored');
    assert_same(['action' => 'store', 'value' => '{}', 'error' => null], PageField::validate(' {} '), 'validate: empty object stored');
}

function case_editor_with_unfiltered_html_cannot_save(): void
{
    $GLOBALS['test_role'] = 'editor_unfiltered_html';
    assert_same(false, PageField::can_edit(200), 'editor: cannot edit');
    submit(NEW_VALUE);
    assert_same(OLD, stored(), 'editor: forged POST with a valid nonce leaves the value');
    submit('   ');
    assert_same(OLD, stored(), 'editor: cannot delete either');
    assert_same(false, PageField::auth(true, PageField::META, 200, 7, 'edit_post_meta', []), 'editor: meta auth denied');
}

function case_author_cannot_save(): void
{
    $GLOBALS['test_role'] = 'author';
    assert_same(false, PageField::can_edit(200), 'author: cannot edit');
    submit(NEW_VALUE);
    assert_same(OLD, stored(), 'author: value unchanged');
    $GLOBALS['test_role'] = 'administrator';
    assert_same(true, PageField::auth(false, PageField::META, 200, 7, 'edit_post_meta', []), 'admin: meta auth allowed');
}

function case_save_without_nonce_keeps_value(): void
{
    submit(NEW_VALUE, null);
    assert_same(OLD, stored(), 'no nonce: untouched');
    submit('', null);
    assert_same(OLD, stored(), 'no nonce, empty field: not deleted');
    submit(NEW_VALUE, 'bad');
    assert_same(OLD, stored(), 'bad nonce: untouched');
    $_POST = ['sfx_page_schema_nonce' => 'good'];
    PageField::save(200);
    assert_same(OLD, stored(), 'nonce without the field: untouched');
}

function case_save_for_another_post_ignored(): void
{
    submit(NEW_VALUE);
    $_POST['post_ID'] = '999';
    $_POST['sfx_page_schema'] = addslashes('{"@type":"Event"}');
    PageField::save(200);
    assert_same(NEW_VALUE, stored(), 'other post updated in the same request: untouched');
    unset($_POST['post_ID']);
    PageField::save(200);
    assert_same(NEW_VALUE, stored(), 'no post_ID: untouched');
}

function case_autosave_and_revision_ignored(): void
{
    $GLOBALS['test_autosave'] = 201;
    submit(NEW_VALUE);
    assert_same(OLD, stored(), 'autosave: untouched');
    $GLOBALS['test_autosave'] = false;
    $GLOBALS['test_revision'] = 200;
    submit(NEW_VALUE);
    assert_same(OLD, stored(), 'revision: untouched');
}

function case_whitespace_deletes(): void
{
    assert_same(['action' => 'delete', 'value' => '', 'error' => null], PageField::validate(" \n\t "), 'validate: whitespace deletes');
    submit(" \r\n ");
    assert_same(null, stored(), 'whitespace: meta deleted');
    assert_true(!str_contains(notice(), 'notice-error'), 'whitespace: no notice');
}

function case_invalid_json_rejected_old_kept(): void
{
    assert_rejected('{"@type": "Event",}', 'invalid JSON');
    assert_rejected('<script>alert(1)</script>', 'markup');
}

function case_scalar_json_rejected(): void
{
    foreach (['"text"', '42', 'true', 'null'] as $scalar) {
        assert_rejected($scalar, "scalar {$scalar}");
    }
}

function case_array_with_non_object_rejected(): void
{
    assert_rejected('[{"@type":"Event"}, "x"]', 'array with a string');
    assert_rejected('[{"@type":"Event"}, []]', 'array with an array');
    assert_rejected('[]', 'empty array');
}

function case_over_64kb_rejected(): void
{
    $wrap = '{"a":""}';
    $fits = '{"a":"' . str_repeat('x', 65536 - strlen($wrap)) . '"}';
    assert_same(65536, strlen($fits), 'fixture: exactly 64 KB');
    assert_same('store', PageField::validate("  {$fits}  ")['action'], '64 KB exactly, surrounding whitespace not counted: stored');
    $multibyte = '{"a":"' . str_repeat('ä', (65536 - strlen($wrap)) / 2 + 1) . '"}';
    assert_same(true, mb_strlen($multibyte) < 65536 && strlen($multibyte) > 65536, 'fixture: under 64K characters, over 64 KB');
    assert_rejected($multibyte, 'over 64 KB in bytes');
    assert_rejected('{"a":"' . str_repeat('x', 65536) . '"}', 'over 64 KB');
}

function case_output_hex_escapes(): void
{
    global $test_meta;
    $test_meta[200][PageField::META] = ['{"@type":"Event","name":"</script><script>alert(1)</script> <!-- & x","sub":{},"list":[]}'];
    $out = PageField::render_for_request();
    assert_same(1, substr_count($out, '<script'), 'escape: exactly one opening script tag');
    assert_same(1, substr_count($out, '</script>'), 'escape: exactly one closing script tag');
    assert_same(0, strpos($out, '<script type="application/ld+json">'), 'escape: the one script is the JSON-LD one');
    $body = substr($out, strlen('<script type="application/ld+json">'), -strlen("</script>\n"));
    assert_same(false, str_contains($body, '<'), 'escape: no "<" inside the JSON');
    assert_same(false, str_contains($body, '&'), 'escape: no "&" inside the JSON');
    assert_contains('\\u003C!--', $body, 'escape: "<!--" hex-encoded');
    assert_same(
        ['@type' => 'Event', 'name' => '</script><script>alert(1)</script> <!-- & x', 'sub' => [], 'list' => []],
        json_decode($body, true),
        'escape: decodes back to the submitted data'
    );
    assert_contains('"sub":{},"list":[]', $body, 'encode: empty object stays an object');
    ob_start();
    PageField::print_for_request();
    assert_same($out, ob_get_clean(), 'wp_head callback echoes the same');
}

function case_password_protected_prints_nothing(): void
{
    assert_contains('"name":"Old"', PageField::render_for_request(), 'control: published page prints');
    $GLOBALS['test_password_required'] = true;
    assert_same('', PageField::render_for_request(), 'password required: nothing');
}

function case_draft_prints_nothing(): void
{
    $GLOBALS['test_posts'][200]->post_status = 'draft';
    assert_same('', PageField::render_for_request(), 'draft: nothing');
    $GLOBALS['test_posts'][200]->post_status = 'private';
    assert_same('', PageField::render_for_request(), 'private: nothing');
}

function case_non_singular_prints_nothing(): void
{
    $GLOBALS['test_singular'] = false;
    assert_same('', PageField::render_for_request(), 'archive: nothing');
}

function case_feature_off_prints_nothing(): void
{
    global $test_options, $test_meta;
    $test_options[Settings::OPTION]['enabled'] = false;
    assert_same('', PageField::render_for_request(), 'off: nothing');
    $test_options[Settings::OPTION]['enabled'] = true;
    $test_meta[200][PageField::META] = ['"not stored through the field"'];
    assert_same('', PageField::render_for_request(), 'stored value of the wrong shape: nothing');
    $test_meta[200][PageField::META] = ['{broken'];
    assert_same('', PageField::render_for_request(), 'stored broken JSON: nothing');
    unset($test_meta[200][PageField::META]);
    assert_same('', PageField::render_for_request(), 'no value: nothing');
}

function case_conflict_mode_still_prints(): void
{
    assert_same('conflict', \SFX\ContactInfos\Schema\Mode::detect()['mode'], 'conflict: mode');
    assert_contains('"name":"Old"', PageField::render_for_request(), 'conflict: field still printed');
}

function case_meta_box_only_for_admins(): void
{
    global $wp_post_types;
    foreach ($wp_post_types as $object) {
        $object->show_ui = true;
    }
    $page = $GLOBALS['test_posts'][200];
    PageField::add_meta_box('page', $page);
    assert_same('Structured data (JSON-LD)', $GLOBALS['test_meta_boxes']['sfx_page_schema']['title'] ?? null, 'meta box: added for admin');
    $GLOBALS['test_meta_boxes'] = [];
    $wp_post_types['attachment'] = new WP_Post_Type('attachment', true);
    $wp_post_types['attachment']->show_ui = true;
    PageField::add_meta_box('attachment', $page);
    assert_same([], $GLOBALS['test_meta_boxes'], 'meta box: not on attachments');
    PageField::add_meta_box('sfx_contact_info', $page);
    assert_same([], $GLOBALS['test_meta_boxes'], 'meta box: not on a non-public post type');
    $GLOBALS['test_role'] = 'editor_unfiltered_html';
    PageField::add_meta_box('page', $page);
    assert_same([], $GLOBALS['test_meta_boxes'], 'meta box: not for an editor');

    ob_start();
    PageField::render_meta_box($page);
    $html = (string) ob_get_clean();
    assert_same('', $html, 'render: nothing for an editor');
    $GLOBALS['test_role'] = 'administrator';
    $GLOBALS['test_meta'][200][PageField::META] = ['{"name":"</textarea><b>x"}'];
    ob_start();
    PageField::render_meta_box($page);
    $html = (string) ob_get_clean();
    assert_contains('name="sfx_page_schema_nonce" value="nonce-for-sfx_page_schema_save"', $html, 'render: nonce field');
    assert_contains('&lt;/textarea&gt;&lt;b&gt;x', $html, 'render: stored value escaped');
}

function case_meta_registered_without_rest(): void
{
    PageField::register_meta();
    assert_same(
        ['show_in_rest' => false, 'single' => true, 'type' => 'string', 'auth_callback' => [PageField::class, 'auth']],
        $GLOBALS['test_registered_meta'][''][PageField::META] ?? null,
        'meta: registered for every post type, not in REST, admin-only auth'
    );
}

fixture();
("case_{$case}")();

global $failures;
if ($failures > 0) {
    exit(1);
}
echo "ok {$case}\n";
