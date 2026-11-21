#!/usr/bin/env bash
# Release check for the Contact Infos schema output (spec AC-6, C7). Manual, before `bin/release.sh publish`.
#   SFX_TEST_SITE=<site dir> SFX_TEST_URL=<https origin> bash tests/support/contact-schema-release-check.sh
# For each setup (no SEO plugin, Yoast, Rank Math, The SEO Framework with its knowledge output off and
# on) it activates exactly that plugin and fetches SFX_TEST_URL twice: once with the theme's schema switch
# off (control) and once on. It prints one line per setup:
#   delivered | deliberately suppressed (expected for this setup) | not delivered | inconclusive - <why>
# The marker is the organization's telephone from Contact Infos (SFX_TEST_MARKER overrides it), looked for
# only in the primary organization node's own telephone field: the graph node another node names as its
# publisher, or the Organization/LocalBusiness node whose @id ends in "#organization".
# Provenance by element: only the company script of the setup's producer is read - the theme's own
# (class sfx-contact-schema) without an SEO plugin or with TSF, Yoast's (yoast-schema-graph), Rank Math's
# (rank-math-schema). The per-page field (sfx-page-schema) is never read. For TSF with its knowledge output
# on, TSF's own JSON-LD must be on the page, or the setup is inconclusive.
# A fetch counts only with HTTP 200 and a body that Observations::html_document() recognises as HTML; the
# page is read inside the site (contact-schema-jsonld.php, via `wp eval-file -`). With the switch off the
# marker must be absent, else the plugin already holds it. Anything uncertain is inconclusive, never a verdict.
# It installs nothing: a plugin that is not installed is skipped.
# It turns the schema switch on itself for each "on" fetch; if the theme still reports mode "off" then,
# the run fails (exit 1) instead of printing no verdict.
# Lock: a run takes <site>/.sfx-contact-schema-release-check.lock (mkdir, atomic; <site> is SFX_TEST_SITE
# as an absolute physical path) before its snapshot; a second run fails at once. The snapshots are kept
# in that directory as <option>.snapshot files before the first change. A run killed with SIGKILL leaves
# the lock; remove it by hand.
# Teardown: one trap, declared before the first change, restores the active plugins, TSF's option and
# the theme's schema option exactly as they were and re-reads each. Every restore-side WP-CLI call runs
# with --skip-plugins --skip-themes, so a plugin this run activated cannot block the restore by breaking
# WordPress; the restores are plain core option writes. Only a fully verified restore removes the
# snapshots and the lock. If any step fails, the run exits non-zero, keeps the lock and the snapshots,
# and prints their path and the commands to restore by hand.
set -euo pipefail

SITE=${SFX_TEST_SITE:?set SFX_TEST_SITE to the test site directory}
# absolute and physical before anything is derived from it, so a relative path names the same lock
SITE=$(cd "$SITE" && pwd -P) || { echo "cannot resolve $SFX_TEST_SITE" >&2; exit 1; }
[ -n "$SITE" ] || { echo "cannot resolve $SFX_TEST_SITE" >&2; exit 1; }
URL=${SFX_TEST_URL:?set SFX_TEST_URL to the test site URL}
HERE=$(cd "$(dirname "$0")" && pwd) || { echo "cannot find the harness directory" >&2; exit 1; }
ANALYZER="$HERE/contact-schema-jsonld.php"
[ -f "$ANALYZER" ] || { echo "missing $ANALYZER" >&2; exit 1; }
cd "$SITE" || { echo "cannot cd to $SITE" >&2; exit 1; }
# every guard that picks where we operate fails fatally: this must be a WordPress root
[ -f wp-config.php ] || [ -f wp-config-ddev.php ] || { echo "$SITE is not a WordPress directory" >&2; exit 1; }

PLUGINS="wordpress-seo seo-by-rank-math autodescription"
TSF_OPTION=autodescription-site-settings
SCHEMA_OPTION=sfx_contact_infos_schema
LOCK="$SITE/.sfx-contact-schema-release-check.lock"

