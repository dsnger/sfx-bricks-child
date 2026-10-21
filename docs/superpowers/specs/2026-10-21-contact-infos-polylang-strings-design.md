# Contact infos translatable through Polylang string translations — Design

Module `ContactInfos` only. Polylang facts below were read from the Polylang 3.9.1 source
(downloaded 2026-10-21); none of it has run against a live Polylang yet. No story cited —
unprofiled.

## Goal

A site running Polylang can show contact data differently per language — a phone number
written the way English readers expect it, a translated country name, English opening hours —
while each contact stays **one** `sfx_contact_info` post. The translations are entered where
Polylang keeps every other theme phrase: *Languages → Translations* ("Zeichenketten-
Übersetzungen").

Today the module carries Polylang and WPML code meant to do this
(`inc/ContactInfos/PostType.php` `register_multilingual_support()` and the two
`register_*_strings()` methods). It has never worked:

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

D7 also named "register the post types with `pll_get_post_types`" and "the language in the
cache keys". Both belonged to the one-post-per-language route that D8 replaced: the post
types stay untranslated in Polylang's sense, and no cache holds translated text (see
Caching), so neither is needed.

## Behaviour

### Registration (admin side)

When Polylang is active (`pll_register_string()` exists), on every wp-admin request the
module registers, through Polylang's public `pll_register_string()`, every **non-empty**
value of every field in `FieldRegistry::get_fields()` of every **published** contact.
"Non-empty" is Polylang's own test: `PLL_Admin_Strings::register_string()` skips any falsy
value, so the string `"0"` is never offered for translation either.

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

Without Polylang nothing is registered and no Polylang function is called.

### Output (frontend)

Every place that prints a contact field value passes the stored value through **one**
translation step: `pll__()` when it exists, the value unchanged otherwise. `pll__()` is
`__( $string, 'pll_string' )` (Polylang `src/api.php`), i.e. translation into the current
language by original text; a string with no translation comes back unchanged.

Places that must go through it:

- `SC_ContactInfos::get_field_value()` — already calls the helper today; this is the path
  for the `[contact_info]` shortcode, the `{contact_info:…}` Bricks tags (which render
  through the shortcode) and `@format:tel` (which normalises the value after it).
- The composed address in `SC_ContactInfos::render_address_field()` — when `address` is empty
  it builds the address from `street`, `zip`, `city`, `country` read straight from post meta,
  **bypassing translation today**. Each part goes through the same step.

The phone link follows the displayed value: `tel:` is derived from the translated number,
so display and link always agree.

Escaping is unchanged and happens at output on the translated value (`wp_kses_post` for
`address` and `opening`, `esc_html` / `esc_url` elsewhere) — a translation is admin input
like the original and gets the same treatment (invariant 3).

### Removed

`register_multilingual_support()`, `register_polylang_strings()`,
`register_wpml_strings()`, the `add_action('sfx_init_advanced_features', …)` line in
`PostType::init()` that wires them (the hook itself stays; the bootstrap fires it), and the
WPML branch in `get_translated_field()`. The `$default` parameter of
`get_translated_field()` goes if no caller needs it after the change (the plan decides).

### Caching

Unchanged. The shortcode caches the **stored** value per contact and field
(`sfx_contact_info_<id>_<field>`) and the contact ID per type; translation happens after the
cache read on every call. No cached value depends on the language, so no key needs it.

## Accepted limits

- **Translation follows the original text (D8).** Changing the German value orphans its
  translation; the German value shows until it is re-translated. Polylang's "Clean strings
  translation database" removes orphaned entries. Until then they stay, so changing a value
  back to an earlier text — or to any text that already has a translation — shows that
  translation again at once.
- **Same text, same translation.** Two fields or two contacts with identical values share one
  entry on the Translations screen (Polylang keys its list by `md5` of the text), and that
  entry carries the name, group and multiline flag of **whichever registration came last** —
  another plugin's or a widget's included. A contact value identical to a string registered
  elsewhere can therefore show under another group, or as a one-line field.
- **A Translations screen left open while a contact value changes fails on save.** Polylang
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
- Admin list columns keep calling the same helper; what they show in wp-admin is whatever
  Polylang's admin language yields and is not specified here.

## Non-goals

WPML support; social media accounts; one post per language; automatic translation; any
change for sites running neither Polylang nor WPML.

Removing the WPML branch (D7) is a change for a WPML site in one case: if a string named
`<field>_<id>` in context `sfx_contact_info` was ever registered in WPML from outside this
theme, its translation is no longer used. The theme itself never registered such a string.

## Acceptance

1. **Without Polylang and without WPML**, shortcode and Bricks-tag output for every field is byte-identical to
   today's, composed address included.
2. **With Polylang** (functions stubbed in the PHP test): on an admin request every non-empty
   field of every published contact is registered with the group, a name naming field and
   contact ID, and `multiline` true exactly for `address` and `opening`; empty values, drafts
   and non-contact posts are not registered; a contact title containing markup does not appear
   in any registered name or group.
3. **With Polylang**, a translated value is printed for: a plain field, `phone` with link
   (link built from the translated number), `@format:tel`, `address`, and the composed
   address built from translated parts. An untranslated value prints unchanged.
4. The removed methods and hook are gone; nothing references `icl_t` or
   `wpml_register_single_string` in `inc/ContactInfos/`.
5. **Live, on the local DDEV site with Polylang 3.9.1 and two languages:** a contact's phone
   and country appear on *Languages → Translations* in the contact group; after entering
   English values, an English page shows them (phone link included) and the German page the
   originals; after changing the German phone to a number that has never been translated, the
   English page shows the new German number.
6. `./quality.sh` green.
