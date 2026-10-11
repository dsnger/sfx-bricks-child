<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\OutsideChecks;

/**
 * AJAX endpoints of the outside checks (spec "Manual run" step 3):
 *
 * - `sfx_site_check_targets` (run, check) → {targets:[{url}], comparison, skipped}
 * - `sfx_site_check_observe` (run, check, observations: JSON list) → {graded, facts, token}
 *
 * Both check Access::allowed() and the nonce Access::NONCE before reading
 * any other input, take only a fixed check ID and a run ID, and work only
 * for the issued run. They write nothing.
 */
final class OutsideEndpoints
{
    /** At most this many observations are read per request (2 per target + the comparison). */
    private const MAX_OBSERVATIONS = 2 * OutsideChecks::MAX_TARGETS + 1;

    public static function register(): void
    {
        add_action('wp_ajax_sfx_site_check_targets', [self::class, 'handle_targets']);
        add_action('wp_ajax_sfx_site_check_observe', [self::class, 'handle_observe']);
    }

    public static function handle_targets(): void
    {
        [$code, $body] = self::targets_request();
        wp_send_json($body, $code);
    }

    public static function handle_observe(): void
    {
        [$code, $body] = self::observe_request();
        wp_send_json($body, $code);
    }

    /** @return array{0:int, 1:array} HTTP status, JSON body */
    public static function targets_request(): array
    {
        [$error, $check, $ctx] = self::begin();
        if ($error !== null) {
            return $error;
        }

        return [200, ['success' => true, 'data' => OutsideChecks::targets($check, $ctx)]];
    }

    /** @return array{0:int, 1:array} HTTP status, JSON body */
    public static function observe_request(): array
    {
        [$error, $check, $ctx] = self::begin();
        if ($error !== null) {
            return $error;
        }

        $raw = isset($_POST['observations']) && is_string($_POST['observations']) ? wp_unslash($_POST['observations']) : '[]';
        $observations = json_decode($raw, true);
        if (!is_array($observations)) {
            return self::error(400, __('The observations could not be read.', 'sfxtheme'));
        }
        $observations = array_slice(array_values($observations), 0, self::MAX_OBSERVATIONS);

        $facts = OutsideChecks::observe($check, $ctx, $observations);

        return [200, ['success' => true, 'data' => [
            'graded' => Runs::compact($check, Catalogue::grade($check, $facts, $ctx)),
            'facts' => $facts,
            // What the save carries back (Runs::save() trusts nothing else).
            'token' => Runs::seal($ctx->run(), $check, $facts),
        ]]];
    }

    /** The issued run's snapshot, or null when $run is not the issued run (Runs owns the shape). */
    public static function context(string $run): ?RunContext
    {
        return Runs::context($run);
    }

    /**
     * Access and nonce first, then run and check.
     *
     * @return array{0:?array, 1:string, 2:?RunContext}
     */
    private static function begin(): array
    {
        if (!Access::allowed() || check_ajax_referer(Access::NONCE, '_ajax_nonce', false) === false) {
            return [self::error(403, __('You are not allowed to do this.', 'sfxtheme')), '', null];
        }
        $check = isset($_POST['check']) && is_string($_POST['check']) ? $_POST['check'] : '';
        if (!OutsideChecks::handles($check)) {
            return [self::error(400, __('Unknown check.', 'sfxtheme')), '', null];
        }
        $run = isset($_POST['run']) && is_string($_POST['run']) ? wp_unslash($_POST['run']) : '';
        $ctx = self::context($run);
        if ($ctx === null) {
            return [self::error(409, __('This run is no longer current. Start the check again.', 'sfxtheme')), '', null];
        }

        return [null, $check, $ctx];
    }

    private static function error(int $code, string $message): array
    {
        return [$code, ['success' => false, 'data' => ['message' => $message]]];
    }
}
