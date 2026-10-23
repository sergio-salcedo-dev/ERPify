#!/bin/sh
# Installs the PWA's dependencies unless node_modules already holds the tree an earlier run installed from these
# exact inputs. The image runs it once at build, and that node_modules seeds the dev container's named volume;
# the container runs it again on every start, ahead of `next dev`. A fresh stack therefore boots without
# touching the npm registry, and boot time depends on the registry only when the dependencies changed.
#
# The fingerprint covers what decides the tree — package.json, the lockfile, Node, npm, platform and arch,
# because the volume outlives image rebuilds and would otherwise keep native addons built for another ABI —
# plus the hidden lockfile npm rewrites inside node_modules on every install, so a tree emptied or edited by
# hand stops matching and is reinstalled. A fingerprint that cannot be computed is an error, never a match.
#
# The dev image installs this file at /usr/local/bin/pwa-install-deps, outside the ./pwa bind mount, so a
# container runs the copy its image was built with whatever source tree is mounted; it reads the inputs from
# the working directory.
set -eu

stamp=node_modules/.erpify-install-stamp

fingerprint() {
	digest=$(
		{
			node --version
			node -p 'process.platform + "-" + process.arch'
			npm --version
			cat package.json package-lock.json
			cat node_modules/.package-lock.json 2>/dev/null || :
		} | sha256sum | cut -d ' ' -f 1
	)
	if [ "${#digest}" -ne 64 ]; then
		echo "install-deps: cannot fingerprint node_modules (is sha256sum on PATH?)" >&2
		return 1
	fi
	printf '%s\n' "$digest"
}

expected=$(fingerprint)

if [ -f "$stamp" ] && [ "$(cat "$stamp")" = "$expected" ]; then
	echo "install-deps: node_modules matches its inputs; skipping npm ci"
	exit 0
fi

npm ci
installed=$(fingerprint)
printf '%s\n' "$installed" > "$stamp"