# ddev swallows stdin, so every call inside a loop gets </dev/null
wp() { ddev wp "$@" </dev/null; }
# core only: no plugin or theme code runs, so a broken plugin cannot stop a snapshot or a restore
wp_core() { ddev wp --skip-plugins --skip-themes "$@" </dev/null; }
# serialize() keeps an option exactly as stored; "__absent__" marks an option that did not exist
option_snapshot() { wp_core eval 'echo base64_encode(serialize(get_option("'"$1"'", "__absent__")));'; }
option_restore() { # $1 = option, $2 = snapshot
  wp_core eval '$v = unserialize(base64_decode("'"$2"'"), ["allowed_classes" => false]);
    if ($v === "__absent__") { delete_option("'"$1"'"); } else { update_option("'"$1"'", $v); }' >/dev/null 2>&1
}

SNAPSHOT_OPTIONS="active_plugins $TSF_OPTION $SCHEMA_OPTION"
HAVE_LOCK=0
SNAPSHOT_TAKEN=0
cleanup() {
  local rc=$? o
  set +e
  if [ "$SNAPSHOT_TAKEN" = 1 ] && ! restore; then
    echo "RESTORE FAILED. The lock stays, so no other run starts, and the original values are kept in" >&2
    echo "  $LOCK (<option>.snapshot: base64 of PHP serialize(); \"__absent__\" = the option did not exist)." >&2
    echo "Restore by hand from $SITE, one command per option, check the site, then remove that directory:" >&2
    for o in $SNAPSHOT_OPTIONS; do
      # %q-quoted path: the command stays valid for any site path, an apostrophe included
      printf '  ddev wp --skip-plugins --skip-themes eval '\''$v = unserialize(base64_decode("'\''"$(cat %s)"'\''"), ["allowed_classes" => false]); if ($v === "__absent__") { delete_option("%s"); } else { update_option("%s", $v); }'\''\n' "$(printf %q "$LOCK/$o.snapshot")" "$o" "$o" >&2
    done
    [ "$rc" != 0 ] || rc=1
    exit "$rc"
  fi
  if [ "$HAVE_LOCK" = 1 ]; then
    # a lock left behind refuses the next run, so a failed cleanup fails this one (an earlier status wins)
    for o in $SNAPSHOT_OPTIONS; do rm -f "$LOCK/$o.snapshot" || { [ "$rc" != 0 ] || rc=1; }; done
    if rmdir "$LOCK"; then echo "restore: lock released"; else echo "restore: COULD NOT REMOVE THE LOCK $LOCK" >&2; [ "$rc" != 0 ] || rc=1; fi
  fi
  exit "$rc"
}
restore() { # returns non-zero if any option could not be restored or reads back different
  local o snap failed=0
  # the active plugin list is restored as stored, without loading any plugin (no (de)activation hooks)
  for o in $SNAPSHOT_OPTIONS; do
    if ! snap=$(cat "$LOCK/$o.snapshot") || [ -z "$snap" ]; then
      echo "restore: SNAPSHOT OF $o MISSING" >&2; failed=1; continue
    fi
    if ! option_restore "$o" "$snap"; then echo "restore: WRITING $o FAILED" >&2; failed=1; fi
    if [ "$(option_snapshot "$o" 2>/dev/null)" = "$snap" ]; then echo "restore: $o as before"; else echo "restore: $o DIFFERS from the initial value" >&2; failed=1; fi
  done
  return "$failed"
}
trap cleanup EXIT

mkdir "$LOCK" 2>/dev/null || { echo "another release check holds $LOCK; wait for it, or remove the directory if no run is active" >&2; exit 1; }
HAVE_LOCK=1

for o in $SNAPSHOT_OPTIONS; do
  snap=$(option_snapshot "$o")
  [ -n "$snap" ] || { echo "empty snapshot of $o" >&2; exit 1; }
  printf '%s' "$snap" > "$LOCK/$o.snapshot"
done
SNAPSHOT_TAKEN=1

MARKER=${SFX_TEST_MARKER:-$(wp eval 'echo \SFX\ContactInfos\Schema\GraphBuilder::organization(\SFX\ContactInfos\Schema\Selection::resolve(), "Organization")["entity"]["telephone"] ?? "";' | tail -n1)}
[ -n "$MARKER" ] || { echo "the organization contact has no telephone, so nothing marks the theme's data; fill it in or set SFX_TEST_MARKER" >&2; exit 1; }
echo "organization telephone looked for: $MARKER"

