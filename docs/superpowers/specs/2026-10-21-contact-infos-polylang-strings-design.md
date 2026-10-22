# Contact infos translatable through Polylang and WPML string translations — Design

Module `ContactInfos` only. Polylang facts below were read from the Polylang 3.9.1 source
(downloaded 2026-10-21) and confirmed live on the DDEV site (PR #75). WPML facts come from
WPML's documentation only (amendment of 2026-10-21, below); no WPML has run against this code.
No story cited — unprofiled.

## Goal

A site running Polylang can show contact data differently per language — a phone number
written the way English readers expect it, a translated country name, English opening hours —
while each contact stays **one** `sfx_contact_info` post. The translations are entered where
Polylang keeps every other theme phrase: *Languages → Translations* ("Zeichenketten-
Übersetzungen").

*Problem statement as of `main` before this change (historical; the implementation commit
`337065e` already replaced this code):* the module carried Polylang and WPML code meant to do
this (`inc/ContactInfos/PostType.php` `register_multilingual_support()` and the two
`register_*_strings()` methods). It had never worked:

- The strings are registered from a `save_post_*` callback that is itself added on
  `add_meta_boxes`. `add_meta_boxes` runs when the edit screen is displayed, `save_post` on
  the separate request that saves the form, so the callback is never attached on a request
  where it would fire. *(Inference from the WordPress request flow; not observed.)*
- Polylang keeps registered strings in a static array for the current request only
  (`PLL_Admin_Strings::$strings`, `src/admin/admin-strings.php`), so even a registration on
  save would not appear on the Translations screen, which is a different request.
- WPML registers under context `Contact Information`, name `contact_info_<field>_<id>`, and
  reads with `icl_t('sfx_contact_info', '<field>_<id>', …)` — names that never match.

## Decisions settled with Daniel (2026-10-21)

- **D7:** make the contact data translatable for Polylang, and remove the dead
  field-translation code, WPML included.
- **D8:** **string translation, not one post per language.** One contact post; its field
  values are offered on Polylang's Translations screen. Accepted consequence: Polylang
  matches a translation **by the original text**, so when someone changes the German value
  the old translation no longer applies and the new German value shows in every language
  until it is translated again.
- **Social media accounts are out of scope.** Their links are the same in every language.
- **D10 (amends D7):** WPML is supported too, now and not "when a site needs it" — Polylang
  and WPML are the two standard translation plugins, and support added later comes too late.
- **D12:** there is no free WPML (wordpress.org closed the plugin in 2017; String Translation
  was never there) and no licence, so the WPML path is built from WPML's documentation and
  checked against stubs only. Better in place and fixed later than absent.
- **D13 (2026-10-22):** no `wpml-config.xml`. WPML's default for a theme's post type
  ("Not translatable") is the setting needed; making the contact type translatable is
  unsupported.

D7 also named "register the post types with `pll_get_post_types`" and "the language in the
cache keys". Both belonged to the one-post-per-language route that D8 replaced: the post
types stay untranslated in Polylang's sense, and no cache holds translated text (see
Caching), so neither is needed.

## Behaviour

### Registration (admin side)

When Polylang is active (`pll_register_string()` exists), on every wp-admin request the
module registers, through Polylang's public `pll_register_string()`, every **non-empty**
value of every field in `FieldRegistry::get_fields()` of every **published** contact.
"Non-empty" means what the output step translates: `get_translated_field()` returns `''` for
any `empty()` value, so `''` and `"0"` are not registered with either plugin (Polylang would
skip `"0"` itself anyway — `PLL_Admin_Strings::register_string()` drops falsy values).

- **Group (Polylang "context"):** one fixed group for all contacts, translated with the
  `sfxtheme` text domain (e.g. "Contact Information"), so an editor can filter the
  Translations screen to it.
- **Name:** field label from `FieldRegistry` plus the contact's post ID, e.g. `Phone #12`.
  It is a label only; Polylang does not look translations up by it. **The contact title is
  deliberately not part of it:** Polylang prints name and group verbatim
  (`PLL_Table_String::column_default()`, `src/settings/table-string.php`), and a title is
  editable by editors (`edit_others_posts`), so a title carrying markup would run on an
  administrator's Translations screen. Name and group are built only from the theme's own
  translated labels and an integer. The string itself is safe there: Polylang prints it
  through `format_to_edit()`.
- **Multiline:** `true` for the two editor fields `address` and `opening`, `false` otherwise.
- Why every admin request and not only Polylang's screen: Polylang's documented pattern is to
  register on each admin load, and its save handler reads the same registered list — a
  string missing from it on the save request has its translation dropped when "Clean strings
  translation database" is ticked. The cost is one query for the published contacts' IDs
  and their meta per admin request; contact counts are small (typically one to a handful).

Without Polylang no Polylang function is called; the WPML section below says what happens
instead.

### Output (frontend)

Every place that prints a contact field value passes the stored value through **one**
translation step: `pll__()` when it exists, otherwise the WPML filter of the WPML section
(which returns the value unchanged when nothing listens). `pll__()` is
`__( $string, 'pll_string' )` (Polylang `src/api.php`), i.e. translation into the current
language by original text; a string with no translation comes back unchanged.

Places that must go through it:

- `SC_ContactInfos::get_field_value()` — already calls the helper today; this is the path
  for the `[contact_info]` shortcode, the `{contact_info:…}` Bricks tags (which render
  through the shortcode) and `@format:tel` (which normalises the value after it).
- The composed address in `SC_ContactInfos::render_address_field()` — when `address` is empty
  it builds the address from `street`, `zip`, `city`, `country` read straight from post meta,
  which bypassed translation before `337065e`. Each part goes through the same step.

The phone link follows the displayed value: `tel:` is derived from the translated number,
so display and link always agree.

Escaping is unchanged and happens at output on the translated value (`wp_kses_post` for
`address` and `opening`, `esc_html` / `esc_url` elsewhere) — a translation is admin input
like the original and gets the same treatment (invariant 3).

### Removed

`register_multilingual_support()`, `register_polylang_strings()`,
`register_wpml_strings()`, the `add_action('sfx_init_advanced_features', …)` line in
`PostType::init()` that wires them (the hook itself stays; the bootstrap fires it), and the
`icl_t()` call in `get_translated_field()` (it never matched; the WPML section below replaces it). The `$default` parameter of
`get_translated_field()` goes if no caller needs it after the change (the plan decides).

### WPML (amendment, D10/D12)

**Supported configuration:** WPML **with its String Translation module** active — both
hooks below require it (WPML's hook pages say so). Translations are entered under
*WPML → String Translation*. With WPML core alone nothing listens, and contacts show their
originals in every language.

The contact post type must stay **Not translatable** in WPML — the one-post decision (D8)
holds for WPML too. If an administrator made `sfx_contact_info` translatable, WPML would filter
the contact queries by language and the type lookup (`main`/`branch`) could find no contact, or
a cached one from another language, before any string translation is reached. That is WPML's
own default: "Custom post types added by themes and plugins default to Not translatable"
(wpml.org/documentation/translating-your-contents/posts-and-custom-post-types). The theme
relies on that default and ships no `wpml-config.xml` (owner decision D13, 2026-10-22): the
canonical release and test-instance scripts admit only the entries of the SFX release standard
(`THEME_ALLOWED`, STD-REL-06), which has no place for it, and the scripts are not hand-edited
(invariant 6). Setting `sfx_contact_info` to translatable in WPML's settings is **unsupported**.

**When:** whenever Polylang is absent (`pll_register_string()` does not exist). The theme does
not detect WPML: it calls WPML's documented hooks, and without a listener `do_action()` does
nothing and `apply_filters()` returns the value unchanged — so a site where nothing listens to
these hooks is unaffected by construction. Any other plugin that listens to them (a WPML
compatibility layer) receives the same calls, deliberately. (WPML documents no version constant to test for; `WPML_ST_VERSION`
appears in no WPML documentation.) Polylang re-implements the same hooks
(`src/modules/wpml/wpml-api.php`); taking the Polylang path first whenever Polylang is present
keeps a Polylang site from registering every field twice.

**Registration** — same trigger, same contacts, same fields, same value read as the Polylang
path, and only when something listens (`has_action('wpml_register_single_string')`), so a
site without WPML runs no contact query: `do_action('wpml_register_single_string', $context,
$name, $value, false, $source_language)` on every admin load, which is what WPML's documentation asks of themes
("register all user strings every time the admin screen … is loaded"; WPML acts only on new or
changed values).

- **Context:** the fixed, untranslated identifier `sfx-bricks-child contact infos`. WPML
  identifies a string by context **and name**, so neither may depend on the admin user's
  language — a translated context or label would register a second string per admin language
  and orphan the translations of the first. This is an identifier, not interface copy, which is
  why it carries no text domain (invariant 4 covers translatable interface strings).
- **Name:** `<field key> #<post ID>`, e.g. `phone #12` — field key from `FieldRegistry`,
  never the post title (same reason as for Polylang).
- **Source language:** `apply_filters('wpml_default_language', null)` — the site's default
  language, in which contacts are entered. The originals are assumed to stay in the **current**
  default language: changing WPML's default language without re-entering the contacts in the
  new one sends the old text under the new language code (accepted limit below). Without it WPML may file a German original as
  English; WPML documents exactly this failure and this fix for another plugin
  ([erratum](https://wpml.org/errata/complianz-strings-are-registered-under-the-wrong-source-language/)).
  The fourth argument `false` is WPML's `$allow_empty_value`; empty values are never sent.

**Output** — the same single translation step: when Polylang is absent,
`apply_filters('wpml_translate_single_string', $value, $context, $name)`, which per WPML's
documentation returns the translation in the current language, else the original. Because WPML
looks up by context and name, the step needs the field and the contact ID —
`get_translated_field()` already receives both, and the composed address passes them per part.

**Unknown, accepted (D12):** what WPML prints when the original changes after a translation
exists — the old translation or the new original. Its documentation does not say; forum reports
mention a "needs update" status. Whatever WPML does there is WPML's behaviour, not specified here.

### Caching

Unchanged. The shortcode caches the **stored** value per contact and field
(`sfx_contact_info_<id>_<field>`) and the contact ID per type; translation happens after the
cache read on every call. No cached value depends on the language, so no key needs it.

## Accepted limits

- **Polylang: translation follows the original text (D8).** Changing the German value orphans its
  translation; the German value shows until it is re-translated. Polylang's "Clean strings
  translation database" removes orphaned entries. Until then they stay, so changing a value
  back to an earlier text — or to any text that already has a translation — shows that
  translation again at once.
- **Polylang: same text, same translation.** Two fields or two contacts with identical values share one
  entry on the Translations screen (Polylang keys its list by `md5` of the text), and that
  entry carries the name, group and multiline flag of **whichever registration came last** —
  another plugin's or a widget's included. A contact value identical to a string registered
  elsewhere can therefore show under another group, or as a one-line field.
- **Polylang: a Translations screen left open while a contact value changes fails on save.** Polylang
  saves by the row keys of the form it rendered and looks each up in the list registered on
  the save request; a key whose text is no longer registered is not handled
  (`PLL_Table_String::save_translations()`, by source reading) and the save errors, possibly
  after some languages were already written. This holds for every registered string that
  changes, not only ours. Remedy: reload the screen and save again.
- **Only who may open Polylang's Translations screen can translate** — `manage_options` by
  default (Polylang `src/admin/admin-base.php`). Editors who maintain contacts cannot.
- **A value cannot exist in one language only.** An empty German field registers nothing.
- **Exports** (`ImportExport`) carry the contact posts, not Polylang's translations; those
  live in Polylang's own storage.
- **WPML: the default language is the originals' language.** After a change of WPML's default
  language, contacts must be re-entered in it; until then their originals are registered under
  the wrong source language.
- **WPML: records outlive the contacts.** A cleared field, an unpublished or deleted contact,
  or a purge of the theme's data sends WPML no removal — the theme only stops registering.
  WPML keeps such strings and their translations until someone deletes them under
  *WPML → String Translation*.
- Admin list columns keep calling the same helper; what they show in wp-admin is whatever
  Polylang's admin language yields and is not specified here.

## Non-goals

Social media accounts; one post per language; automatic translation; any change for sites
where Polylang is absent and nothing listens to the two WPML hooks.

Replacing the `icl_t()` lookup is a change for a WPML site in one case: if a string named
`<field>_<id>` in context `sfx_contact_info` was ever registered in WPML from outside this
theme, its translation is no longer used. The theme itself never registered such a string.

**Scope of the amendment beyond the WPML path:** the Polylang registration guard changes from
"skip `''`" to "skip `empty()`", so `"0"` no longer reaches Polylang either (it was skipped by
Polylang itself, so nothing visible changes); the existing test expectation that registered
`ZIP Code #410` with `"0"` changes with it.

## Acceptance

1. **Without Polylang and without any listener on the WPML hooks**, shortcode and Bricks-tag
   output for every field is byte-identical to `main` before this change (and to `337065e`),
   composed address included.
2. **With Polylang** (functions stubbed in the PHP test): on an admin request every non-empty
   field of every published contact is registered with the group, a name naming field and
   contact ID, and `multiline` true exactly for `address` and `opening`; empty values (`''`,
   `"0"`), drafts
   and non-contact posts are not registered; a contact title containing markup does not appear
   in any registered name or group.
3. **With Polylang**, a translated value is printed for: a plain field, `phone` with link
   (link built from the translated number), `@format:tel`, `address`, and the composed
   address built from translated parts. An untranslated value prints unchanged.
4. The three removed methods and `PostType::init()`'s attachment to `sfx_init_advanced_features`
   are gone (the action itself and `Controller`'s use of it stay); nothing in
   `inc/ContactInfos/` references `icl_t`.
5. **Live, on the local DDEV site with Polylang 3.9.1 and two languages:** a contact's phone
   and country appear on *Languages → Translations* in the contact group; after entering
   English values, an English page shows them (phone link included) and the German page the
   originals; after changing the German phone to a number that has never been translated, the
   English page shows the new German number.
6. `./quality.sh` green.
7. **With WPML** (stubs following WPML's documented signatures, Polylang absent): with a
   listener on `wpml_register_single_string`, every non-empty field of every published contact
   (neither `''` nor `"0"`) is registered with context `sfx-bricks-child contact infos`, name
   `<field> #<id>`, `false` and the language `wpml_default_language` returns; the shortcode, the
   Bricks tag, `@format:tel`, the phone link and the composed address print the value the
   `wpml_translate_single_string` filter returns for that context and name. Without a
   listener, no contact query runs. With Polylang present, only the Polylang path runs and no
   WPML hook is called.
8. The theme ships no `wpml-config.xml`, and `SHIPPED` in `bin/plugin.conf` is unchanged by
   this work (D13).
