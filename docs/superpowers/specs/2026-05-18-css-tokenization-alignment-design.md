# CSS Tokenization Alignment — Design

**Date:** 2026-05-18
**Branch (planned):** `refactor/css-tokenization-alignment`
**Scope:** `assets/css/frontend/modules/*.css` + `inc/GeneralThemeOptions/Settings.php`

## Goal

Extend the buttons-module tokenization contract (internal `--sfx-btn-*` scoped tokens fed by external `--btn-*` wireup tokens) to the other layered modules — **forms**, **lists**, **content-grid** — and simplify the buttons surface so the user doesn't have to define a token per color variant.

Animations module is **out of scope**: its tokens are intentionally cascade-overridden from outside (`.stagger`, `.animation--*`) and the scoped-longhand pattern would fight that.

## Background

Two prior commits established the pattern:

- `3974d82` — wrapped modules in `@layer sfx.components` and introduced scoped `--sfx-*` variables.
- `bbb3198` — admin "CSS Variables" extractor switched to "referenced but not defined" so internal `--sfx-*` tokens are hidden and only external wireup tokens surface.

Buttons (`assets/css/frontend/modules/buttons.css`) is the current gold standard. Forms is mostly aligned. Lists and content-grid still use single-prefix names that mix internal/external roles, and have no chain-to-Bricks-core defaults — so the user must define every override literally.

## Architecture

Every layered component module follows this contract:

```
External wireup tokens (--<module>-*)
  - public surface, admin-visible
  - default-chain to Bricks core tokens (--primary, --space-s, etc.), with literal as final fallback
        ↓
Internal scoped tokens (--sfx-<module>-*)
  - declared on the component's scoped selector
  - never referenced outside the module
        ↓
CSS rules use ONLY --sfx-* longhands
```

- `:where(...)` keeps base specificity at 0.
- Modules sit under `@layer sfx.components`; sub-layer order anchored centrally in `styles.css` (commit `c7c2e7a`).
- Variants override `--sfx-*` to pull from a different external token (e.g. `--sfx-btn-color: var(--btn-primary-bg, var(--primary, #2563eb))`).

## Buttons — simplification

**Problem.** Each of 10 color variants currently requires 2–3 external tokens (`--btn-primary-bg`, `--btn-primary-fg`, `--btn-secondary-mix`, …) — ~25–30 tokens for full coverage. User has to redefine everything Bricks already exposes.

**Decision.** Chain to Bricks core color tokens. Reduce fg/mix to two global tokens each.

### External token surface (after)

| Token | Default | Purpose |
|---|---|---|
| `--btn-gap`, `--btn-padding-block/-inline`, `--btn-font-size/-weight/-line-height/-letter-spacing`, `--btn-border-width/-style`, `--btn-radius`, `--btn-shadow`, `--btn-shadow-hover`, `--btn-transition`, `--btn-focus-outline-width/-color` | literals (unchanged) | Base chrome |
| `--btn-s-*`, `--btn-l-*`, `--btn-xl-*` | literals (unchanged) | Size variants |
| `--btn-color-fg` | `#fff` | Single default fg for dark-bg variants |
| `--btn-color-fg-on-light` | `#111` | Single default fg for light-bg variants (`light`, `accent`, `warning`) |
| `--btn-mix` | `black` | Hover darken for dark bgs |
| `--btn-mix-on-light` | `white` | Hover lighten for `secondary`, `dark`, `muted` |
| `--btn-<variant>-bg` (10, optional) | `var(--<variant>, <literal>)` | Bg per variant; chain to Bricks core token |
| `--btn-<variant>-fg` (10, optional) | not declared; falls through to global | Per-variant fg override |
| `--btn-<variant>-mix` (10, optional) | not declared; falls through to global | Per-variant mix override |

### Per-variant CSS pattern

