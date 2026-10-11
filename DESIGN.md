---
name: sfx-bricks-child admin (wp-admin native)
source: inc/SiteCheck/assets/site-check.css, inc/SiteCheck/assets/site-check.js, inc/SiteCheck/AdminPage.php
recorded: 2026-10-11
colors:
  ink: "#1d2327"
  ink-2: "#50575e"
  ink-3: "#646970"
  line: "#dcdcde"
  line-2: "#f0f0f1"
  panel: "#ffffff"
  surface-quiet: "#f6f7f7"
  action: "var(--wp-admin-theme-color, #2271b1)"
  status-red: "#d63638"
  status-yellow: "#dba617"
  status-green: "#00a32a"
  status-unknown: "#646970"
  status-hint: "#c5d9ed"
  status-idle: "#8c8f94"
  state-text: "#8a2424"
typography:
  family: wp-admin system stack (inherited, never set)
  verdict: { size: 23px, weight: 400, line-height: 1.3 }
  group-head: { size: 15px, weight: 600 }
  row-title: { size: 14px, weight: 600 }
  sub-head: { size: 13px, weight: 600 }
  body: { size: 13px, weight: 400 }
  meta: { size: 12px, weight: 400 }
rounded:
  panel: 0
  badge: 3px
  toggle: 3px
spacing: [2, 4, 6, 8, 10, 12, 16, 20, 24, 28, 32]
breakpoint: 782px
---

# Design system: wp-admin native

The theme's admin pages live inside wp-admin, among core and plugin screens, in German. The world is WordPress' own: white panels on the admin grey, core blue for actions, core status colours, the system font, dashicons, and core components (`button-primary`, `button-hero`, `button-link`, `nav-tab`, `subsubsub`, `notice`, `form-table`). Recorded from the Sicherheits-Check build (Tools → Sicherheits-Check), which applies WordPress' "Website-Zustand" grammar: one verdict for the site, then the checks sorted by what to do.

## Colors

All page tokens are custom properties on the app root (`.sfx-sc-app`, `--sfx-sc-*`).

- **Ink** `#1d2327` for headings, titles and verdict; **ink-2** `#50575e` for secondary prose and finding icons; **ink-3** `#646970` for meta lines, counts, chevrons and quiet links.
- **Lines** `#dcdcde` frame panels and separate rows; **line-2** `#f0f0f1` separates findings and notes inside an open row.
- **Panel** white on the admin background; **surface-quiet** `#f6f7f7` is the row hover, the "Selbst prüfen" hint box and the code template background.
- **Action** is `--wp-admin-theme-color` with `#2271b1` as fallback, so the page follows the user's admin colour scheme. It colours focus rings and the running state. Never hard-code the blue.
- **Status colours** are core's: red `#d63638`, yellow `#dba617`, green `#00a32a` (the green badge uses `#007017`, because white 12px text on `#00a32a` is only 3.3:1). "Selbst prüfen" (unknown) is `#646970`, "Info" (hint) is `#c5d9ed`, not yet checked is `#f0f0f1` with ink-2 text, and the idle verdict mark is `#8c8f94`.
- **State text** `#8a2424`, italic, marks transient states in words: the row tag while a run is running or unsaved, and the settings "unsaved changes" line.

## Typography

