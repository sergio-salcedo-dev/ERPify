# ADR — Dependencias externas en Domain/Application: PSR interface-only vs frameworks

> **Status:** accepted · **Date:** 2026-06-15 · **Scope:** `api/src/**/Domain`, `api/src/**/Application` — cross-cutting guidance for every external dependency of the inner layers.
>
> Disparador: el PR #299 introdujo un puerto `Shared/Domain/Logging/Logger` (+ `LogLevel`, `NullLogger`, adaptador `PsrLogger`, wiring DI, test de contrato y docs) que envolvía `Psr\Log\LoggerInterface` 1:1. La pregunta de fondo no es el Logger, sino qué precedente sienta para `Clock`, `Cache`, `EventBus`, `MessageBus`, etc.

## Contexto

[`../rules/architecture.md`](../rules/architecture.md) prescribe `Domain/` framework-free con dependencias hacia dentro, y ya lista "PSR standards compliance" como **objetivo** arquitectónico (no como veto). Existe un precedente explícito: `symfony/uid` se admite en `Domain/` por ser *"a leaf component with no coupling to the framework runtime"*.

El vacío que cubre este ADR: nunca se decidió si un **contrato PSR interface-only** (`psr/log`, `psr/cache`, `psr/http-message`…) cuenta como dependencia prohibida o como contrato permitido. El PR #299 lo resolvía de facto — vía un wrapper — sin decidir la política. Eso arriesga una capa `Shared/` poblándose de envoltorios isomórficos (Clock, EventDispatcher, Cache, Collections…) cuyo coste de mantenimiento acumulado es real y cuyo beneficio es nulo cuando el estándar ya *es* el puerto.

## Decisiones

### D1 — Tres categorías de dependencia externa; el discriminante es runtime, no vendor

- **Cat-1 — Frameworks / implementaciones con runtime:** Symfony (salvo `uid`), Doctrine, Monolog, Messenger, API Platform, Inversify, clientes HTTP, `doctrine/collections`. **Prohibidos** fuera de `Infrastructure/`. Estricto.
- **Cat-2 — Contratos de interoperabilidad interface-only:** `psr/log`, `psr/cache`, `psr/http-message`, `psr/event-dispatcher`, `psr/clock`, `psr/container` (como contrato). **Permitidos** en `Domain/Application` con criterio (ver D2).
- **Cat-3 — Tipos de valor neutrales:** `symfony/uid` (precedente vigente, [`../rules/architecture.md`](../rules/architecture.md)). **Permitidos.**

La línea divisoria **no** es "¿es de un vendor?" sino **"¿el paquete trae runtime/comportamiento, o es solo un contrato (interfaces) sin implementación ni dependencias transitivas de framework?"**. Un paquete interface-only no introduce acoplamiento de runtime: es *más* puro que `symfony/uid`, que sí trae código.

Descartado: **"cero third-party en Domain"** — incoherente con el `uid` ya aceptado y con el objetivo PSR de la regla; convierte cada estándar estable en un wrapper a mantener para siempre.

### D2 — No envolver 1:1; crear puerto solo cuando hay reshape semántico

Un contrato Cat-2 se consume **directo** cuando: (a) no tiene runtime propio, (b) expresa exactamente el contrato que el dominio necesita, y (c) el dominio no necesita restringirlo ni remodelarlo. Si el dominio necesita **semántica propia, restricciones añadidas o un lenguaje ubicuo distinto**, entonces — y solo entonces — se crea un puerto de dominio.

Un puerto que es *pass-through* byte a byte de un estándar permitido es abstracción-sobre-abstracción: añade superficie (interfaz + null object + adaptador + DI + test de contrato + docs) sin ganancia semántica. Regla de Tres: se abstrae ante variación real, no ante una hipótesis ("cambiar PSR-3 algún día" nunca ocurre, porque PSR-3 *es* la capa que aísla del sink).

