# ERPify API Project Instructions

Specific guidance for the Symfony HTTP API. Reference the root [GEMINI.md](../GEMINI.md) for monorepo-wide mandates.

## Core Mandates

- **Layering:** Adhere strictly to the three-layer split within each module:
  - `Domain/`: Entities, Value Objects (VO), Interfaces, Domain Events. **Strictly pure PHP.** No annotations/attributes (e.g., Doctrine, Validator).
  - `Application/`: Use cases, Command/Query Handlers, DTOs. Orchestrates domain; defines transaction boundaries.
  - `Infrastructure/`: Repositories, Controllers, Persistence Mappings (XML/Attributes), Messenger Handlers, Mailers, External Clients.
- **Module Boundaries:**
  - `Backoffice/`: Internal operations.
  - `Frontoffice/`: Client-facing operations.
  - `Shared/`: Cross-cutting kernel code. Prefer putting reusable logic here over duplication.
- **Cross-Context Communication:** Calls between modules must go through `Application/` ports, never direct `Domain/` references across modules.

## Technical Standards

- **PHP Version:** 8.5 (sha256-pinned).
- **FrankenPHP:** Running in worker mode.
- **Messenger:** Dedicated `messenger_worker` for async tasks. Do not inline long work.
- **Persistence:** Entities are mapped in `Infrastructure/Persistence/`.

## Essential Commands

Run from the repository root:
- `make php.stan`: Mandatory static analysis. Fix all reports before declaring "done".
- `make php.quality`: Full cleanup (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS). CI will fail if this isn't clean. Psalm runs taint-only (`make php.psalm.taint`, `api-taint` CI job), not here.
- `make db.diff`: Generate migrations from entity changes.
- `make php.test`: Runs `php.unit` (PHPUnit) and `php.behat` (Behat).

## File Constraints

- **Immutable Migrations:** Never edit a migration merged into `main`. Create a new one.
- **Reference Config:** `api/config/reference.php` is auto-generated; do not touch.
- **Vendor:** `api/vendor/` is managed by Composer; never edit manually.
- **Xdebug:** Use `make xdebug.enable` / `xdebug.disable` to toggle.
