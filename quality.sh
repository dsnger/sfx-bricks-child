#!/usr/bin/env bash
# The whole quality battery: syntax lint + test suite. CI runs this; so should you.
# PHP resolution, in this order: $PHP if set, then php on PATH, then the local MAMP
# build. A candidate is accepted only after it answers a real PHP version probe —
# `-x` alone accepts any executable, and `PHP=/usr/bin/true` then reported every test
# as passed. The battery must not be able to pass without running PHP.
php_usable() {
  [ -n "${1:-}" ] || return 1
  case "$1" in */*) [ -x "$1" ] || return 1 ;; *) command -v "$1" >/dev/null 2>&1 || return 1 ;; esac

  # Version, as a number PHP computed rather than a string it could have echoed.
  probe=$("$1" -r 'echo PHP_VERSION_ID + 1;' 2>/dev/null) || return 1
  case "$probe" in '' | *[!0-9]*) return 1 ;; esac
  [ "$probe" -gt 80000 ] || return 1

  # And it must behave like a linter: accept valid PHP, reject invalid PHP. A
  # candidate that cannot tell the two apart cannot be trusted to lint the tree.
  # ponytail: this stops every accident (a wrong binary, a stub, PHP 7.4). A
  # deliberately crafted fake interpreter still wins, and is not the threat model.
  printf '<?php $x = 1;\n' > "$probe_ok"
  printf '<?php function ( {\n' > "$probe_bad"
  "$1" -l "$probe_ok"  >/dev/null 2>&1 || return 1
  "$1" -l "$probe_bad" >/dev/null 2>&1 && return 1
  return 0
}

probe_dir=$(mktemp -d)
trap 'rm -rf "$probe_dir"' EXIT
probe_ok="$probe_dir/ok.php"
probe_bad="$probe_dir/bad.php"
probe_ok_js="$probe_dir/ok.js"
probe_bad_js="$probe_dir/bad.js"

php_override="${PHP:-}"
PHP=""
for candidate in "$php_override" php /Applications/MAMP/bin/php/php8.5.2/bin/php; do
  if php_usable "$candidate"; then PHP="$candidate"; break; fi
done
if [ -z "$PHP" ]; then
  echo "error: no usable PHP >= 8.0 found (tried \$PHP, php on PATH, the MAMP build)" >&2
  exit 1
fi

echo "php: $("$PHP" -r 'echo PHP_VERSION;')"

# Node resolution, same shape and same reasoning as PHP above: probe the
# candidate rather than trust it, because `NODE=/usr/bin/true` would otherwise
# report every JS test as passed. The third candidate is fnm's stable default
# alias — a bare `node` is invisible in a shell where fnm was never initialised,
# and fnm's own multishell path changes from session to session, so it is the
# alias and not that path that belongs here.
node_usable() {
  [ -n "${1:-}" ] || return 1
  case "$1" in */*) [ -x "$1" ] || return 1 ;; *) command -v "$1" >/dev/null 2>&1 || return 1 ;; esac

  # Major version PLUS ONE, as a number Node computed rather than a string it
  # echoed — the offset is what stops an empty or zero answer from passing as a
  # version. `-gt 18` therefore accepts Node 18 and up. That floor guards against
  # something ancient or fake answering the probe; it is NOT a support statement,
  # since 18 and 20 are both past end-of-life and this test needs nothing newer
  # than 14. Raising it would lock out a working runtime for no gain. CI runs 22.
  probe=$("$1" -p 'process.versions.node.split(".")[0] * 1 + 1' 2>/dev/null) || return 1
  case "$probe" in '' | *[!0-9]*) return 1 ;; esac
  [ "$probe" -gt 18 ] || return 1

  # And it must behave like a JS parser: accept valid JS, reject invalid JS.
  # ponytail: same threat model as php_usable — this stops accidents, not a
  # deliberately crafted fake runtime.
  printf 'const x = 1;\n' > "$probe_ok_js"
  printf 'function ( {\n' > "$probe_bad_js"
  "$1" --check "$probe_ok_js"  >/dev/null 2>&1 || return 1
  "$1" --check "$probe_bad_js" >/dev/null 2>&1 && return 1
  return 0
}

node_override="${NODE:-}"
NODE=""
for candidate in "$node_override" node "$HOME/.local/share/fnm/aliases/default/bin/node"; do
  if node_usable "$candidate"; then NODE="$candidate"; break; fi
done
if [ -z "$NODE" ]; then
  echo "error: no usable Node >= 18 found (tried \$NODE, node on PATH, the fnm default alias)" >&2
  exit 1
fi

echo "node: $("$NODE" -p 'process.versions.node')"

status=0

echo "== syntax lint =="
lint_errors=0
while IFS= read -r f; do
  if ! "$PHP" -l "$f" >/dev/null 2>&1; then
    echo "SYNTAX ERROR: $f"
    "$PHP" -l "$f" 2>&1 | head -2
    lint_errors=$((lint_errors + 1))
  fi
done < <(git ls-files --cached --others --exclude-standard '*.php' 2>/dev/null \
          || find . -name '*.php' -not -path './vendor/*')
echo "lint: $lint_errors error(s)"
[ "$lint_errors" -eq 0 ] || status=1

echo "== php tests =="
pass=0; fail=0
for f in tests/*-test.php; do
  [ -e "$f" ] || continue
  if out=$("$PHP" "$f" 2>&1); then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "FAIL: $f"
    echo "$out" | tail -5
  fi
done
echo "php tests: $pass passed, $fail failed"
[ "$fail" -eq 0 ] || status=1
if [ "$((pass + fail))" -eq 0 ]; then
  echo "error: no tests found in tests/*-test.php" >&2
  status=1
fi

echo "== js tests =="
js_pass=0; js_fail=0
for f in tests/*-test.mjs; do
  [ -e "$f" ] || continue
  if out=$("$NODE" "$f" 2>&1); then
    js_pass=$((js_pass + 1))
  else
    js_fail=$((js_fail + 1))
    echo "FAIL: $f"
    # head, not tail: node:assert prints the message and the failing line first
    # and a long stack after it, so `tail` shows the runtime version and hides
    # which assertion failed.
    echo "$out" | head -20
  fi
done
echo "js tests: $js_pass passed, $js_fail failed"
[ "$js_fail" -eq 0 ] || status=1
if [ "$((js_pass + js_fail))" -eq 0 ]; then
  echo "error: no tests found in tests/*-test.mjs" >&2
  status=1
fi

[ "$status" -eq 0 ] && echo "QUALITY: PASS" || echo "QUALITY: FAIL"
exit "$status"
