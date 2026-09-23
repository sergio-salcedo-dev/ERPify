#!/bin/sh
# Reads the `sentry_auth_token` BuildKit secret, validates it, and runs the
# command given as arguments with SENTRY_AUTH_TOKEN exported to that process
# alone. The Dockerfile's build step is `sh docker/read-sentry-token.sh npm run
# build` under `RUN --mount=type=secret,id=sentry_auth_token`: the mount exists
# only for that RUN, the value lives in this shell and the process it execs,
# and nothing here writes it to a file or prints it — so it lands in no layer
# and never in the build log.
#
# No token is the normal case (local builds, CI jobs without the secret), and it
# arrives in two shapes: a plain `docker build` without `--secret` mounts
# nothing, while Compose mounts an EMPTY file when SENTRY_AUTH_TOKEN is unset or
# empty. Both leave upload off, SENTRY_AUTH_TOKEN unset for the command, and the
# build log saying so. Anything else at that path is a broken mount and fails
# the build rather than degrading into one that sends Sentry a credential it
# will refuse. The exact list is owned by docs/rules/security.md ("Build-time
# secrets"); pwa/tests/read-sentry-token.test.ts runs every case.
#
# An env-line shape is refused by its leading `NAME=`, never by any `=`: an
# organisation token is `sntrys_<base64 payload>_<secret>` and its payload keeps
# the base64 `=` padding, so the prefix exempts it from that check.
#
# A SENTRY_ORG with no non-blank character is unset rather than passed on: the
# upload plugin falls back to the SENTRY_ORG environment variable when the
# config names no org, and a blank one would reach Sentry as an org slug.
#
# SENTRY_TOKEN_SECRET_PATH overrides the secret's path; it exists for the tests.
set -eu

if [ "$#" -eq 0 ]; then
    echo "sentry: usage: read-sentry-token.sh <command> [argument...]" >&2
    exit 2
fi

secret="${SENTRY_TOKEN_SECRET_PATH:-/run/secrets/sentry_auth_token}"

fail() {
    echo "sentry: $secret $1" >&2
    exit 1
}

token=""
if [ ! -e "$secret" ]; then
    echo "sentry: no sentry_auth_token secret mounted, source-map upload is off"
elif [ ! -f "$secret" ] || [ ! -r "$secret" ]; then
    fail "is mounted but is not a readable file"
else
    token="$(tr -d '[:space:]' < "$secret")"
    if [ -z "$token" ]; then
        echo "sentry: sentry_auth_token secret is empty, source-map upload is off"
    elif [ "$(wc -w < "$secret")" -ne 1 ]; then
        fail "holds more than one word, not an auth token"
    fi
    case "$token" in
        *\"* | *\'*) fail "holds a quote, not a bare auth token" ;;
        sntrys_*) ;;
        [A-Za-z_]*=*)
            case "${token%%=*}" in
                *[!A-Za-z0-9_]*) ;;
                *) fail "holds a NAME=value line, not a bare auth token" ;;
            esac
            ;;
    esac
fi

case "${SENTRY_ORG:-}" in
    *[![:space:]]*) ;;
    *) unset SENTRY_ORG ;;
esac

if [ -n "$token" ]; then
    SENTRY_AUTH_TOKEN="$token"
    export SENTRY_AUTH_TOKEN
else
    unset SENTRY_AUTH_TOKEN
fi

exec "$@"
