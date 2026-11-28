# Production runbook (Task 11)

Container-based deployment: Docker Compose, Traefik, FrankenPHP/Octane, Horizon, Reverb, PgBouncer, Postgres,
two Redis roles. No Deployer, no symlink releases, no Nginx, PHP-FPM or Supervisor.

**Legend.** `UNTESTED` = written and linted, never executed against a real host. `TODO(measure)` = a number that
nobody measured yet, with the command that produces it. Numbers in this file come from `docs/benchmarks/`
(one laptop, 2026-11-19 to 2026-11-21, **not transferable in absolute terms**) or are marked as configuration.

## 1. Assumptions

Task 11 allows five questions before writing anything (server location, domain, registry, backup target, SMTP).
They were not answered when this was written, so these defaults were assumed. Change them in
`ansible/group_vars/all.yml` and the vault; nothing else hardcodes them.

| # | Assumption | Where to change it |
|---|---|---|
| A1 | One Ubuntu 24.04 VPS with normal internet access (not inside a restricted network), public IPv4, ports 22/80/443 reachable | restricted network: `REGISTRY_PREFIX` / `registry_prefix` (trailing slash), `docker_registry_mirrors`, `apt_mirror_url`, `apt_security_mirror_url`, `docker_repo_base_url`, Dockerfile args `APT_MIRROR_DEBIAN` / `APT_MIRROR_PGDG`, `ANSIBLE_GALAXY_SERVER` |
| A2 | A real domain whose A record points at the server: placeholder `aghigh.example.com` (API) and `https://app.example.com` (frontend origin). Let's Encrypt cannot issue for a bare IP | `app_domain`, `frontend_origin` |
| A3 | Let's Encrypt is reachable from the server and the HTTP-01 challenge on port 80 works | `acme_ca_server` (staging for rehearsals), another ACME CA, or DNS-01 (not implemented) |
| A4 | Registry = GHCR (`ghcr.io/<owner>/aghigh-app`, `aghigh-postgres`), private packages, the server logs in once with a read-only token | `app_image_repo`, `postgres_backup_image`, repository variable `REGISTRY` |
| A5 | Off-server backup target = an S3-compatible bucket (path-style). SFTP is supported by the same template | `backup_repo_type: sftp` and the `backup_sftp_*` variables |
| A6 | SMTP provider = any authenticated SMTP relay on port 587 (STARTTLS); placeholder `smtp.example.com` | `smtp_*`, `vault_smtp_*` |
| A7 | One node. No replica, no load balancer in front, no IPv6 publishing (`0.0.0.0` only) | out of scope |
| A8 | The frontend is hosted elsewhere and calls the API cross-origin (`CORS_ALLOWED_ORIGINS`, `JWT_AUDIENCE`, `AUTH_FRONTEND_URL` = frontend origin) | `frontend_origin` |
| A9 | The server clock is UTC (timer times below are server local time) | `app_timers` in `ansible/roles/app/defaults/main.yml` |
| A10 | The prod image is built with the Dockerfile default `UID=1000`; host directories the containers write to are owned by uid 1000 | `app_container_uid` |
| A11 | Observability (Grafana, Prometheus, Loki, GlitchTip) is optional on this host and off by default | `compose_profiles: observability` |
| A12 | GitHub-hosted runners can reach the server on port 22 | self-hosted runner, or run `ssh deploy@host deploy <sha>` by hand |

Facts in the task text that the repository contradicts (verified in the code, followed the code):

- **`REVERB_SCHEME=https` with the public port is wrong for this codebase.** `config/broadcasting.php` and
  `config/reverb.php` use `REVERB_HOST/PORT/SCHEME` for server-side publishing to `reverb:8080` on the backend
  network, and `/apps` is deliberately not routed publicly. Production keeps `REVERB_SCHEME=http`,
  `REVERB_HOST=reverb`, `REVERB_PORT=8080`; clients get `VITE_REVERB_HOST=<domain>`, `VITE_REVERB_PORT=443`,
  `VITE_REVERB_SCHEME=https` (wss through Traefik). `deploy.sh` enforces exactly this.
