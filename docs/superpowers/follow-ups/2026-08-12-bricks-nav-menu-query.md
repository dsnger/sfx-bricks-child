# Bricks Nav Menu Query — Deferred Findings

**Branch:** `feat/bricks-nav-menu-query`
**Date:** 2026-08-12
**Spec:** [`../specs/2026-08-10-bricks-nav-menu-query-design.md`](../specs/2026-08-10-bricks-nav-menu-query-design.md)
**Plan:** [`../plans/2026-08-11-bricks-nav-menu-query.md`](../plans/2026-08-11-bricks-nav-menu-query.md)

Findings raised during eleven task-scoped reviews, a whole-branch review and manual
end-to-end verification, which were triaged as non-blocking and consciously deferred.
They are recorded here rather than left in a scratch ledger so the decision to defer
survives the branch.

**Count:** eighteen outstanding. Earlier summaries said "nine", then "eleven" —
those figures predated later reviews. The list below is the authoritative one.

Everything here is non-blocking by triage, not by neglect. Each entry states the
concrete risk so a future reader can re-triage on evidence rather than re-deriving it.

---

## 1. Overview-suite "default inactive" assertions have weak gating

**Affected:** `tests/theme-settings-overview-provider-test.php`, the
`assert_status($data, 'enable_*', 'inactive', …)` block.

**Risk:** `SFXBricksChildTheme::is_general_option_enabled()` returns `false` both when
a field's default is `0` *and* when the field is absent from the settings stub
entirely (`inc/SFXBricksChildTheme.php`, final `return false`). So a "default
inactive" assertion passes even if the stub never learned about the field. It cannot
distinguish "correctly defaults off" from "unknown to the stub".

**Why non-blocking:** the assertions still gate the thing that actually matters — the
`OverviewProvider` module row. `get_item_status()` returns `null` for an unknown id,
so they fail loudly if the row is missing. And once the stub *does* carry the field,
a wrong default (`1` instead of `0`) is caught.

**Follow-up:** fix for the whole block at once, not for `enable_nav_menu_query` alone
— the weakness is shared by every `enable_*` row and predates this branch. Patching
one row makes the suite less uniform, not more honest.

---

## 2. Plan's Task 1 Step 7 predicts a RED that cannot happen

**Affected:** `docs/superpowers/plans/2026-08-11-bricks-nav-menu-query.md`, Task 1
Step 7 ("Run the overview test to verify it fails").

**Risk:** the plan tells an implementer to expect two failures before the stub gains
the field. They do not occur, for the reason in item 1. Anyone re-running the plan
loses time proving the plan wrong, or — worse — "fixes" working code to produce the
predicted failure.

**Why non-blocking:** documentation-only; the shipped code and tests are correct.

**Follow-up:** correct the step to expect a pass, and state that the assertions gate
Step 3's `OverviewProvider` entry rather than Step 8's stub field.

---

## 3. Unused loop variable in `parent_options()`

**Affected:** `inc/NavMenuQuery/MenuOptions.php:111` —
`foreach ($titles as $id => $title)`; `$title` is never read, because
`path_label()` re-derives it from `$titles[$id]`.

**Risk:** none functionally. A reader may briefly assume `$title` is used and look
for where.

**Why non-blocking:** cosmetic dead variable, no behaviour attached.

**Follow-up:** `foreach (array_keys($titles) as $id)` next time the file is touched
for another reason. Not worth a commit of its own.

---

## 4. No test for a parent id pointing at an item absent from the menu

**Affected:** `inc/NavMenuQuery/MenuOptions.php` `parent_options()` /
`path_label()`; no corresponding case in `tests/nav-menu-query-test.php`.

**Risk:** an orphaned `menu_item_parent` (pointing at a deleted item) is untested.
Traced by the whole-branch reviewer and confirmed correct by construction: stray
`$counts[$parent]` entries are never read — only `$counts[$id]` for `$id ∈ $titles` —
and `path_label()` terminates on `!isset($titles[$current])`, yielding the orphan's
bare title.

**Why non-blocking:** correct by construction, and the shared traversal is already
covered by the cyclic-parent case (3k), which exercises the same guard.

**Follow-up:** add a case if the traversal is ever refactored. Until then the cyclic
case protects the same code path.

---

## 5. `CONTROL_KEYS` lacks the docblock its siblings carry

**Affected:** `inc/NavMenuQuery/QueryType.php:16`.

**Risk:** none. Every other constant in this feature (`OBJECT_TYPE` one line above,
`RELATIVE_PARENT`, `PREFIX`, `KEYS`) carries a one-line `/** … */`.