only_active() { # $1 = plugin to leave active ("" = none)
  local p
  for p in $PLUGINS; do
    if [ "$p" = "$1" ]; then wp plugin activate "$p" >/dev/null; else wp plugin deactivate "$p" >/dev/null 2>&1 || true; fi
  done
}

set_option_key() { # $1 = option, $2 = key, $3 = PHP value
  wp eval '$o = get_option("'"$1"'", []); $o = is_array($o) ? $o : []; $o["'"$2"'"] = '"$3"'; update_option("'"$1"'", $o);' >/dev/null
}

b64() { printf '%s' "$1" | base64 | tr -d '\n'; }

# Fetches the page and prints the analyzer's one line: present ... | absent ... | inconclusive: <why>
analyze() { # $1 = source: own | yoast | rank_math | tsf
  local out html status
  if ! out=$(curl -ksS --max-time 30 -w '\n%{http_code}' "$URL/?sfx_release_check=$RANDOM" 2>&1); then
    echo "inconclusive: fetch failed: $out" | head -n1
    return
  fi
  status=${out##*$'\n'}
  html=${out%$'\n'*}
  # the page travels inside the script; stdin here is that script, not the loop's
  if ! out=$({ printf '<?php\n$html = base64_decode("%s");\n$marker = base64_decode("%s");\n' "$(b64 "$html")" "$(b64 "$MARKER")"
               sed 1d "$ANALYZER"
               printf '\necho sfx_release_check_verdict($html, (int) "%s", $marker, "%s"), "\\n";\n' "$status" "$1"; } | ddev wp eval-file - 2>/dev/null); then
    echo "inconclusive: wp eval-file failed"
    return
  fi
  out=$(printf '%s\n' "$out" | tail -n1)
  case "$out" in
    present* | absent* | inconclusive:*) echo "$out" ;;
    *) echo "inconclusive: unexpected analyzer output: $out" ;;
  esac
}

check() { # $1 = label, $2 = source (own | yoast | rank_math | tsf); tsf is expected to publish no company data
  local off on mode verdict
  set_option_key "$SCHEMA_OPTION" enabled false
  off=$(analyze "$2")
  set_option_key "$SCHEMA_OPTION" enabled true
  mode=$(wp eval 'echo \SFX\ContactInfos\Schema\Mode::detect()["mode"];')
  # the switch was just turned on: "off" here means it did not take, and no verdict is possible
  [ "$mode" != off ] || { echo "$1: the schema switch is still off after turning it on, so nothing can be checked" >&2; exit 1; }
  on=$(analyze "$2")
  case "$off" in
    inconclusive:*) verdict="inconclusive - control fetch with the switch off: ${off#inconclusive: }" ;;
    present*) verdict="inconclusive - plugin already holds the marker (switch off)" ;;
    *)
      case "$on" in
        inconclusive:*) verdict="inconclusive - ${on#inconclusive: }" ;;
        present*) verdict=delivered; [ "$2" = tsf ] && verdict="delivered (UNEXPECTED: this setup should be suppressed)" ;;
        *) verdict="not delivered"; [ "$2" = tsf ] && verdict="deliberately suppressed (expected for this setup)" ;;
      esac
      ;;
  esac
  printf '%-34s mode=%-15s %s\n' "$1" "$mode" "$verdict"
  printf '%-34s   off: %s | on: %s\n' "" "$off" "$on"
}

only_active ""
check "no SEO plugin" own
for p in $PLUGINS; do
  if ! wp plugin is-installed "$p" >/dev/null 2>&1; then
    printf '%-34s not installed - skipped\n' "$p"
    continue
  fi
  only_active "$p"
  if [ "$p" = autodescription ]; then
    set_option_key "$TSF_OPTION" knowledge_output 0; check "$p (knowledge output off)" own
    set_option_key "$TSF_OPTION" knowledge_output 1; check "$p (knowledge output on)" tsf
  elif [ "$p" = wordpress-seo ]; then
    check "$p" yoast
  else
    check "$p" rank_math
  fi
done
