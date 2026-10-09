<?php

declare(strict_types=1);

// Media Replace overwrites the old file and deletes the new attachment: the user must be
// allowed to edit BOTH attachments, not just hold edit_posts.

namespace {
    $failures = 0;
    $posts = [];
    $editable = [];
    $calls = [];

    final class JsonExit extends \Exception
    {
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        global $failures;
        if ($expected !== $actual) {
            $failures++;
            echo "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
        }
    }
    function wp_verify_nonce($nonce, $action)
    {
        return $nonce === 'good' && $action === 'sfx_media_replace_nonce' ? 1 : false;
    }
    function current_user_can(string $cap, ...$args): bool
    {
        global $editable;
        if ($cap === 'edit_posts') {
            return true; // an Author
        }
        if ($cap === 'edit_post') {
            return in_array((int) ($args[0] ?? 0), $editable, true);
        }
        return false;
    }
    function get_post($id, $output = null)
    {
        global $posts, $calls;
        $calls[] = 'get_post:' . $id;
        $post = $posts[$id] ?? null;
        return $post === null ? null : ($output === ARRAY_A ? $post : (object) $post);
    }
    function wp_send_json_error($data = null): void
    {
        throw new JsonExit('error:' . $data);
    }
    function wp_send_json_success($data = null): void
    {
        throw new JsonExit('success:' . $data);
    }
    ini_set('error_log', '/dev/null');
    function get_post_meta(...$args)
    {
        global $calls;
        $calls[] = 'get_post_meta';
        return '';
    }
    function wp_upload_dir(): array
    {
        return ['basedir' => sys_get_temp_dir()];
    }
    define('ARRAY_A', 'ARRAY_A');

    require dirname(__DIR__) . '/inc/WPOptimizer/classes/MediaReplacement.php';

    function run_replace(array $post): string
    {
        global $calls;
        $calls = [];
        $_POST = $post;
        try {
            \SFX\WPOptimizer\classes\MediaReplacement::handle_ajax_replace_media();
        } catch (JsonExit $e) {
            return $e->getMessage();
        }
        return 'no response';
    }

    $posts[10] = ['ID' => 10, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']; // victim's
    $posts[20] = ['ID' => 20, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']; // author's own
    $posts[30] = ['ID' => 30, 'post_type' => 'post', 'post_mime_type' => ''];                 // not an attachment
    $posts[40] = ['ID' => 40, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']; // author's own
    $editable = [20, 30, 40];

    // 1. Overwriting someone else's file: refused before any file work.
    assert_same('error:Insufficient permissions', run_replace(['nonce' => 'good', 'old_attachment_id' => '10', 'new_attachment_id' => '20']), '1: old not editable');
    assert_same(false, in_array('get_post_meta', $calls, true), '1: no file lookup happened');

    // 2. Deleting someone else's attachment (passed as the "new" one): refused.
    assert_same('error:Insufficient permissions', run_replace(['nonce' => 'good', 'old_attachment_id' => '20', 'new_attachment_id' => '10']), '2: new not editable');
    assert_same(false, in_array('get_post_meta', $calls, true), '2: no file lookup happened');

    // 3. A non-attachment ID is refused even when editable.
    assert_same('error:Invalid attachment IDs', run_replace(['nonce' => 'good', 'old_attachment_id' => '30', 'new_attachment_id' => '20']), '3: old is not an attachment');

    // 4. Missing or bad nonce: refused, no PHP warning.
    assert_same('error:Invalid nonce', run_replace(['old_attachment_id' => '20', 'new_attachment_id' => '40']), '4: missing nonce');
    assert_same('error:Invalid nonce', run_replace(['nonce' => 'bad', 'old_attachment_id' => '20', 'new_attachment_id' => '40']), '4: bad nonce');

    // 5. Both own attachments: passes the checks and reaches the file work.
    run_replace(['nonce' => 'good', 'old_attachment_id' => '20', 'new_attachment_id' => '40']);
    assert_same(true, in_array('get_post_meta', $calls, true), '5: own attachments reach the replacement');

    if ($failures > 0) {
        echo "Tests failed: {$failures}\n";
        exit(1);
    }
    echo "media-replacement-permissions-test: PASS\n";
}