- **Reverb's `stop_grace_period` is not bound by the job timeout.** Reverb runs no queue jobs. Horizon: longest
  supervisor timeout is 100 s (`supervisor-default` in `config/horizon.php`; orders 60, notifications 60,
  broadcasts 30), `retry_after` 120 s, so `stop_grace_period: 110s`. Reverb: 30 s (`REVERB_STOP_GRACE`).
  The measured p99 job runtime is `TODO(measure)` (capacity-handoff row 9: `make k6-spike`, then read the
  `.metrics.prom` snapshot).
- **The migration checker's first run flags `add_saga_columns_to_orders.php` only inside `down()`**
  (`dropColumn`, line 23). Details in docs/migration-policy.md.
- **There is no real payment gateway.** `PAYMENT_GATEWAY=fake` is the only implementation; it declines a share of
  charges by design (`PAYMENT_FAKE_FAILURE_RATE=0.02`, `PAYMENT_FAKE_TIMEOUT_RATE=0.01` in the env examples).
  `card_declined` entries in `failed_jobs` are expected and are not an incident. Do not take real money with this.

## 2. Layout

```
/opt/aghigh/app      compose project directory: compose.yaml, compose.prod.yml, docker/, scripts/ (copied out of the image)
/opt/aghigh/shared   .env.production, .env.pgbackrest, deploy.env, jwt/, redis/auth.conf, pgbackrest/   (Ansible, vault)
/opt/aghigh/state    current_sha, previous_sha, history (last 5), releases.log, deploy.lock, conn-budget-<sha>.log
/var/log/aghigh      <service>.jsonl written by the PHP containers (logrotate: daily, 7 files)
```

- The app image contains the repository (minus `.dockerignore`), so each image carries the compose files, proxy
  configuration and deploy scripts of its own commit. The server needs no git checkout, and a rollback restores
  the matching compose model. Secrets are never in the image (`.env*` is ignored) or in any artifact.
- Published ports: Traefik `0.0.0.0:80`, `0.0.0.0:443`, `127.0.0.1:8081` (admin entrypoint). With the observability
  profile also Grafana, Prometheus, Loki and GlitchTip on `127.0.0.1`. Nothing else. Proven on every deploy and in
  CI by `scripts/deploy/verify-compose.sh` (`make prod-config`).
- **Docker bypasses UFW**: a published container port is reachable even if UFW denies it. The firewall role is
  defence for the host; what protects containers is that only Traefik publishes on `0.0.0.0`.
- Networks: `edge` (172.30.0.0/24, Traefik and what it routes to; `TRUSTED_PROXIES` equals this subnet),
  `backend` (internal), `egress` (new: outbound only, for Postgres -> backup repository and Horizon -> SMTP,
  because `backend` has no route out).
- Traefik overwrites client-supplied `X-Forwarded-*` (no `trustedIPs`, `insecure=false`). Prove it on the real
  stack with `make verify-proxy`. `UNTESTED` in production.

## 3. What was verified, and what was not

| Check | Result |
|---|---|
| `shellcheck -x` on every script | clean (shellcheck 0.11.0) |
| `yamllint` on compose.prod.yml, Traefik config, Ansible, workflows | clean (yamllint 1.38.0, `.yamllint`) |
| `actionlint` on all workflows | clean (actionlint 1.7.12) |
| `ansible-playbook --syntax-check`, `ansible-lint` | clean (ansible-core 2.19, ansible-lint 26.11) |
| `php -l` on changed PHP, `php scripts/check-migrations.php` on the 12 real migrations and on a synthetic bad one | ran (PHP 8.4 CLI): 1 finding in `down()`; 7 of 7 planted violations caught, ignore marker honoured |
| Image digests | resolved from the registries on 2026-11-26 (docs/deploy/image-digests.md) |
| `docker compose -f compose.yaml -f compose.prod.yml config` | `UNTESTED` (no Docker where this was written). CI job `deploy-lint` runs `verify-compose.sh`; locally `make prod-config` |
| `ansible-playbook --check --diff` twice against an Ubuntu 24.04 VM | `UNTESTED` (WSL2 is not equivalent: UFW, sysctl, THP) |
| Pint, Larastan, Pest for the three PHP changes and `tests/Feature/HorizonGateTest.php` | `UNTESTED`: run `make lint stan test` |
| Zero-downtime roll, automatic rollback, smoke test, retention | `UNTESTED`: first rehearsal, section 6 |
| pgBackRest stanza, backup, restore test, PITR | `UNTESTED`: section 9 |
| Sentry timeouts, `PGOPTIONS` in the migrate container, Horizon dashboard through the admin entrypoint | `UNTESTED` |

