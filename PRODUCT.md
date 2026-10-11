# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Agency admins (the theme's own team) running client WordPress sites built with Bricks and this child theme. Technically literate; clients do not use the theme's admin tools (confirmed 2026-10-11 for the Sicherheits-Check).

## Product Purpose

sfx-bricks-child ships theme-level admin modules into wp-admin (optimization, security headers, media credits, redirects, the Sicherheits-Check, and more) so an agency can harden, tidy and launch client sites without extra plugins. Success: an admin knows the state of a site and what to do next without interpreting the tool.

## Operating Context

- Everything lives inside wp-admin, German UI, alongside WordPress core screens and plugin screens.
- Sicherheits-Check (Tools → Sicherheits-Check) is used both as a go-live checklist (work through until done) and as a periodic health check (has anything got worse?) — equally important (confirmed 2026-10-11).
- First glance on that page must give an overall verdict plus the to-do list — the concrete points to work on (confirmed 2026-10-11).

## Capabilities and Constraints

- Native to wp-admin: WordPress admin look, accessible, keyboard operable, German translations via the `sfxtheme` text domain.
- No new runtime dependencies; output escaped at the point of output; admin JS writes text via `textContent` only.
- Sicherheits-Check results come from evidence rules (spec `docs/superpowers/specs/2026-10-10-site-check-design.md`): statuses Rot, Gelb, Nicht prüfbar (shown "Selbst prüfen"), Hinweis (shown "Info"), Grün.

## Product Principles

- Unmistakable state: one verdict per check, and one for the site; detail never competes with the verdict.
- Tell what to do, not just what is: every problem comes with its next step.
- The theme knows itself: settings of its own modules are read, never suggested again when already set.
- Quiet by default: closed details, open on demand.

## Accessibility & Inclusion

Status is never conveyed by colour alone; full keyboard operation; screen-reader announcements for run state.
