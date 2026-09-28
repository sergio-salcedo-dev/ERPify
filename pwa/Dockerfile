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

# The release both bundles report: the commit SHA `make` exports for a
# prod/staging build, shared with the API so an issue maps to one deploy. Empty
# leaves events untagged. SENTRY_REPOSITORY (owner/name, as Sentry's GitHub
# integration lists it) turns on associating the release with that commit, which
# needs the upload token too.
ARG SENTRY_RELEASE=
ENV SENTRY_RELEASE=${SENTRY_RELEASE}
ARG SENTRY_REPOSITORY=
ENV SENTRY_REPOSITORY=${SENTRY_REPOSITORY}

# The auth token is a BuildKit SECRET, deliberately not an ARG. An ARG is
# recorded in the image metadata and `docker history` prints it, so a token
# passed that way leaks to anyone who can pull the image — including through a
# registry the deploy does not control. Mounted this way it exists only for the
# lifetime of this RUN and lands in no layer.
#
# docker/read-sentry-token.sh (copied in by the `COPY . .` above) reads and
# validates the mount and execs the build with SENTRY_AUTH_TOKEN in that
# process's environment alone. With no token the build log says upload is off;
# a broken mount fails the build without printing the value. Which shapes are
# which is owned by docs/rules/security.md ("Build-time secrets") and exercised
# by tests/read-sentry-token.test.ts.
RUN --mount=type=secret,id=sentry_auth_token \
    sh docker/read-sentry-token.sh npm run build

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
