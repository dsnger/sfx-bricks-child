#!/usr/bin/env bash
# SFX Block: test-instance 1.2.0 sha256:5634d8323d4461b75aab82052ac1357a2c54e3121e1390c5406d84d827965f81.
# Copies this plugin or theme into a local, single-user WordPress test site and takes it out
# again (SFX standard STD-TST-06). Never for a shared or production site.
#   bin/test-instance.sh install [--full] [--activate] [--site <path>]
#   bin/test-instance.sh refresh [--full] [--activate] [--site <path>]
#   bin/test-instance.sh remove [--deactivate] [--site <path>]
# The site comes from --site or SFX_TEST_SITE, WP-CLI from SFX_TEST_WP (default: wp), split
# into words and run without a shell; its --path is SFX_TEST_WP_PATH when set, else the
# site. The copy is <site>/wp-content/plugins/<SLUG> (themes/<SLUG> for KIND=theme), owned
# through its .sfx-test-instance file; the script's own folders beside it all start with
# .<SLUG>.test-instance. After install and refresh, SFX_TEST_URL (a bare origin) gets a site
# check and SFX_TEST_ADMIN (a user login) an admin check; a failed check exits 3. Both are
# environment variables, never conf keys; the slug, kind and shipped entries come from
# bin/plugin.conf. This file is the canonical test-instance block: a plugin or theme
# never edits it; adopt-block places a later version.
set -u
START=$PWD
cd "$(dirname "$0")/.." || exit 1
CHECKOUT=$(pwd -P)

die() { echo "test-instance: $*" >&2; exit 1; }
usage() { echo "test-instance: usage: bin/test-instance.sh install|refresh [--full] [--activate] | remove [--deactivate]; [--site <path>]" >&2; exit 2; }

# shortcut: SFX_TEST_INSTANCE_TEST_FAIL exists only for bin/block-acceptance.sh, because no
# filesystem trick fails a run between two renames; unset in every real run.
SEAM=${SFX_TEST_INSTANCE_TEST_FAIL:-}
case "$SEAM" in
  '' | copy | second-rename | rollback) ;;
  *) echo "test-instance: unknown SFX_TEST_INSTANCE_TEST_FAIL value '$SEAM'" >&2; exit 2 ;;
esac
# shortcut: SFX_TEST_INSTANCE_TEST_MAXTIME exists only for bin/block-acceptance.sh's timeout case;
# it can only lower curl's --max-time of 20 seconds; unset in every real run.
MAXTIME=${SFX_TEST_INSTANCE_TEST_MAXTIME:-20}
case "$MAXTIME" in [1-9] | 1[0-9] | 20) ;; *) echo "test-instance: SFX_TEST_INSTANCE_TEST_MAXTIME must be 1 to 20" >&2; exit 2 ;; esac