```css
&.bricks-background-primary   { --sfx-btn-color: var(--btn-primary-bg, var(--primary, #2563eb)); }
&.bricks-background-secondary { --sfx-btn-color: var(--btn-secondary-bg, var(--secondary, #64748b));
                                --sfx-btn-mix: var(--btn-secondary-mix, var(--btn-mix-on-light)); }
&.bricks-background-accent    { --sfx-btn-color: var(--btn-accent-bg, var(--accent, #f59e0b));
                                --sfx-btn-color-fg: var(--btn-accent-fg, var(--btn-color-fg-on-light)); }
&.bricks-background-light     { --sfx-btn-color: var(--btn-light-bg, var(--light, #f8fafc));
                                --sfx-btn-color-fg: var(--btn-light-fg, var(--btn-color-fg-on-light)); }
&.bricks-background-dark      { --sfx-btn-color: var(--btn-dark-bg, var(--dark, #0f172a));
                                --sfx-btn-mix: var(--btn-dark-mix, var(--btn-mix-on-light)); }
&.bricks-background-muted     { --sfx-btn-color: var(--btn-muted-bg, var(--muted, #94a3b8));
                                --sfx-btn-mix: var(--btn-muted-mix, var(--btn-mix-on-light)); }
&.bricks-background-info      { --sfx-btn-color: var(--btn-info-bg, var(--info, #0ea5e9)); }
&.bricks-background-success   { --sfx-btn-color: var(--btn-success-bg, var(--success, #22c55e)); }
&.bricks-background-danger    { --sfx-btn-color: var(--btn-danger-bg, var(--danger, #ef4444)); }
&.bricks-background-warning   { --sfx-btn-color: var(--btn-warning-bg, var(--warning, #eab308));
                                --sfx-btn-color-fg: var(--btn-warning-fg, var(--btn-color-fg-on-light)); }
```

The base scoped block sets defaults the dark-bg variants rely on:

```css
:where(.brxe-button.bricks-button, .brxe-button.btn, button.bricks-button) {
  --sfx-btn-color-fg: var(--btn-color-fg, #fff);
  --sfx-btn-mix: var(--btn-mix, black);
  /* ... other base longhands unchanged ... */
}
```

Outline variants (`.outline` + `.bricks-color-<variant>`) get the same treatment.

### User experience after

- Defines nothing → buttons render with Bricks defaults via `--primary`, `--secondary`, etc.
- Sets `--primary` in Bricks → buttons follow.
- Wants button-specific override → sets `--btn-primary-bg`.
- Wants to flip default fg globally → sets `--btn-color-fg`.

## Forms — completeness + state-surface tokens

### New external tokens

| Token | Default | Replaces |
|---|---|---|
| `--form-transition` | `all .15s ease` | 4 hardcoded transitions (`choose-files`, `file-result`, `file-result .remove`, checkbox/radio) |
| `--form-placeholder-transition` | `opacity .2s ease` | hardcoded placeholder transition |
| `--form-success-surface-fg` | `var(--success, #16a34a)` | hardcoded `#16a34a` (file-result border + text) |
| `--form-success-surface` | `color-mix(in srgb, var(--sfx-form-success-surface-fg) 5%, var(--sfx-form-input-bg))` | hardcoded file-result success bg |
| `--form-error-surface-fg` | `var(--danger, #dc2626)` | hardcoded `#dc2626` (file-result border + text) |
| `--form-error-surface` | `color-mix(in srgb, var(--sfx-form-error-surface-fg) 5%, var(--sfx-form-input-bg))` | hardcoded file-result error bg |
| `--form-file-result-gap` | `0.75rem` | literal |
| `--form-file-result-padding` | `0.75rem` | literal |
| `--form-file-result-icon-size` | `16px` | literal |
| `--form-submit-sending-gap` | `8px` | literal |

### Chain existing literal defaults to Bricks core

| Existing token | Old default | New default chain |
|---|---|---|
| `--form-focus-color` | `#2563eb` | `var(--primary, #2563eb)` |
| `--form-radio-active-color` | `#2563eb` | `var(--primary, #2563eb)` |
| `--form-label-color` | `#111827` | `var(--text, #111827)` |
| `--form-color` | `#111111` | `var(--text, #111111)` |
| `--form-error-color` | `#991b1b` | `var(--danger-fg, #991b1b)` |
| `--form-error-bg` | `#fee2e2` | `var(--danger-bg, #fee2e2)` |

