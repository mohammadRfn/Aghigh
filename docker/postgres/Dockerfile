# Task 11: Postgres + pgBackRest (WAL archiving, full/incremental backups, PITR). Used ONLY by compose.prod.yml.
# The base image is pinned by digest (docs/deploy/image-digests.md); REGISTRY_PREFIX is applied by the caller.
ARG POSTGRES_BASE_IMAGE=postgres:18@sha256:c699303802144847d238196173655dbbecf7a96aeca1565f2b02eb671a1f0b6b
FROM ${POSTGRES_BASE_IMAGE}

# Restricted networks: point both apt sources at a mirror (no trailing slash needed). Empty = image defaults.
ARG APT_MIRROR_DEBIAN=""
ARG APT_MIRROR_PGDG=""
# Empty = the version PGDG ships for this base. The build prints `pgbackrest version`: record it next to the
# image tag in docs/deploy/image-digests.md, then pin it here (e.g. 2.xx.x-1.pgdg13+1) for reproducible rebuilds.
ARG PGBACKREST_VERSION=""

RUN set -eux; \
    printf 'Acquire::Retries "10";\nAcquire::http::Timeout "60";\nAcquire::https::Timeout "60";\n' > /etc/apt/apt.conf.d/80-network; \
    sources="$(find /etc/apt -type f \( -name '*.list' -o -name '*.sources' \))"; \
    if [ -n "$APT_MIRROR_DEBIAN" ] && [ -n "$sources" ]; then \
      echo "$sources" | xargs sed -i -E "s#https?://deb\.debian\.org#${APT_MIRROR_DEBIAN%/}#g"; \
    fi; \
    if [ -n "$APT_MIRROR_PGDG" ] && [ -n "$sources" ]; then \
      echo "$sources" | xargs sed -i -E "s#https?://apt\.postgresql\.org/pub/repos/apt#${APT_MIRROR_PGDG%/}#g"; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates openssh-client "pgbackrest${PGBACKREST_VERSION:+=$PGBACKREST_VERSION}"; \
    rm -rf /var/lib/apt/lists/*; \
    pgbackrest version

# Everything else (repository type, endpoint, keys, cipher passphrase, retention) arrives as PGBACKREST_* variables
# from the server-side env file (.env.pgbackrest.example): no secret is baked into the image.
# PGDATA is inherited from the base image, so the data path follows the base instead of being hardcoded.
ENV PGBACKREST_STANZA=aghigh \
    PGBACKREST_PG1_PATH=${PGDATA} \
    PGBACKREST_PG1_SOCKET_PATH=/var/run/postgresql \
    PGBACKREST_LOG_LEVEL_CONSOLE=info \
    PGBACKREST_LOG_LEVEL_FILE=off \
    PGBACKREST_START_FAST=y \
    PGBACKREST_COMPRESS_TYPE=zst
