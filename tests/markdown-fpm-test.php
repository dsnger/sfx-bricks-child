<?php

declare(strict_types=1);

// Request-level tests of the Markdown response through a real PHP-FPM (spec § Verification,
// Battery; plan P5): tests/support/markdown-fpm-app.php is the front script, one request per
// scenario, two pools — "normal" and "fallback" (no finish-request functions).

require __DIR__ . '/support/fastcgi-client.php';

function fail(string $message): void
{
    global $tmp;
    fwrite(STDERR, "FAIL: {$message}\n");
    foreach (['fpm.log', 'php-errors.log'] as $log) {
        if (isset($tmp) && is_file("{$tmp}/{$log}")) {
            fwrite(STDERR, "--- {$log} (tail)\n" . implode('', array_slice(file("{$tmp}/{$log}"), -15)));
        }
    }
    exit(1);
}

$checks = 0;
function assert_same($expected, $actual, string $message): void
{
    global $checks;
    if ($expected !== $actual) {
        fail("{$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true));
    }
    $checks++;
}

// --- P5: resolve PHP-FPM; none usable fails the test. ---
function fpm_usable(string $candidate, string &$why): bool
{
    if ($candidate === '') {
        $why = 'not set';
        return false;
    }
    if (strpos($candidate, '/') === false) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($found === '') {
            $why = 'not on PATH';
            return false;
        }
        $candidate = $found;
    }
    if (!is_file($candidate) || !is_executable($candidate)) {
        $why = 'not an executable file';
        return false;
    }
    $out = [];
    exec(escapeshellarg($candidate) . ' -v 2>/dev/null', $out, $code);
    $text = implode("\n", $out);
    if ($code !== 0 || strpos($text, '(fpm-fcgi)') === false) {
        $why = 'not an FPM binary';
        return false;
    }
    // The leg must run on the FPM of the CLI's own major.minor, never another version's.
    if (!preg_match('/^PHP (\d+\.\d+)\./m', $text, $m) || $m[1] !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
        $why = 'version ' . ($m[1] ?? 'unknown') . ' differs from CLI ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return false;
    }
    return true;
}
$fpm = '';
$tried = [];
foreach ([(string) getenv('PHP_FPM'), '/usr/sbin/php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'php-fpm', '/usr/local/sbin/php-fpm', '/opt/homebrew/sbin/php-fpm'] as $candidate) {
    $why = '';
    if (fpm_usable($candidate, $why)) {
        $fpm = $candidate;
        break;
    }
    $tried[] = ($candidate === '' ? '$PHP_FPM' : $candidate) . ' (' . $why . ')';
}
if ($fpm === '') {
    fail('no usable php-fpm found; tried: ' . implode('; ', $tried));
}

// --- Temp dir and the one teardown path, declared before FPM starts. ---
$tmp = rtrim(sys_get_temp_dir(), '/') . '/sfx-md-fpm-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700)) {
    unset($tmp);
    fail('cannot create the temp dir');
}
$fpm_processes = [];
register_shutdown_function(static function () use ($tmp, &$fpm_processes): void {
    foreach (['normal', 'fallback'] as $name) {
        $pid = is_file("{$tmp}/{$name}.pid") ? (int) trim((string) file_get_contents("{$tmp}/{$name}.pid")) : 0;
        if ($pid > 1) {
            // Only a master this test started (SIGTERM); it stops its own workers.
            function_exists('posix_kill') ? posix_kill($pid, 15) : exec('kill -15 ' . $pid);
        }
    }
    foreach ($fpm_processes as $process) {
        for ($i = 0; $i < 50 && proc_get_status($process)['running']; $i++) {
            usleep(100000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
    if (strpos($tmp, '/sfx-md-fpm-') !== false && is_dir($tmp)) {
        foreach (scandir($tmp) as $f) {
            if ($f !== '.' && $f !== '..') {
                unlink("{$tmp}/{$f}");
            }
        }
        rmdir($tmp);
    }
});
if (strlen("{$tmp}/fallback.sock") > 100) {
    fail("temp dir path too long for a Unix socket: {$tmp}");
}

// One master per pool: with two pools in one master, php-fpm 8.5 applied neither pool's
// disable_functions (probe in the task-6 report).
$deadline = microtime(true) + 10;
foreach (['normal' => '', 'fallback' => "php_admin_value[disable_functions] = fastcgi_finish_request,litespeed_finish_request\n"] as $name => $extra) {
    file_put_contents("{$tmp}/{$name}.conf", "[global]\npid = {$tmp}/{$name}.pid\nerror_log = {$tmp}/fpm.log\ndaemonize = no\n\n"
        . "[{$name}]\nlisten = {$tmp}/{$name}.sock\npm = static\npm.max_children = 1\n"
        . "php_admin_value[output_buffering] = 0\nphp_admin_value[display_errors] = 0\n"
        . "php_admin_value[log_errors] = 1\nphp_admin_value[error_log] = {$tmp}/php-errors.log\n"
        . "php_admin_value[default_mimetype] = text/html\nphp_admin_value[default_charset] = UTF-8\n" . $extra);
    $process = proc_open([$fpm, '-y', "{$tmp}/{$name}.conf"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$tmp}/fpm.out", 'a'], 2 => ['file', "{$tmp}/fpm.out", 'a']], $pipes);
    if (!is_resource($process)) {
        fail("cannot start {$fpm}");
    }
    $fpm_processes[] = $process;
    while (!(is_file("{$tmp}/{$name}.pid") && file_exists("{$tmp}/{$name}.sock"))) {
        if (!proc_get_status($process)['running']) {
            fail("php-fpm ({$name}) exited at start:\n" . file_get_contents("{$tmp}/fpm.out"));
        }
        if (microtime(true) > $deadline) {
            fail('php-fpm sockets not up within 10 s');
        }
        usleep(50000);
    }
}

$app = realpath(__DIR__ . '/support/markdown-fpm-app.php');
function get(string $pool, string $query, string $accept = 'text/html'): array
{
    global $tmp, $app;
    try {
        return fcgi_request("{$tmp}/{$pool}.sock", [
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $app, 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/sample/?' . $query,
            'QUERY_STRING' => $query, 'SERVER_NAME' => 'ex.test', 'HTTP_HOST' => 'ex.test', 'HTTP_ACCEPT' => $accept,
        ], 10.0);
    } catch (Throwable $e) {
        fail("request {$pool} ?{$query}: " . $e->getMessage());
    }
}

// Preconditions: the pools are what the scenarios assume.
$env = json_decode(get('normal', 'scenario=env')['body'], true);
assert_same([true, '0', 0], [$env['fastcgi_finish_request'], $env['output_buffering'], $env['level']], 'normal pool: finish function, no php.ini buffer');
$env = json_decode(get('fallback', 'scenario=env')['body'], true);
assert_same([false, false, '0', 0], [$env['fastcgi_finish_request'], $env['litespeed_finish_request'], $env['output_buffering'], $env['level']], 'fallback pool: no finish function');

const MARKDOWN = "# Hello\n\nWorld\n";

function assert_markdown(array $r, string $body, string $label): void
{
    assert_same(200, $r['status'], "{$label}: status");
    assert_same($body, $r['body'], "{$label}: body");
    $h = $r['headers'];
    assert_same('text/markdown; charset=UTF-8', $h['content-type'] ?? null, "{$label}: Content-Type");
    assert_same('Accept-Encoding, Accept', $h['vary'] ?? null, "{$label}: Vary merged");
    assert_same('private, no-store, max-age=0', $h['cache-control'] ?? null, "{$label}: Cache-Control");
    assert_same('noindex', $h['x-robots-tag'] ?? null, "{$label}: X-Robots-Tag");
    assert_same('<https://ex.test/sample/>; rel="canonical"', $h['link'] ?? null, "{$label}: Link");
    assert_same([], array_values(array_intersect(array_keys($h), ['content-length', 'etag', 'last-modified'])), "{$label}: no length or validators");
}

// convert: the Markdown with Markdown headers; Accept works like ?markdown=1.
assert_markdown(get('normal', 'scenario=convert&module=on&markdown=1'), MARKDOWN, 'convert');
assert_markdown(get('normal', 'scenario=convert&module=on', 'text/markdown'), MARKDOWN, 'convert via Accept');
// Without asking for Markdown the module leaves the HTML page alone.
assert_same(get('normal', 'scenario=plain'), get('normal', 'scenario=plain&module=on'), 'HTML request: same response with and without the module');

// late-output: shutdown callbacks, a shutdown function and an outer handler all print; the client gets only the Markdown.
$r = get('normal', 'scenario=late-output&module=on&markdown=1');
assert_markdown($r, MARKDOWN, 'late-output');
assert_same('set', $r['headers']['x-cache-flag'] ?? null, 'late-output: DONOTCACHEPAGE defined before the outer handlers run');
// Fallback pool (no finish function): the discard buffer drops what is printed before core's
// drain (wp_ob_end_flush_all() at shutdown priority 1); R5 re-opens it right after that drain,
// so priority 10 and the later shutdown function are dropped too. What still leaks — the
// accepted limit, pinned so that a change is seen: a priority-1 callback added after core's
// drain but before the commit.
$r = get('fallback', 'scenario=late-output&module=on&markdown=1');
assert_markdown($r, MARKDOWN . '<!--shutdown-1-->', 'late-output, fallback pool');
assert_same('set', $r['headers']['x-cache-flag'] ?? null, 'late-output, fallback pool: DONOTCACHEPAGE before the outer handlers');

// outer-headers: an outer handler and the finalized action set HTML/cache headers; the Markdown's win.
$r = get('normal', 'scenario=outer-headers&module=on&markdown=1');
assert_markdown($r, MARKDOWN, 'outer-headers');
assert_same(['1', '1'], [$r['headers']['x-outer-ran'] ?? null, $r['headers']['x-action-ran'] ?? null], 'outer-headers: both callbacks ran');

// outer-clean-throw: a handler that throws while the commit removes it; the Markdown still goes out, the throw is logged.
$r = get('normal', 'scenario=outer-clean-throw&module=on&markdown=1');
assert_markdown($r, MARKDOWN, 'outer-clean-throw');
assert_same(1, substr_count((string) @file_get_contents("{$tmp}/php-errors.log"), 'SFX Markdown: an output buffer handler threw while the Markdown response removed it: outer handler refuses CLEAN'), 'outer-clean-throw: logged once');

// unclosed-root: Bricks' footer never reaches the Markdown.
$r = get('normal', 'scenario=unclosed-root&module=on&markdown=1');
assert_markdown($r, MARKDOWN, 'unclosed-root');

// Pass-through: exactly the response the same request gets without the module.
foreach (['throw', 'leave-buffer-open', 'close-own-buffer', 'ob-flush', 'close-and-reopen', 'exit-in-template', 'non-removable-outer', 'status-404', 'location', 'json', 'enhance-passthrough', 'enhance-user-error', 'canonical-throw'] as $scenario) {
    $off = get('normal', "scenario={$scenario}&markdown=1");
    $on = get('normal', "scenario={$scenario}&module=on&markdown=1");
    assert_same(true, strpos($off['body'], 'SITE-HEADER') !== false, "{$scenario}: baseline renders the page");
    assert_same($off, $on, "{$scenario}: pass-through equals the module-off response");
}
$r = get('normal', 'scenario=enhance-user-error&module=on&markdown=1');
assert_same(['text/html; charset=UTF-8', false], [$r['headers']['content-type'] ?? null, strpos($r['body'], 'NOT-REACHED')], 'enhance-user-error: the HTML, unfiltered');
$on = get('normal', 'scenario=enhance-passthrough&module=on&markdown=1');
assert_same('text/html; charset=UTF-8', $on['headers']['content-type'] ?? null, 'enhance-passthrough: HTML');

// P1: the enhancement filter runs once on either path; the finalized action runs once, with what is sent.
$r = get('normal', 'scenario=enhance-convert&module=on&markdown=1');
assert_same(MARKDOWN . "\nENHANCED\n", $r['body'], 'enhance-convert: filtered copy converted');
assert_same(md5($r['body']), $r['headers']['x-finalized'] ?? null, 'enhance-convert: finalized action once, with the Markdown');
assert_same('1', $r['headers']['x-filter-calls'] ?? null, 'enhance-convert: filter called once');
$r = get('normal', 'scenario=enhance-passthrough&module=on&markdown=1');
assert_same(1, substr_count($r['body'], 'ENHANCED'), 'enhance-passthrough: filter applied once');
assert_same(md5($r['body']), $r['headers']['x-finalized'] ?? null, 'enhance-passthrough: finalized action once, with the HTML');
assert_same('1', $r['headers']['x-filter-calls'] ?? null, 'enhance-passthrough: filter called once (by core)');

echo "PASS: markdown-fpm ({$checks} checks, {$fpm})\n";
