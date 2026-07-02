---
title: 'AF-1.2 · Firewall de sesión + SecurityUser/provider/authenticator + CSRF'
type: 'feature'
created: '2026-07-02'
baseline_commit: '79d6b4d900a4e161823764e05a3ec7f1d638a49a'
status: 'in-review'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-auth-foundation-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/af-1-1-user-aggregate-backoffice-identity-persistencia.md'
  - '{project-root}/api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php'
  - '{project-root}/api/src/Backoffice/BankAccount/Infrastructure/Cli/EraseBankAccountSubjectCommand.php'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** ERPify no tiene autenticación: todo `/api/*` es público. AF-1.1 (#419) aterrizó el agregado
`User` libre de framework (email, `HashedPassword`, `Role`, puerto `findByEmail(Email)`), pero **nadie puede
autenticarse todavía** — falta el firewall, el adapter de seguridad y la vía de alta de usuarios.

**Approach:** Instalar `symfony/security-bundle` + un **firewall de sesión httpOnly** con `json_login`;
envolver el `User` en `SecurityUser` + `UserProvider` (Infra); enrutar los fallos de login por el **pipeline
RFC 9457 existente** (401 `unauthenticated`) con un failure-handler que **re-lanza**; cubrir la superficie
**CSRF del login** (same-origin + CORS no ampliada + `application/json` + `SameSite=Lax`); y bootstrap del 1er usuario (`identity:user:create` +
fixture Alice) para ejercer el login. **Sin** `access_control` default-deny (AF-1.3) ni swap de
`ActorContextFactory` (E3).

## Boundaries & Constraints

**Always:**
- Symfony Security **sólo en `Backoffice/Identity/Infrastructure/`**; `Domain` intacto. `deptrac` +
  `bounded-context` verdes (el layer `Identity.Infrastructure` ya admite `Vendor.Symfony`).
- Fallos auth por el pipeline RFC 9457 (**throw**, nunca `JsonResponse` manual). `AuthenticationException`→401
  y `AccessDeniedException`→403 **ya existen** en `ProblemDetailsFactory`.
- **Hashing sólo en Infrastructure**; `Application`/`Domain` no importan el hasher. El comando hashea y
  entrega un `HashedPassword` ya calculado al caso de uso.
- Roles: el adapter emite `Role->value` como `ROLE_*`; el prefijo `ROLE_` **sólo** en el borde de Infra.
- Mantener `hide_user_not_found: true`: email desconocido y password mala son indistinguibles (401 genérico).
- **Sin migración de esquema** (`identity_user` ya existe). Same-origin: **no** ensanchar CORS/nelmio ni Mercure.

**Ask First:**
- `entry_point`: **diferido por defecto** (un único authenticator `json_login` sin `access_control` arranca
  sin él; el default-deny de AF-1.3 lanzará `InsufficientAuthenticationException` ⊂ `AuthenticationException`
  que el arm ya mapea a 401). Añadir uno mínimo que re-lance **sólo** si `debug:firewall`/boot lo exige —
  avisar antes.

**Never:**
- `access_control` default-deny → **AF-1.3**. `#[IsGranted]`/voter/swap de `ActorContextFactory` → **E3/3.1**.
  Login UI PWA → aguas abajo.
- Tocar `ProblemDetailsFactory` (el arm 401 es constant-time, pinneado por
  `ConstantTimeAuthBranchingContractTest`; un 2º arm rompe el test) ni `docs/api-error-contract.md` (filas 401
  ya presentes).
- JWT/tokens en cliente. Puerto `PasswordHasher` de dominio (YAGNI, 1 consumidor). Sembrar credenciales en
  migraciones.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Login OK | `POST /api/v1/backoffice/login {email,password}` válidos | 2xx + cookie sesión httpOnly; request siguiente lleva el `SecurityUser` | N/A |
| Password incorrecta | email válido, password mala | 401 `problem+json` `type: unauthenticated`, título genérico, sin sesión | pipeline (throw) |
| Email desconocido | email inexistente | **401 idéntico** al de password mala (`hide_user_not_found`) | `UserProvider` → `UserNotFoundException` → `AuthenticationException` → 401 |
| Email malformado | `""` / basura en payload | **401, no 500** | `UserProvider` captura `InvalidEmail` de `Email::from` → `UserNotFoundException` (cierra el landmine `findByEmail('')`) |
| CLI alta | `identity:user:create alice@… <pw> --role AUDIT_READER` | hashea en Infra, persiste `User`, `SUCCESS` | rol inválido → `INVALID`; email duplicado → `FAILURE` |

</frozen-after-approval>

## Code Map

- `api/config/bundles.php` -- registrar `SecurityBundle` (1 línea en el array `['all' => true]`).
- `api/config/packages/security.yaml` -- **NUEVO**; firewall `main`. Sobreescribe el scaffold de la receta Flex.
- `api/config/packages/framework.yaml` -- `session` cookie hardening (preservar `when@test`).
- `api/composer.json` -- `symfony/security-bundle` (+ promover `symfony/password-hasher` a directo).
- `…/Identity/Infrastructure/Security/{SecurityUser,UserProvider,ProblemDetailsAuthenticationFailureHandler,PasswordHasher}.php` -- adapters de seguridad (NUEVOS).
- `…/Identity/Infrastructure/Http/LoginController.php` -- ruta stub `check_path` (interceptada por `json_login`).
- `…/Identity/Application/CreateUser.php` -- caso de uso `register`+`ensure`+`save` (sin bus).
- `…/Identity/Infrastructure/Cli/CreateUserCommand.php` -- `identity:user:create`.
- `api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php` -- **sólo lectura** (puente 401; no editar).
- `api/src/Backoffice/BankAccount/Infrastructure/Cli/EraseBankAccountSubjectCommand.php` -- patrón CLI a espejar.

## Tasks & Acceptance

**Execution:**
- [x] `composer require symfony/security-bundle` (en contenedor) + registrar el bundle en `bundles.php`.
- [x] `framework.yaml` -- `session.cookie_secure: auto` + `cookie_samesite: lax` + `cookie_httponly`; no romper `when@test`.
- [x] `Security/SecurityUser.php` -- envuelve `User`; `getRoles()`→`ROLE_*`, `getUserIdentifier()`=email, `getPassword()`=hash.
- [x] `Security/UserProvider.php` -- `Email::from` en try/catch → `findByEmail` → `UserNotFoundException` (email inválido/ausente = 401, no 500).
- [x] `Security/ProblemDetailsAuthenticationFailureHandler.php` -- `onAuthenticationFailure` = `throw $exception;`.
- [x] `Security/PasswordHasher.php` -- adapter (Infra) sobre `PasswordHasherFactoryInterface->getPasswordHasher(SecurityUser::class)`; mismo algoritmo que `password_hashers`.
- [x] `Http/LoginController.php` -- ruta stub `POST /api/v1/backoffice/login`.
- [x] `Application/CreateUser.php` -- `User::register`+`Validator::ensure`+`save`; deptrac verde (sin `Vendor.Symfony`).
- [x] `Cli/CreateUserCommand.php` -- args email/password, opción repetible `--role`; hashea → `HashedPassword::fromHash` → `CreateUser`.
- [x] `security.yaml` -- firewall `main`: `provider` (UserProvider), `json_login` (`check_path`+`failure_handler`+`enable_csrf`), sesión stateful, `password_hashers` (`SecurityUser`→auto). **Sin** `access_control`.
- [x] `tests/Unit/…/Application/InMemoryUserRepository.php` -- fake del puerto (diferido de AF-1.1), `findByEmail` case-insensitive.
- [x] Tests unit -- `CreateUserTest`, `SecurityUserTest` (mapeo `ROLE_*`/identifier/password), `UserProviderTest` (found→SecurityUser; desconocido/blank→`UserNotFoundException`), `CreateUserCommandTest` (`CommandTester`: SUCCESS/INVALID/FAILURE). Cubre la matriz I/O.
- [x] `tests/DataFixtures/Fixtures/User.yaml` (+ factory en `tests/DataFixtures/`) -- Alice con hash **precomputado** (no ensuciar `Domain`); reusar `UserMother::DEFAULT_ID/DEFAULT_EMAIL`.
- [x] `features/backoffice/identity/login.feature` (+ reuse de contextos Http/Json) -- login OK (sesión) + login inválido (401 problem+json).
- [x] Docs -- `architecture-api.md` (Identity gana `Application`+`Infrastructure/{Security,Http,Cli}`), `PRODUCTION_SECURITY_CHECKLIST.md` + `docs/rules/security.md` (firewall sesión, postura CSRF del login, `hide_user_not_found`, sesión file-based = nota de escalado). **`api-error-contract.md` sin cambio.**

**Acceptance Criteria:**
- Given un `User` persistido, when `POST /login` con credenciales válidas, then 2xx + cookie de sesión httpOnly y la request autenticada resuelve a un `SecurityUser` con sus `ROLE_*`.
- Given credenciales inválidas (password mala, email desconocido o malformado), when `POST /login`, then **401 `problem+json` `type: unauthenticated`** por el pipeline, sin sesión ni `JsonResponse` manual, e indistinguible entre los casos.
- Given el aislamiento de capas, when `make php.quality`, then `deptrac`+`bounded-context`+`error-contract` verdes.
- Given `identity:user:create` válido, when se ejecuta, then persiste un `User` con password **hasheado en Infra**; email duplicado → `FAILURE`, rol inválido → `INVALID`.
- Given CSRF, when se cierra el mecanismo del login, then queda cubierto por same-origin + CORS no ampliada + requisito `application/json` + `SameSite=Lax` (el token stateless para rutas mutantes se cablea con la 1ª de ellas — `json_login` no valida token), **sin** ensanchar CORS/Mercure.

## Design Notes

- **Modelado del 401 = re-throw (decidido; consulta externa + debate).** El handler custom hace
  `throw $exception;` (la `AuthenticationException` de Symfony) → `kernel.exception` → `ExceptionResponder`
  (prio 16, > firewall) → arm 401 existente. **No** excepción `Unauthenticated` de dominio concreta:
  espeja el trato **bridge-only del hermano `Forbidden`/403** (misma decisión ya tomada para autz), mantiene
  **una única vía de 401** (login-fail + default-deny de AF-1.3 por el mismo arm, coherente con el diseño
  constant-time) y no dispara el drift-gate `Domain/Exception/`→docs. Reabrir sólo con un productor de 401
  **fuera del firewall** (API-key/OAuth/app-layer). El default de `json_login` emitiría su `JsonResponse` en
  `kernel.request` (bypassa el contrato) → **no** construir `Response` ni `return null`.
- **Riesgo #1 + fallback.** Verificar en runtime (Behat) que el `throw` propaga a `kernel.exception` y que
  el listener prio-16 gana al del firewall (que debe respetar `hasResponse()`); la 401 debe ser
  `application/problem+json`, no el `{"error": …}` de Symfony. Si Symfony cortocircuitase antes, el handler
  invoca el **mismo** `ProblemDetailsFactory` + `ProblemDetailsResponder` (nunca un `JsonResponse` a mano).
- **Hashing split (deptrac).** `Identity.Application` no admite `Vendor.Symfony`; el comando (Infra) hashea con el adapter y pasa un `HashedPassword` a `CreateUser`. El adapter usa la misma factory/algoritmo que verifica el firewall.
- **Fixture Alice.** `User::register` exige VO `HashedPassword` (no string, a diferencia de `Bank::create`) → factory en `tests/DataFixtures/` que envuelve el hash precomputado.
- **`entry_point` diferido.** Sin `access_control` en AF-1.2, ninguna ruta lo dispara; añadir el mínimo
  (re-throw, misma vía que el failure-handler) sólo si el boot lo exige (ver *Ask First*). AF-1.3 lo formaliza.

## Verification

**Commands:**
- `make composer c='require symfony/security-bundle'` -- instala + arrastra security-http/security-csrf/password-hasher.
- `make sf c='debug:firewall main'` -- firewall con json_login + provider resueltos.
- `make php.stan PHP_SERVICE=messenger_worker` -- 0 errores por fichero (workaround segfault web worker).
- `make php.quality` + `make php.psalm.taint` -- EXIT 0 (deptrac/bounded-context/error-contract; dataflow credencial).
- `make php.unit` + `make php.behat` -- verde, incl. `ConstantTimeAuthBranchingContractTest` intacto y `login.feature`.
- `make sf c='identity:user:create alice@erpify.test s3cret --role AUDIT_READER'` + login vía `curl -k` -- alta OK; login OK devuelve cookie; password mala → 401 `type: unauthenticated`.

**Manual checks:**
- La 401 de login es `application/problem+json` (no el `{"error": …}` de Symfony) → confirma que el re-throw enruta por el pipeline.

## Completion Notes

**Gates (worktree stack) — TODOS verdes:** `php.stan` OK · `php.quality` **EXIT 0** (deptrac 0, bounded-context, error-contract, phpmd, cs-fixer, phpcs, rector) · `php.psalm.taint` **No errors** · `php.unit` **1483 OK** (6886 assertions; +~23 de Identity; `ConstantTimeAuthBranchingContractTest` intacto — no se tocó el factory) · **`php.behat` `login.feature` 4/4** (+ `bank/get` 5/5, suite completo restaurado). Verificado además por curl en vivo: login OK→204 + `PHPSESSID; secure; httponly; samesite=lax`; password mala / email desconocido / email en blanco → **idénticos** `401 application/problem+json type=unauthenticated title="Invalid credentials."`; no-JSON→400 (negociación de contenido, sin 204 falso).

> **Corrección de triage (review):** una nota previa atribuía el fallo de Behat a "el entorno del worktree". **Era falso.** La receta Flex de `composer require symfony/security-bundle` **reescribió `config/bundles.php`** y borró el registro condicional del bundle `FriendsOfBehat` → todo el suite Behat dejaba de bootear (features existentes incluidas). Restaurado el bloque (manteniendo `SecurityBundle`); Behat verde. Regresión auto-infligida, cazada por los 3 revisores adversariales.

**Hallazgo de seguridad (runtime) — enumeración de usuarios:** el consult asumió que `hide_user_not_found` normaliza todo, pero Symfony emite *"The presented password is invalid."* (password mala sobre email existente) vs *"Bad credentials."* (email desconocido) → distinguibles por título. **Fix:** el failure-handler re-lanza un `CustomUserMessageAuthenticationException` con mensaje **constante** ("Invalid credentials.") — sigue por el arm 401 (Opción A, sin tocar el factory constant-time), la causa real viaja como `previous` para el debug de dev. Cumple la AC de indistinguibilidad.

**CSRF (resuelto — Opción C, aprobada por Sergio tras consult + debate):** `json_login` **no** valida CSRF (`enable_csrf` es de `form_login`). En vez de dejar config inerte, AF-1.2 **no** cablea token stateless: el login queda protegido por same-origin + CORS no ampliada + requisito `application/json` + `SameSite=Lax`; el token stateless double-submit se introduce con la 1ª ruta autenticada mutante (*wire-on-consumer*, precedente #421). **ADR D1 enmendado** para reflejar la realidad del framework (config honesta > config inerte).

**Boy-scout (nombrados):** (1) `LoginController` **devuelve 204** directamente (json_login sin `success_handler` pasa la request al controller en éxito) → se eliminó `LoginSuccessHandler` (1 clase + 2 params sin usar menos). (2) `Email::toString()` + `User::email()` anotados `non-empty-string` (tipo honesto del invariante del VO; evita un guard muerto en `SecurityUser`). (3) `CreateUser` queda **genuinamente Symfony-free** (sin el `@throws ValidationFailedException` que los `BankCreator`/`BankAccountCreator` grandfathered arrastran en el baseline deptrac) — no se añade deuda nueva al ratchet.

**Follow-ups (no bloquean AF-1.2):** logout (fuera de scope — se eliminó el route scaffoldeado por Flex); handler de sesión compartido (Postgres/Redis) antes de escalar horizontal; login UI PWA (aguas abajo); `access_control` default-deny + 401 de rutas protegidas → **AF-1.3**; `#[IsGranted]` + swap `ActorContextFactory` → **E3/3.1**.

## File List

**API NEW (`api/src/Backoffice/Identity/`):** `Application/CreateUser.php`; `Infrastructure/Security/{SecurityUser,UserProvider,PasswordHasher,ProblemDetailsAuthenticationFailureHandler}.php`; `Infrastructure/Http/LoginController.php`; `Infrastructure/Cli/CreateUserCommand.php`.
**API NEW (config):** `config/packages/security.yaml`.
**API UPDATE:** `config/bundles.php` (SecurityBundle, vía receta Flex); `config/packages/framework.yaml` (session cookie hardening; CSRF inerte retirada por Opción C); `composer.json`/`composer.lock`/`symfony.lock`/`reference.php` (`symfony/security-bundle` + `symfony/password-hasher`); `src/Backoffice/Identity/Domain/{Email.php,Entity/User.php}` (anotaciones `non-empty-string`). **REMOVED:** `config/routes/security.yaml` (logout scaffold, fuera de scope).
**API NEW (tests):** `tests/Unit/Backoffice/Identity/Application/{InMemoryUserRepository,CreateUserTest}.php`; `tests/Unit/Backoffice/Identity/Infrastructure/Security/{SecurityUserTest,UserProviderTest,ProblemDetailsAuthenticationFailureHandlerTest}.php`; `tests/Functional/Backoffice/Identity/CreateUserCommandTest.php`; `tests/DataFixtures/UserFixtureFactory.php` + `tests/DataFixtures/Fixtures/User.yaml`; `features/backoffice/identity/login.feature`.
**DOCS UPDATE:** `docs/architecture-api.md`; `PRODUCTION_SECURITY_CHECKLIST.md`; `docs/rules/security.md`. **`docs/api-error-contract.md` sin cambio** (puente 401 + filas ya presentes).
**STORY/SPRINT:** este spec + `sprint-status.yaml` (`af-1-2` → `in-review`) + `epic-auth-foundation-context.md`.

## Review (step-04) — 3 revisores adversariales

**PATCH (aplicados):**
- **[HIGH] `bundles.php`** — la receta Flex borró el registro condicional de `FriendsOfBehat` → suite Behat caído. **Restaurado.** (raíz del falso "ambiental").
- **[MED] `CreateUserCommand`** — el docblock sobreafirmaba; el argumento `password` expone plaintext en `ps`/history → docblock honesto + recomienda el hidden prompt.
- **[test] `login.feature`** — assertion de cookie env-frágil (`PHPSESSID` es `MOCKSESSID` en test) → verifica `httponly`+`samesite=lax` (env-agnóstico).

**REJECT (runtime-disproven / convención):** non-JSON POST "204 falso" → en realidad **400** por negociación de contenido antes del controller (verificado; LoginController vuelve al 204 simple). Route `/backoffice` prefix (falso positivo). Missing-fields→400 (correcto). TOCTOU email dup (patrón del repo; índice DB = backstop). `supportsClass` rama muerta (idiom Symfony). Account-status/`cookie_secure` env/`catch Throwable` (fuera de scope/aceptable).

**DEFER (follow-ups, no bloquean):**
- **Enumeración por timing** — email desconocido salta la verificación de hash (rápido) vs password mala corre bcrypt (lento). El cuerpo es indistinguible (normalizado + testeado); el timing no. Mitigación = dummy-hash; Symfony no lo hace por defecto.
- **`login_throttling`** — sin rate-limit en el firewall (rate-limiter ya instalado); interactúa con tests → decisión de scope.
- **`User::roles()` lanza `ValueError`** ante un valor de rol desconocido en la columna JSON — ahora se ejecuta en **cada** request autenticada (mayor blast radius que en AF-1.1, donde ya estaba diferido). Sólo alcanzable con datos corruptos (el único writer es type-safe).
- Política de fuerza de password · `PasswordUpgraderInterface` (rehash transparente) · AC1 "request autenticada resuelve a SecurityUser con ROLE_*" e2e → **AF-1.3** (no hay ruta protegida aún).

**SIGN-OFF CSRF (Sergio — RESUELTO):** **Opción C aprobada** (consult externo + debate): se quita la config CSRF inerte; el login se protege por same-origin + CORS no ampliada + `application/json` + `SameSite=Lax`; el token stateless se cablea con la 1ª ruta mutante. ADR D1 + AC + `PRODUCTION_SECURITY_CHECKLIST.md` + `docs/rules/security.md` **enmendados**.

### Review Findings (code review independiente — 2026-07-03; 3 capas: Blind Hunter / Edge-Case Hunter / Acceptance Auditor)

**Veredicto:** implementación **fiel** a los AC y constraints congelados. Sin defectos CRÍTICA/ALTA reales. Verificado: `ProblemDetailsFactory` y `api-error-contract.md` intactos; deptrac registra las capas nuevas `Identity/{Application,Infrastructure}` con rulesets inward-only; `bundles.php` (bloque condicional FriendsOfBehat) restaurado; sin ensanchar CORS/Mercure; sin secretos en el diff; hashing confinado a Infrastructure. Los residuales de seguridad de mayor calado (timing, throttling) estaban en la lista DEFER de este spec y se **implementaron en esta PR** a petición de Sergio (ver bullets `[Fixed]`).

- [x] [Review][Fixed] Enumeración por **timing** — **cerrada en esta PR** (a petición de Sergio, 2026-07-03): `UserProvider::equaliseTiming()` ejecuta una verificación de hash de coste equivalente (dummy hash memoizado, misma factory que el firewall) en las ramas email-desconocido/malformado **antes** de lanzar `UserNotFoundException` → la latencia iguala a la de password-mala (1 verify en estado estable). Pinneado por `UserProviderTest::testAnUnknownEmailStillRunsAHashVerification…`. `api/src/Backoffice/Identity/Infrastructure/Security/UserProvider.php`
- [x] [Review][Fixed] **`login_throttling`** — **añadido en esta PR** (a petición de Sergio, 2026-07-03): firewall `main` con `max_attempts: 5` (por IP + email) sobre el `symfony/rate-limiter` ya instalado → brute-force / credential-stuffing con fricción. `api/config/packages/security.yaml`
- [ ] [Review][Patch] Docblock de `LoginController` describe mal el rechazo del POST no-JSON ("content negotiation before routing"); el mecanismo real es el default `_format: json` de la ruta → `json_login` atiende → 400 al fallar el `json_decode`. `api/src/Backoffice/Identity/Infrastructure/Http/LoginController.php`
- [ ] [Review][Patch] El escenario Behat 4 ("A blank email is a 401, never a 500") **no** asevera `title == "Invalid credentials."` (los escenarios 2 y 3 sí) → la indistinguibilidad del caso malformado queda sin pinnear. `api/features/backoffice/identity/login.feature`
- [ ] [Review][Patch] `InMemoryUserRepository::findById()` devuelve `$preset` para cualquier `$id` (fake infiel a la semántica del puerto) → riesgo de falso-verde futuro; `findByEmail` sí filtra. `api/tests/Unit/Backoffice/Identity/Application/InMemoryUserRepository.php`
- [ ] [Review][Patch] Higiene de registro: `deferred-work.md` conserva 2 bullets de la review de AF-1.1 que **esta PR resuelve** (`Validator::ensure` en el alta — ahora en `CreateUser`; patrón de traducción de errores del `UserProvider` — ahora try/catch `InvalidEmail`→`UserNotFoundException`) → podarlos.
- [x] [Review][Defer] `User::passwordHash()` re-hidrata `HashedPassword::fromHash('')` sin guard → `InvalidHashedPassword` (marker-less) = **500** en la ruta de credenciales si el hash estuviera vacío en BD. Inalcanzable hoy (columna NOT NULL + VO writer). Sibling del `User::roles()` ya registrado. `api/src/Backoffice/Identity/Domain/Entity/User.php` — deferred, persistido en `deferred-work.md`.
- [x] [Review][Defer] `User::roles()` lanza `ValueError` (→500) en **cada** request autenticada ante un rol persistido fuera del enum — **YA registrado** en `deferred-work.md` (review AF-1.1) + DEFER de este spec. No re-duplicado.
- [x] [Review][Defer] AC1 2ª cláusula ("request autenticada resuelve a `SecurityUser` con `ROLE_*`") no demostrada e2e (no hay ruta gated aún) — **YA en DEFER → AF-1.3**.
- Dismissed (4): (a) "204 falso a no-autenticado" — refutado: POST no-JSON → **400** por el default `_format: json`; (b) rama muerta `is_subclass_of` en `supportsClass` sobre clase `final` — idiom Symfony (ya rechazado en la review del dev); (c) checklist Execution lista `enable_csrf` que el código omite — reconciliado (Opción C; el código es el correcto); (d) `cookie_secure: auto` tras proxy TLS — idiom correcto para FrankenPHP TLS-directo (ya adjudicado aceptable en la review del dev; endurecer vía trusted-proxies es concern de despliegue).
