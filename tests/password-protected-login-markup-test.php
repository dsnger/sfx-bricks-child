<?php

/**
 * Verifies the standalone login template (login-form.php) is compatible with the
 * WordPress / password-protected login hook + DOM contract, so snippets that
 * enqueue styles on login_enqueue_scripts actually take effect on this screen.
 *
 * The template calls neither wp_head() nor the wp-login.php pipeline, so it must
 * (1) fire the enqueue hooks and (2) print the enqueued styles itself. This test
 * renders the page through a minimal WP_Styles stand-in that models the two
 * things that matter: dependency resolution (a missing dep drops the dependent,
 * like core) and single-emit dedup. It then asserts the head and body markup.
 */

declare(strict_types=1);

// Test doubles for the module's own classes, in their own namespace block.
namespace SFX\PasswordProtected {

    class Settings
    {
        public static function get(): array
        {
            return ['allow_remember_me' => true];
        }

        /** @param array<string, mixed> $source */
        public static function request_string(array $source, string $key): string
        {
            return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : '';
        }
    }

    class Controller
    {
        public static ?\WP_Error $errors = null;

        public static function login_url(string $redirect_to = ''): string
        {
            return 'https://example.test/?sfx-protected=login';
        }
    }
}

namespace {

    // Let the template define its render function without auto-running + exit().
    define('SFX_PP_LOGIN_FORM_TEST', true);
    define('ABSPATH', dirname(__DIR__) . '/');

    $failures = 0;

    function assert_true(bool $condition, string $message): void
    {
        global $failures;
        if (!$condition) {
            echo "FAIL: {$message}\n";
            $failures++;
        }
    }

    function assert_count_once(string $needle, string $haystack, string $message): void
    {
        $n = substr_count($haystack, $needle);
        assert_true($n === 1, "{$message} (expected exactly 1 '{$needle}', got {$n})");
    }

    // --- Minimal WP_Styles stand-in --------------------------------------------

    final class TestStyles
    {
        /** @var array<string, array{src:string, deps:array<int,string>}> */
        public array $registered = [];
        /** @var array<int, string> */
        public array $queue = [];
        /** @var array<string, array<int,string>> */
        public array $inline = [];
        /** @var array<string, bool> */
        private array $done = [];

        /** @return array|false */
        public function query(string $handle)
        {
            return $this->registered[$handle] ?? false;
        }

        public function register(string $handle, string $src, array $deps = []): void
        {
            $this->registered[$handle] = ['src' => $src, 'deps' => $deps];
        }

        public function enqueue(string $handle): void
        {
            if (!in_array($handle, $this->queue, true)) {
                $this->queue[] = $handle;
            }
        }

        public function add_inline(string $handle, string $css): void
        {
            $this->inline[$handle][] = $css;
        }

        /**
         * Mirrors wp_print_styles(): a bare call flushes the whole enqueue queue and
         * fires the global action; an explicit handle list prints only those handles
         * (plus deps) and fires no action.
         *
         * @param array<int,string>|false $handles
         */
        public function print_all($handles = false): void
        {
            if ($handles === false) {
                do_action('wp_print_styles');
                $handles = $this->queue;
            }
            foreach ($handles as $handle) {
                $this->do_item($handle);
            }
        }

        private function do_item(string $handle): void
        {
            if (isset($this->done[$handle]) || !isset($this->registered[$handle])) {
                return;
            }

            // Core drops an item whose dependency is unregistered (WP_Dependencies::all_deps).
            foreach ($this->registered[$handle]['deps'] as $dep) {
                if (!isset($this->registered[$dep])) {
                    return;
                }
            }

            foreach ($this->registered[$handle]['deps'] as $dep) {
                $this->do_item($dep);
            }

            $this->done[$handle] = true;
            echo "<link rel='stylesheet' id='{$handle}-css' href='{$this->registered[$handle]['src']}' media='all' />\n";

            foreach ($this->inline[$handle] ?? [] as $css) {
                echo "<style id='{$handle}-inline-css'>{$css}</style>\n";
            }
        }
    }