### Naming decision: keep `--form-error-bg` / `--form-error-color`

These remain as-is (they style the validation-message tooltip — a different visual context from the new file-result state surfaces). Renaming would break back-compat for theme-options users. The new `--form-{error,success}-surface*` tokens cover the inline-state use case.

## Lists — full rename + wireup

Internal `--list-*` → `--sfx-list-*` across the file. New external `--list-*` wireup layer chained to Bricks core tokens.

| External token | Default chain |
|---|---|
| `--list-gutter` | `var(--space-s, 1rem)` |
| `--list-indent` | `0` |
| `--list-gap` | `var(--space-2xs, 0.5rem)` |
| `--list-item-gutter` | `var(--article-gutter-xs, var(--space-2xs, 0.5rem))` |
| `--list-icon-gap` | `var(--space-2xs, 0.5rem)` |
| `--list-icon-size` | `1em` |
| `--list-icon-offset` | `0` |
| `--list-icon-display` | `block` |
| `--list-icon-url` | check SVG (see below) |
| `--list-icon-color` | `var(--secondary, currentColor)` |
| `--list-icon-bg-offset` | `0` |
| `--list-icon-bg-size-pad` | `8px` |
| `--list-icon-bg-radius` | `var(--radius-full, 9999px)` |
| `--list-icon-bg-color` | `var(--tertiary-l-4, transparent)` |

**Default icon SVG** (mirrors the checkbox icon in forms.css; fill irrelevant under `mask-image`):

```
url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='black' d='m14.914 4l-9.47 9.47L1.09 8.393L2.608 7.09l2.948 3.44L13.5 2.585z'/%3E%3C/svg%3E")
```

Both base and `.is-check` variant default to the same check SVG:

```css
:is(ul.list--icon, .has-list-icons) {
  --sfx-list-icon-url: var(--list-icon-url, url("data:image/svg+xml,...check..."));
  /* ... */
}

:is(ul.list--icon, .has-list-icons).is-check {
  --sfx-list-icon-url: var(--list-check-icon-url, var(--list-icon-url, url("data:image/svg+xml,...check...")));
  --sfx-list-icon-color: var(--list-check-icon-color, var(--list-icon-color, var(--secondary)));
  --sfx-list-icon-offset: var(--list-check-icon-offset, 0 .5ex);
  /* ... rest of the existing check-tuned spacing tokens, all rerouted through --sfx-* ... */
}
```

So `<ul class="list--icon">` renders a check by default; `<ul class="list--icon is-check">` renders a check with the check-tuned spacing preset.

Existing variant external tokens (`--list-check-icon-url`, `--list-check-icon-color`, `--list-check-icon-offset`, etc.) are preserved — they keep working as the override surface for the `.is-check` variant.

## Content-grid — full rename + wireup

Internal `--cg-*` → `--sfx-cg-*`. New external `--cg-*` wireup defined at `:root`.

| External token | Default chain |
|---|---|
| `--cg-gutter` | `var(--container-padding-horizontal, 1.5rem)` |
| `--cg-content` | `var(--max-screen-width, 1400px)` |
| `--cg-feature` | `var(--container-xlarge, 2100px)` |
| `--cg-feature-max` | `var(--container-2xlarge, 2450px)` |
| `--cg-gap` | `var(--grid-gap, var(--space-m, 1.5rem))` |

```css
:root {
  --sfx-cg-gutter: var(--cg-gutter, var(--container-padding-horizontal, 1.5rem));
  --sfx-cg-content: var(--cg-content, var(--max-screen-width, 1400px));
  --sfx-cg-feature: var(--cg-feature, var(--container-xlarge, 2100px));
  --sfx-cg-feature-max: var(--cg-feature-max, var(--container-2xlarge, 2450px));
  --sfx-cg-gap: var(--cg-gap, var(--grid-gap, var(--space-m, 1.5rem)));
}
```

All `.content-grid`, `.content--*` rules switch to `--sfx-cg-*`. Media-query breakpoint literals (768/1400/2100/2450) stay hardcoded — CSS forbids `var()` inside `@media`.

