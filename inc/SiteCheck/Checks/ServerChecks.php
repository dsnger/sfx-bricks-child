<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Finding;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * Entry point for every server-side check (S rows and the server half of S+B
 * rows). observe() never writes and never fetches; it returns facts only —
 * names, sizes, matched signature names, setting values, never file contents
 * (spec rule 7). grade() reads those facts and the run snapshot, nothing live.
 */
final class ServerChecks
{
    /** Check ID → family class. */
    private const FAMILIES = [
        'logs_public'        => FileChecks::class,
        'config_copies'      => FileChecks::class,
        'backups_public'     => FileChecks::class,
        'vcs_env'            => FileChecks::class,
        'phpinfo'            => FileChecks::class,
        'php_files_uploads'  => FileChecks::class,
        'public_files'       => FileChecks::class,
        'debug_display'      => ConfigChecks::class,
        'allow_url_include'  => ConfigChecks::class,
        'https'              => ConfigChecks::class,
        'php_version'        => ConfigChecks::class,
        'file_editor'        => ConfigChecks::class,
        'auto_updates'       => ConfigChecks::class,
        'search_visibility'  => ConfigChecks::class,
        'admin_email'        => ConfigChecks::class,
        'permalinks'         => ConfigChecks::class,
        'table_prefix'       => ConfigChecks::class,
        'registration'       => AccountChecks::class,
        'admin_accounts'     => AccountChecks::class,
        'bricks_permissions' => AccountChecks::class,
        'app_passwords'      => AccountChecks::class,
        'test_content'       => CleanupChecks::class,
        'inactive_plugins'   => CleanupChecks::class,
        'inactive_themes'    => CleanupChecks::class,
        'updates'            => CleanupChecks::class,
    ];

    public static function handles(string $id): bool
    {
        return isset(self::FAMILIES[$id]);
    }

    public static function observe(string $id, RunContext $ctx): array
    {
        if (!self::handles($id)) {
            throw new \InvalidArgumentException('Not a server check: ' . $id);
        }
        return (self::FAMILIES[$id])::observe($id, $ctx);
    }

    /**
     * @return array{status:string, findings:list<array{id:string,status:string,label:string}>, perspective:string, note:string}
     */
    public static function grade(string $id, array $observation, RunContext $ctx): array
    {
        if (!self::handles($id)) {
            throw new \InvalidArgumentException('Not a server check: ' . $id);
        }
        return (self::FAMILIES[$id])::grade($id, $observation, $ctx);
    }

    /** @return array{id:string,status:string,label:string} */
    public static function finding(string $check, string $target, string $status, string $label): array
    {
        return ['id' => Finding::id($check, $target), 'status' => $status, 'label' => $label];
    }

    /**
     * A graded result. The status is the most urgent finding's, or $empty
     * when there are no findings (callers pass the status their complete
     * evidence supports).
     *
     * @param list<array{id:string,status:string,label:string}> $findings
     * @param string $perspective server | browser | loopback (spec rule 8)
     */
    public static function result(array $findings, string $empty, string $note = '', string $perspective = 'server'): array
    {
        return [
            'status'      => Status::worst(array_column($findings, 'status'), $empty),
            'findings'    => $findings,
            'perspective' => $perspective,
            'note'        => $note,
        ];
    }
}
