<?php

declare(strict_types=1);

/**
 * SiteCheck server checks: the catalogue, every S check and the server half of
 * the S+B checks. Spec: docs/superpowers/specs/2026-10-10-site-check-design.md,
 * "Results" and "Check catalogue".
 *
 * Filesystem fixtures live in one temporary tree, removed by the single
 * teardown declared below before the first fixture. WordPress is stubbed;
 * the stubs read $GLOBALS['stub'].
 */

$tmp = sys_get_temp_dir() . '/sfx-site-check-server-' . bin2hex(random_bytes(6));

function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

// One teardown, declared before the first fixture is created.
register_shutdown_function(static function () use ($tmp): void {
    remove_tree($tmp);
});

if (!mkdir($tmp, 0700)) {
    fwrite(STDERR, "error: cannot create {$tmp}\n");
    exit(2);
}
$tmp = realpath($tmp);
if ($tmp === false || strpos($tmp, realpath(sys_get_temp_dir())) !== 0) {
    fwrite(STDERR, "error: temp dir is not under the system temp dir\n");
    exit(2);
}

foreach (['/site/wp-content/plugins', '/site/wp-content/uploads'] as $dir) {
    mkdir($tmp . $dir, 0700, true);
}
file_put_contents($tmp . '/site/wp-config.php', "<?php\n");

define('ABSPATH', $tmp . '/site/');
define('WP_CONTENT_DIR', $tmp . '/site/wp-content');
define('WP_PLUGIN_DIR', $tmp . '/site/wp-content/plugins');
// A custom log path with a PHP-like name: absent, so it must be dropped (rule 6, never fetched).
define('WP_DEBUG_LOG', $tmp . '/site/wp-content/debug.php.log');

// ---------------------------------------------------------------- stubs

function stub_reset(array $overrides = []): void
{
    $GLOBALS['stub'] = array_replace([
        'home'      => 'https://ex.test',
        'siteurl'   => 'https://ex.test',
        'options'   => [],
        'site_options' => [],
        'filters'   => [],
        'roles'     => [],
        'users'     => [],
        'posts'     => [],
        'comments'  => [],
        'plugins'   => [],
        'themes'    => [],
        'stylesheet' => 'sfx-bricks-child',
        'template'  => 'bricks',
        'transients' => [],
        'translations' => [],
    ], $overrides);
}
stub_reset();

function __($text, $domain = null)
{
    return $text;
}

function translate($text, $domain = 'default')
{
    return $GLOBALS['stub']['translations'][$text] ?? $text;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_normalize_path($path)
{
    $path = str_replace('\\', '/', $path);
    return preg_replace('|(?<=.)/+|', '/', $path);
}

function trailingslashit($value)
{
    return untrailingslashit($value) . '/';
}

function untrailingslashit($value)
{
    return rtrim($value, '/\\');
}

function join_url(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function home_url($path = '')
{
    return join_url($GLOBALS['stub']['home'], $path);
}

function site_url($path = '')
{
    return join_url($GLOBALS['stub']['siteurl'], $path);
}

function content_url($path = '')
{
    return $GLOBALS['stub']['content'] ?? 'https://ex.test/wp-content';
}

function plugins_url($path = '', $plugin = '')
{
    return 'https://ex.test/wp-content/plugins';
}

function get_home_path()
{
    return ABSPATH;
}

function wp_get_upload_dir()
{
    return ['basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://ex.test/wp-content/uploads', 'error' => false];
}

function get_option($name, $default = false)
{
    // WordPress always has a date and a time format.
    $options = $GLOBALS['stub']['options'] + ['date_format' => 'Y-m-d', 'time_format' => 'H:i'];
    return array_key_exists($name, $options) ? $options[$name] : $default;
}

/** Like WordPress: timezone_string, else UTC. */
function wp_timezone()
{
    return new DateTimeZone((string) get_option('timezone_string', '') ?: 'UTC');
}

/** Like WordPress' wp_date(): the timestamp in the given zone (default: the site's), in $format. */
function wp_date($format, $timestamp = null, $timezone = null)
{
    return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone ?? wp_timezone())->format($format);
}

function get_site_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['stub']['site_options']) ? $GLOBALS['stub']['site_options'][$name] : $default;
}

function apply_filters($hook, $value, ...$args)
{
    return array_key_exists($hook, $GLOBALS['stub']['filters']) ? $GLOBALS['stub']['filters'][$hook] : $value;
}

function is_multisite()
{
    return false;
}

final class WP_Role
{
    public $name;
    public $capabilities;

    public function __construct(string $name, array $capabilities)
    {
        $this->name = $name;
        $this->capabilities = $capabilities;
    }
}

function get_role($role)
{
    return isset($GLOBALS['stub']['roles'][$role]) ? new WP_Role($role, $GLOBALS['stub']['roles'][$role]) : null;
}

function wp_roles()
{
    $roles = [];
    foreach ($GLOBALS['stub']['roles'] as $name => $caps) {
        $roles[$name] = ['name' => $name, 'capabilities' => $caps];
    }
    return (object) ['roles' => $roles];
}

final class WP_User
{
    public $ID;
    public $user_login;
    public $caps;
    public $allcaps;

    public function __construct(int $id, string $login, array $caps, array $role_caps)
    {
        $this->ID = $id;
        $this->user_login = $login;
        $this->caps = $caps;
        // Like WP_User::get_role_caps(): role caps first, the user's own entries merged over them.
        $this->allcaps = array_merge($role_caps, $caps);
    }

    public function has_cap($cap)
    {
        return !empty($this->allcaps[$cap]);
    }
}

/** The stub ignores the query: the code under test must resolve grants itself. */
function get_users($args = [])
{
    return $GLOBALS['stub']['users'];
}

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';

    public function get_blog_prefix()
    {
        return $this->prefix;
    }
};

function get_post($id)
{
    return $GLOBALS['stub']['posts'][$id] ?? null;
}

function get_comment($id)
{
    return $GLOBALS['stub']['comments'][$id] ?? null;
}

function get_plugins()
{
    return $GLOBALS['stub']['plugins'];
}

final class StubTheme
{
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function get($header)
    {
        return $header === 'Name' ? $this->name : false;
    }
}

function wp_get_themes()
{
    return $GLOBALS['stub']['themes'];
}

function get_stylesheet()
{
    return $GLOBALS['stub']['stylesheet'];
}

function get_template()
{
    return $GLOBALS['stub']['template'];
}

function get_site_transient($name)
{
    return $GLOBALS['stub']['transients'][$name] ?? false;
}