## Admin extractor — allowlist by module prefix

**Problem.** After the refactor, every module references Bricks core tokens (`--primary`, `--space-s`, `--container-padding-horizontal`, etc.) as fallback chains. The current extractor in `inc/GeneralThemeOptions/Settings.php::get_css_variables()` would surface those as per-module external tokens — noise, since they belong to Bricks/core framework and are managed there.

**Fix.** Constrain the extractor's output to tokens matching the module's own prefix. Mapping:

| File | Allowed prefix |
|---|---|
| `buttons.css` | `--btn-` |
| `forms.css` | `--form-` |
| `lists.css` | `--list-` |
| `content-grid.css` | `--cg-` |
| `animations.css` | `--animate-` (unchanged — module not refactored) |

Implementation sketch:

```php
private static function get_module_prefix(string $filename): string {
    $map = [
        'buttons.css'      => '--btn-',
        'forms.css'        => '--form-',
        'lists.css'        => '--list-',
        'content-grid.css' => '--cg-',
        'animations.css'   => '--animate-',
    ];
    return $map[$filename] ?? '';
}

// inside get_css_variables(), after the array_diff:
$prefix = self::get_module_prefix($filename);
if ($prefix !== '') {
    $variables = array_values(array_filter(
        $variables,
        fn($v) => str_starts_with($v, $prefix)
    ));
}
```

## Transient cache

Bump `sfx_css_vars_v6_*` → `sfx_css_vars_v7_*` in `Settings.php:133` so the old (pre-allowlist) lists invalidate on first read.

## Migration plan — 5 commits on one branch

Branch: `refactor/css-tokenization-alignment` (off `main`).

1. **Buttons simplification** — refactor `buttons.css` per the table above. Drops per-variant fg/mix literal defaults; introduces global `--btn-color-fg`, `--btn-color-fg-on-light`, `--btn-mix`, `--btn-mix-on-light`; per-variant `--btn-<variant>-bg` chains to Bricks core.
2. **Lists rename + wireup** — internal `--list-*` → `--sfx-list-*`. External `--list-*` wireup layer added with chain-to-Bricks defaults. Base `.list--icon` renders default check SVG; `.is-check` variant preserved with same default.
3. **Content-grid rename + wireup** — internal `--cg-*` → `--sfx-cg-*`. External `--cg-*` wireup at `:root`.
4. **Forms completeness + state-surface tokens** — add the 10 new tokens; chain 6 existing color tokens to Bricks core; keep `--form-error-bg`/`--form-error-color` names for the validation-message tooltip.
5. **Admin extractor allowlist + transient bump** — `Settings.php` module-prefix filter; `v6` → `v7`.

## Back-compat

- Any theme-options user setting `--btn-secondary-fg` directly: still works (chain falls through `var(--btn-secondary-fg, var(--btn-color-fg, #fff))`).
- Lists/content-grid external setters that match the new wireup names (`--list-icon-color`, `--cg-gutter`, etc.): still work — they're now the public surface explicitly.
- Internal-only renames (`--list-icon-color` if it was being treated as the wireup before vs. now being `--sfx-list-icon-color`): the file's *external* `--list-icon-color` setter remains the same token name. Only intra-file references change.

## Verification per commit

- Visual spot-check in Bricks Builder: button variants render unchanged (all 10 + outline), forms render unchanged (text inputs, select, textarea, checkbox/radio, file upload, error messages), lists render unchanged (`.list--icon`, `.list--icon.is-check`, `.has-list-icons`), content-grid renders unchanged at breakpoints 768 / 1400 / 2100 / 2450.
- After commit 5: open General Theme Options → CSS Variables for each module → only that module's prefix appears.

## Out of scope

- Animations module rewrite.
- Backend / admin / builder stylesheets (`assets/css/backend/`, `assets/css/builder/`, `inc/*/assets/`).
- Media-query breakpoint tokenization (CSS spec limitation).
- Decomposing Bricks core tokens themselves (`--primary`, `--space-*`, etc.) — owned by Bricks / core framework.
