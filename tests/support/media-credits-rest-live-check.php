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
 * Creates its own fixtures — an attachment, a subscriber and a draft post,
 * each tagged with a per-run token — and deletes everything carrying that
 * token on the way out, including when an assertion fails and when a fatal
 * ends the run early. It never writes to media that was already there, and
 * teardown cannot reach anything this run did not create.
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

// Multisite is refused rather than half-supported: wp_delete_user() there only
// drops site membership and leaves the account on the network
// (wp-admin/includes/user.php:440-449), so even a clean run would leak a user.
// Doing this properly means network-wide lookup and wpmu_delete_user, which no
// one has needed here.
if (is_multisite()) {
    fwrite(STDERR, "error: this harness does not support Multisite — teardown could not remove its own user.\n");
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
//
// Teardown identifies fixtures by a per-run TOKEN carried in their own data,
// not by the ids the create calls returned. Two reasons, both of them holes
// an id-based teardown actually had:
//
//   - An id is known only once the create call RETURNS, while core fires
//     add_attachment, save_post and user_register before that. A fatal inside
//     one of those hooks left the object behind with the captured id still 0.
//   - An id can be wrong. `(int)` on the WP_Error that wp_insert_user()
//     returns on failure yields 1 — not 0 — so a `<= 0` guard passed it
//     through and teardown deleted USER 1, the site's first administrator,
//     along with the content wp_delete_user() reassigns or destroys.
//     Reproduced on PHP 8.5: `(int) new WP_Error(...) === 1`.
//
// Searching for the token finds a fixture whose id was never returned. But a
// search is a QUERY, and pre_get_posts / users_pre_query let any plugin on the
// site widen one — so the search only proposes candidates. Every candidate is
// then re-read and must carry the token in its own stored field before it is
// deleted. That check, not the query, is what makes teardown unable to reach
// something this run did not create.
//
// For posts the token is carried TWICE, in post_name and in meta, and either
// one identifies a fixture. Neither alone is enough:
//
//   - The title cannot hold it. Case 7 renames the attachment on purpose, and
//     a title-borne token went with it: teardown declined to delete the fixture
//     AND its own leftover check could not see it, so the run reported success
//     and left an attachment behind.
//   - Meta alone leaves a window. wp_insert_post() writes the row (post.php:5027)
//     before meta_input (:5111), so a fatal in between leaves an untagged
//     orphan that a meta-only search cannot find.
//
// post_name goes in the initial INSERT, so there is no window; meta survives
// anything that rewrites the slug. The user needs neither: its token IS its
// user_login, written with the row, and nothing renames a user here.
//
// Deletion is verified rather than assumed: wp_delete_post() can be refused by
// pre_delete_post, and a handler that printed "removed" without looking would
// be the same kind of lie the rest of this file exists to catch.

const FIXTURE_META = '_sfx_live_check_token';

/**
 * Posts this run created, by either carrier of the token.
 *
 * Two queries, because a search is only a proposal: pre_get_posts lets any
 * plugin widen one, so every candidate is re-read and must carry the token in
 * post_name or in meta before it counts. That re-read, not the query, is what
 * keeps teardown off anything this run did not create.
 *
 * @return list<int>
 */
function fixture_posts(string $token): array
{
    $base = [
        'post_type'        => ['attachment', 'post'],
        'post_status'      => 'any',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
    ];

    $candidates = array_merge(
        get_posts($base + ['meta_key' => FIXTURE_META, 'meta_value' => $token]),
        get_posts($base + ['post_name__in' => [$token]])
    );

    $confirmed = [];

    foreach (array_unique(array_map('intval', $candidates)) as $id) {
        $post = get_post($id);

        if (!$post) {
            continue;
        }

        if ($post->post_name === $token || get_post_meta($id, FIXTURE_META, true) === $token) {
            $confirmed[] = $id;
        }
    }

    return $confirmed;
}

// Lowercase, because post_name goes through sanitize_title() and a mixed-case
// token comes back lowercased — the slug lookup then silently matches nothing,
// which is exactly the leak this carrier exists to prevent. Caught by probing a
// fatal before the token meta was written.
$token = 'sfxlivecheck' . strtolower(wp_generate_password(16, false, false));

register_shutdown_function(static function () use ($token): void {
    $candidates = fixture_posts($token);
    $posts      = 0;

    foreach ($candidates as $id) {
        wp_delete_post((int) $id, true);
        $posts++;
    }

    $candidates = get_users(['search' => '*' . $token . '*', 'fields' => 'ID']);
    $users      = 0;

    if ($candidates) {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($candidates as $id) {
            $user = get_userdata((int) $id);

            if (!$user || $user->user_login !== $token) {
                continue;
            }

            wp_delete_user((int) $id);
            $users++;
        }
    }

    $left = count(fixture_posts($token)) + count(get_users(['search' => '*' . $token . '*', 'fields' => 'ID']));

    if ($left > 0) {
        fwrite(STDERR, "  TEARDOWN FAILED: {$left} fixture(s) still on the site, token {$token}\n");
        exit(3);
    }

    printf("  (fixtures removed: %d post(s), %d user(s))\n", $posts, $users);
});

/**
 * wp_insert_post() returns 0 on failure and wp_insert_user() a WP_Error, and
 * casting the latter to int gives 1. Both go through here so neither can reach
 * a caller as a plausible-looking id.
 *
 * @param int|WP_Error $created
 */
function fixture_id($created, string $what): int
{
    if (is_wp_error($created)) {
        fwrite(STDERR, "error: could not create the fixture {$what}: " . $created->get_error_message() . "\n");
        exit(2);
    }

    $id = is_scalar($created) ? (int) $created : 0;

    if ($id <= 0) {
        fwrite(STDERR, "error: could not create the fixture {$what}.\n");
        exit(2);
    }

    return $id;
}

$attachment_id = fixture_id(wp_insert_post([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_title'     => 'sfx media credits live check ' . $token,
    'post_author'    => $admin->ID,
    'post_mime_type' => 'image/jpeg',
    'post_name'      => $token,
    'meta_input'     => [FIXTURE_META => $token],
], true), 'attachment');

// The slug is one of the two things teardown recognises a fixture by, so a
// sanitiser or a uniquifier quietly changing it has to be an error here rather
// than a leak later.
if (get_post($attachment_id)->post_name !== $token) {
    fwrite(STDERR, "error: the fixture slug was rewritten to '" . get_post($attachment_id)->post_name . "'; teardown could not rely on it.\n");
    exit(2);
}

$subscriber_id = fixture_id(wp_insert_user([
    'user_login' => $token,
    'user_pass'  => wp_generate_password(),
    'user_email' => $token . '@example.invalid',
    'role'       => 'subscriber',
]), 'subscriber');

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

// Seeded, so "absent from the response" is a statement about exposure rather
// than about an empty value, and read back through a GET that is checked for
// 200 first: an error response carries no meta either, so asserting absence
// against an unchecked response would pass for a request that never looked.
update_post_meta($attachment_id, '_sfx_media_iptc_prefilled', '1');

$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/media/' . $attachment_id));
$meta     = (array) ($response->get_data()['meta'] ?? []);

check('the attachment is readable', 200, $response->get_status());
check('the response carries a meta object', true, is_array($response->get_data()['meta'] ?? null));
check('the seeded marker is still absent from it', false, array_key_exists('_sfx_media_iptc_prefilled', $meta));

// Write protection is a separate question from exposure, so it gets its own
// request rather than sharing the one above.
$response = write_meta($attachment_id, ['_sfx_media_iptc_prefilled' => 'tampered']);

check('the marker is unchanged by a write attempt', '1', (string) get_post_meta($attachment_id, '_sfx_media_iptc_prefilled', true));

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

// Carries the token like every fixture, so the same teardown removes it.
$post_id  = fixture_id(wp_insert_post([
    'post_type'   => 'post',
    'post_status' => 'draft',
    'post_title'  => 'sfx media credits live check (subtype scope) ' . $token,
    'post_author' => $admin->ID,
    'post_name'   => $token,
    'meta_input'  => [FIXTURE_META => $token],
], true), 'draft post');

$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $post_id));
$data     = (array) $response->get_data();