foreach (['Status', 'Evidence', 'Finding', 'Locations', 'Options', 'RunContext', 'Catalogue', 'Mutex', 'Probe'] as $class) {
    require_once __DIR__ . '/../inc/SiteCheck/' . $class . '.php';
}
foreach (['ServerChecks', 'FileChecks', 'ConfigChecks', 'AccountChecks', 'CleanupChecks', 'OutsideChecks'] as $class) {
    require_once __DIR__ . '/../inc/SiteCheck/Checks/' . $class . '.php';
}

use SFX\SiteCheck\Catalogue;
use SFX\SiteCheck\Checks\FileChecks;
use SFX\SiteCheck\Checks\ServerChecks;
use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function ctx(string $profile = 'live', string $fallback = ''): RunContext
{
    return new RunContext('run1', $profile, [], [], $fallback, false);
}

function grade(string $id, array $obs, string $profile = 'live'): array
{
    $result = Catalogue::grade($id, $obs, ctx($profile));
    foreach (['status', 'findings', 'perspective', 'note'] as $key) {
        assert_true(array_key_exists($key, $result), "{$id}: result has {$key}");
    }
    foreach ($result['findings'] as $f) {
        assert_same(['id', 'status', 'label'], array_keys($f), "{$id}: finding shape");
        assert_true(strpos($f['id'], $id . ':') === 0, "{$id}: finding ID starts with the check ID");
    }
    return $result;
}

final class WP_Automatic_Updater
{
    public function is_vcs_checkout($context)
    {
        return !empty($GLOBALS['vcs_checkout']);
    }
}

function check(string $id, string $profile = 'live', string $fallback = ''): array
{
    $c = ctx($profile, $fallback);
    return Catalogue::grade($id, ServerChecks::observe($id, $c), $c);
}

function statuses(array $result): array
{
    return array_column($result['findings'], 'status', 'id');
}

function put(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $content);
}

// ---------------------------------------------------------------- catalogue

$spec_ids = [
    'security' => ['logs_public', 'config_copies', 'backups_public', 'vcs_env', 'phpinfo', 'dir_listing', 'php_in_uploads',
        'php_files_uploads', 'debug_display', 'allow_url_include', 'https', 'php_version', 'security_headers', 'file_editor',
        'xmlrpc', 'registration', 'auto_updates', 'admin_accounts', 'usernames_public', 'bricks_permissions'],
    'golive' => ['search_visibility', 'robots_txt', 'indexability', 'sitemap', 'sitemap_entries', 'admin_email', 'permalinks'],
    'cleanup' => ['test_content', 'inactive_plugins', 'inactive_themes', 'updates', 'public_files', 'version_leaks', 'table_prefix', 'app_passwords'],
];
$all = Catalogue::all();
$expected_ids = array_merge(...array_values($spec_ids));
assert_same($expected_ids, array_keys($all), 'catalogue lists every spec ID in spec order');
foreach ($spec_ids as $section => $ids) {
    foreach ($ids as $id) {
        assert_same($section, $all[$id]['section'], "{$id} section");
        assert_true(in_array($all[$id]['how'], ['S', 'B', 'S+B'], true), "{$id} how");
        assert_true(is_bool($all[$id]['monitor']), "{$id} monitor flag");
        assert_true($all[$id]['title'] !== '' && $all[$id]['why'] !== '' && $all[$id]['recommendation'] !== '', "{$id} title and guidance");
    }
}
$monitor = array_keys(array_filter($all, static fn($row) => $row['monitor']));
assert_same(['logs_public', 'config_copies', 'backups_public', 'vcs_env', 'phpinfo', 'dir_listing', 'php_in_uploads', 'php_files_uploads',
    'debug_display', 'https', 'registration', 'admin_accounts', 'usernames_public', 'bricks_permissions', 'search_visibility',
    'robots_txt', 'indexability', 'updates'], $monitor, '★ flags as in the spec');
$server = ['logs_public', 'config_copies', 'backups_public', 'vcs_env', 'phpinfo', 'php_files_uploads', 'debug_display', 'allow_url_include',
    'https', 'php_version', 'file_editor', 'registration', 'auto_updates', 'admin_accounts', 'bricks_permissions', 'search_visibility',
    'admin_email', 'permalinks', 'test_content', 'inactive_plugins', 'inactive_themes', 'updates', 'public_files', 'table_prefix', 'app_passwords'];
foreach ($expected_ids as $id) {
    assert_same(in_array($id, $server, true), ServerChecks::handles($id), "{$id}: handled by the server checks iff S or S+B");
}
assert_same(Status::UNKNOWN, Catalogue::grade('no_such_check', [], ctx())['status'], 'unknown ID → Nicht prüfbar');

// ---------------------------------------------------------------- debug_display

$debug = static fn($wp_debug, $display, $master) => grade('debug_display', ['wp_debug' => $wp_debug, 'wp_debug_display' => $display, 'wp_debug_log' => false, 'display_errors_master' => $master]);
assert_same(Status::RED, $debug(true, true, false)['status'], 'WP_DEBUG + display true → Rot');
assert_same(Status::YELLOW, $debug(true, false, true)['status'], 'WP_DEBUG + display false → Gelb');
assert_same(Status::YELLOW, $debug(true, 0, true)['status'], 'WP_DEBUG + display 0 → Gelb');
assert_same(Status::YELLOW, $debug(true, '', true)['status'], "WP_DEBUG + display '' → Gelb");
assert_same(Status::RED, $debug(true, null, true)['status'], 'WP_DEBUG + display null + master on → Rot');
$r = $debug(true, null, false);
assert_same(Status::HINT, $r['status'], 'WP_DEBUG + display null + master off → Hinweis');
assert_true(strpos($r['findings'][0]['label'], '.user.ini') !== false, 'master off names the folder-setting limitation');
assert_same(Status::RED, $debug(false, true, true)['status'], 'WP_DEBUG off + master on → Rot');
assert_same(Status::HINT, $debug(false, false, false)['status'], 'WP_DEBUG off + master off → Hinweis');
assert_same(Status::UNKNOWN, $debug(false, true, null)['status'], 'master value unreadable → Nicht prüfbar');
foreach ([true, false] as $a) {
    foreach ([true, false, null, 0, ''] as $b) {
        foreach ([true, false] as $c) {
            assert_true($debug($a, $b, $c)['status'] !== Status::GREEN, 'debug_display is never Grün');
        }
    }
}
assert_true(strpos($debug(true, true, true)['note'], 'enable_wp_debug_mode_checks') !== false, 'note names the unseen filter');
foreach (['stderr' => false, 'STDERR' => false, 'stdout' => true, '1' => true, 'On' => true, '0' => false, 'Off' => false, '' => false] as $raw => $on) {
    assert_same($on, \SFX\SiteCheck\Checks\ConfigChecks::display_errors_on((string) $raw), "display_errors={$raw}");
}
assert_same(Status::HINT, $debug(false, true, \SFX\SiteCheck\Checks\ConfigChecks::display_errors_on('stderr'))['status'], 'display_errors=stderr → Hinweis when PHP decides');

