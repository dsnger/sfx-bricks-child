# Theme Data Purge — design

**Date:** 2026-08-25 · **Status:** implemented on `feature/danger-zone-purge`

> Written after the implementation, not before it. The work was classified as a
> bounded change and agreed from a short design in chat. For a feature that
> deletes data irreversibly that was the weaker call, so the reasoning is
> recorded here where the next person will look for it.
>
> Revised after an independent review of the diff, which **failed** the first
> version. Two of its findings were contradictions inside this design rather
> than slips in the code, and both are marked below.

## The problem

The theme's General Theme Options screen carried a switch:

> **Delete Data on Uninstall** — Delete all theme settings and data when the
> theme is deleted.

It could never do that. **WordPress runs no uninstall routine for a theme.**
`uninstall.php` and `register_uninstall_hook()` are a plugin convention
(`wp-admin/includes/plugin.php`); `delete_theme()` never includes the file. Nor
could it: an active theme cannot be deleted, so a theme must be switched away
from first, and by the time its files are removed none of its code is loaded to
run anything.

`delete_theme` / `deleted_theme` hooks do exist (WP 5.8+), but they fire in the
context of whatever is active at that moment — the *new* theme and the plugins.
A theme cannot subscribe to its own deletion. Only a plugin or mu-plugin could.

One qualification, since the claim above is easy to overstate: `delete_theme()`
carries no active-theme guard of its own, and WP-CLI will force-delete an active
theme. In that path the theme's `functions.php` *has* loaded and its own
`delete_theme` callback could run. The narrower statement is the true one —
**ordinary dashboard deletion happens after switching away**, so a theme cannot
rely on its code being loaded, and a mechanism that only works under `wp theme
delete --force` is not a mechanism.

So the switch promised something with no moment to happen in, and the purge code
behind it had never executed once.

### What that leaves behind

Measured on the development install: 36 `sfx_*` option rows totalling ~71 KB
(one WebP conversion log alone is 55 KB), plus the Media Credits attachment meta
— six rows there, but that number scales with the media library.

## The decision

**Purge on demand, while the theme is active.** A Danger Zone on the General
Theme Options screen deletes now; there is no later. It is the only moment the
theme controls its own code running.

The switch is removed rather than relabelled. Two controls for one job, one of
which never works, is worse than one that does.

### Rejected: a companion mu-plugin on `delete_theme`

It would work, and it costs a second artifact that has to stay installed on
every client site for a job done once. More moving parts than the problem
deserves.

## Scope: settings, not content

| Deleted | Kept |
|---|---|
| The theme's options (`DataPurge::OPTION_NAMES`) | Contact Infos, Social Media Accounts, Custom Scripts posts |
| Media Credits attachment meta — **only when explicitly ticked** | Posts, pages, media files; the Media Credits meta by default |
| Transients under the theme's own key prefixes (`DataPurge::TRANSIENT_PREFIXES`) | Everything belonging to plugins or to WordPress |

**The Media Credits meta is kept by default.** The first version deleted it
outright while the success notice said "your content was not touched" — a
contradiction review caught and the reasoning behind it was wrong anyway. A
copyright notice is typed by an editor, can matter legally, and is no less
content than the Contact Infos posts the purge leaves alone.

It now has its own checkbox beside the confirmation field, checked on the server
like the phrase. When it is ticked all three keys go together: leaving the IPTC
marker would make a later reinstall skip the prefill for attachments whose
copyright had just been deleted.

Note for anyone reusing the keys: `delete_post_meta_by_key()` is not scoped to
attachments. It removes the key from every post type. Harmless while only the
Media Credits module writes them, and only to attachments.

The CPT posts stay because an editor typed them. A button called *delete theme
data* must not carry off someone's contact details — surprises of that shape are
exactly what a danger zone exists to prevent. They also survive as ordinary
posts and are readable again if the theme returns.

## Membership, not prefix

**Deleting everything matching `sfx_` is wrong on this estate.** The prefix is
not evidence of ownership: the site's own plugins use it too. `sfx_animation_*`
belongs to SFX Animations, `sfx_feedback_*` to SFX Feedback, `sfx_drilldown_*`
to SFX Drilldown Menu. A prefix sweep would take a customer's plugin
configuration with it.

