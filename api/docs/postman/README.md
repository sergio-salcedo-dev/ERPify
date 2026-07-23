# Postman collection — ERPify API

[`erpify-api.postman_collection.json`](erpify-api.postman_collection.json) is the **canonical source** of the ERPify API Postman collection (format v2.1). The cloud copy in the Postman *ERPify* workspace (collection `ERPify API`, uid `25727389-9e5c9b1f-b34b-415e-843b-dbacf4439808`) is a mirror — edit here first, then sync.

## Structure

Folders mirror the bounded contexts under `api/src/`:

- **Backoffice** (`/api/v1/backoffice`) — Health, plus a `Banks` folder (search, get, create, update, delete, realtime authorize).
- **Frontoffice** (`/api/v1`) — Health and `Dev` (FrankenPHP hot reload) folders. Dev-only routes return 404 outside the dev environment.

The collection variable `base_url` defaults to `https://localhost` (dev stack, primary checkout). The dev TLS certificate is local — disable *SSL certificate verification* in Postman settings or trust Caddy's local CA.

## Keeping it in sync

When a PR adds or changes endpoints (see "Keeping docs up to date" in the root [`CLAUDE.md`](../../../CLAUDE.md)):

1. Re-derive the route list: `make sf.routes f='api'`.
2. Update `erpify-api.postman_collection.json` in the same PR.
3. Push the file to the Postman cloud mirror — via the Postman MCP server (configured in [`.mcp.json`](../../../.mcp.json)), or directly:

   ```bash
   jq '{collection: .}' api/docs/postman/erpify-api.postman_collection.json \
     | curl -s -X PUT "https://api.getpostman.com/collections/25727389-9e5c9b1f-b34b-415e-843b-dbacf4439808" \
         -H "X-Api-Key: $POSTMAN_API_KEY" -H "Content-Type: application/json" -d @-
   ```

`POSTMAN_API_KEY` is personal — keep it in your shell profile, never in the repo (the committed [`.mcp.json`](../../../.mcp.json) only references `${POSTMAN_API_KEY}`). The collection file itself must stay secret-free: dev-only `base_url`, empty sample values.