No font is declared; everything inherits wp-admin's system stack. That is the platform's voice, not a fallback. The scale is short and steps by weight as much as size: verdict 23px/400 (core's page-title size), group heads 15px/600, row titles 14px/600, sub-heads inside a row ("Befunde", "Warum", "Empfehlung", "So geht's") 13px/600, body 13px, meta and badges 12px. Prose blocks are capped at 75ch, finding lists and notes at 80ch.

## Layout

- The app is capped at 1000px inside `.wrap`, under the core `h1`.
- Spacing comes from a small set of values: 4/8/12/16 inside components, 20–24 panel padding, 28 between result groups, 32 as the head panel's column gap.
- Below 782px (core's admin breakpoint), the head panel padding drops to 16px, the actions take the full width, the row body loses its indent, badges lose their minimum width and the one-line next step wraps.
- An open row's body is indented 112px, so its text lines up with the row title (16px padding + 84px badge + 12px gap).

## Elevation and depth

Flat. Panels get a 1px `line` border and no shadow. The only box-shadow is the focus ring, `0 0 0 2px` in the action colour (inset on rows), paired with a transparent 2px outline for forced-colours mode. That is core's focus treatment, not decoration.

## Shapes

Panels, lists and rows have square corners. The status badge and the "In Ordnung" toggle are rounded 3px, like core's buttons.

## Components

- **Verdict panel.** A white panel spanning the full width. On the left: a 40px dashicon mark in the state colour (`warning` for red/yellow, `editor-help` for unknown, `yes-alt` for green, `shield` before any run, `update` while running), the verdict as an `h2` ("8 Punkte zu erledigen"), a count line, and the last-run meta. On the right: `button button-primary button-hero` "Jetzt prüfen", the uploads-test checkbox, and a quiet `button-link` reset that only turns red and underlined on hover or focus. Run state is announced through an `aria-live="polite"` line.
- **Tabs.** Core `nav-tab-wrapper` / `nav-tab` / `nav-tab-active`: Ergebnis | .htaccess-Vorlage | Einstellungen.
- **Area filter.** Core `subsubsub` with `count` spans: Alle | Sicherheit | Live-Gang | Aufräumen.
- **Result groups.** "Zu erledigen" (red, yellow), "Selbst prüfen" (unknown), then "In Ordnung" (hint, green). Each group has a 15px head with a count and a one-line intro. "In Ordnung" folds behind a quiet `details`/`summary` toggle with a dashicon chevron, the way Site Health folds its passed tests.
- **Check row.** A native `details`/`summary`, closed by default. The summary holds the badge, the title, area and finding count in ink-3 ("Sicherheit · 4 Funde"), the state tag, and, on red and yellow only, the one-line next step (ellipsised, hidden once the row is open). A dashicon chevron (`\f347` / `\f343`) sits on the right. Rows stack inside one bordered white list, separated by 1px lines.
- **Status badge, the traffic light.** One per check: a word on a core colour, 84px minimum width, 12px/18px text: Rot, Gelb, Selbst prüfen, Info, Grün. Status is always a word as well as a colour.
- **Findings list.** Inside an open row: an unstyled list with `line-2` separators, each item a neutral 18px dashicon in ink-2 (`warning`, `editor-help`, `info-outline`, `yes`) plus the text. Passed findings drop to ink-3. Never coloured, never pills.
- **Guidance.** Warum / Empfehlung / So geht's (an ordered list of steps) / links in a wrapping row. For unknown results, a `surface-quiet` box tells the reader what to check by hand. A small ink-3 note under a `line-2` rule names where the evidence came from.
- **Empty and manual panels.** A white bordered panel, 16px × 20px padding.
- **PHP panels.** The `.htaccess` template is a `pre` on `surface-quiet` with a `line` border, scrolling horizontally, followed by a core `button` "Kopieren". Settings use `form-table`, `description`, `fieldset` and `p.submit` with `button-primary`, plus the state-text "unsaved" line. Page notices are core `notice notice-*`.

## Named rules

1. **One traffic light per check.** The status colour appears only on the check's badge and on the site verdict mark. Nothing else on the page carries a status colour.
2. **Neutral findings.** Findings are a grey list with monochrome dashicons. They never repeat the check's light.
3. **Status is a word.** Every colour has a label next to it ("Rot", "Gelb", "Selbst prüfen", "Info", "Grün", or the verdict sentence).
4. **Quiet by default.** Checks are closed until opened, passed checks fold away, and the reset is a grey link until it is hovered or focused.
5. **Core vocabulary first.** Use the wp-admin component when one exists (buttons, nav-tab, subsubsub, notice, form-table, dashicons). Take the action colour from `--wp-admin-theme-color`.
6. **Icons are dashicons.** Enqueue the `dashicons` style as a dependency. Do not stand in Unicode glyphs or emoji for icons.

## Do's and don'ts

- Do sort by what to do (red, yellow, unknown, hint, green), not by section.
- Do give every problem its next step in the row summary.
- Don't put a coloured side border on rows, groups or findings. Core's own `notice` keeps its native left border: that is the platform's convention, not this page's.
- Don't add shadows, gradients, or extra rounded cards around the panels.
