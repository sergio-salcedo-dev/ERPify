#syntax=docker/dockerfile:1
#
# Stage layout aligned with api-platform/api-platform pwa/Dockerfile (npm instead of pnpm; package-lock.json).
# https://github.com/api-platform/api-platform/blob/main/pwa/Dockerfile
#
# Targets: dev (hot reload), builder (intermediate), prod (default — Next.js standalone on :3000).
# Browser traffic: FrankenPHP on php:80/443 reverse_proxies to pwa:3000.
# https://nextjs.org/docs/app/building-your-application/deploying#docker-image

FROM node@sha256:e961046fec20896e8904f2b4a8b4c7e5ca91826d84d8d33d83dbaa61f942069e AS node_upstream

# Shared dependencies (dev + build)
FROM node_upstream AS base

WORKDIR /app/pwa

ENV NEXT_TELEMETRY_DISABLED=1

# The base image ships its own npm (11.x); pin explicitly so this image, CI and
# dev machines resolve dependencies with one npm — the version declared in
# package.json#engines.npm. corepack is not an option: Node no longer bundles
# it, so it is absent here. Kept above the COPY so the layer caches across
# dependency changes.
RUN npm install --global npm@12.0.1

COPY package.json package-lock.json ./

RUN npm ci

# Development image (use with bind-mount of ./pwa → /app/pwa, or run standalone)
FROM base AS dev

COPY . .

RUN mkdir -p public

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# `npm ci` (not `install`): base already ran it, and the persistent
# pwa_node_modules volume keeps deps across restarts. `ci` is deterministic and,
# unlike `install`, never rewrites the bind-mounted package-lock.json — which
# would leave it root-owned on the host.
CMD ["sh", "-c", "npm ci && npx next dev --turbo -H 0.0.0.0 -p 3000"]

FROM base AS builder

COPY . .

RUN mkdir -p public

ARG NEXT_PUBLIC_API_BASE_URL=https://localhost
ENV NEXT_PUBLIC_API_BASE_URL=${NEXT_PUBLIC_API_BASE_URL}

ARG NEXT_PUBLIC_APP_ENV=dev
ENV NEXT_PUBLIC_APP_ENV=${NEXT_PUBLIC_APP_ENV}

# Sentry DSN, baked per environment image (selects the erpify-pwa-dev /
# erpify-pwa-prod project). Public by construction (the SDK runs client-side);
# empty default keeps the SDK inert when unset.
ARG NEXT_PUBLIC_SENTRY_DSN=
ENV NEXT_PUBLIC_SENTRY_DSN=${NEXT_PUBLIC_SENTRY_DSN}

# Sentry source-map upload. The org and the project slug are not secrets, so
# they are ordinary build args; the project defaults to the per-environment
# slug next.config.ts derives (erpify-pwa-dev / erpify-pwa-prod).
ARG SENTRY_ORG=
ENV SENTRY_ORG=${SENTRY_ORG}
ARG SENTRY_PROJECT=
ENV SENTRY_PROJECT=${SENTRY_PROJECT}

# The auth token is a BuildKit SECRET, deliberately not an ARG. An ARG is
# recorded in the image metadata and `docker history` prints it, so a token
# passed that way leaks to anyone who can pull the image — including through a
# registry the deploy does not control. Mounted this way it exists only for the
# lifetime of this RUN and lands in no layer.
#
# No token is the normal case (local builds, CI jobs without the secret), and it
# arrives in two shapes: a plain `docker build` without `--secret` mounts
# nothing, while Compose mounts an EMPTY file when SENTRY_AUTH_TOKEN is unset or
# empty. Both leave upload off and say so once in the build log. Anything else
# at that path — a directory, an unreadable file, a file holding more than one
# word (a whole env file pointed at by mistake) — is a broken mount, and it
# fails the build rather than degrading into a silent build with no upload.
#
# An empty SENTRY_ORG is unset rather than passed on: sentry-cli inherits the
# build environment, and an org-scoped `sntrys_` token already names its org.
RUN --mount=type=secret,id=sentry_auth_token \
    secret=/run/secrets/sentry_auth_token; \
    token=""; \
    if [ ! -e "$secret" ]; then \
        echo "sentry: no sentry_auth_token secret mounted, source-map upload is off"; \
    elif [ ! -f "$secret" ] || [ ! -r "$secret" ]; then \
        echo "sentry: $secret is mounted but is not a readable file" >&2; exit 1; \
    else \
        token="$(tr -d '[:space:]' < "$secret")" || exit 1; \
        if [ -z "$token" ]; then \
            echo "sentry: sentry_auth_token secret is empty, source-map upload is off"; \
        elif [ "$(wc -w < "$secret")" -ne 1 ]; then \
            echo "sentry: $secret holds more than one word, not an auth token" >&2; exit 1; \
        fi; \
    fi; \
    if [ -z "$SENTRY_ORG" ]; then unset SENTRY_ORG; fi; \
    SENTRY_AUTH_TOKEN="$token" npm run build

# Production — copy standalone output (same idea as upstream `prod` stage)
FROM node_upstream AS prod

WORKDIR /app/pwa

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs && adduser --system --uid 1001 nextjs

COPY --from=builder --link --chmod=555 /app/pwa/public ./public
COPY --from=builder --chmod=555 /app/pwa/.next/standalone ./
COPY --from=builder --chmod=555 /app/pwa/.next/static ./.next/static

USER nextjs

ENV PORT=3000
ENV HOSTNAME=0.0.0.0

EXPOSE 3000

CMD ["node", "server.js"]