// ---------------------------------------------------------------- config_copies

put(ABSPATH . 'wp-config.php.bak', "<?php\ndefine( 'DB_PASSWORD', 'secret' );\n");
put(ABSPATH . 'wp-config.php.old', "<?php\n// old\n");
put(ABSPATH . 'wp-config-sample.php', "<?php\ndefine( 'DB_PASSWORD', 'password_here' );\n");
$obs = ServerChecks::observe('config_copies', ctx());
assert_true(strpos(json_encode($obs), 'secret') === false, 'config_copies observation holds no contents');
$r = Catalogue::grade('config_copies', $obs, ctx());
assert_same(Status::YELLOW, $r['status'], 'config copy → Gelb');
assert_same(['config_copies:/wp-config.php.bak', 'config_copies:/wp-config.php.old'], array_column($r['findings'], 'id'), 'copy with credentials listed first, sample ignored');
assert_true(strpos($r['findings'][0]['label'], 'credentials') !== false, 'copy with DB_PASSWORD marked with credentials');
assert_true(strpos($r['findings'][1]['label'], 'credentials') === false, 'copy without DB_PASSWORD not marked');
assert_true(strpos($r['note'], 'not tested') !== false, 'note says public readability is not tested');
unlink(ABSPATH . 'wp-config.php.bak');
unlink(ABSPATH . 'wp-config.php.old');
assert_same(Status::GREEN, check('config_copies')['status'], 'no copies → Grün (complete listing)');

// ---------------------------------------------------------------- phpinfo

put(ABSPATH . 'info.php', "<?php phpinfo(); ?>");
put(ABSPATH . 'test.php', "<?php echo 'hi';");
$r = check('phpinfo');
assert_same(Status::YELLOW, $r['status'], 'phpinfo files → Gelb');
assert_same(Status::YELLOW, statuses($r)['phpinfo:/info.php'] ?? null, 'file calling phpinfo( → Gelb');
assert_true(strpos($r['findings'][0]['label'], 'server details') !== false || strpos($r['findings'][1]['label'], 'server details') !== false, 'phpinfo label');
assert_same(Status::YELLOW, statuses($r)['phpinfo:/test.php'] ?? null, 'unknown test.php → Gelb');
unlink(ABSPATH . 'info.php');
unlink(ABSPATH . 'test.php');
assert_same(Status::GREEN, check('phpinfo')['status'], 'nothing → Grün');

// ---------------------------------------------------------------- php_files_uploads

$up = WP_CONTENT_DIR . '/uploads';
put($up . '/index.php', "<?php // Silence is golden");
put($up . '/2026/10/shell.php.jpg', "<?php eval(base64_decode('ZWNobyAxOw==')); ");
put($up . '/2026/10/tool.phtml', "<?php echo 'tool';");
put($up . '/2026/10/padded.php', "<?php\n" . str_repeat('/' . str_repeat('x', 63) . "\n", 70) . "system(\$_GET['c']);");
put($up . '/locked/inside.php', "<?php system(\$_GET['c']);");
chmod($up . '/locked', 0000);

// The probe folder (decided inside the critical section) is covered by site-check-probe-test.php.
$obs = ServerChecks::observe('php_files_uploads', ctx());
assert_true(strpos(json_encode($obs), 'base64_decode') === false, 'observation holds no file contents');
$r = Catalogue::grade('php_files_uploads', $obs, ctx());
$s = statuses($r);
assert_same(Status::YELLOW, $r['status'], 'php_files_uploads overall Gelb');
assert_same(Status::HINT, $s['php_files_uploads:/wp-content/uploads/index.php'] ?? null, 'silence placeholder → Hinweis');
assert_same('php_files_uploads:/wp-content/uploads/2026/10/shell.php.jpg', $r['findings'][0]['id'], 'shell pattern listed first');
assert_true(strpos($r['findings'][0]['label'], 'suspicious') !== false, 'shell finding says suspicious code');
assert_same(Status::YELLOW, $s['php_files_uploads:/wp-content/uploads/2026/10/tool.phtml'] ?? null, 'other PHP content → Gelb');
assert_same(Status::YELLOW, $s['php_files_uploads:/wp-content/uploads/2026/10/padded.php'] ?? null, 'padded file → Gelb');
$padded = array_values(array_filter($r['findings'], static fn($f) => $f['id'] === 'php_files_uploads:/wp-content/uploads/2026/10/padded.php'))[0];
assert_true(strpos($padded['label'], 'suspicious') === false, 'a pattern after the first 4 KB is not read');
assert_same(Status::UNKNOWN, $s['php_files_uploads:/wp-content/uploads/locked'] ?? null, 'unreadable subfolder → Nicht prüfbar, named');