Membership of an explicit list is the only ownership claim that holds.

### How NOT to establish ownership

The first pass classified these by grepping the current theme for each option
name. That is wrong in a way worth recording, because it looks convincing:
**a module the theme has removed leaves options behind that no longer appear
anywhere in the tree**, so its leftovers read exactly like a stranger's data.

Two were misclassified as plugin options and would have survived a purge meant
to remove them — and one of them was written into a test as a thing that *must*
survive:

- `sfx_company_logo_options` — created by the theme's CompanyLogo module,
  removed in `46d83d0`
- `sfx_contact_infos_options` — created by ContactInfos before `8ff1193`
  rebuilt it onto a custom post type

Git history is the ownership signal, not the working tree. Both are on the list
now.

Two more are on nobody's books — `sfx_mailcatch` and
`sfx_custom_dashboard_svg_migration` appear in no plugin and nowhere in this
repo's history. They are deliberately **not** purged: unknown provenance is a
reason to leave something alone, not a reason to sweep it up.

### The list had drifted, in both directions

The hand-written list inherited from `uninstall.php` was wrong twice over:

1. **It named `thumbnail_size_w`** — a WordPress *core* option — directly beside
   a comment saying core options were being excluded to avoid side effects. They
   were not excluded. Running the purge would have reset the site's thumbnail
   width. Harmless only because the file never ran; making the purge reachable
   would have armed it.
2. **It had not been maintained** as modules were added.

Both are now guarded rather than trusted:

- **Case 1** asserts every entry is `sfx_`-prefixed or one of three named legacy
  keys. No core option can rejoin the list without failing a test.
- **Case 2** walks `inc/*/*.php` for `OPTION_NAME` constants and fails when one
  is missing from the purge, naming the file and the fix. It sees the module
  pattern; an option written as a bare literal is invisible to it, so the class
  docblock still asks for hand maintenance.

**Adding a feature means adding its options here.** Recorded in the class
docblock, in `.cursor/rules/feature-registry-structure.mdc` with the reasoning,
and in `.cursor/rules/wordpress-php-cursor-rules.mdc`.

## The transient sweep was the same mistake, one method further down

**Found by review, not by design.** The first version swept
`_transient_sfx_%` — a blanket prefix match, in the same class whose docblock
explains that the prefix does not prove ownership. It would have deleted SFX
Feedback's `sfx_feedback_shot_rl_<user>` (an abuse rate limit) and
`sfx_feedback_loops_form_<user>` (a half-filled form). The keys are built by
concatenation, so a grep for quoted literals did not surface them.

It now sweeps `DataPurge::TRANSIENT_PREFIXES`, each traced to a `set_transient()`
call inside `inc/`. Two details that were wrong and are now right:

- `_` is a **single-character wildcard** in SQL `LIKE`. Unescaped,
  `sfx_contact_info_` also matches `sfx-contact-infoX`. Every prefix goes
  through `esc_like()`.
- `gh_block_` was swept for a GitHub-updater cache that **nothing in the theme
  or the plugins produces**. Removed.

The direct DELETE goes round `get_option()`, so WordPress' cached copy of the
autoloaded set would keep serving rows that no longer exist. `wp_cache_delete('alloptions', 'options')`
drops that one entry. Flushing the whole object cache would evict every other
plugin's data to fix a problem of ours.

Known limitation, accepted: with a persistent object cache a transient can live
only in the cache and have no row to delete, so it survives until it expires.
They are caches with an expiry; the options and post meta that matter use the
proper APIs.

## `uninstall.php` is deleted

**Also a review finding.** Keeping it meant keeping code that, with the opt-in
switch gone, called `DataPurge::run()` behind no gate but `ABSPATH` — and that
resolved its own dependencies through `get_stylesheet_directory()`, which names
the **active** theme, not the inactive one being deleted. Dead code that would
delete everything if ever resurrected is a liability, not insurance. The file
is gone; the button is the path.

`TextSnippetsRemoval::purge_legacy_data()` therefore loses its only caller,
which it never really had. It is not folded into `DataPurge`: it deletes posts
and taxonomy terms, and this purge is settings-only. Exposing it is a separate
decision.

## Scope: this site only

