<?php

declare(strict_types=1);

/**
 * Drive the Media Credits REST contract against a REAL WordPress.
 *
 * tests/media-credits-rest-test.php asserts what register_meta() is ASKED for.
 * That is a useful thing to pin, and it is not the same question as whether
 * WordPress honours it: core owns protected-meta auth, the schema validator
 * and map_meta_cap, and any of them could change under a registration that
 * still looks correct. This script asks the other question by dispatching real
 * REST requests through rest_do_request().
 *
 * MANUAL. quality.sh does not run this — its globs are tests/*-test.php and
 * tests/*-test.mjs, and this needs a booted WordPress with the Media Credits
 * feature enabled, which CI has no way to provide.
 *
 *   php tests/support/media-credits-rest-live-check.php
 *   WP_ROOT=/path/to/wordpress php tests/support/media-credits-rest-live-check.php
 *
 * Locally that is MAMP's PHP (see AGENTS.md § Local PHP); WP-CLI cannot reach
 * the socket, which is why this boots wp-load.php by hand.
 *
 * Creates its own fixtures — an attachment, a subscriber and a draft post —
 * and deletes every one of them on the way out, including when an assertion
 * fails and when a fatal ends the run early. It never writes to media that
 * was already there.
 */

// ---------------------------------------------------------------- bootstrap

// Walk up rather than counting directories: this file sits five levels below
// the WordPress root today, and a hard-coded depth is silently wrong the
// moment anything moves.
$load = '';

if ($root = getenv('WP_ROOT')) {
    $load = rtrim($root, '/') . '/wp-load.php';
} else {
    for ($dir = __DIR__; $dir !== dirname($dir); $dir = dirname($dir)) {
        if (is_file($dir . '/wp-load.php')) {
            $load = $dir . '/wp-load.php';
            break;
        }
    }
}

