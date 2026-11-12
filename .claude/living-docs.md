# Living docs

Read this file before any work on a project document, before changing behaviour one describes, and
before Gate B. It says where each kind of content lives and what a change must record. The documents
it governs describe; binding rules stay in `AGENTS.md` and the approved specs.

## Homes

One home per kind of content. `AGENTS.md` § Documentation map is the signpost: one line per document,
with its link and when to read it, and no content repeated.

- **Product** — `docs/PRD.md`: problem, success measures, non-goals, boundaries, and the **feature
  map**: `Feature | Behaviour | Status | Last changed | Doc | Last story/plan`, one sentence of
  behaviour per row; status is exactly `planned`, `partial` (the row says which part is built) or
  `built`.
- **Architecture** — `docs/ARCHITECTURE.md`: components, boundaries, the path a request takes, shared
  contracts by reference, cross-cutting rules with links to what binds them.
- **Tech stack** — `docs/TECH-STACK.md`: each technology's job, why it was chosen, its constraints. No
  version numbers: manifests and lockfiles hold them. A version that constrains behaviour and that no
  manifest or lockfile records may be stated as a constraint, with its source.
- **Feature** — `docs/features/<name>.md`, one per feature, with these headings: purpose and triggers;
  current behaviour; planned; business rules, including failure, empty, concurrent and recovery paths;
  surfaces; borders to neighbour features; contracts used; records (the stories, specs and plans).

A suitable existing document may stay where it is; the signpost names it. Write a document when it
gets content, never as an empty template; until then the signpost marks its home `not yet written`.
Why one home: a statement kept in two places drifts in one of them.

## Authority and provenance

- A restated rule links its binding source. Where a document and its source disagree, the source
  wins: fix the document, or write `DISCREPANCY (<source> vs <source>)` when only a decision can
  resolve it.
- Each document names what it was last checked against: `as of <base commit> + <story or pull
  request>`. Never its own commit's hash: it does not exist yet, and a squash merge changes it.
- Built behaviour is written as current; a commitment not yet built is labelled `planned`.
- A derived statement carries `INFERRED (source: <path or section>)`. A fact no source records is
  written `not recorded`, never filled in from plausibility.

## In every change

1. **Same change.** A change that alters behaviour a document describes updates that document in the
   same change.
2. **Build record**, one line per document: `<path> | <status> — <what was checked>`, with status
   exactly one of `changed`, `checked, unchanged`, or `absent` (a home marked `not yet written`).
   Example: `docs/features/billing.md | checked, unchanged — refund rules still match`. It holds a
   line for:
   - every feature doc whose current behaviour or `Planned` section the change affects, neighbour
     features included — not only the docs the change edited;
   - each top-level home the signpost names;
   - `CLAUDE.md` and `AGENTS.md`, always, after checking that every sentence in them about where
     something lives agrees with the Documentation map. A moved document can falsify them without
     editing them, and a kit template taken into them can bring back an old sentence.

   A missing line is a finding, whoever finds it: the author before the closing commit, Gate B, or a
   pull-request review. Write the record before the first Gate B pass and put it in every Gate B
   call's `whatWasImplemented`, where Gate B checks it as claims; copy it into the pull request body
   and the unit's handover. Why: the record that lists only the edited docs lets an affected
   neighbour go stale unnoticed.
3. **Link check.** Run the docs link-check command from `AGENTS.md` § Commands
   (`sh scripts/check-doc-links.sh`, plus any skip prefixes) before the record is closed; it
   resolves relative links and their heading fragments, and belongs in the quality command. Why: a checker
   kept outside the repository is lost, and one without fragments misses renamed headings.
4. **Size soft alarm.** A living document past 10,000 bytes gets a line `size alarm: <path> <bytes>`
   in the build record. It puts splitting up for decision and never fails a check; coverage is never
   cut to meet a size.
5. **Effort per piece.** Each piece of documentation work — writing one document, the upkeep in one
   change — gets a line in the build record: start and end (`date` stamps), tokens where the harness
   reports them, each with how it was captured, and `unknown` where it was not. Never an estimate.

## Not covered

These rules do not cover naming a story's features at intake, assembling a builder's context from the
map, cross-feature flow sections, the impact check along dependencies, conflict checks on shared
contracts, the composed-candidate check, removing a feature, the splitting decision itself, or capability and child-feature levels.
They were not exercised in the pilot, and silence here is not a rule.