**Why non-blocking:** style consistency only.

**Follow-up:** add the line when the file is next edited.

---

## 6. `$done = true` is set before the registration loop

**Affected:** `inc/NavMenuQuery/QueryType.php:53-59`, the once-guard in
`register_element_controls()`.

**Risk:** if the loop body ever became fallible, a partial failure would still leave
the guard armed, so a retry would register nothing.

**Why non-blocking:** `add_filter()` cannot throw, so the loop is not fallible today.
The whole-branch reviewer argued the current order is in fact the *safer* one — a
throw mid-loop must not re-arm the guard and cause duplicate registrations on a
second call. **Recommended disposition: won't fix.**

**Follow-up:** none. Revisit only if the loop gains fallible work, and weigh
duplicate-registration risk against partial-registration risk at that point.

---

## 7. Ungrouped-path assertion covers only one of the three controls

**Affected:** `tests/nav-menu-query-test.php`, Case 5i — asserts group-absence for
`sfxNavMenuLocation` only; 5g/5h check the other two for presence, not group-absence.

**Risk:** a regression that stamped a group onto only `sfxNavMenuId` or
`sfxNavMenuParent` would slip through.

**Why non-blocking:** all three keys go through the same `CONTROL_KEYS` loop inside a
single `if ($group !== null)` guard, so divergence between them is not reachable
without restructuring that loop. The grouped path (5k-5m) *does* assert all three.

**Follow-up:** extend 5i to all three keys — a two-line change, worth doing whenever
Case 5 is next edited.

---

## 8. `if (!$enclosing)` also treats an empty-string query id as "no loop"

**Affected:** `inc/NavMenuQuery/QueryType.php:201`, in `resolve_parent()`.

**Risk:** `Bricks\Query::is_any_looping()` returns `false` or a query id. The falsy
check would also swallow `''` or `'0'` if either were ever a legitimate id, silently
returning an empty loop instead of resolving the relative parent.

**Why non-blocking:** Bricks query ids are element ids, which are non-empty
alphanumeric strings; `'0'` is not reachable. Verified against Bricks 2.3.9,
re-verified against 2.3.10 after the mid-branch update — `$element_id =
$element['id'] ?? ''` (`query.php:75`) is unchanged.

**Follow-up:** tighten to `if ($enclosing === false)` if Bricks ever changes the
return contract. Low value today; the current form is idiomatic.

---

## 9. `target` and `rel` fixtures are escape-neutral

**Affected:** `tests/nav-menu-query-test.php`, the Case 9 fixture — `target` is
`_blank` and `rel` is `noopener`, neither containing a character `esc_html()`
changes.

**Risk:** the `esc_html` arm of `render_content()`'s `match` is proven for `title`,
`description` and `classes`, but not for `target` and `rel`. A regression moving
just those two to the raw arm would not fail any test.

**Why non-blocking:** all five share one `match` arm (`default`), so moving two of
them out requires deliberately naming them — and `classes` (Case 9j, `promo&sale`)
already fails if that arm is tampered with. Neither value is user-authored free text
in the way `title`/`description`/`classes` are.

**Follow-up:** give one of the two an `&`-bearing fixture value if Case 9 is revisited.

---

## 10. New `.po` entries were appended after the trailing obsolete block

**Affected:** `languages/de_DE.po` — the new entries sit after the trailing `#~`
obsolete-entry block (272 `#~` lines).

**Risk:** none functionally; gettext ignores ordering. `msgfmt --check` passes and
the compiled `.mo` is correct.

**Why non-blocking:** cosmetic file layout. Obsolete entries are conventionally last,
so live entries following them is unusual to read but harmless.

**Follow-up:** self-heals on the next `msgmerge` regeneration. No manual action.

---

## 11. Reused translation entries lack a `#:` reference for the new call site

**Affected:** `languages/de_DE.po` — the pre-existing `Title`, `URL` and `ID` entries
were reused rather than duplicated (correctly; a duplicate `msgid` breaks `msgfmt`),
but `inc/NavMenuQuery/MenuItemTags.php` was not added to their `#:` reference
comments.

**Risk:** none functionally; gettext resolves by `msgid`, not by reference comment.
A translator auditing where a string is used would see an incomplete list.

**Why non-blocking:** reference comments are metadata, regenerated by tooling.

**Follow-up:** self-heals on the next `xgettext`/`msgmerge` pass. No manual action.

---

## 12. `classes` lost its postmeta fallback in the cache split