// Assert the request succeeded before reading its meta. An error response
// carries no meta either, so "no _sfx_media_ key in the payload" would other-
// wise pass for a 404 — a green tick for a request that never looked.
check('the post is readable over REST', 200, $response->get_status());
check('the response carries a meta object', true, is_array($data['meta'] ?? null));
check('no media-credit key on a post', [], array_values(preg_grep('/^_sfx_media_/', array_keys((array) ($data['meta'] ?? [])))));

// ------------------ Case 6: the wp-admin save path still sanitises

echo "\nCase 6 — the wp-admin path is unaffected\n";

// save() writes through update_post_meta(), which reaches sanitize_meta() with
// the object subtype resolved from the post. Adding object_subtype to the
// registration could have moved the callback out of that path's reach.
update_post_meta($attachment_id, '_sfx_media_ai', 'bogus-key');
check('an unknown slug still sanitises to empty', '', get_post_meta($attachment_id, '_sfx_media_ai', true));

update_post_meta($attachment_id, '_sfx_media_copyright', '  <b>Agentur Nord</b>  ');
check('copyright is still stripped and trimmed', 'Agentur Nord', get_post_meta($attachment_id, '_sfx_media_copyright', true));

// ------------------ Case 7: a REST write still fires the save action

echo "\nCase 7 — sfx_media_credits_saved fires for a REST write\n";

