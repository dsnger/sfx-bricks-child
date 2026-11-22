<?php

declare(strict_types=1);

namespace SFX {
    class AccessControl
    {
        public static int $died = 0;
        public static function can_access_theme_settings(): bool { return true; }
        public static function die_if_unauthorized_theme(): void { self::$died++; }
    }
    class SFXBricksChildTheme
    {
        public static bool $on = true;
        public static function is_general_option_enabled(string $k): bool { return $k === 'enable_markdown' && self::$on; }
    }
}

namespace {
    class WP_Error
    {
        public function get_error_message() { return 'boom'; }
    }
    $test_options = [];
    $queue = [];
    $requests = [];
    $newest = 'https://site.test/a-post/';
    $submenu = [];

    function get_option($n, $d = false) { global $test_options; return array_key_exists($n, $test_options) ? $test_options[$n] : $d; }
    function add_action($h, $cb, $p = 10, $a = 1) { return true; }
    function register_setting($g, $n, $a = []) { return true; }
    function add_submenu_page(...$a) { global $submenu; $submenu = $a; }
    function get_post_types($args = [], $out = 'names')
    {
        $all = ['page' => 'Pages', 'post' => 'Posts <b>', 'job' => 'Jobs'];
        $o = [];
        foreach ($all as $n => $l) {
            $o[$n] = (object) ['name' => $n, 'label' => $l, 'labels' => (object) ['name' => $l]];
        }
        return $o;
    }
    function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . $v; }
    function home_url($p = '') { return 'https://site.test' . $p; }
    function apply_filters($h, $v, ...$a) { return $v; }
    function is_wp_error($t) { return $t instanceof WP_Error; }
    function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['response']['code'] ?? '') : ''; }
    function wp_remote_retrieve_header($r, $h) { return $r['headers'][strtolower($h)] ?? ''; }
    function wp_remote_get($url, $args = []) { global $requests, $queue; $requests[] = $url; return array_shift($queue); }
    $tr = []; // Translations, to show a translated string is escaped.
    function __($t, $d = null) { return $GLOBALS['tr'][$t] ?? $t; }
    function esc_html__($t, $d = null) { return htmlspecialchars(__($t)); }
    function esc_html_e($t, $d = null) { echo htmlspecialchars(__($t)); }
    function admin_url($p = '') { return 'https://site.test/wp-admin/' . $p; }
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true) { echo '<input type="hidden" name="' . $name . '" value="nonce-for:' . $action . '" />'; }
    function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'good' && $action === 'sfx_markdown_check' ? 1 : false; }
    function wp_unslash($v) { return $v; }
    function sanitize_text_field($v) { return trim((string) $v); }
    function esc_html($t) { return htmlspecialchars((string) $t); }
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function esc_url($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function settings_fields($g) { echo '<!--fields:' . $g . '-->'; }
    function submit_button() { echo '<button>save</button>'; }
    function checked($c) { echo $c ? ' checked' : ''; }

    // Stand-ins for the collaborators; the real ones have their own tests.
    require_once __DIR__ . '/../inc/Markdown/Settings.php';
    require_once __DIR__ . '/../inc/Markdown/LoopbackCheck.php';
    require_once __DIR__ . '/../inc/Markdown/AdminPage.php';

    final class WP_HTML_Processor
    {
        public static function create_full_parser($h) { return null; }
    }
}

namespace SFX\Markdown {
    // Declared before Request/LlmsTxt load, so these replace them for the page test.
    class LlmsTxt
    {
        public static function newest_listed_url(): ?string { return $GLOBALS['newest']; }
    }
    class Request
    {
        public static bool $parser = true;
        public static function parser_available(): bool { return self::$parser; }
    }

    function render(): string
    {
        ob_start();
        AdminPage::render_page();
        return (string) ob_get_clean();
    }
    function assert_true($c, string $m): void
    {
        if (!$c) { fwrite(STDERR, "FAIL: {$m}\n"); exit(1); }
    }
    function has(string $html, string $needle, string $m): void { assert_true(str_contains($html, $needle), $m . " (missing: {$needle})"); }
    function hasnt(string $html, string $needle, string $m): void { assert_true(!str_contains($html, $needle), $m . " (unexpected: {$needle})"); }
    function res(int $code, array $h = []): array { return ['response' => ['code' => $code], 'headers' => array_change_key_case($h)]; }

    assert_true(AdminPage::$menu_slug === 'sfx-markdown', '0: menu slug');
    AdminPage::add_submenu_page();
    assert_true(($GLOBALS['submenu'][0] ?? '') === 'sfx-theme-settings' && ($GLOBALS['submenu'][4] ?? '') === 'sfx-markdown' && ($GLOBALS['submenu'][3] ?? '') === 'manage_options', '0: submenu registered under theme settings');

    // 0b. Without the request parameter: button and explanation, no loopback request.
    $GLOBALS['test_options'] = ['sfx_markdown_options' => ['post_types' => ['page']]];
    $_GET = [];
    $GLOBALS['tr'] = ['Run checks' => 'Run <checks>'];
    $html = render();
    assert_true($GLOBALS['requests'] === [], '0b: no request without the parameter');
    has($html, 'method="get" action="https://site.test/wp-admin/admin.php"', '0b: check form is a GET to admin.php');
    has($html, 'name="page" value="sfx-markdown"', '0b: form stays on the page');
    has($html, 'name="sfx_markdown_check" value="1"', '0b: form sends the parameter');
    has($html, 'value="nonce-for:sfx_markdown_check"', '0b: form carries the nonce');
    has($html, 'Run &lt;checks&gt;', '0b: button label escaped');
    hasnt($html, 'Run <checks>', '0b: button label not raw');
    has($html, 'The checks ask this site for', '0b: one line explaining the checks');
    has($html, 'https://site.test/llms.txt', '0b: examples kept');
    has($html, 'CDN', '0b: CDN limit sentence kept');
    hasnt($html, 'did not run', '0b: no bad-nonce notice');
    $GLOBALS['tr'] = [];

