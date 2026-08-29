#!/usr/bin/env bash
#
# Drive release.sh end to end against a throwaway bare remote with a stubbed
# `gh`, and assert what it pushed. release.sh once shipped a release whose
# version bump never reached the remote (v0.22.3), and its push behaviour has
# no other check.
#
# MANUAL. quality.sh does not run this: its globs are tests/*-test.php and
# tests/*-test.mjs, and this needs a scratch clone and a few seconds per
# scenario.
#
#   tests/support/release-push-harness.sh <repo> [release.sh]
#
# Scenarios: normal, rc (run detached — an RC needs no destination), rcnovendor
# (the invariant-5 guard must still fire for an RC), rcnoexclude (so must the
# package-exclude guard), detached (no branch to push
# to), tagnotbranch (origin
# has only a same-named tag), notlevel (an unpushed local commit is present),
# cleanupfails (the post-release `rm` fails), pushfails
# (the remote rejects branch pushes). The last two both assert that the TAG of
# an already-published release survives — rollback() must not fire there.
#
# The optional second argument is the release.sh to test; without it the
# clone's committed copy is used, which is how the counterfactual is run —
# point it at an older revision, or omit it while a fix is uncommitted, and the
# `normal` scenario must FAIL.
#
# NOT covered: --skip-bump (removing its guard from the push condition still passes),
# and the identity check reads the remote head's SUBJECT rather than the pinned SHA,
# which the harness cannot see. Both would need the harness to parse release.sh's
# output for the SHA it pinned.
#
# NOT covered, deliberately: a remote rewound or deleted BETWEEN the preflight and
# the final push. The push carries --force-with-lease pinned to the SHA the preflight
# approved, but staging that race needs a receive hook that mutates the bare repo
# mid-run, which is more machinery than the scenario is worth.
#
# Everything that MUTATES a working repository runs after the checked `cd` into
# the throwaway clone. Before it, only read-only or scratch-only commands run:
# `git -C "$SRC" rev-parse` to validate the argument, and `git init`/`git clone`
# into the temp dir. Two separate guards: a failed clone returns from the
# scenario, and the `cd` is fatal — without it the rest would operate on the
# caller's real checkout, rewriting its `origin` and running release.sh there.

set -euo pipefail

SRC=${1:-}
RELEASE_SH=${2:-}
[ -n "$SRC" ] || { echo "usage: $0 <repo> [release.sh]" >&2; exit 2; }
# rev-parse, not `-d .git`: in a linked worktree .git is a file, and the harness
# should still run from one.
git -C "$SRC" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  || { echo "error: $SRC is not a git repository" >&2; exit 2; }
[ -z "$RELEASE_SH" ] || [ -f "$RELEASE_SH" ] || { echo "error: no such file: $RELEASE_SH" >&2; exit 2; }

SRC=$(cd "$SRC" && pwd)
[ -z "$RELEASE_SH" ] || RELEASE_SH=$(cd "$(dirname "$RELEASE_SH")" && pwd)/$(basename "$RELEASE_SH")

