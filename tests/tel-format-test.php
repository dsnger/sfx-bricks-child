<?php

declare(strict_types=1);

// Self-contained: TelFormat needs only these WordPress/Bricks functions.
define('ABSPATH', __DIR__ . '/');

$failures = 0;
function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}
function assert_same(mixed $expected, mixed $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function apply_filters(string $hook, $value, ...$args)
{
    return $value; // country code stays 49
}

$test_hooks = [];
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_hooks;
    $test_hooks[] = [$hook, $callback, $priority, $accepted_args];
    return true;
}

// esc_html: identity by default; test 5a switches on a visible wrapper.
$test_wrap_escape = false;
function esc_html($text): string
{
    global $test_wrap_escape;
    return $test_wrap_escape ? '[e]' . $text . '[/e]' : (string) $text;
}

// Bricks resolver stub: records calls, returns fixture values per tag.
$test_calls = [];
$test_values = [];
$test_resolver = null; // optional callable(string $tag, int $post_id, string $context)
function bricks_render_dynamic_data($content, $post_id = 0, $context = 'text')
{
    global $test_calls, $test_values, $test_resolver;
    $test_calls[] = [$content, $post_id, $context];
    if ($test_resolver !== null) {
        return ($test_resolver)($content, $post_id, $context);
    }
    return $test_values[$content] ?? '';
}

require dirname(__DIR__) . '/inc/TelNormalizer.php';
require dirname(__DIR__) . '/inc/TelFormat/Controller.php';

use SFX\TelFormat\Controller;

$post = (object) ['ID' => 135];
function reset_stub(array $values = []): void
{
    global $test_calls, $test_values, $test_resolver;
    $test_calls = [];
    $test_values = $values;
    $test_resolver = null;
}

// 0. Registration: exactly the two documented hooks, priority 9, nothing else; config has no activation key.
new Controller();
assert_same([
    ['bricks/dynamic_data/render_content', [Controller::class, 'render_content'], 9, 3],
    ['bricks/frontend/render_data', [Controller::class, 'render_data'], 9, 2],
], $test_hooks, '0: hooks');
$config = Controller::get_feature_config();
assert_same(Controller::class, $config['class'], '0: config class');
assert_true(!isset($config['activation_option_key']), '0: always on');

// 1. The task's examples, with and without the space.
reset_stub(['{acf_phone}' => '0151 15921554', '{acf_job_general_contacts_phone}' => '0208 / 207 658 0']);
assert_same('tel:+4915115921554', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '1: space');
assert_same('tel:+4915115921554', Controller::render_content('tel:{acf_phone@format:tel}', $post, 'link'), '1: no space');
assert_same('tel:+492082076580', Controller::render_content('tel:{acf_job_general_contacts_phone @format:tel}', $post, 'text'), '1: group field');

// 2. The resolver gets the bare tag, the post ID and the context; render_data passes 'text'.
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_content('{acf_phone @format:tel}', $post, 'link');
assert_same([['{acf_phone}', 135, 'link']], $test_calls, '2: resolver args (render_content)');
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_data('{acf_phone @format:tel}', $post);
assert_same([['{acf_phone}', 135, 'text']], $test_calls, '2: resolver args (render_data)');
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_content('{acf_phone @format:tel}', null, 'text');
assert_same([['{acf_phone}', 0, 'text']], $test_calls, '2: no post -> 0');

// 3. Not matched: byte-identical, resolver not called.
foreach ([
    "{acf_phone @fallback:'x' @format:tel}",
    '{acf_phone:plain @format:tel}',
    '{contact_info:phone @format:tel}',
    '{social_account:url:1 @format:tel}',
    '{acf_phone @format:telefax}',
    '{acf_phone @FORMAT:TEL}',
    '{acf_phone @format:tel @format:tel}',
    'tel:{acf_phone}',
    'no tag here',
] as $input) {
    reset_stub(['{acf_phone}' => '0151 1']);
    assert_same($input, Controller::render_content($input, $post, 'text'), "3: untouched {$input}");
    assert_same([], $test_calls, "3: resolver not called for {$input}");
}

// 3a. Accepted limit: an inner simple tag is resolved; the outer text stays.
reset_stub(['{acf_phone}' => '0151 15921554']);
assert_same('{echo:fn(+4915115921554)}', Controller::render_content('{echo:fn({acf_phone @format:tel})}', $post, 'text'), '3a: inner tag resolved');

// 4. Rejections give '' — never the tag, never digits from a name or from markup.
foreach ([
    'empty'           => '',
    'unresolved'      => '{acf_phone}',
    'encoded tag'     => '&#123;acf_phone2&#125;',
    'markup'          => '<a href="tel:1">1</a>',
    'encoded markup'  => '&lt;a href="tel:123"&gt;456&lt;/a&gt;',
] as $label => $value) {
    reset_stub(['{acf_phone}' => $value]);
    assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), "4: {$label} -> empty");
}
reset_stub();
$test_resolver = static fn() => ['0151 1'];
assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '4: array -> empty');

// 5. Entities and an existing tel: prefix.
foreach ([
    '0151&nbsp;15921554'         => '+4915115921554',
    '0151&#160;15921554'         => '+4915115921554',
    'tel:+49 (0) 208 2076580'    => '+492082076580',
    'TEL: 0208 2076580'          => '+492082076580',
    'tel:+49 171 1700557'        => '+491711700557',
    '&nbsp;tel:+49 171 1700557'  => '+491711700557',
    "\u{00A0}tel:\u{202F}+49 171 1700557" => '+491711700557',
] as $value => $expected) {
    reset_stub(['{acf_phone}' => $value]);
    assert_same($expected, Controller::render_content('{acf_phone @format:tel}', $post, 'text'), "5: {$value}");
}

