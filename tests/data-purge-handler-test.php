<?php

declare(strict_types=1);

/**
 * The destructive handler's gate chain.
 *
 * Nothing here checks that the purge works — that is data-purge-test.php. This
 * file checks the opposite: that a request which fails ANY gate never reaches
 * DataPurge::run(). Each case removes exactly one gate's precondition and
 * asserts nothing was deleted. The last cases follow the Redirects table
 * count and lock flag from the handler's redirect to the screen's notice.
 */

require __DIR__ . '/support/data-purge-handler-stubs.php';
require dirname(__DIR__) . '/inc/DataPurge.php';
require dirname(__DIR__) . '/inc/GeneralThemeOptions/AdminPage.php';

use SFX\GeneralThemeOptions\AdminPage;

/**
 * Run the handler and report where it stopped.
 *
 * @return array{stopped:string, deleted:int}
 */
function run_handler(): array
{
    global $test_deleted_options;

    $test_deleted_options = 0;

    try {
        AdminPage::handle_purge();
    } catch (Stopped $e) {
        return ['stopped' => $e->getMessage(), 'deleted' => $test_deleted_options];
    }

    return ['stopped' => 'nothing', 'deleted' => $test_deleted_options];
}

// ------------------------------------------- the happy path, as a baseline
//
// Without this the cases below prove nothing: a handler that always dies would
// pass every one of them.

test_gates_reset();
$result = run_handler();

assert_same('redirect', $result['stopped'], 'Case 1a: a fully valid request reaches the redirect');
assert_true($result['deleted'] > 0, 'Case 1b: and it actually deleted something');

// ------------------------------------------------------- one gate at a time

test_gates_reset();
$GLOBALS['test_nonce_valid'] = false;
$result = run_handler();

assert_same('nonce', $result['stopped'], 'Case 2a: an invalid nonce stops the handler');
assert_same(0, $result['deleted'], 'Case 2b: and nothing was deleted');

test_gates_reset();
$GLOBALS['test_theme_access'] = false;
$result = run_handler();

assert_same('theme-access', $result['stopped'], 'Case 3a: failing the theme access gate stops the handler');
assert_same(0, $result['deleted'], 'Case 3b: and nothing was deleted');

test_gates_reset();
$GLOBALS['test_can_manage_options'] = false;
$result = run_handler();

assert_same('capability', $result['stopped'], 'Case 4a: a user without manage_options is stopped');
assert_same(0, $result['deleted'], 'Case 4b: and nothing was deleted');

test_gates_reset();
$_POST['sfx_purge_confirmation'] = 'sfx-bricks';
$result = run_handler();

assert_same('phrase', $result['stopped'], 'Case 5a: a wrong confirmation phrase stops the handler');
assert_same(0, $result['deleted'], 'Case 5b: and nothing was deleted');

test_gates_reset();
unset($_POST['sfx_purge_confirmation']);
$result = run_handler();

assert_same('phrase', $result['stopped'], 'Case 6a: an absent confirmation field stops the handler');
assert_same(0, $result['deleted'], 'Case 6b: and nothing was deleted');

// An array where a string is expected: the handler type-checks before trusting
// $_POST, so this must be rejected rather than crash.
test_gates_reset();
$_POST['sfx_purge_confirmation'] = ['sfx-bricks-child'];
$result = run_handler();

assert_same('phrase', $result['stopped'], 'Case 7a: an array in the confirmation field is rejected, not unwrapped');
assert_same(0, $result['deleted'], 'Case 7b: and nothing was deleted');

// ------------------- the Media Credits opt-in travels through the handler

test_gates_reset();
$result = run_handler();

assert_same(0, $GLOBALS['test_deleted_meta'], 'Case 8a: without the checkbox the attachment meta is not touched');

test_gates_reset();
$_POST['sfx_purge_media_credits'] = '1';
$result = run_handler();

assert_same('redirect', $result['stopped'], 'Case 8b: the opt-in request still completes');
assert_true($GLOBALS['test_deleted_meta'] > 0, 'Case 8c: and with the checkbox the meta is deleted');

// ----------- the Redirects table count and the lock reach the screen
//
// The counts travel from run() through the redirect's query args to the
// notice. Asserted at both ends: a count the handler drops, or one the screen
// never reads, would each report less than was deleted.

/**
 * Render the Danger Zone with the given query args and return its notice.
 *
 * @return array{message:string, type:string}
 */
function render_notice(array $get): array
{
    global $test_notices;

    $_GET         = $get;
    $test_notices = [];

    $render = new ReflectionMethod(AdminPage::class, 'render_danger_zone');
    if (PHP_VERSION_ID < 80100) {
        $render->setAccessible(true); // PHP 8.0 (CI's floor); deprecated from 8.5
    }
    ob_start();
    $render->invoke(null);
    ob_end_clean();

    return $test_notices[0] ?? ['message' => '', 'type' => ''];
}

test_gates_reset();
run_handler();

assert_same(2, $GLOBALS['test_redirect_args']['sfx-tables'] ?? null, 'Case 9a: both dropped tables are counted in the redirect');
assert_same(0, $GLOBALS['test_redirect_args']['sfx-tables-locked'] ?? null, 'Case 9b: a free lock is not reported as held');

$notice = render_notice(array_map('strval', $GLOBALS['test_redirect_args']));

assert_true(strpos($notice['message'], 'Redirect tables deleted: 2.') !== false, 'Case 9c: the notice reports the table count');
assert_true(strpos($notice['message'], 'another redirect change was in progress') === false, 'Case 9d: and no lock message');
assert_same('success', $notice['type'], 'Case 9e: a full purge is a success');

test_gates_reset();
$GLOBALS['test_lock_free'] = false;
run_handler();

assert_same(0, $GLOBALS['test_redirect_args']['sfx-tables'] ?? null, 'Case 10a: without the lock no table is counted');
assert_same(1, $GLOBALS['test_redirect_args']['sfx-tables-locked'] ?? null, 'Case 10b: the held lock travels in the redirect');

$notice = render_notice(array_map('strval', $GLOBALS['test_redirect_args']));

assert_true(
    strpos($notice['message'], 'Redirect tables not deleted: another redirect change was in progress.') !== false,
    'Case 10c: the notice says why the tables are still there'
);
assert_same('warning', $notice['type'], 'Case 10d: a purge that left the tables is a partial result, styled as one');

// The Danger Zone says the rules go before the phrase is typed, not after.
$_GET = [];
ob_start();
$render = new ReflectionMethod(AdminPage::class, 'render_danger_zone');
if (PHP_VERSION_ID < 80100) {
    $render->setAccessible(true); // PHP 8.0 (CI's floor); deprecated from 8.5
}
$render->invoke(null);
$screen = (string) ob_get_clean();

assert_true(strpos($screen, 'every redirect rule and the 404 log') !== false, 'Case 11: the warning names the redirect rules and the 404 log');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all data-purge handler tests\n";
exit(0);
