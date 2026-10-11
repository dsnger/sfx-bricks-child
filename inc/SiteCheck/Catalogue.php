<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\OutsideChecks;
use SFX\SiteCheck\Checks\ServerChecks;

/**
 * The check catalogue (spec "Check catalogue"): the single list the page, the
 * dashboard box and the monitor read, and the one entry point for grading.
 */
final class Catalogue
{
    public const SECURITY = 'security';
    public const GOLIVE   = 'golive';
    public const CLEANUP  = 'cleanup';

    /** Checks whose result is measured by Loopback (Task 4's perspective table, and the probe). */
    private const LOOPBACK = ['php_in_uploads', 'https', 'xmlrpc', 'indexability', 'sitemap', 'sitemap_entries'];

    /**
     * In spec order. `how`: S = Server, B = Browser or Loopback, S+B = both
     * halves joined by target. `monitor` is the ★ column.
     *
     * Guidance (spec "Guidance per check"), fixed text, never site data:
     * `why` (Warum), `recommendation` (Empfehlung), `steps` (So geht's, may
     * be empty) and `links`: {label, url} with url an admin path relative to
     * wp-admin/ or a tab fragment of this page. A theme module's link also
     * carries `module` (its switch in sfx_general_options) and
     * `fallback_label`; AdminPage resolves it to the theme settings page when
     * that module is off. No module class is referenced.
     *
     * @return array<string, array{section:string, how:string, monitor:bool, title:string, why:string, recommendation:string, steps:list<string>, links:list<array{label:string, url:string}>}>
     */
    public static function all(): array
    {
        $s = self::SECURITY;
        $g = self::GOLIVE;
        $c = self::CLEANUP;

        return [
            'logs_public' => self::row($s, 'S+B', true, __('Public log files', 'sfxtheme'), [
                'why' => __('Log files often contain server paths, database errors and sometimes personal data. Anyone who can read them learns where the site is vulnerable.', 'sfxtheme'),
                'recommendation' => __('Delete log files in public folders and keep the debug log outside the web folder.', 'sfxtheme'),
                'steps' => [
                    __('Download the log file if you still need it, then delete it with the host\'s file manager or by SFTP.', 'sfxtheme'),
                    __('On a live site set define( \'WP_DEBUG_LOG\', false ); in wp-config.php, or a path outside the web folder.', 'sfxtheme'),
                    __('Add the block "Root folder: sensitive files" from the .htaccess template, so a new log cannot be read.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'config_copies' => self::row($s, 'S', true, __('Copies of wp-config.php', 'sfxtheme'), [
                'why' => __('Not every server runs a copy such as wp-config.php.bak as PHP; then it can be downloaded as text, database password included.', 'sfxtheme'),
                'recommendation' => __('Delete every copy now; if a copy held the password, change the database password too.', 'sfxtheme'),
                'steps' => [
                    __('Delete the files listed above with the host\'s file manager or by SFTP.', 'sfxtheme'),
                    __('If a copy contained the database password: set a new one in the host\'s panel and enter it in wp-config.php as DB_PASSWORD.', 'sfxtheme'),
                    __('Add the block "Root folder: sensitive files" from the .htaccess template as a safety net.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'backups_public' => self::row($s, 'S+B', true, __('Backups and archives in public folders', 'sfxtheme'), [
                'why' => __('A database dump or site archive in a public folder contains everything: content, user accounts and password hashes. Whoever guesses the name can download it.', 'sfxtheme'),
                'recommendation' => __('Keep backups off the server or in a folder the web server does not serve.', 'sfxtheme'),
                'steps' => [
                    __('Download the backups you still need and delete them from the folders listed.', 'sfxtheme'),
                    __('Set the backup plugin to store backups remotely (cloud storage, SFTP) instead of on this server.', 'sfxtheme'),
                    __('Add the block "Root folder: sensitive files" from the .htaccess template; it blocks .sql files.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::template_link(), self::link(__('Installed plugins', 'sfxtheme'), 'plugins.php')],
            ]),
            'vcs_env' => self::row($s, 'S+B', true, __('.git and .env files', 'sfxtheme'), [
                'why' => __('A readable .git folder can give away the whole source code; a .env file usually holds passwords and API keys.', 'sfxtheme'),
                'recommendation' => __('Remove .git and .env from the web folder, or block access to them.', 'sfxtheme'),
                'steps' => [
                    __('Deploy without the .git folder, or delete it on the server.', 'sfxtheme'),
                    __('Move .env one level above the web folder if the setup allows it.', 'sfxtheme'),
                    __('If they must stay: add "Root folder: sensitive files" and ".git folder: its own .htaccess inside .git" from the .htaccess template.', 'sfxtheme'),
                    __('If a .env was readable, change the passwords and keys it contains.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'phpinfo' => self::row($s, 'S', true, __('phpinfo and test scripts', 'sfxtheme'), [
                'why' => __('phpinfo() and test scripts show server paths, PHP modules and settings, a map for attackers. An unknown script in the root folder can also be left over from an attack.', 'sfxtheme'),
                'recommendation' => __('Delete the scripts listed after checking what they are.', 'sfxtheme'),
                'steps' => [
                    __('Open each file listed in the host\'s file manager and look at what it does.', 'sfxtheme'),
                    __('Delete test scripts you no longer need.', 'sfxtheme'),
                    __('If you do not recognise a script, treat it as suspicious: keep a copy for analysis, delete it and change the passwords.', 'sfxtheme'),
                ],
                'links' => [],
            ]),
            'dir_listing' => self::row($s, 'B', true, __('Directory listing', 'sfxtheme'), [
                'why' => __('With directory listing on, anyone can see every file in a folder, including files nobody was meant to find.', 'sfxtheme'),
                'recommendation' => __('Turn directory listing off for the whole site.', 'sfxtheme'),
                'steps' => [
                    __('Add the block "Root folder: directory listing" (Options -Indexes) from the .htaccess template.', 'sfxtheme'),
                    __('If the host forbids that directive (error 500): remove it again and ask the host to switch listing off.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'php_in_uploads' => self::row($s, 'S', true, __('PHP execution in the uploads folder', 'sfxtheme'), [
                'why' => __('If PHP runs in the uploads folder, one uploaded file is enough to take over the site. It is the most common way an attack escalates.', 'sfxtheme'),
                'recommendation' => __('Block PHP execution in the uploads folder.', 'sfxtheme'),
                'steps' => [
                    __('Open the .htaccess template tab and copy the block "Uploads folder: no PHP".', 'sfxtheme'),
                    __('Put it into the .htaccess file in the uploads folder (the path is shown with the block); create the file if it does not exist.', 'sfxtheme'),
                    __('On nginx, give the nginx text from the template to the host.', 'sfxtheme'),
                    __('Run the check again with the uploads probe ticked.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'php_files_uploads' => self::row($s, 'S', true, __('PHP files in the uploads folder', 'sfxtheme'), [
                'why' => __('The uploads folder should hold media only. A PHP file there is either a plugin\'s placeholder or something that does not belong, often a backdoor.', 'sfxtheme'),
                'recommendation' => __('Check every PHP file listed and delete what does not belong there.', 'sfxtheme'),
                'steps' => [
                    __('Start with the files marked as suspicious code: they need a look today.', 'sfxtheme'),
                    __('Open each file in the host\'s file manager; the plugin folder it sits in usually tells where it comes from.', 'sfxtheme'),
                    __('Delete files no plugin needs. If code looks malicious, treat the site as compromised: back it up, clean it and change all passwords.', 'sfxtheme'),
                    __('Block PHP in the uploads folder with the .htaccess template, so new files cannot run.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'debug_display' => self::row($s, 'S', true, __('Error display', 'sfxtheme'), [
                'why' => __('Error messages on the page show visitors server paths and code details, and can break the layout.', 'sfxtheme'),
                'recommendation' => __('On a live site keep errors out of the page: WP_DEBUG_DISPLAY false and display_errors off.', 'sfxtheme'),
                'steps' => [
                    __('In wp-config.php set define( \'WP_DEBUG\', false ); or, if you need debugging, define( \'WP_DEBUG_DISPLAY\', false );.', 'sfxtheme'),
                    __('Switch display_errors off in the host\'s PHP settings.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [],
            ]),
            'allow_url_include' => self::row($s, 'S', false, __('allow_url_include', 'sfxtheme'), [
                'why' => __('With allow_url_include on, a single bug in any plugin can load and run code from another server.', 'sfxtheme'),
                'recommendation' => __('Switch allow_url_include off.', 'sfxtheme'),
                'steps' => [
                    __('Look for allow_url_include in the host\'s PHP settings (php.ini, .user.ini) and set it to Off.', 'sfxtheme'),
                    __('If you cannot change it, ask the host.', 'sfxtheme'),
                ],
                'links' => [],
            ]),
            'https' => self::row($s, 'S+B', true, __('HTTPS', 'sfxtheme'), [
                'why' => __('Without HTTPS, logins and form data travel readable over the network, and browsers mark the site as not secure.', 'sfxtheme'),
                'recommendation' => __('Serve the site only over https:// with a valid certificate, and redirect http:// to https://.', 'sfxtheme'),
                'steps' => [
                    __('Activate a certificate in the host\'s panel (Let\'s Encrypt is usually free).', 'sfxtheme'),
                    __('Under Settings → General set both the WordPress address and the site address to https://.', 'sfxtheme'),
                    __('Switch on the redirect from http:// to https:// in the host\'s panel.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → General', 'sfxtheme'), 'options-general.php')],
            ]),
            'php_version' => self::row($s, 'S', false, __('PHP version', 'sfxtheme'), [
                'why' => __('A PHP version without security support no longer gets fixes for known holes.', 'sfxtheme'),
                'recommendation' => __('Switch to a PHP version that still receives security updates.', 'sfxtheme'),
                'steps' => [
                    __('Check that the plugins and the theme support the newer version, on a staging site first if you have one.', 'sfxtheme'),
                    __('Change the PHP version in the host\'s panel.', 'sfxtheme'),
                    __('Some hosts patch old versions themselves; if yours does, get it confirmed in writing.', 'sfxtheme'),
                ],
                'links' => [],
            ]),
            'security_headers' => self::row($s, 'B', false, __('Security headers', 'sfxtheme'), [
                'why' => __('Security headers tell the browser to block common attacks such as clickjacking and content sniffing. Once set, they cost nothing.', 'sfxtheme'),
                'recommendation' => __('Send the missing headers, and take care with the Content-Security-Policy.', 'sfxtheme'),
                'steps' => [
                    __('Open the Security Header module of this theme and switch on the headers listed as missing.', 'sfxtheme'),
                    __('Test a Content-Security-Policy on a staging site first: a strict policy can block scripts, fonts and embeds.', 'sfxtheme'),
                    __('The check only says whether a Content-Security-Policy and a Permissions-Policy are present; what they allow is not judged in detail. Review them with a dedicated tool if they matter for this site.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::module_link(__('Open Security Header', 'sfxtheme'), 'Security Header', 'enable_security_header', 'sfx-security-header')],
            ]),
            'file_editor' => self::row($s, 'S', false, __('File editor in wp-admin', 'sfxtheme'), [
                'why' => __('With the file editor on, anyone who gets into an admin account can change PHP code directly. One stolen password becomes full control.', 'sfxtheme'),
                'recommendation' => __('Switch the file editor off.', 'sfxtheme'),
                'steps' => [
                    __('Switch on "Disable Theme Editor" in WP Optimizer, or add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::module_link(__('Open WP Optimizer', 'sfxtheme'), 'WP Optimizer', 'enable_wp_optimizer', 'sfx-wp-optimizer')],
            ]),
            'xmlrpc' => self::row($s, 'B', false, __('XML-RPC', 'sfxtheme'), [
                'why' => __('XML-RPC lets anyone try many passwords in one request and is a common target. Few sites still need it.', 'sfxtheme'),
                'recommendation' => __('Switch XML-RPC off unless an app or Jetpack uses it.', 'sfxtheme'),
                'steps' => [
                    __('Switch on "Disable XMLRPC" in WP Optimizer.', 'sfxtheme'),
                    __('Optionally block the file as well with the block "Root folder: XML-RPC (optional)" from the .htaccess template.', 'sfxtheme'),
                ],
                'links' => [self::module_link(__('Open WP Optimizer', 'sfxtheme'), 'WP Optimizer', 'enable_wp_optimizer', 'sfx-wp-optimizer'), self::template_link()],
            ]),
            'registration' => self::row($s, 'S', true, __('Open registration', 'sfxtheme'), [
                'why' => __('With open registration anyone can create an account. If the default role can write or upload, strangers get that power too.', 'sfxtheme'),
                'recommendation' => __('Turn registration off, or set the default role to Subscriber.', 'sfxtheme'),
                'steps' => [
                    __('Under Settings → General untick "Anyone can register".', 'sfxtheme'),
                    __('If registration is wanted (shop, members), set "New User Default Role" to Subscriber or Customer.', 'sfxtheme'),
                    __('Under Users look for accounts that registered without your knowledge.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → General', 'sfxtheme'), 'options-general.php'), self::link(__('Users', 'sfxtheme'), 'users.php')],
            ]),
            'auto_updates' => self::row($s, 'S', false, __('Automatic security updates', 'sfxtheme'), [
                'why' => __('Minor WordPress releases mostly fix security holes. Without automatic updates a known hole stays open until someone updates by hand.', 'sfxtheme'),
                'recommendation' => __('Allow automatic minor core updates, unless an external update process covers them.', 'sfxtheme'),
                'steps' => [
                    __('Look in wp-config.php for WP_AUTO_UPDATE_CORE or AUTOMATIC_UPDATER_DISABLED and remove the setting that switches updates off.', 'sfxtheme'),
                    __('Check whether a plugin switches updates off, for example an update manager.', 'sfxtheme'),
                    __('If updates run elsewhere on purpose (WP Umbrella, MainWP, the host), that is fine: note it for the client.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Dashboard → Updates', 'sfxtheme'), 'update-core.php')],
            ]),
            'admin_accounts' => self::row($s, 'S', true, __('Privileged accounts', 'sfxtheme'), [
                'why' => __('Every administrator can do everything, including installing code. Each extra account is one more password that can leak, and "admin" is the first login attackers try.', 'sfxtheme'),
                'recommendation' => __('Keep this list short and replace accounts with a guessable login.', 'sfxtheme'),
                'steps' => [
                    __('Under Users remove administrators who no longer need the role, or give them a lower role.', 'sfxtheme'),
                    __('For a login such as admin: create a new administrator with another login, sign in with it and delete the old account, assigning its content to the new one.', 'sfxtheme'),
                    __('Make sure every administrator uses two-factor sign-in.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Users', 'sfxtheme'), 'users.php')],
            ]),
            'usernames_public' => self::row($s, 'B', true, __('Login names visible to guests', 'sfxtheme'), [
                'why' => __('A known login name is half of the login. Attackers collect them from author links, the REST API and feeds.', 'sfxtheme'),
                'recommendation' => __('Show display names instead of logins and close the public user sources.', 'sfxtheme'),
                'steps' => [
                    __('Under Users → All Users → Edit give each user listed a nickname and choose it under "Display name publicly as".', 'sfxtheme'),
                    __('In WP Optimizer switch on "Block ?author= Enumeration", "Block REST API Users for Guests" and "Remove Users Sitemap".', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Users', 'sfxtheme'), 'users.php'), self::module_link(__('Open WP Optimizer', 'sfxtheme'), 'WP Optimizer', 'enable_wp_optimizer', 'sfx-wp-optimizer')],
            ]),
            'bricks_permissions' => self::row($s, 'S', true, __('Bricks permissions', 'sfxtheme'), [
                'why' => __('Code execution in Bricks runs PHP from the builder: whoever holds it can do anything on the server. SVG files can carry scripts.', 'sfxtheme'),
                'recommendation' => __('Grant code execution and SVG upload only to administrators who need them.', 'sfxtheme'),
                'steps' => [
                    __('Under Bricks → Settings → Custom code switch code execution off if no one needs it, or limit it to administrators.', 'sfxtheme'),
                    __('Under Bricks → Settings → General → SVG uploads check which roles may upload SVG files.', 'sfxtheme'),
                    __('Check the users listed above: a grant on a single user overrides the role.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Bricks settings', 'sfxtheme'), 'admin.php?page=bricks-settings')],
            ]),

            'search_visibility' => self::row($g, 'S', true, __('Search engine visibility', 'sfxtheme'), [
                'why' => __('"Discourage search engines" asks search engines to stay away. On a live site that keeps the site out of Google; on a staging site it should stay on.', 'sfxtheme'),
                'recommendation' => __('Live: untick it at launch. Staging or private: keep it ticked.', 'sfxtheme'),
                'steps' => [
                    __('Open Settings → Reading.', 'sfxtheme'),
                    __('Set "Search engine visibility" to match the profile and save.', 'sfxtheme'),
                    __('If the profile itself is wrong, change it in the Settings tab of this page.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → Reading', 'sfxtheme'), 'options-reading.php'), self::settings_link()],
            ]),
            'robots_txt' => self::row($g, 'B', true, __('robots.txt', 'sfxtheme'), [
                'why' => __('robots.txt tells crawlers what they may read. "Disallow: /" for all crawlers keeps the whole site out of search results.', 'sfxtheme'),
                'recommendation' => __('On a live site let crawlers read the public pages.', 'sfxtheme'),
                'steps' => [
                    __('Find out who writes robots.txt: a real file in the root folder, an SEO plugin, or WordPress itself, which follows Settings → Reading.', 'sfxtheme'),
                    __('Remove the line "Disallow: /" under "User-agent: *" there.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → Reading', 'sfxtheme'), 'options-reading.php')],
            ]),
            'indexability' => self::row($g, 'B', true, __('Indexability of important pages', 'sfxtheme'), [
                'why' => __('A page with noindex, a foreign canonical or an error status does not appear in search results, however good its content is.', 'sfxtheme'),
                'recommendation' => __('Important pages should answer 200, carry no noindex and point their canonical to this site.', 'sfxtheme'),
                'steps' => [
                    __('Open the page listed and check its robots setting in the SEO plugin: noindex must be off.', 'sfxtheme'),
                    __('Check the canonical URL in the SEO plugin: it must point to this site.', 'sfxtheme'),
                    __('For a 404 or a redirect: restore the page, or correct the path in the Settings tab.', 'sfxtheme'),
                ],
                'links' => [self::settings_link()],
            ]),
            'sitemap' => self::row($g, 'B', false, __('Sitemap', 'sfxtheme'), [
                'why' => __('A sitemap helps search engines find every page, especially new and deeply linked ones.', 'sfxtheme'),
                'recommendation' => __('Publish a sitemap and name it in robots.txt.', 'sfxtheme'),
                'steps' => [
                    __('WordPress publishes /wp-sitemap.xml itself, and an SEO plugin may replace it. Make sure one of them is on.', 'sfxtheme'),
                    __('Add the line "Sitemap: <address of the sitemap>" to robots.txt; most SEO plugins do this themselves.', 'sfxtheme'),
                    __('Submit the sitemap in Google Search Console.', 'sfxtheme'),
                ],
                'links' => [],
            ]),
            'sitemap_entries' => self::row($g, 'B', false, __('Sitemap entries', 'sfxtheme'), [
                'why' => __('Entries such as Bricks templates, attachment pages or the author list lead visitors from search results to pages that were never meant to be found.', 'sfxtheme'),
                'recommendation' => __('Remove unwanted types from the sitemap, or mark them as intended.', 'sfxtheme'),
                'steps' => [
                    __('Exclude the types listed in the sitemap settings of the SEO plugin.', 'sfxtheme'),
                    __('For the author list: switch on "Remove Users Sitemap" in WP Optimizer.', 'sfxtheme'),
                    __('If a type is intended, tick it in the Settings tab under the sitemap entries; the check then stays quiet about it.', 'sfxtheme'),
                ],
                'links' => [self::settings_link(), self::module_link(__('Open WP Optimizer', 'sfxtheme'), 'WP Optimizer', 'enable_wp_optimizer', 'sfx-wp-optimizer')],
            ]),
            'admin_email' => self::row($g, 'S', false, __('Administration e-mail address', 'sfxtheme'), [
                'why' => __('WordPress sends security notices and password links to this address, and this check\'s warnings go there too.', 'sfxtheme'),
                'recommendation' => __('Make sure the address is current and someone reads it.', 'sfxtheme'),
                'steps' => [
                    __('Under Settings → General check "Administration Email Address".', 'sfxtheme'),
                    __('A change takes effect only after the confirmation link sent to the new address is clicked.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → General', 'sfxtheme'), 'options-general.php')],
            ]),
            'permalinks' => self::row($g, 'S', false, __('Permalinks', 'sfxtheme'), [
                'why' => __('Plain permalinks (?p=123) say nothing about the page and look untrustworthy. Changing them later breaks links that already exist.', 'sfxtheme'),
                'recommendation' => __('Choose a permalink structure before launch; changing it later can break existing links.', 'sfxtheme'),
                'steps' => [
                    __('Under Settings → Permalinks choose "Post name" and save.', 'sfxtheme'),
                    __('On a site that is already live, set up redirects from the old addresses first.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Settings → Permalinks', 'sfxtheme'), 'options-permalink.php')],
            ]),

            'test_content' => self::row($c, 'S', false, __('Sample content', 'sfxtheme'), [
                'why' => __('"Hello world!", the sample page and the sample comment look unfinished and show up in search results and feeds.', 'sfxtheme'),
                'recommendation' => __('Delete the sample post, the sample page and the sample comment.', 'sfxtheme'),
                'steps' => [
                    __('Under Posts, Pages and Comments move the items listed to the trash.', 'sfxtheme'),
                    __('Empty the trash.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Posts', 'sfxtheme'), 'edit.php'), self::link(__('Pages', 'sfxtheme'), 'edit.php?post_type=page'), self::link(__('Comments', 'sfxtheme'), 'edit-comments.php')],
            ]),
            'inactive_plugins' => self::row($c, 'S', false, __('Inactive plugins', 'sfxtheme'), [
                'why' => __('An inactive plugin\'s files stay on the server and can still be attacked, and they are easily forgotten at updates.', 'sfxtheme'),
                'recommendation' => __('Delete plugins you do not use; inactive code can still be attacked.', 'sfxtheme'),
                'steps' => [
                    __('Under Plugins → Installed Plugins, show the inactive ones.', 'sfxtheme'),
                    __('Delete what you no longer need; keep only what you will switch on soon.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Inactive plugins', 'sfxtheme'), 'plugins.php?plugin_status=inactive')],
            ]),
            'inactive_themes' => self::row($c, 'S', false, __('Inactive themes', 'sfxtheme'), [
                'why' => __('Like plugins, inactive themes stay on the server and can contain holes.', 'sfxtheme'),
                'recommendation' => __('Keep Bricks, this theme and one fallback theme; delete the rest.', 'sfxtheme'),
                'steps' => [
                    __('Under Appearance → Themes open each theme listed and delete it.', 'sfxtheme'),
                    __('Name the fallback theme you keep in the Settings tab, so it is not listed.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Appearance → Themes', 'sfxtheme'), 'themes.php'), self::settings_link()],
            ]),
            'updates' => self::row($c, 'S', true, __('Updates', 'sfxtheme'), [
                'why' => __('Most attacks use holes that an update has already fixed.', 'sfxtheme'),
                'recommendation' => __('Install the updates offered, after a backup.', 'sfxtheme'),
                'steps' => [
                    __('Make a backup, or make sure the nightly one is fresh.', 'sfxtheme'),
                    __('Install the updates under Dashboard → Updates, ideally on a staging site first.', 'sfxtheme'),
                    __('Look at the site afterwards: the home page, a form, the shop if there is one.', 'sfxtheme'),
                    __('"No updates offered" can also mean a premium plugin has no licence: check its licence.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Dashboard → Updates', 'sfxtheme'), 'update-core.php')],
            ]),
            'public_files' => self::row($c, 'S+B', false, __('Public default files', 'sfxtheme'), [
                'why' => __('readme.html and license.txt confirm that WordPress runs here, which is what scanners look for. An installer that still shows its setup form lets anyone take over the site.', 'sfxtheme'),
                'recommendation' => __('Block readme.html and license.txt with the .htaccess template, and close an open installer at once.', 'sfxtheme'),
                'steps' => [
                    __('Add the block "Root folder: sensitive files" from the .htaccess template; it blocks readme.html and license.txt. Deleting them does not last: they come back with every WordPress update.', 'sfxtheme'),
                    __('If install.php shows its setup form, WordPress finds no tables under the configured table prefix: the tables are missing or $table_prefix in wp-config.php is wrong. Restore the tables or correct the prefix right away.', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::template_link()],
            ]),
            'version_leaks' => self::row($c, 'B', false, __('Version numbers publicly visible', 'sfxtheme'), [
                'why' => __('A visible version number tells attackers which known holes to try. Hiding it fixes no hole, but it makes automated scans less precise.', 'sfxtheme'),
                'recommendation' => __('Remove the WordPress version from the generator tags, and ask the host to hide server and PHP versions.', 'sfxtheme'),
                'steps' => [
                    __('In WP Optimizer switch on "Disable WP Version".', 'sfxtheme'),
                    __('Ask the host to remove version details from the Server and X-Powered-By headers (ServerTokens Prod, expose_php off).', 'sfxtheme'),
                    __('Run the check again.', 'sfxtheme'),
                ],
                'links' => [self::module_link(__('Open WP Optimizer', 'sfxtheme'), 'WP Optimizer', 'enable_wp_optimizer', 'sfx-wp-optimizer')],
            ]),
            'table_prefix' => self::row($c, 'S', false, __('Database table prefix', 'sfxtheme'), [
                'why' => __('The default prefix wp_ makes some automated SQL attacks a little easier. On its own it is no hole.', 'sfxtheme'),
                'recommendation' => __('For information only; changing the prefix of a running site is not worth the risk.', 'sfxtheme'),
                'steps' => [],
                'links' => [],
            ]),
            'app_passwords' => self::row($c, 'S', false, __('Application passwords', 'sfxtheme'), [
                'why' => __('Application passwords let programs sign in without two-factor sign-in. A forgotten one stays valid until it is revoked.', 'sfxtheme'),
                'recommendation' => __('Revoke application passwords that are no longer used.', 'sfxtheme'),
                'steps' => [
                    __('Under Users → All Users → Edit open each owner listed, scroll to "Application Passwords" and revoke the ones nobody uses.', 'sfxtheme'),
                ],
                'links' => [self::link(__('Users', 'sfxtheme'), 'users.php')],
            ]),
        ];
    }

    /** The perspective a check's result is reported from (spec rule 8): server, browser or loopback. */
    public static function perspective(string $id): string
    {
        return self::perspective_for($id, (string) (self::all()[$id]['how'] ?? 'S'));
    }

    /** perspective() for a caller that already holds the row's `how` (loops over all()). */
    public static function perspective_for(string $id, string $how): string
    {
        if (in_array($id, self::LOOPBACK, true)) {
            return 'loopback';
        }
        return $how === 'S' ? 'server' : 'browser';
    }

    /**
     * Grades one observation against the run's snapshot.
     *
     * @return array{status:string, findings:list<array{id:string,status:string,label:string}>, perspective:string, note:string}
     */
    public static function grade(string $id, array $observation, RunContext $ctx): array
    {
        if ($id === 'php_in_uploads') {
            return Probe::grade($observation);
        }
        if (OutsideChecks::grades($id)) {
            return OutsideChecks::grade($id, $observation, $ctx);
        }
        if (ServerChecks::handles($id)) {
            return ServerChecks::grade($id, $observation, $ctx);
        }

        $note = isset(self::all()[$id])
            ? __('This check has not been graded.', 'sfxtheme')
            : __('Unknown check.', 'sfxtheme');

        return ['status' => Status::UNKNOWN, 'findings' => [], 'perspective' => 'server', 'note' => $note];
    }

    /** @param array{why:string, recommendation:string, steps:list<string>, links:list<array>} $guidance */
    private static function row(string $section, string $how, bool $monitor, string $title, array $guidance): array
    {
        return ['section' => $section, 'how' => $how, 'monitor' => $monitor, 'title' => $title] + $guidance;
    }

    private static function link(string $label, string $url): array
    {
        return ['label' => $label, 'url' => $url];
    }

    private static function template_link(): array
    {
        return self::link(__('.htaccess template', 'sfxtheme'), '#htaccess');
    }

    private static function settings_link(): array
    {
        return self::link(__('Settings of this check', 'sfxtheme'), '#einstellungen');
    }

    /** A theme module's admin page, by slug; `module` names its switch for AdminPage's fallback. */
    private static function module_link(string $label, string $name, string $switch, string $slug): array
    {
        return self::link($label, 'admin.php?page=' . $slug) + [
            'module' => $switch,
            /* translators: %s: name of a module of this theme */
            'fallback_label' => sprintf(__('Switch on %s in the theme settings', 'sfxtheme'), $name),
        ];
    }
}
