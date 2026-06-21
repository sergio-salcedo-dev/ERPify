# Architecture

## Clean Architecture Principles
- Must follow Clean Architecture principles
- Separate application into layers: Domain, Application, Infrastructure
- Dependencies should point inward (Domain has no dependencies)
- Use dependency inversion principle
- Keep business logic independent of frameworks and external concerns

## Architectural Goals
- Scalable architecture
- Testable code
- Maintainable structure
- Clear separation of concerns
- Easy to add new features
- Framework-independent business logic
- PSR standards compliance
- Security
- Avoid leaks and attacks

## Layer Structure
The application follows Clean Architecture with these layers:

### Domain Layer
- Contains business logic and entities
- No dependencies on other layers
- Pure business rules and value objects

#### Documented exception — passive metadata attributes on entities and embeddables

Domain entities, aggregates, and mapped value objects (`#[ORM\Embeddable]`) MAY carry framework
**metadata attributes**: `#[ORM\…]` mapping, `#[Assert\…]` constraints (including `UniqueEntity` and
`#[Assert\Callback]` hooks), and serializer `#[Groups]`. Rationale: one source of truth for persistence
shape and invariants, enforced via the shared `Validator::ensure($entity)` right before save;
`UniqueEntity` inherently needs the database. An embeddable keeps a repeated cluster of columns (e.g. an
image's key + metadata) as one inline value object reused across aggregates under a per-owner
`columnPrefix`, instead of duplicating the columns by hand. The prohibition stays absolute for
**behavioral** framework code in `Domain/`: no `Request`/`Response`, no `EntityManagerInterface`, no
`HttpException`, no Messenger envelopes, no service or HTTP calls. Examples:
`api/src/Backoffice/Bank/Domain/Entity/Bank.php` (entity),
`api/src/Shared/Storage/Domain/StoredObject.php` (embeddable value object).

#### Documented exception — symfony/uid value objects in Domain

`Domain/` MAY import `symfony/uid` as an identity / value-object library. Rationale: it is a leaf
component with no coupling to the framework runtime, and it is the best primitive for creating and
validating UUIDs across versions (v4, v7). The prohibition stays absolute for **behavioral** framework
code in `Domain/`: this exception covers only `symfony/uid`, not `Erpify\Shared\Infrastructure\…` or any
other framework service. Example: `api/src/Shared/Uuid/Domain/Uuid.php`.

#### Documented exception — PSR interface-only interop contracts

`Domain/` and `Application/` MAY depend directly on **interface-only PSR interop contracts** —
`psr/log`, `psr/cache`, `psr/http-message`, `psr/event-dispatcher`, `psr/clock`, `psr/container` (as a
contract). The discriminant is **runtime, not vendor**: a package that ships only interfaces (no
implementation, no transitive framework deps) introduces no runtime coupling, so it is admissible in the
inner layers — strictly more so than `symfony/uid`, which ships code. Frameworks/implementations with a
runtime (Symfony beyond `uid`, Doctrine, Monolog, Messenger, API Platform) stay in `Infrastructure/`.

**Do not wrap a permitted PSR contract in a 1:1 domain port.** A pass-through interface that mirrors the
standard method-for-method adds maintenance surface (interface + null object + adapter + DI + contract
test + docs) with no semantic gain — the PSR contract already *is* the port (Rule of Three). Create a
domain port only when the domain needs a **narrower/different contract** (e.g. `Clock`: "now" is a domain
concept wanting a test seam; `MessageBus`: no PSR exists). When the auxiliary types of an eliminated
wrapper have no real consumers and express no independent domain semantics, delete them too. Full
decision record and the per-dependency table: [`../adr/external-dependencies-in-domain.md`](../adr/external-dependencies-in-domain.md).

### Application Layer
- Contains use cases and application services
- Depends only on Domain layer
- Orchestrates business logic

### Infrastructure Layer
- Contains implementations of interfaces defined in Application/Domain
- Database access, external APIs, file system
- Depends on Application and Domain layers

### Shared Kernel
- Common utilities, base classes, and shared value objects.

### Dependency Inversion
- High-level modules should not depend on low-level modules. Both should depend on abstractions.

### Encapsulation
- Keep logic internal to the context.

### Domain–presentation separation

Presentation — display text, formatting, i18n — never lives in the inner layers: no `Domain/` type
(enum, value object, entity) and no `Application/` DTO/mapper carries labels, UI formatting, or i18n.
Readable text belongs to the presentation layer, keyed by the identity value. The line is
display-text-OUT vs business-rules-IN — predicates/invariants/transitions (`isTerminal()`,
`canTransitionTo()`) stay in. Enforced by the `DomainPresentationSeparationGateTest` arch-test plus
review. Decision record:
[`../adr/domain-presentation-separation.md`](../adr/domain-presentation-separation.md).
