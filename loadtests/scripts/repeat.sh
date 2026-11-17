#!/usr/bin/env bash
# Task 10: measurement hygiene in one command. Runs a k6 make target RUNS times (default 3), each time after
# `make loadtest-reset` and followed by a cooldown, then summarizes median / min / max per scenario+variant.
#   make k6-repeat TARGET=k6-catalog RUNS=3 COOLDOWN=60
#   make k6-repeat TARGET=k6-order-flood ARGS="STRATEGY=atomic" RUNS=3
# RESET=0 skips the reset between runs (only for read-only scenarios such as catalog). A failing run stops the
# series: an incomplete series is not summarized as if it were complete.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

TARGET="${1:?usage: repeat.sh <make target> (e.g. k6-catalog)}"
RUNS="${RUNS:-3}"
COOLDOWN="${COOLDOWN:-60}"
RESET="${RESET:-1}"
mkdir -p loadtests/results
marker="$(mktemp)"
trap 'rm -f "$marker"' EXIT

for i in $(seq 1 "$RUNS"); do
  echo "== repeat $i/$RUNS: $TARGET ${ARGS:-}"
  if [ "$RESET" = 1 ]; then make --no-print-directory loadtest-reset; fi
  # shellcheck disable=SC2086
  make --no-print-directory "$TARGET" ${ARGS:-}
  if [ "$i" -lt "$RUNS" ]; then
    echo "== cooldown ${COOLDOWN}s"
    sleep "$COOLDOWN"
  fi
done

mapfile -t files < <(find loadtests/results -maxdepth 1 -type f -name '*.json' ! -name 'summary-*' -newer "$marker" | sort)
[ "${#files[@]}" -gt 0 ] || { echo "repeat: no result files were written" >&2; exit 1; }
out="loadtests/results/summary-$TARGET-$(date -u +%Y%m%dT%H%M%SZ).json"
"$(dirname "${BASH_SOURCE[0]}")/summarize.sh" "${files[@]}" > "$out"
echo "== summary: $out"
jq -r '.groups[] | "   \(.scenario)/\(.variant) runs=\(.runs)  p95 median=\(.p95.median) min=\(.p95.min) max=\(.p95.max) ms   rps median=\(.rps.median)   error_rate median=\(.error_rate.median)"' "$out"
