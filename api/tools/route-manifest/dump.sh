#!/bin/sh
#
# Emits the API route manifest on stdout, in the shape `api/.route-manifest.json` holds:
#
#     {
#       "<route-name>": { "path": "/api/v1/…", "methods": ["POST"] }
#     }
#
# Route names sorted, `methods` always a sorted array, 2-space indent, trailing newline.
# One line per route so a changed path is a one-line diff in review; JSON carries no
# comment, so the "do not hand-edit this" instruction lives in `php.lint.route-manifest`'s
# failure message rather than in the file.
#
# WHY THE ROUTER, AND WHY THE PROD ENVIRONMENT
#
# The public URL of a controller cannot be read off its `#[Route]` attribute: `config/routes.yaml`
# applies a prefix per DIRECTORY, and two sibling directories of one context differ —
# `Iam/Identity/Infrastructure/Http/` mounts under `/api/v1/backoffice` while
# `Iam/Identity/Infrastructure/Controller/` mounts under `/api/v1`. Moving a controller between
# them changes its public URL and touches no attribute. The router is the only correct source.
#
# The environment is `prod` because a manifest that vouches for a route production does not serve
# is worse than no manifest: a client constant pointing at it 404s in front of the user while every
# gate stays green. Measured on this tree: the test kernel declares 74 routes and the prod kernel
# 42 — the 32 extras are `/api/test/*` (`when@test` routing imports), `/api/v1/dev/*`
# (`#[When(env: 'dev')]`) and the profiler/web-debug-toolbar set. Filtering those by name would be
# an enumeration of what somebody remembered; asking the prod kernel is the mechanism.
#
# `debug:router` reads the route collection through the loader chain on every call rather than
# through the compiled matcher cache, so a stale `var/cache/prod` does not stale the output.
# Measured both directions: an edited `#[Route]` path and an edited `routes.yaml` prefix each
# showed up with no `cache:clear` in between.
#
# WHAT A GENERATED MANIFEST DOES NOT PROVE
#
#   - The prod ROUTER is compiled here against the DEV vendor tree, the same limitation
#     `php.lint.prod-container` records. `composer install --no-dev` prunes require-dev, so a route
#     reachable only through a require-dev package would be listed here and absent from the image.
#   - It resolves this checkout's environment. A route whose presence or path depends on an env
#     variable answers for this container, not for a deployment.
#   - It records path and method restriction and nothing else — host, scheme, requirements,
#     defaults and priority are all dropped, so a host-restricted route is indistinguishable from
#     an unrestricted one.
#   - `ANY` is `debug:router`'s own token for "no method restriction" and is emitted verbatim; no
#     real HTTP method is spelled that way. Zero routes carry it today.
#   - A listed path is not a reachable one: nothing here says the route is authorised, that its
#     controller works, or that the edge (Caddy) forwards it. `/.well-known/mercure` is served
#     outside Symfony and therefore appears nowhere.
#   - Nothing here reconciles the manifest against a client's copy of these paths. That direction
#     belongs to the consumer.
set -eu

routes="$(php bin/console debug:router --format=json --env=prod)"

printf '%s\n' "$routes" | jq -r '
  [ to_entries[]
    | { name: .key,
        path: .value.path,
        methods: (.value.method | if . == "ANY" then ["ANY"] else split("|") end | sort)
      }
  ]
  | sort_by(.name)
  | if length == 0 then error("debug:router declared no routes") else . end
  | "{\n"
    + ( map("  " + (.name | tojson) + ": { \"path\": " + (.path | tojson) + ", \"methods\": " + (.methods | tojson) + " }")
        | join(",\n") )
    + "\n}"
'
