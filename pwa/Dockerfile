#syntax=docker/dockerfile:1
#
# Stage layout aligned with api-platform/api-platform pwa/Dockerfile (npm instead of pnpm; package-lock.json).
# https://github.com/api-platform/api-platform/blob/main/pwa/Dockerfile
#
# Targets: dev (hot reload), builder (intermediate), prod (default — Next.js standalone on :3000).
# Browser traffic: FrankenPHP on php:80/443 reverse_proxies to pwa:3000.
# https://nextjs.org/docs/app/building-your-application/deploying#docker-image

FROM node@sha256:f5d1cc40abc10c2843339a2134d07817cf33c405cb16bfd052b0ed790254c3a3 AS node_upstream

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
# Absent secret is the normal case (local builds, CI jobs without the secret):
# the redirect swallows the missing file, the variable stays empty, and
# next.config.ts leaves upload off instead of failing the build.
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    npm run build

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