## 4. First-time server setup

On your workstation (Ansible >= 2.15), from `ansible/`:

```bash
ansible-galaxy collection install -r requirements.yml
cp inventory/production.example.yml inventory/production.yml          # host, first-run user
$EDITOR group_vars/all.yml                                            # domain, keys, mirrors, backup target
mkdir -p group_vars/production && cp group_vars/vault.example.yml group_vars/production/vault.yml
$EDITOR group_vars/production/vault.yml && ansible-vault encrypt group_vars/production/vault.yml
ansible-playbook playbook.yml --ask-vault-pass
ansible-playbook playbook.yml --ask-vault-pass --check --diff          # must report no change (UNTESTED)
```

- Secrets to generate first: `APP_KEY` (`php artisan key:generate --show` in the dev container), JWT keys
  (`php artisan identity:jwt-keys --kid=k1`, copy `storage/jwt/k1.*` into the vault), Reverb id/key/secret,
  database, Redis, webhook, metrics and Horizon admin tokens (`openssl rand -hex 32`; letters and digits only,
  some are interpolated into SQL), the backup cipher passphrase (store a copy **outside** the server).
- After the first run root and password logins are disabled: set `ansible_user` to the admin user (`ops`).
- The deploy user is in the `docker` group and therefore **root-equivalent**. The CI key is restricted by a forced
  command to `/usr/local/bin/aghigh-deploy` (`deploy <sha>`, `rollback [<sha>]`, `status`): a stolen CI key can
  deploy an image from the configured registry, it gets no shell, tunnel or file copy. Humans use the admin user.
- Registry login, once, as the deploy user (the token is not stored in Ansible):
  `sudo -u deploy docker login ghcr.io -u <user>` with a token that has only `read:packages`.
- GitHub: environment `production` with secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` and variables `DEPLOY_HOST`,
  `APP_DOMAIN` (header of `.github/workflows/deploy.yml`). Pin the host key: run `ssh-keyscan -t ed25519 <host>`
  once and compare the fingerprint with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server console.
- Unattended upgrades install security updates only; Docker packages are excluded; **no automatic reboot**.
  When `/var/run/reboot-required` exists, reboot in a window (section 12).

## 5. First deploy

1. Build the Postgres image: run the workflow "Postgres backup image" with tag `18-pgbackrest-1`, record the
   printed digest and `pgbackrest version` in docs/deploy/image-digests.md.
2. Rehearse with the staging CA first: `acme_ca_server` = staging URL, `acme_storage_file: acme-staging.json`,
   `ansible-playbook playbook.yml --tags app`. The smoke test then runs with `SMOKE_INSECURE=1` automatically.
3. Merge to `main`. CI runs, then the Deploy workflow builds `aghigh-app:<sha>`, pushes it and runs
   `ssh deploy@host "deploy <sha>"`. By hand: `ssh ops@host`, then `sudo -u deploy /usr/local/bin/aghigh-deploy deploy <sha>`.
   On an empty server this creates the volumes, runs the Postgres init scripts (roles), migrates, starts everything.
4. Backups: `sudo -u deploy /opt/aghigh/app/scripts/backup/backup.sh stanza-create`, then `check`, then `full`.
   Until the stanza exists `archive_command` fails and WAL accumulates (bounded by `PGBACKREST_ARCHIVE_PUSH_QUEUE_MAX`).
5. Run the restore test once by hand: `sudo systemctl start aghigh-restore-test.service; journalctl -u aghigh-restore-test -f`.
6. Switch to the production CA (`acme_ca_server` default, `acme_storage_file: acme.json`), `--tags app`, then
   redeploy the same SHA with infrastructure recreation allowed (Traefik's command changes):
   Deploy workflow -> Run workflow -> `allow_infra_recreate`.
7. Optional observability: `compose_profiles: observability`, write the metrics token to
   `/opt/aghigh/app/docker/observability/secrets/metrics_token` (same value as `METRICS_TOKEN`), redeploy, then
   follow "GlitchTip first run" in docs/observability.md.

## 6. Deploy

Every push to `main` that passes CI is deployed. Sequence (`scripts/deploy/deploy.sh`):

```
pull image -> remember previous SHA -> verify merged compose model -> environment guards -> infra up (no recreate)
-> one-off migrate container (migrator role, direct to Postgres, expand-only)
-> horizon:terminate + recreate Horizon (grace 110 s), scheduler
-> rolling restart app, then reverb (scripts/deploy/roll.sh, ADR 0011)
-> APP_DEBUG check, smoke test (/up, catalog read, security headers, /internal and /horizon closed, redirect)
-> record release, keep the last 5 SHAs (images + state/history), prune the rest
```

Failure of the migration, a healthcheck or the smoke test triggers the automatic rollback to the previous SHA.
Exit codes: 0 live, 1 failed and previous release serving, 2 rollback failed too (section 11.8).

**Zero downtime is a claim until proven.** The workflow runs `scripts/zero-downtime-check.sh` for the whole deploy
(constant 5 requests/s to `/up`, 1 request/s to `/api/v1/products?per_page=1`; rates are configuration) and fails
the run on a single non-2xx answer or connection error. First rehearsal result: `UNTESTED`. Locally against the
dev stack: `make zero-downtime-check BASE_URL=http://localhost:8000 DURATION=120` while restarting things.