**Affected:** `inc/NavMenuQuery/MenuItemTags.php:120-121`, the `classes` case in
`value()`.

**Risk:** Before `400f256`, `classes` came from `wp_setup_nav_menu_item(clone
$item)`, which fills `->classes` from postmeta (`_menu_item_classes`) when the
property is absent (`$menu_item->classes = ! isset( $menu_item->classes ) ? (array)
get_post_meta( $menu_item->ID, '_menu_item_classes', true ) : $menu_item->classes;`
— `wp-includes/nav-menu.php:981`). The cache split moved `classes` out of the
`wp_setup_nav_menu_item()`-derived cache and into a branch that reads
`$item->classes` straight off whatever `item_from_context()` resolved. An
*undecorated* `nav_menu_item` — one that never passed through
`_wp_menu_item_classes_by_context()` or `wp_setup_nav_menu_item()` — would yield
`''` where the old code returned the editor's CSS classes.

**Why non-blocking:** unreachable in practice. Bricks never hands
`MenuItemTags` an undecorated `nav_menu_item` as `$post`:
- The "other render providers" branch of `Query::run()` (`query.php:1944-1958`)
  sets `$this->loop_object` to our own `QueryType::run()` output — already
  decorated by `_wp_menu_item_classes_by_context()` (`QueryType.php:168`) — and
  never calls `setup_postdata()`; each iteration finishes with
  `self::parse_dynamic_data( $part, get_the_ID() )`, never substituting a bare
  reconstructed post for the loop object mid-iteration.
- `Providers::render_content()`'s classification guard only swaps `$post` for
  `get_post()` when the loop object type is *not* `post`
  (`providers.php:770-772`) — and a `nav_menu_item` *is* a `WP_Post`, so that
  swap never fires either. This is the same mechanism Finding 1 of the spec
  correction documents, from the other side.

**Follow-up:** none needed on the evidence above. If `item_from_context()`'s
`$post`-fallback branch is ever reached by a genuinely undecorated
`nav_menu_item` (e.g. a third-party integration passing one directly, outside
any Bricks loop), reinstate the postmeta fallback in `value()`'s `classes`
case.

---

## 13. Fifth stub blind spot: `Bricks\Query::is_looping()` is modelled as a flat bool

**Affected:** `tests/support/nav-menu-query-bricks-stubs.php`, the
`Bricks\Query::is_looping()` / `is_any_looping()` stub.

**Risk:** the stub returns a single settable flag regardless of the query
stack; the real `is_looping()` consults the innermost query only
(`Query::is_looping()` calls `get_query_object( $query_id )`, `query.php:2273-2288`,
which resolves via `get_query_object()` returning `end( $bricks_loop_query )` —
the innermost entry — at `query.php:2044-2052`). A scenario the stub cannot
express: a *non*-menu-item loop nested inside a menu-item loop. There,
`item_from_context()` sees the inner loop's non-menu object (not the outer
menu item), fails the `post_type === 'nav_menu_item'` check on
`Query::get_loop_object()`, falls through to `$post` — the enclosing page, not
either loop's item — and returns `null`. Tags inside that inner subtree do not
resolve inline.

**Why non-blocking:** largely self-healing for text content — Bricks re-parses
the outer iteration once the inner query is destroyed, so a `render_content()`
call made after the inner loop unwinds sees the outer item again. It is
**not** self-healing for `render_tag` (image/background sources), which
resolves inline during the inner loop and is stuck with the unresolved tag for
that render.

**Follow-up:** either model a real query stack (a list, not a bool) in the
stub, with one nested-non-menu-loop regression case, or accept this as a
documented limitation of the harness. The nesting itself is already
out-of-scope per the spec ("Depth beyond one level per loop" — Out of scope);
this is about what the *test double* can prove, not about supporting the
scenario.

---

## 14. `current_item_parent` is not exposed

**Affected:** `inc/NavMenuQuery/MenuItemTags.php` — the nine `{sfx_menu_item_*}`
tags have no `current_item_parent` / "is parent of current page" tag.

**Risk:** `_wp_menu_item_classes_by_context()` sets `->current_item_parent` and
the `current-menu-parent` class (`wp-includes/nav-menu-template.php:585,587`)
alongside the two states this feature does expose (`->current`,
`->current_item_ancestor`). It runs on every request via `QueryType::run()`
(`_wp_menu_item_classes_by_context($items)`), so the value exists on the item —
it is simply never surfaced as its own tag.

