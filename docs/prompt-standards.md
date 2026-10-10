# Prompt Standards

Skills, gate prompts (CLAUDE.md §5), hook messages, slash commands, agent definitions
(`.claude/agents/` or `plugins/*/agents/`, if this project has any), and spec/plan
templates are prompts. When authoring or changing one, it must pass the checklist below —
Gate A reviews prompt specs against these criteria via AGENTS.md.

**Ad-hoc task briefs are prompts too.** A brief handed to the coding agent for a single
task steers the same model with the same failure modes as anything above. Briefs are held
to this checklist **in spirit** — success criteria, stop conditions, verified claims —
but nobody reviews a brief against all 12 items, which is exactly why those habits have
to live in how briefs are written rather than in a review step.

Living references (consult, don't copy — copies go stale):

- Anthropic prompting best practices: https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices
- Model-specific pages (pick the target model's page): https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/overview
- OpenAI/Codex prompting guide — applies to the Codex gate prompts (Gate A/B
  run on an OpenAI model, not Claude): https://learn.chatgpt.com/docs/prompting
  (Codex-specific workflows are a section of that page.)

## Checklist (each item must be verifiably true)

1. **Target model named.** The prompt states which model executes it (Claude
   via Claude Code, or Codex via `mcp__codex__*`), and the author checked that
   model's current prompting page. Why: recommendations differ per model and
   change between generations.
2. **Success criteria explicit.** The prompt defines what "done" looks like in
   checkable terms (e.g. "typecheck exit 0", "story has ≥3 acceptance criteria"),
   never "make it good". Why: strong criteria let agents loop independently
   (CLAUDE.md §4).
3. **Stop conditions defined.** When to stop, escalate, or ask the user —
   especially for looping/agentic prompts. Why: prevents runaway loops and
   silent scope drift.
4. **Output format specified with an example.** Expected structure shown, not
   described. Why: examples constrain format better than prose.
5. **Structured sections.** Context → task → rules → output format, separated
   by headings or XML tags. Why: models parse delimited structure more
   reliably than flowing prose.
6. **Rules carry their why.** Each constraint states its reason in one clause.
   Why: models follow motivated rules better, and reviewers can judge whether
   the rule still applies.
7. **No contradictions with CLAUDE.md / AGENTS.md.** New prompt text must not
   conflict with existing instructions; if it supersedes one, update the old
   text in the same change. Why: contradictory instructions degrade
   compliance unpredictably.
8. **Token-lean.** No duplicated content from AGENTS.md/CLAUDE.md (reference
   instead), no boilerplate. Why: context budget is shared with the actual
   task.
9. **Positive instructions.** Say what to do, not what to avoid ("write
   flowing prose" instead of "don't use markdown"). Why: per Anthropic's best
   practices, positive framing steers current models more reliably. The
   CLAUDE.md §1–3 discipline rules are a deliberate exception: their subject *is*
   the prohibition ("no speculative abstractions", "don't refactor what isn't
   broken"), and restating a prohibition positively loses the boundary it draws —
   new prompts need a stated reason to do the same.
10. **Diagnostic states name their causes.** A prompt that reports a failure state
    ("NOT LOADED", "MISSING", "unavailable") enumerates the distinct causes that
    produce that state, gives a check that tells them apart, and pairs each with its
    own fix. Why: causes with an identical symptom but different fixes are the case
    the reader cannot resolve alone — offering only the most common one sends them
    round a loop that never terminates. A result the prompt could not fully establish
    is reported as undetermined, a state of its own, rather than as "protected",
    "absent" or "permitted"; what follows from it is what the contract for the
    affected action already says. Why: filing an undetermined result under the state
    that needs no action removes a protection nobody decided to remove.
11. **Enforcement claims name their mechanism.** Any sentence saying something is
    enforced, caught, guaranteed or prevented names *what does it*, and the author
    verified that mechanism exists before writing it — by reading the code, running the
    command, or checking the doc it relies on. Why: an unverified guarantee is worse
    than an admitted gap, because a reader stops looking. When the mechanism turns out
    not to exist, say what actually happens instead ("this is a rule the agent keeps;
    nothing counts for it").

    **Where the reader can reach the authoritative source, cite it instead of restating
    it** — a classifier, a policy section, a config. Every restatement is a copy that can
    drift and a fresh chance to overclaim. This does not apply to text that must be
    self-contained (a scaffolded template cannot point at a file the reader does not
    have); there, restate and keep the copies in sync deliberately. **And when a claim
    about a mechanism has needed a fourth correction, delete the claim rather than refine
    it again** — successive corrections tend to be subtler versions of the same
    overclaim.
12. **Calibrated emphasis.** Reserve MUST/CRITICAL/ALL-CAPS for genuinely hard
    rules; default to plain wording ("Use X when …"). Why: current models follow
    instructions more literally and overtrigger on aggressive language
    (documented in the best-practices page). Existing heavy emphasis (e.g.
    CLAUDE.md §5 gate language) is a deliberate exception for discipline
    gates — new prompts need a stated reason to use it.

## Model-specific notes — last verified: 2026-10-10 (Claude Opus 5.5 · Codex `gpt-6-astra`)

Distilled from the model-specific pages; the linked pages are authoritative. Read on
2026-10-10: the best-practices page, "Prompting Claude Opus 5.5" and "Prompting Claude
Opus 5" (Opus 5.5 says Opus 5 patterns remain the starting point). The Codex model in
`~/.codex/config.toml` is `gpt-6-astra`; OpenAI's Codex prompting page names no model, and
the Astra guidance below is from OpenAI's "Rethinking skills and prompts for GPT-6 Astra"
(developers.openai.com/blog), read 2026-10-10.

- **Less scaffolding on stronger models.** Skills/prompts written for prior
  models are often too prescriptive and degrade output quality on newer ones.
  On a model upgrade, test with instructions *removed* before adding more.
  (Opus 5 page: drop explicit verification and "double-check" instructions — they cause
  over-verification. Astra post: trim AGENTS.md and skills, drop "run the tests"
  nudges.)
- **Review prompts: coverage first, filter later.** "Only report high-severity"
  makes current models silently drop real findings. The finding stage must ask for
  every issue with confidence + severity; ranking/filtering is a separate step.
  Gate B's Blocker/Major filter is downstream — the finding prompt itself must
  request full coverage. (Opus 5 page states this directly.)
- **Ground progress claims.** In long runs, instruct: audit each claim against
  a tool result before reporting; unverified work is reported as unverified.
  Belongs in executing/TDD prompts. Opus 5.5 may end a turn with a progress report
  while work is open: treat a text-only turn end as a report, not as "done".
- **Independence comes from the other model, not from a second Claude pass.** The
  Opus 5 page advises against using subagents to verify or double-check the model's own
  work. The cross-model gates rest on a different model reviewing, which no current
  page contradicts; an earlier note claiming "fresh-context verifier subagents
  outperform self-critique" is no longer supported by the pages and was removed.
- **Never instruct "show your reasoning in the response".** On Opus 5 and 5.5 such
  prompts may be declined with the `reasoning_extraction` refusal category. Ask for a
  short explanation or a summary of actions instead.
- **Literal instruction following.** Current models don't generalize scope on
  their own — state it ("apply to every section, not just the first").
- **Calibrated emphasis still holds.** The best-practices page recommends "Use this
  tool when…" over "CRITICAL: You MUST…", because current models overtrigger on it.

## Escalation

Recurring prompt-quality findings follow the same ladder as code findings
(CLAUDE.md): prose note → checklist item here → template change. Prompts are
artifacts; `harden-finding` treats them like code. Run the
`dev-workflow:harden-finding` skill to apply a rung and record it in
`docs/hardening-log.md`.

## Revalidation

On a model generation change (new Claude model in Claude Code, new Codex
model for the gates): re-check this doc against the then-current
model-specific pages, and update the "last verified" date in the heading above. Tracked with the tooling
revalidation entry in `todos.md`.
