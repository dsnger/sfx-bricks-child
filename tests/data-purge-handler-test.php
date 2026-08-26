<?php

declare(strict_types=1);

/**
 * The destructive handler's gate chain.
 *
 * Nothing here checks that the purge works — that is data-purge-test.php. This
 * file checks the opposite: that a request which fails ANY gate never reaches
 * DataPurge::run(). Each case removes exactly one gate's precondition and
 * asserts nothing was deleted.
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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all data-purge handler tests\n";
exit(0);