Options, post meta and the transient rows all belong to the current blog's
tables, so on multisite this clears the site it was run on and no other. Stated
in the UI ("on this site only") and in `DataPurge::run()`'s docblock rather than
left to be discovered. A network-wide purge would need super-admin
authorisation and a site loop, which is a different feature.

## Reporting

`DataPurge::run()` returns what it actually removed — options, meta keys,
transient rows — and the notice prints those numbers. The first version
redirected with a bare flag and any request carrying it claimed success, which
for an irreversible and possibly partial operation is the wrong default.

## The double confirmation

Two deliberate acts, and only one of them can be trusted.

1. Type `sfx-bricks-child` into a field. The phrase is printed on the page —
   the point is not secrecy, it is that sixteen characters cannot be typed by
   reflex the way a button is clicked.
2. Press the button.

**The server checks the typed phrase before deleting anything**
(`DataPurge::confirmed()`). Whitespace around it is forgiven — a stray space is
a typing accident, not a change of intent. Case is not: the field shows the
exact phrase.

The browser-side enabling is **convenience only**. The markup ships an enabled
button and JavaScript disables it on load, so a JS-off admin is not locked out
of the feature, and a request built by hand simply meets the server check. A
guard that lives only in the browser is not a guard.

### The gate chain

`AdminPage::handle_purge()`, in order:

1. `check_admin_referer('sfx_purge_theme_data')` — CSRF
2. `AccessControl::die_if_unauthorized_theme()` — the theme's own gate
3. `current_user_can('manage_options')` — belt and braces, because this destroys
   data
4. `DataPurge::confirmed()` on the typed phrase

Then delete, then redirect with a notice naming what happened. No undo, and none
is offered: an undo for this would be a larger feature than the button.

## Verification

Twenty suites green.

`tests/data-purge-test.php` covers the list and the purge. `tests/data-purge-handler-test.php`
covers the gate chain and nothing else: each case removes one gate's
precondition and asserts the handler stopped there **and deleted nothing**, with
a happy-path baseline so that a handler which always died would not pass.

Mutations tried, each caught by its own assertion: a case-insensitive
confirmation, a substring confirmation, the meta deletion removed, the transient
deletion removed, a declared option dropped from the list (Case 2),
`thumbnail_size_w` smuggled in (Case 1), and each of the four handler gates
deleted in turn.

One caution learned here: the first mutation run reported two gates as
uncovered. The mutations had simply failed to match the source. Assert that the
mutation applied before believing a "not caught" result.

Exercised end to end on the development install, with every affected row backed
up first and restored afterwards:

| | |
|---|---|
| Wrong phrase, button forced open in the browser | Server refused; 23 options and 6 meta rows untouched |
| Correct phrase typed | Button enabled itself |
| Executed | Options 23 → 5 (five re-created at defaults by the theme itself), meta 6 → 0 |
| Plugin options | untouched |
| `thumbnail_size_w`, `blogname`, `siteurl` | untouched |
| Contact Infos and Social Media Accounts posts | untouched |

That run exercised the version with the blanket transient sweep; the narrowed
sweep is covered by tests rather than by a second live run.

## Reviewed twice, and what is still open

Gate B failed the first version; Gate A failed the first spec. Their findings
are folded in above. Three are knowingly **not** addressed:

- **No transaction, lock or purge generation.** A concurrent AJAX request, or
  another administrator saving a settings page they opened before the purge,
  can write a value back immediately afterwards. Reporting real counts is the
  mitigation; a generation token is a larger design than this button.
- **Ownership is a hand-maintained list, not a per-module manifest.** The
  better mechanism — each module declaring what it stores, consumed by the
  purge, import/export and the tests alike — would touch every module and is
  its own piece of work. Case 2 plus three written reminders is the interim.
- **The success notice renders for any request carrying `sfx-purged`.** Only
  the person already on the page can do that to themselves, so it misleads
  nobody but its author.

## Known and accepted

- The Danger Zone's CSS and JS are emitted inline, following the 90-line inline
  `<style>` block this file already carried. Moving the page's assets out is a
  separate change; adding a second mechanism for six rules would not be an
  improvement.
- `DataPurge::OPTION_NAMES` cannot be derived. Four modules write their option
  names as bare literals rather than constants, so Case 2 cannot see them. The
  list stays hand-maintained, which is why the requirement is recorded in three
  places.
