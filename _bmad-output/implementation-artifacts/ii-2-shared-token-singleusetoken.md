---
baseline_commit: 6f30eb50
---

# Story II-2 (PR-2): `Shared/Token` — `SingleUseToken` constant-time (prerrequisito de invitación y reset)

Status: done

Epic `identity-invitation-lifecycle` · **historia del lote paralelo aditivo** (`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Slice: **fundación aditiva pura** — nace la capacidad `Shared/Token` (`SingleUseToken`) **sin consumidores todavía**. Prerrequisito de **II-4** (invitación) y **II-5** (reset). No depende de ninguna otra historia (aditivo puro sobre lo que ya hay en `main`). Bajísimo riesgo: cero superficie pública, cero cambio de esquema, cero TCB tocado.

> **⚠️ Baseline = `origin/main` (`6f30eb50`).** Incluye II-0 (#458) e II-1 (#460, squash `81450ccf`). La implementación se hace en el worktree/rama **`feat/iam-organization-membership-bootstrap-…`** → aquí: worktree **`shared-token-single-use-token-na5w`**, rama **`feat/shared-token-single-use-token-na5w`** (creada sobre `main 6f30eb50` con autorización de Sergio; story + implementación = **1 PR**). `Shared/Token` **no existe hoy** — se crea de cero. **Nada que registrar en deptrac** (ver Decisión 4).

## Story

Como responsable de seguridad,
quiero un **único** building-block de token de un solo uso — alta entropía, TTL, hasheado en reposo y verificado en tiempo constante,
para que invitación (II-4) y reset (II-5) compartan **exactamente el mismo** mecanismo y no puedan divergir en su seguridad.

**Comportamiento que introduce:** la capacidad `Shared/Token` (VO `SingleUseToken` + acuñación CSPRNG + hash-at-rest + verificación constant-time). **Aún sin consumidores** — es infraestructura de dominio reutilizable.

**Invariantes que consume:** — (aditivo puro; no consume ninguna invariante previa).
**Invariantes que establece (base de SI-13):**

- El token es **alta-entropía** (CSPRNG, ≥ 256 bits), **single-use**, **TTL-bound**, **hasheado en reposo** (el crudo **nunca** persiste — solo su hash), y su verificación es **constant-time** (sin short-circuit).
- **Un único** mecanismo compartido (un generador, un hasher, un verificador) — **no** dos verificadores hand-rolled que puedan divergir.
- **Opacidad de causa:** una muerte de token (usado / TTL lapsado / no coincide) se resuelve como **un único fallo booleano** — la API **jamás** revela el motivo (alimenta SI-13 en los consumidores).

## Decisiones (contexto para el dev — recomendaciones argumentadas; confirmar Decisión 1 y 2 al inicio de dev, bajo riesgo → proceder si no hay objeción)

1. **Acuñación en `Domain/` como factory estático (RECOMENDADO — espejo `Shared/Uuid`), no en `Infrastructure/`.**
   El ADR D6 escribe literalmente *"`Domain/SingleUseToken` value + `Infrastructure/` CSPRNG generator + hasher"*, **pero en la misma frase invoca "mirroring `Shared/Uuid`, `Shared/Clock`"** — y `Shared/Uuid` **no tiene `Infrastructure/`**: `Uuid::generate()` mintea en `Domain/` usando `symfony/uid` bajo la excepción de capa documentada. Las primitivas que necesita el token (`random_bytes`, `hash`, `hash_equals`) son **funciones core de PHP** (no un framework), **invisibles a deptrac** (no son `use` de clases namespaced), así que un factory de `Domain/` es **deptrac-limpio** y **más fiel al «mirror `Shared/Uuid`»** que la palabra literal *Infrastructure*.
   - **Principio:** DIP + YAGNI/Regla-de-Tres — un puerto `TokenGenerator` con **un solo** adaptador es abstracción prematura para una primitiva-hoja sin variación de implementación.
   - **Objetivo:** **testabilidad sin contenedor** de código *security-critical* (unit test directo del dominio, sin DB, sin DI) + un único sitio donde vive el algoritmo (no puede divergir).
   - **Coste / alternativa descartada:** desviación **literal** de la palabra *Infrastructure* de D6 (mitigada por el propio «mirror `Shared/Uuid`» del D6 y el precedente de la allowlist `*domain`). *Alternativa (Opción A del ADR literal):* puerto `Domain/TokenGenerator` + adaptador `Infrastructure/RandomTokenGenerator` `#[AsAlias]` — se descarta **por ahora** (un adaptador, sin segundo implementador; se puede promover a puerto el día que aparezca — p. ej. un mock CSPRNG determinista para tests, que hoy no hace falta porque se testea el comportamiento, no la fuente de azar).
   - Si Sergio prefiere la Opción A literal, el resto de la story (AC de comportamiento) **no cambia** — solo se mueven 1–2 clases a `Infrastructure/` tras un puerto.

2. **Estado `consumed`/single-use vive en el CONSUMIDOR, no en el VO (RECOMENDADO).** El VO `SingleUseToken` modela el **digest en reposo**: `{ hash, expiresAt }`. El «un solo uso» es lifecycle del **consumidor** (D5: `Invitation.status: …→ACCEPTED`; reset consume su token). La contribución de la capacidad a la opacidad es un **`verify(): bool` opaco** (jamás un enum `EXPIRED`/`USED`). AC de opacidad se prueba en II-2 con **{token-erróneo, token-expirado}** → ambos `false` indistinguibles; la rama **used→false** la prueban los tests del consumidor (II-4/II-5). *Alternativa (VO porta `consumedAt`):* haría la rama `used` testeable ya en II-2, pero **pre-juzga** el modelado del consumidor (D5 pone el estado en `Invitation.status`) y añade estado al VO sin consumidor — se descarta por YAGNI/aditividad. Ver §Preguntas.

3. **TTL: el VO guarda `expiresAt` absoluto; el VALOR del TTL es política del consumidor.** `SingleUseToken` almacena un `DateTimeImmutable $expiresAt` (no un `DateInterval`). La acuñación recibe el `expiresAt` ya calculado (o un `ttl` + `now`), pero **II-2 no fija ningún TTL concreto** (invitación 72h, reset 1h, … son de II-4/II-5). Expiración es **pura**: `verify(...)`/`isExpired(...)` reciben `DateTimeImmutable $now` como **parámetro** (no se reachea `SystemClock` — mantiene el VO puro y testeable con un instante fijo). El caller pasa `$clock->now()` (`Shared\Clock\Domain\Clock`).

4. **Placement `Shared/Token` + deptrac: NADA que registrar.** Es una **capacidad vertical-slice** de `Shared/` (ADR `shared-module-organization.md` D1), **no** kernel (`SingleUseToken` no es primitiva base — tiene lifecycle propio + reúso cross-context, exactamente el precedente `Uuid` del ADR D8 §4). Los colectores `src/Shared/(.*/)?{Domain,Infrastructure}/.*` de `deptrac.yaml` **auto-pliegan** el módulo anidado en `Shared.Domain`/`Shared.Infrastructure` — **cero** cambios en `deptrac.yaml`, `deptrac.baseline.yaml`, ni `.bounded-context-allowlist`. `Iam/*` **ya** puede importar `Shared.*` (allowlist «siempre importable»), así que D6 (importable por `Iam/*`) se cumple sin tocar nada.

## Acceptance Criteria

> Anclados a **D6** (mecanismo único), **D11 / SI-13** (opacidad + higiene), **NFR7** (deptrac verde). Espejo de los AC del epic (líneas 584–602 de `epics-identity-invitation-lifecycle.md`).

1. **`Domain/SingleUseToken` es un VO puro, hasheado en reposo.** Existe `Erpify\Shared\Token\Domain\SingleUseToken` (`final readonly`), sin **ninguna** dependencia de framework (ni `use` de Symfony/Doctrine/HTTP/DI). Porta el **hash** del token (no el crudo) y su `expiresAt`. El **token crudo nunca se persiste ni se guarda en el VO** — solo su hash. *(D6, SI-13)*

2. **Acuñación de alta entropía, crudo entregado una sola vez.** La acuñación produce un par **`{ plaintext, SingleUseToken }`** (DTO inmutable `GeneratedToken`): el `plaintext` (alta entropía, ≥ 256 bits CSPRNG vía `random_bytes`, codificado URL-safe base64 sin padding) es **para entregar** (se devuelve una vez), y el `SingleUseToken` es **para que el consumidor lo persista**. Dos acuñaciones producen **plaintexts distintos** (un test lo prueba). *(D6)*

3. **Verificación constant-time (invariante).** Al comparar un token presentado contra el hash almacenado, la comparación es **constant-time** — se usa `\hash_equals(...)` (precedente único del repo: `Shared/Search/.../Keyset/CursorCodec.php`), **sin** `===`/`strcmp`/`str_starts_with`/short-circuit sobre el secreto. Un test prueba: token correcto → acepta; cualquier diferencia (1 char / longitud distinta) → rechaza; y la implementación usa `hash_equals` (prueba **estructural/comportamental**, **no** medición de wall-clock — un test de timing sería flaky y está prohibido). *(D6)*

4. **Opacidad de causa (invariante).** `verify(...)` devuelve un **`bool` opaco**. Un token con TTL lapsado **y** un token que no coincide fallan **indistinguiblemente** (ambos `false`, sin excepción con motivo, sin enum de causa). Un test prueba que {token-expirado-pero-correcto, token-no-coincidente} son ambos `false`. *(D11, SI-13; la rama `used`→false la cubren los consumidores II-4/II-5)*

5. **Un único mecanismo compartido.** Existe **un** camino de acuñación, **un** hasher y **un** verificador en `Shared/Token` — invitación y reset (futuros) consumirán **estos**, sin re-implementar un segundo verificador. No hay duplicación del compare ni del hash. *(D6)*

6. **Deptrac verde sin tocar config.** `Shared/Token/{Domain[,Infrastructure]}` queda absorbido por los colectores `Shared.*`; `make php.deptrac` sigue en **0 violations** **sin** editar `deptrac.yaml`/baseline/allowlist. `Shared/Token` es importable por `Iam/*` (verificable: un import de prueba desde `Iam` no violaría — no hace falta escribirlo, basta con que la regla lo permita). *(NFR7)*

7. **Gates verdes, aditividad probada.** `make php.quality` EXIT 0 (deptrac, PHPStan level max, cs-fixer, PHPCS ≤120c, PHPMD incl. coupling ≤13 en tests). Suite completa **sin regresión**: `make php.test` (PHPUnit + Behat) sigue verde — II-2 no toca ningún test existente. SonarCloud **new_coverage ≥ 80%** sobre las líneas nuevas.

## Tasks / Subtasks

- [x] **Setup del worktree** (AC: 7)
  - [x] Trabajar dentro de `.claude/worktrees/shared-token-single-use-token-na5w` (rama `feat/shared-token-single-use-token-na5w`). Levantar stack: `make app.dev`.
  - [x] **Worktree fresco → `make php.behat.install`** antes de `php.stan`/`php.quality` (tooling aislado; gotcha II-0/II-1, [ver Previous Story Intelligence]).
- [x] **Task 1 — VO `SingleUseToken`** (AC: 1, 3, 4)
  - [x] Crear `api/src/Shared/Token/Domain/SingleUseToken.php` — `final readonly`, ctor privado, reconstitución `fromHash(string $hash, DateTimeImmutable $expiresAt): self` (rehidratar desde columnas del futuro consumidor).
  - [x] `verify(#[\SensitiveParameter] string $presentedPlaintext, DateTimeImmutable $now): bool` — `!$this->isExpired($now) && \hash_equals($this->hash, self::digest($presentedPlaintext))`. **Sin** short-circuit que ordene los factores por el secreto (evaluar expiración primero es tiempo-constante respecto al *contenido* del token).
  - [x] `isExpired(DateTimeImmutable $now): bool` (puro, `$now >= $this->expiresAt`) + accessors `toHash(): string`, `expiresAt(): DateTimeImmutable` para el consumidor.
  - [x] Digest privado `self::digest(string $plaintext): string` = `\hash('sha256', $plaintext)` (hex). Constante `HASH_ALGO = 'sha256'`.
- [x] **Task 2 — Acuñación (factory `Domain/` — Decisión 1)** (AC: 2, 5)
  - [x] `SingleUseToken::mint(DateTimeImmutable $expiresAt): GeneratedToken` — `random_bytes(self::TOKEN_BYTES)` (`TOKEN_BYTES = 32`) → `plaintext` URL-safe base64 sin padding (`sodium_bin2base64(..., SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)`, precedente `SodiumEnvelopeEncryptor`); `SingleUseToken` con `digest($plaintext)` + `$expiresAt`.
  - [x] `api/src/Shared/Token/Domain/GeneratedToken.php` — DTO `final readonly` inmutable `{ public string $plaintext; public SingleUseToken $token; }` (espejo `Shared/Crypto/Application/WrappedDek`). El `plaintext` **nunca** se loguea; sin `__toString`.
- [x] **Task 3 — Tests unitarios** (AC: 1–5, 7)
  - [x] `api/tests/Unit/Shared/Token/Domain/SingleUseTokenTest.php` — `@internal`, `#[CoversClass(SingleUseToken::class)]` `#[CoversClass(GeneratedToken::class)]`, `final extends TestCase`, estilo `#[Test]` + `it…` (convención `Shared/Crypto`). Casos: entropía (mints distintos), hash-at-rest (plaintext ≠ hash guardado; el VO no expone el crudo), frontera TTL con instante fijo, verify acepta/rechaza (1-char / longitud), opacidad {expirado, no-coincide}→`false`.
  - [x] Cubrir **todas** las líneas nuevas para SonarCloud `new_coverage ≥ 80%` con `#[CoversClass]` estricto ([[sonar-new-coverage-local-verification]]).
- [x] **Task 4 — Doc de seguridad** (AC: 7)
  - [x] Añadir 1 línea a `PRODUCTION_SECURITY_CHECKLIST.md` documentando el primitivo `SingleUseToken` (alta entropía, hash-at-rest, constant-time) — cambio *security-sensitive* (obligatorio por `docs/rules/security.md`).
  - [x] **NO** tocar `docs/api-error-contract.md` (no hay marker nuevo en II-2; los tipos de error del token nacen en II-3/II-4 — espejo de la nota del ADR).
- [x] **Task 5 — Gates** (AC: 6, 7)
  - [x] `make php.stan` sobre los ficheros nuevos (si segfault 139 del web worker → `PHP_SERVICE=messenger_worker`, [[php-worker-segfault-php-service-override]]).
  - [x] `make php.unit c='--filter SingleUseTokenTest'` → verde; luego `make php.test` (regresión) → verde.
  - [x] `make php.quality` EXIT 0 (verificar deptrac **0 violations sin** editar config). PWA no se toca.

## Dev Notes

### Contrato de diseño (fuente de verdad)

- **ADR** `docs/adr/identity-invitation-lifecycle.md` **D6** (el building-block), **D11 / SI-13** (opacidad + higiene del token), **D9/D5** (los futuros consumidores: reset e invitación).
- **Addendum** `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`: **SI-13** (opacidad+higiene), fila **PR-2** de «Localización de decisiones por PR», DAG (`PR-2` es prereq de `PR-4`/`PR-5`).
- **Epic** `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` → **Story II-2** (líneas 570–603): los 5 AC de comportamiento.
- **Precedencia** (jerarquía BMAD): epic > addendum > ADR de diseño > esta story. Donde D6 dice literal «Infrastructure» pero «mirroring `Shared/Uuid`», ver **Decisión 1** (la contradicción interna del ADR se resuelve a favor del mirror `Shared/Uuid`, argumentada).

### Estado actual del código (leído sobre `main 6f30eb50` — NO reinventar; mirror de patrones reales)

- **`Shared/Uuid/Domain/Uuid.php`** — VO-hoja que mintea en `Domain/` (`generate()` sobre `symfony/uid`) bajo excepción de capa. **Modelo de placement** para `SingleUseToken` (Decisión 1).
- **`Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/CursorCodec.php:95,100,135`** — **único** uso de `\hash_equals` del repo (verificación HMAC del cursor). **Modelo directo del compare constant-time** de `verify`.
- **`Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php:53,62`** — `\random_bytes(...)` para el nonce + `\sodium_bin2base64(..., SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)` para encoding URL-safe. **Modelo del CSPRNG + encoding** del `plaintext`.
- **`Shared/Crypto/Application/WrappedDek.php`** — DTO `final readonly` de props públicas promovidas. **Modelo de `GeneratedToken`**.
- **`Shared/Crypto/Domain/EncryptionScopeId.php`** — VO `final readonly`, ctor privado + factories estáticos, invariante-guarded. **Mejor plantilla de VO** (estilo).
- **`Iam/Identity/Domain/HashedPassword.php`** — VO opaco que envuelve un hash, `#[\SensitiveParameter]` en el factory, `equals()` por valor. **Modelo de VO «hasheado en reposo»** (ojo: usa `===` porque compara dos hashes almacenados, no un secreto vs input — para `SingleUseToken` **sí** va `hash_equals`).
- **`Iam/Identity/Infrastructure/Security/UserProvider.php:84-90`** (`equaliseTiming()`) — precedente de dummy-hash anti-timing (contexto: NFR de constant-time; **no** se necesita en II-2, es de II-8, pero explica por qué el verify importa).
- **`Media/Infrastructure/Image/InterventionImageNormalizer.php:73`** — `\hash('sha256', ...)`. Precedente de SHA-256.
- **`Shared/Clock/Domain/Clock.php`** — puerto `Clock::now(): DateTimeImmutable`. El caller (futuro consumidor) inyecta `Clock` y pasa `$clock->now()` a `verify`. `SingleUseToken` **no** conoce el reloj.

### Por qué SHA-256 y NO bcrypt/argon2 (nota de seguridad — evitar «hardening» equivocado)

El plaintext ya es **256 bits uniformes de CSPRNG**, no un password de baja entropía. La transformación en reposo correcta es un **hash criptográfico rápido (SHA-256)**: un KDF lento (bcrypt/argon2) existe para **resistir fuerza bruta sobre secretos de baja entropía** y aquí **no compra nada** (256 bits son inbruteforceables) mientras añade coste por verificación. **No** cambiar SHA-256 por un KDF «para endurecer».

### Artefactos a crear/tocar (rutas exactas)

**Crear:**
- `api/src/Shared/Token/Domain/SingleUseToken.php`
- `api/src/Shared/Token/Domain/GeneratedToken.php`
- `api/tests/Unit/Shared/Token/Domain/SingleUseTokenTest.php`

**Tocar:**
- `PRODUCTION_SECURITY_CHECKLIST.md` (+1 línea del primitivo)
- La story (Dev Agent Record) + `sprint-status.yaml` (`ii-2-… : ready-for-dev` → lo hace create-story; el dev lo mueve a `review` al terminar).

**NO tocar:** `deptrac.yaml`, `deptrac.baseline.yaml`, `.bounded-context-allowlist`, migraciones, doctrine mapping, `services.yaml` (con factory `Domain/` no hace falta wiring — Decisión 1), `docs/api-error-contract.md`, nada del PWA.

### Decisiones de diseño y gotchas críticos

1. **`#[\SensitiveParameter]`** en todo parámetro que reciba el plaintext (`verify`, y el interno `digest` si es método). El VO **no** implementa `__toString` ni expone el crudo; `GeneratedToken.plaintext` es `readonly` y **jamás** se loguea.
2. **Orden de factores en `verify`**: evaluar `isExpired($now)` (comparación de fechas, independiente del contenido del token) **antes** de `hash_equals` es tiempo-constante respecto al **secreto** — lo que importa es que la comparación **del token** no haga short-circuit por byte. `hash_equals` lo garantiza. No «optimizar» con un `str_starts_with`/`===` previo.
3. **Persistencia diferida (readiness only):** el VO se diseña **embeddable-ready** (dos campos: `hash` string + `expiresAt` timestamptz) para que un futuro `Invitation` lo adopte vía `#[ORM\Embeddable]` + `#[ORM\Embedded(columnPrefix: '…')]` (patrón `Shared/Storage/StoredObject` ↔ `Bank`) **o** columnas escalares + rehidratación en accessor (patrón `HashedPassword` ↔ `User`). **II-2 NO persiste nada** — solo mantén el ctor/`fromHash` con forma amistosa a ese futuro. No añadir `#[ORM]` en II-2 (sin consumidor).
4. **deptrac invisible a funciones globales:** `random_bytes`/`hash`/`hash_equals`/`sodium_bin2base64` son funciones globales, no `use` de clases namespaced — deptrac no las ve. Por eso el factory `Domain/` (Decisión 1) pasa el gate sin allowlist. (Confirmar con `make php.deptrac` igualmente.)
5. **Sonar new_coverage estricto:** `#[CoversClass]` acredita **solo** la clase target; cubre `SingleUseToken` **y** `GeneratedToken`. Verifica local (clover) antes de push ([[sonar-new-coverage-local-verification]], [[coversclass-restricts-clover-and-phpmd-coupling]]).
6. **PHPMD coupling ≤13 aplica también a los tests** ([[phpmd-coupling-applies-to-tests]]) — el test es pequeño, no debería acercarse, pero no metas stubs innecesarios.

### Fuera de alcance (NO hacer)

- **NO** modelar `Invitation` ni el reset (II-4/II-5) — II-2 es solo la capacidad.
- **NO** añadir `consumedAt`/estado `used` al VO (Decisión 2; el single-use es del consumidor).
- **NO** persistir, mapear en Doctrine, ni crear migración.
- **NO** wiring de `services.yaml`, HTTP, voter, DTO, `#[Assert]`, marker de error, ni `api-error-contract.md`.
- **NO** dummy-hash anti-timing global / `Referrer-Policy` / strip de URL / redacción de logs — eso es **II-8** (hardening transversal); SI-13 aquí es solo el **primitivo opaco**.
- **NO** tocar deptrac/allowlist/baseline.

### Testing (obligatorio; convenciones del repo)

- Unit test de dominio **directo** (sin contenedor, sin DB). Namespace `Erpify\Tests\Unit\Shared\Token\Domain`, fichero espejo de `src/`.
- Estilo: `@internal` + `#[CoversClass(...)]` + `final extends TestCase`; `#[Test]` + `it…` (convención `Shared/Crypto`, la más nueva/análoga). AAA, un comportamiento por test.
- **Constant-time = prueba estructural/comportamental**, **no** timing: (a) match → true; (b) 1-char distinto → false; (c) longitud distinta → false; (d) el compare usa `hash_equals` (revisión de código / que no exista `===`/`strcmp` sobre el secreto). **Prohibido** un test que mida wall-clock (flaky).
- Entropía: dos `mint(...)` → `plaintext` distintos (à la `itProducesADifferentCiphertextEachCall` de `SodiumEnvelopeEncryptorTest`).
- Frontera TTL: `expiresAt` fijo; `verify(correcto, expiresAt)` y `verify(correcto, expiresAt+1s)` → false (expirado); `verify(correcto, expiresAt-1s)` → true.
- Sin Behat (no hay superficie HTTP). Sin Playwright (no hay PWA).

### Project Structure Notes

- **PSR-4:** `Erpify\Shared\Token\Domain\SingleUseToken` → `api/src/Shared/Token/Domain/SingleUseToken.php`. Tests: `Erpify\Tests\Unit\Shared\Token\…` → `api/tests/Unit/Shared/Token/…`. (Confirmado en `api/composer.json`.)
- **Vertical-slice** `Shared/Token/` con **solo** `Domain/` (Decisión 1). Si Sergio elige la Opción A del ADR, aparece `Shared/Token/Infrastructure/` — ambos auto-plegados por deptrac.
- Sin conflicto con la estructura unificada: es el patrón `Shared/Uuid`/`Shared/Clock` (capacidad-hoja).

### References

- [Source: docs/adr/identity-invitation-lifecycle.md#D6] — building-block `SingleUseToken` en `Shared/Token`; «mirroring `Shared/Uuid`, `Shared/Clock`».
- [Source: docs/adr/identity-invitation-lifecycle.md#D11] · [Source: _bmad-output/planning-artifacts/arch-addendum-identity-invitation.md#SI-13] — opacidad + higiene del token.
- [Source: _bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md#Story-II-2] — los 5 AC (líneas 570–603) + fila PR-2.
- [Source: docs/adr/shared-module-organization.md#D1] — `Shared` vertical-slice por capacidad; [#D8] — precedente `Uuid` (capacidad-hoja fuera del kernel).
- [Source: api/src/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/CursorCodec.php#L95-135] — `hash_equals` constant-time (único precedente).
- [Source: api/src/Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php#L53-62] — `random_bytes` + base64 URL-safe.
- [Source: api/src/Iam/Identity/Domain/HashedPassword.php] — VO opaco «hasheado en reposo» + `#[\SensitiveParameter]`.
- [Source: api/tools/deptrac/deptrac.yaml#L132-138] — colectores `src/Shared/(.*/)?…` auto-fold (nada que registrar).

### Previous Story Intelligence (II-1, #460 merged `81450ccf`; II-0, #458)

- **Worktree fresco → `make php.behat.install` ANTES de `php.stan`/`php.quality`** (tooling aislado bajo `api/tools/behat/vendor`). Fue gotcha real en II-0 e II-1. [[behat-tooling-isolated-install]]
- **`php.stan` puede segfault (exit 139) en el web worker FrankenPHP** → `make php.stan PHP_SERVICE=messenger_worker`. [[php-worker-segfault-php-service-override]]
- **Sonar new_coverage ≥80% es un gate real** (a II-1 le falló a 78.8% por `#[CoversClass]` estricto sin acreditar líneas nuevas; fix = cubrir cada clase nueva). Verifica clover local antes de push. [[sonar-new-coverage-local-verification]]
- **`make php.quality` es el único que dispara PHPMD/cs-fixer**; `php.stan` solo no basta. [[phpmd-no-baseline-quality-sweep]]
- **En el worktree, verificar sobre el path del worktree** (no logs viejos del primario); confía en el exit code recién impreso. [[feedback-verify-fresh-not-stale-logs]] · [[code-review-subagents-read-primary-checkout]]
- **Diferencia con II-1:** II-1 tocaba seams cross-context (`.bounded-context-allowlist` + deptrac per-file). **II-2 NO** — `Shared` es siempre importable, cero seams, cero allowlist. Más simple.

### Git Intelligence

- `main` tip `6f30eb50` (chore: rm-3/rm-4 done). Últimos relevantes: `81450ccf` (II-1 #460), `4ccb2f92` (II-0 #458). El worktree se creó sobre `6f30eb50`.
- Otro worktree activo `iam-rbac-ocp-gate-xjdl` (rama `test/iam-rbac-ocp-gate-xjdl`, RM-6) — **track ortogonal**, no interfiere con II-2 (stacks Docker aislados por `COMPOSE_PROJECT_NAME`).

### Project Context Reference

`docs/project-context.md` — reglas PHP 8.5 / Symfony 8 / hexagonal-DDD. Relevante aquí: `declare(strict_types=1)`, `final readonly`, tipos en todo, excepciones de dominio (no en este story), **Domain puro sin framework**, `hash_equals` para compares de secreto, no secretos en logs, líneas ≤120c.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Amelia / bmad-dev-story)

### Debug Log References

- `make php.unit c='--filter SingleUseTokenTest'` → 8 tests, 18 assertions, OK.
- `make php.stan` → No errors (830 ficheros). 1 hallazgo corregido: `assertSame(false, false)` redundante tras dos `assertFalse` (PHPStan `method.alreadyNarrowedType`) → eliminado.
- `make php.quality` → **EXIT 0**. Deptrac **Violations 0 / Skipped 75 / Uncovered 0** sin tocar `deptrac.yaml`/baseline/allowlist (AC6). cs-fixer/rector idempotentes (2ª pasada «Fixed 0 of 846»); bounded-context «No violations».
- `make php.unit.coverage c='--filter SingleUseTokenTest'` → **100%** en ambos ficheros nuevos (`SingleUseToken` 18/18 elements, `GeneratedToken` 2/2; 0 líneas sin cubrir) → Sonar `new_coverage` OK.
- `make php.test` → PHPUnit **1601** (3 skip), Behat **250 scenarios / 2309 steps**, todo verde (sin regresión).

### Completion Notes List

- **Decisión 1 aplicada (Domain factory, mirror `Shared/Uuid`):** `SingleUseToken::mint()` es un factory estático de `Domain/` usando `random_bytes` + `sodium_bin2base64` (URL-safe, no-padding) + `hash('sha256')` — funciones core, deptrac-limpias, sin puerto/adaptador (YAGNI). Cero wiring en `services.yaml`.
- **Decisión 2 aplicada (single-use en el consumidor):** el VO es `{ hash, expiresAt }` inmutable; `verify(): bool` opaco. Sin `consumedAt`. La rama `used→false` la probarán II-4/II-5.
- **Constant-time:** `verify` = `!isExpired($now) && \hash_equals($this->hash, digest($presented))`. `hash_equals` compara siempre dos digests SHA-256 de **igual longitud** (64 hex), así que incluso un token presentado de longitud distinta pasa por el compare constant-time. Prueba **comportamental** (acepta exacto; rechaza +1 char / longitud distinta / vacío), **no** timing wall-clock.
- **Opacidad (SI-13):** {expirado-correcto, no-coincidente} → ambos `false` indistinguibles (test `itFailsIndistinguishablyForAnExpiredAndAWrongToken`).
- **Hash-at-rest:** el crudo se entrega en `GeneratedToken.plaintext` (una vez) y **nunca** persiste; solo su hex SHA-256 vive en el VO. `#[\SensitiveParameter]` en `verify`, `digest` y el ctor de `GeneratedToken` (redacción en stack traces).
- **SHA-256, no KDF:** documentado en el docblock del VO — el crudo ya es 256-bit CSPRNG, un KDF lento no aporta y encarece cada verificación.
- **Seguridad:** +1 item en `PRODUCTION_SECURITY_CHECKLIST.md` (§6, junto a `HashedPassword`). `api-error-contract.md` **no** tocado (sin marker nuevo). Sin HTTP/voter/DTO/migración/PWA.
- **rector/cs-fixer:** blank line entre consts tipadas; `SODIUM_*` sin `\` inicial; `assertEquals`→`assertSame` en el round-trip (correcto: `expiresAt()` devuelve la **misma instancia** guardada). Todo verde tras las mutaciones.

### Change Log

| Fecha | Cambio |
|-------|--------|
| 2026-07-08 | Implementada II-2: capacidad `Shared/Token` (`SingleUseToken` VO + `GeneratedToken` DTO en `Domain/`, factory `mint` CSPRNG, verify constant-time opaco) + test unitario (8 casos, 100% cov) + item de seguridad. Gates verdes. Status → review. |
| 2026-07-08 | Code-review (3 capas): Auditor 7/7 AC met. Aplicados 3 findings — F1 `GeneratedToken.plaintext` privado + accessor `plaintext()`; F2 cap `MAX_PLAINTEXT_LENGTH` en `verify`; F3 tests frontera TTL (cross-tz + micro-instante). 1 defer (single-use del consumidor → deferred-work.md), 4 dismiss. Gates re-verdes (quality EXIT 0, cov 100%, PHPUnit 1603, Behat 250, psalm-taint OK). Status → done. |

### File List

**Nuevos:**
- `api/src/Shared/Token/Domain/SingleUseToken.php`
- `api/src/Shared/Token/Domain/GeneratedToken.php`
- `api/tests/Unit/Shared/Token/Domain/SingleUseTokenTest.php`

**Modificados:**
- `PRODUCTION_SECURITY_CHECKLIST.md` (+1 item: primitivo `SingleUseToken`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (`ii-2` → in-progress → review → done)
- `_bmad-output/implementation-artifacts/ii-2-shared-token-singleusetoken.md` (esta story: tasks + Dev Agent Record + Review Findings + Status)
- `_bmad-output/implementation-artifacts/deferred-work.md` (+1 defer: single-use del consumidor)

## Preguntas abiertas / decisiones a confirmar antes de (o al inicio de) dev

1. **Acuñación en `Domain/` (factory estático, mirror `Shared/Uuid`) vs `Infrastructure/` literal del ADR D6** — recomendado **Domain/** (Decisión 1, argumentada: DIP/YAGNI + testabilidad sin contenedor; deptrac-limpio; más fiel al «mirror `Shared/Uuid`»). Confirmar con Sergio; si prefiere el literal, mover 1–2 clases tras un puerto `Infrastructure/` sin cambiar AC.
2. **`consumed`/single-use en el consumidor, no en el VO** — recomendado (Decisión 2). Confirmar que el «used→false» se testea en II-4/II-5 (consumidor), no en II-2.
3. **Encoding del plaintext:** `sodium_bin2base64` URL-safe no-padding (recomendado, precedente `SodiumEnvelopeEncryptor`) vs `bin2hex` (más largo, sin dependencia de sodium en `Domain/`). `sodium` es ext requerida (project-context), así que URL-safe base64 es OK; confirmar si se prefiere hex por pureza. Bajo riesgo.

## Review Findings (code review — 2026-07-08 · 3 capas independientes)

Acceptance Auditor: **7/7 AC met, 0 hallazgos**, ambas decisiones flagged implementadas, nada fuera de alcance. Los hunters trajeron Low/Med. Triaje **D=1 · P=2 · W=1 · R=4**.

> **Todos resueltos (2026-07-08): decision F2 aplicada + 2 patches aplicados. Gates re-verdes.**

### decision-needed

- [x] [Review][Decision] **`verify()` hashea input de longitud ilimitada — sin cap antes del SHA-256** [`api/src/Shared/Token/Domain/SingleUseToken.php`]. Diverge del NFR1 de `CursorCodec` («never hash an unbounded input»). **RESUELTO (Sergio → aplicar):** añadido `MAX_PLAINTEXT_LENGTH = 128` + guard `strlen(...) > MAX → return false` al inicio de `verify()` (antes del `digest`). No filtra el secreto (longitud pública); acota el trabajo. Test `itRejectsAnOverLongPresentedTokenWithoutHashingIt` cubre el branch.

### patch

- [x] [Review][Patch] **`GeneratedToken.plaintext` público filtra el token crudo a logs** [`api/src/Shared/Token/Domain/GeneratedToken.php`]. **APLICADO:** propiedad **privada** + accessor `plaintext()` (espejo de `SingleUseToken` hash/`toHash()`) → `json_encode`/Monolog emiten `{}`; el vector `var_dump` ya lo cubre el lint no-debug-artifacts. Tests actualizados a `->plaintext()`.
- [x] [Review][Patch] **Faltan tests de frontera TTL** [`api/tests/Unit/Shared/Token/Domain/SingleUseTokenTest.php`]. **APLICADO:** `itComparesExpiryByInstantNotWallClockAcrossTimezones` (mismo instante en `+02:00` vs UTC → fija instant-comparison) + micro-instante exacto plegado en `itVerifiesOnlyStrictlyWithinTheTtlWindow` (antes/exacto/después + `-1µs`). *(Los dos tests de frontera se consolidaron en uno para no superar PHPMD `TooManyPublicMethods`≤10.)*

### defer

- [x] [Review][Defer] **single-use no forzado por el VO** [`api/src/Shared/Token/Domain/SingleUseToken.php`] — el nombre promete de más; `verify()` devuelve `true` repetidamente hasta el TTL. Es **Decisión 2** (single-use = lifecycle del consumidor). Contrato para II-4/II-5: **retirar el digest atómicamente** (retire-then-act en la misma transacción) para evitar replay dentro de la ventana TTL. Deferred — pertenece al consumidor.

### dismissed (considerados, sin acción)

- **`fromHash()` sin validación de shape:** fail-closed por construcción (un hash ≠ 64-hex nunca casa vía `hash_equals` por longitud), input propio/confiable → YAGNI (ambos hunters coinciden).
- **timing expired-vs-wrong (`&&`):** no filtra el secreto (solo estado de reloj, ya conocido por el caller); el compare del token sí es constant-time. Hardening de timing transversal = II-8.
- **entropía sin `sodium_memzero`:** el `plaintext` es valor de retorno (debe sobrevivir para llegar al portador, a diferencia de un DEK interno); `$raw` es local efímero.
- **naming `ENTROPY_BYTES` vs `TOKEN_BYTES`:** `ENTROPY_BYTES` es más claro; sin cambio.
