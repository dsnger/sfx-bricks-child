# Todos

Valid review findings that were out of scope where they surfaced — so they
don't get lost. Remove entries when done.

- **release.sh rollback leaves the bump commit as HEAD** (from Greptile on
  PR #30, 2026-08-26): any failure after the release commit — not just a
  missing autoloader, e.g. a zip/rsync failure in `build_theme` — triggers
  `rollback()`, which deletes the tag locally and on the remote but keeps
  the version-bump commit as HEAD (`git reset --hard HEAD` is a no-op
  against the commit itself). The checkout is then on an untagged,
  unpublished release commit. PR #30 removed the most likely trigger by
  checking the autoloader in the preflight; making `rollback()` also
  restore the pre-release HEAD is a separate, riskier change (the trap
  fires for every ERR, and eating a commit on an unrelated failure would
  be worse) and needs its own design pass.
