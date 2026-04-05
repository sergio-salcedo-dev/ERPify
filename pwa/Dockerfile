#syntax=docker/dockerfile:1
#
# Stage layout aligned with api-platform/api-platform pwa/Dockerfile (npm instead of pnpm; package-lock.json).
# https://github.com/api-platform/api-platform/blob/main/pwa/Dockerfile
#
# Targets: dev (hot reload), builder (intermediate), prod (default — Next.js standalone on :3000).
# Browser traffic: FrankenPHP on php:80/443 reverse_proxies to pwa:3000.
# https://nextjs.org/docs/app/building-your-application/deploying#docker-image

FROM node:20-alpine AS node_upstream

# Shared dependencies (dev + build)
FROM node_upstream AS base

WORKDIR /srv/app

ENV NEXT_TELEMETRY_DISABLED=1

COPY package.json package-lock.json ./

RUN npm ci

# Development image (use with bind-mount of ./pwa → /srv/app, or run standalone)
FROM base AS dev

COPY . .

RUN mkdir -p public

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME=0.0.0.0

CMD ["sh", "-c", "npm install && npm exec next dev --turbo -H 0.0.0.0 -p 3000"]

FROM base AS builder

COPY . .

RUN mkdir -p public

ARG NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost
ENV NEXT_PUBLIC_SYMFONY_API_BASE_URL=${NEXT_PUBLIC_SYMFONY_API_BASE_URL}

RUN npm run build

# Production — copy standalone output (same idea as upstream `prod` stage)
FROM node_upstream AS prod

WORKDIR /srv/app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs && adduser --system --uid 1001 nextjs

COPY --from=builder --link /srv/app/public ./public
COPY --from=builder --chown=nextjs:nodejs /srv/app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /srv/app/.next/static ./.next/static

USER nextjs

ENV PORT=3000
ENV HOSTNAME=0.0.0.0

EXPOSE 3000

CMD ["node", "server.js"]