| Dependencia        | Decisión                          | Por qué                                                                              |
|--------------------|-----------------------------------|--------------------------------------------------------------------------------------|
| `psr/log`          | **Directo**                       | El dominio necesita exactamente el contrato PSR-3. Sin reshape.                       |
| `psr/cache`        | **Directo**                       | Contrato neutro suficiente.                                                          |
| `psr/http-message` | **Directo**                       | Contrato neutro suficiente.                                                          |
| Clock              | **Puerto propio válido**          | "Now" es concepto de dominio; se quiere seam de test + contrato estrecho (`SystemClock`). |
| EventBus           | **Puerto propio válido**          | Los domain events DDD quieren semántica propia, no PSR-14 crudo.                      |
| MessageBus         | **Puerto propio obligatorio**     | No hay PSR; Symfony Messenger es Cat-1.                                              |
| Monolog            | **Solo Infrastructure**           | Implementación con runtime.                                                          |
| Doctrine           | **Solo Infrastructure**           | Implementación con runtime.                                                          |

### D3 — Consecuencia para el PR #299: cerrar el wrapper, adoptar `psr/log` directo

El `Logger` de #299 era isomórfico a PSR-3 (mismos métodos, semántica, contexto y comportamiento). La única diferencia, `LogLevel`, no justificaba la cadena `Logger`/`NullLogger`/`PsrLogger`/alias/tests/docs: los call-sites usan `->info()/->warning()`, nunca `->log(LogLevel::…)`, así que el enum tampoco resolvía un problema existente. Se revierte el wrapper y los cuatro call-sites vuelven a `Psr\Log\LoggerInterface` directo (incluido el autowire de `monolog.logger.observability` en `SearchObservabilityListener`). El pin NFR5 de la ruta de error (`ProblemDetailsFactory`, `ExceptionResponder`, vía `LoggerInterfaceContractTest`) deja de ser excepción y pasa a ser la norma.

**Regla de tipos auxiliares:** los tipos asociados a un wrapper eliminado (aquí `LogLevel`) se eliminan también, salvo que tengan consumidores reales o expresen una semántica de dominio independiente — ninguno aplica. Si en el futuro aparece una necesidad real de niveles tipados, `LogLevel` es un enum de un fichero que se añade entonces.

### D4 — Enforcement

Allowlist explícita de namespaces permitidos en `Domain/Application` (patrón hermano de `api/.bounded-context-allowlist`), enforced por un gate de lint al estilo de `LoggerInterfaceContractTest` / `BannedDoctrineApisTest`: falla si una capa interna importa un vendor fuera de Cat-2/Cat-3. La prohibición de "puerto pass-through 1:1 sobre un PSR permitido" (D2) queda como revisión humana documentada aquí — difícil de automatizar de forma fiable. La excepción se añade en [`../rules/architecture.md`](../rules/architecture.md), hermana de la de `symfony/uid`.

## Implementación

Reversión del wrapper de #299 y este ADR aterrizaron juntos en la rama del propio PR.

