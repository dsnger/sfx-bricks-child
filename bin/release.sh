#!/usr/bin/env bash
# SFX Block: release-script 1.1.1 sha256:2c8316cc82a36687ce8a640853a06b1dbf63007fd4780a6e93f96523281722ce.
# Release procedure (SFX standard STD-REL-05, STD-REL-09), two phases separated by review and merge:
#   bin/release.sh patch|minor|major     on a clean main equal to origin/main: create
#                                        release/<version>, bump, regenerate the .pot, run the
#                                        battery, stop
#   bin/release.sh publish [--no-upload] on a clean main equal to origin/main: check the version,
#                                        build the ZIP (STD-REL-06), tag, push, GitHub release;
#                                        --no-upload stops after the ZIP and prints its path
# The plugin's values come from bin/plugin.conf. This file is the canonical release-script
# block: a plugin never edits it; adopt-block places a later version.
# The PHP snippets are single-quoted on purpose.
# shellcheck disable=SC2016
set -u
cd "$(dirname "$0")/.." || exit 1

die() { echo "release: $*" >&2; exit 1; }
usage() { echo "usage: bin/release.sh patch|minor|major | publish [--no-upload]" >&2; exit 2; }

NUM='(0|[1-9][0-9]*)'
VERSION_RE="^$NUM\\.$NUM\\.$NUM(-rc\\.$NUM)?\$"
# STD-REL-06: the entries a ZIP may hold besides the main file.
ALLOWED='uninstall.php src includes assets languages README.md CHANGELOG.md readme.txt'
# STD-REL-06 theme form: the entries a theme ZIP may hold besides style.css.
THEME_ALLOWED='functions.php screenshot.png theme.json inc src includes elements templates parts patterns assets languages README.md CHANGELOG.md'

# --- bin/plugin.conf: parsed line by line, never sourced -----------------------
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
        fits '^SFX_[A-Z0-9_]+$' "$value" && VERSION_CONST=$value ;;
      SHIPPED) words_fit '^[A-Za-z0-9_][A-Za-z0-9._-]*$' "$value" && read -r -a SHIPPED <<< "$value" && shipped_line=$n ;;
      BATTERY) words_fit '^[A-Za-z0-9._/=:-]+$' "$value" && read -r -a BATTERY <<< "$value" ;;
      WP_CLI) words_fit '^[A-Za-z0-9._/=:-]+$' "$value" && read -r -a WP_CLI <<< "$value" ;;
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
  shipped "$MAIN" || die "bin/plugin.conf line $shipped_line: SHIPPED must name MAIN ($MAIN)"
  [ -f "$MAIN" ] || die "bin/plugin.conf: MAIN $MAIN does not exist"
}
shipped() { case " ${SHIPPED[*]} " in *" $1 "*) return 0 ;; esac; return 1; }

read_conf
TEXT_DOMAIN=$(sed -n 's/^[ *]*Text Domain:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' "$MAIN" | head -1)
fits '^[a-z0-9-]+$' "$TEXT_DOMAIN" || die "$MAIN has no usable Text Domain header"
# A theme's version is the one style.css line matching ^[ \t*]*Version:; with two, a bump could edit the wrong one.
VERSION_LINE='^[[:blank:]*]*Version:'
if [ $KIND = theme ] && [ "$(grep -c "$VERSION_LINE" style.css)" != 1 ]; then
  die "style.css must hold exactly one line matching ^[ \\t*]*Version: (it holds $(grep -c "$VERSION_LINE" style.css))"
fi

