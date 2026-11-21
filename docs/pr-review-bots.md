# PR review bots — sfx-bricks-child

Which automated reviewers run on this repo, and what each one *actually produces*.
`/dev-workflow:process-pr-review` routes on the **Wait for** list below — that list is
the authority. The table is descriptive: it records where each bot's findings have
been *observed*, which may be `inconsistent`.

Format follows dev-workflow-kit `docs/pr-review-bots.md`; the observations are this
repo's own (PRs #25, #28, #30 and #32).

| Bot | Enabled | Where findings appear | Notes (plan/tier limits, completion signal, quirks) |
|---|---|---|---|
| CodeRabbit | **no — gone for good** (Daniel, 2026-11-15) | as observed while it ran: **inline + PR-level summary** | No longer reviews this repo; nothing waits on it or reads it. Observations from when it ran: real findings observed on #25 (inline + summary items). **Plan: Pro Plus, 1 included review per hour** (#28) — the quota trap is the defining quirk: **its status check goes green while the comment reads "Review rate limited" and no review ran** (#25 head `b0baea9` merged unreviewed; #28 first head). A green check never proves the head was reviewed — the per-head review-record count is the arbiter. After the hourly reset the next push auto-reviews; `@coderabbitai review` answers "applicable only when automatic reviews are paused", so it is not a reliable re-trigger. Edits its walkthrough comment in place. |
| Greptile | **requested by the review loop, once per PR** (Daniel, 2026-11-20; automatic reviews set to never since 2026-10-29) | as observed before 2026-10-29: **summary always; inline sometimes** | Automatic reviews stay off in Greptile's dashboard ("never"; INFERRED to match `autoReview: []`, which Greptile's greptile.json reference defines as never — source: greptile.com/docs/code-review/greptile-json-reference; the repo has no `greptile.json` or `.greptile/` to override it); `/dev-workflow:process-pr-review` requests one review per PR with an `@greptileai` comment where **Request when** below matches, and needs Greptile's default summary comment to see it. PRs #85–#93 (2026-10-29 to 2026-11-20) carry no Greptile comment. Observations from when it was on: PR-level summary comment every time, **edited in place** on re-review (compare the "Last reviewed commit" footer, not comment timestamps). Inline comments carry the findings when there are any (#28: 1 inline; #25: several). Confidence score in the summary (4/5 with findings, 5/5 clean). **Completion signal: none you can block on** — `gh pr checks` showed a "Greptile Review" entry on the first #28 head but the check-runs API returned nothing for later heads. Does not reliably auto-re-review small pushes; re-trigger and poll via the Greptile MCP (`trigger_code_review`, then `list_code_reviews` filtered by PR until `COMPLETED`, `get_code_review` for the body). Reviews take ~2–4 min. |

**Routing — these lists are authoritative.**

An entry may carry `only with label <name>` (the bot is off on a PR without that label)
and `once per PR` (a review of any head of the PR counts), and Greptile may carry `requested by the
review loop` (`/dev-workflow:process-pr-review` requests it once per PR where **Request when**
below matches, and waits at most 15 minutes, the one bounded wait in this category). A bot that is
off goes under **Ignore**; `/dev-workflow:process-pr-review` neither reads nor reports it.

- **Wait for (block on it):** *none.* No bot reviews this repo automatically.
- **Process opportunistically (never block):** Greptile — once per PR, requested by the review loop
  (Daniel, 2026-11-20: "Das Kit ruft es selbst").
- **Ignore:** CodeRabbit — gone for good (Daniel, 2026-11-15); a CodeRabbit check or
  comment that still shows up on a PR is neither waited on nor read.

**Request when** (only with `requested by the review loop`; edit to suit this project). Request a
Greptile review only when all four hold, over the PR's merge-base..head; the first that fails is the
one-line reason in the PR body:
1. The head branch does not start with `chore/kit-` (a kit refresh).
2. Some changed path is not docs-only as `.claude/review-gates.md` defines it.
3. Some such path is also not a `CHANGELOG.md`, and not a version record (the files on `AGENTS.md`'s
   `**Version sites:**` line, a plugin manifest, `.claude/dev-workflow-version`, `VERSION`) whose
   diff changes only lines holding the version. Unclear: it counts.
4. The PR is not a small change as `.claude/review-gates.md` defines it (a cited story at risk `high`
   or security above `none`, or any prompt path, is never small).

**Per-head review count** — for a requested Greptile review (by the review loop or by hand). Greptile creates a
review record only when it has inline findings. Pass the bot's login:

```sh
BOT_LOGIN=greptile-apps[bot]
gh api --paginate --slurp repos/dsnger/sfx-bricks-child/pulls/<PR>/reviews \
  | jq "[.[][] | select(.user.login==\"$BOT_LOGIN\" and .commit_id==\"<HEAD_SHA>\")] | length"
```

`--paginate` matters: without it `gh api` returns only the first 30 reviews, so a
match on a later page reads as `0` — the exact false negative this command exists to
rule out. `--slurp` cannot be combined with `gh`'s own `--jq`, hence the pipe to `jq`
and the `.[][]` that flattens the array-of-pages.

**For Greptile a `0` proves nothing.** It creates a review record when it posts
inline findings (#25, #28 and #30 each have one, `COMMENTED`) and none when it finds
nothing — on #32 the query returned `0` while Greptile's own footer named the head it
had just reviewed. So a non-zero count is real positive evidence, a zero is
inconclusive, and the complete signal is that footer: "Last reviewed commit" in its
summary comment. With no
bot under **Wait for**, a `0` does not block the merge and needs no record.

**Revisit when:** a bot's plan/tier changes (a Pro→Free downgrade turns a findings bot
into a summary-only one, and back), or a bot is enabled/disabled.
