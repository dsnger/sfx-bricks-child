#!/usr/bin/env bash
# Release check for the Contact Infos schema output (spec AC-6, C7). Manual, before `bin/release.sh publish`.
#   SFX_TEST_SITE=<site dir> SFX_TEST_URL=<https origin> bash tests/support/contact-schema-release-check.sh
# For each setup (no SEO plugin, Yoast, Rank Math, The SEO Framework with its knowledge output off and
# on) it activates exactly that plugin and fetches SFX_TEST_URL twice: once with the theme's schema switch
# off (control) and once on. It prints one line per setup:
#   delivered | deliberately suppressed (expected for this setup) | not delivered | inconclusive - <why>
# The marker is the organization's telephone from Contact Infos (SFX_TEST_MARKER overrides it), looked for
# only in the primary organization node's own telephone field: the graph node another node names as its
# publisher, or the Organization/LocalBusiness node whose @id ends in "#organization". The page is read
# inside the site by WordPress' WP_HTML_Tag_Processor (contact-schema-jsonld.php, via `wp eval-file -`).
# Provenance: with the switch off the marker must be absent, else the plugin already holds it and the
# setup is inconclusive. Unreadable HTML or invalid JSON-LD is inconclusive, never a verdict.
# It installs nothing: a plugin that is not installed is skipped.
# Teardown: one trap, declared before the first change, restores the active SEO plugins, TSF's option and
# the theme's schema option exactly as they were.
set -euo pipefail

SITE=${SFX_TEST_SITE:?set SFX_TEST_SITE to the test site directory}
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

# ddev swallows stdin, so every call inside a loop gets </dev/null
wp() { ddev wp "$@" </dev/null; }
# serialize() keeps an option exactly as stored; "__absent__" marks an option that did not exist
option_snapshot() { wp eval 'echo base64_encode(serialize(get_option("'"$1"'", "__absent__")));'; }
option_restore() { # $1 = option, $2 = snapshot
  wp eval '$v = unserialize(base64_decode("'"$2"'"), ["allowed_classes" => false]);
    if ($v === "__absent__") { delete_option("'"$1"'"); } else { update_option("'"$1"'", $v); }' >/dev/null 2>&1
}

INITIAL_ACTIVE=$(wp plugin list --status=active --field=name)
INITIAL_TSF=$(option_snapshot "$TSF_OPTION")
INITIAL_SCHEMA=$(option_snapshot "$SCHEMA_OPTION")

restore() {
  set +e
  local p now
  for p in $PLUGINS; do
    wp plugin is-installed "$p" >/dev/null 2>&1 || continue
    if printf '%s\n' "$INITIAL_ACTIVE" | grep -qx "$p"; then
      wp plugin activate "$p" >/dev/null 2>&1
    else
      wp plugin deactivate "$p" >/dev/null 2>&1
    fi
  done
  option_restore "$TSF_OPTION" "$INITIAL_TSF"
  option_restore "$SCHEMA_OPTION" "$INITIAL_SCHEMA"
  now=$(wp plugin list --status=active --field=name 2>/dev/null)
  if [ "$now" = "$INITIAL_ACTIVE" ]; then echo "restore: active plugins back to the initial list"; else echo "restore: ACTIVE PLUGIN LIST DIFFERS from the initial one" >&2; fi
  if [ "$(option_snapshot "$TSF_OPTION" 2>/dev/null)" = "$INITIAL_TSF" ]; then echo "restore: TSF option as before"; else echo "restore: TSF OPTION DIFFERS from the initial one" >&2; fi
  if [ "$(option_snapshot "$SCHEMA_OPTION" 2>/dev/null)" = "$INITIAL_SCHEMA" ]; then echo "restore: schema switch as before"; else echo "restore: SCHEMA OPTION DIFFERS from the initial one" >&2; fi
}
trap restore EXIT

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
analyze() {
  local html out
  if ! html=$(curl -ksSf --max-time 30 "$URL/?sfx_release_check=$RANDOM" 2>&1); then
    echo "inconclusive: fetch failed: $html" | head -n1
    return
  fi
  # the page travels inside the script; stdin here is that script, not the loop's
  if ! out=$({ printf '<?php\n$html = base64_decode("%s");\n$marker = base64_decode("%s");\n' "$(b64 "$html")" "$(b64 "$MARKER")"
               sed 1d "$ANALYZER"
               printf '\necho sfx_release_check_verdict($html, $marker), "\\n";\n'; } | ddev wp eval-file - 2>/dev/null); then
    echo "inconclusive: wp eval-file failed"
    return
  fi
  out=$(printf '%s\n' "$out" | tail -n1)
  case "$out" in
    present* | absent* | inconclusive:*) echo "$out" ;;
    *) echo "inconclusive: unexpected analyzer output: $out" ;;
  esac
}

check() { # $1 = label, $2 = "suppressed" when the setup is expected to publish no company data
  local off on mode verdict
  set_option_key "$SCHEMA_OPTION" enabled false
  off=$(analyze)
  set_option_key "$SCHEMA_OPTION" enabled true
  mode=$(wp eval 'echo \SFX\ContactInfos\Schema\Mode::detect()["mode"];')
  on=$(analyze)
  case "$off" in
    inconclusive:*) verdict="inconclusive - control fetch with the switch off: ${off#inconclusive: }" ;;
    present*) verdict="inconclusive - plugin already holds the marker (switch off)" ;;
    *)
      case "$on" in
        inconclusive:*) verdict="inconclusive - ${on#inconclusive: }" ;;
        present*) verdict=delivered; [ "$2" = suppressed ] && verdict="delivered (UNEXPECTED: this setup should be suppressed)" ;;
        *) verdict="not delivered"; [ "$2" = suppressed ] && verdict="deliberately suppressed (expected for this setup)" ;;
      esac
      ;;
  esac
  printf '%-34s mode=%-15s %s\n' "$1" "$mode" "$verdict"
  printf '%-34s   off: %s | on: %s\n' "" "$off" "$on"
}

only_active ""
check "no SEO plugin" ""
for p in $PLUGINS; do
  if ! wp plugin is-installed "$p" >/dev/null 2>&1; then
    printf '%-34s not installed - skipped\n' "$p"
    continue
  fi
  only_active "$p"
  if [ "$p" = autodescription ]; then
    set_option_key "$TSF_OPTION" knowledge_output 0; check "$p (knowledge output off)" ""
    set_option_key "$TSF_OPTION" knowledge_output 1; check "$p (knowledge output on)" suppressed
  else
    check "$p" ""
  fi
done