VERSION=0.99.0
failures=0
checks=0
ok()   { echo "    ok   $*"; }
fail() { echo "    FAIL $*"; failures=$((failures + 1)); }
check() { # check <description> <expected> <actual>
  checks=$((checks + 1))
  if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

# One EXIT trap removes every scratch dir the run accumulated, including when
# errexit kills the shell part-way through a scenario.
scratch=()
cleanup() { [ ${#scratch[@]} -eq 0 ] || rm -rf "${scratch[@]}"; }
trap cleanup EXIT

run_scenario() {
  local scenario=$1
  local tmp; tmp=$(mktemp -d)
  scratch+=("$tmp")

  git init --bare --quiet "$tmp/origin"
  git clone --quiet "$SRC" "$tmp/work" 2>/dev/null || { fail "clone failed"; return; }
  cd "$tmp/work" || { fail "cd into clone failed"; return; }

  local branch; branch=$(git branch --show-current)
  git remote set-url origin "$tmp/origin"
  git push --quiet origin "HEAD:refs/heads/$branch"
  local base_head; base_head=$(git rev-parse HEAD)

  # The clone carries the committed release.sh; swapping in another one has to
  # be committed, since release.sh preflights a clean working directory.
  if [ -n "$RELEASE_SH" ]; then
    cp "$RELEASE_SH" release.sh
    # An identical copy is not an error: `git commit` would exit 1 on an empty
    # change and take the whole harness down with it under `set -e`.
    if ! git diff --quiet -- release.sh; then
      git add release.sh
      git commit --quiet -m "harness: release.sh under test"
      git push --quiet origin "HEAD:refs/heads/$branch"
      base_head=$(git rev-parse HEAD)
    fi
  fi

  # HEAD detached: there is no branch to push the release commit to. The `rc`
  # scenario runs detached too, on purpose — an RC publishes and pushes nothing,
  # so it must stay releasable from a checkout that has no destination at all.
  if [ "$scenario" = detached ] || [ "$scenario" = rc ]; then
    git checkout --quiet --detach HEAD
  fi

  # The remote has no such branch — only a TAG of the same name, which an
  # unqualified `git fetch origin <name>` would happily accept.
  if [ "$scenario" = tagnotbranch ]; then
    git push --quiet origin "HEAD:refs/tags/$branch"
    # refs/heads/ explicitly: with the tag in place, `--delete <name>` is
    # ambiguous and git refuses it.
    git push --quiet origin --delete "refs/heads/$branch"
  fi

  # An unrelated local commit the remote does not have. `git push` would send it
  # along with the release commit, which invariant 7 reserves for pull requests.
  if [ "$scenario" = notlevel ]; then
    git commit --quiet --allow-empty -m "unrelated local commit"
  fi

  # release.sh preflights the Composer autoloader — invariant 5. `rcnovendor`
  # withholds it to prove an RC still reaches that guard: the RC exemption is
  # meant to skip the destination checks only.
  if [ "$scenario" != rcnovendor ]; then
    mkdir -p vendor && printf '<?php\n' > vendor/autoload.php
  fi

  # Break the package exclude list, so the SECOND shared guard fails. rcnovendor
  # alone would pass even if only the autoloader check stayed above the RC
  # return — this pins that the earlier shared guards run for an RC too.
  if [ "$scenario" = rcnoexclude ]; then
    sed -i.bak 's|^  "/tests/"|  "/tests-disabled/"|' build-theme.sh && rm -f build-theme.sh.bak
    git commit --quiet -am "harness: break the exclude list"
    git push --quiet origin "HEAD:refs/heads/$branch"
    base_head=$(git rev-parse HEAD)
  fi

  # Stub gh so no real release is created.
  mkdir -p "$tmp/bin"
  # The stub also logs, so the order of publish / upload / push can be asserted:
  # moving the push back inside the rollback window would otherwise satisfy every
  # other check in this file.
  # shellcheck disable=SC2016  # the stub's $1/$2 and GH_LOG must reach the stub
  printf '#!/bin/sh\necho "$1 $2" >> "$GH_LOG"\n[ "$1 $2" = "release create" ] && echo "https://example.invalid/tag/stub"\nexit 0\n' > "$tmp/bin/gh"
  chmod +x "$tmp/bin/gh"

  # Fail the post-release zip cleanup only, to prove it cannot cost the tag of
  # an already-published release nor skip the commit push.
  if [ "$scenario" = cleanupfails ]; then
    # shellcheck disable=SC2016  # "$@" belongs to the stub
    printf '#!/bin/sh\ncase "$*" in *.zip) exit 1 ;; esac\nexec /bin/rm "$@"\n' > "$tmp/bin/rm"
    chmod +x "$tmp/bin/rm"
  fi

  # A receive hook logs REAL branch updates into the same event stream as the gh
  # verbs. Ordering asserted from status messages alone proved nothing: a push
  # moved earlier while its message stayed put passed every check.
  # `pushfails` additionally rejects them.
  local deny=""
  [ "$scenario" = pushfails ] && deny="echo \"denied by harness\"; exit 1"
  cat > "$tmp/origin/hooks/pre-receive" <<HOOK
#!/bin/sh
while read -r _o _n ref; do
  case "\$ref" in
    refs/heads/*) echo "branch push" >> "$tmp/gh.log"; $deny ;;
  esac
done
exit 0
HOOK
  chmod +x "$tmp/origin/hooks/pre-receive"

  local rc=0
  export GH_LOG="$tmp/gh.log"; : > "$GH_LOG"
  case "$scenario" in rc|rcnovendor|rcnoexclude) is_rc_run=1 ;; *) is_rc_run=0 ;; esac
  if [ "$is_rc_run" -eq 1 ]; then
    PATH="$tmp/bin:$PATH" ./release.sh "$VERSION" harness --rc >"$tmp/out" 2>&1 || rc=$?
  else
    PATH="$tmp/bin:$PATH" ./release.sh "$VERSION" harness >"$tmp/out" 2>&1 || rc=$?
  fi

  local remote_head remote_tags local_tags local_head
  remote_head=$(git -C "$tmp/origin" rev-parse "$branch" 2>/dev/null || echo none)
  remote_tags=$(git -C "$tmp/origin" tag -l | tr '\n' ' ' | sed 's/ $//')
  local_tags=$(git tag -l | grep 0.99 | tr '\n' ' ' | sed 's/ $//' || true)
  local_head=$(git rev-parse HEAD)

  # Only the release verbs: release.sh also calls `gh auth status` from its release
  # helper, which says nothing about publication order.
  local gh_order; gh_order=$(grep '^release ' "$GH_LOG" | tr '\n' ',' | sed 's/,$//' || true)
  # Every logged event, gh verbs and REAL branch updates, in the order they happened.
  local events; events=$(grep -E '^(release |branch push)' "$GH_LOG" | tr '\n' ',' | sed 's/,$//' || true)

  # Real ordering, by position in the transcript. Comparing the gh log alone
  # cannot do this: the branch push is not a gh command, so a release.sh that
  # pushed FIRST and published afterwards satisfied every other check here.
  local out_file=$tmp/out
  line_of() { grep -na -m1 "$1" "$out_file" | cut -d: -f1; }
  in_order() {
    local prev=0 n m
    for m in "$@"; do
      n=$(line_of "$m")
      [ -n "$n" ] || return 1
      [ "$n" -gt "$prev" ] || return 1
      prev=$n
    done
  }

  echo "  scenario: $scenario (exit $rc)"
  case "$scenario" in
    normal)
      check "exits 0"                              0            "$rc"
      check "remote tip equals the local tip"      "$local_head" "$remote_head"
      # Identify the remote head by its message, not just by equality with the
      # local one: a commit created after the release commit would satisfy that
      # comparison while riding along on the push.
      check "remote head has the release subject"  "Release v$VERSION" "$(git -C "$tmp/origin" log -1 --format=%s "$branch" 2>/dev/null)"
      check "remote branch moved past the base"    changed      "$([ "$remote_head" != "$base_head" ] && echo changed || echo unchanged)"
      check "tag pushed"                           "v$VERSION"  "$remote_tags"
      check "reports the branch up to date"        yes          "$(grep -qa 'on origin is up to date' "$tmp/out" && echo yes || echo no)"
      check "real event order"                     "release create,release upload,branch push" "$events"
      check "cleanup message precedes push message" yes          "$(in_order 'Cleaning up zip file' 'Updating .* on origin' && echo yes || echo no)"
      ;;
    rc)
      check "exits 0 from a detached HEAD"         0            "$rc"
      check "remote branch untouched"              "$base_head" "$remote_head"
      check "no tag on the remote"                 ""           "$remote_tags"
      check "local RC tag only"                    "v${VERSION}_rc" "$local_tags"
      check "skipped the remote checks"            yes          "$(grep -qa 'RC release: skipping' "$tmp/out" && echo yes || echo no)"
      check "nothing was published"                ""           "$gh_order"
      ;;
    rcnoexclude)
      check "RC still runs the exclude guard"      failed       "$([ "$rc" -ne 0 ] && echo failed || echo ok)"
      check "names the exclude list"               yes          "$(grep -qa 'exclude list is missing' "$tmp/out" && echo yes || echo no)"
      check "no commit was made"                   "$base_head" "$local_head"
      check "no tag created locally"               ""           "$local_tags"
      check "nothing was published"                ""           "$gh_order"
      ;;
    rcnovendor)
      check "refuses without vendor/autoload.php"  failed       "$([ "$rc" -ne 0 ] && echo failed || echo ok)"
      check "names the autoloader"                 yes          "$(grep -qa 'vendor/autoload.php not found' "$tmp/out" && echo yes || echo no)"
      check "no commit was made"                   "$base_head" "$local_head"
      check "no tag created locally"               ""           "$local_tags"
      check "nothing was published"                ""           "$gh_order"
      ;;
    detached|tagnotbranch)
      check "refuses to release"                   failed       "$([ "$rc" -ne 0 ] && echo failed || echo ok)"
      check "no tag created locally"               ""           "$local_tags"
      check "no RELEASE tag on the remote"         ""           "$(git -C "$tmp/origin" tag -l | grep -c 0.99 | tr -d ' ' | sed 's/^0$//')"
      check "nothing was published"                ""           "$gh_order"
      ;;
    notlevel)
      check "refuses to release"                   failed       "$([ "$rc" -ne 0 ] && echo failed || echo ok)"
      check "says why"                             yes          "$(grep -qa 'not level with origin' "$tmp/out" && echo yes || echo no)"
      check "no tag created locally"               ""           "$local_tags"
      check "no tag on the remote"                 ""           "$remote_tags"
      check "remote branch untouched"              "$base_head" "$remote_head"
      check "nothing was published"                ""           "$gh_order"
      ;;
    cleanupfails)
      check "exits 0 despite the failed cleanup"   0            "$rc"
      check "TAG SURVIVES (no rollback)"           "v$VERSION"  "$remote_tags"
      check "commit still pushed"                  "$local_head" "$remote_head"
      check "did not roll back"                    no           "$(grep -qa 'Rolling back' "$tmp/out" && echo yes || echo no)"
      check "warns about the leftover zip"         yes          "$(grep -qa 'Could not remove' "$tmp/out" && echo yes || echo no)"
      ;;
    pushfails)
      check "exits non-zero"                       failed       "$([ "$rc" -ne 0 ] && echo failed || echo ok)"
      check "TAG SURVIVES (no rollback)"           "v$VERSION"  "$remote_tags"
      check "remote branch unchanged"              "$base_head" "$remote_head"
      check "reports the outcome as unknown"       yes          "$(grep -qa 'is UNKNOWN' "$tmp/out" && echo yes || echo no)"
      check "names the release commit SHA"         yes          "$(grep -qa 'Release commit: [0-9a-f]\{40\}' "$tmp/out" && echo yes || echo no)"
      check "names the approved remote state"      yes          "$(grep -qa 'Expected remote state at preflight: [0-9a-f]\{40\}' "$tmp/out" && echo yes || echo no)"
      check "points at the recovery steps"         yes          "$(grep -qa 'publish-release.mdc' "$tmp/out" && echo yes || echo no)"
      check "warns against a plain retry"          yes          "$(grep -qa 'Do not simply retry' "$tmp/out" && echo yes || echo no)"
      check "says the release is intact"           yes          "$(grep -qa 'release and its tag are intact' "$tmp/out" && echo yes || echo no)"
      check "did not roll back"                    no           "$(grep -qa 'Rolling back' "$tmp/out" && echo yes || echo no)"
      ;;
  esac
  cd "$SRC"
}

echo "release-push-harness: ${RELEASE_SH:-<committed copy>}"
for s in normal rc rcnovendor rcnoexclude detached tagnotbranch notlevel cleanupfails pushfails; do run_scenario "$s"; done

if [ "$failures" -ne 0 ]; then
  echo "HARNESS: FAIL ($failures of $checks assertions)"
  exit 1
fi
echo "HARNESS: PASS ($checks assertions)"
