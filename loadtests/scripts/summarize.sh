#!/usr/bin/env bash
# Task 10: median / min / max over several result files of the same scenario+variant (the "3 runs" rule).
#   loadtests/scripts/summarize.sh loadtests/results/catalog-naive-*.json > summary.json
#   loadtests/scripts/summarize.sh docs/benchmarks/order-flood-*.json
# Works on any files that follow loadtests/results/SCHEMA.md. Values are copied from the files; nothing is estimated.
# With an even number of runs the median is the mean of the two middle values. Fewer than 3 runs is flagged.
set -euo pipefail
command -v jq >/dev/null 2>&1 || { echo "summarize: jq is required" >&2; exit 1; }
[ $# -gt 0 ] || { echo "usage: summarize.sh <result.json>..." >&2; exit 1; }

jq -s '
  def stat: map(select(. != null)) | sort as $v | ($v | length) as $n
    | if $n == 0 then {median: null, min: null, max: null, n: 0}
      else {median: (if $n % 2 == 1 then $v[($n - 1) / 2] else (($v[$n / 2 - 1] + $v[$n / 2]) / 2) end), min: $v[0], max: $v[-1], n: $n} end;
  {
    summary_schema_version: 1,
    generated_at: (now | todate),
    groups: (map(select(.schema_version == 1)) | group_by(.scenario + "/" + .variant) | map({
      scenario: .[0].scenario,
      variant: .[0].variant,
      runs: length,
      enough_runs: (length >= 3),
      run_ids: map(.run.id),
      git_shas: (map(.run.git_sha) | unique),
      any_dirty: (map(.run.git_dirty == true) | any),
      observability_stack: (map(.run.env.observability_stack) | unique),
      all_verdicts_passed: (map(.invariants.verdict.passed == true) | all),
      p50: (map(.metrics.p50) | stat),
      p90: (map(.metrics.p90) | stat),
      p95: (map(.metrics.p95) | stat),
      p99: (map(.metrics.p99) | stat),
      rps: (map(.metrics.rps) | stat),
      error_rate: (map(.metrics.error_rate) | stat)
    }))
  }' "$@"
