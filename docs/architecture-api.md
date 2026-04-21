# Architecture — API (`api/`)

## Executive summary

The `api/` deployable is a Symfony 8 HTTP API running on **FrankenPHP** (Caddy embedded), backed by PostgreSQL via Doctrine ORM 3.6 / DBAL 4.4, with async workflows on Symfony Messenger and real-time updates on Mercure. The code is organised as **DDD + Hexagonal / Clean Architecture** across top-level bounded contexts (`Backoffice/`, `Frontoffice/`, `Shared/`), each internally layered into `Domain / Application / Infrastructure`.

## Technology stack

| Category        | Technology                                     | Version                                       |
|-----------------|------------------------------------------------|-----------------------------------------------|
| Runtime         | PHP                                            | **8.5**                                       |
| Framework       | Symfony (components)                           | **8.0.x**                                     |
| HTTP server     | FrankenPHP (Caddy)                             | `dunglas/frankenphp:1-php8.5` (digest-pinned) |
| ORM / DBAL      | Doctrine ORM / DBAL / Migrations / Persistence | 3.6 / 4.4 / 4.0 / 4.1                         |
| Database        | PostgreSQL                                     | via Compose service                           |
| Async           | Symfony Messenger + Doctrine transport         | 8.0.8                                         |
| Realtime        | Symfony Mercure (+ Hub)                        | 0.7 / bundle 0.4                              |
| Mail            | symfony/mailer                                 | 8.0.8                                         |
| Storage         | league/flysystem (+ bundle)                    | 3.33 / 3.7                                    |
| Media           | Intervention Image                             | 4.0                                           |
| CORS            | nelmio/cors-bundle                             | 2.6                                           |
| Unit tests      | PHPUnit                                        | 13                                            |
| E2E tests       | Behat (isolated tree)                          | `api/tools/behat/`                            |
| Static analysis | PHPStan / Psalm / Rector                       | 2 / 6.16 / 2                                  |
| Style / quality | PHP-CS-Fixer / PHPCS / PHPMD                   | 3.95 / 4 / —                                  |
| Fixtures        | Hautelook Alice                                | 2.17                                          |

See [`project-context.md`](./project-context.md#technology-stack--versions) for the full constraint table (version gotchas, Doctrine 3 API deltas, polyfill `replace` block, Behat isolation rationale).

## Architecture pattern

**DDD + Hexagonal (Ports & Adapters) + Clean Architecture.** Dependencies point inward: `Infrastructure → Application → Domain`. `Domain/` is framework-free — no Symfony, Doctrine, HTTP, or DI-container types. Ports (interfaces) are declared in `Domain/` or `Application/`; adapters live in `Infrastructure/`.

### Bounded contexts

```
api/src/
├── Backoffice/
│   ├── Bank/       { Domain, Application, Infrastructure }
│   └── Health/
├── Frontoffice/
│   ├── Dev/
│   ├── Health/
│   └── Mercure/
└── Shared/
    ├── Application/
    ├── Domain/
    ├── Infrastructure/
    ├── Media/      { Domain, Application, Infrastructure }
    └── Storage/    { Domain, Application, Infrastructure }
```

Cross-context calls go through **published Application services** or **domain events**; one context never reaches into another's `Domain/` or `Infrastructure/`.

## Layer responsibilities

| Layer | Contains | Must NOT depend on |
|---|---|---|
| `Domain/` | Entities, value objects, domain services, repository/port **interfaces**, domain exceptions, domain events | Framework, ORM, HTTP, DI container |
| `Application/` | Use cases (command/query handlers), DTOs, orchestration, validators over DTOs | Infrastructure implementations (only their interfaces) |
| `Infrastructure/` | Doctrine mappings, repository implementations, Symfony controllers, Messenger handlers, Mercure publishers, Flysystem adapters, external-service clients | — (outermost layer) |

## Data architecture

- **Primary store**: PostgreSQL via Doctrine ORM.
- **Migrations**: `api/migrations/2026/Version<timestamp>.php` (organised by year).
- **Fixtures**: Hautelook Alice — `make db.load.fixtures`; destructive reset via `make db.reset`.
- **Mapping**: Doctrine mapping lives in `Infrastructure/` (not `Domain/`); domain objects are POPOs.
- **Doctrine 3 / DBAL 4 API caveats**: see [`project-context.md` → Runtime gotchas](./project-context.md).

## API design

- Attribute-only routing (`#[Route]`) on controllers placed under each bounded context's `Infrastructure/`.
- Controllers are thin — delegate to Application-layer use cases and return via `AbstractController::json()` so Serializer groups apply.
- CORS configured in `api/config/packages/nelmio_cors.php`; no wildcard `*` for credentialed origins.
- Public health endpoints exposed from `Frontoffice/Health/` and `Backoffice/Health/`.

## Async & messaging

- **Symfony Messenger** with a **separate `messenger_worker` Compose service** in `ci` / `prod`. Handlers must be idempotent and tolerate at-least-once delivery.
- **Mercure Hub**: publish via `Frontoffice/Mercure/` publishers at `/.well-known/mercure`; JWT required (`CADDY_MERCURE_JWT_SECRET` in prod).
- Mail is dispatched asynchronously via Messenger — see [`domain-events-and-messenger.md`](./domain-events-and-messenger.md).
- Audit-table semantics and transport details: same doc.

## Storage & media

- `Shared/Storage/` wraps Flysystem adapters. Never hit the local FS directly for user-facing content.
- `Shared/Media/` uses Intervention Image for processing. Upload flow: see [`media-upload.md`](./media-upload.md) and [`object-storage.md`](./object-storage.md).

## Configuration

- Bundle configuration under `api/config/packages/`: Doctrine, Doctrine migrations, Messenger, Mercure (publish + subscribe), Mailer, Flysystem, Media, Nelmio CORS, Validator, Property Info, Cache, Framework, Routing, Fixtures.
- `api/config/services.yaml` — autoconfigure defaults; explicit definitions are the exception.
- `api/config/routes.yaml` + routes in `api/config/routes/` — attribute-first.
- Environment via `api/.env` / `api/.env.example`; secrets via Symfony Secrets vault in prod.

## Testing strategy

| Layer | Tool | Entry |
|---|---|---|
| Unit | **PHPUnit 13** | `api/phpunit.xml.dist`, run via `make php.unit` |
| E2E / BDD | **Behat 3** (isolated Composer tree) | `api/tools/behat/`, run via `make php.behat` |
| Fixtures | Hautelook Alice | `make db.load.fixtures` |
| Static analysis | PHPStan, Psalm, Rector | `make php.stan`, `php.psalm`, `php.rector[.dry-run]` |
| Style / quality | PHP-CS-Fixer, PHPCS, PHPMD | `make php.lint` (aggregate) |
| Composer hygiene | composer-unused, composer-require-checker | `make composer.checks` |

Integration tests that hit Doctrine use a **real Postgres** (Compose), not SQLite. No network in unit tests — mock at the transport level.

Detailed rules: [`project-context.md` → Testing Rules](./project-context.md).

## Source tree

See [`source-tree-analysis.md`](./source-tree-analysis.md) for the full annotated tree.

## Development & deployment

- Dev setup, commands, and DB tasks: [`development-guide-api.md`](./development-guide-api.md).
- Production deploy, env vars, worker lifecycle: [`deployment-guide.md`](./deployment-guide.md) and [`production-deployment.md`](../docs-info/production-deployment.md).
