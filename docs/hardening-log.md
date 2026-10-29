# Hardening log

Append-only ledger of review findings hardened via the `dev-workflow:harden-finding`
skill. One row per hardening (rung 0 "already caught" is not logged). `fingerprint`
is a canonical class — from the base taxonomy in the `harden-finding` skill, or from
this project's `docs/hardening-taxonomy.md`; column 2 is the recurrence-grep target.
Never edit a row; resolve a `pending` row by appending a new row (same fingerprint,
`ref` naming the prior row's date + anchor).

A row records a hardening claim as of its date, and its narration may be found wrong or
made stale later. When a row's text no longer describes reality — falsified by a
later change, or wrong when it was written — append a `Superseded rows` entry rather
than editing it. One later change is **excluded**: a hardening that is itself removed,
for which this convention supplies no move at all (see the end of this paragraph). This
holds for every row without exception: the existing `Never edit a row` rule is
absolute, and correcting a row is always an append. The rule is bound to rows, not to
commits: once text exists as a row it is never edited, committed or not. Drafting
before a row exists — an editor buffer, a line not yet written — is below the
rule's resolution, and nothing checks one. Resolving a `pending` row also appends —
that is a new hardening, not a correction to a row's text. Supersession marks a row's
**text** and never alters mechanical behaviour, including when the entry records that
the row's hardening claim was itself false: the row keeps its fingerprint, keeps
matching the column-2 grep, and keeps counting. A hardening later removed is out of
scope.

**Correcting a row.** Corrections live in a `Superseded rows` block above the
`Columns:` paragraph — a `**Superseded rows:**` label carrying one appended line per
supersession, present only once at least one entry exists:

    - <date> · supersedes <row date> `<fingerprint>` "<row fragment>" · what is false · where the current answer is

`<date>` is the day the entry is written, in `YYYY-MM-DD`. A row is located by date +
fingerprint. **An entry applies to every row its locator matches** — uniqueness is
not a requirement, and an entry that matches two rows says the same thing about both.
To narrow the match, add `"<row fragment>"`, a quoted fragment of that row's `finding`
carrying no double quote, in the position shown immediately after the fingerprint. **A
fragment narrows the match set; it singles out one row only where that row has one no
sibling shares** — a sibling being another row the same date and fingerprint match.
Where it has none — an identical `finding`, one that is a substring of a sibling's,
or one whose every unique fragment carries a double quote — the entry marks every
matching row, its accurate siblings included, and no fragment prevents that. Omit it,
quotes included, when you mean every row the pair matches — including when the pair
matches only one. Name the claim that does not hold — saying whether it stopped
holding or was never true — and cite where the current answer lives; restating that
answer here only makes the entry the next stale narration. Neither of those two fields
may contain ` · `: that separator is what divides them, and free text carrying it
makes an entry parse two ways. A fragment is matched **literally and case-sensitively
against the row's `finding` as written in the file**, escapes and markup included —
what you quote is what is in the table, not what a renderer shows you. **An entry
applies only to matching rows dated on or before the entry's own date** —
supersession marks the past, so a row dated later never comes under an entry written
before it. **A row's date is the day it is appended**, and the table is chronological:
backdating a row is forbidden, which is what makes the date bound mean what it says.
Nothing can verify the append day itself, and nothing checks that dates never decrease;
the rule is stated and read. The union merge driver (`.gitattributes`) keeps every
branch's lines but interleaves them by position, not by date, so after a merge the table
can hold a later row above an earlier one with nobody having backdated anything: **for
rows, read the date column, never the position.** Entries are the opposite case —
supersession is defined by entry order, so a union merge that interleaves two branches'
entries can put an older correction last and make it govern. **A merge touching the
`Superseded rows:` block is resolved by hand**, in date order, not left to the driver. An entry whose locator matches no such row is **inert**:
it governs nothing and is not an error to repair in place — append a new entry with a
locator that matches, and leave the inert one standing as history, like every other
entry. **An entry marks a row, and the last entry for a row is the one that governs**
— where a row carries more than one, later in the file wins and the earlier ones are
history. **A later entry must therefore describe the row as it now stands, not only the
newly found fault**, or it retires a still-accurate earlier entry from a reader's view.
Entries are never edited, never removed, and never reference one another — and they
take the same floor as rows: once a line exists as a complete entry it is protected,
committed or not, while a line that is partial or does not yet carry the shape above is
still drafting and may be fixed. A mistyped locator in a complete entry is corrected
the same way everything else is, by appending. If a union merge leaves two `Superseded
rows:` labels, keep one and keep every entry under it. No standing tool reads this
block — to every grep and skill scanning the table a superseded row is unchanged,
including one whose entry says its fingerprint is wrong; prose readers get the
correction, mechanical readers do not, and nothing checks the difference.

**Superseded rows:**

- 2026-10-12 · supersedes 2026-10-12 `adversarial-input-against-handwritten-parser` · the count "45 Gate-B passes" was never true of the two cycles it names: they ran 25 (xxb2ep650r 16, prv9jv3vrc 9) · the curves in the squash body of b4306e4 (PR #60)
- 2026-10-12 · supersedes 2026-10-12 `adversarial-input-against-handwritten-parser` · the count "45 Gate-B passes" was never true of the two cycles the row names · the Gate B curves of xxb2ep650r and prv9jv3vrc in the squash body of b4306e4 (PR #60)

Columns: `date` (YYYY-MM-DD), `fingerprint` (canonical class), `finding` (short,
escape `\|`, one line), `source` (gate-a|gate-b|bot|manual),
`severity` (blocker|major|minor|nit), `rung` (e.g. `2 lint`, `4 test`, `1 prose`,
`P std`, `pending`), `ref` (rule name / test path / AGENTS.md section / prior row;
a `pending` row points at the `todos.md` entry that tracks it, by that entry's
leading bold title).
Unless the gate mode is `classic`, a row is also the learning record: `finding` names the symptom and its cause, `ref` the change,
its location and the evidence — the cycle nonce for a row a gate cycle owed, else the source (a
PR comment, a manual note). A `1 prose` row's `ref` also says why neither lint nor a test can
decide the finding.

| date | fingerprint | finding | source | severity | rung | ref |
|------|-------------|---------|--------|----------|------|-----|
| 2026-08-26 | docs-drift | AGENTS.md restated CLAUDE.md §5's prompt-artifact path list without `plugins/` | bot | minor | 4 test | tests/prompt-artifact-paths-test.php |
| 2026-09-16 | harness-unguarded-real-state | media-credits-rest-live-check.php deleted its Case 5 draft post inline, so a fatal left it on the live site | bot | minor | 1 prose | AGENTS.md § Don'ts, "verification harness ... guaranteed teardown" |
| 2026-10-12 | adversarial-input-against-handwritten-parser | 45 Gate-B passes (cycles xxb2ep650r, prv9jv3vrc) on hand-written HTML/header heuristics in the site check; shrunk to WP_HTML_Tag_Processor, uncertain → Nicht prüfbar (PR #61) | gate-b | major | 1 prose | AGENTS.md § Don'ts, "Don't read HTML in a check with a hand-written regex"; reader pinned by tests/site-check-outside-checks-test.php (html_document) |
| 2026-10-12 | docs-drift | taxonomy class text prescribed the HTML-only Observations::html_document() for HTTP header values | gate-b | minor | 1 prose | docs/hardening-taxonomy.md "Format", "A class that names a remedy promises only what that remedy does" |
| 2026-10-20 | docs-drift | README production note named only .env.local after the wp-config SFX_THEME_DEV_MODE constant became a second dev-mode source (cycle ioopr1xcoz) | gate-b | minor | 4 test | tests/docs-runtime-claims-test.php |
| 2026-10-20 | docs-drift | .cursor rule still said functions.php loads a Composer autoloader after runtime Composer was dropped (cycle ioopr1xcoz) | gate-b | minor | 4 test | tests/docs-runtime-claims-test.php |
| 2026-10-22 | release-config-unchecked | SHIPPED gained wpml-config.xml, which release.sh and test-instance.sh reject as outside STD-REL-06; the battery ran neither (cycle fi2i4pzg3s) | gate-b | blocker | 4 test | tests/release-config-test.php |
