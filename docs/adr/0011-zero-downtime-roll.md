# ADR 0011: zero-downtime roll with Traefik's file provider instead of docker-rollout

Status: accepted (Task 11). The mechanism is `UNTESTED` until the first rehearsal with
`scripts/zero-downtime-check.sh`.

## Context

A deploy must replace the `app` containers without a failed HTTP request. Task 11 suggests vendoring
[docker-rollout](https://github.com/wowu/docker-rollout) "or an equivalent you can justify".

docker-rollout scales a service to twice its size, waits for the new containers to become healthy and removes the
old ones. It requires a rolled service without `container_name` and without published ports, a healthcheck, and a
reverse proxy that discovers containers and stops routing to unhealthy ones on its own: in practice Traefik's
**docker provider** or nginx-proxy, both reading the Docker API.

This repository routes with Traefik's **file provider** (`docker/traefik/dynamic.yml`): the upstream is the Docker
DNS name `app`, the `/internal` deny rule lives in that file, and the load tests measured exactly this topology.
Docker's DNS returns every running container of a service, healthy or not, so a plain scale-up would send requests
to containers that are still booting.

## Decision

Keep the file provider and no Docker socket. `scripts/deploy/roll.sh` does the roll itself:

1. write the IPs of the old containers into `docker/traefik/prod/dynamic/upstreams.yml` (hot-reloaded),
2. `docker compose up --no-recreate --scale app=N+T` (new containers start with the new image),
3. wait until the new containers are `healthy` (`/up`, healthcheck from `compose.yaml`),
4. write the IPs of the new containers, wait for Traefik to reload, let in-flight requests drain,
5. stop the old containers (SIGTERM, `stop_grace_period`) and remove them,
6. restore the DNS form of `upstreams.yml`, because container IPs do not survive a reboot.

If step 3 fails, the new containers are removed and the old ones never stopped serving.

## Why

- **No Docker socket in the internet-facing container.** The docker provider needs the socket (root-equivalent,
  see docs/observability.md) inside Traefik, or one more third-party image (a socket proxy) in front of it.
- **Nothing to vendor or download.** No third-party tool, no checksum to maintain, works in a restricted network.
- **Same routing model as the measured stack.** Steady state is byte-for-byte the DNS upstream of the dev stack;
  explicit IPs exist only for the seconds of a roll.
- It meets docker-rollout's own requirements (no `container_name`, no published ports, a healthcheck on `app`),
  so switching to docker-rollout later only means changing the provider, not the service definition.

## Consequences

- About 150 lines of bash to own (`roll.sh`), reviewed and linted in CI (`deploy-lint`), proven only by
  `scripts/zero-downtime-check.sh` during a real deploy.
- The reload wait (`ROLL_RELOAD_SECONDS`, default 5 s) and the drain time (`ROLL_DRAIN_SECONDS`, default 15 s) are
  configuration, not measurements. `TODO(measure)`: run a deploy under `make zero-downtime-check` and shorten them
  only with evidence.
- Recreating Traefik, Postgres, PgBouncer or Redis is outside this mechanism and not zero-downtime. `deploy.sh`
  detects such a change and refuses unless `--allow-infra-recreate` is passed.
- WebSockets are out of scope: a Reverb replica that stops closes its sockets and clients reconnect with backoff
  (docs/chat-protocol.md). The same roll is used for `reverb` only so that a new replica is already accepting
  connections when the old one goes away.
