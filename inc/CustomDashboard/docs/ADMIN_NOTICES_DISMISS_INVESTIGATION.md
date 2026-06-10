# Admin-notice dismiss — open investigation

**Status:** open / non-reproducing as of 2026-05-03
**Reported by:** Daniel
**Symptom:** Clicking a plugin notice's own dismiss link inside the custom dashboard does nothing (notice does not go away). Specifically observed on an UpdraftPlus "Danke für das Installieren von UpdraftPlus!" notice with a "Verwerfen (für 12 Monate)" link in the top-right.

The user could not reproduce the issue on a follow-up check (notice was already gone). This file captures what we know so the next time the issue appears we can move straight to root cause without re-doing the architecture review.

## How notice rendering works in our dashboard

1. `Controller.php:241` — dashboard wrapper is hooked at `all_admin_notices` with `PHP_INT_MAX` priority, so plugin notices have already echoed into `#wpbody-content` by the time our wrapper renders.
2. `Controller.php:198-206` — CSS hides any `.notice / .updated / .error / .update-nag` that is a direct child of `.wrap` or `#wpbody-content`, to suppress flash-of-unstyled-content before JS runs.
3. `DashboardRenderer.php:170` — empty `<div class="sfx-admin-notices"></div>` placeholder is rendered inside the dashboard container.
4. `dashboard-script.js:792-805` — `relocateAdminNotices()` runs on DOMContentLoaded and `appendChild`s every matching notice into `.sfx-admin-notices`.
5. `dashboard-script.js:814-843` — a `MutationObserver` on `#wpbody-content` relocates late-injected notices (plugins that print notices via AJAX after page load).

`appendChild` *moves* the live DOM node, so any event handler bound directly to the element — or delegated from `document` / `body` — survives the move. WordPress core's `.notice-dismiss` × button uses `document`-level delegation (`wp-admin/js/common.js`), so the standard × works after relocation.

## Where dismiss can still break (ranked by likelihood)

1. **Plugin-specific dismiss handlers that walk the DOM upward.**
   Some plugins do `$(link).closest('.wrap').find(…)` or scope state to `#wpbody-content > .notice:nth-child(...)`. After our `appendChild` move, the notice is no longer inside `.wrap` — so a `closest('.wrap')` lookup returns `null` and the handler silently no-ops.
   *Most likely cause for the UpdraftPlus link.*

2. **Inline `<script>` tags embedded inside the notice HTML.**
   `appendChild` does not re-execute already-run scripts. If a plugin prints `<script>$('.foo-dismiss').on('click', …)</script>` *inside* the notice markup, that script ran once when the notice was first parsed. After the move, `$('.foo-dismiss')` may still match — the binding is on the live node, so it should travel with it. **Unless** the script depends on DOM ancestry, in which case the binding works but the click handler fails.

3. **Custom dismiss link is a normal `<a href="?action=…">`.**
   Click triggers a full GET to e.g. `admin.php?action=updraftplus_dismiss_notice&...`. The `href` is absolute, so the move doesn't change navigation. But if the plugin re-prints the notice on the redirected page (because the snooze window is short, or the dismiss action requires extra params we're missing), it looks like "click did nothing" symptomatically — in reality the dismiss did persist but the notice came back on next render.

4. **The MutationObserver re-relocates a freshly-added notice mid-dismiss.**
   If a plugin's dismiss handler does an in-place DOM swap (insert a "thanks, dismissed" message), our observer might react. Less likely — the observer ignores additions inside `.sfx-admin-notices` (`dashboard-script.js:825`) — but worth checking if other failure modes are ruled out.

## Diagnostic to run when the issue reappears

Open the dashboard with browser DevTools (Console + Network tabs), click the failing dismiss link, and capture:

1. **URL change?** — does the address bar change at all when clicking dismiss?
2. **Network request?** — is anything fired to `admin-ajax.php`, `admin-post.php`, or a query-string action like `?action=…_dismiss`? What HTTP status?
3. **Console errors?** — JS errors, especially `TypeError: Cannot read properties of null` (a closest()/parent() lookup that lost its target).
4. **Cross-check with WP-standard `.notice-dismiss` × button** on a different notice on the same dashboard. If that × works but the plugin-specific link doesn't → the issue is plugin-specific (likely cause #1 above). If neither works → our relocation broke document-level delegation, which would be surprising and worth a deeper look.

## Likely fix paths (don't implement until reproduced)

**If cause #1 (DOM-walk) is confirmed for UpdraftPlus or any other plugin:**

- Option A — keep notices in their original DOM position and only *visually* move them via CSS (`position: absolute`, `transform`, or grid placement). Pro: zero handler-context damage. Con: more complex CSS, harder to keep responsive layout clean.
- Option B — clone-and-link approach: leave the original node where it was (hidden via CSS), and render a visible clone inside `.sfx-admin-notices`. Sync click events from the clone back to the hidden original via event forwarding. Brittle.
- Option C — wrap the relocated notice with a synthetic `.wrap` ancestor inside `.sfx-admin-notices` to satisfy plugins that walk up to `.wrap`. Cheap to try; doesn't help if the plugin walks to `#wpbody-content` specifically.

**If cause #2 (inline script lost ancestry):** there's no clean fix without re-parsing the notice markup. Document as a known limitation for plugins that embed dismiss scripts inline.

**If cause #3 (dismiss persists but notice re-renders):** this is a plugin behaviour, not our bug. Document and move on.

## Files involved

- `inc/CustomDashboard/Controller.php:183-241` — CSS hide rules and the `all_admin_notices` PHP_INT_MAX hook
- `inc/CustomDashboard/DashboardRenderer.php:159-171` — `.sfx-admin-notices` mount point
- `inc/CustomDashboard/assets/dashboard-script.js:765-843` — `relocateAdminNotices()` + MutationObserver
- `inc/CustomDashboard/assets/dashboard-style.css:93-176` — visual styles for relocated notices (no `pointer-events` or `display:none` rules that would interfere with clicks; reviewed and ruled out)
