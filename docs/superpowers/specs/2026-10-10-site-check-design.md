# Sicherheits-Check (`SiteCheck`) — Design

New module `inc/SiteCheck/`. A go-live and security checklist an agency admin runs by hand
at every go-live, with opt-in monitoring afterwards. No story cited — unprofiled.
Verified against Bricks 2.4.2 and this checkout (2026-10-10). The check list was reviewed
by Codex as a sparring partner (advisory, 2026-10-10); its recommendations are adopted
below except where marked.

## Goal

Before a site goes live, someone has to work through the same list every time: is the
debug log public, can PHP run in the uploads folder, is the site still set to "discourage
search engines", are there leftover test pages, who can execute code in Bricks. Today that
list lives in heads and in an external scanner. WP Umbrella covers only the minority of
sites whose owners pay for it, so the theme check must stand on its own.

The module **checks and advises; it changes nothing on the site** except the one
explicitly confirmed active test (the uploads probe, below) and its own records.

## Decisions settled with Daniel (2026-10-10)

- **Check, don't fix.** Every result comes with a tip. Automatic fixes are a later,
  separate step and not part of this spec.
- **Manual run first; monitoring is opt-in.** Monitoring is off by default. Interval:
  off / daily / weekly. The admin picks which checks the monitor runs.
- **E-mail is a separate opt-in**, sent to the site's admin e-mail address
  (`admin_email`). Monitoring can run without mail.
- **Dashboard box** (added 2026-10-10, after the first Gate A): a summary box on the
  WordPress dashboard and in the Custom Dashboard, like the Theme Settings Overview box.
  It reaches the Custom Dashboard through a new registration hook, not a hard-wired
  entry, so no module-to-module edge is added.
- **Copy template for `.htaccess`.** Shown, never written by the module. It must work
  across Apache hosters and must not touch redirects, caching-plugin blocks or
  WordPress' own block.
- **Codex' recommendations adopted:** evidence rules before verdicts; red only for real
  dangers; three sections; unknown PHP files are never requested; the probe is its own
  confirmed test; a monitor state model; a smaller `.htaccess` template without
  `php_flag`; four additions (Bricks permissions, real indexability, a site profile,
  manual tick items).
- **Deviation from Codex:** no vulnerability database in v1. Core checksums against
  wordpress.org stay a later extra.

## Out of scope

- Fixing anything, including writing `.htaccess` (ImageOptimizer already writes the
  root `.htaccess`, `inc/ImageOptimizer/Controller.php:303-324`; a second writer needs
  write coordination first).
- Vulnerability feeds, malware verdicts, file-integrity checks.
- Enforcing anything on nginx. nginx snippets are shown as text only.
- Following URLs taken from sitemaps or XML to other hosts.

## Who can use it

The page and every endpoint require `SFX\AccessControl::can_access_theme_settings()`
**and** `manage_options`. The page is an ordinary admin page (a plain link to it works,
e.g. from the mail); every AJAX/REST request — **reading results included** — also
needs the nonce the page prints. Endpoints take fixed check IDs from the catalogue,
never paths or URLs.

Module registration follows the convention: `inc/SiteCheck/Controller.php` with
`get_feature_config()`, activation key `enable_site_check` in `sfx_general_options`,
default off, page under Tools like Redirects.

## Site profile

Set in the *Einstellungen* tab and shown beside "Prüfen" in *Übersicht* as the profile the next run uses; stored per site, never exported:

| Profile | Meaning |
|---|---|
| **Live** (default) | Public site meant to be found. |
| **Staging** | Copy for testing. Blocking search engines is expected. |
| **Privat** | Intranet / member site, not meant to be indexed. |

The profile only changes grading of the checks marked *P*, all in the *Live-Gang*
section. Every other check grades the same under every profile.

## Locations

All file and URL checks resolve their places from WordPress, never from fixed paths:

- **WordPress root** = `ABSPATH`, public at `site_url('/')`.
- **Web root** = `ABSPATH`, established only when `home` and `siteurl` are the same URL
  (ruling, Gate B pass 8: the front-controller recognition was removed). In every other
  case (WordPress in a subdirectory, different hosts) the web root is **not established**:
  its file checks are Nicht prüfbar with that reason, and only the other mappings are used.
- **Config** = `ABSPATH . 'wp-config.php'`, or one level above `ABSPATH` when WordPress
  loads it from there.
- **Content** = `WP_CONTENT_DIR`, public at `content_url()`; **plugins** =
  `WP_PLUGIN_DIR`, public at `plugins_url()`.
- **Uploads** = `wp_get_upload_dir()` `basedir`, public at its `baseurl` — wherever
  `basedir` lies on disk. (Not `wp_upload_dir()`, which creates folders; only the
  consented probe creates anything.)
