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
**persistence and validation metadata**: `#[ORM\…]` mapping and `#[Assert\…]` constraints (including
`UniqueEntity` and `#[Assert\Callback]` hooks). Rationale: one source of truth for persistence
shape and invariants, enforced via the shared `Validator::ensure($entity)` right before save;
`UniqueEntity` inherently needs the database. An embeddable keeps a repeated cluster of columns (a money
amount and its currency, the parts of a postal address) as one inline value object reused across aggregates
under a per-owner `columnPrefix`, instead of duplicating the columns by hand. The prohibition stays absolute
for **behavioral** framework code in `Domain/`: no `Request`/`Response`, no `EntityManagerInterface`, no
`HttpException`, no Messenger envelopes, no service or HTTP calls. Example:
`api/src/Backoffice/Bank/Domain/Entity/Bank.php` (entity).

**Serializer `#[Groups]` are NOT blessed metadata — the entity is never the HTTP wire contract.** Each
exposed view is served from a dedicated **per-view Resource DTO** (`Application/Resource/<Entity><View>Resource`,
flat `readonly` data with no logic), mapped from the entity by an Infrastructure `*ResourceMapper` service;
the domain entity never crosses the HTTP boundary. A reviewer reads the view's exact JSON off its DTO without
crossing the entity or any serializer group, and a change to one view cannot leak into another through a
shared group. No serializer attribute is admissible inward: the entity carries only `#[ORM]` / `#[Assert]`
passive metadata, the ATOM timestamp format is owned by the Infrastructure mapper, and `ResourceDtoContractTest`
holds every `Application/Resource/` DTO to a flat, immutable, scalar-only shape so nothing else can reach the
group-less serializer. Decision record: [`../adr/api-resource-dtos.md`](../adr/api-resource-dtos.md).

#### Documented exception — symfony/uid value objects in Domain

`Domain/` MAY import `symfony/uid` as an identity / value-object library. Rationale: it is a leaf
component with no coupling to the framework runtime, and it is the best primitive for creating and
validating UUIDs across versions (v4, v7). The prohibition stays absolute for **behavioral** framework
code in `Domain/`: this exception covers only `symfony/uid`, not `Erpify\Shared\Infrastructure\…` or any
other framework service. Example: `api/src/Shared/Uuid/Domain/Uuid.php`.

#### A first-party constraint cannot be split from its validator — and why that keeps one debt entry alive

`Erpify\Shared\Validation\Infrastructure\EnumType` is passive metadata by nature, so it *looks* like it
belongs in `Domain/` beside the invariant it states — and a `Domain/` entity importing it
(`BankAccount → EnumType`) is a literal breach of this section, grandfathered in the deptrac baseline.

**Moving it is not the fix, and this is measured, not assumed.** Symfony binds a constraint to its validator
by **name**: `Constraint::validatedBy()` returns `static::class . 'Validator'`. Move the constraint one
directory and the derived name points at a class that does not exist — `#[EnumType]` then fatals on every
validated property, while PHPStan, deptrac and the constraint's own unit test all stay green (that test
instantiates the validator directly through `ConstraintValidatorTestCase::createValidator()`, so it never
travels through `validatedBy()`). The validator itself cannot follow into `Domain/`: it extends
`ConstraintValidator`, which is framework runtime.

That leaves three ways to split the pair, and each is worse than the debt entry: a `validatedBy()` returning
`EnumTypeValidator::class` just relocates the same Domain→Infrastructure edge; returning the FQCN as a
**string literal** hides it from deptrac, which is falsifying the gate rather than paying it; and registering
the validator under a service id invents DI machinery for one class and still couples through a magic string.
The pair therefore stays co-located, exactly as `PasswordPolicy` / `PasswordPolicyValidator` already are.

Enforced by `ConstraintValidatorResolutionGateTest`
(`api/tests/Unit/Gate/`), which resolves `validatedBy()` for every concrete `Constraint`
subclass under `src` and fails when the target does not exist.

#### Documented exception — `ExecutionContextInterface` as a callback signature

`Domain/` and `Application/` MAY import `Symfony\Component\Validator\Context\ExecutionContextInterface`,
solely as the parameter type of a method annotated `#[Assert\Callback]`. Blessing the attribute while
refusing the signature it obliges you to write is half a decision: `#[Assert\Callback]` is already passive
metadata, and the callback cannot be declared without naming this type. Examples:
`Backoffice/Bank/Domain/Entity/Bank.php`, `Shared/Search/Application/Http/{FilterQuery,SearchQuery}.php`.

**Be exact about what this costs, because "no runtime enters" would be false.** The inner layer does not
*construct* the runtime — the framework injects the context — but it does **drive** it:
`Bank.php` calls `$context->buildViolation(…)->atPath(…)->addViolation()`. Worse,
`ExecutionContextInterface::getValidator(): ValidatorInterface` is a typed gateway to the entire validator
runtime, and deptrac cannot see through it: its extractors read declared and named references, never the
return type of a method call, so `$context->getValidator()->validate(…)` inside `Domain/` would pass the gate
green. **The exception is therefore scoped by review to the `#[Assert\Callback]` parameter signature and the
violation-builder calls that follow it; reaching any other member is a breach the gate will not catch.**

This exception is enforced by collector, not by baseline: `Vendor.PassiveMetadata` in
`api/tools/deptrac/deptrac.yaml` matches the class **anchored** (`…\ExecutionContextInterface$`). The anchor
is load-bearing for anything added there — an unanchored `…\Validator\Constraint` entry would also swallow
`ConstraintViolation` and its `List`/`Interface` siblings, which are runtime result types that must stay in
`Infrastructure/`.

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
