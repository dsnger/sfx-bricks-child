<?php

declare(strict_types=1);

namespace SFX {
    class AccessControl
    {
        public static function can_access_theme_settings(): bool { return true; }
        public static function die_if_unauthorized_theme(): void {}
    }
}

namespace {
    define('BRICKS_VERSION', '2.4.2');

    $test_options = [];
    $enqueued_scripts = [];
    $inline_scripts = [];
    $enqueued_styles = [];
    $screen = null;
    $css_suffix = '';

    function get_option($name, $default = false) { global $test_options; return array_key_exists($name, $test_options) ? $test_options[$name] : $default; }
    function add_action($hook, $cb, $p = 10, $a = 1) { return true; }
    function register_setting($g, $n, $a = []) { return true; }
    function post_type_exists($pt) { return in_array($pt, ['post', 'page'], true); }
    function use_block_editor_for_post_type($pt) { return true; }
    function is_admin() { return true; }
    function get_current_screen() { global $screen; return $screen; }
    function get_the_ID() { return 7; }
    function get_post_type($id) { return 'post'; }
    function get_stylesheet_directory() { return dirname(__DIR__); }
    function get_stylesheet_directory_uri() { return 'https://site.test/wp-content/themes/sfx-bricks-child'; }
    function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . $v; }
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); } // WordPress passes flags through
    function apply_filters($hook, $value, ...$args) { global $css_suffix; return $hook === 'sfx_editor_prose_css' ? $value . $css_suffix : $value; }
    function wp_enqueue_script($h, $src, $deps, $ver, $footer) { global $enqueued_scripts; $enqueued_scripts[$h] = [$src, $deps, $footer]; }
    function wp_add_inline_script($h, $js, $pos) { global $inline_scripts; $inline_scripts[$h] = [$js, $pos]; }
    function wp_enqueue_style($h, $src, $deps, $ver) { global $enqueued_styles; $enqueued_styles[$h] = [$src, $deps]; }
    function __($t, $d = null) { return $t; }
    function esc_html__($t, $d = null) { return htmlspecialchars($t); }
    function esc_html_e($t, $d = null) { echo htmlspecialchars($t); }
    function esc_html($t) { return htmlspecialchars((string) $t); }
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function esc_attr__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES); }
    function settings_fields($g) { echo '<!--fields:' . $g . '-->'; }
    function submit_button() { echo '<button>save</button>'; }
    function selected($a, $b) { echo $a === $b ? ' selected' : ''; }
    function checked($c) { echo $c ? ' checked' : ''; }
    function get_post_types($args, $out) { return [(object) ['name' => 'post', 'labels' => (object) ['singular_name' => 'Post']]]; }

    require_once __DIR__ . '/../inc/EditorProse/Settings.php';
    require_once __DIR__ . '/../inc/EditorProse/Payload.php';
    require_once __DIR__ . '/../inc/EditorProse/Starter.php';
    require_once __DIR__ . '/../inc/EditorProse/AdminPage.php';
    require_once __DIR__ . '/../inc/EditorProse/Controller.php';

    use SFX\EditorProse\AdminPage;
    use SFX\EditorProse\Controller;

    function assert_true($cond, string $message): void
    {
        if (!$cond) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    }
    function reset_state(array $options, string $base = 'post'): void
    {
        global $test_options, $enqueued_scripts, $inline_scripts, $enqueued_styles, $screen;
        $test_options = ['sfx_editor_prose_options' => $options];
        $enqueued_scripts = $inline_scripts = $enqueued_styles = [];
        $screen = new class($base) {
            public $base;
            public function __construct($b) { $this->base = $b; }
            public function is_block_editor() { return true; }
        };
    }

    // 1. Module on, nothing configured -> editor untouched (Review Focus 1).
    reset_state([]);
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [] && $inline_scripts === [], '1: no script without classes or baseline');

    // 2. Not the post editor (widgets / site editor) -> untouched.
    reset_state(['classes' => 'prose'], 'widgets');
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '2: widgets screen ignored');

    // 3. Configured -> script in the footer plus config before it; CSS that tries to end the
    //    script element is serialized safely and decodes losslessly (Review Focus 5).
    reset_state(['classes' => 'prose', 'title_gap' => '1em']);
    $css_suffix = '<!--<script></script><script>alert(1)</script>&';
    Controller::enqueue_editor();
    $css_suffix = '';
    assert_true(isset($enqueued_scripts['sfx-editor-prose']) && $enqueued_scripts['sfx-editor-prose'][2] === true, '3: script enqueued in footer');
    [$js, $pos] = $inline_scripts['sfx-editor-prose'];
    assert_true($pos === 'before', '3: config before the script');
    assert_true(strpbrk($js, '<>') === false, '3: no literal < or > in the inline script');
    $json = substr($js, strlen('window.sfxEditorProseConfig = '), -1);
    $config = json_decode($json, true);
    assert_true($config['classes'] === ['brxe-text', 'prose'], '3: classes in config');
    assert_true(str_ends_with($config['css'], '<!--<script></script><script>alert(1)</script>&'), '3: css decodes losslessly');

    // 3b. Excluded post type and non-block-editor screen -> untouched.
    reset_state(['classes' => 'prose', 'all_post_types' => '0', 'post_types' => ['page']]);
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '3b: post type not selected');
    reset_state(['classes' => 'prose']);
    $screen = new class { public $base = 'post'; public function is_block_editor() { return false; } };
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '3b: classic editor screen ignored');

    // 4. Baseline: editor config carries the versioned prose.css link; frontend enqueues it without dependencies.
    reset_state(['baseline' => '1']);
    Controller::enqueue_editor();
    $config = json_decode(substr($inline_scripts['sfx-editor-prose'][0], strlen('window.sfxEditorProseConfig = '), -1), true);
    assert_true(str_starts_with($config['links'][0], 'https://site.test/wp-content/themes/sfx-bricks-child/inc/EditorProse/assets/prose.css?ver='), '4: baseline link in editor config');
    Controller::enqueue_frontend();
    assert_true(isset($enqueued_styles['sfx-prose']) && $enqueued_styles['sfx-prose'][1] === [], '4: frontend baseline enqueued without dependencies');
    reset_state(['classes' => 'prose']);
    Controller::enqueue_frontend();
    assert_true(!isset($enqueued_styles['sfx-prose']), '4: no frontend baseline when off');

    // 5. Settings page: hidden "0" inputs precede both checkboxes (Review Focus 4); missing class named (Review Focus 2).
    reset_state(['classes' => 'typo', 'baseline' => '1']);
    ob_start();
    AdminPage::render_page();
    $html = (string) ob_get_clean();
    foreach (['all_post_types', 'baseline'] as $key) {
        $hidden = strpos($html, 'type="hidden" name="sfx_editor_prose_options[' . $key . ']" value="0"');
        $box = strpos($html, 'type="checkbox" name="sfx_editor_prose_options[' . $key . ']" value="1"');
        assert_true($hidden !== false && $box !== false && $hidden < $box, "5: hidden 0 before the {$key} checkbox");
    }
    assert_true(strpos($html, 'No Bricks global class with this name: typo') !== false, '5: missing class named');
    assert_true(strpos($html, 'sfx-prose') !== false, '5: baseline hint shown');
    assert_true(strpos($html, 'id="sfx_ep_starter"') !== false, '5: starter field rendered');
    assert_true(strpos($html, '%root% {') !== false, '5: starter CSS in the field');
    assert_true(strpos($html, 'id="sfx_ep_starter_copy"') !== false, '5: copy button rendered');

    echo "editor-prose-controller-test: PASS\n";
}