if ($load === '' || !is_file($load)) {
    fwrite(STDERR, "error: no wp-load.php found above " . __DIR__ . "\nSet WP_ROOT to the WordPress root.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $load;

if (!function_exists('rest_do_request')) {
    fwrite(STDERR, "error: WordPress booted but the REST API is unavailable.\n");
    exit(2);
}

// The module is opt-in. Without it register_meta() never runs and every case
// below would fail for a reason that has nothing to do with the contract.
$general = get_option('sfx_general_options');

if (empty($general['enable_media_credits'])) {
    fwrite(STDERR, "error: Media Credits is disabled (sfx_general_options[enable_media_credits]).\nEnable it in General Theme Options and re-run.\n");
    exit(2);
}

$admins = get_users(['role' => 'administrator', 'number' => 1]);

if (!$admins) {
    fwrite(STDERR, "error: no administrator on this site to act as.\n");
    exit(2);
}

$admin = $admins[0];

// ---------------------------------------------------------------- assertions

$failures = 0;

function check(string $message, $expected, $actual): void
{
    global $failures;

    if ($expected === $actual) {
        echo "  ok   {$message}\n";

        return;
    }

    echo '  FAIL ' . $message . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ")\n";
    $failures++;
}

// ------------------------------------------------------------------ fixtures
// Every fixture is torn down by one shutdown handler rather than at the point
// it stops being needed: a fatal between creating one and deleting it would
// otherwise leave it behind on a real site. That includes $post_id, which is
// created much further down for Case 5 — it is declared here so the handler
// closes over it, and Case 5 does not delete it itself.

$attachment_id = 0;
$subscriber_id = 0;
$post_id       = 0;

register_shutdown_function(static function () use (&$attachment_id, &$subscriber_id, &$post_id): void {
    if ($attachment_id > 0) {
        wp_delete_attachment($attachment_id, true);
    }

    if ($post_id > 0) {
        wp_delete_post($post_id, true);
    }

    if ($subscriber_id > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($subscriber_id);
    }

    echo "  (fixtures removed)\n";
});

$attachment_id = (int) wp_insert_post([
    'post_type'   => 'attachment',
    'post_status' => 'inherit',
    'post_title'  => 'sfx media credits live check',
    'post_author' => $admin->ID,
    'post_mime_type' => 'image/jpeg',
]);

if ($attachment_id <= 0) {
    fwrite(STDERR, "error: could not create the fixture attachment.\n");
    exit(2);
}

$subscriber_id = (int) wp_insert_user([
    'user_login' => 'sfx_live_check_' . wp_generate_password(8, false),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'sfx-live-check-' . wp_generate_password(8, false) . '@example.invalid',
    'role'       => 'subscriber',
]);

if ($subscriber_id <= 0) {
    fwrite(STDERR, "error: could not create the fixture subscriber.\n");
    exit(2);
}

echo "attachment {$attachment_id}, admin {$admin->user_login}, throwaway subscriber {$subscriber_id}\n";

wp_set_current_user($admin->ID);
do_action('rest_api_init');

/** @param array<string, mixed> $meta */
function write_meta(int $attachment_id, array $meta): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/wp/v2/media/' . $attachment_id);
    $request->set_body_params(['meta' => $meta]);

    return rest_do_request($request);
}

// ------------------------------------------- Case 1: an editor's write lands

echo "\nCase 1 — an administrator writes both fields\n";

$response = write_meta($attachment_id, [
    '_sfx_media_copyright' => 'Foto Müller',
    '_sfx_media_ai'        => 'ai_generated',
]);
$meta = (array) ($response->get_data()['meta'] ?? []);

check('status is 200', 200, $response->get_status());
check('copyright is stored', 'Foto Müller', get_post_meta($attachment_id, '_sfx_media_copyright', true));
check('AI marking is stored', 'ai_generated', get_post_meta($attachment_id, '_sfx_media_ai', true));
check('copyright comes back in the response', 'Foto Müller', $meta['_sfx_media_copyright'] ?? null);
check('AI marking comes back in the response', 'ai_generated', $meta['_sfx_media_ai'] ?? null);

// ------------------------------------- Case 2: the IPTC marker stays private

echo "\nCase 2 — the IPTC marker is neither exposed nor writable\n";

$before = (string) get_post_meta($attachment_id, '_sfx_media_iptc_prefilled', true);
$response = write_meta($attachment_id, ['_sfx_media_iptc_prefilled' => 'tampered']);

check('the marker is absent from the response', false, array_key_exists('_sfx_media_iptc_prefilled', (array) ($response->get_data()['meta'] ?? [])));
check('the marker is unchanged', $before, (string) get_post_meta($attachment_id, '_sfx_media_iptc_prefilled', true));

// --------------------------------- Case 3: an unknown AI slug is an error

echo "\nCase 3 — an unrecognised AI slug is rejected, not silently cleared\n";

$response = write_meta($attachment_id, ['_sfx_media_ai' => 'not-a-real-key']);

check('status is 400', 400, $response->get_status());
check('the previous value survives', 'ai_generated', get_post_meta($attachment_id, '_sfx_media_ai', true));

// The empty string is not a typo — it is how "no marking" is written.
$response = write_meta($attachment_id, ['_sfx_media_ai' => '']);

check('clearing is still allowed', 200, $response->get_status());
check('and clears', '', get_post_meta($attachment_id, '_sfx_media_ai', true));

// -------------------------------------- Case 4: a subscriber cannot write

echo "\nCase 4 — a user who may not edit the attachment is refused\n";

wp_set_current_user($subscriber_id);

$response = write_meta($attachment_id, ['_sfx_media_copyright' => 'OVERWRITTEN']);

check('status is 403', 403, $response->get_status());
check('the stored value is untouched', 'Foto Müller', get_post_meta($attachment_id, '_sfx_media_copyright', true));

// --------------------- Case 5: the keys do not leak onto other post types

echo "\nCase 5 — object_subtype keeps the keys off posts and pages\n";

wp_set_current_user($admin->ID);

// Assigned to the variable the shutdown handler closes over, so this post is
// removed by the same teardown as the other two fixtures.
$post_id = (int) wp_insert_post([
    'post_type'   => 'post',
    'post_status' => 'draft',
    'post_title'  => 'sfx media credits live check (subtype scope)',
    'post_author' => $admin->ID,
]);

if ($post_id > 0) {
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $post_id));
    $keys     = array_keys((array) ($response->get_data()['meta'] ?? []));

    check('no media-credit key on a post', [], array_values(preg_grep('/^_sfx_media_/', $keys)));
} else {
    echo "  skip a post could not be created\n";
}

// ------------------ Case 6: the wp-admin save path still sanitises

echo "\nCase 6 — the wp-admin path is unaffected\n";

// save() writes through update_post_meta(), which reaches sanitize_meta() with
// the object subtype resolved from the post. Adding object_subtype to the
// registration could have moved the callback out of that path's reach.
update_post_meta($attachment_id, '_sfx_media_ai', 'bogus-key');
check('an unknown slug still sanitises to empty', '', get_post_meta($attachment_id, '_sfx_media_ai', true));

update_post_meta($attachment_id, '_sfx_media_copyright', '  <b>Agentur Nord</b>  ');
check('copyright is still stripped and trimmed', 'Agentur Nord', get_post_meta($attachment_id, '_sfx_media_copyright', true));

// ------------------------------------------------------------- epilogue

echo "\n";

if ($failures > 0) {
    echo "media-credits REST live check: {$failures} failed\n";
    exit(1);
}

echo "PASS: media-credits REST live check\n";
exit(0);