- Scope: **HTTP only.** A Reverb replica that stops closes its WebSockets by design; clients reconnect with
  backoff (docs/chat-protocol.md). Excluded from the claim.
- Not zero-downtime, and refused unless `--allow-infra-recreate` (workflow input) is given: any change to the
  configuration or image of Traefik, Postgres, PgBouncer or Redis. Do those in a maintenance window.
- Queue processing pauses for the seconds Horizon restarts; jobs wait in Redis.
- `/up` is liveness and the container healthcheck. No readiness endpoint was added: the smoke test's catalog read
  goes through PgBouncer, Postgres and the cache Redis. If one is added later it belongs under `/internal`
  (never routed publicly) and must not become the container healthcheck.

**Connection budget during the roll.** Old and new `app` containers overlap and both use PgBouncer (pool 20;
docs/queues.md counts 15 clients in steady state, of which 2 are Octane workers, so an overlap adds
`OCTANE_WORKERS x APP_SCALE` clients on paper). `deploy.sh` samples `pg_stat_activity` and `SHOW POOLS` during
every deploy (`scripts/deploy/conn-budget.sh`) and prints the peaks.

| During a deploy | Value | Compare with (docs/benchmarks/capacity-handoff.md, laptop, under load, not during a deploy) |
|---|---|---|
| PgBouncer `sv_active` peak | `TODO(measure)`: read "peaks during this deploy" in the deploy log, or `/opt/aghigh/state/conn-budget-<sha>.log` | row 4: peak 3 / 2 / 2 of pool 20 (spike, 3 runs) |
| PgBouncer `cl_waiting` peak | `TODO(measure)`: same | row 5: 0 / 0 / 0 |
| Postgres client backends peak | `TODO(measure)`: same | row 6: 9 / 9 / 9 (spike); `max_connections` was not read in that series |

## 7. Rollback

One command, from anywhere with the CI key, or on the server:

```bash
ssh deploy@<host> rollback                                # previous release
sudo -u deploy /opt/aghigh/app/scripts/deploy/rollback.sh [<sha>]   # on the server; any SHA in /opt/aghigh/state/history
```

Or the Deploy workflow with `action = rollback`. It restores the compose files of that SHA from its image and
re-runs the release **without migrations**. It never runs `migrate:rollback`: the schema stays at the newer
version and the older code must work with it, which is what docs/migration-policy.md guarantees.
The last 5 deployed SHAs keep their images locally, so a rollback needs no registry. `ssh deploy@<host> status`
shows current, previous and history. `UNTESTED`.

If the failed deploy used `--allow-infra-recreate`, roll back with `ALLOW_INFRA_RECREATE=1` in the environment.

## 8. Admin tools

Everything is on `127.0.0.1`; reach it through an SSH tunnel as the admin user:

```bash
ssh -N -L 3000:127.0.0.1:3000 -L 9090:127.0.0.1:9090 -L 8090:127.0.0.1:8090 -L 8081:127.0.0.1:8081 ops@<host>
```

| Tool | URL through the tunnel | Notes |
|---|---|---|
| Grafana | http://localhost:3000 | profile `observability` |
| Prometheus | http://localhost:9090 | |
| GlitchTip | http://localhost:8090 | own Postgres instance, not backed up |
| Horizon | http://localhost:8081/horizon | Traefik `admin` entrypoint |

