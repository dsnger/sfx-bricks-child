# Bricks Nav Menu Query — Deferred Findings

**Branch:** `feat/bricks-nav-menu-query`
**Date:** 2026-08-12
**Spec:** [`../specs/2026-08-10-bricks-nav-menu-query-design.md`](../specs/2026-08-10-bricks-nav-menu-query-design.md)
**Plan:** [`../plans/2026-08-11-bricks-nav-menu-query.md`](../plans/2026-08-11-bricks-nav-menu-query.md)

Findings raised during eleven task-scoped reviews, a whole-branch review and manual
end-to-end verification, which were triaged as non-blocking and consciously deferred.
They are recorded here rather than left in a scratch ledger so the decision to defer
survives the branch.

**Count:** eleven outstanding. Earlier summaries said "nine" — that figure predated
two further reviews. The list below is the authoritative one.

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