**Why non-blocking:** reachable via `{sfx_menu_item_classes}`, which includes
`current-menu-parent` when set — a gap in convenience, not in information. An
editor can still drive a condition off the class string with a `contains`
operator; it is just less direct than `{sfx_menu_item_is_active}` /
`{sfx_menu_item_is_ancestor}`.

**Follow-up:** the CHANGELOG entry frames this feature as building navigation
"instead of `wp_nav_menu()` markup," which reads as fuller state parity with
the walker output than exists. Either soften that wording, or add a tenth
`is_parent` tag mirroring `is_active`/`is_ancestor` if a condition author
needs it without string-matching classes.

---

## 15. `{sfx_menu_item_classes}` omits `menu-item-{ID}`

**Affected:** `inc/NavMenuQuery/MenuItemTags.php`, the `classes` tag.

**Risk:** `menu-item-{ID}` is added by `Walker_Nav_Menu::start_el()`
(`$classes[] = 'menu-item-' . $menu_item->ID;` —
`wp-includes/class-walker-nav-menu.php:170`), not by
`_wp_menu_item_classes_by_context()`. Because this feature drives markup from
the query loop rather than the walker, that class never lands on
`$item->classes` and never appears in `{sfx_menu_item_classes}`. Anyone
porting CSS selectors written against `wp_nav_menu()`'s markup — which does
carry `menu-item-{ID}` — will find them silently unmatched.

**Why non-blocking:** documented consequence of bypassing the walker, not a
defect; the ID is already available on its own as `{sfx_menu_item_id}` for
anyone who needs to target a specific item.

**Follow-up:** note the omission in the tag's builder-picker description if
migration confusion turns out to be common; otherwise accept as-is — the
walker's ID class is a `wp_nav_menu()` rendering convention, not part of the
menu item's stored data.

---

## 16. The parent select can offer items the frontend query drops

**Affected:** `inc/NavMenuQuery/MenuOptions.php` `ajax_parent_options()` /
`parent_options()`.

