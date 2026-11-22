# OCTANE_WORKERS 2 vs 4 (websocket-chat fanout, 200 conversations)

**This is an experiment, not a baseline.** It is kept out of the `docs/benchmarks/` root on purpose: the group name
`fanout-r1` would collide with the official 50-conversation series (2026-11-19, 49e2785), and the verdict thresholds
(calibrated for 50 conversations) fail at 200, so every run ends with rc=2. That is expected, not a malfunction.

Setup: one laptop, 3 runs per value, interleaved A B A B A B (A = `OCTANE_WORKERS=2`, B = `OCTANE_WORKERS=4`),
`CONVERSATIONS=200`, observability stack down, git_dirty=false in all six runs, 0 missed messages in all six.
The real `--workers=` value of the container was read with `docker inspect` and is in `workers-map.txt`.

| Metric (median, min–max of 3) | workers=2 | workers=4 |
|---|---|---|
| send p50 (ms) | 726 (639–740) | 234 (213–239) |
| send p95 (ms) | 1599 (1450–1643) | 1302 (1198–1647) |
| rps | 97.9 (97.4–104) | 123.8 (123.2–126.4) |
| delivery p50 (ms) | 1298 (1190–1379) | 790 (726–1418) |
| delivery p95 (ms) | 2108 (1979–2628) | 3586 (3421–3975) |
| app CPU peak, median | 115.5 % | 167.3 % |
| pgbouncer CPU peak, median (limit 50 %) | 36.6 % | 50.4 % |
| redis-queue CPU peak, median (limit 50 %) | 40.3 % | 45.4 % |
| Postgres connections peak, median | 5 | 7 |

Reading: send p50 and rps are better with 4 workers (ranges do not overlap). Delivery p95 is worse with 4 workers
(ranges do not overlap). Send p95 and delivery p50 ranges overlap, so no difference is claimed for them.
pgbouncer sits at its 0.5 CPU limit and redis-queue is close to its own with 4 workers; this is consistent with the
bottleneck moving downstream, but this experiment did not isolate that cause.

Caveats: the first A run (091508Z) overlapped for about 35 s with a half-killed earlier run. It was kept: it is
better than the other two A runs, not worse, and medians are used. Per-run numbers are in the json files; the
medians above come from `octane-ab-report.sh`. Absolute values belong to this machine and are not comparable elsewhere.
Whether to change the default `OCTANE_WORKERS` is left to the maintainer; per `docs/queues.md` the sum of connections
must stay below pool=20 (peak here: 5 and 7).
