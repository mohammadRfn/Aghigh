import json, sys
f = sys.argv[1]
rows = [json.loads(l) for l in open(f) if l.strip()]
t0 = rows[0]["ts"]
MIB = 1048576
def dock(r, name): return next(d["mem_bytes"] for d in r["docker"] if d["name"] == name)
def series(get):
    out, skipped = [], 0
    for r in rows:
        try: out.append(((r["ts"] - t0) / 60, get(r) / MIB))
        except (StopIteration, KeyError, TypeError): skipped += 1
    return out, skipped
S = {
  "redis-queue used_memory": series(lambda r: r["redis"]["queue_used_memory"]),
  "horizon container":       series(lambda r: dock(r, "aghigh-horizon-1")),
  "app container":           series(lambda r: dock(r, "aghigh-app-1")),
  "redis-queue container":   series(lambda r: dock(r, "aghigh-redis-queue-1")),
}
def slope(pts):
    n = len(pts); mx = sum(p[0] for p in pts)/n; my = sum(p[1] for p in pts)/n
    d = sum((p[0]-mx)**2 for p in pts)
    return (sum((p[0]-mx)*(p[1]-my) for p in pts)/d)*60 if d else 0
print(f"rows={len(rows)} window={(rows[-1]['ts']-t0)/60:.1f} min")
for name, (pts, skipped) in S.items():
    print(f"\n== {name} (MiB), samples used={len(pts)} skipped={skipped}")
    print("  first=%.1f peak=%.1f last=%.1f" % (pts[0][1], max(p[1] for p in pts), pts[-1][1]))
    h1 = [p for p in pts if p[0] <= 60]; h2 = [p for p in pts if p[0] > 60]
    print("  slope 0-60 min: %.2f MiB/h | slope 60-end: %.2f MiB/h | whole window: %.2f MiB/h" % (slope(h1), slope(h2), slope(pts)))
    out = []
    for m in range(0, int(pts[-1][0]) + 1, 10):
        p = min(pts, key=lambda q: abs(q[0]-m)); out.append("%d:%.0f" % (m, p[1]))
    print("  every 10 min (min:MiB) " + " ".join(out))