    $GLOBALS['test_styles'] = new TestStyles();
    // Core admin/login styles as wp_default_styles() registers them. 'dashicons' is
    // deliberately absent: the WP Optimizer feature deregisters it on the frontend,
    // and the template is expected to put it back so 'login' still resolves.
    $GLOBALS['test_styles']->register('buttons', '/wp-admin/css/buttons.css');
    $GLOBALS['test_styles']->register('forms', '/wp-admin/css/forms.css');
    $GLOBALS['test_styles']->register('login', '/wp-admin/css/login.css', ['dashicons', 'buttons', 'forms']);

    function wp_styles(): TestStyles
    {
        return $GLOBALS['test_styles'];
    }

    function wp_register_style(string $handle, string $src, array $deps = [], $ver = null, string $media = 'all'): bool
    {
        wp_styles()->register($handle, $src, $deps);
        return true;
    }

    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = null, string $media = 'all'): void
    {
        if ($src !== '') {
            wp_styles()->register($handle, $src, $deps);
        }
        wp_styles()->enqueue($handle);
    }

    function wp_add_inline_style(string $handle, string $css): bool
    {
        wp_styles()->add_inline($handle, $css);
        return true;
    }

    function wp_print_styles($handles = false): void
    {
        wp_styles()->print_all($handles);
    }

    // --- Minimal hook registry -------------------------------------------------

    function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['test_actions'][$hook][$priority][] = $cb;
        return true;
    }

    function do_action(string $hook, ...$args): void
    {
        if (empty($GLOBALS['test_actions'][$hook])) {
            return;
        }
        ksort($GLOBALS['test_actions'][$hook]);
        foreach ($GLOBALS['test_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $cb) {
                $cb(...$args);
            }
        }
    }

    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }

    // --- Remaining WordPress surface the template touches ----------------------

    function __($text, $domain = 'default')
    {
        return $text;
    }

    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function esc_url($url): string
    {
        return trim((string) $url);
    }

    function esc_html_e($text, $domain = 'default'): void
    {
        echo esc_html($text);
    }

    function esc_attr_e($text, $domain = 'default'): void
    {
        echo esc_attr($text);
    }

    function language_attributes(): void
    {
        echo 'lang="en-US"';
    }

    function bloginfo(string $show = ''): void
    {
        echo get_bloginfo($show);
    }

    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        switch ($show) {
            case 'charset':
                return 'UTF-8';
            case 'html_type':
                return 'text/html';
            case 'version':
                return '6.5';
            case 'name':
            default:
                return 'Test Site';
        }
    }

    function home_url(string $path = '/'): string
    {
        return 'https://example.test' . $path;
    }

    function includes_url(string $path = ''): string
    {
        return 'https://example.test/wp-includes/' . $path;
    }

    function wp_nonce_field(string $action = '-1'): void
    {
        echo '<input type="hidden" name="_wpnonce" value="test-nonce" />';
    }

    function wp_site_icon(): void
    {
        echo "<link rel='icon' href='https://example.test/icon.png' />\n";
    }

    function nocache_headers(): void
    {
    }

    class WP_Error
    {
        /** @var array<int, string> */
        private array $messages = [];

        public function add(string $code, string $message): void
        {
            $this->messages[] = $message;
        }

        public function has_errors(): bool
        {
            return $this->messages !== [];
        }

        /** @return array<int, string> */
        public function get_error_messages(): array
        {
            return $this->messages;
        }
    }

    // --- Drive the template ----------------------------------------------------

    $errors = new \WP_Error();
    $errors->add('incorrect_password', 'Incorrect password.');
    \SFX\PasswordProtected\Controller::$errors = $errors;

    // A stale redirect target with characters that must be escaped in the hidden field.
    $_REQUEST['redirect_to'] = 'https://example.test/members/?a=1&b=2';

    // A frontend/protected style some plugin already enqueued before the gate renders.
    // The template must NOT flush it onto the login screen (no wp_head-style leak).
    wp_enqueue_style('theme-frontend', 'https://example.test/wp-content/themes/x/style.css', []);

    // The compatibility target: a snippet registering its styles on login_enqueue_scripts.
    add_action('login_enqueue_scripts', static function (): void {
        wp_enqueue_style('client-gate', 'https://cdn.example/gate.css', []);
        wp_add_inline_style('client-gate', '.login #login_error{color:#c00}');
    }, 20);

    // Marker callbacks proving the remaining hooks fire (and in the right place).
    add_action('password_protected_login_head', static function (): void {
        echo "<!-- pp-login-head -->\n";
    });
    add_action('password_protected_before_login_form', static function (): void {
        echo "<!-- pp-before-form -->\n";
    });
    add_action('password_protected_after_login_form', static function (): void {
        echo "<!-- pp-after-form -->\n";
    });

    require dirname(__DIR__) . '/inc/PasswordProtected/login-form.php';

    if (!function_exists('sfx_pp_render_login_page')) {
        assert_true(false, 'sfx_pp_render_login_page() is not defined by login-form.php');
        echo "Tests failed: {$failures}\n";
        exit(1);
    }

    ob_start();
    sfx_pp_render_login_page();
    $html = ob_get_clean();

    $head = substr($html, 0, strpos($html, '<body') ?: strlen($html));

    // 1. Core login CSS and its deps print (dashicons re-registered by the template).
    assert_true(strpos($head, '/wp-admin/css/login.css') !== false, 'core login CSS is printed');
    assert_true(strpos($head, 'dashicons') !== false, 'dashicons dep is present (re-registered)');
    assert_true(strpos($head, '/wp-admin/css/buttons.css') !== false, 'buttons dep is printed');

    // 2. Styles enqueued via login_enqueue_scripts appear, exactly once.
    assert_count_once("href='https://cdn.example/gate.css'", $head, 'external login_enqueue_scripts style prints once');
    assert_count_once('.login #login_error{color:#c00}', $head, 'wp_add_inline_style CSS prints once');

    // 3. No duplicate core login link.
    assert_count_once("id='login-css'", $head, 'core login stylesheet is not emitted twice');

    // 3b. A style enqueued before the gate rendered must NOT leak onto it.
    assert_true(
        strpos($html, 'wp-content/themes/x/style.css') === false,
        'pre-existing frontend style is not flushed onto the gate screen'
    );

    // 4. Head hook fires, and after the stylesheets so echo-based overrides win the cascade.
    assert_true(strpos($head, '<!-- pp-login-head -->') !== false, 'password_protected_login_head fires');
    assert_true(
        strpos($head, "id='login-css'") < strpos($head, '<!-- pp-login-head -->'),
        'login CSS is printed before the head hook output'
    );

    // 5. DOM contract the login CSS + snippets are written against.
    assert_true(strpos($html, 'class="login wp-core-ui"') !== false, 'body keeps login wp-core-ui');
    assert_true(strpos($html, 'id="login"') !== false, '#login wrapper present');
    assert_true(strpos($html, 'id="loginform"') !== false, 'form is addressable via #loginform');
    assert_true(strpos($html, 'id="login_error"') !== false, '#login_error renders when errors exist');
    assert_true(strpos($html, 'class="forgetmenot"') !== false, '.forgetmenot present for remember-me');
    assert_true(strpos($html, 'button button-primary button-large') !== false, 'submit keeps .button-primary.button-large');

    // 6. Logo uses the login_headerurl / login_headertext filters inside .login h1 a.
    assert_true(strpos($html, '<h1') !== false && strpos($html, '</h1>') !== false, 'logo h1 present');

    // 7. The internal field contract the Controller reads is preserved.
    assert_true(strpos($html, 'name="sfx_pp_pwd"') !== false, 'password field name preserved');
    assert_true(strpos($html, 'name="sfx_pp_rememberme"') !== false, 'remember-me field name preserved');
    assert_true(strpos($html, 'name="_wpnonce"') !== false, 'nonce field present');
    assert_true(
        strpos($html, 'value="https://example.test/members/?a=1&amp;b=2"') !== false,
        'redirect_to is preserved and escaped'
    );

    // 8. Markup-injection hooks fire around the form.
    assert_true(strpos($html, '<!-- pp-before-form -->') !== false, 'before_login_form fires');
    assert_true(strpos($html, '<!-- pp-after-form -->') !== false, 'after_login_form fires');
    assert_true(
        strpos($html, '<!-- pp-before-form -->') < strpos($html, 'id="loginform"'),
        'before_login_form output precedes the form'
    );
    assert_true(
        strpos($html, '<!-- pp-after-form -->') > strpos($html, '</form>'),
        'after_login_form output follows the form'
    );

    if ($failures > 0) {
        echo "Tests failed: {$failures}\n";
        exit(1);
    }

    echo "PASS: all password-protected-login-markup tests\n";
    exit(0);
}