El gate de la allowlist (D4, issue **#301**) lo implementa **deptrac** (`api/tools/deptrac/deptrac.yaml`, `make php.deptrac`, gating en `php.quality.dry-run`): `Domain`/`Application` solo pueden depender de `Psr\*`, `Symfony\Component\Uid\*` y los namespaces de **metadato pasivo** que [`../rules/architecture.md`](../rules/architecture.md) bendice (`Doctrine\ORM\Mapping`, `Doctrine\DBAL\Types`, `Symfony\Component\Validator\Constraints`, `Symfony\Bridge\Doctrine\Validator\Constraints`); todo framework con runtime (Doctrine/Symfony-salvo-uid/Monolog/API Platform/Guzzle) solo es importable desde `Infrastructure`. Desde la adopción de DTOs de recurso por vista (ADR [`api-resource-dtos.md`](api-resource-dtos.md)) el `#[Groups]` desapareció del dominio y el pin `#[Serializer\Context]` (formato ATOM) se retiró del trait `Timestamped`: el formato ATOM lo posee el mapper de `Infrastructure` y `ResourceDtoContractTest` mantiene cada DTO de `Application/Resource/` plano y escalar-only. Por eso `Symfony\Component\Serializer\Attribute` **ya no entra hacia dentro** — sale del colector `Vendor.PassiveMetadata` y cae a `Vendor.Symfony` (solo-Infrastructure); cualquier uso nuevo hacia dentro falla. El colector y la lista de [`../rules/architecture.md`](../rules/architecture.md) se mueven juntos para no divergir de la gobernanza. El allowlist literal de #301 (`Symfony\*` prohibido salvo uid) habría dado falsos positivos sobre la excepción de metadato pasivo: el gate la modela explícitamente. La deuda preexistente queda *grandfathered* en `tools/deptrac/deptrac.baseline.yaml` como ratchet: verde hoy, falla ante cualquier fuga nueva. Lo que el baseline indulta hoy es el runtime del Validator y del ProblemDetails desde `*/Application`, y `EnumType` referenciado desde la entidad `BankAccount` de `Domain`. La deuda del MessageBus de Symfony Messenger (D2) **ya no está ahí**: se pagó con el puerto `Erpify\Shared\Event\Domain\EventBus` y el import lo refutan ahora dos lectores independientes — deptrac desde su ruleset `*.Application` y `make php.lint.event-bus`, que además cubre la familia de managers de Doctrine y los módulos que deptrac aún no tiene registrados ([`event-driven-architecture.md`](./event-driven-architecture.md) D4).

El colector `Vendor.PassiveMetadata` bendice además **una clase exacta y anclada**,
`Symfony\Component\Validator\Context\ExecutionContextInterface`, documentada en
[`../rules/architecture.md`](../rules/architecture.md): `#[Assert\Callback]` ya estaba bendecido y bendecir el
atributo sin el tipo que obliga a declarar era media decisión. Baja el baseline de **21 a 18 pares** (`Bank`,
`FilterQuery`, `SearchQuery`). El anclaje `$` es load-bearing para cualquier añadido futuro: sin él, una
entrada `…\Validator\Constraint` se tragaría también `ConstraintViolation` y sus hermanos `List`/`Interface`,
que son resultados de runtime.

**Descartado: mover `EnumType` a `Domain/` y bendecir ahí su clase base.** Es la opción obvia —la constraint es
metadato pasivo puro y su import desde `BankAccount` es la única violación literal de la regla— y se probó.
Falla por una razón que ningún gate del repo puede ver: Symfony ata constraint y validador **por nombre**
(`Constraint::validatedBy()` devuelve `static::class . 'Validator'`), así que separar el par deja el nombre
derivado apuntando a una clase inexistente y `#[EnumType]` revienta en runtime, con PHPStan, deptrac y la suite
entera en verde — el test de la constraint instancia el validador directamente y nunca pasa por
`validatedBy()`. El validador no puede acompañarla: extiende `ConstraintValidator`, que es runtime. Las tres
salidas para partir el par son peores que el par de deuda: `validatedBy()` devolviendo `EnumTypeValidator::class`
reubica la misma arista Domain→Infrastructure; devolverla como *string literal* la esconde de deptrac, que es
falsear el gate en vez de pagarlo; y registrar el validador bajo un service id inventa cableado de DI para una
clase y sigue acoplando por cadena mágica. Queda como deuda documentada, con
`ConstraintValidatorResolutionGateTest` cerrando **una** de las tres salidas: la del movimiento silencioso.
Las otras dos siguen invisibles a los gates — un `validatedBy()` que devuelva el FQCN como **cadena literal**
pasa el test nuevo (`class_exists()` es cierto) y pasa deptrac (que no tiene nodo para literales de cadena),
y un service id igual. Ahí sólo llega la revisión humana.

### D5 — Declined: porting `Erpify\Shared\Validation\Application\Validator` behind a domain interface (issue #305)

The remaining baseline entry for `Validator` is **6 symbols** — `Constraint`, `ConstraintViolation`,
`ConstraintViolationList`, `ConstraintViolationListInterface`, `Exception\ValidationFailedException`,
`Validator\ValidatorInterface` — not 7: `Validator.php` also imports `Constraints\GroupSequence` for its
`$groups` parameter, but that class already matches the `Symfony\Component\Validator\Constraints\*` regex
`Vendor.PassiveMetadata` blesses inward (`deptrac.yaml:165`), so it was never a violation to begin with. Get the
count from the baseline file, not from memory of the issue — this ADR itself repeated the wrong number once.

The 6-symbol shape looks like the one already paid down for `EventBus` (D2 table) and `TransactionManager` — an
`Application`-layer class touching a Symfony runtime type directly. It is not: `EventBus`/`TransactionManager`
port a **service call** (publish an event, commit a transaction) whose Symfony-ness is incidental to the
contract Application actually needs. `Validator::ensure()` is different in kind — `Constraint` and its siblings
are the **value types callers pass in**, and that is not an assumption, it is what a call-site survey of every
production use shows. Eight callers (`BankCreator`, `BankUpdater`, `BankAccountCreator`,
`BankAccountStatusChanger`, `BankAccountUpdater`, `CreateUser`, `InviteUser`, `ProvisionOrganization`) call
`ensure($entity)` with no explicit constraint, relying on the entity's own `#[Assert]`/`#[UniqueEntity]` class
metadata — validation rules that are themselves already blessed inward as passive metadata, so the native
vocabulary is unavoidable at the value-object end regardless of what `Validator::ensure()` does. The ninth,
`PasswordPolicyCheck::violationsFor()`, validates a raw `string $plainPassword` — a value with no class to carry
metadata — and hands `ensure()` an explicit `[new Assert\NotBlank(...), new PasswordPolicy()]`, this repo's own
custom constraint included. That is exactly the "other inputs (uploads, non-id scalars) go through the shared
`Validator::ensure()`" path the root `CLAUDE.md` security checklist mandates, so the explicit-`Constraint`
capability is part of the contract, not a corner case a redesign could shed. `ValidationFailedException` is
equally unavoidable one layer up: `Shared/Http/Infrastructure/UnknownPayloadMemberListener.php:61` constructs
one by hand to answer a body carrying undeclared members, so it is already this app's native wire-level
vocabulary for "validation failed" — a port that swapped it for a domain-shaped exception would leave the HTTP
mapping layer speaking a second, parallel one.

Five of the six symbols are therefore inherent to "validate a value against Symfony constraints, structured for
the RFC 9457 pipeline" as a contract; only `ValidatorInterface` is a pure service dependency of the
`EventBus`/`TransactionManager` shape. Porting only that one removes 1 of 6 symbols from the violation for the
cost of a new interface, adapter and DI wiring — a half-measure, and the worse of the two options (more code,
same debt). The other path — inventing this app's own parallel constraint/violation vocabulary so the port's
signature never names a Symfony type — is rejected the same way D2 rejects a pass-through wrapper: there is one
validation engine here and no second one planned, so the vocabulary would exist solely to satisfy deptrac, and
`ProblemDetailsFactory` would need a second `match` arm minting the same `type: 'validation-failed'` from two
sources — the two-minters-no-owner shape the root `CLAUDE.md` names and refuses under "Minting a
`ProblemDetails.type`".

**Considered and declined separately: moving `rebindEmptyPropertyPath()` to the HTTP boundary.** Three of the
six symbols (`ConstraintViolation`, `ConstraintViolationList`, `ConstraintViolationListInterface`) serve only
that private method, which repairs the wire's `field` name on a scalar-root violation — wire formatting, not
validation policy — and `ProblemDetailsFactory` already carries an equivalent last-resort fallback
(`VIOLATION_FIELD_FALLBACK`). Moving it would shrink the entry to 3 symbols before any decision on the
remaining ones, but it is a real behavioural move (a different class assembles the violation list, no caller
today exercises the `propertyPath:` argument to notice a regression) and is out of scope for a "leave it,
argued" change — a candidate for its own PR, not folded in here.

**Stays as a documented baseline entry**, the same mechanism as `BankAccount → EnumType` above — and provably
the only mechanism available, not merely the precedent: the obvious alternative, hand-adding the 6 entries to
`deptrac.yaml`'s own `skip_violations`, fails `DeptracSeamSyncGateTest` on the next run. That gate `assertSame()`s
every `skip_violations` entry in `deptrac.yaml` (not the imported baseline, which it explicitly excludes)
against `api/.bounded-context-allowlist`, with no filter for vendor vs. cross-context targets — a vendor entry
there has no allowlist counterpart, so it reds immediately. `skip_violations` stays reserved for published
cross-context seams; vendor debt regenerates into `tools/deptrac/deptrac.baseline.yaml` via
`make php.deptrac.baseline` instead, which is exactly where it already sat before this decision was written down.