**Risk:** `wp_get_nav_menu_items()` filters invalid items (deleted/trashed
targets) only `if ( ! is_admin() )` (`wp-includes/nav-menu.php:749`, "Remove
invalid items only on front end."). `ajax_parent_options()` runs under
`admin-ajax.php`, where `is_admin()` is `true`, so an item pointing at a
trashed page appears in the parent select and inflates its parent's `(n)`
child count — while `QueryType::run()` calls the identical
`wp_get_nav_menu_items()` from the frontend, where `is_admin()` is `false`,
and that item is silently absent from the actual loop.

**Why non-blocking:** cosmetic. The editor can select a parent that
(temporarily) yields fewer items than its `(n)` count promised; nothing
renders incorrectly, and restoring or permanently deleting the target
resolves the mismatch either way.

**Follow-up:** none identified beyond documenting the discrepancy —
replicating WordPress' front-end invalid-item filter inside the AJAX handler
would mean reimplementing private core logic to fix a count that is off by a
small, self-correcting amount.

---

## 17. Option lists are rebuilt per element instance on the frontend, for three elements

**Affected:** `inc/NavMenuQuery/MenuOptions.php` `locations()` / `menus()`,
called from `QueryType::add_element_controls()`.

**Risk:** `Frontend::render_element()` calls `$element_instance->load()` per
element instance (`frontend.php:744`), which fires
`bricks/elements/{name}/controls` — and so our controls filter — on **every**
rendered instance of a loop-capable element, not once per request. Section,
Container, Block and Div gate their whole loop-builder control block on
`bricks_is_builder()` (`elements/container.php:89-93`, the token itself on 90), so `add_element_controls()`
never runs `MenuOptions::locations()`/`menus()` for those four outside the
builder. Slider (`elements/slider.php:214`) and Accordion
(`elements/accordion.php:67`) call `get_loop_builder_controls()`
unconditionally, and Map's query controls (`elements/map.php:250`) are
likewise ungated — so every frontend Slider, Accordion or Map instance rebuilds
both option lists, including a `get_terms()` call inside `menus()`, for
controls that are never displayed outside the builder.

**Why non-blocking:** bounded and small. `get_registered_nav_menus()` and
`wp_get_nav_menus()` are cheap, already-cached WP calls, and the waste scales
with the count of Slider/Accordion/Map instances on a page, not with menu
size or site content.

**Follow-up:** a static memo inside `MenuOptions::locations()`/`menus()` would
remove the repeated work cheaply; gating `add_element_controls()` itself on
`bricks_is_builder()` would remove it entirely but would need to preserve the
controls' visibility inside the builder for all seven elements.

---

## 18. AJAX capability is weaker than Bricks' own

**Affected:** `inc/NavMenuQuery/MenuOptions.php::ajax_parent_options()`.

**Risk:** uses `current_user_can('edit_posts')`; Bricks' own builder AJAX
endpoints gate on `Capabilities::current_user_can_use_builder()`, a narrower
check. A user who can edit posts but has been denied builder access could
still call this endpoint and enumerate menu structure.

**Why non-blocking:** the data is not sensitive, and the docblock on
`ajax_parent_options()` already reasons about the choice explicitly — menu
structure disclosure to any authenticated, `edit_posts`-capable user is a
deliberate, documented trade, not an oversight. Recorded here so the choice
stays visible and re-triageable rather than silently accepted a second time.

**Follow-up:** switch to `Capabilities::current_user_can_use_builder()` if
this endpoint's exposure is revisited for other reasons. Not worth a change
on its own.

---

## Separate: `render_content` shares the same registration-ordering shape

This is not one of the eleven. It is a latent architectural note about the *other*
dynamic-data filter, recorded because the analysis is easy to get backwards.

**The shape.** Bricks registers its own `render()` on
`bricks/dynamic_data/render_content` at priority 10, at theme-include time
(`bricks/includes/integrations/dynamic-data/providers.php:148`). Our callback is
added later at the same priority, so Bricks runs first — structurally identical to
the `render_tag` defect fixed on this branch.

**Why it is nonetheless not a live bug.** Our tags are not in `Providers::$tags`, so
they are absent from `$registered_tags`. That count (`$dd_tag_count`) governs only
*how many parser passes run* (`providers.php:396-402`). Two consequences:

- If the content contains **only** our tags, `$dd_tag_count` is `0`, the parse loop
  never executes, and Bricks returns the content untouched for us to substitute.
  This is the common case for a menu template.
- If the content **also** contains a tag Bricks knows, the loop runs, and inside each
  pass `preg_match_all` re-matches every `{...}` and calls `get_tag_value()` on it
  (`providers.php:411-418`), gated only by `$exclude_tags`. Our tags reach it — and
  by default come back re-wrapped and unchanged, which is harmless.

**The conditional risk.** If a site filters
`bricks/dynamic_data/replace_nonexistent_tags` to `true`, that same path returns `''`
instead of the re-wrapped tag (`providers.php:562`), and Bricks would blank our tags
at priority 10 before we ever ran. The filter defaults to `false`, and Bricks' own
docblock records that it was set `false` in 1.4 because `true` caused unwanted
replacement of inline `<script>`/`<style>` data — so enabling it is unusual.

**Corrected remedy — priority 20 would make this WORSE, not better.** The instinct is
to mirror the `render_tag` fix. That is backwards:

- `render_tag` needed priority **20** because Bricks *returns a value* we must
  override, and running first would let Bricks re-read our resolved value as a tag.
- `render_content` needs the opposite. If Bricks blanks our tags at priority 10,
  running at 20 means they are already gone. The fix would be a priority **below 10**,
  so our substitution happens before Bricks can parse them.

**Disposition:** deferred. It requires a non-default filter to bite, and the fix
direction is the reverse of the one just applied — so it must not be "harmonised"
with `render_tag` by reflex.

**Follow-up:** if a site ever enables `replace_nonexistent_tags`, move
`render_content` to a priority below 10 and add a regression that models Bricks'
priority-10 blanking behaviour.

---

## Resolved during the run — do not re-raise

Listed so a future reviewer does not rediscover them as open:

| Finding | Resolution |
|---|---|
| Empty-array `locationId` untested | Case 4l added |
| `Case 4k` could not fail (unslash/sanitize order) | Fixture replaced with one verified to fail under a swapped composition |
| `esc_html__` stub did not escape | Fixed in both stub files as `esc_html(__($text, $domain))` |
| `wp_setup_nav_menu_item` stub cleared context flags | Corrected to model real WordPress; clone isolation now tested by object identity |
| `classes` used default-falsy `array_filter` | Predicate now `$c !== ''` |
| Case 7i (`is_ancestor`) could not fail | Cases 7i2/7i3 added |
| `render_tag` never exercised the loop-recovery path | Cases 8o/8p added |
| `render_tag` inert in production (Bricks p10 re-wraps) | Priority 20 + brace tolerance |
| Active-state tags dead on the frontend | Loop object now authoritative over the reconstructed post; context values excluded from the per-id cache |
| Three German wording items | Applied after native-speaker review |