- A file inside one of these mapped locations gets its URL from that mapping. A file
  in no mapped location is not fetched; its exposure is **Nicht prüfbar** ("liegt
  außerhalb der bekannten Webverzeichnisse; ein Server-Alias wäre nicht sichtbar").
- If `baseurl` or `site_url` is on a different host than `home_url` (CDN, offload), the
  outside checks for that location are *Nicht prüfbar* with that reason.
- **Fetchable URLs** are `http`/`https` on the host and port of `home_url`, without
  user info, normalised before the check. Anything else found in `robots.txt`, sitemaps
  or redirects is listed as skipped, never fetched. The `http://` → `https://` test in
  `https` is the one stated exception.

## Results

### Status values

| Status | Meaning |
|---|---|
| **Rot** | Confirmed danger, or a confirmed go-live blocker under the current profile. |
| **Gelb** | Should be looked at or fixed; not a confirmed danger. |
| **Grün** | Evidence confirms the good state. |
| **Hinweis** | Information only; no action implied. |
| **Nicht prüfbar** | No reliable evidence either way. Always says why. |
| **Offen / Erledigt** | Manual items (below). |

### Evidence rules (binding for every check)

1. **Green needs positive evidence.** Absence of a bad signal is not a good signal.
2. **Outside fetches judge content, not status codes.** A leak needs a content signature
   of the resource (e.g. a PHP log line pattern, `[core]` in `.git/config`, an SQL dump
   header). Every outside batch — Browser or Loopback — also fetches one random
   non-existent URL under the prefix `/sfx-site-check-missing-<random>`. That URL passes
   the same request guard (rule 6) as every target; a refused comparison is not requested,
   and the batch then has no soft-404 evidence (its `200` answers are Nicht prüfbar).
3. **"Not reachable from here now"** = `403`, `404`, `410`, a redirect, or — **only
   when the comparison URL itself answered `404`/`410`** — a response with the same
   status as the comparison. It never means "the file does not exist". Only together
   with the server's own look at the disk does it become a verdict (table below).
4. **Nicht prüfbar** = challenge pages, timeouts, `401`/`429`/`5xx`, empty bodies,
   truncated bodies without signature, unparseable answers, and any `200` when the
   comparison URL also answered `200` (soft-404 site) — except where a check row
   defines a status as its own finding (`indexability`). Rule 4 wins over rule 3. An
   empty complete `200` counts as "nothing there" only where a row says so
   (`dir_listing`, `robots_txt`), and only when the comparison URL answered `404`/`410`.
   One further deliberate exception: a non-empty `robots.txt` that reads as robots.txt
   (robots fields, or comments only) counts whatever the comparison answered — its
   content is its own signature (rule 2); an empty, HTML or unparseable one does not.
   `security_headers` judges the response headers of the home page, which arrive whole
   (up to 8 KB per value) even when its body is cut at 64 KB; it still needs a non-empty
   home page and a comparison that did not answer `200`.
5. **A confirmed leak of sensitive content is Rot regardless of anything else.** Disclosure
   of non-sensitive information (version numbers, readme/license, archive presence) takes the
   grade its catalogue row names.
6. **No unknown PHP is ever requested.** Allowed requests to PHP: the module's own
   endpoints, `xmlrpc.php`, `wp-admin/install.php` (GET), the module's own probe,
   WordPress' front end (`/`, `?author=…`, feeds, REST). A file counts as PHP when a
   PHP-like extension (`.php .phtml .php5 .php7 .phar .pht`) appears **anywhere** in its
   name — Apache can run `wp-config.php.bak` as PHP. Such files are read server-side
   only (name, size, first 4 KB — and only from a regular file, never a FIFO, socket or
   device), never fetched. Redirects are never followed by
   the browser (`redirect: 'manual'`), so no request can land on an unknown script.
   A path is decoded exactly once, as the web server does — and so are the location URLs
   it is matched against; a `%` escape left after that
   one decode (e.g. `%2561`) is ambiguous and never requested.
   A URL whose path is a folder on disk (mapped by "Locations") runs that folder's
   index file, so every request — whatever check found the URL — goes to a folder only
   when the server listed it and found no PHP-like `index.*` other than the exact silence
   placeholder; an unreadable folder, a path whose stat fails or whose nearest existing
   ancestor cannot be searched, or a path no known location maps, is not requested and
   is named. Only a path proven absent or proven not to be a folder passes. A link whose
   target cannot be inspected (dangling, or below an unsearchable folder) is unknown,
   never absent — on disk as well as at the request boundary. The home page is the front end; paths that are no folder on
   disk (permalinks, feeds, REST) stay allowed.
7. **Contents found are never stored or mailed** — only the fact, the URL and the
   signature name.
8. **Every result names its perspective:** *Server* (PHP inside WordPress), *Browser*
   (the admin's browser, `credentials: 'omit'`), *Loopback* (the server fetching its
   own public URL without cookies, redirects not followed). Checks that need the
   redirect target use Loopback in manual runs too.
9. **Every fetch is bounded:** 64 KB read, then aborted (stream reader in the browser,
   `limit_response_size` on the server); 10 s timeout. Signatures are matched within
   those bytes. A timeout is Nicht prüfbar for that target; the run never hangs.

### File exposure — one decision table

Used by `logs_public`, `backups_public`, `vcs_env`:

| On disk (Server) | From outside | Result |
|---|---|---|
| any | content signature confirmed | **Rot** |
| present | not reachable (rule 3) | **Gelb** — "liegt da, aktuell gesperrt; löschen" |
| absent | not reachable (rule 3) | **Grün** |
| any | Nicht prüfbar (rule 4) | **Nicht prüfbar** |
| present | `200` without signature | **Gelb** — "erreichbar, Inhalt nicht erkannt" |
| absent | `200` without signature | **Nicht prüfbar** (cache or rewrite serves something) |
| unknown (unreadable folder, failed listing, denied `stat`) | anything but a signature | **Nicht prüfbar** |

Rows are read top to bottom; the first match wins. **In monitor runs only**, the server
also revisits every target recorded in earlier monitor runs of `logs_public`, `backups_public` and `vcs_env` (an
old custom log path, a backup found once, a `.env` in a root that has since moved): checked on disk at its recorded place, a
missing file there means "absent".

### Finding identity

A **finding** is one problem at one place. Its ID is
`<check-id>:<target>`, where the target is fixed per check: a site-relative path (files,
URLs; the module's cache-buster removed — admin-chosen paths carry no query by rule),
`user-<ID>:<grant>` (accounts), or a setting
name (configuration). The probe's random file name is never part of an ID
(`php_in_uploads:probe`). Monitoring and mail compare these IDs only.

### Sections

Results are grouped in three sections, each sorted Rot → Gelb → Nicht prüfbar →
Hinweis → Grün:

- **Sicherheit** — exposure and attack surface.
- **Live-Gang** — what makes a site work and be found.
- **Aufräumen** — leftovers and housekeeping.

### Page layout and guidance

Added 2026-10-10 after the first implementation was seen in a browser (Daniel: the page
must be better structured, in tabs, and every check needs a recommendation and/or a
solution, comfortable to use). Same day, Daniel: the hints other scanners give — like
removing readme.html and license.txt from the root — belong in too; hence `public_files`
and the WordPress-version part of `version_leaks` grade Gelb.

**Tabs**, in wp-admin's own tab look, switching in place without a reload:

| Tab | Content |
|---|---|
| **Übersicht** (default) | Last saved run (date and time in the site's time zone and date/time format, user, profile) or "Noch nicht geprüft" with one sentence on what the check does; the profile the next run uses with a link to change it; "Prüfen" with the probe checkbox; one summary per section (counts Rot / Gelb / Nicht prüfbar) that opens that tab; the most urgent Rot and Gelb checks across all sections (at most 5), each opening its check; the manual items. Plan 2 adds a "Überwachung" panel here (last monitor run, overdue state, open red findings with "aus der Überwachung" and their time, last mail hand-over); a mail link opens this tab. |
| **Sicherheit**, **Live-Gang**, **Aufräumen** | That section's checks, sorted as in "Sections". Each tab label shows its Rot and Gelb counts. A switch "Nur Handlungsbedarf" hides Grün and Hinweis rows; it changes no count. Plan 2 puts the account-baseline approval on the `admin_accounts` row. |
| **.htaccess-Vorlage** | The template section, unchanged in content. Each block's copy button writes to the clipboard; where that is unavailable or refused, the block's text is selected and a message says to copy it by hand (Strg/Cmd+C). |
| **Einstellungen** | Profile, indexability paths, sitemap allow list, fallback theme. Plan 2 adds the "Überwachung" group here (interval, monitored checks, probe selection, e-mail switch). |

**Counting.** A check's status is the worst of its findings in the order Rot, Gelb,
Nicht prüfbar, Hinweis, Grün (a check with no findings takes the status its row grades).
Tab badges, the section summaries and the dashboard box count **checks** by status; a
row shows how many findings it has. Checks without a result are "Noch nicht geprüft" and
are counted nowhere.

**One run at a time on the page.** A run status line sits above the tabs and stays
visible on every tab (also announced to screen readers). While a run is in progress all
tabs show the incoming results, marked as in progress, and badges and summaries count
them. On a successful save the stored run replaces them. If the server refuses the save, a
notice above the tabs says the results are **not saved** and the last saved run still
counts; it offers "Erneut speichern" when the server was busy and "Neu prüfen" otherwise;
the unsaved results stay visible, marked as unsaved, until the page is reloaded. If no
answer arrives (lost connection, timeout), the notice says the save status is **unknown**
and offers reloading the page to see what was stored — it makes no claim either way.

**Settings.** The five indexability path fields each carry their own label ("Seite 1 für die
Indexierungsprüfung" …) and share the instruction as their description.

**Settings and runs.** A run uses the saved settings. Unsaved edits in *Einstellungen*
are marked "nicht gespeichert"; saving settings is disabled while a run is in progress.

**Rows.** Every check row is a native disclosure (`details`/`summary`): closed it shows
status, title and finding count ("0 Funde" included once graded); open it shows, in this order, the findings, the check's
own note (reason, coverage, perspective — also when there are no findings), and the
guidance. A row opens by default when it first receives a Rot or Gelb result; after that
the admin's open/closed choice is kept while further results arrive and across tab
switches, and rows are updated in place so focus is not lost.

**Links.** `#<tab>` opens a tab (`uebersicht`, `sicherheit`, `live-gang`, `aufraeumen`,
`htaccess`, `einstellungen`); `#check-<check-id>` opens the check's tab, opens and
focuses its row, and shows it even when "Nur Handlungsbedarf" would hide it. An unknown
fragment — including names an object inherits, such as `#constructor` — opens *Übersicht*. The fragment follows tab changes, so back and forward work.

**Accessibility.** The tab bar follows the WAI-ARIA tabs pattern (tablist, tabs with
selected state and controls, one tab stop with arrow-key movement, inactive panels
hidden from focus). Disclosures use the native element. Status is never shown by colour
alone.

**Empty states.** Before the first run: "Noch nicht geprüft" on every tab with the
"Prüfen" control — the *.htaccess-Vorlage* and *Einstellungen* tabs included; a check link (`#check-<id>`) still shows its own row. When "Nur Handlungsbedarf" leaves a tab empty after a run: "Kein
Handlungsbedarf in diesem Bereich."

**Guidance per check**, fixed text in the catalogue, the same for every site:

- **Warum** — one or two sentences on the risk or the go-live effect.
- **Empfehlung** — what to do, in one sentence.
- **So geht's** — numbered steps where the fix has steps; may link to the WordPress
  screen, the theme module (see "Coupling") or the *.htaccess-Vorlage* tab, or name the
  hoster's panel. A check that needs no action when green says so.

Guidance never contains data from the site; only findings and the check's note do. It
replaces the single tip line; it never changes a grade.

**Sitemap allow list** (*Einstellungen*): offers attachments, the author sitemap, every
post type and taxonomy that is public or publicly queryable, and every type the last
saved `sitemap_entries` result reported — never WordPress' internal types (revisions,
menu items, custom CSS, changesets, oEmbed cache, user requests, reusable blocks,
templates and template parts, global styles, navigation, font families and faces).
Entries already stored but no longer offered stay stored and keep silencing their type;
they are listed separately as "gespeichert, derzeit nicht angeboten" with a way to
remove them. Import keeps validating as before.

The status word for information is **Hinweis** everywhere (not "Notiz").

## Check catalogue

Columns: **ID** (stable, used in storage, mail and endpoints), **How** (S = Server,
B = Browser or Loopback), **★** = available to the monitor, grading.

### Sicherheit

| ID | Check | How | ★ | Grading |
|---|---|---|---|---|
| `logs_public` | `debug.log` (default and custom `WP_DEBUG_LOG` path), `error_log` in the web root and the WordPress root, PHP `error_log` path | S+B | ★ | File exposure table. Signature: PHP log line pattern. |
| `config_copies` | `wp-config` copies in the config location (`.bak .old .save .orig .txt ~ .swp`, `wp-config-sample.php` excluded) | S | ★ | Server only — every copy carries `.php` in its name (rule 6). Copy present → Gelb "Kopie der Konfiguration — sofort löschen", marked *mit Zugangsdaten* when it contains `DB_PASSWORD`, and listed first. Whether it is publicly readable is not tested (so never Rot); the row says so. Revisits copies found in earlier monitor runs: a complete listing without them means resolved. A copy whose stat fails is Nicht prüfbar, named, never dropped. |
| `backups_public` | `.sql`, `.sql.gz` and archives (`.zip .tar .tar.gz .tgz`) in the web root and the WordPress root and in the folders of common backup plugins | S+B | ★ | File exposure table. Signature: SQL dump header (`-- MySQL dump`, `CREATE TABLE`); for archives the archive magic bytes count only as Gelb ("öffentliches Archiv — prüfen"), never Rot. A candidate whose stat fails (unsearchable folder, broken link) stays a target with disk state unknown → Nicht prüfbar, never dropped; so does a backup-plugin folder that cannot be inspected (a broken link, a failed search, a folder that can be listed but not searched — empty or not) — a folder is left out only when its absence is established. A wildcard backup folder search counts as "nothing there" only when its parent folder could be listed and searched; otherwise the parent is named Nicht prüfbar. |
| `vcs_env` | `.git/HEAD`, `.git/config`, `.env` in the web root and the WordPress root | S+B | ★ | File exposure table. Signature: `ref:` / `[core]` / `KEY=value` lines. |
| `phpinfo` | `phpinfo.php`, `info.php`, `test.php`, `php.php` and similar in the web root and the WordPress root | S | ★ | Read on disk only (rule 6). Calls `phpinfo(` → Gelb "verrät Serverdetails — löschen". Unknown script → Gelb "prüfen und löschen". Only a regular file (or a link to one) is opened; anything else (FIFO, socket, device) is Nicht prüfbar, never read. |
| `dir_listing` | Directory listing of the uploads root, one dated uploads subfolder, `wp-content/plugins/`, `wp-includes/` | B | ★ | Listing detected by several markers (title "Index of", parent link, file rows) in a `200` answer that is no challenge, while the comparison URL did not serve a page (on a catch-all site the markers prove nothing → Nicht prüfbar). Gelb with the listed names. An empty complete `200` (the silence placeholder) counts as no listing only when the comparison URL answered `404`/`410`; otherwise Nicht prüfbar. |
| `php_in_uploads` | Uploads probe (active test, below) | S (Loopback, one request) | ★ | Outcome table in the probe section. |
| `php_files_uploads` | PHP-like files in uploads (`.php .phtml .php5 .php7 .phar .pht`) | S | ★ | Known silence placeholder (`<?php // Silence is golden`, ≤ 64 bytes) → Hinweis. Other content → Gelb with list. Known shell patterns (`eval(base64_decode`, `assert($_`, `system($_` …) → Gelb "verdächtiger Code — sofort prüfen", first in the list. The module's own registered probes are skipped only while their content is the expected one; a modified probe is scanned like any file. Scan limit per run (count and time, set in the plan); hitting it → Nicht prüfbar for the rest, named. A listed folder that cannot be searched, or an entry whose stat fails, is Nicht prüfbar, named — never skipped. A linked folder is not followed and is named Nicht prüfbar; folders are read entry by entry, so the scan limit also bounds the listing itself. A folder is checked for searchability right after it is opened, so an empty unsearchable folder is named too. |
| `debug_display` | `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG`, PHP `display_errors` master value (`ini_get_all()` `global_value`) | S | ★ | Configuration evidence only, labelled *laut Konfiguration*, following `wp_debug_mode()` (`wp-includes/load.php:613-640`). **WordPress decides** when `WP_DEBUG` is truthy and `WP_DEBUG_DISPLAY` is not null: truthy → display on → Rot; falsy → off → Gelb ("Debug-Modus an"). **PHP decides** in every other case (`WP_DEBUG` falsy, or `WP_DEBUG_DISPLAY` null): PHP master value (`ini_get_all()` `global_value`) on → Rot; off → Hinweis "laut PHP-Grundeinstellung aus — eine Ordner-Einstellung (`.user.ini`, `.htaccess`) sieht der Check nicht". Never Grün. The `enable_wp_debug_mode_checks` filter and runtime changes by plugins are not seen; the row says so. No error is triggered on purpose. |
| `allow_url_include` | PHP setting | S | | On → Rot ("gefährliche Konfiguration"). |
| `https` | `home` and `siteurl` on `https`; TLS answers; `http://` redirects to `https://` | S+B | ★ | Each part its own line. TLS and redirect measured by Loopback. Any part failing → Rot. |
| `php_version` | PHP version vs. upstream support | S | | Static table in code with source URL and check date. Upstream security support ended → Rot "Upstream-Support beendet (Hoster-Backports separat klären)". Ends within 6 months → Gelb. Version not in table → Nicht prüfbar. |
| `security_headers` | HSTS, CSP, `X-Content-Type-Options`, `X-Frame-Options` or CSP `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy` on the home page | B | Gate B pass 3 ruling — exact grammar only where it is small: HSTS (RFC 6797 directives, exactly one `max-age` with a positive digits value, `includeSubDomains` and `preload` without a value; malformed → Gelb, `max-age=0` → Gelb), `X-Content-Type-Options` exactly `nosniff`, framing (an enforcing CSP `frame-ancestors` wins over `X-Frame-Options`; a CSP header may hold several comma-separated policies, and every one with `frame-ancestors` must be `'none'`/`'self'`; only `frame-ancestors 'none'`/`'self'` or `X-Frame-Options` `DENY`/`SAMEORIGIN` → Grün, anything else → Gelb), `Referrer-Policy` (the last recognised token; Grün only for `no-referrer`, `same-origin`, `strict-origin`, `strict-origin-when-cross-origin`). CSP and `Permissions-Policy` are not judged in detail: an enforcing CSP or a Permissions-Policy present → Hinweis "vorhanden — Wirkung wird nicht im Detail bewertet"; CSP missing, only `Report-Only`, or only `upgrade-insecure-requests` ("vorhanden, schützt kaum") → Gelb; Permissions-Policy missing → Gelb. A header value longer than 8 KB is not judged → Nicht prüfbar for that line. Never Rot. Tip names the SecurityHeader module. |
| `file_editor` | Effective block via `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` | S | | Not blocked → Gelb. |
| `xmlrpc` | XML-RPC reachable and answering | B | | Gelb if it answers `system.listMethods` to an anonymous POST; no pingback calls. Tip names WPOptimizer's switch. A blocking status counts only with a non-empty, complete body (rule 4); otherwise Nicht prüfbar. A `200` answer counts only when the comparison URL clearly did not serve a page (rule 4). |
| `registration` | Open registration and the default role's capabilities | S | ★ | Off → Grün. On with a default role holding `edit_posts`, `upload_files`, `unfiltered_html`, `manage_options`, `bricks_execute_code` or `bricks_upload_svg` → Rot. On with a read-only role (only `read`/`level_0`) → Hinweis "beabsichtigt?". On with any other role → Gelb, listing its capabilities beyond `read`. The two Bricks grants count by key presence on the role, as Bricks resolves them (a `false`-valued `bricks_execute_code` still grants). |
| `auto_updates` | Core minor/security auto-updates effective | S | | Off → Gelb; the tip names "externer Update-Prozess" as a valid reason. A version-control checkout (WordPress' own `is_vcs_checkout()`, which blocks its automatic updates) → Gelb "Updates deaktiviert (Versionsverwaltung)". |
| `admin_accounts` | Users with a privileged grant: `manage_options` or Bricks code execution | S | ★ | List shown (Hinweis). Login `admin`/`administrator` → Gelb. Monitoring: see "Account baseline". |
| `usernames_public` | Login names visible to guests: `?author=1` redirect (Loopback), REST `/wp/v2/users`, author sitemap, feed, oEmbed | B | ★ | The browser returns the names it found; the server compares them **exactly** with real `user_login` values (logins never go to the browser). Match → Gelb. Display name equal to login but not found publicly → Hinweis. A source counts as read only when its answer is complete, has its own format (a JSON list — not an object — of users, each a JSON object with an integer `id` and a string `slug` or `name`; sitemap; feed; an oEmbed JSON object with `type` and `version` or string author fields) and the comparison URL did not answer `200`; otherwise it is Nicht prüfbar. A login found in any served answer still counts. `401` is no evidence (Nicht prüfbar); a `403`/`404`/`410` closes a source only with a non-empty, complete body (rule 4). A source not fetched (rule 6, Locations) is Nicht prüfbar with its reason. Author archive URLs are read under the site's own author base; JSON answers are decoded first (REST `slug`/`name`/`link`, oEmbed `author_name`/`author_url`); every name read is compared (no cap beyond the 64 KB per source) and every confirmed match is graded (the saved run's compaction handles display); feeds give Dublin Core `creator` and Atom `author/name`, read by the XML parser with namespaces (any prefix, attributes allowed, entities and CDATA decoded, comments not read). `?author=1` is judged against a comparison fetched by Loopback, from the same perspective. |
| `bricks_permissions` | Bricks code execution on (`\Bricks\Helpers::code_execution_enabled()`); who holds code execution and SVG upload | S | ★ | Grants resolved per user as Bricks 2.4.2 does (below). Code execution on and granted to anyone without `manage_options` → Rot. On, admins only → Hinweis. SVG upload for non-admins → Gelb. Bricks inactive or helper missing → Nicht prüfbar. No signature audit (Bricks has its own tool). |

**Bricks grants**, verified in `../bricks/includes/capabilities.php:229-264`
(2.4.2): an entry on the user (`$user->caps`) decides before the role
(`$user->allcaps`): `bricks_execute_code` grants, `bricks_execute_code_off` denies even
if the role grants; same pattern for `bricks_upload_svg` / `_off`. The check mirrors
this for any user ID (Bricks resolves it only for the current user). Builder access is
not checked — Bricks resolves it per post type and template, which a list cannot show
fairly. If a Bricks update changes these rules the check can be wrong; the row names the
verified version.

### Live-Gang

| ID | Check | How | ★ | Grading |
|---|---|---|---|---|
| `search_visibility` *P* | "Suchmaschinen davon abhalten" | S | ★ | *Live*: on → Rot, off → Grün. *Staging/Privat*: on → Grün, off → Gelb ("Seite kann in Suchmaschinen landen"). |
| `robots_txt` *P* | `robots.txt` parsed per user-agent group, `Allow` exceptions respected | B | ★ | Everything disallowed for `*` (a `Disallow` covering every path — `/`, `/*`; `/$` covers only the home page — and no `Allow` that actually wins for the literal path prefix it names; WordPress' `/wp-admin/` Allow — recognised on the normalised rule — does not count) → Rot under *Live*, Grün under *Staging/Privat*. Anything else → Grün under *Live*, Hinweis under *Staging/Privat*. Fetch fails → Nicht prüfbar. An HTML page or text that does not read as robots.txt → Nicht prüfbar; an empty file counts as "everything allowed" only when the comparison URL answered `404`/`410`. Rule and path are compared in one octet form (RFC 9309 §2.2.2): escapes of unreserved characters decoded, other escapes kept, non-ASCII bytes encoded; a rule's specificity (the longest match wins) is measured in that form too. A `404`/`410` for robots.txt means "no robots.txt" only with a complete, non-empty body (an error page); empty or truncated → Nicht prüfbar (rule 4). |
| `indexability` *P* | Home page plus up to 5 admin-chosen paths, fetched by Loopback: status, `noindex` in meta or `X-Robots-Tag`, canonical host, redirect target | B | ★ | By status, under *Live*: `200` HTML → Rot on `noindex` or canonical to another host; Gelb when `robots.txt` disallows this path for `*` ("Crawling gesperrt"); Grün only when neither applies **and** `robots.txt` was read — the check fetches it itself in the same run (`404`/`410` = everything allowed); if it cannot be read, Nicht prüfbar; `404`/`410` → Rot ("Seite fehlt"); `401`, `503` or redirect to `wp-login.php` → Rot ("nicht öffentlich erreichbar"); redirect to another path → Gelb; challenge page and anything else → Nicht prüfbar. Under *Staging/Privat*: `noindex`, `401`, `503` and login redirects are Grün; `404`/`410` → Gelb; the rest as under *Live*. `noindex` and the canonical link are read by an HTML parser (DOM, no network, only the bytes read): comments and script text are no markup, attribute values may be quoted or not, entities are decoded; no parser or a failed parse → Nicht prüfbar. A `200` counts as HTML only when the comparison URL did not also answer `200` (rule 4), the body is not empty and reads as HTML; a page longer than the bytes read is always Nicht prüfbar for its metadata (ruling, Gate B pass 4: what lies beyond the cut could carry noindex or a canonical link); the status verdicts (`404`/`410`, `401`, `503`, redirects) stay. A `Location` without a path means `/`; one that is no absolute URL or absolute path → Nicht prüfbar. robots.txt counts as read under the same conditions as in `robots_txt`. |
| `sitemap` *P* | Sitemap via `robots.txt`, `/wp-sitemap.xml`, `/sitemap_index.xml`, `/sitemap.xml` | B | | Found (a well-formed `urlset` or `sitemapindex` document; a truncated one by its root element, read in part; in a `200` only when the comparison URL did not also answer `200` or fail) → Grün. None found → Gelb under *Live*, Hinweis under *Staging/Privat* ("nicht gefunden", not "existiert nicht"); when an address gave no reliable answer (no answer, challenge, `401`/`429`/`5xx`, empty or a malformed sitemap, an empty or truncated `403`/`404`/`410`) → Nicht prüfbar. `sitemap_entries` counts only sub-sitemaps read this way. Every `Sitemap:` line of robots.txt is a candidate (bounded by its 64 KB); every candidate is guarded, also after a sitemap was found, and discovery goes on past refused ones. Every refused address is a finding of its own with its reason (Hinweis when a sitemap was found, Nicht prüfbar otherwise; in `sitemap_entries` Hinweis), so the saved run's compaction applies — never only a note. |
| `sitemap_entries` *P* | Sitemap index followed one level, max 20 sub-sitemaps, same host only; entries matched against the site's post types, taxonomies and the author sitemap | B | | Bricks templates (`bricks_template`), attachments, non-public post types and taxonomies → Gelb with list. Author sitemap → Gelb. Every type graded here can be marked gewollt in Einstellungen, except WordPress' internal types listed in "Page layout and guidance". Coverage always shown ("12 von 30 Sitemaps geprüft"); entries that match nothing → Hinweis "nicht zuordenbar". A per-site allow list ("gewollt") silences an entry type. `loc` elements are read by the XML parser with namespaces, so prefixed sitemaps (`sm:loc`) count. Every entry type read is graded — no cap on the number of types (reading is bounded by 64 KB per sitemap and 20 sub-sitemaps). |
| `admin_email` | Current `admin_email`, pending change | S | | Hinweis: "Ist das die richtige Adresse? An sie gehen die Warn-Mails." |
| `permalinks` | Plain permalinks | S | | Gelb, tip only; changing them can break existing URLs. |

Admin-chosen paths: stored site-relative (start with `/`, no scheme, host, query, `..`
or `.php`), validated on save, on import and before each fetch; an invalid stored path is
shown as rejected and skipped (Hinweis). A valid path the request guard refuses (an unsafe
or unreadable folder, an unmapped path) is Nicht prüfbar with the refusal reason.

### Aufräumen

| ID | Check | How | ★ | Grading |
|---|---|---|---|---|
| `test_content` | Published "Hello world" post, "Sample Page", the default comment — by ID 1/2 **and** title/content markers, translated titles included | S | | Gelb per item found published. |
| `inactive_plugins` | Inactive plugins | S | | Gelb with list. |
| `inactive_themes` | Inactive themes except Bricks and one named fallback | S | | Gelb with list. |
| `updates` | Updates offered for core, plugins, themes (incl. Bricks and this theme's updater) | S | ★ | Offered → Gelb with list. No offers → Hinweis "keine Updates angeboten (Stand: <Datum>)" — never Grün: WordPress keeps old data when a check fails, and premium updaters without a licence show no offer. |
| `public_files` | `readme.html`, `license.txt` in the WordPress root (disk + outside); `wp-admin/install.php` (outside) | S+B | | readme/license: content readable from outside (the file's own signature) → Gelb "entfernen oder sperren" — they return with every WordPress update, so the guidance points to the .htaccess block; present on disk and not reachable (rule 3) → Grün "liegt da, ist aber gesperrt"; absent and not reachable → Grün; Nicht prüfbar per rule 4 otherwise. `install.php`: setup form (not "bereits installiert") → Rot, nothing is submitted; "bereits installiert" → Hinweis; not reachable → Grün. A target not fetched (rule 6, Locations) is Nicht prüfbar with its refusal reason; the installer stays listed. |
| `version_leaks` | WordPress version in two sources: the home page's `generator` meta tag and the main feed's `generator` element; PHP/server version in the home page's response headers | B | | Per source, worst wins: the installed WordPress version found → Gelb (guidance names WP Optimizer's "WordPress-Version entfernen" switch); source read completely (rule 4 not hit) without it → Grün for that source, labelled "in den geprüften Quellen nicht gefunden" — never a site-wide claim; a version found within the bytes read counts even if the body was truncated (rule 4 covers truncation only without a match); feed answering `404`/`410` → Grün "Feed nicht vorhanden"; a redirect, challenge, `5xx`, truncation without a match, unparseable → Nicht prüfbar for that source (redirects are not followed). Server/PHP version in headers → Hinweis (host-controlled). `?ver=` on assets is not checked: it serves cache busting and the switch that strips it has a documented trade-off. The home page's generator is read by the same HTML parser as in `indexability` (exact `name="generator"`; no parser → Nicht prüfbar). The feed's generator is read by the XML parser with namespaces (CDATA and entities decoded, comments not read): only the feed's own generator — RSS `channel/generator` or Atom `feed/generator`, text and its `version`/`uri` attributes. The feed counts as read completely only as a well-formed feed document (`rss`, `feed`, `rdf:RDF`); a source not fetched (rule 6, Locations) is Nicht prüfbar with its reason. A feed answering `404`/`410` counts as "not present" only with a complete, non-empty error page (rule 4). |
| `table_prefix`, `app_passwords` | Prefix `wp_`; application passwords with owner and last use | S | | Hinweis only. |

### Manual items

Ticked by the admin, stored with user and date in their own option, reset by a button.
One write at a time: while a tick or the reset is pending, every item control is disabled.
Shown in the *Übersicht* tab only. Never touched by the monitor.

- Kontaktformular als Gast abgeschickt **und** die Mail ist angekommen.
- Backup außerhalb des Servers vorhanden **und** eine Wiederherstellung wurde getestet.
- Alle Administratoren nutzen Zwei-Faktor-Anmeldung.
- Test- und Altkonten entfernt; Zugänge an den Kunden übergeben.

## Uploads probe (active test)

The only check that writes a file. Labelled "aktiver Test: legt kurz eine Testdatei an".
It runs only when ticked for this manual run, or when selected for the monitor on this
site (that selection is the consent; it is never imported).

**One server request does it all** — in manual runs the probe check's own request, in
the monitor a step of the run. The perspective is always *Loopback*; the browser never
fetches the probe. So no pause in a browser tab can sit between creating, fetching and
removing.

**Lifecycle**, recorded in `sfx_site_check_probes`:

1. **Create**, inside the critical section (Storage → Writes): open
   `<uploads basedir>/sfx-site-check/<random 32-hex>.php` exclusively (fail if it
   exists). Only if that open succeeded is an entry `{path, run, expires: now + 5 min}`
   written — the full path, used by every later step even if the uploads location
   changes. If the open fails, nothing is recorded and nothing at that path is touched.
   If the open succeeded but writing the content failed, the entry is still written and
   the file is handled by the removal rule (content differs → kept and reported). A
   crash after the open but before the entry is written leaves an unregistered file,
   which is reported, never deleted. If the entry cannot be written and the just-opened
   empty file cannot be removed either, that file is reported as a cleanup line too.
2. **Fetch** it once by Loopback (bounded, rule 9), right after this batch's comparison
   URL (rule 2), which the outcome table's "like the comparison" refers to.
3. **Remove** it in the same request.
4. **Background cleanup** — on loading the check page, on every monitor run, and daily
   by its own cron hook regardless of monitoring — handles **expired** entries, which
   only a crashed request leaves behind. Unregistered files in the folder are reported,
   never deleted. When the page-load cleanup cannot enter the critical section, the page
   says so (busy, reload to try again).
5. **Teardown** (purge only) handles every entry, expired or not.

**Removing an entry** (steps 3, 4, 5 alike): file has the expected content → delete it,
then drop the entry; file missing → drop the entry; file differs or deletion fails →
keep the file and the entry, report it. The expected content follows from `expires`.

**File content:** no includes, no request parameters. It prints one line, the marker,
built at runtime from two parts so the source never contains the printed line; after
`expires` it prints nothing. A leftover is therefore inert once its `expires` has passed —
at most five minutes after it was created; until then it can still answer.

**Outcome** (execution and cleanup are reported separately; rule 4 wins over rule 3):

| Fetch result | `php_in_uploads` |
|---|---|
| body equals the expected line | **Rot** — PHP läuft im Upload-Ordner |
| body contains `<?php` | **Gelb** — Quelltext wird ausgeliefert, aber nicht ausgeführt |
| `403`, `404`, `410`, or like the comparison (rule 3, **without** redirects) | **Grün** — gesperrt |
| redirect | **Nicht prüfbar** — "leitet weiter nach <Ziel>"; not followed |
| Nicht prüfbar (rule 4), or any other body | **Nicht prüfbar** |
| create or fetch failed, or the entry was gone before the fetch | **Nicht prüfbar**, with the failing step |

Cleanup failure is its own line: Gelb "Testdatei konnte nicht gelöscht werden: <Pfad>".
The result covers `.php` in that folder only and says so.

## Manual run

1. Admin opens Tools → Sicherheits-Check, checks the profile, presses "Prüfen".
2. The server issues a **run**: ID, start time, profile and settings snapshot, stored
   as the *issued run* in `sfx_site_check_manual` (the previous saved results stay
   untouched). The browser gets the run and the list of checks.
3. The browser runs each check as its own request, at most 4 in parallel. Server checks
   return their observation; browser checks fetch (bounded, rule 9) and send what they
   saw to the server, which grades it against the run snapshot. Graded results are
   shown as they come in.
4. At the end the browser sends one **save** with all observations. The server accepts
   it only if its run ID is the issued run, re-grades against the snapshot and replaces
   the saved results in one write. Starting a new run therefore invalidates the old
   one; purge deletes the issued run, so no save from before the purge lands.
5. A closed tab saves nothing; the previous results stay. Results show date, user,
   profile and perspective.

## Monitoring

Off by default. Settings: interval (off/daily/weekly), the subset of ★ checks (at least
one; an empty selection cannot be saved with monitoring on), the e-mail switch.

### Run

- WP-Cron. One run at a time via its own lock option `sfx_site_check_lock`, taken with
  an atomic insert that fails if the row exists (not `add_option()`, which overwrites under
  a race) and holding a run token; lease 15 min, total run
  deadline 10 min, after which remaining checks are Nicht prüfbar. Every write and the
  mail send first check that the lock still holds this run's token; if not, the run
  stops without writing.
- The run reads the settings and baseline once at its start. Changes an admin makes
  meanwhile apply from the next run — including deselecting the probe.
- Sequential, bounded fetches (rule 9), Loopback perspective. Blocked loopback →
  Nicht prüfbar.
- A manual run never reads or changes monitor state, and vice versa.
- **One writer per option:** only the monitor run writes `sfx_site_check_monitor`;
  admin actions write only settings and baseline. Purge is the exception (it deletes).

### State

Per finding ID: `{status, since, last_seen}`. Per check: `{last_attempt, outcome,
targets_covered}`.

- An observation updates only the findings of the **targets it covered**. A target
  that timed out keeps its findings as they were.
- A finding is **open red** from a valid Rot observation until a valid observation of
  the same target is not Rot (Grün, Gelb, Hinweis). *Nicht prüfbar* changes nothing.
- A finding that **becomes** open red goes into the **pending** list. Leaving open red
  and coming back is a new event and goes in again.
- First run: every open red finding goes into pending.
- **Retirement:** findings of a check no longer selected, or of an indexability path no
  longer configured, are removed from state and pending at the next run.

### Account baseline

The admin approves the current privileged grants with a button: stored in its own
option per user ID as a set of grants (`manage_options`, Bricks code execution). The
monitor compares the current grants with the baseline: every grant not in it is an open
red finding `admin_accounts:user-<ID>:<grant>` — new accounts and promotions alike.
Removing a grant is shown as Hinweis. Re-approving replaces the baseline. Without a
baseline the check is Nicht prüfbar "Ausgangsliste noch nicht bestätigt" and raises
nothing.

### E-mail

- Delivery is **at least once**: a digest goes out after a run when the mail switch is
  on and pending is not empty. It holds check names, finding IDs and a link to the page
  — no file contents, no config values.
- Before deciding whether to send, every completed run drops each pending finding that
  is no longer open red — whatever the mail switch and whatever happened to earlier
  sends. The `overdue` entry is not a finding and stays until handed over.
- `wp_mail()` returned true (shown as "übergeben", not "zugestellt") → pending is
  cleared. False → pending stays for the next run. A crash between hand-over and
  clearing can send one digest twice.
- The settings store when mail was last switched on. The first run that sees a newer
  switch-on time than the one it last consumed puts every open red finding into
  pending and records that time as consumed — so a failed hand-over is retried like
  any other pending entry. While mail is off nothing is sent; pending keeps collecting.

### Overdue

Settings store when monitoring was turned on or its interval changed (`armed_at`); the
monitor stores when its last run finished. Overdue = `now − max(last_finished,
armed_at)` exceeds twice the interval. The check page computes it fresh on every load,
so a cron that never runs shows as overdue. A run computes it once at its start, before
anything is updated; if it was late it adds an `overdue` entry to pending, so the notice
is retried like any finding until a digest is handed over. No overdue flag is stored.
Monitoring off → never overdue.

### Turning things off

- **Module disabled** (`enable_site_check` off): the controller is not loaded, so its
  cron callbacks do nothing; settings and state are kept. Leftover probes turn inert at their
  expiry (at most five minutes) and
  are cleaned on the next page load after re-enabling, which resumes the stored settings.
- **Theme switch:** nothing runs. The cron events have no callback without this theme's
  code and do nothing; leftover probes turn inert at their expiry (at most five minutes). Switching back resumes; purge removes
  everything.
- **Monitoring off:** unschedules the monitor hook; state is kept.

## `.htaccess` template

Shown as copy text; the page states that Sicherheits-Check never writes these files (it does
not speak for the whole theme). Steps per destination: 1. download the current `.htaccess` of that
folder (file manager or SFTP) as a backup, or note that none exists; 2. root blocks go
**above** `# BEGIN WordPress`, or at the top when that marker is absent; the uploads and
`.git` blocks go at the top of their own folder's `.htaccess`, created if missing; 3. run
the check again; 4. on a 500 error put the backup back (or delete the newly created file). Paths come from "Locations". Separate blocks, each with
`# BEGIN sfx-…` / `# END sfx-…` markers, so they can be added one at a time:

- **Root, sensitive files:** deny `wp-config` copies, `*.sql`, `*.log`, `error_log`,
  `.env`, `readme.html`, `license.txt` via `FilesMatch` — Apache 2.4 syntax inside
  `<IfModule mod_authz_core.c>`, 2.2 syntax inside `<IfModule !mod_authz_core.c>`.
- **`.git` folder:** `FilesMatch` matches file names, not a folder path like `.git/`,
  and a pattern for `HEAD` or `config` would hit unrelated files. The tip is to
  remove `.git` from the web root; if it must stay, a separate one-line deny-all
  `.htaccess` **inside** `.git/`, same two-version syntax.
- **Root, directory listing:** `Options -Indexes` as its own block (a host can forbid it
  via `AllowOverride`, which gives a 500).
- **Uploads folder** (`<uploads>/.htaccess`): deny the PHP-like extensions listed
  above via `FilesMatch`, matched anywhere in the name as in rule 6 (`x.php~`, `x.phtml.bak`).
  The nginx text scopes the same rule to the resolved uploads URL path — decoded once (nginx
  matches the decoded URI), regex characters escaped, quoted — and leaves it out, with a note,
  when the web root is not established, when that path is unknown, is the site root, or lies
  on another host than the site's home host (CDN,
  offload: "Uploads liegen auf einem anderen Host — Regel dort setzen") — never a site-wide
  PHP deny. No `php_flag`, no handler changes.
- **XML-RPC:** commented out, with the note that apps and Jetpack need it.

Only access-control and `Options` directives: no `RewriteRule`, `Redirect` or `Header`,
so the theme's Redirects module (PHP-only), caching-plugin blocks and WordPress' block
are not touched. The page does **not** promise freedom from conflicts; it names the
`AllowOverride` risk. nginx equivalents are shown as text when the server looks like
nginx, with the note that an Apache rule does nothing for files nginx serves itself.

## Storage

All options prefixed, `autoload = no`, listed in DataPurge's ownership list (`DataPurge::option_names()`):

| Option | Written by | Content | Exported |
|---|---|---|---|
| `sfx_site_check_settings` | admin | profile, monitor interval, monitored check IDs, mail switch, `armed_at`, mail-on time, indexability paths, sitemap allow list, fallback theme | only indexability paths and sitemap allow list |
| `sfx_site_check_baseline` | admin | account baseline | no |
| `sfx_site_check_manual` | admin | issued run, last saved run | no |
| `sfx_site_check_items` | admin | manual items | no |
| `sfx_site_check_monitor` | monitor run | state, pending, last run, mail result | no |
| `sfx_site_check_lock` | monitor run | run token, taken at | no |
| `sfx_site_check_probes` | both | probe entries | no |
| `sfx_site_check_mutex` | both | critical-section token, taken at | no |

**Import** reports what was actually stored after merging and the 5-path cap: entries
rejected as invalid and entries that did not fit are counted separately, on the full
incoming list (validation does not cap).
**Import** writes only the two exported keys and leaves everything else on the
destination as it is. `enable_site_check` travels with `sfx_general_options` like every
other module switch. Importing it can turn the module back on, which resumes whatever
monitoring the **destination** had stored — that site's own earlier consent, exactly as
re-enabling by hand would. Nothing from the source site starts monitoring or the probe.

**Writes.** Every read-check-write on the module's options — creating and removing
probe entries, issuing a run, accepting a save, every monitor write, purge — happens
inside one short critical section: a mutex option `sfx_site_check_mutex` taken with
an atomic insert that fails if the row exists (not `add_option()`), held only for that read-check-write, treated as stale
after 30 s. Taking over a stale mutex must not let its old owner write afterwards: every protected
write succeeds only if the mutex still holds the writer's own token at that moment
(a conditional write), otherwise it is dropped and reported; the mechanism is the
plan's. A caller that cannot get it within 5 s gives up visibly ("beschäftigt, bitte
erneut versuchen"; the monitor marks the affected check Nicht prüfbar). A validity check
(run token, issued run) and the write it guards are always in the same section, so
nothing written after purge or after a newer run was issued can land.

**Purge** (`DataPurge::run()`), inside the critical section, in this order: unschedule the module's cron hooks;
delete the lock (a running monitor stops at its next fenced write); delete the issued
run (no manual save lands afterwards); tear down probes (lifecycle step 5, Teardown); delete the
remaining options — except `sfx_site_check_probes` when a probe could not be deleted,
which stays and is reported in the purge result. A delete that does not go through
(section taken over, database error) is reported in the purge result as such, never
counted as an absent option; if deleting the lock or the issued run fails, the purge
stops there and tears nothing else down. Files in the probe folder that no entry records
are kept and reported. A cron hook whose events could not be unscheduled, and a mutex row
the section could not release (database error), are reported too.
The theme's purge notice says so (a partial purge, to be run again).

Stored text is size-limited and escaped at output.

## Dashboard box

A read-only summary, ID `sfx_site_check`, title "Sicherheits-Check". It shows:

- date and profile of the last saved manual run, or "Noch nicht geprüft";
- counts of Rot, Gelb and Nicht prüfbar from that run;
- up to three Rot findings by check name, Sicherheit section first;
- monitoring on/off, last finished run, and the overdue notice as computed on the
  check page;
- a link "Jetzt prüfen" to the check page.

It reads stored results only; it never runs a check, takes the mutex or writes. It is
shown only to users who pass the page's access test (theme access **and**
`manage_options`); everyone else gets nothing, not an empty box. All output is escaped
in the box's own renderer. Nothing about findings beyond check names and counts is
shown; no paths, no file contents.

**Where it appears:**

- **WordPress dashboard:** the module registers it with `wp_add_dashboard_widget()` on
  `wp_dashboard_setup`, for allowed users only. When the Custom Dashboard is on, it
  clears all native widgets (`inc/CustomDashboard/Controller.php:162-187`), so the
  native box disappears there without the module having to know.
- **Custom Dashboard:** through a new filter `sfx/custom_dashboard/widgets`, which the
  CustomDashboard module adds. A module returns entries
  `id => {title, render (callable), can_render (callable)}`. CustomDashboard uses them in
  three places: the widget picker (`Settings::get_available_widgets()`), the renderer's
  widget map (`DashboardRenderer::render_single_widget()`), and the visibility check
  (`can_render_dashboard_widget()`), where an entry's `can_render` decides. Entries are
  off by default in the picker, like every other widget. An entry whose module is
  disabled is simply absent: a saved selection naming it renders nothing, as an unknown
  ID does today. The existing hard-wired Theme Settings Overview entry stays as it is;
  moving it onto the filter is not part of this change.

The filter is CustomDashboard's public contract; SiteCheck depends on the hook name
only, never on a CustomDashboard class.

## Coupling

No new module-to-module edges (AGENTS.md "Dependency direction"); the dashboard box uses
the `sfx/custom_dashboard/widgets` hook contract, not a class. AGENTS.md gets one line
naming that filter as CustomDashboard's extension point. Checks observe real
behaviour; where another module explains a result (SecurityHeader, WPOptimizer's XML-RPC
and author switches) the guidance names it and may link to its admin page by URL (an
admin URL string — never a class of that module); when that module is off, the link
goes to the theme settings page where it is switched on. `DataPurge` gains the module's
options, cron hook names and probe teardown, as it already does for Redirects. The
module's own outside fetches can land in the Redirects 404 log; the comparison URL's
fixed prefix `/sfx-site-check-missing-` makes them recognisable.

## Acceptance criteria

1. Without theme access or `manage_options` the page and every endpoint refuse; an
   endpoint call with a missing or wrong nonce refuses — reads included.
2. File exposure follows the table: a public `debug.log` with log content is Rot; a
   present log answering 404 is Gelb; an absent log answering 404 is Grün; on a soft-404
   site (comparison URL answers `200`) an absent log whose URL also answers `200` is
   Nicht prüfbar, never Rot or Grün;
   an unreadable folder never gives Grün.
3. No request is made to any PHP file outside the allowed list in rule 6, including via
   redirects and names like `wp-config.php.bak`; no URL outside the fetchable-URL rule is
   requested.
4. Probe: after a run the file is gone; after a crash after creating it, the file is
   gone once expired and cleaned; a pre-existing file at the drawn name is never
   deleted; an unregistered or modified file is reported and kept, on every removal
   path; purge removes unexpired own probes; a probe is removed from its recorded path
   after the uploads location changed; two probes created at the same moment both keep
   their entries; a probe whose entry purge removed before the fetch is Nicht prüfbar,
   never Grün.
5. Probe outcomes follow the outcome table; an empty `200` is Nicht prüfbar.
6. *Staging*: "Suchmaschinen abhalten" Grün; *Live*: Rot. `https` grades the same in
   every profile. A configured path answering `404` is Rot under *Live*.
7. `debug_display` follows the stated truth table, including `WP_DEBUG` false with PHP
   `display_errors` on → Rot.
8. Monitor: a red finding already handed over that turns Nicht prüfbar stays red and
   raises no new alert (a pending, not yet handed-over finding is still retried); the
   next Rot does not alert again; a second leaking file in an already red check alerts; a
   deselected check's findings leave state and pending.
9. Promoting an editor to administrator after baseline approval is Rot in the next
   monitor run and mailed; a baseline user gaining code execution is too.
10. A failed mail is retried after the next run; switching mail on mails current reds
    once; an overdue notice is retried until handed over.
11. A manual run does not change monitor state; a save for a superseded run, or after
    purge — including a save whose check passed just before purge started — is
    refused, also when the saving request took over a stale mutex or lost it to a
    takeover; the first save on a fresh site works. No check creates a folder unless the
    probe runs.
12. Import leaves profile, monitor, mail switch, probe selection and baseline untouched;
    export contains only the listed keys. Purge removes all options, cron hooks and
    deletable probes, and reports probes it could not delete.
13. All new strings use `sfxtheme` and have German translations.
14. Dashboard box: shown on the WordPress dashboard and, once enabled in the picker, in
    the Custom Dashboard to allowed users; absent for a user without theme access or
    without `manage_options` in both places; shows "Noch nicht geprüft" on a fresh
    site; never writes an option or runs a check; with SiteCheck disabled it is absent
    from the picker and a saved selection renders nothing.

15. Page: six tabs and behaviour as in "Page layout and guidance"; the fragment reopens a tab on
    reload; every catalogue check has Warum and Empfehlung text (and So geht's steps where
    the fix has steps) in German; Rot/Gelb rows open by default when they first get their result, others closed, later
    admin choices and check links win; "Nur
    Handlungsbedarf" hides Grün and Hinweis rows; tabs and disclosures work by keyboard;
    dates on the page and in the box use the site's time zone and date format.

16. `public_files`: readable readme.html → Gelb; after the template block (403) with the
    file still on disk → Grün "gesperrt"; absent on disk and not reachable → Grün; absent
    on disk but its content still served (cache) → Gelb; a challenge → Nicht prüfbar. `version_leaks`: generator
    meta with the installed version → Gelb; the same version in the feed only → Gelb; a
    plugin's version in another meta tag → not counted; feed disabled (404) and no
    generator meta → Grün ("Feed nicht vorhanden", "in den geprüften Quellen nicht gefunden"); challenged or
    truncated feed without a match → Nicht prüfbar for the feed while the home source
    still grades; a truncated feed whose generator appears in the bytes read → Gelb; a
    redirected feed → Nicht prüfbar; server/PHP header → Hinweis. No extra request beyond
    the home page, the feed and the batch's comparison URL (rule 2).

## Open for the plan

- Two plans: (1) module, locations, catalogue, manual run, probe, template, storage,
  purge/export, dashboard box and the CustomDashboard filter (the box's monitoring line
  stays hidden until plan 2); (2) monitoring and mail. Plan 2 builds on the finding identity, probe
  lifecycle and option shapes defined here.
- Signature lists, the PHP support table with sources, backup-plugin folder list, scan
  limits.
