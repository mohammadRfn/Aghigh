#!/usr/bin/env bash
# Task 10: the k6 image.
#   k6-image.sh check      (make k6-image-check)    which image compose resolves, is it pinned, is it present, k6 version
#   k6-image.sh fallback   (make k6-image-fallback) OFFLINE FALLBACK when the registry (mirror) cannot serve grafana/k6:
#       import the OFFICIAL release binary into a local image after checking its PUBLISHED sha256.
#       Files (downloaded, or copied by hand into K6_DOWNLOAD_DIR when this machine has no internet):
#         https://github.com/grafana/k6/releases/download/v<version>/k6-v<version>-linux-<arch>.tar.gz
#         https://github.com/grafana/k6/releases/download/v<version>/k6-v<version>-checksums.txt
#       The tarball must match the line for it in the checksums file (sha256sum -c). Set K6_SHA256 to pin the expected
#       digest yourself as well (e.g. a value you verified on another machine); a mismatch aborts.
#       Then put the printed K6_IMAGE / K6_PULL_POLICY lines into .env.docker.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-.env.docker}"
dc() { docker compose --env-file "$ENV_FILE" -f compose.yaml -f compose.loadtest.yaml "$@"; }
die() { echo "k6-image: $*" >&2; exit 1; }

case "${1:-check}" in
  check)
    command -v jq >/dev/null 2>&1 || die "jq is required"
    image="$(dc --profile loadtest config --format json | jq -r '.services.k6.image')"
    echo "compose resolves the k6 service to: $image"
    case "$image" in
      *@sha256:*) echo "pin: by digest (strongest)" ;;
      *:latest|*/k6|k6) die "the k6 image is NOT pinned ($image): set K6_IMAGE=grafana/k6:<exact version> in .env.docker" ;;
      *) echo "pin: by tag. To pin by digest, copy the RepoDigest printed below into K6_IMAGE=grafana/k6@sha256:<digest>" ;;
    esac
    if docker image inspect "$image" >/dev/null 2>&1; then
      docker image inspect "$image" --format 'local image id: {{.Id}}{{"\n"}}repo digests:   {{join .RepoDigests ", "}}'
    else
      echo "the image is not present locally: run 'make pull' (honours REGISTRY_PREFIX), or 'make k6-image-fallback' when the registry cannot serve it"
      exit 1
    fi
    echo -n "k6 version in the image: "
    dc --profile loadtest run --rm --no-deps -T k6 version
    ;;
  fallback)
    version="${K6_VERSION:-1.6.1}"
    case "$(uname -m)" in x86_64|amd64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; *) die "unsupported architecture $(uname -m)" ;; esac
    dir="${K6_DOWNLOAD_DIR:-loadtests/results/.k6-download}"
    tarball="k6-v$version-linux-$arch.tar.gz"
    sums="k6-v$version-checksums.txt"
    url="https://github.com/grafana/k6/releases/download/v$version"
    mkdir -p "$dir"
    for file in "$tarball" "$sums"; do
      if [ ! -s "$dir/$file" ]; then
        echo "downloading $url/$file"
        curl -fsSL --retry 3 -o "$dir/$file" "$url/$file" || die "download failed. Copy $tarball and $sums into $dir by hand and run this again."
      fi
    done
    echo "checking the published sha256"
    (cd "$dir" && grep -E "[[:space:]]$tarball\$" "$sums" | sha256sum -c -) || die "sha256 MISMATCH for $tarball: do not use this file"
    actual="$(sha256sum "$dir/$tarball" | awk '{print $1}')"
    if [ -n "${K6_SHA256:-}" ] && [ "$K6_SHA256" != "$actual" ]; then die "sha256 $actual does not match K6_SHA256=$K6_SHA256"; fi
    echo "sha256 ok: $actual"

    work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
    tar -xzf "$dir/$tarball" -C "$work"
    mkdir -p "$work/rootfs/usr/bin" "$work/rootfs/tmp" "$work/rootfs/etc/ssl/certs"
    cp "$work/k6-v$version-linux-$arch/k6" "$work/rootfs/usr/bin/k6"
    chmod 0755 "$work/rootfs/usr/bin/k6"; chmod 1777 "$work/rootfs/tmp"
    # CA bundle only matters for https targets; BASE_URL=http://traefik does not need it.
    [ -f /etc/ssl/certs/ca-certificates.crt ] && cp /etc/ssl/certs/ca-certificates.crt "$work/rootfs/etc/ssl/certs/"
    prefix="$({ grep -E '^REGISTRY_PREFIX=' "$ENV_FILE" || true; } | tail -1 | cut -d= -f2- | tr -d '"\r')"
    tag="aghigh-k6:$version-local"
    tar -C "$work/rootfs" -c . | docker import --change 'ENV PATH=/usr/bin' --change 'ENV HOME=/tmp' --change 'ENTRYPOINT ["/usr/bin/k6"]' - "$prefix$tag"
    echo
    echo "imported $prefix$tag (official k6 v$version binary, sha256 $actual). Put these lines into $ENV_FILE:"
    echo "  K6_IMAGE=$tag"
    echo "  K6_PULL_POLICY=never"
    echo "then: make k6-image-check"
    ;;
  *) die "usage: k6-image.sh check|fallback" ;;
esac
