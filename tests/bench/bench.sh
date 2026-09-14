#!/usr/bin/env bash
# Benchmark two git refs of wp-extend inside the DDEV site (see tests/bench/README.md).
# Usage: tests/bench/bench.sh <ref-a> <ref-b> [iterations=10]
# A ref of "." means the current working tree (uncommitted changes included).
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$S/../.." && pwd)"
N="${3:-10}"
# The harness may not exist on the refs under test: stage it outside the tree.
STAGE="$(mktemp -d)"; cp "$S/probe.php" "$S/report.php" "$STAGE/"; mkdir -p "$STAGE/results"
ORIG="$(git -C "$PLUGIN" rev-parse --abbrev-ref HEAD)"
DIRTY=0
if [ -n "$(git -C "$PLUGIN" status --porcelain --untracked-files=no)" ]; then
  git -C "$PLUGIN" stash push -q -m "bench.sh"; DIRTY=1
fi
restore() {
  git -C "$PLUGIN" checkout -q -- . ; git -C "$PLUGIN" checkout -q "$ORIG"
  if [ "$DIRTY" = 1 ]; then git -C "$PLUGIN" stash pop -q; fi
  mkdir -p "$S/results" && cp "$STAGE"/results/*.jsonl "$S/results/" 2>/dev/null || true
}
trap restore EXIT
checkout_ref() { # "." = original tree with the stashed changes applied
  git -C "$PLUGIN" checkout -q -- .
  if [ "$1" != "." ]; then git -C "$PLUGIN" checkout -q "$1"; return; fi
  git -C "$PLUGIN" checkout -q "$ORIG"
  if [ "$DIRTY" = 1 ]; then git -C "$PLUGIN" stash apply -q; fi
}
ddev exec -d /var/www/html "cat > /tmp/ewp-bench-probe.php" < "$STAGE/probe.php"

http_ms() { # TTFB in ms for a path, measured inside the web container
  ddev exec curl -s -o /dev/null -w '%{time_starttransfer}' "http://localhost$1" | awk '{printf "%.1f", $1*1000}'
}
cli_ms() { # wall-clock ms of a wp-cli command (includes ddev exec overhead)
  local s e; s=$(date +%s%N); ddev exec wp "$@" >/dev/null 2>&1 || true; e=$(date +%s%N); echo $(( (e - s) / 1000000 ))
}

for REF in "$1" "$2"; do
  echo "== $REF"
  checkout_ref "$REF"
  ddev exec -d /var/www/html "cat > /tmp/ewp-bench-probe.php" < "$STAGE/probe.php"
  NAME="${REF//\//_}"; [ "$NAME" = "." ] && NAME="worktree"
  OUT="$STAGE/results/$NAME.jsonl"; : > "$OUT"
  ddev exec wp cache flush >/dev/null 2>&1 || true
  ddev exec wp eval-file /tmp/ewp-bench-probe.php >/dev/null   # warm-up
  for p in /wp-json/ /wp-json/extend-wp/v1 "/?bench=warm"; do http_ms "$p" >/dev/null; done
  for i in $(seq 1 "$N"); do
    PROBE=$(ddev exec wp eval-file /tmp/ewp-bench-probe.php)
    H_INDEX=$(http_ms /wp-json/); H_NS=$(http_ms /wp-json/extend-wp/v1); H_HOME=$(http_ms "/?bench=$RANDOM$i")
    C_SYS=$(cli_ms ewp system info); C_HELP=$(cli_ms ewp --help)
    echo "${PROBE%\}},\"http_index_ms\":$H_INDEX,\"http_ns_ms\":$H_NS,\"http_home_ms\":$H_HOME,\"cli_system_info_ms\":$C_SYS,\"cli_help_ms\":$C_HELP}" | tee -a "$OUT" | cut -c1-120
  done
done
A="${1//\//_}"; [ "$A" = "." ] && A="worktree"; B="${2//\//_}"; [ "$B" = "." ] && B="worktree"
php "$STAGE/report.php" "$STAGE/results/$A.jsonl" "$STAGE/results/$B.jsonl"
