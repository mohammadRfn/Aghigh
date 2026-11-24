# Soak, 2 hours (exploratory, one run)

**This is an experiment, not a baseline.** One run of `make k6-soak DURATION=2h` (run `20261123T103036Z`, SHA `94e7991`),
5 iterations/s, observability stack down, `git_dirty=false`, verdict OK. It differs from the official series in two ways:
it is one run (no spread), and `SENTRY_LARAVEL_DSN` was empty (`run.env.SENTRY_DSN_SET=false`); every official series ran with it set.
The official soak series lasted about 31 minutes; this is the first run past 60 minutes.

Files: the result json, `.samples.ndjson`, `soak-series.out` (output of `soak-series.py` on the samples; the halves below come from it),
`soak-failed-jobs.out` (read from the `failed_jobs` table right after the run). No `.metrics.prom` (repository rule).

## redis-queue `used_memory` (MiB, nearest sample to each minute)

| minute | 0 | 10 | 20 | 30 | 40 | 50 | 60 | 70 | 80 | 90 | 100 | 110 | 120 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| MiB | 3 | 8 | 13 | 17 | 22 | 27 | 31 | 32 | 33 | 33 | 34 | 34 | 35 |

Least-squares slope: 28.09 MiB/h over minutes 0 to 60, 3.89 MiB/h over 60 to the end, 15.82 MiB/h over the whole window.
The growth is close to linear for the first hour and nearly flat afterwards. This is **consistent with** a 60-minute retention
in Horizon (`trim.recent=60`), but it does not prove it: the slope after minute 60 is not zero, and its cause was not investigated
(one untested candidate is that failed-job payloads stay longer than recent jobs).

## Horizon container memory (MiB)

First 536.1, last 485.3, min about 431, peak 589.8. Nearest sample per 10 minutes: 536, 436, 436, 536, 572, 436, 435, 437, 496, 538, 456, 436, 431.
The slope is negative in both halves (-16.81 and -14.98 MiB/h). The pattern is a saw-tooth, so a slope is a weak summary; no upward trend was seen
in this run. The cause of the repeated drops was not investigated (210 of 211 samples had the container; one sample is missing).

## app container memory

178.8 first, 186.1 peak, 177.3 last; slope 1.82 MiB/h over the first hour and 0.09 MiB/h afterwards.

## Failed jobs

`failed_jobs` had 122 rows after the run, all `ChargePayment` rejected with `card_declined` (simulated declines of the fake gateway).