# --- bin/plugin.conf: parsed line by line, never sourced -----------------------
ALLOWED='uninstall.php src includes assets languages README.md CHANGELOG.md readme.txt'
THEME_ALLOWED='functions.php screenshot.png theme.json inc src includes elements templates parts patterns assets languages README.md CHANGELOG.md'
fits() { printf '%s' "$2" | grep -Eq "$1"; }
words_fit() { # words_fit <regex> <value> : at least one word, each matching (no globbing)
  local w words=()
  read -r -a words <<< "$2"
  [ ${#words[@]} -gt 0 ] || return 1
  for w in "${words[@]}"; do fits "$1" "$w" || return 1; done
}
read_conf() {
  local n=0 line key value seen=' ' k w shipped_line need='SLUG MAIN VERSION_CONST SHIPPED BATTERY WP_CLI'
  [ -r bin/plugin.conf ] || die "bin/plugin.conf is missing or unreadable"
  # KIND may follow MAIN, so the kind is read first; the loop below refuses a bad KIND line.
  KIND=plugin
  if [ "$(sed -n 's/^KIND=//p' bin/plugin.conf | head -1)" = theme ]; then
    KIND=theme ALLOWED=$THEME_ALLOWED need='SLUG MAIN SHIPPED BATTERY WP_CLI'
  fi
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    case "$line" in '' | '#'*) continue ;; *=*) ;; *) die "bin/plugin.conf line $n: not KEY=value" ;; esac
    key=${line%%=*} value=${line#*=}
    case "$seen" in *" $key "*) die "bin/plugin.conf line $n: $key repeated" ;; esac
    seen="$seen$key "
    case "$key" in
      KIND) fits '^(plugin|theme)$' "$value" ;;
      SLUG) fits '^sfx-[a-z0-9]+(-[a-z0-9]+)*$' "$value" && SLUG=$value ;;
      MAIN) if [ $KIND = theme ]; then [ "$value" = style.css ]; else fits '^[a-z0-9-]+\.php$' "$value"; fi && MAIN=$value ;;
      VERSION_CONST) [ $KIND = plugin ] || die "bin/plugin.conf line $n: VERSION_CONST must be absent in theme kind"
        fits '^SFX_[A-Z0-9_]+$' "$value" ;;
      SHIPPED) words_fit '^[A-Za-z0-9_][A-Za-z0-9._-]*$' "$value" && read -r -a SHIPPED <<< "$value" && shipped_line=$n ;;
      BATTERY | WP_CLI) words_fit '^[A-Za-z0-9._/=:-]+$' "$value" ;;
      *) die "bin/plugin.conf line $n: unknown key '$key'" ;;
    esac || die "bin/plugin.conf line $n: $key '$value' does not fit its grammar"
  done < bin/plugin.conf
  for k in $need; do
    case "$seen" in *" $k "*) ;; *) die "bin/plugin.conf: $k is missing" ;; esac
  done
  for w in "${SHIPPED[@]}"; do
    case " $MAIN $ALLOWED " in *" $w "*) ;; *) die "bin/plugin.conf line $shipped_line: SHIPPED entry '$w' is outside STD-REL-06" ;; esac
    [ $KIND = plugin ] || [ ! -L "$w" ] || die "bin/plugin.conf line $shipped_line: SHIPPED entry '$w' is a symlink"
  done
  case " ${SHIPPED[*]} " in *" $MAIN "*) ;; *) die "bin/plugin.conf line $shipped_line: SHIPPED must name MAIN ($MAIN)" ;; esac
  [ -f "$MAIN" ] || die "bin/plugin.conf: MAIN $MAIN does not exist"
}
read_conf

# --- arguments ---
ACTION=${1:-}
case "$ACTION" in install | refresh | remove) shift ;; *) usage ;; esac
SITE=${SFX_TEST_SITE:-} FULL=0 WP_ACT=''
while [ $# -gt 0 ]; do
  case "$1" in
    --site) [ $# -ge 2 ] || usage; SITE=$2; shift 2 ;;
    --full) FULL=1; shift ;;
    --activate | --deactivate) [ -z "$WP_ACT" ] || usage; WP_ACT=${1#--}; shift ;;
    *) usage ;;
  esac
done
case "$ACTION:$WP_ACT:$FULL" in
  remove:activate:* | install:deactivate:* | refresh:deactivate:* | remove:*:1) usage ;;
esac
if [ $KIND = theme ]; then
  [ $FULL = 0 ] || die "--full is refused in theme kind: it copies every root entry (vendor/, tests/, tooling), a tree no release ships"
  [ "$WP_ACT" != deactivate ] || die "--deactivate is refused in theme kind: WordPress has no theme deactivation; activate another theme first"
fi
# Site and admin check settings (spec E8), only for install and refresh; checked before any write.
CHECK_URL=0 CHECK_ADMIN=0
if [ "$ACTION" != remove ] && [ -n "${SFX_TEST_URL+set}" ]; then
  url_re='^https?://[^/?#[:space:]]+$' login_re='^[A-Za-z0-9_.@][A-Za-z0-9_.@-]*$'
  [[ $SFX_TEST_URL =~ $url_re ]] || die "SFX_TEST_URL must be a bare origin such as https://example.org: scheme and host, no trailing slash, path, query or blank"
  command -v curl > /dev/null || die "the site check needs curl"
  CHECK_URL=1
  if [ -n "${SFX_TEST_ADMIN+set}" ]; then
    [[ $SFX_TEST_ADMIN =~ $login_re ]] || die "SFX_TEST_ADMIN must be one user login of A-Z a-z 0-9 _ . @ - that does not start with -"
    CHECK_ADMIN=1
  fi
