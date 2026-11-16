# Wire contract

What `ic-1` implements and `ic-2` codes against. Exact public paths come from `make sf.routes.manifest` in the consumer story: the router prefixes per directory, so a path written on a `#[Route]` is not the public one. A cell marked **open** is an Open Question of `SPEC.md`.

## Write routes

| Consumer | Route (suffix on the owner's resource) | Permission |
|---|---|---|
| Bank logo | `PUT`/`DELETE …/banks/{id}/logo` | existing bank update permission |
| User avatar | `PUT`/`DELETE …/users/{id}/avatar` | **open** (self, admin, both) |
| Bank-account image | `PUT`/`DELETE …/bank-accounts/{id}/image` | existing bank-account update permission |

The suffix never collides with the owner's own update route. Authentication, permission, UUID syntax and owner existence are checked **before** the body is read, so a 404 or 403 stores nothing.

## Requests and responses

| | Contract |
|---|---|
| `PUT` request | Raw image bytes as the body. No multipart, no query. `Content-Length` is mandatory and the bytes read must equal it. `Content-Type` is an optional hint. |
| `PUT` success | `200` with `{"imageId": "<uuid>"}`. The old image, if any, is released by a queued request. |
| `DELETE` success | `204`, always. Idempotent: no image held means `204` and no event. |
| Declared media type | Handed to the processor only when it is a clean `image/*` token; anything else (`application/octet-stream`, `image/jpg`, parameters, form types) is ignored. The served type comes from detection, never from the declaration. |
| Accepted formats | The `ImageProcessor` allowlist, and nothing wider. The bytes are decoded and re-encoded; the stored bytes are never the upload. |
| Byte cap | `erpify.images.max_input_bytes` (20 MiB today). The edge matcher for these routes carries the same value. |

## Problem Details

`ic-1` mints the `type` values and documents them in `docs/api-error-contract.md`; the PWA maps exactly this set.

| Case | Status | `type` |
|---|---|---|
| Malformed owner id | `400` | `invalid-uuid` (existing) |
| Not authenticated / not permitted | `401` / `403` | existing |
| Owner absent | `404` | the owner's existing not-found type |
| `Content-Length` missing or not equal to the bytes read | `411` | `image-length-required` |
| Over the byte cap, or over the decoded pixel and dimension limits | `413` | `image-too-large` |
| Format outside the allowlist | `415` | `image-unsupported-type` |
| Empty body, or bytes that do not decode | `422` | `image-invalid` |
| Rate limit | `429` | the existing rate-limit type, with `Retry-After` |
| Storage or processing failure | `5xx` | unchanged |

A decodable polyglot is not an error: it is re-encoded and stored, and what is guaranteed is that the stored bytes carry no trailing payload.

## Reads and the Resource DTO

- Logo and bank-account image are read through the existing shared route (`GET /api/v1/images/{imageId}`), unchanged.
- The avatar is read through an owner-scoped route, `GET …/users/{id}/avatar`, that resolves the image only through the user's current `avatarImageId`. Its audience, audit type and cache window are **open** (SPEC Open Questions).
- The bank and bank-account Resource DTOs gain one nullable string, `logoImageId` and `accountImageId`: a presence flag and a cache key, never a URL; the PWA composes the read URL itself through `safeHref`.
- The user Resource DTO gains the boolean `hasAvatar` and never the id: the shared route serves any id to any session, so exposing it would bypass the owner route.
- The PWA shows a placeholder on **any** failure to load the image: an `<img>` does not expose the status, and a fetch-to-blob read would conflict with `safeHref` and the CSP.