$limited = FileChecks::scan_uploads($up, \SFX\SiteCheck\Locations::current(), 3, 5.0);
$lr = Catalogue::grade('php_files_uploads', $limited, ctx());
$unknown = array_values(array_filter($lr['findings'], static fn($f) => $f['status'] === Status::UNKNOWN && strpos($f['id'], ':scan-limit') !== false));
assert_same(1, count($unknown), 'scan limit hit → one Nicht prüfbar for the rest');
assert_true(strpos($unknown[0]['label'], '3') !== false, 'scan limit finding names the limit');
chmod($up . '/locked', 0700);
remove_tree($up);
mkdir($up, 0700);
assert_same(Status::GREEN, check('php_files_uploads')['status'], 'no PHP-like files → Grün');
// Gate B pass 2 (spec-2): a listed folder whose entries cannot be stat'ed is named, never Grün.
if (!(function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    put($up . '/listed/child/payload.php', "<?php system(\$_GET['c']);");
    chmod($up . '/listed', 0400);
    $r = check('php_files_uploads');
    chmod($up . '/listed', 0700);
    assert_true($r['status'] !== Status::GREEN, 'listing allowed, stat denied → never Grün');
    assert_same(Status::UNKNOWN, statuses($r)['php_files_uploads:/wp-content/uploads/listed'] ?? null, 'the folder is named Nicht prüfbar');
    remove_tree($up . '/listed');
}

// ---------------------------------------------------------------- logs_public / backups_public / vcs_env (server half)

put(WP_CONTENT_DIR . '/debug.log', "[10-Oct-2026 10:00:00 UTC] PHP Warning: x\n");
put(ABSPATH . '.git/HEAD', "ref: refs/heads/main\n");
put(ABSPATH . 'site.sql', "-- MySQL dump\n");
put(ABSPATH . 'site.php.zip', 'PK');
put(WP_CONTENT_DIR . '/updraft/backup_db.gz', 'x');
put(WP_CONTENT_DIR . '/updraft/backup_db.sql.gz', 'x');

$obs = ServerChecks::observe('logs_public', ctx());
$by_target = array_column($obs['targets'], null, 'target');
assert_same('present', $by_target['/wp-content/debug.log']['disk'] ?? null, 'default debug.log present');
assert_same('https://ex.test/wp-content/debug.log', $by_target['/wp-content/debug.log']['url'], 'debug.log mapped to its URL');
assert_same('absent', $by_target['/error_log']['disk'] ?? null, 'error_log in the root absent');
assert_true(!isset($by_target['/wp-content/debug.php.log']), 'absent PHP-named custom log path is dropped');
assert_true(strpos(json_encode($obs), 'PHP Warning') === false, 'log contents are never observed');
foreach ($obs['targets'] as $t) {
    assert_same(['target', 'path', 'url', 'disk', 'size', 'reason'], array_keys($t), 'target shape');
}

$r = Catalogue::grade('logs_public', $obs, ctx());
assert_same(Status::UNKNOWN, $r['status'], 'no outside half yet → Nicht prüfbar');
foreach ($obs['targets'] as $i => $t) {
    $obs['targets'][$i]['outside'] = Evidence::UNREACHABLE;
}
$s = statuses(Catalogue::grade('logs_public', $obs, ctx()));
assert_same(Status::YELLOW, $s['logs_public:/wp-content/debug.log'], 'present + blocked → Gelb');
assert_same(Status::GREEN, $s['logs_public:/error_log'], 'absent + blocked → Grün');
$obs['targets'][0]['outside'] = Evidence::SIGNATURE;
assert_same(Status::RED, Catalogue::grade('logs_public', $obs, ctx())['status'], 'signature → Rot');

$obs = ServerChecks::observe('vcs_env', ctx());
$by_target = array_column($obs['targets'], null, 'target');
assert_same('present', $by_target['/.git/HEAD']['disk'] ?? null, '.git/HEAD present');
assert_same('absent', $by_target['/.env']['disk'] ?? null, '.env absent');
chmod(ABSPATH . '.git', 0000);
$obs = ServerChecks::observe('vcs_env', ctx());
chmod(ABSPATH . '.git', 0700);
$by_target = array_column($obs['targets'], null, 'target');
assert_same('unknown', $by_target['/.git/HEAD']['disk'] ?? null, 'denied stat on .git → unknown, never absent');
$obs['targets'] = array_map(static function ($t) {
    $t['outside'] = Evidence::UNREACHABLE;
    return $t;
}, $obs['targets']);
assert_same(Status::UNKNOWN, statuses(Catalogue::grade('vcs_env', $obs, ctx()))['vcs_env:/.git/HEAD'], 'unknown disk + blocked → Nicht prüfbar');

$obs = ServerChecks::observe('backups_public', ctx());
$by_target = array_column($obs['targets'], null, 'target');
assert_true(isset($by_target['/site.sql']), 'sql dump in the root found');
assert_true(isset($by_target['/site.php.zip']) && $by_target['/site.php.zip']['url'] === null, 'a PHP-like name is never given a URL (rule 6)');
assert_true(isset($by_target['/wp-content/updraft/backup_db.sql.gz']), 'backup plugin folder scanned');
assert_true(!isset($by_target['/wp-content/updraft/backup_db.gz']), '.gz alone is not a backup extension');
$with_signature = static function (array $obs, string $group): array {
    $obs['targets'] = array_map(static function ($t) use ($group) {
        $t['outside'] = Evidence::SIGNATURE;
        $t['outside_signature'] = $group;
        return $t;
    }, $obs['targets']);
    return $obs;
};
$s = statuses(Catalogue::grade('backups_public', $with_signature($obs, 'archive'), ctx()));
assert_same(Status::YELLOW, $s['backups_public:/wp-content/updraft/backup_db.sql.gz'] ?? null, '.sql.gz with only gzip magic → Gelb, never Rot');
$s = statuses(Catalogue::grade('backups_public', $with_signature($obs, 'sql'), ctx()));
assert_same(Status::RED, $s['backups_public:/wp-content/updraft/backup_db.sql.gz'] ?? null, '.sql.gz served decoded with the SQL header → Rot');
assert_same(Status::RED, $s['backups_public:/site.sql'] ?? null, 'SQL dump header → Rot');
assert_same(Status::UNKNOWN, $s['backups_public:/site.php.zip'] ?? null, 'never-fetched PHP-like archive → Nicht prüfbar');

// Off-host (CDN) content root: an absent debug.log is reported, not dropped.
unlink(WP_CONTENT_DIR . '/debug.log');
$GLOBALS['stub']['content'] = 'https://cdn.example/wp-content';
$obs = ServerChecks::observe('logs_public', ctx());
unset($GLOBALS['stub']['content']);
$by_target = array_column($obs['targets'], null, 'target');
assert_true(isset($by_target['/wp-content/debug.log']) && $by_target['/wp-content/debug.log']['url'] === null, 'off-host absent debug.log kept without URL');
assert_true($by_target['/wp-content/debug.log']['reason'] !== '', 'off-host target carries the location reason');
$r = Catalogue::grade('logs_public', $obs, ctx());
assert_same(Status::UNKNOWN, statuses($r)['logs_public:/wp-content/debug.log'], 'off-host absent debug.log → Nicht prüfbar');
assert_true($r['status'] !== Status::GREEN, 'off-host location cannot end Grün');

chmod(WP_CONTENT_DIR . '/updraft', 0000);
$obs = ServerChecks::observe('backups_public', ctx());
chmod(WP_CONTENT_DIR . '/updraft', 0700);
$by_target = array_column($obs['targets'], null, 'target');
assert_same('unknown', $by_target['/wp-content/updraft']['disk'] ?? null, 'unreadable backup folder → unknown');
assert_same(Status::UNKNOWN, statuses(Catalogue::grade('backups_public', $obs, ctx()))['backups_public:/wp-content/updraft'], 'unknown folder → Nicht prüfbar');

// ---------------------------------------------------------------- registration

$reg = static fn(bool $open, ?array $caps) => grade('registration', ['open' => $open, 'role' => 'x', 'caps' => $caps]);
assert_same(Status::GREEN, $reg(false, ['read'])['status'], 'registration off → Grün');
assert_same(Status::RED, $reg(true, ['read', 'level_0', 'edit_posts'])['status'], 'default role with edit_posts → Rot');
assert_same(Status::RED, $reg(true, ['read', 'bricks_execute_code'])['status'], 'default role with bricks_execute_code → Rot');
assert_same(Status::HINT, $reg(true, ['read', 'level_0'])['status'], 'read-only role → Hinweis');
$r = $reg(true, ['read', 'edit_pages']);
assert_same(Status::YELLOW, $r['status'], 'custom role with edit_pages → Gelb');
assert_true(strpos($r['findings'][0]['label'], 'edit_pages') !== false, 'Gelb lists the extra capability');
assert_same(Status::UNKNOWN, $reg(true, null)['status'], 'default role missing → Nicht prüfbar');

stub_reset(['options' => ['users_can_register' => '1', 'default_role' => 'subscriber'], 'roles' => ['subscriber' => ['read' => true, 'level_0' => true, 'edit_posts' => false]]]);
assert_same(Status::HINT, check('registration')['status'], 'observe: caps granted false are not held');

// ---------------------------------------------------------------- bricks_permissions / admin_accounts

stub_reset([
    'roles' => [
        'administrator' => ['manage_options' => true, 'bricks_execute_code' => true],
        'editor' => ['edit_posts' => true, 'bricks_execute_code' => true],
    ],
    'users' => [
        new WP_User(1, 'daniel', ['administrator' => true], ['manage_options' => true, 'bricks_execute_code' => true]),
        new WP_User(2, 'eddi', ['editor' => true, 'bricks_execute_code_off' => true], ['edit_posts' => true, 'bricks_execute_code' => true]),
    ],
]);
assert_same(Status::UNKNOWN, check('bricks_permissions')['status'], '\Bricks\Helpers missing → Nicht prüfbar');

// eval only defines a fixed stub class here, after the "class missing" case ran.
eval('namespace Bricks; class Helpers { public static $on = true; public static function code_execution_enabled() { return self::$on; } }');

$r = check('bricks_permissions');
assert_same(Status::HINT, $r['status'], 'user cap _off beats role grant; admins only → Hinweis');
assert_true(!isset(statuses($r)['bricks_permissions:user-2:bricks_execute_code']), 'denied user not listed as granted');

$GLOBALS['stub']['users'][1] = new WP_User(2, 'eddi', ['editor' => true], ['edit_posts' => true, 'bricks_execute_code' => true, 'bricks_upload_svg' => true]);
$r = check('bricks_permissions');
assert_same(Status::RED, $r['status'], 'editor granted execution while enabled → Rot');
assert_same(Status::RED, statuses($r)['bricks_permissions:user-2:bricks_execute_code'] ?? null, 'Rot finding names the user and grant');
assert_same(Status::YELLOW, statuses($r)['bricks_permissions:user-2:bricks_upload_svg'] ?? null, 'SVG for an editor → Gelb');
assert_true(strpos($r['note'], '2.4.2') !== false, 'note names the verified Bricks version');

\Bricks\Helpers::$on = false;
$r = check('bricks_permissions');
assert_true($r['status'] !== Status::RED, 'execution disabled → no Rot');
\Bricks\Helpers::$on = true;

$r = check('admin_accounts');
assert_same(Status::HINT, $r['status'], 'ordinary privileged logins → Hinweis');
assert_same(['admin_accounts:user-1:manage_options', 'admin_accounts:user-1:bricks_execute_code', 'admin_accounts:user-2:bricks_execute_code'],
    array_column($r['findings'], 'id'), 'one finding per privileged grant');
foreach (['admin', 'Administrator'] as $login) {
    $GLOBALS['stub']['users'][0] = new WP_User(1, $login, ['administrator' => true], ['manage_options' => true]);
    $r = check('admin_accounts');
    assert_same(Status::YELLOW, $r['status'], "privileged login {$login} → Gelb");
}

// ---------------------------------------------------------------- search_visibility

foreach (['live' => [Status::RED, Status::GREEN], 'staging' => [Status::GREEN, Status::YELLOW], 'private' => [Status::GREEN, Status::YELLOW]] as $profile => [$blocked, $open]) {
    assert_same($blocked, grade('search_visibility', ['blog_public' => '0'], $profile)['status'], "{$profile}: discouraging search engines");
    assert_same($open, grade('search_visibility', ['blog_public' => '1'], $profile)['status'], "{$profile}: visible to search engines");
}
stub_reset(['options' => ['blog_public' => '0']]);
assert_same(Status::RED, check('search_visibility')['status'], 'observe reads blog_public');

// ---------------------------------------------------------------- php_version

assert_same(Status::RED, grade('php_version', ['version' => '8.1.30', 'today' => '2026-10-10'])['status'], 'EOL → Rot');
assert_same(Status::YELLOW, grade('php_version', ['version' => '8.2.20', 'today' => '2026-10-10'])['status'], 'ends within 6 months → Gelb');
assert_same(Status::GREEN, grade('php_version', ['version' => '8.4.1', 'today' => '2026-10-10'])['status'], 'supported → Grün');
assert_same(Status::UNKNOWN, grade('php_version', ['version' => '9.0.0', 'today' => '2026-10-10'])['status'], 'unknown → Nicht prüfbar');
$r = grade('php_version', ['version' => '8.1.30', 'today' => '2026-10-10']);
assert_true(strpos($r['note'], 'php.net') !== false, 'note names the source');

// ---------------------------------------------------------------- updates

$r = grade('updates', ['core' => ['6.9.1'], 'plugins' => [['file' => 'a/a.php', 'name' => 'A', 'version' => '2.0']], 'themes' => [], 'checked' => 1791590400]);
assert_same(Status::YELLOW, $r['status'], 'offers → Gelb');
assert_same(['updates:core', 'updates:plugin:a/a.php'], array_column($r['findings'], 'id'), 'one finding per offer');
$r = grade('updates', ['core' => [], 'plugins' => [], 'themes' => [], 'checked' => 1791590400]);
assert_same(Status::HINT, $r['status'], 'no offers → Hinweis');
assert_true(strpos($r['findings'][0]['label'], '2026-10-10') !== false, 'Hinweis names the date of the last check');
stub_reset(['transients' => [
    'update_core' => (object) ['last_checked' => 1791590400, 'updates' => [(object) ['response' => 'latest', 'current' => '6.9']]],
    'update_plugins' => (object) ['last_checked' => 1791590400, 'response' => ['b/b.php' => (object) ['new_version' => '3']]],
    'update_themes' => (object) ['last_checked' => 1791590400, 'response' => []],
], 'plugins' => ['b/b.php' => ['Name' => 'B']]]);
$r = check('updates');
assert_same(['updates:plugin:b/b.php'], array_column($r['findings'], 'id'), 'observe reads the update transients');
$r = grade('updates', ['core' => [], 'plugins' => [], 'themes' => [], 'checked' => null]);
assert_same(Status::HINT, $r['status'], 'no update transient → Hinweis');
assert_true(strpos($r['findings'][0]['label'], 'never checked') !== false, 'no update transient → says it was never checked');
foreach ([[[], [], []]] as [$c, $p, $t]) {
    assert_true(grade('updates', ['core' => $c, 'plugins' => $p, 'themes' => $t, 'checked' => null])['status'] !== Status::GREEN, 'updates never Grün');
}

// ---------------------------------------------------------------- test_content

$post = static fn(int $id, string $type, string $status, string $title, string $content) => (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_content' => $content];
$comment = static fn(string $approved, string $author, string $content) => (object) ['comment_ID' => '1', 'comment_approved' => $approved, 'comment_author' => $author, 'comment_content' => $content];
$de = [
    'Hello world!' => 'Hallo Welt!',
    'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!' => 'Willkommen bei WordPress. Dies ist dein erster Beitrag. Bearbeite oder lösche ihn und beginne mit dem Schreiben!',
];
stub_reset(['translations' => $de, 'posts' => [
    1 => $post(1, 'post', 'publish', 'Hallo Welt!', "<!-- wp:paragraph -->\n<p>Willkommen bei WordPress. Dies ist dein erster Beitrag. Bearbeite oder lösche ihn und beginne mit dem Schreiben!</p>"),
    2 => $post(2, 'page', 'publish', 'Beispiel-Seite', '<p>Dies ist eine Beispiel-Seite. Sie unterscheidet sich von Beiträgen …</p>'),
], 'comments' => [1 => $comment('1', 'Ein WordPress-Kommentator', "Hallo, dies ist ein Kommentar.\nUm …")]]);
$r = check('test_content');
assert_same(['test_content:post-1', 'test_content:page-2', 'test_content:comment-1'], array_column($r['findings'], 'id'), 'default post, page and comment → Gelb each');
assert_same(Status::YELLOW, $r['status'], 'test content → Gelb');

$GLOBALS['stub']['posts'][1] = $post(1, 'post', 'publish', 'Unser Start', '<p>Ein echter Beitrag.</p>');
$GLOBALS['stub']['posts'][2] = $post(2, 'page', 'publish', 'Sample Page', '<p>Our real about text.</p>');
$GLOBALS['stub']['comments'][1] = $comment('0', 'Ein WordPress-Kommentator', 'Hallo, dies ist ein Kommentar.');
assert_same(Status::GREEN, check('test_content')['status'], 'retitled/rewritten post, edited page, unapproved comment → not flagged');
$GLOBALS['stub']['posts'][2] = $post(2, 'page', 'draft', 'Sample Page', "<p>This is an example page. It's different …</p>");
$GLOBALS['stub']['comments'][1] = $comment('1', 'A WordPress Commenter', 'Thanks, edited by the owner.');
assert_same(Status::GREEN, check('test_content')['status'], 'draft page and edited comment → not flagged');
$GLOBALS['stub']['posts'][2] = $post(2, 'page', 'publish', 'Sample Page', "<p>This is an example page. It's different …</p>");
assert_same(['test_content:page-2'], array_column(check('test_content')['findings'], 'id'), 'English sample page flagged');

// ---------------------------------------------------------------- inactive_plugins / inactive_themes

stub_reset([
    'options' => ['active_plugins' => ['a/a.php'], 'sfx_site_check_settings' => ['fallback_theme' => 'twentytwentyfour']],
    'plugins' => ['a/a.php' => ['Name' => 'A'], 'b/b.php' => ['Name' => 'B']],
    'themes' => ['bricks' => new StubTheme('Bricks'), 'sfx-bricks-child' => new StubTheme('SFX'), 'twentytwentyfive' => new StubTheme('TT5'), 'twentytwentyfour' => new StubTheme('TT4')],
]);
$r = check('inactive_plugins');
assert_same(['inactive_plugins:b/b.php'], array_column($r['findings'], 'id'), 'inactive plugin listed');
assert_same(Status::YELLOW, $r['status'], 'inactive plugins → Gelb');

$c = ctx('live', 'twentytwentyfive');
$obs = ServerChecks::observe('inactive_themes', $c);
$r = Catalogue::grade('inactive_themes', $obs, $c);
assert_same(['inactive_themes:twentytwentyfour'], array_column($r['findings'], 'id'), 'Bricks, active and the run\'s fallback theme are excluded; the live setting is not read');

// ---------------------------------------------------------------- https (server half)

foreach (['live', 'staging', 'private'] as $profile) {
    $s = statuses(grade('https', ['home' => 'http://ex.test', 'siteurl' => 'https://ex.test'], $profile));
    assert_same(Status::RED, $s['https:home'], "{$profile}: home http → Rot");
    assert_same(Status::GREEN, $s['https:siteurl'], "{$profile}: siteurl https → Grün");
    $s = statuses(grade('https', ['home' => 'https://ex.test', 'siteurl' => 'http://ex.test'], $profile));
    assert_same(Status::RED, $s['https:siteurl'], "{$profile}: siteurl http → Rot");
    assert_same(Status::UNKNOWN, grade('https', ['home' => 'https://ex.test', 'siteurl' => 'https://ex.test'], $profile)['status'], "{$profile}: both https, outside half not measured → Nicht prüfbar");
    assert_same(Status::GREEN, grade('https', ['home' => 'https://ex.test', 'siteurl' => 'https://ex.test', 'tls' => 'ok', 'http_redirect' => 'ok'], $profile)['status'], "{$profile}: both https, TLS and redirect ok → Grün");
}

// ---------------------------------------------------------------- allow_url_include / file_editor / auto_updates

assert_same(Status::RED, grade('allow_url_include', ['value' => '1'])['status'], 'allow_url_include on → Rot');
assert_same(Status::RED, grade('allow_url_include', ['value' => 'On'])['status'], 'allow_url_include On → Rot');
assert_same(Status::GREEN, grade('allow_url_include', ['value' => '0'])['status'], 'allow_url_include off → Grün');
assert_same(Status::GREEN, grade('allow_url_include', ['value' => ''])['status'], 'allow_url_include empty → Grün');

assert_same(Status::YELLOW, grade('file_editor', ['disallow_file_edit' => false, 'disallow_file_mods' => false])['status'], 'no constant → Gelb');
assert_same(Status::GREEN, grade('file_editor', ['disallow_file_edit' => true, 'disallow_file_mods' => false])['status'], 'DISALLOW_FILE_EDIT → Grün');
assert_same(Status::GREEN, grade('file_editor', ['disallow_file_edit' => false, 'disallow_file_mods' => true])['status'], 'DISALLOW_FILE_MODS only → Grün');

$r = grade('auto_updates', ['minor_enabled' => false, 'reason' => 'constant']);
assert_same(Status::YELLOW, $r['status'], 'minor updates off → Gelb');
assert_true(strpos($r['findings'][0]['label'] . $r['note'], 'external update process') !== false, 'tip names an external update process');
assert_same(Status::GREEN, grade('auto_updates', ['minor_enabled' => true, 'reason' => ''])['status'], 'minor updates on → Grün');
stub_reset(['filters' => ['allow_minor_auto_core_updates' => false]]);
$r = check('auto_updates');
assert_same(Status::YELLOW, $r['status'], 'observe: filter disabling minor updates → Gelb');

// ---------------------------------------------------------------- the rest of the Hinweis/Gelb rows

assert_same(Status::HINT, grade('admin_email', ['email' => 'a@ex.test', 'pending' => ''])['status'], 'admin_email → Hinweis');
assert_same(Status::YELLOW, grade('permalinks', ['structure' => ''])['status'], 'plain permalinks → Gelb');
assert_same(Status::GREEN, grade('permalinks', ['structure' => '/%postname%/'])['status'], 'pretty permalinks → Grün');
assert_same(Status::HINT, grade('table_prefix', ['prefix' => 'wp_'])['status'], 'table prefix wp_ → Hinweis');
assert_same(Status::HINT, grade('table_prefix', ['prefix' => 'x7_'])['status'], 'custom prefix → Hinweis');
assert_same(Status::HINT, grade('app_passwords', ['available' => true, 'passwords' => [['user' => 3, 'login' => 'api', 'name' => 'CI', 'uuid' => 'u1', 'last_used' => null]]])['status'], 'app passwords → Hinweis');
assert_same('app_passwords:user-3:u1', grade('app_passwords', ['available' => true, 'passwords' => [['user' => 3, 'login' => 'api', 'name' => 'CI', 'uuid' => 'u1', 'last_used' => null]]])['findings'][0]['id'], 'app password finding ID');

// Task 10: the pending-change line appears only when the pending address differs.
$label = grade('admin_email', ['email' => 'a@ex.test', 'pending' => 'a@ex.test'])['findings'][0]['label'];
assert_true(strpos($label, 'waiting for confirmation') === false, 'admin_email: a pending change to the same address is not mentioned');
$label = grade('admin_email', ['email' => 'a@ex.test', 'pending' => 'b@ex.test'])['findings'][0]['label'];
assert_true(strpos($label, 'A change to b@ex.test is waiting for confirmation.') !== false, 'admin_email: a pending change to another address is named');

// ---------------------------------------------------------------- dates in findings (Task 11): site zone and format

$GLOBALS['stub']['options'] = array_replace($GLOBALS['stub']['options'], ['timezone_string' => 'Europe/Berlin', 'date_format' => 'd.m.Y', 'time_format' => 'H:i']);
$near_midnight = gmmktime(22, 30, 0, 10, 10, 2026); // 10.10.2026 22:30 UTC = 11.10.2026 00:30 in Berlin
$label = grade('updates', ['core' => [], 'plugins' => [], 'themes' => [], 'checked' => $near_midnight])['findings'][0]['label'];
assert_true(strpos($label, '11.10.2026 00:30') !== false && strpos($label, 'UTC') === false, 'updates "Stand": site zone and format (' . $label . ')');
$label = grade('app_passwords', ['available' => true, 'passwords' => [['user' => 3, 'login' => 'api', 'name' => 'CI', 'uuid' => 'u1', 'last_used' => $near_midnight]]])['findings'][0]['label'];
assert_true(strpos($label, '11.10.2026') !== false, 'app password last use: site zone and date format (' . $label . ')');
$GLOBALS['stub']['options']['timezone_string'] = 'America/Los_Angeles';
$r = grade('php_version', ['version' => '8.2.20', 'today' => '2026-10-10']);
assert_same(Status::YELLOW, $r['status'], 'php_version still compares the ISO dates');
assert_true(strpos($r['findings'][0]['label'], '31.12.2026') !== false, 'support end in the site date format, no time-zone shift (' . $r['findings'][0]['label'] . ')');
assert_true(strpos($r['note'], '10.10.2026') !== false && strpos($r['note'], '2026-10-10') === false, 'check date in the site date format (' . $r['note'] . ')');
unset($GLOBALS['stub']['options']['timezone_string'], $GLOBALS['stub']['options']['date_format'], $GLOBALS['stub']['options']['time_format']);

// Task 10: the dead "Probe class missing" guard is gone.
assert_true(strpos((string) file_get_contents(__DIR__ . '/../inc/SiteCheck/Checks/FileChecks.php'), 'class_exists(Probe::class)') === false, 'FileChecks: no class_exists(Probe::class) guard');

// ================================================================ Gate B pass 3

$as_root3 = function_exists('posix_geteuid') && posix_geteuid() === 0;
// File discovery: an entry whose stat fails is named Nicht prüfbar, never dropped.
if (!$as_root3) {
    put(WP_CONTENT_DIR . '/updraft/backup_db.sql.gz', 'x');
    chmod(WP_CONTENT_DIR . '/updraft', 0400);
    $obs = ServerChecks::observe('backups_public', ctx());
    chmod(WP_CONTENT_DIR . '/updraft', 0700);
    $by_target = array_column($obs['targets'], null, 'target');
    assert_same('unknown', $by_target['/wp-content/updraft/backup_db.sql.gz']['disk'] ?? null, 'backup in a listable, unsearchable folder → kept, disk unknown');
    $r = Catalogue::grade('backups_public', $obs, ctx());
    assert_same(Status::UNKNOWN, statuses($r)['backups_public:/wp-content/updraft/backup_db.sql.gz'] ?? null, 'and graded Nicht prüfbar, not dropped');
}
symlink(ABSPATH . 'nowhere', ABSPATH . 'wp-config.php.bak');
$r = check('config_copies');
unlink(ABSPATH . 'wp-config.php.bak');
assert_same(Status::UNKNOWN, statuses($r)['config_copies:/wp-config.php.bak'] ?? null, 'a config copy whose stat fails (dangling link) → Nicht prüfbar, named');
// Uploads: a linked folder is named coverage, never silently skipped.
// The link target lives in the teardown-owned temp tree, outside uploads.
$linked_target = $tmp . '/linked-target';
mkdir($linked_target, 0700);
file_put_contents($linked_target . '/webshell.php', '<?php system($_GET["c"]);');
symlink($linked_target, WP_CONTENT_DIR . '/uploads/linked');
$r = check('php_files_uploads');
unlink(WP_CONTENT_DIR . '/uploads/linked');
unlink($linked_target . '/webshell.php');
rmdir($linked_target);
assert_same(Status::UNKNOWN, statuses($r)['php_files_uploads:/wp-content/uploads/linked'] ?? null, 'a linked uploads folder → Nicht prüfbar, named');
assert_true($r['status'] !== Status::GREEN, 'a linked folder never lets the scan end Grün');
// Enumeration stops at the limit: a flat folder with many entries is not read whole.
for ($i = 0; $i < 30; $i++) {
    put(WP_CONTENT_DIR . '/uploads/flat/f' . $i . '.txt', 'x');
}
$limited = FileChecks::scan_uploads(WP_CONTENT_DIR . '/uploads', \SFX\SiteCheck\Locations::current(), 5, 5.0);
assert_same(5, $limited['limit']['checked'] ?? null, 'the limit stops the scan after 5 entries');
remove_tree(WP_CONTENT_DIR . '/uploads/flat');

// auto_updates: a version-control checkout disables WordPress' automatic updates.
$GLOBALS['vcs_checkout'] = true;
$r = check('auto_updates');
$GLOBALS['vcs_checkout'] = false;
assert_same(Status::YELLOW, $r['status'], 'VCS checkout → Gelb');
assert_true(strpos($r['findings'][0]['label'], 'version control') !== false, 'named: version control');

// ================================================================ Gate B pass 4

// A dangling link is no proof of absence.
@unlink(WP_CONTENT_DIR . '/debug.log');
symlink(ABSPATH . 'nowhere.log', WP_CONTENT_DIR . '/debug.log');
assert_same('unknown', FileChecks::disk_state(WP_CONTENT_DIR . '/debug.log'), 'dangling debug.log link → disk unknown');
$by_target = array_column(ServerChecks::observe('logs_public', ctx())['targets'], null, 'target');
assert_same('unknown', $by_target['/wp-content/debug.log']['disk'] ?? null, 'logs_public: the dangling link is disk unknown (graded Nicht prüfbar, never Grün)');
unlink(WP_CONTENT_DIR . '/debug.log');
// A backup folder that is a dangling link stays a named unknown target.
remove_tree(WP_CONTENT_DIR . '/updraft');
symlink(ABSPATH . 'nowhere-updraft', WP_CONTENT_DIR . '/updraft');
$by_target = array_column(ServerChecks::observe('backups_public', ctx())['targets'], null, 'target');
unlink(WP_CONTENT_DIR . '/updraft');
assert_same('unknown', $by_target['/wp-content/updraft']['disk'] ?? null, 'a dangling backup folder link → named unknown coverage');
$r = Catalogue::grade('backups_public', ['targets' => [$by_target['/wp-content/updraft']]], ctx());
assert_same(Status::UNKNOWN, $r['status'], 'and graded Nicht prüfbar');

// ================================================================ Gate B pass 5

if (!$as_root3) {
    // Wildcard backup folders: an empty glob below an unsearchable parent is no proof of absence.
    put(WP_CONTENT_DIR . '/uploads/backwpup-test-backups/private.sql', 'x');
    chmod(WP_CONTENT_DIR . '/uploads', 0100);
    $obs = ServerChecks::observe('backups_public', ctx());
    chmod(WP_CONTENT_DIR . '/uploads', 0700);
    $r = Catalogue::grade('backups_public', $obs, ctx());
    assert_true($r['status'] !== Status::GREEN, 'unreadable uploads → wildcard backup discovery never Grün');
    assert_same(Status::UNKNOWN, statuses($r)['backups_public:/wp-content/uploads/'] ?? null, 'the unsearchable parent is named Nicht prüfbar');
    remove_tree(WP_CONTENT_DIR . '/uploads/backwpup-test-backups');
    // An empty unsearchable uploads folder is named too.
    mkdir(WP_CONTENT_DIR . '/uploads/emptylocked', 0700);
    chmod(WP_CONTENT_DIR . '/uploads/emptylocked', 0400);
    $r = check('php_files_uploads');
    chmod(WP_CONTENT_DIR . '/uploads/emptylocked', 0700);
    rmdir(WP_CONTENT_DIR . '/uploads/emptylocked');
    assert_same(Status::UNKNOWN, statuses($r)['php_files_uploads:/wp-content/uploads/emptylocked'] ?? null, 'an empty unsearchable folder → Nicht prüfbar, named');
}

// Registration: Bricks grants count by key presence, as Bricks resolves them.
foreach (['bricks_execute_code', 'bricks_upload_svg'] as $grant) {
    stub_reset(['options' => ['users_can_register' => '1', 'default_role' => 'subscriber'], 'roles' => ['subscriber' => ['read' => true, 'level_0' => true, $grant => false]]]);
    assert_same(Status::RED, check('registration')['status'], "a false-valued {$grant} on the default role still grants it → Rot");
}
stub_reset();

// ================================================================ Gate B pass 6

// A non-regular file is never opened (a FIFO would block the request).
if (function_exists('posix_mkfifo')) {
    posix_mkfifo(ABSPATH . 'phpinfo.php', 0600);
    $r = check('phpinfo');
    unlink(ABSPATH . 'phpinfo.php');
    assert_same(Status::UNKNOWN, statuses($r)['phpinfo:/phpinfo.php'] ?? null, 'a FIFO named phpinfo.php → Nicht prüfbar, not opened');
} else {
    echo "skip: posix_mkfifo missing, FIFO case not staged\n";
}

// ================================================================ Gate B pass 7

if (!$as_root3) {
    // An empty, unsearchable backup-plugin folder is named, not dropped.
    remove_tree(WP_CONTENT_DIR . '/updraft');
    mkdir(WP_CONTENT_DIR . '/updraft', 0700);
    chmod(WP_CONTENT_DIR . '/updraft', 0400);
    $by_target = array_column(ServerChecks::observe('backups_public', ctx())['targets'], null, 'target');
    chmod(WP_CONTENT_DIR . '/updraft', 0700);
    rmdir(WP_CONTENT_DIR . '/updraft');
    assert_same('unknown', $by_target['/wp-content/updraft']['disk'] ?? ($by_target['/wp-content/updraft/']['disk'] ?? null), 'empty unsearchable backup folder → named unknown');
}

echo "site-check server checks: ok\n";