Horizon is a route inside the app, not a container. Before Task 11 its gate allowed nobody outside `local`
(empty e-mail list, `web` middleware, and there is no session user because JWT is stateless). Now: the public
entrypoint denies `/horizon` and strips `X-Admin-Edge-Token`; the admin entrypoint adds the header with
`HORIZON_ADMIN_TOKEN`; the gate compares it in constant time. Empty token = closed. `UNTESTED`; with
`SESSION_DRIVER=array` actions that need a CSRF token (retry from the UI) may answer 419: retry jobs with
`docker compose ... exec horizon php artisan queue:retry <id>` instead.

Compose on the server (as deploy):
`cd /opt/aghigh/app && APP_IMAGE_TAG=$(cat /opt/aghigh/state/current_sha) docker compose --env-file /opt/aghigh/shared/.env.production -f compose.yaml -f compose.prod.yml ps`
(below: `dc`).

## 9. Backups and restore

- Tool: pgBackRest inside the custom Postgres image (`docker/postgres/Dockerfile`). WAL-G was the alternative;
  pgBackRest covers archiving, full/incremental backups, retention, repository encryption, PITR and `check` in one
  tool, speaks S3 and SFTP, and is one `apt install` from PGDG on the pinned base image.
- Repository: **off-server** (S3-compatible by default, SFTP optional), encrypted with `aes-256-cbc`
  (`PGBACKREST_REPO1_CIPHER_PASS`; losing the passphrase loses every backup).
- WAL archiving: `archive_mode=on`, `archive_command=pgbackrest archive-push %p`, `archive_timeout=300` (a segment
  is shipped at least every 5 minutes: worst-case loss on total server loss; configuration, not measured).
- Schedule (systemd timers, role app): full Sunday 02:00, incremental Monday to Saturday 02:00, restore test
  Wednesday 04:00. Retention: 4 full backups (`PGBACKREST_REPO1_RETENTION_FULL`), WAL kept as long as a retained
  backup needs it, so the PITR window is about four weeks.
- **GlitchTip is excluded by running it on its own Postgres instance** (`postgres-glitchtip`). pgBackRest backs
  up and archives a whole cluster; one database cannot be left out of WAL archiving, and GlitchTip's daily event
  partitions would dominate the WAL stream. Its events are disposable (`GLITCHTIP_EVENT_LIFE_DAYS`); it is not
  archived and not backed up. `02-observability-roles.sh` skips its role and database on the application cluster
  when `GLITCHTIP_SHARED_CLUSTER=false`.
- Backup size and duration: `TODO(measure)`: `scripts/backup/backup.sh info` after the first full backup.

```bash
sudo -u deploy /opt/aghigh/app/scripts/backup/backup.sh info      # backups and the WAL range
sudo -u deploy /opt/aghigh/app/scripts/backup/backup.sh check     # archive + repository round trip
systemctl list-timers 'aghigh-*'
```

**Restore test (weekly, automated).** `scripts/backup/restore-test.sh` restores the latest backup into a scratch
container on port 5433 (internal network), replays archived WAL, runs sanity queries and
`orders:verify-invariants` on the `aghigh` database, then reports to the journal and to the webhook/mail hook
(`backup_webhook_url`, `backup_mail_to`). Restore duration: `TODO(measure)`: the report prints it.

**Restore the latest state (server lost or data directory destroyed).** `UNTESTED`.

```bash
dc stop app horizon scheduler reverb pgbouncer postgres
docker run --rm --network aghigh_egress --env-file /opt/aghigh/shared/.env.pgbackrest --user postgres \
  -v aghigh_pgdata:/var/lib/postgresql -v /opt/aghigh/shared/pgbackrest:/etc/pgbackrest/keys:ro \
  --entrypoint bash "$POSTGRES_BACKUP_IMAGE" -ec 'mkdir -p "$PGDATA" && chmod 700 "$PGDATA" && pgbackrest --delta restore'
dc up -d --wait postgres          # replays WAL to the end of the archive, then promotes
dc up -d                          # pgbouncer and the application
sudo -u deploy /opt/aghigh/app/scripts/backup/backup.sh full
```

On a new server: run Ansible, deploy once so the volumes and images exist, then the block above.

**Point-in-time restore (bad migration, deleted data).** `UNTESTED`.

