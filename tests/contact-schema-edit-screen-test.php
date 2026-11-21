<?php

declare(strict_types=1);

// Contact edit screen: third type, area label, structured hours, translatable strings.
$test_filter_callbacks = [];
function apply_filters(string $hook, $value, ...$args)
{
    global $test_filter_callbacks;
    foreach ($test_filter_callbacks[$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}
function do_action(string $hook, ...$args): void
{
    global $test_filter_callbacks;
    foreach ($test_filter_callbacks[$hook] ?? [] as $callback) {
        $callback(...$args);
    }
}
function has_action(string $hook, $callback = false)
{
    global $test_filter_callbacks;
    return !empty($test_filter_callbacks[$hook]);
}

require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/OpeningHours.php';
require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';

use SFX\ContactInfos\PostType;

// Request-level stubs.
$test_nonce_ok = true;
$test_can_edit = true;
function wp_verify_nonce($nonce, $action)
{
    global $test_nonce_ok;
    return $test_nonce_ok && $nonce === 'good' ? 1 : false;
}
function current_user_can(string $cap, ...$args): bool
{
    global $test_can_edit;
    return $test_can_edit;
}
function get_current_user_id(): int
{
    return 7;
}
function sanitize_email($email): string
{
    return trim((string) $email);
}
function esc_url_raw($url): string
{
    return trim((string) $url);
}
function wp_is_post_revision($id): bool
{
    return false;
}
function wp_is_post_autosave($id): bool
{
    return false;
}
function update_post_meta($post_id, $key, $value)
{
    global $test_meta, $test_meta_writes;
    $test_meta[$post_id][$key] = [$value];
    $test_meta_writes[] = $key;
    return true;
}
function delete_post_meta($post_id, $key)
{
    global $test_meta, $test_meta_deletes;
    unset($test_meta[$post_id][$key]);
    $test_meta_deletes[] = $key;
    return true;
}
function selected($a, $b, $echo = true)
{
    $r = (string) $a === (string) $b ? ' selected="selected"' : '';
    if ($echo) {
        echo $r;
    }
    return $r;
}
function wp_nonce_field(...$a): void
{
}
function wp_editor(...$a): void
{
}
function get_current_screen()
{
    return (object) ['post_type' => 'sfx_contact_info'];
}

$fresh = static function (): void {
    global $test_meta_writes, $test_meta_deletes;
    $test_meta_writes = [];
    $test_meta_deletes = [];
    $_POST = ['sfx_contact_info_config_nonce' => 'good'];
};

// Validation allowlist.
$test_meta[1] = ['_contact_type' => ['contact_point']];
PostType::validate_meta_fields(1);
assert_same('contact_point', $test_meta[1]['_contact_type'][0], 'contact_point_type_survives_validation');
$test_meta[1] = ['_contact_type' => ['bogus']];
PostType::validate_meta_fields(1);
assert_same('main', $test_meta[1]['_contact_type'][0], 'unknown_type_still_maps_to_main');

// Save.
$business = ['company' => 'ACME', 'tax_id' => 'T1', 'vat' => 'V1', 'hrb' => 'H1', 'court' => 'C1', 'dsb' => 'D1'];
$fresh();
$test_meta[2] = ['_company' => ['Old']];
$_POST += ['contact_type' => 'contact_point', 'phone' => ' 0201 1 ', 'sfx_schema_area' => ' Press ', 'sfx_schema_org' => '1'] + $business;
PostType::save_custom_fields(2);
assert_same('contact_point', $test_meta[2]['_contact_type'][0], 'contact_point type saved');
foreach (array_keys($business) as $key) {
    assert_same('', $test_meta[2]['_' . $key][0], "contact_point_clears_business_fields: {$key}");
}
assert_same('Press', $test_meta[2]['_sfx_contact_schema_area'][0], 'area_label_saved_sanitized');

// Marks are never written or deleted, even when posted.
$fresh();
$_POST += ['contact_type' => 'main', '_sfx_contact_schema_org' => '1', 'sfx_contact_schema_org' => '1',
    '_sfx_contact_schema_excluded' => '1', 'sfx_contact_schema_excluded' => '1'];
PostType::save_custom_fields(3);
$touched = array_merge($test_meta_writes, $test_meta_deletes);
assert_true(
    array_intersect($touched, ['_sfx_contact_schema_org', '_sfx_contact_schema_excluded']) === []
        && !isset($test_meta[3]['_sfx_contact_schema_org']) && !isset($test_meta[3]['_sfx_contact_schema_excluded']),
    'schema_org_and_excluded_marks_not_written_by_edit_form'
);

// Existing fields unchanged for a main contact.
$fresh();
$_POST += ['contact_type' => 'main', 'phone' => '0201 123', 'opening' => 'Mo 9-17', 'city' => 'Essen', 'email' => 'a@b.de'] + $business;
PostType::save_custom_fields(4);
assert_same(['0201 123', 'Mo 9-17', 'Essen', 'a@b.de', 'ACME', 'T1'], [
    $test_meta[4]['_phone'][0], $test_meta[4]['_opening'][0], $test_meta[4]['_city'][0], $test_meta[4]['_email'][0],
    $test_meta[4]['_company'][0], $test_meta[4]['_tax_id'][0],
], 'existing_fields_unchanged');

// Hours: valid save, then an invalid day keeps the previous value and flags a notice.
$fresh();
$_POST += ['contact_type' => 'main', 'sfx_hours' => ['Monday' => ['state' => 'open', 'ranges' => [['09:00', '17:00'], ['', '']]]]];
PostType::save_custom_fields(5);
$hours = $test_meta[5]['_sfx_contact_schema_hours'][0];
assert_same(['state' => 'open', 'ranges' => [['09:00', '17:00']]], $hours['Monday'], 'valid hours saved');
assert_same(['state' => 'unset', 'ranges' => []], $hours['Tuesday'], 'missing day defaults to unset');
assert_same(false, get_transient('sfx_contact_hours_errors_7'), 'no notice for valid hours');

// The shared get_post_meta stub returns an array value raw only for keys listed here.
$test_meta_single_as_array[5] = ['_sfx_contact_schema_hours'];
$test_meta[5]['_sfx_contact_schema_hours'] = $hours;
$fresh();
$_POST += ['contact_type' => 'main', 'sfx_hours' => ['Monday' => ['state' => 'open', 'ranges' => [['9', 'x']]]]];
PostType::save_custom_fields(5);
assert_same(['state' => 'open', 'ranges' => [['09:00', '17:00']]], $test_meta[5]['_sfx_contact_schema_hours'][0]['Monday'], 'invalid_hours_keep_previous_and_flag_notice: kept');
$errors = get_transient('sfx_contact_hours_errors_7');
assert_true(is_array($errors) && count($errors) === 1, 'invalid_hours: error stored for the user');
ob_start();
PostType::admin_notices();
$notice = ob_get_clean();
assert_contains('Monday: Enter times as HH:MM.', $notice, 'invalid_hours: notice shown');
assert_same(false, get_transient('sfx_contact_hours_errors_7'), 'invalid_hours: notice shown once');

// No nonce: nothing changes.
$fresh();
unset($_POST['sfx_contact_info_config_nonce']);
$_POST += ['contact_type' => 'branch', 'sfx_schema_area' => 'X', 'sfx_hours' => ['Monday' => ['state' => 'closed']]];
PostType::save_custom_fields(6);
assert_same([], $test_meta_writes, 'save_without_nonce_changes_nothing');

// No capability: nothing changes.
$fresh();
$test_can_edit = false;
$_POST += ['contact_type' => 'branch', 'sfx_schema_area' => 'X', 'sfx_hours' => ['Monday' => ['state' => 'closed']]];
PostType::save_custom_fields(6);
$test_can_edit = true;
assert_same([], $test_meta_writes, 'save_without_edit_post_changes_nothing');

// Edit form shows the new type and fields.
$test_posts[8] = sfx_make_post(8, 'sfx_contact_info', 'publish', 'Press');
ob_start();
PostType::render_meta_box($test_posts[8]);
$form = ob_get_clean();
assert_contains('value="contact_point"', $form, 'form: third type');
assert_contains('Contact point for an area', $form, 'form: type label');
assert_contains('Area (e.g. Press, Group travel)', $form, 'form: area label field');
assert_contains('name="sfx_hours[Sunday][ranges][1][1]"', $form, 'form: hours inputs');
assert_same(7, substr_count($form, '<fieldset'), 'form: one fieldset per weekday');
assert_same(7, preg_match_all('~<fieldset[^>]*><legend[^>]*>[^<]+</legend>~', $form), 'form: each weekday fieldset has its legend');
assert_contains('name="sfx_hours[Monday][state]" aria-label="Monday: opening state"', $form, 'form: state select named with the weekday');
assert_contains('name="sfx_hours[Sunday][ranges][1][0]" value="" aria-label="Sunday, range 2: from"', $form, 'form: time input named with weekday, range and from');
assert_contains('name="sfx_hours[Sunday][ranges][1][1]" value="" aria-label="Sunday, range 2: to"', $form, 'form: time input named with weekday, range and to');
preg_match_all('~<(?:select|input type="time")\s[^>]*name="sfx_hours\[[^>]*>~', $form, $controls);
assert_same(35, count($controls[0]), 'form: 7 selects and 28 time inputs');
assert_same(35, count(array_filter($controls[0], static fn(string $c): bool => (bool) preg_match('~aria-label="[^"]+"~', $c))), 'form: every hours control has an accessible name');

// Translatable strings: area and title of non-main contacts.
$test_posts[30] = sfx_make_post(30, 'sfx_contact_info', 'publish', 'Berlin Office');
$test_meta[30] = ['_contact_type' => ['branch'], '_city' => ['Berlin']];
$test_posts[31] = sfx_make_post(31, 'sfx_contact_info', 'publish', 'Press desk');
$test_meta[31] = ['_contact_type' => ['contact_point'], '_sfx_contact_schema_area' => ['Press']];
$test_post_lists['sfx_contact_info'] = [30, 31];

// WPML (documentation-built, stub-tested only): registration through the documented action.
$test_wpml_registered = [];
$test_filter_callbacks['wpml_default_language'][] = static fn($v) => 'de';
$test_filter_callbacks['wpml_register_single_string'][] = static function ($ctx, $name, $value) {
    global $test_wpml_registered;
    $test_wpml_registered[] = [$ctx, $name, $value];
};
$test_filter_callbacks['wpml_translate_single_string'][] = static fn($v, $ctx, $name) => 'WPML ' . $v;
PostType::register_translatable_strings();
$ctx = 'sfx-bricks-child contact infos';
foreach ([['title #30', 'Berlin Office'], ['title #31', 'Press desk'], ['schema_area #31', 'Press']] as [$name, $value]) {
    assert_true(in_array([$ctx, $name, $value], $test_wpml_registered, true), 'area_and_title_registered_for_wpml: ' . $name);
}
assert_same('WPML Press', PostType::get_translated_field(31, 'schema_area', 'Press'), 'wpml: area translated');
unset($test_filter_callbacks);
$test_filter_callbacks = [];

$test_pll_registered = [];
// Declared at runtime, so the WPML cases above run without Polylang.
if (!function_exists('pll_register_string')) {
function pll_register_string($name, $string, $context = 'Polylang', $multiline = false)
{
    global $test_pll_registered;
    $test_pll_registered[] = [$name, $string];
}
function pll__($string)
{
    return 'PLL ' . $string;
}
}
PostType::register_translatable_strings();
foreach ([['Name #30', 'Berlin Office'], ['Name #31', 'Press desk'], ['Area #31', 'Press']] as $row) {
    assert_true(in_array($row, $test_pll_registered, true), 'area_and_title_registered_for_polylang: ' . $row[0]);
}
assert_same('PLL Press', PostType::get_translated_field(31, 'schema_area', 'Press'), 'polylang: area read through pll__');
assert_same('PLL Press desk', PostType::get_translated_field(31, 'title', 'Press desk'), 'polylang: title read through pll__');

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-schema-edit-screen-test: PASS\n";
