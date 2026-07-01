---
baseline_commit: 6224f2a21de4aebc3e9680c4381f46ea3c233c24
---

# Story 2.1: Clasificación PII por campo (`#[PersonalData]`)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **responsable de cumplimiento**,
quiero **declarar qué campos de una entidad son datos personales mediante un atributo pasivo en la propia entidad**,
para que **la auditoría (y cualquier otra infra de tratamiento de datos personales) decida por columna qué se cifra y qué va en claro, sin un mapa central**.

> **Origen:** Epic 2 (`_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.1`), ADR [`regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) D11/D12. Es la **base de clasificación** de E2: no toca cripto ni keystore — solo la metadata que *decide* qué cifrar. La consume la Story 2.3.

### Scope — solo el atributo + su clasificación + el lector (sin cifrado)

Esta story introduce el atributo `#[PersonalData]`, lo **aplica** a `BankAccount`, y expone un **lector** (reflexión) que enumera los campos PII de una clase. **No** cifra nada, **no** crea keystore, **no** cablea `BankAccount` como `AuditedEntity` (eso es 2.3). **Sin migración** (un atributo pasivo no cambia el esquema). `Bank` (institución) **no** recibe `#[PersonalData]` (no es PII — D11).

### Dependencias dentro de E2

```
2.1 (clasificación) ─┐
                     ├─► 2.3 (cifra el diff PII usando 2.1 + 2.2) ─► 2.4 (erase-subject)
2.2 (keystore) ──────┘
```

2.1 ⊥ 2.2 (independientes). 2.1 es prerequisito de 2.3 (le dice *qué* campos cifrar).

### Decisiones tomadas (ADR D12 + sesión de arquitectura)

1. **Atributo pasivo, propiedad del módulo dueño** (D12). `#[PersonalData]` es metadata de dominio — la misma excepción que ya sanciona `#[ORM]`/`#[Assert]` en `Domain/` ([`docs/rules/architecture.md`](../../docs/rules/architecture.md)). El módulo dueño (`BankAccount`) lo aplica a *sus* campos; los consumidores (auditoría hoy; export/masking/indexación mañana) **solo lo leen**, ninguno *decide* qué es personal. Descartado: un mapa PII central en `Shared/Audit` — colapsaría la autoridad de clasificación en el subsistema de auditoría (espejo de cómo `AuditedEntity` ya deja la acción semántica en manos del módulo).
2. **Clasificación por campo, no por entidad** (D11/D12). PII es propiedad de un *campo* en el contexto de su *sujeto*: un `BankAccount` mezcla columnas PII (`holderName`/`iban`) y no-PII (`bic`/`currency`/`status`/`bankId`). Clasificar la entidad entera sería demasiado grueso.
3. **Lector por reflexión, puerto + adaptador** (DIP). El "leer la clasificación" es un puerto de aplicación (`PersonalDataClassifier`) con un adaptador de infraestructura por reflexión; los consumidores dependen del puerto, no de `ReflectionClass`.

## Acceptance Criteria

**AC1 — Atributo pasivo `#[PersonalData]`, module-owned (FR11, D12).**
Given un agregado auditado con campos personales,
When se clasifica,
Then sus campos PII se marcan con un atributo **pasivo `#[PersonalData]`** (TARGET_PROPERTY) propiedad del módulo dueño — **no** un mapa central en `Shared/Audit`; la auditoría y cualquier otra infra de datos personales **solo lo leen** para decidir cifrado-vs-claro por columna. El atributo no introduce comportamiento (cero coste en runtime salvo la lectura por reflexión).

**AC2 — Clasificación de `BankAccount` (FR12, NFR6, D11).**
Given `BankAccount`,
When se clasifica,
Then `holderName` e `iban` llevan `#[PersonalData]`; `bic`, `currency`, `status`, `bankId` van **en claro**. `Bank` (institución) **no** tiene campos PII (no se toca).

**AC3 — Lector de clasificación (DIP, consumible por 2.3).**
Given una clase de entidad (o una instancia),
When un consumidor pregunta por sus campos personales,
Then un **puerto `PersonalDataClassifier`** (adaptador por reflexión) devuelve el conjunto de nombres de campo marcados `#[PersonalData]`, de forma determinista y cacheable por clase; una clase sin marcas devuelve conjunto vacío.

**AC4 — Aislamiento de capas (NFR7).**
Given el atributo y el lector,
When se ejecuta `make php.deptrac` + `make php.lint.bounded-context`,
Then no hay violaciones: el atributo es metadata pasiva importable; el adaptador de reflexión vive en `Infrastructure`; ningún `Domain/` alcanza framework.

## Tasks / Subtasks

> Convenciones API: `declare(strict_types=1)`; PSR-12; tipos en todo; `final`; sin framework en `Domain/`. **Sin migración** (atributo pasivo). Barrido final: sin comentarios con ID de story/AC/FR antes del commit.

### A. Atributo `#[PersonalData]` (AC1)

- [ ] **A1.** Crear el atributo: `api/src/Shared/Privacy/Domain/PersonalData.php`
  - [ ] `#[Attribute(Attribute::TARGET_PROPERTY)]`, `final class PersonalData`. Mínimo viable: sin argumentos (marcador puro) **o** un único `string $classification` opcional si el architect lo pide (YAGNI: por defecto marcador sin args — la decisión cifrado-vs-claro es binaria hoy). Sin dependencias de framework (es metadata de dominio, espejo de `#[Assert]`).
  - [ ] **Decisión de ubicación (confirmar):** se propone una capability nueva `Shared/Privacy/` (clasificación de datos personales transversal, no validación). Alternativa considerada y descartada: `Shared/Audit/Domain` — la auditoría *lee* la clasificación, no la *posee* (D12). Patrón de atributo a imitar: `api/src/Shared/Validation/Infrastructure/EnumType.php` (estructura del `#[Attribute]`), **no** su namespace.
- [ ] **A2.** Asegurar que `services.yaml` excluye el atributo del autowiring igual que las entidades (`src/**/Domain/Entity/` ya está excluido; un atributo no es un servicio — verificar que no se registra como tal).

### B. Clasificar `BankAccount` (AC2)

- [ ] **B1.** `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`
  - [ ] Añadir `#[PersonalData]` a la propiedad `holderName` (línea ~38) y a `iban` (línea ~52). **No** marcar `bic`, `currency`, `status`, `bankId` (D12 → claro).
  - [ ] **`alias` — clasificación abierta:** el ADR D12 enumera solo `holderName`/`iban`; `alias` (`?string`, etiqueta libre del usuario, línea ~57) **no** está en el ADR pero puede contener datos personales ("nómina de Juan"). **Decisión del módulo dueño a confirmar con el usuario/architect:** marcarlo `#[PersonalData]` (conservador, sobre-cifrar es inocuo — D12) o dejarlo en claro. Por defecto, **dejarlo en claro** salvo confirmación, y registrarlo en las notas del PR.
  - [ ] No tocar `Bank` (`api/src/Backoffice/Bank/Domain/Entity/Bank.php`): no es PII (D11).

### C. Lector de clasificación (AC3)

- [ ] **C1.** Puerto: `api/src/Shared/Privacy/Application/PersonalDataClassifier.php`
  - [ ] `personalFieldsOf(object|string $entityOrClass): array<int,string>` (nombres de campo) — o un value object `PersonalDataFields` si el architect prefiere encapsular. Contrato determinista.
- [ ] **C2.** Adaptador: `api/src/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifier.php`
  - [ ] `#[AsAlias(PersonalDataClassifier::class)]`. Recorre `ReflectionClass::getProperties()` → `getAttributes(PersonalData::class)`; cachea por nombre de clase (mapa estático interno o `WeakMap`; **no** estado mutable global — usar propiedad de instancia readonly-friendly o cache local del adaptador). Clase sin marcas → `[]`.
- [ ] **C3.** Tests (AC2/AC3)
  - [ ] Unit: `api/tests/Unit/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifierTest.php` — `BankAccount` → exactamente `['holderName','iban']` (orden estable, p.ej. ordenado); una clase fake sin marcas → `[]`; una clase fake con 1 marca → ese campo. (Cuidado PHPMD `CouplingBetweenObjects` ≤13 en tests → usar Fixtures locales en un trait si hace falta; ver gotchas.)
  - [ ] Unit (contrato de clasificación de `BankAccount`): un test que fije la clasificación esperada de `BankAccount` (`holderName`/`iban` PII; `bic`/`currency`/`status`/`bankId` claro) — protege la decisión D12 frente a un futuro `git`-rename de campos.

### D. Docs

- [ ] **D1.** `docs/rules/security.md` — sección A.5.12 (clasificación de la información): el atributo `#[PersonalData]` como mecanismo de clasificación por campo; los consumidores leen, no deciden.
- [ ] **D2.** `docs/architecture-api.md` — registrar la capability `Shared/Privacy` (atributo + clasificador) y su rol como base de E2.
- [ ] **D3.** **Barrido final:** eliminar de `src`/tests cualquier comentario con ID de story/AC/FR; `make php.stan` por archivo + `make php.quality` antes del commit final.

## Dev Notes

### Estado actual (verificado)

- **No existe** ningún atributo de clasificación PII ni `Shared/Privacy`. La única búsqueda de `PersonalData`/`EncryptionScope`/`sodium`/`encrypt` en `api/src` solo encuentra `Shared/Search/.../Keyset/CursorCodec.php` (HMAC de cursor, no relacionado).
- **`BankAccount`** (`api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:28-66`) extiende `AggregateRoot`, **no** implementa `AuditedEntity`. Propiedades (verbatim del entity): `bankId` (GUID, `#[Assert\Uuid]`), `holderName` (`length:255`, `#[Assert\NotBlank]`), `iban` (`length:34, unique:true`, `#[Assert\Iban]`, canonicalizado upper/no-espacios), `bic` (`?string length:11`, `#[Assert\Bic(ibanPropertyPath:'iban')]`), `alias` (`?string length:100`), `currency` (`Currency` enum, `length:3`), `status` (`BankAccountStatus` enum, `TEXT`).
- **`EnumType`** (`api/src/Shared/Validation/Infrastructure/EnumType.php`) es el patrón vivo de "atributo pasivo en propiedad de entidad" — copiar su forma `#[Attribute(...)]` (no su namespace ni su semántica de validación).
- **`services.yaml`** (`api/config/services.yaml`): `_defaults` autowire+autoconfigure; `Erpify\` se carga con `resource: '../src/'` excluyendo `Domain/Entity/`. Atributos y value objects no necesitan registro explícito.

### Decisión de arquitectura (argumentada)

- **Principio (D12 / SRP / autoridad de clasificación):** el dato decide, no el agregado; la autoridad de "qué es personal" es del módulo dueño, no de un mapa central (que con decenas de bounded contexts degeneraría en una *god map*). El atributo distribuye esa autoridad a la entidad, espejo exacto de cómo `AuditedEntity` deja la acción semántica en el módulo.
- **Objetivo:** habilitar a 2.3 a cifrar **solo** las columnas correctas del diff sin hardcodear nombres de campo en el subsistema de auditoría; reutilizable por futura infra (export GDPR, masking).
- **Coste / descartada:** un mapa central `array<class,array<field>>` en `Shared/Audit` — acopla clasificación a auditoría y obliga a tocar una clase compartida por cada entidad nueva. El atributo cuesta una lectura por reflexión (cacheada) y gana localidad + extensibilidad.

### Source tree — archivos a tocar

**NEW:** `Shared/Privacy/Domain/PersonalData.php`, `Shared/Privacy/Application/PersonalDataClassifier.php`, `Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifier.php`; tests `Unit/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifierTest.php` (+ test de contrato de clasificación de `BankAccount`).
**UPDATE:** `Backoffice/BankAccount/Domain/Entity/BankAccount.php` (atributos en `holderName`/`iban`).
**NO TOCAR:** `Bank.php`, el esquema/`audit_log`, cualquier cripto (no existe aún), el listener `AuditWriteCaptureListener` (lo consume 2.3).
**deptrac:** el atributo es metadata pasiva (como `#[ORM]`/`#[Assert]`) → permitido inward; el adaptador de reflexión vive en `Infrastructure`. Si una clase nueva no resuelve, registrar en `api/tools/deptrac/deptrac.yaml`.

### Previous-story intelligence (patrones a seguir)

- **Story 1.7 (`6224f2a2`):** vertical slice DDD = puerto Domain/Application + adaptador Infra + tests que fijan lista+orden de claves; `#[CoversClass]` por test; raw-DBAL entity-free para `audit_log`. Aplica el mismo rigor de "test que fija el contrato" al clasificador.
- **`AuditedEntity` como espejo de autoría module-owned:** `Bank.php:142-154` declara su `auditAction()` localmente; `#[PersonalData]` distribuye la clasificación con la misma filosofía.

### Testing standards

PHPUnit 13 (`#[CoversClass]`, AAA, `#[DataProvider]` para varias clases-fixture → **un foreach** si dispara `TooManyPublicMethods`(10)). Tests de dominio sin contenedor ni DB (reflexión pura). Fixtures locales para clases de prueba (no instanciar `BankAccount` real innecesariamente — usar clases fake con `#[PersonalData]`).

### Quality gates + gotchas relevantes

`make php.stan` por archivo (worker segfault → `PHP_SERVICE=messenger_worker`); `make php.quality`; `make php.deptrac`; `make php.lint.bounded-context`. **PHP gotchas:** PHPMD `CouplingBetweenObjects` ≤13 cuenta `use` de test → extraer fixtures a trait si hace falta ([[coversclass-restricts-clover-and-phpmd-coupling]]); Rector puede readonly-ficar clases fake anónimas → PDepend peta ([[phpmd-anon-readonly-class-parse-error]]) → usar Fixtures **nombradas**, no `new readonly class`; sin `// NOSONAR`, sin comentarios que narren reglas de lint.

### Must-preserve / regresión

- `BankAccount` sigue funcionando idéntico: añadir un atributo pasivo **no** cambia comportamiento, ni esquema, ni el wire (DTOs por vista intactos). `iban` sigue `unique` y canonicalizado.
- **Sin migración** (`make db.diff` debe salir vacío tras esta story; si genera algo, hay un error — el atributo no es metadata de esquema).
- `Bank` intacto.

### Project Structure Notes

Nueva capability `Shared/Privacy/{Domain,Application,Infrastructure}` alineada a DDD/hexagonal. Si el architect prefiere no abrir una capability nueva, alternativa: alojar el atributo en una ubicación `Shared/` existente de metadata pasiva — pero **no** en `Shared/Audit` (D12: auditoría lee, no posee). Confirmar antes de implementar.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#Story 2.1`] — ACs base, FR11/FR12.
- [Source: `docs/adr/regulatory-audit-trail.md`] — D11 (BankAccount PII condicional), D12 (clasificación por campo, module-owned, atributo pasivo), D6/NFR6 (nunca PII en claro en reposo).
- [Source: `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:28-66`] — campos a clasificar.
- [Source: `api/src/Shared/Validation/Infrastructure/EnumType.php`] — patrón de atributo pasivo en entidad.
- [Source: `api/src/Backoffice/Bank/Domain/Entity/Bank.php:142-154`] — autoría module-owned (espejo filosófico).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story).

### Debug Log References

`make php.stan` 0 errors · `make php.quality` 0 violations · `make php.unit --filter PersonalData` green. Commit `1ce19642`.

### Completion Notes List

- Passive `#[PersonalData]` attribute in new capability `Shared/Privacy/Domain`; reflection reader as puerto `PersonalDataClassifier` + adapter `ReflectionPersonalDataClassifier` (cached, `#[AsAlias]`). Sin migración.
- `BankAccount`: `holderName`/`iban` marcados PII; `bic`/`currency`/`status`/`bankId` en claro; `Bank` intacto.
- **Decisión abierta resuelta por defecto:** `alias` dejado en claro (el ADR no lo enumera) — revisable en review.

### File List

**NEW:** `api/src/Shared/Privacy/{Domain/PersonalData.php, Application/PersonalDataClassifier.php, Infrastructure/ReflectionPersonalDataClassifier.php}`; `api/tests/Unit/Shared/Privacy/Infrastructure/{ReflectionPersonalDataClassifierTest.php, Fixtures/SampleWithPersonalData.php, Fixtures/SampleWithoutPersonalData.php}`; `api/tests/Unit/Backoffice/BankAccount/Domain/Entity/BankAccountPersonalDataTest.php`.
**UPDATE:** `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`.