// 5a. Escaping is applied to every successful replacement; rejections stay bare ''.
$test_wrap_escape = true;
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('tel:[e]+491511[/e]', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '5a: escaped');
reset_stub(['{acf_phone}' => '']);
assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '5a: rejection not wrapped');
$test_wrap_escape = false;

// 5b. Re-entry: same key yields '' and terminates; different post or context resolves; guard released after a throw.
reset_stub();
$depth = 0;
$inner = [];
$test_resolver = static function (string $tag, int $post_id, string $context) use (&$depth, &$inner, $post): string {
    $depth++;
    if ($depth > 5) {
        return 'RUNAWAY';
    }
    if ($tag !== '{acf_phone}' || $post_id !== 135 || $context !== 'text') {
        return '0151 1'; // the legitimate inner resolutions end here
    }
    // Outer resolution (135, text) asks for the same tag three ways while it is in flight.
    $inner['same']    = Controller::render_content('{acf_phone @format:tel}', $post, 'text');
    $inner['post']    = Controller::render_content('{acf_phone @format:tel}', (object) ['ID' => 200], 'text');
    $inner['context'] = Controller::render_content('{acf_phone @format:tel}', $post, 'link');
    $inner['name']    = Controller::render_content('{acf_fax @format:tel}', $post, 'text');
    return '0151 1';
};
assert_same('+491511', Controller::render_content('{acf_phone @format:tel}', $post, 'text'), '5b: outer value');
assert_same(4, $depth, '5b: outer + three legitimate inner resolutions; the same key is rejected without resolving');
assert_same(['same' => '', 'post' => '+491511', 'context' => '+491511', 'name' => '+491511'], $inner, '5b: inner results');
$test_resolver = static function () {
    throw new RuntimeException('boom');
};
try {
    Controller::render_content('{acf_phone @format:tel}', $post, 'text');
} catch (RuntimeException $e) {
}
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('+491511', Controller::render_content('{acf_phone @format:tel}', $post, 'text'), '5b: guard released after throw');

// 5c. Accepted limit pinned: inside a :raw fallback the inner tag is resolved too.
reset_stub(['{acf_phone}' => '0151 1']);
assert_same("{post_title:raw @fallback:'+491511'}", Controller::render_content("{post_title:raw @fallback:'{acf_phone @format:tel}'}", $post, 'text'), '5c: :raw limit');

// 6. Two tags, text around them byte-identical.
reset_stub(['{acf_phone}' => '0151 1', '{acf_fax}' => '0208 2']);
assert_same(
    '<a href="tel:+491511">Ruf an</a> · Fax tel:+492082 – Ende',
    Controller::render_content('<a href="tel:{acf_phone @format:tel}">Ruf an</a> · Fax tel:{acf_fax @format:tel} – Ende', $post, 'text'),
    '6: two tags'
);

// 7. Non-string content passes through untouched.
assert_same(['x'], Controller::render_content(['x'], $post, 'text'), '7: array');
assert_same(null, Controller::render_content(null, $post, 'text'), '7: null');

// 8. PCRE failure returns the original content (control run first).
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('tel:+491511', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '8: control');
$limit = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '1');
    $out = Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link');
    $err = preg_last_error();
} finally {
    ini_set('pcre.backtrack_limit', (string) $limit);
}
assert_same(PREG_BACKTRACK_LIMIT_ERROR, $err, '8: PCRE actually failed');
assert_same('tel:{acf_phone @format:tel}', $out, '8: original returned');

// 9. Output alphabet of the controller's replacements (not of the normaliser alone).
$alphabet = '/\A\+?[0-9]*(;ext=[0-9]+)?\z/';
foreach (['0151 15921554', '0208 / 207 658 0', '0151&nbsp;15921554', 'tel:+49 171 1700557', '0208 2076580 x 12'] as $value) {
    reset_stub(['{acf_phone}' => $value]);
    $replaced = Controller::render_content('{acf_phone @format:tel}', $post, 'text');
    assert_true(preg_match($alphabet, $replaced) === 1, "9: alphabet {$value} -> {$replaced}");
}
reset_stub(['{acf_phone}' => '0208 2076580 x 12']);
assert_same('+492082076580;ext=12', Controller::render_content('{acf_phone @format:tel}', $post, 'text'), '9: extension kept through the controller');

// 10. Any simple tag name, not only ACF.
reset_stub(['{cf_phone}' => '0151 1', '{woo_billing_phone}' => '0208 2', '{my-tag_2}' => '0201 3']);
assert_same('+491511', Controller::render_content('{cf_phone @format:tel}', $post, 'text'), '10: cf_ tag');
assert_same('+492082', Controller::render_content('{woo_billing_phone @format:tel}', $post, 'text'), '10: other provider');
assert_same('+492013', Controller::render_content('{my-tag_2 @format:tel}', $post, 'text'), '10: hyphen and digit in name');
foreach (["+49123\n", '+49"1', '+49<1', '+49;ext=1a'] as $bad) {
    assert_true(preg_match($alphabet, $bad) !== 1, '9: alphabet rejects ' . json_encode($bad));
}

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "tel-format-test: PASS\n";