// The action's documented reason for existing is page-cache invalidation. A
// REST write reaches update_metadata() directly, so without a hook of its own
// a cached disclosure would go stale in silence.
$fired = [];

add_action('sfx_media_credits_saved', static function ($id, $copyright, $ai_key, $context) use (&$fired): void {
    $fired[] = ['id' => $id, 'copyright' => $copyright, 'ai' => $ai_key, 'context' => $context];
}, 10, 4);

write_meta($attachment_id, ['_sfx_media_copyright' => 'Agentur Nord']);

check('it fired exactly once', 1, count($fired));
check('with context meta', 'meta', $fired[0]['context'] ?? null);
check('for this attachment', $attachment_id, $fired[0]['id'] ?? null);
check('carrying the post-write value', 'Agentur Nord', $fired[0]['copyright'] ?? null);

// An unrelated media edit is not a credit save — but only a SUCCESSFUL one
// proves that. A rejected request writes nothing either, so "no notification"
// would pass for a request that never got as far as trying.
$fired   = [];
$request = new WP_REST_Request('POST', '/wp/v2/media/' . $attachment_id);
$request->set_body_params(['title' => 'renamed, no credit fields']);
$response = rest_do_request($request);

check('an unrelated edit succeeds', 200, $response->get_status());
check('and really changed the title', 'renamed, no credit fields', get_post($attachment_id)->post_title);
check('and fired nothing', 0, count($fired));

// The partial-failure path, which is why this is keyed on writes and not on a
// successful response: a valid copyright beside an invalid slug is PERSISTED
// and then answered 400.
$fired    = [];
$response = write_meta($attachment_id, [
    '_sfx_media_copyright' => 'Partially Persisted',
    '_sfx_media_ai'        => 'not-a-real-key',
]);

check('a mixed write is rejected', 400, $response->get_status());
check('yet the copyright was stored anyway', 'Partially Persisted', get_post_meta($attachment_id, '_sfx_media_copyright', true));
check('and the save action still fired for it', 1, count($fired));

// ------------------------------------------------------------- epilogue

echo "\n";

if ($failures > 0) {
    echo "media-credits REST live check: {$failures} failed\n";
    exit(1);
}

echo "PASS: media-credits REST live check\n";
exit(0);
