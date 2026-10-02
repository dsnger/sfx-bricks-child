<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

/**
 * Worker for tests/support/rest-guest-live-check.php — never run it on its own.
 *
 * It fully boots WordPress (theme, plugins, WP Optimizer) in a fresh process and
 * prints four marker lines the parent parses. It creates an application password
 * for user 1; the parent's teardown restores user 1's `_application_passwords`
 * row and the `using_application_passwords` option from its raw snapshot, which
 * is why the worker refuses to run unless the parent launched it: the token in
 * argv[1] must equal SFX_REST_GUEST_RUN in the environment.
 *
 * It never creates posts: no published post means exit 1.
 */

$token = $argv[1] ?? '';
if ($token === '' || getenv('SFX_REST_GUEST_RUN') !== $token) {
    fwrite(STDERR, "error: run tests/support/rest-guest-live-check.php, not this worker.\n");
    exit(1);
}

define('WP_USE_THEMES', false);
require __DIR__ . '/../../../../../wp-load.php';

use SFX\WPOptimizer\classes\RestGuestAccess;

if (!get_userdata(1)) {
    fwrite(STDERR, "error: user 1 does not exist.\n");
    exit(1);
}
wp_set_current_user(1);

// The namespace index route '/oembed/1.0' exists whenever the namespace does; only the
// embed routes say the oEmbed endpoint itself is registered.
$routes = array_filter(array_keys(rest_get_server()->get_routes('oembed/1.0')), static fn($r) => $r !== '/oembed/1.0');
echo 'OEMBED_ROUTES:' . count($routes) . "\n";

$posts = get_posts([
    'post_type'        => 'post',
    'post_status'      => 'publish',
    'has_password'     => false,
    'numberposts'      => 1,
    'orderby'          => 'ID',
    'order'            => 'ASC',
    'suppress_filters' => true,
]);
if (!$posts) {
    fwrite(STDERR, "error: the live check needs one published post.\n");
    exit(1);
}
echo 'PERMALINK:' . get_permalink($posts[0]) . "\n";

$created = WP_Application_Passwords::create_new_application_password(1, ['name' => 'sfx-rest-guest-live-check']);
if (is_wp_error($created)) {
    fwrite(STDERR, 'error: could not create the application password: ' . $created->get_error_message() . "\n");
    exit(1);
}
echo 'APP:' . $created[1]['uuid'] . ':' . $created[0] . "\n";

// In memory only: nothing below writes the option.
$o = RestGuestAccess::option();
$o['rest_guest_seen'] = ['bricks/v1', 'oembed/1.0'];
$blocked = RestGuestAccess::new_blocked(RestGuestAccess::live_namespaces(), RestGuestAccess::seen($o), RestGuestAccess::allowed_map($o));
echo 'NEW_BLOCKED:' . wp_json_encode($blocked) . "\n";

exit(0);
