#!/usr/bin/env bash
# Live check of the Markdown module (inc/Markdown/) against a real DDEV test site:
# spec docs/superpowers/specs/2026-11-21-markdown-for-agents-design.md § Verification,
# "Verification (AC-12)" and "Abuse path (AC-5)".
#
# MANUAL. quality.sh does not run this. Refresh the site's theme copy first
# (bin/test-instance.sh refresh), then:
#
#   SFX_TEST_SITE=<ddev site dir> SFX_TEST_URL=<https origin> SFX_TEST_ADMIN=<admin login> \
#     bash tests/support/markdown-live-check.sh
#
# Order, per the AGENTS.md harness rule: fatal guards (they decide WHERE this runs and
# write nothing) -> a per-site run lock (a held lock is exit 2, nothing changed) ->
# snapshot of everything the run may change (a database error there is exit 2) -> the ONE
# teardown (an EXIT trap) -> only then writes. The teardown deletes the fixture posts, the 410
# rule (by its recorded ID and by the run's note) and the temporary
# Editor-role user, deactivates Rank Math if this run activated it, and puts every
# snapshotted option back byte for byte (deleting the ones that did not exist). It re-reads
# each of them afterwards and drops a cleanup record only once its removal is confirmed.
# Only when every item is confirmed does it remove the temp dir and release the lock; otherwise
# it keeps both (the original option snapshot and the cleanup records), prints where they are
# and exits 3, and a later run that finds the held lock prints the same. The editor and
# post-password cookies live only in a mode-700 temp dir, are never printed and are removed
# by the teardown in either case.
#
# Exit: 0 all passed, 1 a check failed or a required check did not run (NOT RUN),
# 2 guard, lock or fixture error, 3 teardown left state behind.
set -euo pipefail

fatal() { echo "FATAL: $*" >&2; exit 2; }
SITE=${SFX_TEST_SITE:?SFX_TEST_SITE (the DDEV site directory) is required}
URL=${SFX_TEST_URL:?SFX_TEST_URL (the site origin) is required}
URL=${URL%/}
ADMIN=${SFX_TEST_ADMIN:?SFX_TEST_ADMIN (an administrator login) is required}
CHECKOUT=$(cd "$(dirname "$0")/../.." && pwd -P) || fatal "cannot resolve the checkout"
THEME="$SITE/wp-content/themes/sfx-bricks-child"

# ddev reads stdin (it would swallow loop input) and must run from the site directory.
wp() { (cd "$SITE" || exit 97; ddev wp "$@") < /dev/null; }
wpe() { wp eval "$1"; }

# ---------------------------------------------------------------- guards (no writes)
[ -f "$SITE/wp-config.php" ] && [ -d "$SITE/.ddev" ] || fatal "$SITE is not a DDEV WordPress site"
[ "$(wp option get siteurl)" = "$URL" ] || fatal "siteurl of $SITE is not $URL"
[ "$(wp option get stylesheet)" = sfx-bricks-child ] || fatal "sfx-bricks-child is not the active theme"
diff -rq "$CHECKOUT/inc" "$THEME/inc" > /dev/null || fatal "the site's theme copy differs from this checkout; run bin/test-instance.sh refresh"
ADMIN_ID=$(wpe "\$u = get_user_by('login', '$ADMIN'); echo \$u && user_can(\$u, 'manage_options') ? \$u->ID : 0;")
[ "$ADMIN_ID" -gt 0 ] 2>/dev/null || fatal "$ADMIN is not an administrator"
[ "$(curl -ks -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || fatal "$URL/ does not answer 200"
wp plugin is-active wordpress-seo && fatal "Yoast is active; the module-off baselines assume it is not"
RM_WAS_ACTIVE=0; wp plugin is-active seo-by-rank-math && RM_WAS_ACTIVE=1
[ "$RM_WAS_ACTIVE" = 0 ] || fatal "Rank Math is already active; the plain baseline needs it inactive"
[ "$(wp plugin get seo-by-rank-math --field=version)" = 1.0.282 ] || fatal "Rank Math is not 1.0.282"
[ -d "$SITE/wp-content/uploads" ] || fatal "$SITE/wp-content/uploads is missing (the run lock lives there)"
echo "site $SITE ($URL), WP $(wp core version), theme $(wp theme get sfx-bricks-child --field=version), Bricks $(wp theme get bricks --field=version), checkout $(git -C "$CHECKOUT" rev-parse --short HEAD)"

# ---------------------------------------------------------------- per-site run lock
# Two overlapping runs would snapshot each other's temporary state and restore it as
# permanent. mkdir is atomic; the lock dir also carries files PHP must read (front.html).
RUN=r$(date +%s)$$$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
LOCK="$SITE/wp-content/uploads/sfx-md-live.lock"
mkdir "$LOCK" 2> /dev/null || fatal "another run holds $LOCK ($(cat "$LOCK/owner" 2> /dev/null || echo 'owner unknown')); $(cat "$LOCK/recovery" 2> /dev/null || echo 'if none is running, remove it by hand')"
TMP=
release() { rm -rf "$LOCK"; [ ! -e "$LOCK" ] || { echo "  FAILED to release the run lock $LOCK" >&2; return 1; }; [ -z "$TMP" ] || rm -rf "$TMP"; }
trap 'rc=$?; release || rc=3; exit $rc' EXIT # replaced by the teardown once the snapshot exists
trap 'exit 130' INT TERM
echo "$RUN" > "$LOCK/owner"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/sfx-md-live.XXXXXX") || fatal "mktemp failed"
chmod 700 "$TMP"
echo "if no run is active, its teardown did not finish: check its recovery data in $TMP, then remove $LOCK by hand" > "$LOCK/recovery"

# ---------------------------------------------------------------- snapshot
# Raw option rows (value and autoload), sorted by name, base64 JSON. Every option the run
# writes, plus everything Rank Math may create while it is active. Any database error exits 1
# before anything is printed: an unreadable row must never be taken for an absent one.
SNAPSHOT_FN='function sfx_md_db_ok($what) { global $wpdb; if ($wpdb->last_error !== "") { fwrite(STDERR, "database error ($what): $wpdb->last_error\n"); exit(1); } }
function sfx_md_snapshot() { global $wpdb;
$names = ["sfx_general_options","sfx_markdown_options","sfx_password_protected_options","sfx_redirects_options","bricks_global_settings","active_plugins","recently_activated","rewrite_rules",$wpdb->prefix."user_roles"];
$found = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE \"rank%\" OR option_name LIKE \"%rank\\_math%\" OR option_name LIKE \"%rank-math%\"");
sfx_md_db_ok("option names");
$names = array_merge($names, $found);
$out = [];
foreach (array_unique($names) as $n) {
  $r = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM $wpdb->options WHERE option_name = %s", $n));
  sfx_md_db_ok($n);
  $out[$n] = $r ? ["v" => base64_encode($r->option_value), "a" => $r->autoload] : null;
}
ksort($out);
return $out; }'
SNAPSHOT_PHP="$SNAPSHOT_FN echo base64_encode(json_encode(sfx_md_snapshot()));"
STATE_PHP='global $wpdb; $c = _get_cron_array(); $h = []; foreach ($c as $t => $hooks) { foreach ($hooks as $k => $e) { $h[$k] = 1; } } ksort($h);
echo "cron hooks: ", implode(",", array_keys($h)), "\n";
echo "redirect rules: ", (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfx_redirects"), ", 404 log rows: ", (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfx_redirects_404"), "\n";
echo "sessions of '"$ADMIN"': ", count((array) get_user_meta('"$ADMIN_ID"', "session_tokens", true)), ", users: ", count_users()["total_users"], "\n";
echo "rank_math tables: ", implode(",", $wpdb->get_col("SHOW TABLES LIKE \"%rank\\_math%\"")), "\n";'
wpe "$SNAPSHOT_PHP" > "$TMP/snapshot.b64" || fatal "the snapshot failed (see the database error above); nothing was changed"
[ -s "$TMP/snapshot.b64" ] || fatal "snapshot is empty"
wpe "$STATE_PHP" > "$TMP/state-before.txt"
echo "== snapshot: $(wpe "\$s = json_decode(base64_decode('$(cat "$TMP/snapshot.b64")'), true); foreach (\$s as \$n => \$r) echo \$n, \$r === null ? ' (absent)' : '', '; ';")"
cat "$TMP/state-before.txt"

