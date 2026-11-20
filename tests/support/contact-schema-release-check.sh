#!/usr/bin/env bash
# Release check for the Contact Infos schema output (spec AC-6, C7). Manual, before `bin/release.sh publish`.
#   SFX_TEST_SITE=<site dir> SFX_TEST_URL=<https origin> bash tests/support/contact-schema-release-check.sh
# For each setup (no SEO plugin, Yoast, Rank Math, The SEO Framework with its knowledge output off and
# on) it activates exactly that plugin, fetches SFX_TEST_URL and prints one line:
#   delivered | deliberately suppressed (expected for this setup) | not delivered
# "delivered" means the organization's telephone from Contact Infos shows up in a JSON-LD string value (decoded,
# so the theme's &/</> escaping does not matter). A telephone, not the name: the SEO plugins emit the name themselves.
# It installs nothing: a plugin that is not installed is skipped. The theme's schema switch and the
# Contact Infos data are the site's own (switch off: the check says so and expects nothing). SFX_TEST_MARKER overrides the telephone.
# Teardown: one trap, declared before the first change, restores the active SEO plugins and TSF's option.
set -euo pipefail

SITE=${SFX_TEST_SITE:?set SFX_TEST_SITE to the test site directory}
URL=${SFX_TEST_URL:?set SFX_TEST_URL to the test site URL}
cd "$SITE" || { echo "cannot cd to $SITE" >&2; exit 1; }
# every guard that picks where we operate fails fatally: this must be a WordPress root
[ -f wp-config.php ] || [ -f wp-config-ddev.php ] || { echo "$SITE is not a WordPress directory" >&2; exit 1; }

PLUGINS="wordpress-seo seo-by-rank-math autodescription"
TSF_OPTION=autodescription-site-settings

# ddev swallows stdin, so every call inside a loop gets </dev/null
wp() { ddev wp "$@" </dev/null; }

INITIAL_ACTIVE=$(wp plugin list --status=active --field=name)
# serialize() keeps the option exactly as stored; "__absent__" marks an option that did not exist
INITIAL_TSF=$(wp eval 'echo base64_encode(serialize(get_option("'"$TSF_OPTION"'", "__absent__")));')

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
  wp eval '$v = unserialize(base64_decode("'"$INITIAL_TSF"'"), ["allowed_classes" => false]);
    if ($v === "__absent__") { delete_option("'"$TSF_OPTION"'"); } else { update_option("'"$TSF_OPTION"'", $v); }' >/dev/null 2>&1
  now=$(wp plugin list --status=active --field=name 2>/dev/null)
  if [ "$now" = "$INITIAL_ACTIVE" ]; then echo "restore: active plugins back to the initial list"; else echo "restore: ACTIVE PLUGIN LIST DIFFERS from the initial one" >&2; fi
}
trap restore EXIT

MARKER=${SFX_TEST_MARKER:-$(wp eval 'echo \SFX\ContactInfos\Schema\GraphBuilder::organization(\SFX\ContactInfos\Schema\Selection::resolve(), "Organization")["entity"]["telephone"] ?? "";' | tail -n1)}
[ -n "$MARKER" ] || { echo "the organization contact has no telephone, so nothing marks the theme's data; fill it in or set SFX_TEST_MARKER" >&2; exit 1; }
export MARKER
echo "organization telephone looked for: $MARKER"

only_active() { # $1 = plugin to leave active ("" = none)
  local p
  for p in $PLUGINS; do
    if [ "$p" = "$1" ]; then wp plugin activate "$p" >/dev/null; else wp plugin deactivate "$p" >/dev/null 2>&1 || true; fi
  done
}

tsf_knowledge() { # $1 = 0|1
  wp eval '$o = get_option("'"$TSF_OPTION"'", []); $o = is_array($o) ? $o : []; $o["knowledge_output"] = '"$1"'; update_option("'"$TSF_OPTION"'", $o);' >/dev/null
}

check() { # $1 = label, $2 = "suppressed" when the setup is expected to publish nothing
  local mode scripts verdict
  mode=$(wp eval 'echo \SFX\ContactInfos\Schema\Mode::detect()["mode"];')
  scripts=$(curl -ksS --max-time 30 "$URL/?sfx_release_check=$RANDOM" | perl -0777 -ne 'while (/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/gs) { (my $b = $1) =~ s/\s*\n\s*/ /g; print "$b\n" }')
  if [ "$mode" = off ]; then
    printf '%-34s mode=off: the schema switch is off, nothing expected (no verdict)\n' "$1"
    return
  fi
  # decode each script body and look for the telephone in any string value
  if printf '%s' "$scripts" | php -r '
      $m = getenv("MARKER"); $hit = false;
      $walk = function ($v) use (&$walk, $m, &$hit) {
        if (is_array($v)) { foreach ($v as $x) { $walk($x); } } elseif (is_string($v) && strpos($v, $m) !== false) { $hit = true; }
      };
      foreach (explode("\n", stream_get_contents(STDIN)) as $line) { $d = json_decode($line, true); if ($d !== null) { $walk($d); } }
      exit($hit ? 0 : 1);'; then
    verdict=delivered
    [ "$2" = suppressed ] && verdict="delivered (UNEXPECTED: this setup should be suppressed)"
  elif [ "$2" = suppressed ]; then
    verdict="deliberately suppressed (expected for this setup)"
  else
    verdict="not delivered"
  fi
  printf '%-34s mode=%-15s %s\n' "$1" "$mode" "$verdict"
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
    tsf_knowledge 0; check "$p (knowledge output off)" ""
    tsf_knowledge 1; check "$p (knowledge output on)" suppressed
  else
    check "$p" ""
  fi
done
