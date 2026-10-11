<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * SiteCheck's part of the theme purge (spec "Storage" → Purge), called by
 * DataPurge::run() whether or not the module is enabled. Everything happens
 * inside the critical section, in the spec's order: unschedule the cron
 * hooks, delete the lock (a running monitor stops at its next fenced write),
 * delete the issued run (no manual save lands afterwards), tear down the
 * probes, delete the remaining options — except `probes` while it still
 * records a probe that could not be deleted.
 *
 * Names come from Options, so a harness prefix isolates options and cron
 * events alike.
 */
final class Purge
{
    /** Cron hook keys (Options::hook()). Plan 2 adds the monitor's. */
    private const CRON_KEYS = ['probe_cleanup'];

    /**
     * @return array{options:int, probes_failed:list<string>, probes_unregistered:list<string>, refused:list<string>, hooks_failed:list<string>, busy:bool}
     *         `options` counts options that existed and were deleted;
     *         `refused` names options whose fenced delete (or the probes
     *         rewrite) did not go through — the section was taken over or
     *         the database refused. A refused lock or issued-run delete stops
     *         the purge there: nothing after it is torn down;
     *         `hooks_failed` names cron hooks whose events could not be
     *         unscheduled (false or WP_Error from wp_clear_scheduled_hook());
     *         `probes_unregistered` lists files in the probe folder no test
     *         run recorded, kept;
     *         `busy` means the section could not be entered and nothing was done.
     */
    public static function run(): array
    {
        $result = Mutex::with(static function (): array {
            $out = ['options' => 0, 'probes_failed' => [], 'probes_unregistered' => [], 'refused' => [], 'hooks_failed' => [], 'busy' => false];
            foreach (self::CRON_KEYS as $key) {
                $cleared = wp_clear_scheduled_hook(Options::hook($key), [], true);
                if ($cleared === false || is_wp_error($cleared)) {
                    $out['hooks_failed'][] = Options::hook($key);
                }
            }

            foreach (['lock', 'manual'] as $key) {
                if (!self::delete($key, $out)) {
                    // The issued run may still be saveable: tear nothing else down.
                    return $out;
                }
            }
            $probes = Probe::teardown();
            $out['probes_failed'] = $probes['failed'];
            $out['probes_unregistered'] = $probes['unregistered'];
            if ($probes['store_failed']) {
                $out['refused'][] = Options::name('probes');
            }
            foreach (Options::KEYS as $key) {
                if (in_array($key, ['lock', 'manual', 'mutex'], true) || ($key === 'probes' && ($probes['failed'] !== [] || $probes['store_failed']))) {
                    continue;
                }
                self::delete($key, $out);
            }

            return $out;
        });

        if ($result !== Mutex::Busy && Mutex::release_failed()) {
            // The mutex row could not be deleted (database error): reported, never forced.
            $result['refused'][] = Options::name('mutex');
        }
        if ($result === Mutex::Busy) {
            return ['options' => 0, 'probes_failed' => [], 'probes_unregistered' => [], 'refused' => [], 'hooks_failed' => [], 'busy' => true];
        }
        return $result;
    }

    /** False when the option existed and its fenced delete did not go through (recorded in `refused`). */
    private static function delete(string $key, array &$out): bool
    {
        $name = Options::name($key);
        if (get_option($name, null) === null) {
            return true;
        }
        if (!Mutex::delete($name)) {
            $out['refused'][] = $name;
            return false;
        }
        $out['options']++;
        return true;
    }
}
