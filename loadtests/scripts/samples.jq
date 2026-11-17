# Task 10: aggregates a sampler NDJSON file (jq -s). Peaks feed the Task 11 capacity hand-off, slopes are the leak
# signal of the soak scenario. Slopes are plain least squares over the WHOLE sampler window (warm-up included), in
# units per hour; with fewer than 3 samples the slope is null. No verdict here: what counts as a leak is calibrated
# after the first baseline (loadtests/README.md).
def slope($xs; $ys):
  ($xs | length) as $n
  | if $n < 3 then null else
      (($xs | add) / $n) as $mx | (($ys | add) / $n) as $my
      | ([range(0; $n) | ($xs[.] - $mx) * ($ys[.] - $my)] | add) as $num
      | ([range(0; $n) | ($xs[.] - $mx) * ($xs[.] - $mx)] | add) as $den
      | if $den == 0 then null else ($num / $den * 3600 | . * 100 | round / 100) end
    end;

def series($samples; f):
  [$samples[] | {t: .ts, v: (f)} | select(.v != null)] as $points
  | if ($points | length) == 0 then null else {
      samples: ($points | length),
      first: $points[0].v,
      last: $points[-1].v,
      min: ($points | map(.v) | min),
      peak: ($points | map(.v) | max),
      slope_per_hour: slope($points | map(.t); $points | map(.v))
    } end;

. as $s
| ($s | map(.docker // [] | .[].name) | unique) as $containers
| ($s | map(.reverb // [] | .[].container) | unique) as $replicas
| {
    sampler_file: $file,
    samples: ($s | length),
    window_seconds: (if ($s | length) > 1 then ($s[-1].ts - $s[0].ts) else 0 end),
    note: "slope_per_hour = least squares over the whole sampler window; null below 3 samples. Units: bytes, connections, percent.",
    redis: {
      queue_used_memory_bytes: series($s; .redis.queue_used_memory),
      cache_used_memory_bytes: series($s; .redis.cache_used_memory)
    },
    postgres: { connections: series($s; .postgres.connections) },
    pgbouncer: {
      cl_active: series($s; .pgbouncer.cl_active),
      cl_waiting: series($s; .pgbouncer.cl_waiting),
      sv_active: series($s; .pgbouncer.sv_active),
      sv_idle: series($s; .pgbouncer.sv_idle),
      maxwait_seconds: series($s; .pgbouncer.maxwait)
    },
    app_metrics: { aghigh_metrics_series: series($s; .metrics_series) },
    containers: ($containers | map(. as $name | {
      key: $name,
      value: {
        mem_bytes: series($s; (.docker // [] | map(select(.name == $name)) | .[0].mem_bytes)),
        cpu_pct: series($s; (.docker // [] | map(select(.name == $name)) | .[0].cpu_pct)),
        pids: series($s; (.docker // [] | map(select(.name == $name)) | .[0].pids))
      }
    }) | from_entries),
    reverb: ($replicas | map(. as $name | {
      key: $name,
      value: {
        established_sockets: series($s; (.reverb // [] | map(select(.container == $name)) | .[0].established)),
        open_fds: series($s; (.reverb // [] | map(select(.container == $name)) | .[0].fds))
      }
    }) | from_entries)
  }
