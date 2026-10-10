<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\FileChecks;

/**
 * The `.htaccess` copy template (spec "`.htaccess` template"). Text only — the
 * module never writes a server file. Each block carries `# BEGIN sfx-…` /
 * `# END sfx-…` so it can be added and removed on its own, and holds only
 * access-control and `Options` directives: no rewrite, redirect, header or
 * handler change, so WordPress' block, caching plugins and the Redirects
 * module are not touched.
 */
final class Template
{
    /** PHP-like extensions anywhere in a name (spec rule 6), case-insensitive. */
    private const PHP_LIKE = '(?i)\.(php|pht|phar)';

    /** wp-config copies (FileChecks' suffixes), dumps, logs, error_log, .env, readme.html, license.txt. */
    private const SENSITIVE = '(?i)^(\.?wp-config.*(\.(' . FileChecks::COPY_SUFFIXES . ')|~)|.*\.sql(\.gz)?|.*\.log|error_log|\.env|readme\.html|license\.txt)$';

    /** WordPress decides this from SERVER_SOFTWARE (wp-includes/vars.php). */
    public static function looks_like_nginx(): bool
    {
        return !empty($GLOBALS['is_nginx']);
    }

    /**
     * `file` is where the text goes: a path, '' for the server configuration
     * (nginx), or null when that location is unknown on this site.
     *
     * @param array $locations Locations::current()
     * @return list<array{id:string, title:string, file:?string, note:string, text:string}>
     */
    public static function blocks(array $locations, bool $nginx): array
    {
        $root = $locations['web']['dir'] ?? null;
        $uploads = $locations['uploads']['dir'] ?? null;
        $in = static fn(?string $dir, string $name): ?string => $dir === null ? null : $dir . $name;

        $blocks = [
            self::block('sensitive', __('Root folder: sensitive files', 'sfxtheme'), $in($root, '.htaccess'), 'sensitive-files', [
                '<FilesMatch "' . self::SENSITIVE . '">',
                self::deny("\t"),
                '</FilesMatch>',
            ], __('This rule also applies in every subfolder: a plugin\'s readme.html or any .log file below this folder is blocked too.', 'sfxtheme')),
            self::block('git', __('.git folder: its own .htaccess inside .git', 'sfxtheme'), $in($root, '.git/.htaccess'), 'git', [
                self::deny(''),
            ], __('Best remove .git from the web folder. Only if it must stay, use this as its own file inside .git.', 'sfxtheme')),
            self::block('indexes', __('Root folder: directory listing', 'sfxtheme'), $in($root, '.htaccess'), 'indexes', [
                'Options -Indexes',
            ]),
            self::block('uploads', __('Uploads folder: no PHP', 'sfxtheme'), $in($uploads, '.htaccess'), 'uploads-php', [
                '<FilesMatch "' . self::PHP_LIKE . '">',
                self::deny("\t"),
                '</FilesMatch>',
            ]),
            self::block('xmlrpc', __('Root folder: XML-RPC (optional)', 'sfxtheme'), $in($root, '.htaccess'), 'xmlrpc', [
                '# ' . __('Apps and Jetpack need XML-RPC. Remove the leading # only if nothing on this site uses it.', 'sfxtheme'),
                '# <Files xmlrpc.php>',
                preg_replace('/^/m', '# ', self::deny("\t")),
                '# </Files>',
            ]),
        ];

        if ($nginx) {
            // nginx matches the decoded URI: decode once, escape regex characters, quote (spaces).
            $path = $locations['uploads'] === null ? '' : rawurldecode((string) parse_url((string) $locations['uploads']['url'], PHP_URL_PATH));
            // Compared with the site's home host only (the established web root's URL), never the WordPress host.
            $site = $locations['web']['url'] ?? '';
            $same_host = $locations['uploads'] !== null && $site !== ''
                && strtolower((string) parse_url((string) $locations['uploads']['url'], PHP_URL_HOST)) === strtolower((string) parse_url((string) $site, PHP_URL_HOST));
            // Never a pattern rooted at "/" (it would deny PHP site-wide), never for another host.
            if ($locations['web'] === null) {
                $uploads_rule = ['# ' . __('The web folder of this site is not established (site and WordPress address differ), so no uploads rule is given; ask the host.', 'sfxtheme')];
            } elseif ($locations['uploads'] !== null && !$same_host) {
                $uploads_rule = ['# ' . __('The uploads are served from another host (CDN or offload): set the uploads rule there.', 'sfxtheme')];
            } elseif ($locations['uploads'] === null) {
                $uploads_rule = ['# ' . __('The uploads folder could not be determined on this site, so no uploads rule is given; ask the host.', 'sfxtheme')];
            } elseif (trim($path, '/') === '') {
                $uploads_rule = ['# ' . __('The uploads are served from the site root, so an uploads rule would deny PHP on the whole site; none is given — ask the host.', 'sfxtheme')];
            } else {
                $uploads_rule = [
                    'location ~* "^' . str_replace('"', '\\"', preg_quote($path)) . '.*\\.(php|pht|phar)" {',
                    "\tdeny all;",
                    '}',
                ];
            }
            $blocks[] = self::block('nginx', __('nginx (server configuration, for the host)', 'sfxtheme'), '', 'nginx', array_merge([
                '# ' . __('Place these blocks before the location block that hands PHP files to PHP (usually location ~ \.php$): nginx uses the first matching regular-expression location.', 'sfxtheme'),
                'location ~* (^|/)(\.?wp-config[^/]*(\.(' . FileChecks::COPY_SUFFIXES . ')|~)|[^/]*\.sql(\.gz)?|[^/]*\.log|error_log|\.env|readme\.html|license\.txt)$ {',
                "\tdeny all;",
                '}',
                'location ~ /\.git(/|$) {',
                "\tdeny all;",
                '}',
            ], $uploads_rule, [
                'autoindex off;',
            ]));
        }

        return $blocks;
    }

    /** Apache 2.4 inside mod_authz_core, 2.2 otherwise. */
    private static function deny(string $indent): string
    {
        return implode("\n", [
            $indent . '<IfModule mod_authz_core.c>',
            $indent . "\tRequire all denied",
            $indent . '</IfModule>',
            $indent . '<IfModule !mod_authz_core.c>',
            $indent . "\tOrder allow,deny",
            $indent . "\tDeny from all",
            $indent . '</IfModule>',
        ]);
    }

    /** @param list<string> $lines */
    private static function block(string $id, string $title, ?string $file, string $marker, array $lines, string $note = ''): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'file' => $file,
            'note' => $note,
            'text' => '# BEGIN sfx-' . $marker . "\n" . implode("\n", $lines) . "\n# END sfx-" . $marker . "\n",
        ];
    }
}
