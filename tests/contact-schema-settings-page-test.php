<?php

declare(strict_types=1);

// Schema: the settings screen (spec § "Settings and purge", § "Selection of contacts", § "Mode selection").
require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/OpeningHours.php';
require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Settings.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Selection.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/GraphBuilder.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Mode.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/SettingsPage.php';

use SFX\ContactInfos\Schema\Settings;
use SFX\ContactInfos\Schema\SettingsPage;

$test_can = true;
$test_nonce_ok = true;
$test_nonce_seen = [];
$test_redirect = null;

function current_user_can($cap, ...$args): bool
{
    global $test_can;
    return $cap === 'manage_options' && $test_can;
}
// Core's check_admin_referer() dies on a bad nonce; the stub throws so the test sees the stop.
function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    global $test_nonce_ok, $test_nonce_seen;
    $test_nonce_seen[] = $action;
    if (!$test_nonce_ok) {
        throw new RuntimeException('nonce');
    }
    return 1;
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new RuntimeException('die');
}
function wp_safe_redirect($location, $status = 302)
{
    global $test_redirect;
    $test_redirect = (string) $location;
    throw new RuntimeException('redirect');
}
function admin_url($path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}
function add_query_arg($key, $value, $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value;
}
function sanitize_key($key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
}
function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map('wp_unslash', $value) : $value);
}
function wp_nonce_field($action = -1, $name = '_wpnonce'): void
{
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="nonce-for-' . esc_attr($action) . '" />';
}
function get_edit_post_link($id = 0, $context = 'display')
{
    return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&amp;action=edit'; // display context, as core
}
// As core: false for an unchanged value or nothing to delete; "id:key" in $test_meta_fail fails the write.
$test_meta_fail = [];
function update_post_meta($post_id, $key, $value)
{
    global $test_meta, $test_meta_fail;
    if (in_array((int) $post_id . ':' . $key, $test_meta_fail, true) || ($test_meta[(int) $post_id][$key] ?? null) === [$value]) {
        return false;
    }
    $test_meta[(int) $post_id][$key] = [$value];
    return true;
}
function delete_post_meta($post_id, $key)
{
    global $test_meta, $test_meta_fail;
    if (in_array((int) $post_id . ':' . $key, $test_meta_fail, true) || !isset($test_meta[(int) $post_id][$key])) {
        return false;
    }
    unset($test_meta[(int) $post_id][$key]);
    return true;
}
function wp_strip_all_tags($text, $remove_breaks = false): string
{
    return trim(strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text)));
}
function esc_url_raw($url, $protocols = null): string
{
    return trim((string) $url);
}

function add_contact(int $id, string $status, string $title, array $meta): void
{
    global $test_posts, $test_meta, $test_post_lists;
    $test_posts[$id] = sfx_make_post($id, 'sfx_contact_info', $status, $title);
    $test_meta[$id] = array_map(static fn($v) => [$v], $meta);
    $test_post_lists['sfx_contact_info'][] = $test_posts[$id];
}

/** Two published mains (none marked), one branch, one incomplete contact point. */
function fixture(): void
{
    global $test_posts, $test_meta, $test_post_lists, $test_options, $test_can, $test_nonce_ok, $test_nonce_seen, $test_redirect;
    $test_posts = $test_meta = $test_options = $test_nonce_seen = [];
    $test_post_lists = ['sfx_contact_info' => [], 'sfx_social_account' => []];
    $test_can = $test_nonce_ok = true;
    $test_redirect = null;
    $_POST = $_GET = [];
    add_contact(500, 'publish', 'HQ', ['_contact_type' => 'main', '_company' => 'ACME GmbH', '_street' => 'Hauptstr. 1', '_city' => 'Essen']);
    add_contact(501, 'publish', 'Filiale Bochum', ['_contact_type' => 'branch', '_street' => 'Weg 2', '_city' => 'Bochum']);
    add_contact(502, 'publish', 'Presse', ['_contact_type' => 'contact_point']);
    add_contact(503, 'publish', 'Second main', ['_contact_type' => 'main', '_company' => 'Other AG']);
}

/** Runs the admin-post handler; returns how it stopped: redirect, nonce or die. */
function submit(array $post): string
{
    $_POST = array_map(static fn($v) => is_string($v) ? addslashes($v) : $v, $post);
    try {
        SettingsPage::handle_save();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return 'returned';
}

function page(): string
{
    ob_start();
    SettingsPage::render_page();
    return (string) ob_get_clean();
}

const VALID = ['enabled' => '1', 'type' => 'LocalBusiness', 'organization_id' => '500', 'excluded' => ['501']];

// plugin_read_failed runs in its own process (`php <this file> throwing_rank_math`): RANK_MATH_VERSION stays defined.
if (($argv[1] ?? '') === 'throwing_rank_math') {
    fixture();
    $test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => false];
    define('RANK_MATH_VERSION', '1.0.282');
    require __DIR__ . '/support/rank-math-throwing-helper-stub.php';
    $out = page();
    assert_contains('The settings of the active SEO plugin (Rank Math) could not be read.', $out, 'throwing helper: page renders the diagnostic');
    assert_contains('Per-page JSON-LD stays active while the switch is on.', $out, 'throwing helper: per-page output named');
    exit($failures > 0 ? 1 : 0);
}
$child = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' throwing_rank_math 2>&1', $child, $child_code);
assert_same([0, []], [$child_code, $child], 'throwing Rank Math helper: settings page renders, no fatal');