    // 0c. Parameter with a bad or missing nonce: no request, a short notice.
    foreach ([['sfx_markdown_check' => '1', '_wpnonce' => 'bad'], ['sfx_markdown_check' => '1']] as $get) {
        $_GET = $get;
        $html = render();
        assert_true($GLOBALS['requests'] === [], '0c: no request with a bad nonce');
        has($html, 'The checks did not run', '0c: bad-nonce notice');
    }
    assert_true(\SFX\AccessControl::$died === 3, '0c: capability gate called on every load');
    \SFX\AccessControl::$died = 0;

    // From here on the admin clicked the button: valid nonce.
    $_GET = ['sfx_markdown_check' => '1', '_wpnonce' => 'good'];

    // 1. Module on, everything available: form, checkboxes, examples, checks.
    $GLOBALS['queue'] = [
        res(200, ['X-SFX-Llms-Txt' => '1', 'Content-Type' => 'text/plain']),
        res(200, ['Content-Type' => 'text/markdown']), res(200, ['Content-Type' => 'text/html']), res(200, ['Content-Type' => 'text/markdown']),
    ];
    $html = render();
    assert_true(\SFX\AccessControl::$died === 1, '1: capability gate called');
    has($html, 'action="options.php"', '1: form posts to options.php');
    has($html, '<!--fields:sfx_markdown_options-->', '1: settings_fields');
    has($html, 'name="sfx_markdown_options[_present]" value="1"', '1: hidden presence field');
    has($html, 'name="sfx_markdown_options[post_types][]" value="page"  checked', '1: page checked');
    has($html, 'name="sfx_markdown_options[post_types][]" value="post"', '1: post offered');
    hasnt($html, 'value="post"  checked', '1: post not checked');
    has($html, 'Posts &lt;b&gt;', '1: label escaped');
    hasnt($html, 'Posts <b>', '1: label not raw');
    has($html, 'https://site.test/a-post/?markdown=1', '1: ?markdown=1 example');
    has($html, 'Accept: text/markdown', '1: Accept example');
    has($html, 'https://site.test/llms.txt', '1: llms.txt example');
    has($html, 'CDN', '1: CDN limit sentence');
    has($html, 'A page with a password is served only to visitors who have entered it, and is never listed in llms.txt.', '1: password sentence matches Request::is_eligible');
    has($html, 'The theme serves /llms.txt.', '1: llms outcome theme');
    has($html, 'No page cache problem found', '1: cache clean');
    assert_true($GLOBALS['requests'] === ['https://site.test/llms.txt', 'https://site.test/a-post/?markdown=1', 'https://site.test/a-post/', 'https://site.test/a-post/'], '1: request sequence');

    // 2. Foreign llms.txt shows guidance, escaped Location for redirects.
    $GLOBALS['requests'] = [];
    $GLOBALS['queue'] = [res(200, ['Content-Type' => 'text/plain']), res(200, ['Content-Type' => 'text/markdown']), res(200, ['Content-Type' => 'text/html']), res(200, ['Content-Type' => 'text/html'])];
    $html = render();
    has($html, 'If a file llms.txt stays in the site root, remove it there.', '2: foreign guidance');
    has($html, 'answers Markdown requests by', '2: cache_html message');
    $GLOBALS['queue'] = [res(302, ['Location' => '/login?a=1&b="2"']), res(500)];
    $html = render();
    has($html, '/login?a=1&amp;b=&quot;2&quot;', '2: Location escaped');
    hasnt($html, 'remove it there', '2: redirect gives no guidance');
    has($html, 'Could not check', '2: cache check failed');

    // 3. Module off or parser missing: no loopback request at all.
    $GLOBALS['requests'] = [];
    \SFX\SFXBricksChildTheme::$on = false;
    $html = render();
    assert_true($GLOBALS['requests'] === [], '3: module off, no requests');
    hasnt($html, 'name="sfx_markdown_check"', '3: module off, no check button');
    has($html, 'action="options.php"', '3: form still shown');
    \SFX\SFXBricksChildTheme::$on = true;
    Request::$parser = false;
    $html = render();
    assert_true($GLOBALS['requests'] === [], '3: parser missing, no requests');
    hasnt($html, 'name="sfx_markdown_check"', '3: parser missing, no check button');
    has($html, 'WordPress 6.7', '3: WP 6.7 notice');
    Request::$parser = true;

    // 4. Nothing listed: "no page to check", llms check still runs.
    $GLOBALS['newest'] = null;
    $GLOBALS['queue'] = [res(404)];
    $html = render();
    has($html, 'No page to check', '4: no page');
    assert_true($GLOBALS['requests'] === ['https://site.test/llms.txt'], '4: only llms request');
    has($html, 'No llms.txt is served', '4: none outcome');

    // 5. Control not converting.
    $GLOBALS['newest'] = 'https://site.test/a-post/';
    $GLOBALS['queue'] = [res(404), res(200, ['Content-Type' => 'text/html'])];
    $html = render();
    has($html, 'does not convert', '5: no_convert message');
    echo "markdown-admin-page-test: OK\n";
}