1. Find the target time (UTC, just before the damage): application logs, `X-Request-Id`, `releases.log`.
2. Rehearse on a scratch copy first: `RESTORE_TEST_TYPE=default` restores to the end; for a specific time run the
   `docker run ... pgbackrest` line below against a scratch volume and inspect the data.
3. Stop writers and Postgres: `dc stop app horizon scheduler reverb pgbouncer postgres`.
4. Keep the damaged data: `docker run --rm -v aghigh_pgdata:/from -v /opt/aghigh/pgdata-before-pitr:/to --entrypoint cp "$POSTGRES_BACKUP_IMAGE" -a /from/. /to/` (needs the disk space).
5. Restore to the target:

```bash
docker run --rm --network aghigh_egress --env-file /opt/aghigh/shared/.env.pgbackrest --user postgres \
  -v aghigh_pgdata:/var/lib/postgresql -v /opt/aghigh/shared/pgbackrest:/etc/pgbackrest/keys:ro \
  --entrypoint pgbackrest "$POSTGRES_BACKUP_IMAGE" \
  --delta --type=time "--target=2026-12-01 10:15:00+00" --target-action=promote restore
dc up -d --wait postgres && dc up -d
```

6. Queues and Redis state are **not** rewound: jobs queued after the target time still exist in `redis-queue`.
   Inspect the queues before starting Horizon (`dc up -d` minus horizon), and run
   `dc exec app php artisan orders:verify-invariants`.
7. Take a full backup immediately (`backup.sh full`): the restored cluster is on a new timeline.

## 10. Rotate secrets

General path: change the vault, `ansible-playbook playbook.yml --tags app --ask-vault-pass`, then redeploy the
current SHA so the containers pick the file up: `ssh deploy@<host> "deploy $(ssh deploy@<host> status | awk '/current/{print $2}') --force"`.
All of this section is `UNTESTED`.

