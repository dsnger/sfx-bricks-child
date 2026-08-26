# PR review bots — sfx-bricks-child

Which automated reviewers run on this repo, and what each one *actually produces*.
`/dev-workflow:process-pr-review` routes on the **Wait for** list below — that list is
the authority. The table is descriptive: it records where each bot's findings have
been *observed*, which may be `inconsistent`.

Format follows dev-workflow-kit `docs/pr-review-bots.md`; the observations are this
repo's own (PRs #25 and #28).

| Bot | Enabled | Where findings appear | Notes (plan/tier limits, completion signal, quirks) |
|---|---|---|---|
| CodeRabbit | yes | **inline + PR-level summary** | Real findings observed on #25 (inline + summary items). **Plan: Pro Plus, 1 included review per hour** (#28) — the quota trap is the defining quirk: **its status check goes green while the comment reads "Review rate limited" and no review ran** (#25 head `b0baea9` merged unreviewed; #28 first head). A green check never proves the head was reviewed — the per-head review-record count is the arbiter. After the hourly reset the next push auto-reviews; `@coderabbitai review` answers "applicable only when automatic reviews are paused", so it is not a reliable re-trigger. Edits its walkthrough comment in place. |
| Greptile | yes | **summary always; inline sometimes** | PR-level summary comment every time, **edited in place** on re-review (compare the "Last reviewed commit" footer, not comment timestamps). Inline comments carry the findings when there are any (#28: 1 inline; #25: several). Confidence score in the summary (4/5 with findings, 5/5 clean). **Completion signal: none you can block on** — `gh pr checks` showed a "Greptile Review" entry on the first #28 head but the check-runs API returned nothing for later heads. Does not reliably auto-re-review small pushes; re-trigger and poll via the Greptile MCP (`trigger_code_review`, then `list_code_reviews` filtered by PR until `COMPLETED`, `get_code_review` for the body). Reviews take ~2–4 min. |

**Routing — these lists are authoritative.**

- **Wait for (block on it):** *none.* CodeRabbit's check is green even when no review
  ran, and Greptile has no signal at all.
- **Process opportunistically (never block):** CodeRabbit and Greptile. Read both
  channels (inline comments *and* the PR-level summary body) at the start of the pass;
  a later post is a follow-up, not something to wait on up front.
- **Ignore:** none observed on this repo so far.

**Per-head review count** (the arbiter for "was this head reviewed", any bot):

```
gh api repos/dsnger/sfx-bricks-child/pulls/<PR>/reviews \
  --jq '[.[] | select(.user.login=="coderabbitai[bot]" and .commit_id=="<HEAD_SHA>")] | length'
```

`0` means the head was not reviewed, whatever the check or the comment says. With no
bot under **Wait for**, a `0` does not block the merge — it only means the merge ships
without that bot's opinion, which is the human's call to record.
