#!/bin/sh
# Installs the PWA's dependencies unless node_modules already holds an install
# of this exact package-lock.json, made with this Node and this npm. The image
# runs it once at build, and that node_modules seeds the dev container's named
# volume; the container runs it again on every start, ahead of `next dev`. A
# fresh stack — every CI run — therefore boots without touching the registry,
# while a lockfile edit still reinstalls on the next start. Reinstalling
# unconditionally made the boot time a property of the registry: one slow
# mirror was enough to keep `next dev` silent past the compose healthcheck
# budget and fail an E2E shard before any test ran.
#
# The stamp covers Node and npm as well as the lockfile because the volume
# outlives image rebuilds: a Node upgrade over an unchanged lockfile would
# otherwise keep native addons built for the previous ABI.
set -eu

stamp=node_modules/.erpify-install-stamp
expected=$({ node --version; npm --version; cat package-lock.json; } | sha256sum | cut -d ' ' -f 1)

if [ -f "$stamp" ] && [ "$(cat "$stamp")" = "$expected" ]; then
	echo "install-deps: node_modules matches package-lock.json, Node and npm; skipping npm ci"
	exit 0
fi

npm ci
printf '%s\n' "$expected" > "$stamp"