clean_main() {
  local branch
  branch=$(git symbolic-ref -q --short HEAD) || die "HEAD is detached; run this on main"
  [ "$branch" = main ] || die "run this on main (now on $branch)"
  [ -z "$(git status --porcelain)" ] || die "the working tree is not clean"
  git config --get remote.origin.url > /dev/null || die "no remote 'origin'"
  git fetch -q origin main || die "could not fetch main from origin"
  [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "HEAD is not origin/main; pull or push main first"
}

header_version() {
  if [ $KIND = theme ]; then sed -n 's/^[[:blank:]*]*Version:[[:blank:]]*\([^[:blank:]]*\)[[:blank:]]*$/\1/p' style.css
  else sed -n 's/^ \* Version: \(.*\)$/\1/p' "$MAIN" | head -1; fi
}
constant_version() { sed -n "s/^define( '$VERSION_CONST', '\([^']*\)' );$/\1/p" "$MAIN" | head -1; }
stable_tag() { [ -f readme.txt ] && sed -n 's/^Stable tag: \(.*\)$/\1/p' readme.txt | head -1; }

# replace_in <file> <regex> <replacement> : the regex must match exactly once
replace_in() {
	php -r '$c = file_get_contents( $argv[1] ); if ( 1 !== preg_match_all( $argv[2], $c ) ) { exit( 1 ); }
		$c = preg_replace( $argv[2], $argv[3], $c, 1 ); exit( null !== $c && false !== file_put_contents( $argv[1], $c ) ? 0 : 1 );' "$1" "$2" "$3"
}

bump() {
  local part=$1 current next date made_lang=0 made_pot=0
  clean_main
  current=$(header_version)
  fits "^$NUM\\.$NUM\\.$NUM\$" "$current" || die "header Version '$current' is not MAJOR.MINOR.PATCH (a pre-release is tagged by hand)"
  next=$(php -r '[ $a, $b, $c ] = array_map( "intval", explode( ".", $argv[1] ) );
		echo "major" === $argv[2] ? ( $a + 1 ) . ".0.0" : ( "minor" === $argv[2] ? "$a." . ( $b + 1 ) . ".0" : "$a.$b." . ( $c + 1 ) );' "$current" "$part")
  [ -d languages ] || made_lang=1
  [ -e "languages/$TEXT_DOMAIN.pot" ] || made_pot=1
  git switch -q -c "release/$next" || die "could not create release/$next"
  undo() {
    echo "release: bump to $next failed; release/$next and its changed files are left in place. To undo:" >&2
    echo "  git checkout -- ." >&2
    if [ $made_lang -eq 1 ]; then echo "  rm -rf languages" >&2
    elif [ $made_pot -eq 1 ]; then echo "  rm -f languages/$TEXT_DOMAIN.pot" >&2; fi
    echo "  git switch main && git branch -D release/$next" >&2
    die "bump to $next failed: $1"
  }
  date=$(date -u +%F)
  if [ $KIND = theme ]; then # no constant, no Stable tag
    replace_in style.css '/^([ \t*]*Version:[ \t]*).*$/m' "\${1}$next" || undo "the Version line must match exactly once in style.css"
  else
  replace_in "$MAIN" '/^ \* Version: .*$/m' " * Version: $next" || undo "the Version header must match exactly once in $MAIN"
  replace_in "$MAIN" "/^define\\( '$VERSION_CONST', '[^']*' \\);\$/m" "define( '$VERSION_CONST', '$next' );" \
    || undo "the $VERSION_CONST define must match exactly once in $MAIN"
  if [ -f readme.txt ]; then replace_in readme.txt '/^Stable tag: .*$/m' "Stable tag: $next" || undo "Stable tag must match exactly once in readme.txt"; fi
  fi
  # A top-level "version" plus exactly one "version" key in the text: that one is the top-level key.
  if [ -f package.json ]; then
    php -r '$j = json_decode( (string) file_get_contents( "package.json" ), true ); exit( is_array( $j ) && is_string( $j["version"] ?? null ) ? 0 : 1 );' \
      || undo "package.json has no top-level version string"
    replace_in package.json '/("version"\s*:\s*)"[^"]*"/' "\${1}\"$next\"" || undo "a version key must match exactly once in package.json"
  fi
  # package-lock.json restates it at the top level and in packages[""] (lockfileVersion 2, 3); the
  # decoded result must differ from the original in exactly those keys, so no other one moves.
  if [ -f package-lock.json ]; then
    php -r '$t = (string) file_get_contents( "package-lock.json" ); $j = json_decode( $t, true ); $n = $argv[1];
        if ( ! is_array( $j ) || ! is_string( $j["version"] ?? null ) ) { exit( 1 ); }
        $root = array_key_exists( "packages", $j );
        if ( $root && ! is_string( $j["packages"][""]["version"] ?? null ) ) { exit( 1 ); }
        $set = function ( $m ) use ( $n ) { return $m[1] . "\"" . $n . "\""; };
        $t = preg_replace_callback( "/(\"version\"\\s*:\\s*)\"[^\"]*\"/", $set, $t, 1 );
        if ( $root ) { $t = preg_replace_callback( "/(\"\"\\s*:\\s*\\{[^{}]*?\"version\"\\s*:\\s*)\"[^\"]*\"/", $set, $t, 1 ); }
        $j["version"] = $n;
        if ( $root ) { $j["packages"][""]["version"] = $n; }
        exit( null !== $t && json_decode( $t, true ) === $j && false !== file_put_contents( "package-lock.json", $t ) ? 0 : 1 );' "$next" \
      || undo "package-lock.json needs a top-level version string and, with a packages key, a packages[\"\"].version string, each set exactly once"
  fi
  replace_in CHANGELOG.md '/^## \[Unreleased\]$/m' "## [Unreleased]

## [$next] - $date" || undo "the Unreleased heading must match exactly once in CHANGELOG.md"
  mkdir -p languages || undo "could not create languages/"
  "${WP_CLI[@]}" i18n make-pot . "languages/$TEXT_DOMAIN.pot" "--slug=$SLUG" --exclude=vendor,tests \
    || undo "make-pot failed (WP_CLI: ${WP_CLI[*]})"
  "${BATTERY[@]}" || undo "the battery failed (BATTERY: ${BATTERY[*]})"
  echo "release: release/$next is ready. Review it (the plugin gate, if any), commit, open a pull request, merge, then run bin/release.sh publish on main."
}

KEEP=0
TMP_DIR=''
cleanup() { [ -n "$TMP_DIR" ] && [ $KEEP -eq 0 ] && rm -rf "$TMP_DIR"; }

publish() {
  local upload=1 version notes zip list files want bad e present=() repo='' url tag
  local local_state remote_state remote peeled err rc pre=()
  case "$#:${1:-}" in
    0:) ;;
    1:--no-upload) upload=0 ;;
    *) usage ;;
  esac
  clean_main
  version=$(header_version)
  fits "$VERSION_RE" "$version" || die "version '$version' is not X.Y.Z or X.Y.Z-rc.N without leading zeros (STD-REL-02)"
  if [ $KIND = plugin ]; then
  [ "$version" = "$(constant_version)" ] || die "header Version '$version' and $VERSION_CONST '$(constant_version)' disagree"
  if [ -f readme.txt ] && [ "$version" != "$(stable_tag)" ]; then die "header Version '$version' and readme.txt Stable tag '$(stable_tag)' disagree"; fi
  fi
  awk -v h="## [$version] - " 'index($0, h) == 1 { found = 1 } END { exit !found }' CHANGELOG.md \
    || die "CHANGELOG.md has no section for $version"
  notes=$(awk -v h="## [$version]" 'index($0, h) == 1 { on = 1; next } on && /^## \[/ { exit } on { print }' CHANGELOG.md)
  [ -n "${notes//[[:space:]]/}" ] || die "CHANGELOG.md section $version holds no release notes; refusing a release with an empty body"

  # --- what ships (STD-REL-06) ---
  for e in "${SHIPPED[@]}"; do
    if git cat-file -e "HEAD:$e" 2> /dev/null; then present+=( "$e" )
    else case "$e" in assets | languages | readme.txt) ;; *) die "SHIPPED entry $e does not exist at HEAD" ;; esac; fi
  done
  if [ $KIND = plugin ] && shipped includes && git cat-file -e HEAD:src 2> /dev/null; then
    die "SHIPPED names includes, but HEAD holds src/: a plugin that has taken STD-LAY-04 never ships includes/"
  fi
  for e in $ALLOWED; do
    if git cat-file -e "HEAD:$e" 2> /dev/null && ! shipped "$e"; then die "$e exists at HEAD but SHIPPED in bin/plugin.conf omits it"; fi
  done
  # Unquoted names (-z), so a non-ASCII file name compares equal to unzip's listing.
  files=$(git ls-tree -r -z --name-only HEAD -- "${present[@]}" | tr '\0' '\n' | sort)
  bad=$(printf '%s\n' "$files" | grep -E '(^|/)\.' || true)
  [ -z "$bad" ] || die "a shipped entry holds a dotfile: $(printf '%s' "$bad" | tr '\n' ' ')"
  # unzip -Z1 prints non-ASCII names in a local code page (macOS: Ü as +243), so the listing
  # check could not confirm them.
  bad=$(printf '%s\n' "$files" | LC_ALL=C grep '[^ -~]' || true)
  [ -z "$bad" ] || die "shipped file names must be ASCII (the ZIP listing check cannot confirm others); rename: $(printf '%s' "$bad" | tr '\n' ' ')"

  # --- tag and release state, before anything is built (upload only) ---
  if [ $upload -eq 1 ]; then
    url=$(git config --get remote.origin.url)
    repo=$(printf '%s\n' "$url" | sed -n -E 's#^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)([A-Za-z0-9-]+/[A-Za-z0-9._-]+)$#\2#p' | sed 's/\.git$//')
    [ -n "$repo" ] || die "origin '$url' is not a GitHub repository URL"
    tag="v$version"
    if git rev-parse -q --verify "refs/tags/$tag" > /dev/null; then
      [ "$(git cat-file -t "refs/tags/$tag")" = tag ] || die "local tag $tag is lightweight; STD-REL-01 needs an annotated tag"
      [ "$(git rev-parse "$tag^{commit}")" = "$(git rev-parse HEAD)" ] || die "local tag $tag names another commit than HEAD"
      local_state=annotated
    else local_state=absent; fi
    remote=$(git ls-remote origin "refs/tags/$tag" "refs/tags/$tag^{}") || die "could not read the tag state from origin (git ls-remote failed)"
    if [ -z "$remote" ]; then remote_state=absent
    else
      peeled=$(printf '%s\n' "$remote" | awk '$2 ~ /\^\{\}$/ { print $1 }')
      [ -n "$peeled" ] || die "remote tag $tag is lightweight; STD-REL-01 needs an annotated tag"
      [ "$peeled" = "$(git rev-parse HEAD)" ] || die "remote tag $tag names another commit than HEAD"
      remote_state=annotated
    fi
    [ "$local_state:$remote_state" != absent:annotated ] || die "origin has tag $tag but this clone does not; run git fetch origin tag $tag and publish again"
    err=$(gh release view "$tag" --repo "$repo" 2>&1 > /dev/null); rc=$?
    case $rc in
      0) die "a GitHub release $tag already exists in $repo" ;;
      1) printf '%s' "$err" | grep -q 'release not found' || die "could not determine the release state of $tag: $(printf '%s' "$err" | tr '\n' ' ')" ;;
      *) die "could not determine the release state of $tag (gh exit $rc): $(printf '%s' "$err" | tr '\n' ' ')" ;;
    esac
  fi

  # --- the ZIP ---
  TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/$SLUG-release.XXXXXX") || die "could not create a temporary directory"
  trap cleanup EXIT
  zip="$TMP_DIR/$SLUG-$version.zip"
  git archive --format=zip --prefix="$SLUG/" -o "$zip" HEAD "${present[@]}" || die "git archive failed"
  list=$(unzip -Z1 "$zip") || die "could not list $zip"
  want=$(printf '%s\n' "$files" | sed "s#^#$SLUG/#" | sort)
  [ "$(printf '%s\n' "$list" | grep -v '/$' | sort)" = "$want" ] \
    || die "the ZIP listing differs from the shipped files at HEAD (an export-ignore attribute?)"
  bad=$(printf '%s\n' "$list" | grep '/$' | awk -v s="$SLUG/" -v ok=" ${present[*]} " '
    $0 == s { next }
    { rest = substr( $0, length( s ) + 1 ); split( rest, p, "/" ); if ( index( $0, s ) != 1 || index( ok, " " p[1] " " ) == 0 ) print }')
  [ -z "$bad" ] || die "the ZIP holds folders outside SHIPPED: $bad"
  printf '%s\n' "$list" | grep -qx "$SLUG/$MAIN" || die "the ZIP lacks $SLUG/$MAIN"
  if [ $upload -eq 0 ]; then
    KEEP=1
    echo "zip $zip"
    echo "release: --no-upload: stopped before the tag and the GitHub release; remove $TMP_DIR when done"
    return 0
  fi

  # --- tag, push, release ---
  case "$version" in *-rc.*) pre=( --prerelease ) ;; esac
  if [ "$local_state" = absent ]; then git tag -a "$tag" -m "$SLUG $version" || die "could not create tag $tag"; fi
  if [ "$remote_state" = absent ]; then
    git push -q origin "refs/tags/$tag" || die "tag $tag exists only locally; run bin/release.sh publish again to resume, or undo with: git tag -d $tag"
  fi
  KEEP=1
  printf '%s\n' "$notes" > "$TMP_DIR/notes.md"
  gh release create "$tag" "$zip" --repo "$repo" --title "$SLUG $version" --notes-file "$TMP_DIR/notes.md" --verify-tag ${pre[@]+"${pre[@]}"} \
    || die "tag $tag is pushed but the release is missing; the ZIP $zip and the notes $TMP_DIR/notes.md are kept: run bin/release.sh publish again to resume"
  KEEP=0
  echo "release: published $tag to $repo"
}

case "${1:-}" in
  patch | minor | major) bump "$1" ;;
  publish) shift; publish "$@" ;;
  *) usage ;;
esac
