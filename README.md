# Aghigh — Task 9: Observability stack (شاخهٔ `feat/observability`)

این پوشه خروجی کامل Task 9 است: **همهٔ فایل‌ها با همان مسیر نسبی که باید داخل ریپوی Aghigh قرار بگیرند** (ریشهٔ پروژه = ریشهٔ ریپو، بعد از PR #21 روی `main`).
دو راه برای اعمال وجود دارد:

1. **پچ گیت (پیشنهادی):** `git checkout -b feat/observability main && git apply --3way deliverable/aghigh-task9-observability.patch`
2. **کپی دستی:** هر فایل این پوشه را روی همان مسیر در ریپو کپی/جایگزین کنید (جدول پایین). فایل‌های «تغییر یافته» نسخهٔ کامل و نهایی هستند (نه تکه‌کد)، چون روی commit `09158a9` (feat/chat #21) ساخته شده‌اند.

> ⚠️ **وضعیت: UNTESTED.** من به داکر و PHP دسترسی نداشتم؛ کد بر اساس سورس نسخه‌های پین‌شده نوشته شده و فقط YAML/JSON ها از نظر نحوی چک شده‌اند. اولین `make lint stan test test-int obs-rules-test obs-verify` روی ماشین شما بخشی از تحویل این تسک است. هر چیزی که اجرا نشده در داکیومنت‌ها با `UNTESTED` و هر عددی که اندازه‌گیری نشده با `provisional` / `TODO(measure)` علامت خورده است.

## پیش‌نیاز: نصب کتابخانهٔ Prometheus (داخل کانتینر، نه روی هاست)
`composer.json`/`composer.lock` را دستی تغییر نداده‌ام (lock باید با `composer require` به‌روز شود وگرنه `composer audit --locked` در CI می‌شکند):
```
docker compose --env-file .env.docker exec app composer require promphp/prometheus_client_php:^2.15.1
```
نسخهٔ 2.15.1 (2026-05-28) `php ^8.2` می‌خواهد، پس PHP 8.5 را قبول می‌کند؛ اینکه خود کتابخانه روی 8.5 تست شده باشد را تأیید نکرده‌ام → بعد از نصب `make test` را بزنید.

## جدول مسیر فایل‌ها (deliverable/… → همان مسیر در ریپو)
| مسیر | نوع |
|---|---|
| `.env.docker.example` | تغییر یافته (modified) |
| `.env.example` | تغییر یافته (modified) |
| `.github/workflows/ci.yml` | تغییر یافته (modified) |
| `.gitignore` | تغییر یافته (modified) |
| `AGENTS.md` | تغییر یافته (modified) |
| `Makefile` | تغییر یافته (modified) |
| `Modules/Chat/app/Listeners/RecordChatMetrics.php` | جدید (new) |
| `Modules/Chat/app/Providers/EventServiceProvider.php` | تغییر یافته (modified) |
| `Modules/Ordering/app/Listeners/RecordOrderMetrics.php` | جدید (new) |
| `Modules/Ordering/app/Providers/EventServiceProvider.php` | تغییر یافته (modified) |
| `Modules/Ordering/app/Services/IdempotencyCache.php` | تغییر یافته (modified) |
| `Modules/Shared/app/Console/MetricsReset.php` | جدید (new) |
| `Modules/Shared/app/Contracts/Metrics.php` | جدید (new) |
| `Modules/Shared/app/ErrorReporting/ErrorReporting.php` | جدید (new) |
| `Modules/Shared/app/ErrorReporting/SentryScrubber.php` | جدید (new) |
| `Modules/Shared/app/Http/Controllers/MetricsController.php` | جدید (new) |
| `Modules/Shared/app/Http/Middleware/MetricsToken.php` | جدید (new) |
| `Modules/Shared/app/Http/Middleware/RecordHttpMetrics.php` | جدید (new) |
| `Modules/Shared/app/Logging/RedactSensitiveData.php` | تغییر یافته (modified) |
| `Modules/Shared/app/Logging/SensitiveKeys.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Catalog.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Collectors/QueueGauges.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Collectors/ReverbGauges.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Failures.php` | جدید (new) |
| `Modules/Shared/app/Metrics/LabelValue.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Listeners/QueueEventMetrics.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Listeners/RateLimitMetrics.php` | جدید (new) |
| `Modules/Shared/app/Metrics/MetricDefinition.php` | جدید (new) |
| `Modules/Shared/app/Metrics/MetricsStore.php` | جدید (new) |
| `Modules/Shared/app/Metrics/Names.php` | جدید (new) |
| `Modules/Shared/app/Metrics/NullMetrics.php` | جدید (new) |
| `Modules/Shared/app/Metrics/PrometheusMetrics.php` | جدید (new) |
| `Modules/Shared/app/Metrics/ScrapeCache.php` | جدید (new) |
| `Modules/Shared/app/Providers/MetricsServiceProvider.php` | جدید (new) |
| `Modules/Shared/app/Providers/SharedServiceProvider.php` | تغییر یافته (modified) |
| `Modules/Shared/config/config.php` | تغییر یافته (modified) |
| `Modules/Shared/tests/Feature/MetricsEndpointTest.php` | جدید (new) |
| `Modules/Shared/tests/Feature/SentryScrubTest.php` | جدید (new) |
| `bootstrap/app.php` | تغییر یافته (modified) |
| `compose.yaml` | تغییر یافته (modified) |
| `config/database.php` | تغییر یافته (modified) |
| `config/sentry.php` | جدید (new) |
| `docker/observability/alloy/config.alloy` | جدید (new) |
| `docker/observability/grafana/dashboards/api.json` | جدید (new) |
| `docker/observability/grafana/dashboards/db.json` | جدید (new) |
| `docker/observability/grafana/dashboards/orders.json` | جدید (new) |
| `docker/observability/grafana/dashboards/queues.json` | جدید (new) |
| `docker/observability/grafana/dashboards/redis.json` | جدید (new) |
| `docker/observability/grafana/dashboards/websocket.json` | جدید (new) |
| `docker/observability/grafana/provisioning/dashboards/dashboards.yml` | جدید (new) |
| `docker/observability/grafana/provisioning/datasources/datasources.yml` | جدید (new) |
| `docker/observability/loki/loki.yml` | جدید (new) |
| `docker/observability/prometheus/prometheus.yml` | جدید (new) |
| `docker/observability/prometheus/rules/aghigh.rules.test.yml` | جدید (new) |
| `docker/observability/prometheus/rules/aghigh.rules.yml` | جدید (new) |
| `docker/observability/secrets/.gitignore` | جدید (new) |
| `docker/observability/verify-allowlist.txt` | جدید (new) |
| `docker/postgres/init/02-observability-roles.sh` | جدید (new) |
| `docker/traefik/dynamic.yml` | تغییر یافته (modified) |
| `docs/adr/0010-metrics-redis-db-and-cardinality.md` | جدید (new) |
| `docs/observability.md` | جدید (new) |
| `docs/queues.md` | تغییر یافته (modified) |
| `docs/security.md` | تغییر یافته (modified) |
| `phpunit.xml` | تغییر یافته (modified) |
| `scripts/obs-keys.sh` | جدید (new) |
| `scripts/obs-query-check.php` | جدید (new) |
| `scripts/obs-verify.sh` | جدید (new) |
| `tests/Arch/ModulesTest.php` | تغییر یافته (modified) |
| `tests/Concurrency/MetricsCounterTest.php` | جدید (new) |
| `tests/Concurrency/metrics-worker.php` | جدید (new) |
| `aghigh-task9-observability.patch` | پچ گیت همهٔ موارد بالا (فقط برای راه ۱) |

## ترتیب اجرا بعد از قرار دادن فایل‌ها
```
make init                                   # اگر .env.docker ندارید
# خطوط بخش "Task 9" از .env.docker.example را به .env.docker خودتان اضافه کنید (پایین، بخش Manual steps)
docker compose --env-file .env.docker exec app composer require promphp/prometheus_client_php:^2.15.1
make obs-keys                               # METRICS_TOKEN, GRAFANA_ADMIN_PASSWORD, GlitchTip/monitor secrets + فایل توکن Prometheus
make monitoring-role                        # روی volume موجود Postgres: نقش aghigh_monitor + دیتابیس/نقش glitchtip
docker compose --env-file .env.docker up -d --force-recreate app horizon reverb scheduler
make pull                                   # تگ‌های پین‌شدهٔ پروفایل observability را هم می‌کشد
make obs-up
make lint stan test test-int obs-rules-test
make obs-verify                             # اثبات: provisioning، رد شدن /internal در Traefik، هر کوئری داشبورد/قانون غیرخالی
```

---

## Summary
- **Phase 1 (سرویس‌ها، پروفایل `observability`):** Prometheus v3.16.0، Grafana 12.4.14، Loki 3.6.20، Grafana Alloy v1.21.3 (Promtail EOL)، cAdvisor v0.52.1، node_exporter v1.12.1، postgres_exporter v0.20.1 (نقش اختصاصی `aghigh_monitor` با `pg_monitor`، مستقیم به Postgres، `CONNECTION LIMIT 3`)، pgbouncer_exporter v0.12.1، دو redis_exporter v1.97.0 (یکی برای هر instance؛ keyspace به ازای هر db، memory به ازای instance)، متریک‌های Traefik روی entrypoint منتشرنشدهٔ `:8082`، GlitchTip v6.0.3 (web/worker/migrate، دیتابیس و نقش جدا، حالت Postgres-only). همهٔ تگ‌ها روی رجیستری‌ها **چک شدند که وجود دارند**؛ همه `cpus`/`mem_limit` env-tunable، `logging: *logging`، `restart: unless-stopped` (migrate یک‌باره: `"no"`). UI ها فقط روی 127.0.0.1. `make pull` هر دو پروفایل را می‌کشد. Traefik: روتر `internal-deny` (priority 100، `ipAllowList 192.0.2.1/32` → 403) جلوی `PathPrefix(/internal)` را می‌گیرد.
- **Phase 2 (متریک‌های اپ):** قرارداد `Modules\Shared\Contracts\Metrics` + `Metrics\Catalog` (نام، نوع، HELP، نام لیبل‌ها، bucket ها یک‌جا) + `Names`. درایورها: `redis` (promphp روی کانکشن اختصاصی `metrics` = redis-queue db 3)، `memory` (تست، strict)، `null` (`METRICS_ENABLED=false`). نوشتن هرگز خطا نمی‌دهد (`Metrics\Failures`: شمارندهٔ درون‌پروسه + لاگ حداکثر یک‌بار در دقیقه + flush به `aghigh_metrics_write_failures_total`). HTTP در `terminate()`؛ لیبل‌ها فقط route name/URI template، method، status class، `unmatched`. صف‌ها در زمان scrape از `QueueMetrics::snapshot()` (کش ۵ ثانیه؛ `null` = حذف نمونه). wait/runtime از رویدادهای worker (`pushedAt` هورایزن **در سورس v5.50.1 تأیید شد**). سفارش‌ها (Ordering listener)، چت (Chat listener)، rate-limit، state-store errors، `reverb_pusher_api_connections` با فراخوانی امضاشده (timeout ≤ 1s، کش ۱۰s، `reverb_scrape_up`). `/internal/metrics` با bearer `METRICS_TOKEN` (constant-time؛ خالی = 404). `php artisan metrics:reset` (در production رد می‌شود).
- **Phase 3 (لاگ و خطا):** Alloy از سوکت داکر (دلیل و هزینهٔ امنیتی مستند)، لیبل‌های Loki فقط `service/channel/level`، `request_id` به‌صورت structured metadata. Sentry SDK → GlitchTip: `config/sentry.php` منتشر شد، `ErrorReporting::register()` در `bootstrap/app.php` بدون دست زدن به `ProblemDetails::render`؛ `SentryScrubber::beforeSend` (callable استاتیک، نه closure) فقط 5xx و شکست جاب‌ها را می‌فرستد و با **همان لیست کلید** لاگ (`Logging\SensitiveKeys`، استخراج‌شده از `RedactSensitiveData`) پاک‌سازی می‌کند؛ `send_default_pii=false`، `traces_sample_rate` پیش‌فرض 0.0 (سقف 0.05)، release از `GIT_SHA`.
- **Phase 4:** شش داشبورد JSON (API, DB, Redis, Queues, WebSocket, Orders) با data link به Loki بر اساس متغیر `request_id`؛ ۱۷ قانون هشدار با `summary`/`runbook` و تست `promtool` (`make obs-rules-test`)؛ `make obs-verify` هر PromQL داشبورد/قانون را بعد از ترافیک (`make k6 s=smoke` + `make verify-chat`) اجرا می‌کند و خالی‌ها را فقط با allowlist دلیل‌دار می‌پذیرد. بدون Alertmanager (صریحاً در داکیومنت).
- **Phase 5:** `docs/observability.md`، ADR 0010، به‌روزرسانی AGENTS.md / security.md / queues.md (بودجهٔ کانکشن exporter ها)؛ تست‌های Feature (توکن، غیرفعال، content-type، cardinality با ۲۰۰ مسیر تصادفی، scrub، خرابی Redis بدون شکست درخواست)، Arch (فقط Shared به `Prometheus\`/`Sentry\`/`Pusher\`)، Concurrency (۶ پروسهٔ واقعی × ۲۰۰ = دقیقاً ۱۲۰۰). `METRICS_DRIVER=memory` به خط `make test` و استپ quality در CI اضافه شد؛ `make test-int` از db 13 استفاده می‌کند.

## Open decisions
1. **db جداگانهٔ metrics روی همان instance صف (redis-queue db 3)** به‌جای instance جدا یا db `state`: بدون کانتینر جدید، ولی یک باگ cardinality می‌تواند حافظهٔ noeviction صف را بخورد → هشدارهای 80%/95% + پنل watchdog `aghigh_metrics_series` + ADR 0010. مسیر ارتقا: instance جدا اگر Task 10 رشد keyspace نشان داد.
2. **بدون Alertmanager در dev:** هشدارها فقط در Prometheus/Grafana دیده می‌شوند؛ Task 11 دربارهٔ notification تصمیم می‌گیرد.
3. **منبع لاگ Alloy = سوکت داکر** (read-only ولی root-equivalent). جایگزین: docker-socket-proxy فقط با `GET /containers`. انتخاب برای dev مستند شده است.
4. **pgbouncer_exporter با کاربر اپ** (`STATS_USERS=${DB_APP_USER}`): entrypoint تصویر edoburu فقط `DB_USER` را (در حالت scram به‌صورت plaintext) در `userlist.txt` می‌نویسد؛ نقش stats جدا به auth file سفارشی نیاز دارد. این تنها تغییر روی یک سرویس پیش‌فرض است (فقط حق `SHOW` روی کنسول). اگر روی هاست واقعی لاگین نشد (`pgbouncer_up==0`)، به saturation سمت Postgres برگردید (داشبورد DB دارد).
5. **GlitchTip در حالت Postgres-only** (`VALKEY_URL` خالی؛ از 5.2 پشتیبانی می‌شود، «experimental»، تگ موجود: v6.0.3؛ v6.0.10 که در وبلاگ‌ها آمده روی Docker Hub وجود ندارد). با `GLITCHTIP_VALKEY_URL` می‌توان Redis داد.
6. **`make monitoring-role` با superuser کانتینر Postgres اجرا می‌شود** (مثل `make test-db`) نه migrator: `CREATE ROLE`/`GRANT pg_monitor` با نقش migrator ممکن نیست.
7. **موارد تأییدنشده (UNTESTED):** نام فیلد `downstream_X-Request-Id` در access log Traefik؛ محل `request_id` در JSON لاگ (`extra` فرض شده، fallback به `context` در pipeline)؛ فلگ‌های metrics Traefik v3.6؛ نام env های Grafana 12؛ healthcheck های Prometheus/Grafana؛ اینکه cAdvisor/node_exporter روی WSL2 چه سری‌هایی می‌دهند و آیا TCP ESTABLISHED برای reverb در می‌آید (`make obs-verify` همه را در `docs/perf/obs-inventory.txt` ثبت می‌کند)؛ سازگاری promphp با PHP 8.5؛ موفق بودن `make lint stan test test-int` (بدون PHP اینجا قابل اجرا نبود).
8. `METRICS_DRIVER=memory` در `phpunit.xml` هم گذاشته شد (علاوه بر Makefile/CI) تا `php artisan test` داخل کانتینر بدون env صریح هم کار کند؛ `make test-int` با `METRICS_DRIVER=redis` آن را override می‌کند.

## Manual steps for the user
1. خطوط زیر را به **`.env.docker`** خود اضافه کنید (کپی از انتهای `.env.docker.example`؛ مقادیر خالی را `make obs-keys` پر می‌کند):
```
METRICS_ENABLED=true
METRICS_DRIVER=redis
METRICS_TOKEN=
REDIS_METRICS_DB=3
METRICS_QUEUE_CACHE_SECONDS=5
METRICS_REVERB_CACHE_SECONDS=10
METRICS_REVERB_TIMEOUT_SECONDS=1
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.0
GIT_SHA=
GRAFANA_ADMIN_PASSWORD=
GRAFANA_PORT=3000
PROMETHEUS_PORT=9090
LOKI_PORT=3100
GLITCHTIP_PORT=8090
GLITCHTIP_SECRET_KEY=
GLITCHTIP_DB_NAME=glitchtip
GLITCHTIP_DB_USER=glitchtip
GLITCHTIP_DB_PASSWORD=
GLITCHTIP_VALKEY_URL=
GLITCHTIP_EVENT_LIFE_DAYS=30
DB_MONITOR_USER=aghigh_monitor
DB_MONITOR_PASSWORD=
PROM_RETENTION_TIME=7d
PROM_RETENTION_SIZE=2GB
LOKI_RETENTION_PERIOD=168h
LOKI_INGESTION_RATE_MB=4
LOKI_INGESTION_BURST_MB=8
PROMETHEUS_IMAGE=prom/prometheus:v3.16.0
GRAFANA_IMAGE=grafana/grafana:12.4.14
LOKI_IMAGE=grafana/loki:3.6.20
ALLOY_IMAGE=grafana/alloy:v1.21.3
CADVISOR_IMAGE=gcr.io/cadvisor/cadvisor:v0.52.1
NODE_EXPORTER_IMAGE=prom/node-exporter:v1.12.1
POSTGRES_EXPORTER_IMAGE=prometheuscommunity/postgres-exporter:v0.20.1
PGBOUNCER_EXPORTER_IMAGE=prometheuscommunity/pgbouncer-exporter:v0.12.1
REDIS_EXPORTER_IMAGE=oliver006/redis_exporter:v1.97.0-alpine
GLITCHTIP_IMAGE=glitchtip/glitchtip:v6.0.3
PROMETHEUS_CPUS=1
PROMETHEUS_MEM_LIMIT=512m
GRAFANA_CPUS=0.5
GRAFANA_MEM_LIMIT=384m
LOKI_CPUS=1
LOKI_MEM_LIMIT=512m
ALLOY_CPUS=0.5
ALLOY_MEM_LIMIT=256m
CADVISOR_CPUS=0.5
CADVISOR_MEM_LIMIT=256m
NODE_EXPORTER_CPUS=0.25
NODE_EXPORTER_MEM_LIMIT=64m
POSTGRES_EXPORTER_CPUS=0.25
POSTGRES_EXPORTER_MEM_LIMIT=64m
PGBOUNCER_EXPORTER_CPUS=0.25
PGBOUNCER_EXPORTER_MEM_LIMIT=64m
REDIS_EXPORTER_CPUS=0.25
REDIS_EXPORTER_MEM_LIMIT=64m
GLITCHTIP_CPUS=1
GLITCHTIP_MEM_LIMIT=512m
GLITCHTIP_WORKER_CPUS=1
GLITCHTIP_WORKER_MEM_LIMIT=512m
```
2. `docker compose --env-file .env.docker exec app composer require promphp/prometheus_client_php:^2.15.1`
3. `make obs-keys` → سپس `make monitoring-role` (برای volume موجود Postgres؛ volume خالی این را از `02-observability-roles.sh` می‌گیرد).
4. کانتینرهایی که `.env.docker` را می‌خوانند دوباره بسازید: `docker compose --env-file .env.docker up -d --force-recreate app horizon reverb scheduler` (Traefik و PgBouncer هم چون `compose.yaml` عوض شده با `make up` بازسازی می‌شوند).
5. `make pull && make obs-up && make obs-rules-test && make obs-verify`.
6. **GlitchTip (دستی، DSN را compose نمی‌تواند بسازد):**
   - `docker compose --env-file .env.docker --profile observability exec glitchtip ./manage.py createsuperuser`
   - ورود به http://localhost:8090 → ساخت Organization و Project (Laravel/PHP) → کپی DSN.
   - DSN را با هاست داخلی بنویسید: `SENTRY_LARAVEL_DSN=http://<key>@glitchtip:8000/<project_id>` در `.env.docker` → دوباره `up -d --force-recreate app horizon reverb scheduler`.
   - تست: `docker compose --env-file .env.docker exec app php artisan tinker --execute 'report(new RuntimeException("glitchtip smoke"));'`
7. بعد از اولین اجرا: یک خط واقعی لاگ اپ را چک کنید (`request_id` در `extra` است یا `context`) و در صورت نیاز دو expression در `docker/observability/alloy/config.alloy` را اصلاح کنید؛ نام فیلد request-id در access log Traefik را از `docs/perf/obs-inventory.txt` بردارید؛ اسکرین‌شات‌های `docs/images/observability/*.png` را بگیرید؛ اعداد `TODO(measure)` را در `docs/observability.md` پر کنید.
8. قبل از هر اندازه‌گیری latency: `make obs-down` (پروفایل را جدا از baseline اجرا کنید؛ Task 10 هزینهٔ خود متریک‌ها را با `METRICS_ENABLED` می‌سنجد).