fi
WP_WORDS=()
if [ -n "$WP_ACT" ] || [ $KIND = theme ] || [ $CHECK_ADMIN = 1 ]; then
  read -r -a WP_WORDS <<< "${SFX_TEST_WP:-wp}"
  [ ${#WP_WORDS[@]} -gt 0 ] || die "SFX_TEST_WP holds no command word; unset it for plain wp"
  # Only an unset SFX_TEST_WP_PATH falls back to the site; [[ =~ ]] matches the whole value, newlines included.
  if [ -n "${SFX_TEST_WP_PATH+set}" ] && ! [[ $SFX_TEST_WP_PATH =~ ^[A-Za-z0-9._/=:-]+$ ]]; then
    die "SFX_TEST_WP_PATH must be one word of A-Z a-z 0-9 . _ / = : - (the WordPress root WP-CLI sees); unset it to use the site path"
  fi
fi

# --- checks before any write (part C2 spec §6.2) ---
if [ "$ACTION" != remove ]; then
  for e in "${SHIPPED[@]}"; do
    [ -e "$e" ] || case "$e" in assets | languages | readme.txt) ;; *) die "SHIPPED entry $e does not exist in the checkout" ;; esac
  done
fi
[ -n "$SITE" ] || die "no site: pass --site <path> or set SFX_TEST_SITE"
case "$SITE" in /*) ;; *) SITE="$START/$SITE" ;; esac
[ -f "$SITE/wp-config.php" ] || die "$SITE holds no wp-config.php; not a WordPress site"
FOLDER=wp-content/${KIND}s
[ -d "$SITE/$FOLDER" ] || die "$SITE has no $FOLDER folder"
[ ! -L "$SITE/$FOLDER" ] || die "$SITE/$FOLDER is a symlink; refused"
PARENT=$(cd "$SITE/$FOLDER" && pwd -P) || die "cannot enter $SITE/$FOLDER"
TARGET="$PARENT/$SLUG"
[ ! -L "$TARGET" ] || die "$TARGET is a symlink; refused"
lower() { printf '%s/' "$1" | tr '[:upper:]' '[:lower:]'; }
case "$(lower "$TARGET")" in "$(lower "$CHECKOUT")"*) die "the target $TARGET lies in the checkout (or is it)" ;; esac
case "$(lower "$CHECKOUT")" in "$(lower "$TARGET")"*) die "the checkout lies in the target $TARGET" ;; esac

wpc() { "${WP_WORDS[@]}" "--path=${SFX_TEST_WP_PATH-$SITE}" "$@"; }
# Output this script parses; a TTY wrapper (ddev exec) may end lines with CR, and the guards compare exactly.
wpo() { wpc "$@" | tr -d '\r'; return "${PIPESTATUS[0]}"; }
opt() { # opt <name> : sets OPT to the site's option; a failing WP-CLI stops the run
  OPT=$(wpo option get "$1") || die "WP-CLI option get $1 failed; theme kind and the admin check need a working WP-CLI (SFX_TEST_WP)"
}
if [ $KIND = theme ] && [ "$ACTION" = remove ]; then
  # WordPress switches away from a missing active theme (spec F6); the developer switches first.
  opt stylesheet; [ "$OPT" != "$SLUG" ] || die "$SLUG is the site's stylesheet (active theme); activate another theme first"
  opt template; [ "$OPT" != "$SLUG" ] || die "$SLUG is the site's template (parent of the active theme); activate another theme first"
fi

PREFIX="$PARENT/.$SLUG.test-instance"
leftovers() { # lists every .<SLUG>.test-instance* folder but the lock; returns 1 when there is one
  local f found=0
  for f in "$PREFIX"*; do
    [ -e "$f" ] && [ "$f" != "$PREFIX-lock" ] || continue
    found=1
    case "$f" in
      "$PREFIX"-old-*) echo "leftover $f: the previous instance; when $TARGET is missing, rename it back to $TARGET by hand, else delete it" >&2 ;;
      "$PREFIX"-trash-*) echo "leftover $f: an incomplete deletion; delete it by hand, never restore it" >&2 ;;
      *) echo "leftover $f: an unfinished copy; delete it by hand" >&2 ;;
    esac
  done
  return $found
}
if ! mkdir "$PREFIX-lock" 2> /dev/null; then
  leftovers
  die "lock $PREFIX-lock is held by another run or was left by a killed one; remove it by hand only once no test-instance.sh for $SLUG is running (ps)"
fi
ADMIN_TMP='' TOKEN='' ADMIN_ID=''
on_exit() { # revokes the admin check's session, deletes its temp file, releases the lock
  if [ -n "$TOKEN" ]; then
    wpc eval "WP_Session_Tokens::get_instance( $ADMIN_ID )->destroy( '$TOKEN' );" > /dev/null \
      || echo "test-instance: could not revoke the admin check's session; it expires within 300 seconds" >&2
  fi
  [ -z "$ADMIN_TMP" ] || rm -f "$ADMIN_TMP"
  rmdir "$PREFIX-lock" 2> /dev/null
}
trap on_exit EXIT
leftovers || die "leftover folders beside $TARGET (listed above); resolve them first"

OWNER=$(printf 'slug=%s\ncheckout=%s' "$SLUG" "$CHECKOUT")
owned() { [ -d "$TARGET" ] && [ -f "$TARGET/.sfx-test-instance" ] && [ "$(cat "$TARGET/.sfx-test-instance")" = "$OWNER" ]; }
if [ "$ACTION" = install ]; then
  [ ! -e "$TARGET" ] || die "$TARGET exists; refresh replaces only a copy this script made"
else
  owned || die "$TARGET was not made by this script for this checkout (no matching .sfx-test-instance); this script did not create it"
fi

rand() { od -An -N4 -tx4 /dev/urandom | tr -d ' \n'; }
# stage : copies the plugin into a new staging folder beside the target; sets STAGE
stage() {
  local e
  STAGE="$PREFIX-$(rand)"
  mkdir "$STAGE" || die "cannot create $STAGE"
  # A subshell, so `exit 1` ends only the copy and the cleanup below still runs.
  (
    if [ $FULL -eq 1 ]; then
      for e in *; do [ -e "$e" ] || [ -L "$e" ] || continue; cp -RP "$e" "$STAGE/" || exit 1; done
    else
      for e in "${SHIPPED[@]}"; do [ -e "$e" ] || continue; cp -RP "$e" "$STAGE/" || exit 1; done
    fi
    printf '%s\n' "$OWNER" > "$STAGE/.sfx-test-instance" || exit 1
    [ "$SEAM" != copy ]
  ) || { rm -rf "$STAGE"; die "copying into $STAGE failed; nothing was published"; }
}
wp_cli() { # wp_cli activate|deactivate
  local rc
  wpc $KIND "$1" "$SLUG"; rc=$?
  [ $rc -eq 0 ] || die "WP-CLI $KIND $1 $SLUG failed with exit $rc; the files stay as they are"
}
[ $KIND = plugin ] || [ "$ACTION" = remove ] || { opt stylesheet; BEFORE=$OPT; }

case "$ACTION" in
  install)
    stage
    if [ -e "$TARGET" ] || [ -L "$TARGET" ] || ! mv "$STAGE" "$TARGET"; then
      rm -rf "$STAGE"; die "could not publish $TARGET (did it appear meanwhile?)"
    fi
    [ "$WP_ACT" != activate ] || wp_cli activate
    echo "test-instance: installed $TARGET"
    ;;
  refresh)
    stage
    owned || { rm -rf "$STAGE"; die "$TARGET changed while copying; nothing replaced"; }
    OLD="$PREFIX-old-$(rand)"
    mv "$TARGET" "$OLD" || { rm -rf "$STAGE"; die "could not move $TARGET aside; nothing replaced"; }
    if [ "$SEAM" = second-rename ] || [ "$SEAM" = rollback ] || ! mv "$STAGE" "$TARGET"; then
      if [ "$SEAM" != rollback ] && mv "$OLD" "$TARGET"; then
        rm -rf "$STAGE"; die "could not move the new copy in; the previous instance is back at $TARGET"
      fi
      die "could not move the new copy in nor the previous instance back: previous instance $OLD, new copy $STAGE; rename $OLD to $TARGET by hand"
    fi
    rm -rf "$OLD" || die "refreshed $TARGET, but could not delete $OLD; delete it by hand"
    [ "$WP_ACT" != activate ] || wp_cli activate
    echo "test-instance: refreshed $TARGET"
    ;;
  remove)
    [ "$WP_ACT" != deactivate ] || wp_cli deactivate
    TRASH="$PREFIX-trash-$(rand)"
    mv "$TARGET" "$TRASH" || die "could not move $TARGET aside; nothing removed"
    rm -rf "$TRASH" || die "could not delete $TRASH; delete it by hand"
    echo "test-instance: removed $TARGET"
    exit 0
    ;;
esac

# --- checks after install and refresh (spec E7, E8); a failed check exits 3, the copy stays ---
FAILED=0
if [ $KIND = theme ]; then
  opt stylesheet
  [ "$BEFORE" != "$SLUG" ] || [ "$OPT" = "$SLUG" ] \
    || die "the site's stylesheet changed from $SLUG to $OPT during the copy (WordPress left the briefly missing theme); repair: wp theme activate $SLUG"
  echo "active stylesheet: $OPT"
  [ "$WP_ACT" != activate ] || [ "$OPT" = "$SLUG" ] || FAILED=1
fi
probe() { # probe <url> [curl options...] : prints the status or "error: ..."; returns 1 unless 200
  local u=$1 r code ex; shift
  # curl's own exit status, not -w %{exitcode} (curl 7.75+ only).
  r=$(curl -sS -o /dev/null -w '%{http_code}' --max-time "$MAXTIME" "$@" "$u" 2>&1); ex=$?
  if [ "$ex" != 0 ]; then r=${r%000}; echo "error: curl exit $ex: ${r#curl: }"; return 1; fi
  echo "$r"; [ "$r" = 200 ]
}
# One fixed PHP string; USER_ID becomes the numeric user ID, the only value read in. Until its
# output is complete, a shutdown function destroys the session it created.
read -r -d '' SESSION_PHP << 'PHP'
$m = WP_Session_Tokens::get_instance( USER_ID ); $e = time() + 300; $t = $m->create( $e ); $done = false;
register_shutdown_function( function () use ( $m, $t, &$done ) { if ( ! $done ) { $m->destroy( $t ); } } );
$s = wp_generate_auth_cookie( USER_ID, $e, 'secure_auth', $t ); $l = wp_generate_auth_cookie( USER_ID, $e, 'logged_in', $t );
echo 'token ', $t, "\n", 'Cookie: ', SECURE_AUTH_COOKIE, '=', rawurlencode( $s ), '; ', LOGGED_IN_COOKIE, '=', rawurlencode( $l ), "\n";
$done = true;
PHP
admin_check() { # spec E8 steps 1-4, in that order
  local re='^(https?://[^/?#]+)' r rc cookie
  case "$SFX_TEST_URL" in https://*) ;; *) echo "admin check: refused: SFX_TEST_URL is not https://"; return 1 ;; esac
  case "$SFX_TEST_URL" in *@*) echo "admin check: refused: SFX_TEST_URL carries userinfo"; return 1 ;; esac
  OPT=$(wpo option get siteurl) || { echo "admin check: error: WP-CLI option get siteurl failed"; return 1; }
  if ! [[ $OPT =~ $re ]] || [ "$(lower "${BASH_REMATCH[1]}")" != "$(lower "$SFX_TEST_URL")" ]; then
    echo "admin check: refused: SFX_TEST_URL is not the origin of the site's siteurl"; return 1
  fi
  r=$(wpo user get "$SFX_TEST_ADMIN" --field=ID 2> /dev/null)
  [[ $r =~ ^[1-9][0-9]*$ ]] || { echo "admin check: refused: SFX_TEST_ADMIN names no user of the site"; return 1; }
  ADMIN_ID=$r
  # The exit trap deletes this mode-0600 file, the only place a cookie or the token is written.
  ADMIN_TMP=$(mktemp "${TMPDIR:-/tmp}/sfx-test-instance.XXXXXX") || { echo "admin check: error: cannot create a temp file"; return 1; }
  wpo eval "${SESSION_PHP//USER_ID/$ADMIN_ID}" > "$ADMIN_TMP"; rc=$?
  # From here the exit trap destroys the session.
  TOKEN=$(sed -n 's/^token \([A-Za-z0-9]\{1,\}\)$/\1/p' "$ADMIN_TMP" | head -1)
  cookie=$(grep '^Cookie: ' "$ADMIN_TMP" | head -1)
  if [ $rc != 0 ] || [ -z "$TOKEN" ] || [ -z "$cookie" ]; then
    echo "admin check: error: the session could not be prepared (WP-CLI exit $rc)"; return 1
  fi
  # The cookie line reaches curl on stdin, never on its command line; redirects are not followed.
  r=$(printf '%s\n' "$cookie" | probe "$SFX_TEST_URL/wp-admin/" -H @-); rc=$?
  echo "admin check: $r"; return $rc
}
if [ $CHECK_URL = 0 ]; then
  echo "site check: skipped (SFX_TEST_URL unset)"
else
  for p in / /wp-login.php; do
    r=$(probe "$SFX_TEST_URL$p" -L --max-redirs 5) || FAILED=1
    echo "site check: $SFX_TEST_URL$p $r"
  done
  if [ $CHECK_ADMIN = 0 ]; then echo "admin check: skipped (SFX_TEST_ADMIN unset)"; else admin_check || FAILED=1; fi
fi
[ $FAILED = 0 ] || { echo "test-instance: a check failed; the copy stays at $TARGET" >&2; exit 3; }
