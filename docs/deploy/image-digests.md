# Pinned image digests (Task 11)

Every third-party image that production runs is pinned by digest in `compose.prod.yml` (base image of the app:
`.github/workflows/deploy.yml`, base image of Postgres: `docker/postgres/Dockerfile` and
`.github/workflows/postgres-image.yml`). A tag can move; a digest cannot.

Resolved from the registries (manifest list / OCI index digest, valid for every platform) on
**2026-11-26T12:23Z** with the same requests as `scripts/deploy/pin-digests.sh` (`make pin-digests`).
Sanity check of the method: `edoburu/pgbouncer:latest` resolved to the digest `compose.yaml` already pins.

| Image (tag the digest was resolved from) | Digest | Used by |
|---|---|---|
| `dunglas/frankenphp:1-php8.5` (floating) | `sha256:b8e0919047838ea32771ef573d74df9a9128de1e908a999366e26c11bc6e7ba4` | build arg `FRANKENPHP_IMAGE` (app, horizon, reverb, scheduler, migrate) |
| `postgres:18` (floating) | `sha256:c699303802144847d238196173655dbbecf7a96aeca1565f2b02eb671a1f0b6b` | base of `docker/postgres/Dockerfile`, `postgres-glitchtip` |
| `redis:8` (floating) | `sha256:ca1cb6df9313d0186b68f19b60fc2802e0c76e8542b2c05650b560e7bbb3cc8f` | `redis-queue`, `redis-cache` |
| `traefik:v3.6` (floating minor) | `sha256:31267173a15b4944e797a76ffd9c419707c8d8b32fe5b610f80cd0cfa05f372d` | `traefik` |
| `edoburu/pgbouncer` | `sha256:8638aa563607256d3c7440437534195f1066a92b68015d89ae9d6b935b444a6d` | `pgbouncer` (already pinned in `compose.yaml`) |
| `glitchtip/glitchtip:v6.0.3` | `sha256:db857de5ac398e274d36f521b1cff0c76d714925b865e45b64fe0bd2f4499ec8` | `glitchtip`, `glitchtip-worker`, `glitchtip-migrate` |
| `prom/prometheus:v3.16.0` | `sha256:e04987641b57704f408e31f6373ba4138ecf27cb4a165d24aabb6c38fddd0f06` | `prometheus` |
| `grafana/grafana:12.4.14` | `sha256:ae509d9ae8c1021ca32ec370a9ca975d40165eda9fccd1e1102d317ff8787cd7` | `grafana` |
| `grafana/loki:3.6.20` | `sha256:be040ec94865dabe4821ce1c0f109452bd4b5acb566ae376672696eb43caedb7` | `loki` |
| `grafana/alloy:v1.21.3` | `sha256:8ab871206bd2d8f89277f53abffee5f452a293980788d52f85fa183a87a505a4` | `alloy` |
| `gcr.io/cadvisor/cadvisor:v0.52.1` | `sha256:f40e65878e25c2e78ea037f73a449527a0fb994e303dc3e34cb6b187b4b91435` | `cadvisor` |
| `prom/node-exporter:v1.12.1` | `sha256:1b4e4438faca4dd7e001dd445d161a4a2091b0fededa84093b3a8dfeae1f1be0` | `node-exporter` |
| `prometheuscommunity/postgres-exporter:v0.20.1` | `sha256:ac5ec343104fae0e2d84a27bb8d69b38430a11910c5382cad85d478d2bab713e` | `postgres-exporter` |
| `prometheuscommunity/pgbouncer-exporter:v0.12.1` | `sha256:30f31b6c2efdad3647f8182cc7c1a3a19e42bae5d17387694989f969371c230d` | `pgbouncer-exporter` |
| `oliver006/redis_exporter:v1.97.0-alpine` | `sha256:ae30be386ab25600a8b222538a868d1b156b7e411073f7d2d5208fe79cc98d81` | `redis-exporter-queue`, `redis-exporter-cache` |
| `grafana/k6:1.6.1` | `sha256:a5ad6bc089a08d77c3ec49f3db8c6fa7a148e4073efcac44c675dbaf3568d8e1` | not used in production (profile `loadtest`); listed for the record |

Not verified here: that these digests were pulled and started (no Docker in the authoring environment). The first
`docker compose pull` on a real host is that check. `UNTESTED`.

## Images built from this repository

| Image | Tag | Recorded values |
|---|---|---|
| `ghcr.io/<owner>/aghigh-app` | git SHA (40 hex) | built by `.github/workflows/deploy.yml`, target `prod` |
| `ghcr.io/<owner>/aghigh-postgres` | `18-pgbackrest-<n>`, set by hand | digest: `TODO` after the first run of `.github/workflows/postgres-image.yml`; `pgbackrest version`: `TODO` (printed in the run summary; then pin `PGBACKREST_VERSION` in `docker/postgres/Dockerfile`) |

## Updating a pin

1. `make pin-digests` and compare with this table.
2. Change the digest in `compose.prod.yml` (and the workflow / Dockerfile for the two base images) and this table in
   one commit. Read the upstream release notes first: a moved floating tag is a new version.
3. Deploy. A changed digest of Postgres, Redis, PgBouncer or Traefik recreates that container, which is **not**
   zero-downtime: the deploy refuses until it is started with `--allow-infra-recreate` (maintenance window).
4. Behind a registry mirror (`REGISTRY_PREFIX`, trailing slash) the digests stay the same as long as the mirror is a
   pull-through cache of the same registries.