| Secret | Procedure | Downtime |
|---|---|---|
| `APP_KEY` | New key into `vault_app_key`, old key appended to `vault_app_previous_keys` (comma-separated, `config/app.php` reads `APP_PREVIOUS_KEYS`). Redeploy with `--force`. Remove the old key once nothing encrypted or signed with it can still be presented | none (roll) |
| JWT signing key (kid overlap) | Generate `k2` (`php artisan identity:jwt-keys --kid=k2` in a dev container). Put `k2` into the vault, set `jwt_active_kid: k2`, and publish the old **public** key under its kid in `vault_jwt_verification_keys` (`JWT_VERIFICATION_KEYS`, a JSON map kid -> public key; format in ADR 0003 and docs/api-identity.md). Redeploy. Tokens signed with `k1` stay valid until they expire (`JWT_ACCESS_TTL` = 10 minutes); refresh tokens are rows in SQL and are not affected. After one access TTL plus margin remove `k1` from the map and redeploy | none (roll) |
| `aghigh_app` DB password | Maintenance window. `ALTER ROLE aghigh_app PASSWORD '...'` as the superuser (`dc exec postgres psql -U postgres -d aghigh`), update the vault, redeploy with `--force --allow-infra-recreate` (PgBouncer builds its userlist from the environment at start, so it must be recreated). Between the `ALTER ROLE` and the PgBouncer restart new server connections fail | seconds (PgBouncer) |
| `aghigh_migrator`, `aghigh_monitor`, superuser | `ALTER ROLE ... PASSWORD`, update the vault, `--tags app`. Used only by one-off containers and the exporter (recreate `postgres-exporter`) | none |
| Redis password | Maintenance window. `dc exec redis-queue redis-cli CONFIG SET requirepass <new>` and the same on `redis-cache` (already authenticated connections keep working, new ones need the new password), update the vault (renders `redis/auth.conf` and `.env.production`), redeploy with `--force`. Until the roll finished, old containers cannot open new connections | short error window |
| Payment webhook secret | New value in `vault_payment_webhook_secret`, old value in `vault_payment_webhook_secret_previous` (`PAYMENT_WEBHOOK_SECRET_PREVIOUS` is accepted during the overlap). Redeploy, switch the sender, later empty the previous value and redeploy | none |
| Reverb app secret | Server-side only: vault, redeploy with `--force` (Reverb restarts: sockets reconnect). `REVERB_APP_KEY` is public and baked into clients: changing it needs a coordinated frontend release | sockets reconnect |
| `HORIZON_ADMIN_TOKEN` | Vault, `--tags app`, redeploy with `--allow-infra-recreate` (it is in Traefik's environment) | seconds (Traefik) |
| `METRICS_TOKEN` | Vault, `--tags app`, rewrite `docker/observability/secrets/metrics_token`, redeploy with `--force`, restart Prometheus | none |
| SMTP password, Sentry DSN | Vault, `--tags app`, redeploy with `--force` | none |
| CI deploy key | New key pair, `deploy_ci_public_key`, `--tags base`, update the GitHub secret `DEPLOY_SSH_KEY` | none |
| Registry token on the server | `sudo -u deploy docker login ghcr.io` again | none |
| Backup cipher passphrase | Cannot be changed in place. New repository path, new passphrase, `stanza-create`, full backup; keep the old repository until its retention ran out | none |
| Let's Encrypt account / certificates | Remove the storage file in the `aghigh_traefik-acme` volume and restart Traefik (new account, new certificates; mind the rate limits) | seconds |

## 11. Common incidents

`dc` = the compose command from section 8. Measured reference values are from docs/benchmarks/capacity-handoff.md.

### 11.1 Redis full under `noeviction` (`redis-queue`)
Symptoms: `OOM command not allowed when used memory > 'maxmemory'` in the logs; jobs cannot be queued; limiters
that fail closed answer 503 `rate-limit-unavailable` (auth, orders, admin).
Reference: peak `used_memory` 29.6 MiB in the spike and soak runs (row 1) against `REDIS_QUEUE_MAXMEMORY=300mb`
(configuration); growth over 2 h is `TODO(measure)`: `make k6-soak DURATION=2h` (row 3).

```bash
dc exec redis-queue redis-cli info memory | grep -E 'used_memory_human|maxmemory_human'
dc exec redis-queue redis-cli info keyspace          # db0 queues/Horizon/Reverb, db2 state, db3 metrics
dc exec redis-queue redis-cli -n 0 --bigkeys
```

Usual cause: Horizon is down or too slow and the queues grow (11.3). Relief without a restart:
`dc exec redis-queue redis-cli CONFIG SET maxmemory 400mb` (the container `mem_limit` is 384m by default: raise
`REDIS_QUEUE_MAXMEMORY` and `REDIS_QUEUE_MEM_LIMIT` together and persist them in group_vars, then redeploy with
`--allow-infra-recreate` in a window). **Never `FLUSHALL`, never flush db 0** (queued jobs) or db 2 (denylist,
limiter, idempotency).

### 11.2 PgBouncer pool exhaustion
Symptoms: rising latency, timeouts, `cl_waiting > 0` for more than a moment.
Reference: `sv_active` peak 3 / 2 / 2 of pool 20 and `cl_waiting` 0 in the spike runs (rows 4, 5).

```bash
dc exec -e PGPASSWORD="$DB_APP_PASSWORD" pgbouncer psql -h 127.0.0.1 -U "$DB_APP_USER" pgbouncer -c 'SHOW POOLS'
dc exec postgres psql -U postgres -d aghigh -c "select pid, usename, state, now() - xact_start as xact_age, left(query, 80) from pg_stat_activity where datname = 'aghigh' order by xact_start nulls last limit 20"
```

Usual causes: a long transaction or `idle in transaction` session holding a server connection, a migration
waiting for a lock (it fails after `lock_timeout` = 5 s, configuration), more workers than the budget in
docs/queues.md. Terminate the offender (`select pg_terminate_backend(<pid>)`); raise `PGBOUNCER_POOL_SIZE` only
together with the Postgres budget (`max_connections`), which recreates PgBouncer (seconds of errors, window).

### 11.3 Queue backlog
Check: Horizon dashboard (section 8), `dc exec horizon php artisan horizon:status`,
`dc exec redis-queue redis-cli -n 0 llen queues:orders` (queue names: orders, broadcasts, notifications, default).
Reference: after the spike the queue was empty within 0.005 s of the measurement start with 12 / 18 / 12 failed
jobs (row 10; the cause was not classified in those runs; one informal run saw only `card_declined`).
Failed `ChargePayment` jobs with `card_declined` are the fake gateway working as designed, **not** an incident.
Actions: `dc logs --tail 200 horizon`; restart Horizon (`dc restart horizon`, grace 110 s); raise
`HORIZON_*_MAX_PROCESSES` only within the PgBouncer and memory budgets (Horizon container peak 693.2 MiB against
`HORIZON_MEM_LIMIT=1280m`, row 11); retry with `php artisan queue:retry`.

### 11.4 WebSocket reconnect storm
After a Reverb restart (every deploy) or a network blip all clients reconnect. Clients must back off with jitter
(docs/chat-protocol.md); channel authorization is limited by `broadcasting-auth` (429 tells clients to wait).
Reference: approximate ceiling 4203 / 4142 / 4349 sockets on **one** replica on the laptop (row 7); two replicas
were not measured: `TODO(measure)`: `make k6-websocket-chat MODE=ceiling REVERB_SCALE=2`; reconnect behaviour:
`make k6-websocket-chat MODE=reconnect`.
Actions: watch `dc logs reverb`, Traefik open connections, app 429 rate; scale `reverb_scale: 2` (fan-out through
Redis is already on); do not restart Reverb again while a storm is in progress.

### 11.5 Certificate renewal failure
Traefik renews on its own well before expiry. Check:

```bash
echo | openssl s_client -connect <domain>:443 -servername <domain> 2>/dev/null | openssl x509 -noout -enddate
dc logs traefik 2>&1 | grep -i -E 'acme|certificate' | tail -n 50
```

Causes: port 80 not reachable from the internet (HTTP-01), DNS no longer points here, Let's Encrypt rate limit,
CA unreachable from the server's network (then use another ACME CA through `acme_ca_server`). Traefik keeps
serving the old certificate until it expires. Rehearse changes against the staging CA (separate storage file).

### 11.6 Disk full
```bash
df -h; docker system df; du -sh /var/log/aghigh /var/lib/docker/volumes/* 2>/dev/null | sort -h | tail
dc exec postgres psql -U postgres -c 'select * from pg_stat_archiver'      # failed_count rising = archiving is broken
```

Candidates: WAL piling up because the backup repository is unreachable (`backup.sh check`; fix the repository,
do not delete files from `pg_wal` by hand), Prometheus/Loki data (retention in group_vars), images
(`docker image prune`; the deploy keeps 5 releases), `/var/log/aghigh` (logrotate keeps 7 days), container logs
(json-file, 10 MB x 3 per container). If Postgres is already down for lack of space, free space elsewhere first.

### 11.7 Failed migration
The deploy stopped before any container changed and the previous release is serving. Read the output of the
`migrate` step in the workflow log. `lock_timeout`: rerun off-peak. A failed `CREATE INDEX CONCURRENTLY` leaves an
invalid index: `select indexrelid::regclass from pg_index where not indisvalid`, `DROP INDEX CONCURRENTLY`, rerun.
Fix forward with a new commit. Never `migrate:rollback` in production.

### 11.8 Failed deploy and failed rollback (exit code 2)
```bash
ssh deploy@<host> status
dc ps                                                    # which containers run which image
cat /opt/aghigh/app/docker/traefik/prod/dynamic/upstreams.yml
```

If `upstreams.yml` still lists container IPs, restore the DNS form (`http://app:8000`, `http://reverb:8080`; the
tracked file shows it) so Traefik follows whatever `app` containers are running, then
`sudo -u deploy /opt/aghigh/app/scripts/deploy/rollback.sh <last good sha>`.

## 12. Reboot and engine upgrades

A reboot is downtime on a single node (duration `TODO(measure)`: time `reboot` to the first 200 from `/up`).
Containers return through `restart: unless-stopped`; the THP unit runs before Docker; Traefik follows DNS names,
so changed container IPs do not matter. Docker engine upgrades are manual (`apt install docker-ce ...`);
`live-restore` keeps containers running while the daemon restarts. After either, run
`BASE_URL=https://<domain> scripts/smoke-test.sh`.

## 13. Sentry / GlitchTip

With an unreachable DSN one exploratory run held each sampled request about 12 s (single run, not in
docs/benchmarks). `config/sentry.php` now sets `http_connect_timeout` (1 s) and `http_timeout` (2 s) from
`SENTRY_HTTP_CONNECT_TIMEOUT` / `SENTRY_HTTP_TIMEOUT`, and production keeps `SENTRY_TRACES_SAMPLE_RATE=0.0`.
`UNTESTED`: after setting a DSN, stop GlitchTip and measure a request that reports an error:
`TODO(measure)`: `make k6-metrics-overhead METRICS=traces-on` with GlitchTip stopped. Only then raise the sample rate.
