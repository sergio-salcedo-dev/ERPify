---
title: 'Rename Bank request DTOs to verb+entity+Request convention'
type: 'refactor'
created: '2026-06-08'
status: 'done'
baseline_commit: '3665714ab69ca08f5d85d7beb3c44bb150790c09'
context: ['{project-root}/api/CLAUDE.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Bank HTTP request DTOs are named after HTTP verbs (`BankPostPayload`, `BankPutPayload`), which obscures intent and is inconsistent with a clearer use-case-oriented naming (`Create…Request` / `Update…Request`).

**Approach:** Rename the two write payload DTOs to `CreateBankRequest` / `UpdateBankRequest`, update every reference (use cases + controllers), and align parameter naming. Pure rename — zero behavior change. `BankSearchQuery` stays as-is (it is a query-string DTO extending `SearchQuery`, not a "Payload").

## Boundaries & Constraints

**Always:** Keep the same namespace `Erpify\Backoffice\Bank\Application\Http`. Keep `final` and all `#[Assert\…]` constraints, property names, and defaults byte-identical. Use case method params become `$bankRequest`; in `BankCreator::create` the local entity var `$bank` becomes `$newBank`. File name must match the class name (PSR-4). Run `make php.quality` (in the worktree stack) before declaring done.

**Ask First:** Renaming `BankSearchQuery`. Touching any context other than Bank. Changing route names, HTTP methods, validation messages, or serializer groups.

**Never:** No behavior, validation, or response-shape changes. No DB/migration changes. Do not rename `BankSearchQuery`, `BankSearcher`, or `BankSearchController`. Do not touch `api/var/cache/**` (regenerated) or `api/tools/**` caches.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| POST /banks valid body | `{name, shortName}` | 201 Created, bank resource — identical to today | N/A |
| POST /banks invalid body | missing/blank `name` | 422 with same `#[Assert\NotBlank]` messages as today | RFC 9457 problem details unchanged |
| PUT /banks/{id} valid body | `{name, shortName}` | 200 OK, renamed bank — identical to today | N/A |
| PUT /banks/{id} not found | unknown id | `BankNotFoundException` → 404, unchanged | unchanged |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Bank/Application/Http/BankPostPayload.php` -- rename file+class → `CreateBankRequest`
- `api/src/Backoffice/Bank/Application/Http/BankPutPayload.php` -- rename file+class → `UpdateBankRequest`
- `api/src/Backoffice/Bank/Application/BankCreator.php` -- import + `create()` type hint, param `$bankRequest`, local `$bank`→`$newBank`, field reads
- `api/src/Backoffice/Bank/Application/BankUpdater.php` -- import + `update()` type hint, param `$bankRequest`, field reads
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- import + `#[MapRequestPayload]` type hint, param `$input`→`$bankRequest`
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php` -- import + `#[MapRequestPayload]` type hint, param `$input`→`$bankRequest`
- `api/src/Backoffice/Bank/Application/Http/BankSearchQuery.php` -- UNCHANGED (reference only)

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/Bank/Application/Http/CreateBankRequest.php` -- create via `git mv` from `BankPostPayload.php`; rename class to `CreateBankRequest` -- preserve constraints/properties
- [x] `api/src/Backoffice/Bank/Application/Http/UpdateBankRequest.php` -- create via `git mv` from `BankPutPayload.php`; rename class to `UpdateBankRequest` -- preserve constraints/properties
- [x] `api/src/Backoffice/Bank/Application/BankCreator.php` -- use `CreateBankRequest $bankRequest`; rename local entity var `$bank`→`$newBank` everywhere in `create()` -- avoid name collision
- [x] `api/src/Backoffice/Bank/Application/BankUpdater.php` -- use `UpdateBankRequest $bankRequest`; update field reads
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- type hint `CreateBankRequest $bankRequest` under `#[MapRequestPayload]`; pass to creator
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php` -- type hint `UpdateBankRequest $bankRequest` under `#[MapRequestPayload]`; pass to updater

**Acceptance Criteria:**
- Given the rename is complete, when `grep -rn "BankPostPayload\|BankPutPayload" api/src` runs, then there are zero matches.
- Given the rename is complete, when `make php.stan` and `make php.quality` run, then both pass with no new findings.
- Given the POST and PUT Bank functional tests run, when executed, then they pass unchanged (no test edits needed).

## Verification

**Commands:**
- `grep -rn "BankPostPayload\|BankPutPayload" api/src` -- expected: no output
- `make php.stan` -- expected: no errors on changed files
- `make php.unit c='--filter Bank'` -- expected: green (POST/PUT functional + Bank unit tests)
- `make php.quality` -- expected: clean (cs-fixer/psalm/phpcs may mutate; re-run until clean)

## Suggested Review Order

**DTO definitions (the rename)**

- Entry point: new name encodes the use case; constraints/properties unchanged from `BankPostPayload`
  [`CreateBankRequest.php:9`](../../api/src/Backoffice/Bank/Application/Http/CreateBankRequest.php#L9)

- Same rename on the update path; body byte-identical to old `BankPutPayload`
  [`UpdateBankRequest.php:9`](../../api/src/Backoffice/Bank/Application/Http/UpdateBankRequest.php#L9)

**Use-case orchestration**

- Param `$bankRequest`; local entity `$bank`→`$newBank` to avoid collision (only behavior-sensitive spot)
  [`BankCreator.php:31`](../../api/src/Backoffice/Bank/Application/BankCreator.php#L31)

- Param `$bankRequest`; local `$bank` (from finder) keeps its name — no collision here
  [`BankUpdater.php:31`](../../api/src/Backoffice/Bank/Application/BankUpdater.php#L31)

**HTTP controllers (wiring)**

- `#[MapRequestPayload]` now binds `CreateBankRequest`; same value flows to the creator
  [`BankPostController.php:36`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php#L36)

- `#[MapRequestPayload]` now binds `UpdateBankRequest`; same value flows to the updater
  [`BankPutController.php:30`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php#L30)