// save_requires_manage_options
fixture();
$test_can = false;
assert_same('die', submit(VALID), 'no capability: handler stops');
assert_same(null, $test_options[Settings::OPTION] ?? null, 'no capability: option untouched');
assert_true(!isset($test_meta[500]['_sfx_contact_schema_org']), 'no capability: no mark written');
assert_true(!isset($test_meta[501]['_sfx_contact_schema_excluded']), 'no capability: no exclusion written');
$threw = '';
try {
    page();
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
assert_same('die', $threw, 'no capability: page not rendered');

// save_requires_nonce
fixture();
$test_nonce_ok = false;
assert_same('nonce', submit(VALID), 'bad nonce: handler stops');
assert_same(['sfx_contact_schema_save'], $test_nonce_seen, 'nonce action checked');
assert_same(null, $test_options[Settings::OPTION] ?? null, 'bad nonce: option untouched');
assert_true(!isset($test_meta[500]['_sfx_contact_schema_org']), 'bad nonce: no mark written');

// enable_and_type_saved
fixture();
assert_same('redirect', submit(VALID), 'valid: redirects');
assert_same(['enabled' => true, 'type' => 'LocalBusiness', 'chosen_once' => true], Settings::get(), 'valid: switch and type saved');
assert_contains('edit.php?post_type=sfx_contact_info&page=sfx-contact-schema', (string) $test_redirect, 'valid: back to the page');
assert_contains('sfx_schema=saved', (string) $test_redirect, 'valid: saved notice');
submit(['type' => 'Organization', 'organization_id' => '500']);
assert_same(['enabled' => false, 'type' => 'Organization', 'chosen_once' => true], Settings::get(), 'unchecked switch turns off');
submit(['enabled' => '1', 'type' => 'Person', 'organization_id' => '500']);
assert_same('Organization', Settings::get()['type'], 'unknown type falls back to Organization');

// choice_saved_via_selection
fixture();
$test_meta[503]['_sfx_contact_schema_org'] = ['1'];
submit(VALID);
assert_same(['1'], $test_meta[500]['_sfx_contact_schema_org'] ?? null, 'choice: chosen contact marked');
assert_true(!isset($test_meta[503]['_sfx_contact_schema_org']), 'choice: other mark removed');
assert_same(['1'], $test_meta[501]['_sfx_contact_schema_excluded'] ?? null, 'choice: exclusion stored');
submit(['enabled' => '1', 'type' => 'Organization', 'organization_id' => '500']);
assert_true(!isset($test_meta[501]['_sfx_contact_schema_excluded']), 'choice: unticked exclusion removed');
// No organization submitted: the switch is saved, the selection is left alone.
fixture();
$test_meta[501]['_sfx_contact_schema_excluded'] = ['1'];
submit(['enabled' => '1', 'type' => 'Organization', 'organization_id' => '0']);
assert_same(true, Settings::get()['enabled'], 'no choice: switch saved');
assert_same(['1'], $test_meta[501]['_sfx_contact_schema_excluded'] ?? null, 'no choice: exclusions untouched');
assert_contains('sfx_schema=saved_no_choice', (string) $test_redirect, 'no choice: notice says so');

// write_failed_notice: a metadata write that does not take is an error, never "Settings saved"
fixture();
$test_meta_fail = ['501:_sfx_contact_schema_excluded'];
assert_same('redirect', submit(VALID), 'write failed: redirects');
$test_meta_fail = [];
assert_contains('sfx_schema=write_failed', (string) $test_redirect, 'write failed: failure notice, not saved');
$_GET = ['sfx_schema' => 'write_failed'];
$out = page();
assert_contains('notice-error', $out, 'write failed: error notice rendered');
assert_contains('could not be saved completely', $out, 'write failed: says so');
assert_true(!str_contains($out, 'Settings saved'), 'write failed: no saved message');
// Saving the same choice again: core's false for an unchanged value is no failure.
fixture();
submit(VALID);
submit(VALID);
assert_contains('sfx_schema=saved', (string) $test_redirect, 'unchanged resave: saved');

// invalid_choice_notice
fixture();
submit(['enabled' => '1', 'type' => 'Organization', 'organization_id' => '501', 'excluded' => ['502']]);
assert_contains('sfx_schema=invalid_choice', (string) $test_redirect, 'invalid: branch rejected');
assert_true(!isset($test_meta[501]['_sfx_contact_schema_org']), 'invalid: no mark');
assert_true(!isset($test_meta[502]['_sfx_contact_schema_excluded']), 'invalid: exclusions not stored');
assert_same(false, Settings::get()['chosen_once'], 'invalid: chosen once not set');
$_GET = ['sfx_schema' => 'invalid_choice'];
assert_contains('notice-error', page(), 'invalid: error notice rendered');
$_GET = ['sfx_schema' => '<b>x</b>'];
assert_true(!str_contains(page(), '<b>x</b>'), 'unknown notice code not echoed');

// mode_reason_rendered_escaped
fixture();
$hostile = '<script>alert(1)</script> & "Co"';
$test_posts[503]->post_title = $hostile;
$test_meta[500]['_sfx_contact_schema_org'] = ['1'];
$test_meta[503]['_sfx_contact_schema_org'] = ['1'];
$test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
$out = page();
assert_true(!str_contains($out, '<script>alert(1)</script>'), 'escaped: no raw markup from a title');
assert_contains(esc_html($hostile), $out, 'escaped: title shown escaped in the marked list');
assert_contains('Several contacts are marked as the organization', $out, 'fail closed: multiple marks explained');
assert_contains('No company data is published or added until you choose one below. Per-page JSON-LD stays active while the switch is on.', $out, 'fail closed: only company data stops, the per-page field is named');
assert_contains('post.php?post=503&amp;action=edit', $out, 'fail closed: edit link per marked contact');
assert_contains('No SEO plugin', $out, 'mode reason rendered');
assert_contains('name="_wpnonce" value="nonce-for-sfx_contact_schema_save"', $out, 'form carries the nonce');
assert_contains('value="sfx_contact_schema_save"', $out, 'form posts to the handler');

// page_lists_state: organization not excludable, unused main, incomplete with missing parts
fixture();
$test_meta[500]['_sfx_contact_schema_org'] = ['1'];
$test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
$out = page();
assert_true(!str_contains($out, 'name="excluded[]" value="500"'), 'organization has no exclusion box');
assert_contains('name="excluded[]" value="501"', $out, 'branch has an exclusion box');
assert_contains('Second main', $out, 'unused main listed');
assert_contains('area label', $out, 'incomplete contact point: missing area named');
assert_contains('telephone or email', $out, 'incomplete contact point: missing contact named');
assert_contains('<option value="LocalBusiness"', $out, 'type select offers LocalBusiness');
assert_true(!str_contains($out, 'published without the address'), 'complete organization: no published-without list');

// formatted_only_organization_is_published_without_address
fixture();
$test_meta[500]['_sfx_contact_schema_org'] = ['1'];
unset($test_meta[500]['_street']);
$test_meta[500]['_address'] = ['Hauptstr. 1, 45127 Essen'];
$test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
$out = page();
$published_at = strpos($out, 'Incomplete, published without the address');
$left_out_at = strpos($out, 'Incomplete, left out');
assert_true($published_at !== false && $left_out_at !== false, 'formatted-only organization: both lists shown');
$published_list = substr($out, (int) $published_at, (int) strpos($out, '</ul>', (int) $published_at) - (int) $published_at);
$left_out_list = substr($out, (int) $left_out_at, (int) strpos($out, '</ul>', (int) $left_out_at) - (int) $left_out_at);
assert_contains('post.php?post=500&amp;action=edit', $published_list, 'formatted-only organization: listed as published without the address');
assert_contains('only the formatted address is filled in', $published_list, 'formatted-only organization: missing parts named');
assert_true(!str_contains($left_out_list, 'post=500&amp;'), 'formatted-only organization: not listed as left out');
assert_contains('post.php?post=502&amp;action=edit', $left_out_list, 'incomplete contact point: still left out');

// plugin_read_failed_without_plugin: a read that fails before any plugin is detected shows no empty "()"
fixture();
$render_status = new ReflectionMethod(SettingsPage::class, 'render_status');
$selection = \SFX\ContactInfos\Schema\Selection::resolve();
ob_start();
$render_status->invoke(null, ['mode' => 'unavailable', 'reason' => 'plugin_read_failed', 'plugins' => []], $selection, \SFX\ContactInfos\Schema\GraphBuilder::organization($selection, 'Organization'));
$out = (string) ob_get_clean();
assert_contains('The settings of the active SEO plugin could not be read.', $out, 'no plugin: diagnostic without a name');
assert_true(!str_contains($out, '()'), 'no plugin: no empty parentheses');

// tsf_guidance_names_the_control; declared last, because a defined constant stays defined.
fixture();
$test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => false];
define('THE_SEO_FRAMEWORK_VERSION', '5.2.1');
$out = page();
assert_contains('&quot;Schema.org Settings&quot; box, untick &quot;Add authorized presence?&quot;', $out, 'TSF: names the box and the checkbox');
assert_true(!str_contains($out, 'Extension configured'), 'TSF: no extension note');
assert_contains('so the theme publishes no company data.', $out, 'TSF: only company data is suppressed');
assert_contains('Per-page JSON-LD stays active while the switch is on.', $out, 'TSF: per-page output named');

// register_adds_submenu_and_handler
$test_added_actions = [];
SettingsPage::register();
assert_true(isset($test_added_actions['admin_menu'], $test_added_actions['admin_post_sfx_contact_schema_save']), 'hooks registered');

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-schema-settings-page-test: PASS\n";