# ---------------------------------------------------------------- the one teardown
teardown() {
  local rc=$? left=0
  set +e
  trap - EXIT
  echo
  echo "== teardown"
  # Each removal is re-read; the eval exits 1 when anything is still there.
  ids=$(awk '{print $2}' "$TMP/posts" 2>/dev/null | tr '\n' ',')
  wpe "global \$wpdb; \$ids = array_filter(array_map('intval', explode(',', '$ids')));
    \$ids = array_unique(array_merge(\$ids, array_map('intval', \$wpdb->get_col(\"SELECT ID FROM \$wpdb->posts WHERE post_title LIKE '%SFXMD % $RUN'\"))));
    \$bad = 0; foreach (\$ids as \$id) { if (get_post(\$id)) { wp_delete_post(\$id, true); clean_post_cache(\$id); }
      if (get_post(\$id)) { \$bad++; echo \"  FAILED to delete post \$id\n\"; } else { echo \"  post \$id gone\n\"; } } exit(\$bad ? 1 : 0);" || left=1
  # By the recorded ID and by this run's note: an interruption after the insert leaves rule_id empty.
  out=$(del_rule)
  case "$out" in
    none) ;;
    gone*) : > "$TMP/rule_id"; echo "  redirect rule ${out#gone } gone" ;;
    *) echo "  FAILED to delete the redirect rule ($(cat "$TMP/rule_id" 2>/dev/null), note 'sfxmd $RUN'): $out"; left=1 ;;
  esac
  wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; \$ids = array_filter(array_map('intval', [$(cat "$TMP/editor_id" 2>/dev/null)]));
    \$u = get_user_by('login', 'sfxmd-editor-$RUN'); if (\$u) { \$ids[] = \$u->ID; }
    \$bad = 0; foreach (array_unique(\$ids) as \$id) { if (get_userdata(\$id)) { wp_delete_user(\$id); clean_user_cache(\$id); }
      if (get_userdata(\$id)) { \$bad++; echo \"  FAILED to delete the editor user \$id\n\"; } else { echo \"  editor user \$id gone (with its session)\n\"; } } exit(\$bad ? 1 : 0);" || left=1
  if [ -f "$TMP/rm_activated" ]; then
    wp plugin deactivate seo-by-rank-math || left=1
    if wp plugin is-active seo-by-rank-math; then echo "  FAILED to deactivate Rank Math"; left=1; else rm -f "$TMP/rm_activated"; fi
    wp rewrite flush || left=1
  fi
  # Every snapshotted row back byte for byte; a row absent before is deleted; a Rank Math
  # row the snapshot does not hold was created during the run and is deleted.
  # Any database error marks the cleanup unverified (exit 1), which keeps the recovery data.
  wpe "global \$wpdb; \$s = json_decode(base64_decode('$(cat "$TMP/snapshot.b64")'), true); \$bad = 0;
    \$now = \$wpdb->get_col(\"SELECT option_name FROM \$wpdb->options WHERE option_name LIKE 'rank%' OR option_name LIKE '%rank\\\\_math%' OR option_name LIKE '%rank-math%'\");
    if (\$wpdb->last_error !== '') { \$bad++; echo \"  FAILED to list the Rank Math rows: \$wpdb->last_error\n\"; }
    foreach (\$now as \$n) { if (!array_key_exists(\$n, \$s)) { \$s[\$n] = null; } }
    foreach (\$s as \$n => \$r) {
      \$row = ['option_value' => base64_decode(\$r['v'] ?? ''), 'autoload' => \$r['a'] ?? ''];
      \$here = \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$wpdb->options WHERE option_name = %s\", \$n));
      if (\$wpdb->last_error !== '') { \$bad++; echo \"  FAILED to read \$n: \$wpdb->last_error\n\"; continue; }
      if (\$r === null) { \$done = \$wpdb->delete(\$wpdb->options, ['option_name' => \$n]); }
      elseif (\$here) { \$done = \$wpdb->update(\$wpdb->options, \$row, ['option_name' => \$n]); }
      else { \$done = \$wpdb->insert(\$wpdb->options, ['option_name' => \$n] + \$row); }
      if (\$done === false || \$wpdb->last_error !== '') { \$bad++; echo \"  FAILED to restore \$n: \$wpdb->last_error\n\"; }
    }
    echo '  restored ', count(\$s) - \$bad, ' of ', count(\$s), \" option rows\n\"; exit(\$bad ? 1 : 0);" || left=1
  # Rank Math's recurring cron events: a wp-cron runner the run's requests spawned can write
  # one back after it was cleared, so clear until no runner holds the lock and none is left.
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16; do
    out=$(wpe "\$h = []; foreach (_get_cron_array() as \$t => \$hooks) { foreach (\$hooks as \$k => \$e) { \$h[\$k] = 1; } }
      foreach (array_keys(\$h) as \$k) { if (preg_match('/rank_math|action_scheduler/i', \$k) && strpos('$(head -1 "$TMP/state-before.txt")', \$k) === false) { wp_unschedule_hook(\$k); echo \"cleared \$k \"; } }
      echo get_transient('doing_cron') ? 'runner' : '';") || { left=1; break; }
    [ -n "$out" ] && echo "  cron: $out"
    [ -z "$out" ] && break
    sleep 5
  done
  wp cache flush > /dev/null || left=1

  echo "== restoration proof"
  wpe "$SNAPSHOT_FN \$was = json_decode(base64_decode('$(cat "$TMP/snapshot.b64")'), true); \$now = sfx_md_snapshot();
    foreach (array_unique(array_merge(array_keys(\$was), array_keys(\$now))) as \$n) { echo (\$was[\$n] ?? 'x') === (\$now[\$n] ?? 'x') ? '  same    ' : '  CHANGED ', \$n, (\$was[\$n] ?? null) === null ? ' (absent before)' : '', (\$now[\$n] ?? null) === null ? ' (absent now)' : '', \"\n\"; }" > "$TMP/proof.txt" || left=1
  cat "$TMP/proof.txt"
  grep -q CHANGED "$TMP/proof.txt" && left=1
  wpe "$STATE_PHP" > "$TMP/state-after.txt" || left=1
  diff "$TMP/state-before.txt" "$TMP/state-after.txt" > /dev/null && echo "  cron hooks, redirect rules, 404 log, sessions, tables: same as before" \
    || { echo "  state differs:"; diff "$TMP/state-before.txt" "$TMP/state-after.txt"; left=1; }
  posts_left=$(wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM \$wpdb->posts WHERE post_title LIKE '%SFXMD %' OR post_content LIKE '%SFXMD-%'\");")
  echo "  fixture posts left (any status, any run): $posts_left"
  [ "$posts_left" = 0 ] || left=1
  echo "  Rank Math active: $(wp plugin is-active seo-by-rank-math && echo yes || echo no) (before: $([ "$RM_WAS_ACTIVE" = 1 ] && echo yes || echo no))"
  rm -f "$TMP/editor.curl" "$TMP/postpass.jar" "$LOCK/front.html" # credentials and a page copy, not recovery data
  echo "== result: $PASSES PASS, $FAILS FAIL, $NOTRUN NOT RUN"
  if [ "$left" = 0 ]; then release || left=1; fi
  if [ "$left" != 0 ]; then
    # Unverified cleanup: keep the snapshot, the records and the lock, so nothing snapshots the damaged site.
    echo "its teardown LEFT STATE BEHIND: recovery data in $TMP (snapshot.b64, posts, rule_id, editor_id, rm_activated, proof.txt); restore by hand, then remove $TMP and $LOCK" > "$LOCK/recovery"
    echo "TEARDOWN LEFT STATE BEHIND. Kept: snapshot $TMP/snapshot.b64, cleanup records in $TMP, lock $LOCK" >&2
    echo "Recovery: put back the option rows in snapshot.b64 (base64 JSON: name -> base64 value + autoload, null = absent), remove what posts/rule_id/editor_id/rm_activated name, then rm -rf $TMP $LOCK" >&2
    exit 3
  fi
  if [ "$rc" != 0 ]; then exit "$rc"; fi
  [ "$FAILS" = 0 ] || exit 1
  [ "$NOTRUN" = 0 ] || { echo "A required check did not run" >&2; exit 1; }
  exit 0
}
# del_rule: deletes this run's 410 rules, found by the recorded ID and by the run's note. Prints
# "none" when there is none, "gone <ids>" only when a re-read finds every one missing and no rule
# with the note left; anything else (a database error included) is a failure.
del_rule() {
  wpe "global \$wpdb; \$t = \SFX\Redirects\Repository::table(); \$note = 'sfxmd $RUN';
    \$ids = \$wpdb->get_col(\$wpdb->prepare(\"SELECT id FROM \$t WHERE note = %s\", \$note));
    if (\$wpdb->last_error !== '') { echo 'left (lookup by note failed: ', \$wpdb->last_error, ')'; exit(1); }
    \$ids = array_values(array_unique(array_filter(array_map('intval', array_merge(\$ids, ['$(cat "$TMP/rule_id" 2>/dev/null)'])))));
    if (!\$ids) { echo 'none'; exit(0); }
    \$r = \SFX\Redirects\Repository::apply_op('delete', \$ids); \$left = [];
    foreach (\$ids as \$id) { \$g = \SFX\Redirects\Repository::get(\$id)['status']; if (\$g !== 'missing') { \$left[] = \"\$id (get \$g)\"; } }
    \$n = (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE note = %s\", \$note));
    if (\$wpdb->last_error !== '') { \$left[] = 'recount failed: ' . \$wpdb->last_error; } elseif (\$n) { \$left[] = \"\$n with the note\"; }
    echo \$left ? \"left (done {\$r['done']}, failed {\$r['failed']}, locked \" . (int) \$r['locked'] . ', ' . implode(', ', \$left) . ')' : 'gone ' . implode(',', \$ids);"
}
PASSES=0; FAILS=0; NOTRUN=0
trap teardown EXIT
trap 'exit 130' INT TERM

# ---------------------------------------------------------------- helpers
pass() { PASSES=$((PASSES + 1)); echo "PASS  $1"; }
fail() { FAILS=$((FAILS + 1)); echo "FAIL  $1"; }
notrun() { NOTRUN=$((NOTRUN + 1)); echo "NOT RUN  $1"; }
eq() { if [ "$2" = "$3" ]; then pass "$1: '$2'"; else fail "$1: got '$2', want '$3'"; fi; }
ne() { if [ "$2" != "$3" ]; then pass "$1: '$2' is not '$3'"; else fail "$1: got '$2', must differ"; fi; }
contains() { case "$2" in *"$3"*) pass "$1: '$2' holds '$3'" ;; *) fail "$1: '$2' lacks '$3'" ;; esac; }
lacks() { case "$2" in *"$3"*) fail "$1: '$2' holds '$3'" ;; *) pass "$1: no '$3'" ;; esac; }
has() { if grep -qF -- "$3" "$TMP/$2.b"; then pass "$1: body of $2 holds '$3'"; else fail "$1: body of $2 lacks '$3'"; fi; }
hasnt() { if grep -qF -- "$3" "$TMP/$2.b"; then fail "$1: body of $2 holds '$3'"; else pass "$1: body of $2 has no '$3'"; fi; }

# req <name> <url> [curl args]: status, headers and body under $TMP/<name>.*
req() {
  local name=$1 url=$2; shift 2
  curl -ks --max-time 60 -o "$TMP/$name.b" -D "$TMP/$name.h" -w '%{http_code}' "$@" "$url" > "$TMP/$name.s" || echo 000 > "$TMP/$name.s"
  echo "  $name: $* $url -> $(cat "$TMP/$name.s") [$(hd "$name" content-type)]"
}
st() { cat "$TMP/$1.s"; }
hd() { tr -d '\r' < "$TMP/$1.h" | awk -v h="$2" 'index($0, ":") { n = tolower(substr($0, 1, index($0, ":") - 1)); if (n == h) { v0 = substr($0, index($0, ":") + 1); sub(/^ */, "", v0); v = v ? v ", " v0 : v0 } } END { print v }'; }
loc() { hd "$1" location | sed -e 's/[?&]markdown=1$//' -e 's/markdown=1&//'; }
sha() { shasum -a 256 < "$TMP/$1.b" | cut -c1-16; }
excerpt() { head -c 400 "$TMP/$1.b" | head -6 | sed 's/^/    | /'; echo; }
module() { wpe "\$o = (array) get_option('sfx_general_options', []); \$o['enable_markdown'] = $1; update_option('sfx_general_options', \$o);"; echo "  (module $([ "$1" = 1 ] && echo on || echo off))"; }
BROWSER='Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
MD='Accept: text/markdown'
HEADLINK='type="text/markdown"'

# ---------------------------------------------------------------- fixtures
module 1
wpe "\$o = (array) get_option('sfx_redirects_options', []); \$o['log_404'] = false; update_option('sfx_redirects_options', \$o);"
echo "  (Redirects 404 log paused for the run: anonymous fixture requests would log 404s)"
wpe "wp_set_current_user($ADMIN_ID); \$m = '$RUN';
  \$mk = function (\$kind, \$args) use (\$m) {
    \$id = wp_insert_post(\$args + ['post_type' => 'post', 'post_title' => \"SFXMD \$kind \$m\", 'post_name' => \"sfxmd-\$kind-\$m\",
      'post_content' => \"<!-- wp:paragraph --><p>SFXMD-SECRET-\$kind-\$m</p><!-- /wp:paragraph -->\"], true);
    if (is_wp_error(\$id)) { fwrite(STDERR, \$id->get_error_message()); exit(1); }
    echo \"\$kind \$id\n\"; return \$id; };
  \$mk('draft', ['post_status' => 'draft']);
  \$mk('pending', ['post_status' => 'pending']);
  \$mk('future', ['post_status' => 'future', 'post_date' => wp_date('Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS)]);
  \$mk('private', ['post_status' => 'private']);
  wp_trash_post(\$mk('trash', ['post_status' => 'draft']));
  \$mk('password', ['post_status' => 'publish', 'post_password' => 'sfxmd-pw-$RUN']);
  \$mk('paged', ['post_status' => 'publish', 'post_content' => \"<!-- wp:paragraph --><p>SFXMD-PART1-\$m</p><!-- /wp:paragraph --><!--nextpage--><!-- wp:paragraph --><p>SFXMD-PART2-\$m</p><!-- /wp:paragraph -->\"]);" > "$TMP/posts"
pid() { awk -v k="$1" '$1 == k { print $2 }' "$TMP/posts"; }
for k in draft pending future private trash password paged; do [ -n "$(pid $k)" ] || fatal "fixture $k missing"; done
echo "  fixtures: $(tr '\n' ' ' < "$TMP/posts")(statuses: $(wpe "foreach ([$(awk '{print $2}' "$TMP/posts" | paste -sd, -)] as \$i) echo get_post_status(\$i), ' ';"))"
FRONT="$URL/"
P1120="$URL/editor-prose-test/"
url_of() { wpe "echo get_permalink($1);"; }
PAGED=$(url_of "$(pid paged)")
PRIV="$URL/sfxmd-private-$RUN/" # get_permalink() gives ?p= for a private post
PW=$(url_of "$(pid password)")
TPL=$(url_of 1121)
echo "  urls: front $FRONT, 1120 $P1120, paged $PAGED, private $PRIV, password $PW, bricks_template $TPL"

# ================================================================ AC-12 verification
echo
echo "== AC-12: front page (Bricks) and post 1120 (block editor), module on, no SEO plugin"
req llms "$URL/llms.txt"
excerpt llms
for page in front:$FRONT p1120:$P1120; do
  n=${page%%:*} u=${page#*:}
  req "$n-html" "$u" -H "$BROWSER"
  req "$n-md" "$u?markdown=1"
  req "$n-acc" "$u" -H "$MD"
  excerpt "$n-md"
  eq "$n ?markdown=1 status" "$(st $n-md)" 200
  eq "$n ?markdown=1 content-type" "$(hd $n-md content-type)" "text/markdown; charset=UTF-8"
  contains "$n ?markdown=1 Vary" "$(hd $n-md vary)" Accept
  eq "$n ?markdown=1 Cache-Control" "$(hd $n-md cache-control)" "private, no-store, max-age=0"
  eq "$n ?markdown=1 X-Robots-Tag" "$(hd $n-md x-robots-tag)" noindex
  contains "$n ?markdown=1 Link" "$(hd $n-md link)" "<$u>; rel=\"canonical\""
  hasnt "$n ?markdown=1 is not HTML" "$n-md" "<html"
  has "$n HTML head link" "$n-html" "<link rel=\"alternate\" type=\"text/markdown\" href=\"$u?markdown=1\" />"
  eq "$n Accept: text/markdown content-type" "$(hd $n-acc content-type)" "text/markdown; charset=UTF-8"
  eq "$n Accept: text/markdown body = ?markdown=1 body" "$(sha $n-acc)" "$(sha $n-md)"
  eq "$n browser Accept content-type" "$(hd $n-html content-type)" "text/html; charset=UTF-8"
  contains "$n browser Accept Vary" "$(hd $n-html vary)" Accept
  has "$n llms.txt entry" llms "(<$u?markdown=1>)"
done
has "front ?markdown=1 holds a heading" front-md "#"
# Positive control for the gate checks below: MARK1120 is in 1120's ordinary Markdown.
MARK1120="Erster Absatz mit einem"
has "1120 ?markdown=1 holds its first paragraph" p1120-md "$MARK1120 [Link]"
has "1120 ?markdown=1 holds the content marker (control)" p1120-md "$MARK1120"

echo
echo "== AC-12: Bricks lazy-loaded images (front page)"
# Read with WordPress' full HTML parser (AGENTS.md: no regex on HTML), inside the site: the body
# goes through the lock dir, whose owner file proves PHP reads this run's copy. The first IMG
# inside the brx-content element, outside every subtree the converter drops (the same rules as
# Converter::dropped), whose src is empty or a data: placeholder and whose data-src is set;
# prints the Markdown destination the converter writes for it.
cp "$TMP/front-html.b" "$LOCK/front.html"
dest=$(wpe "\$d = wp_upload_dir()['basedir'] . '/sfx-md-live.lock/';
  if (trim((string) @file_get_contents(\$d . 'owner')) !== '$RUN') { echo 'UNREADABLE'; return; }
  \$drop = ['SCRIPT','STYLE','NOSCRIPT','TEMPLATE','SVG','MATH','IFRAME','FORM','SELECT','TEXTAREA','BUTTON','NAV','VIDEO','AUDIO','CANVAS','OBJECT','EMBED','DIALOG'];
  \$p = WP_HTML_Processor::create_full_parser((string) file_get_contents(\$d . 'front.html')); if (!\$p) { echo 'UNPARSED'; return; }
  \$root = null; \$skip = null;
  try { while (\$p->next_token()) {
    if (\$p->get_token_type() !== '#tag') { continue; }
    \$depth = \$p->get_current_depth();
    if (\$p->is_tag_closer()) {
      if (\$skip !== null && \$depth < \$skip) { \$skip = null; }
      if (\$skip === null && \$root !== null && \$depth < \$root) { break; }
      continue; }
    if (\$skip !== null) { continue; }
    \$tag = \$p->get_tag(); \$id = \$p->get_attribute('id'); \$aria = \$p->get_attribute('aria-hidden'); \$role = \$p->get_attribute('role');
    if (in_array(\$tag, \$drop, true) || \$p->get_attribute('hidden') !== null || \$id === 'brx-header' || \$id === 'brx-footer'
      || (is_string(\$aria) && strtolower(trim(\$aria)) === 'true')
      || (is_string(\$role) && in_array('navigation', preg_split('/\s+/', strtolower(\$role)), true))
      || \$p->has_class('bricks-pagination') === true) {
      if (\$p->expects_closer()) { \$skip = \$depth; } continue; }
    if (\$root === null) { if (\$id === 'brx-content' && \$p->expects_closer()) { \$root = \$depth; } continue; }
    if (\$tag !== 'IMG') { continue; }
    \$src = trim((string) \$p->get_attribute('src')); \$lazy = \$p->get_attribute('data-src');
    if ((\$src === '' || stripos(\$src, 'data:') === 0) && is_string(\$lazy) && trim(\$lazy) !== '') {
      \$u = \SFX\Markdown\Url::resolve(\$lazy, '$FRONT', 'image'); echo \$u === null ? 'UNSAFE' : \SFX\Markdown\Escape::destination(\$u); return; } }
  } catch (WP_HTML_Unsupported_Exception \$e) { echo 'UNPARSED'; return; }
  echo \$p->get_last_error() !== null ? 'UNPARSED' : 'NONE';")
rm -f "$LOCK/front.html"
case "$dest" in
  UNREADABLE) notrun "lazy images: PHP could not read this run's copy of the front page" ;;
  UNPARSED) notrun "lazy images: WordPress' HTML parser could not read the front page with certainty" ;;
  NONE) notrun "lazy images: no visible lazy image (src placeholder + data-src) inside brx-content" ;;
  UNSAFE) notrun "lazy images: the first lazy image has a URL the converter drops" ;;
  *)
    pass "front page has a visible lazy image inside brx-content: $dest"
    has "lazy image: Markdown uses the data-src address" front-md "]($dest)"
    hasnt "lazy image: no data: placeholder in Markdown" front-md "](<data:" ;;
esac

echo
echo "== AC-12: paginated post, part 2"
req paged2-md "${PAGED}2/?markdown=1"
req paged2-html "${PAGED}2/"
excerpt paged2-md
eq "paged part 2 content-type" "$(hd paged2-md content-type)" "text/markdown; charset=UTF-8"
has "paged part 2 holds part 2" paged2-md "SFXMD-PART2-$RUN"
hasnt "paged part 2 lacks part 1" paged2-md "SFXMD-PART1-$RUN"
contains "paged part 2 Link canonical" "$(hd paged2-md link)" "<${PAGED}2/>; rel=\"canonical\""
has "paged part 2 head link" paged2-html "href=\"${PAGED}2/?markdown=1\""

echo
echo "== AC-12: HEAD gives the GET headers"
req head-md "$P1120?markdown=1" -I
req head-acc "$P1120" -I -H "$MD"
for n in md acc; do
  get=p1120-$n
  eq "HEAD $n status" "$(st head-$n)" "$(st $get)"
  for h in content-type vary cache-control x-robots-tag link; do eq "HEAD $n $h" "$(hd head-$n $h)" "$(hd $get $h)"; done
done

echo
echo "== AC-12: Editor-role session on a private post and a draft preview"
# A temporary Editor-role user (teardown deletes it, and its session with it; it also finds it by login).
wpe "\$id = wp_insert_user(['user_login' => 'sfxmd-editor-$RUN', 'user_email' => 'sfxmd-editor-$RUN@example.invalid', 'user_pass' => wp_generate_password(32), 'role' => 'editor']);
  if (is_wp_error(\$id)) { fwrite(STDERR, \$id->get_error_message()); exit(1); } echo \$id;" > "$TMP/editor_id" || fatal "editor user not created"
EDITOR_ID=$(cat "$TMP/editor_id")
pre=$(wpe "\$u = get_userdata((int) '$EDITOR_ID'); echo implode(',', \$u->roles), ' read_private_posts=', (int) user_can(\$u, 'read_private_posts'), ' edit_others_posts=', (int) user_can(\$u, 'edit_others_posts'), ' manage_options=', (int) user_can(\$u, 'manage_options');")
echo "  editor user $EDITOR_ID: $pre"
[ "$pre" = "editor read_private_posts=1 edit_others_posts=1 manage_options=0" ] || fatal "the temporary user is not a plain Editor: $pre"
( umask 077; wpe "\$c = wp_generate_auth_cookie((int) '$EDITOR_ID', time() + HOUR_IN_SECONDS, 'logged_in');
  echo 'header = \"Cookie: ', LOGGED_IN_COOKIE, '=', rawurlencode(\$c), '\"';" > "$TMP/editor.curl" )
[ -s "$TMP/editor.curl" ] || fatal "editor cookie not built"
echo "  (cookie written to the temp dir, not printed)"
DRAFT_PREVIEW="$URL/?p=$(pid draft)&preview=true"
req ed-priv-html "$PRIV" -K "$TMP/editor.curl"
req ed-priv-md "$PRIV?markdown=1" -K "$TMP/editor.curl"
req ed-prev-html "$DRAFT_PREVIEW" -K "$TMP/editor.curl"
req ed-prev-md "$DRAFT_PREVIEW&markdown=1" -K "$TMP/editor.curl"
for n in ed-priv-html ed-priv-md ed-prev-html ed-prev-md; do
  eq "$n status" "$(st $n)" 200
  eq "$n content-type" "$(hd $n content-type)" "text/html; charset=UTF-8"
  hasnt "$n no head link" "$n" "$HEADLINK"
done
has "editor sees the private post (cookie works)" ed-priv-html "SFXMD-SECRET-private-$RUN"
has "editor sees the draft preview" ed-prev-html "SFXMD-SECRET-draft-$RUN"

# ================================================================ AC-5 abuse path
echo
echo "== AC-5: anonymous requests for non-public posts, module off (baseline) then on"
CASES="draft:$URL/?p=$(pid draft) pending:$URL/?p=$(pid pending) future:$URL/?p=$(pid future) private:$URL/?p=$(pid private) private-slug:$PRIV trash:$URL/?p=$(pid trash) bricks_template:$TPL"
q() { case "$1" in *\?*) echo "$1&markdown=1" ;; *) echo "$1?markdown=1" ;; esac; }
for state in 0 1; do
  module "$state"
  for c in $CASES; do
    n=${c%%:*} u=${c#*:}
    req "ab$state-$n-html" "$u"
    req "ab$state-$n-md" "$(q "$u")"
    req "ab$state-$n-acc" "$u" -H "$MD"
  done
done
for c in $CASES; do
  n=${c%%:*} k=${n%-slug}
  for w in md acc; do
    eq "$n $w status = HTML status" "$(st ab1-$n-$w)" "$(st ab1-$n-html)"
    eq "$n $w status = module-off status" "$(st ab1-$n-$w)" "$(st ab0-$n-$w)"
    eq "$n $w Location = HTML Location" "$(loc ab1-$n-$w)" "$(loc ab1-$n-html)"
    eq "$n $w Location = module-off Location" "$(loc ab1-$n-$w)" "$(loc ab0-$n-$w)"
    lacks "$n $w not Markdown" "$(hd ab1-$n-$w content-type)" markdown
  done
  for w in html md acc; do
    [ "$k" = bricks_template ] || hasnt "$n $w no content" "ab1-$n-$w" "SFXMD-SECRET-$k-$RUN"
    hasnt "$n $w no head link" "ab1-$n-$w" "$HEADLINK"
  done
done
module 1
req llms2 "$URL/llms.txt"
for k in draft pending future private trash password; do
  hasnt "llms.txt does not list $k" llms2 "sfxmd-$k-$RUN"
  hasnt "llms.txt does not name $k" llms2 "SFXMD $k $RUN"
done
hasnt "llms.txt does not list the bricks_template" llms2 "$TPL"
has "llms.txt lists the published paged fixture (control)" llms2 "sfxmd-paged-$RUN"

echo
echo "== AC-5: password-protected post"
req pw-html "$PW"
req pw-md "$PW?markdown=1"
req pw-acc "$PW" -H "$MD"
for n in pw-html pw-md pw-acc; do
  eq "$n status" "$(st $n)" 200
  eq "$n content-type" "$(hd $n content-type)" "text/html; charset=UTF-8"
  has "$n shows the password form" "$n" 'name="post_password"'
  hasnt "$n no content" "$n" "SFXMD-SECRET-password-$RUN"
  hasnt "$n no head link" "$n" "$HEADLINK"
done
( umask 077; curl -ks -o /dev/null -c "$TMP/postpass.jar" --data-urlencode "post_password=sfxmd-pw-$RUN" "$URL/wp-login.php?action=postpass" )
grep -q wp-postpass_ "$TMP/postpass.jar" || fatal "no post-password cookie from wp-login.php?action=postpass"
echo "  (post-password cookie in the temp dir, not printed)"
req pwc-md "$PW?markdown=1" -b "$TMP/postpass.jar"
req pwc-acc "$PW" -H "$MD" -b "$TMP/postpass.jar"
for n in pwc-md pwc-acc; do
  eq "$n with cookie: content-type" "$(hd $n content-type)" "text/markdown; charset=UTF-8"
  has "$n with cookie: content" "$n" "SFXMD-SECRET-password-$RUN"
done
req pwa-md "$PW?markdown=1"
eq "after the cookie, without it: content-type" "$(hd pwa-md content-type)" "text/html; charset=UTF-8"
has "after the cookie, without it: form again" pwa-md 'name="post_password"'
hasnt "after the cookie, without it: no content" pwa-md "SFXMD-SECRET-password-$RUN"

# gate_block <label> <setup>: the site-wide gates, each in the current plugin setup.
gate_block() {
  local g=$1 setup=$2 n
  for state in 0 1; do
    module "$state"
    req "$g$setup$state-html" "$P1120"
    req "$g$setup$state-md" "$P1120?markdown=1"
    req "$g$setup$state-acc" "$P1120" -H "$MD"
    req "$g$setup$state-llms" "$URL/llms.txt"
    req "$g$setup$state-robots" "$URL/llms.txt?robots=1"
    req "$g$setup$state-feed" "$URL/llms.txt?feed=rss2"
  done
  for n in md acc llms; do
    if [ "$g" = pp ]; then
      eq "$g/$setup $n status (login redirect)" "$(st $g${setup}1-$n)" 302
      contains "$g/$setup $n Location" "$(hd $g${setup}1-$n location)" "sfx-protected=login"
    else
      eq "$g/$setup $n status (maintenance)" "$(st $g${setup}1-$n)" 503
      eq "$g/$setup $n = HTML body (maintenance page)" "$(sha $g${setup}1-$n)" "$(sha $g${setup}1-html)"
    fi
    eq "$g/$setup $n = module-off status" "$(st $g${setup}1-$n)" "$(st $g${setup}0-$n)"
    lacks "$g/$setup $n not Markdown" "$(hd $g${setup}1-$n content-type)" markdown
    eq "$g/$setup $n no llms marker" "$(hd $g${setup}1-$n x-sfx-llms-txt)" ""
    hasnt "$g/$setup $n no page content" "$g${setup}1-$n" "$MARK1120"
    hasnt "$g/$setup $n no index lines" "$g${setup}1-$n" "?markdown=1>)"
  done
  for n in robots feed; do
    excerpt "$g${setup}1-$n"
    eq "$g/$setup llms.txt?$n status = module off" "$(st $g${setup}1-$n)" "$(st $g${setup}0-$n)"
    eq "$g/$setup llms.txt?$n content-type = module off" "$(hd $g${setup}1-$n content-type)" "$(hd $g${setup}0-$n content-type)"
    eq "$g/$setup llms.txt?$n Location = module off" "$(hd $g${setup}1-$n location)" "$(hd $g${setup}0-$n location)"
    eq "$g/$setup llms.txt?$n body = module off" "$(sha $g${setup}1-$n)" "$(sha $g${setup}0-$n)"
    eq "$g/$setup llms.txt?$n no marker" "$(hd $g${setup}1-$n x-sfx-llms-txt)" ""
  done
  module 1
}
pp_on() { wpe "\$o = (array) get_option('sfx_password_protected_options', []); \$o['status'] = true; \$o['password'] = wp_hash_password('sfxmd-site-$RUN');
  update_option('sfx_password_protected_options', \$o); \$g = (array) get_option('sfx_general_options'); \$g['enable_password_protected'] = 1; update_option('sfx_general_options', \$g);"; echo "  (site-wide password protection on)"; }
pp_off() { wpe "\$o = (array) get_option('sfx_password_protected_options', []); \$o['status'] = false; update_option('sfx_password_protected_options', \$o);"; echo "  (site-wide password protection off)"; }
mm_on() { wpe "\$s = (array) get_option('bricks_global_settings', []); \$s['maintenanceMode'] = 'maintenance'; update_option('bricks_global_settings', \$s);"; echo "  (Bricks maintenance mode on)"; }
mm_off() { wpe "\$s = (array) get_option('bricks_global_settings', []); unset(\$s['maintenanceMode']); update_option('bricks_global_settings', \$s);"; echo "  (Bricks maintenance mode off)"; }

echo
echo "== AC-5: site-wide password protection, no SEO plugin"
pp_on; gate_block pp plain; pp_off
echo
echo "== AC-5: Bricks maintenance mode, no SEO plugin"
mm_on; gate_block mm plain; mm_off


echo
echo "== AC-12 / AC-5 with Rank Math 1.0.282 and its llms module on"
touch "$TMP/rm_activated"
wp plugin activate seo-by-rank-math
wpe "\$m = (array) get_option('rank_math_modules', []); if (!in_array('llms-txt', \$m, true)) { \$m[] = 'llms-txt'; } update_option('rank_math_modules', \$m);"
wp rewrite flush
req rm-on "$URL/llms.txt"
excerpt rm-on
eq "Rank Math on, module on: /llms.txt status" "$(st rm-on)" 200
eq "Rank Math on, module on: theme's marker" "$(hd rm-on x-sfx-llms-txt)" 1
has "Rank Math on, module on: theme's index lists 1120" rm-on "(<$P1120?markdown=1>)"
module 0
req rm-off "$URL/llms.txt"
excerpt rm-off
eq "Rank Math on, module off: /llms.txt status" "$(st rm-off)" 200
eq "Rank Math on, module off: no theme marker" "$(hd rm-off x-sfx-llms-txt)" ""
hasnt "Rank Math on, module off: not the theme's index" rm-off "?markdown=1>)"
ne "Rank Math on, module off: body differs from the theme's" "$(sha rm-off)" "$(sha rm-on)"
module 1
echo
echo "== AC-5: site-wide password protection, Rank Math llms on"
pp_on; gate_block pp rankmath; pp_off
echo
echo "== AC-5: Bricks maintenance mode, Rank Math llms on"
mm_on; gate_block mm rankmath; mm_off
echo
echo "== AC-5: Redirects 410 rule on /llms.txt"
wp plugin deactivate seo-by-rank-math # the 410 scenario ran without Rank Math before; teardown deactivates again
wpe "\$v = \SFX\Redirects\Rule::validate(['source' => '/llms.txt', 'match_type' => 'exact', 'target' => '', 'status_code' => '410', 'enabled' => true, 'note' => 'sfxmd $RUN']);
  if (\$v['errors']) { fwrite(STDERR, implode('; ', \$v['errors'])); exit(1); }
  \$s = \SFX\Redirects\Repository::save(\$v['rule'], 0, 'manual'); if ((\$s['status'] ?? '') !== 'created') { fwrite(STDERR, print_r(\$s, true)); exit(1); } echo \$s['id'];" > "$TMP/rule_id"
req r410 "$URL/llms.txt"
eq "410 rule: /llms.txt status" "$(st r410)" 410
eq "410 rule: no llms marker" "$(hd r410 x-sfx-llms-txt)" ""
# Teardown (the EXIT trap) restores everything and prints the proof.
