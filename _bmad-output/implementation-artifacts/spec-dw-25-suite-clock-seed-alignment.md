---
title: 'DW-25 — un solo reloj por test: el FixedClock inyectado coincide con el ambiental, y fuera los setters de timestamps'
type: 'refactor'
created: '2026-09-24'
status: 'done'
baseline_revision: 'e75b9cb850d6943d703572a03d5fd1c4f783cf90'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/rules/testing.md'
warnings: ['oversized']
deferred:
  - summary: >-
      El bullet «Reading the clock in a test» del CLAUDE.md raíz no menciona el guardarraíl de FixedClock ni la retirada de los setters de Timestamped.
    evidence: |-
      Sigue siendo correcto (SystemClock::set / pin), pero no avisa de la regla nueva que ahora impone el doble. Editar ficheros de contexto de agente se difiere por regla del workflow; docs/rules/testing.md ya la documenta.
    location: >-
      CLAUDE.md
    severity: low
  - summary: >-
      Cinco tests preexistentes siguen llamando a SystemClock::reset(), que docs/rules/testing.md prohíbe.
    evidence: |-
      BankRenameNoOpTest:40, BankTest:28, BankAccountWriteEventTest:33, StoredBankAccountFixture:32, RevokeCurrentSessionBestEffortTest:63. Inocuos hoy (el pin de Finished restaura), no introducidos por este cambio; basta sustituirlos por FreezeSystemClockExtension::pin() o borrar el tearDown.
    location: >-
      api/tests/Unit/Backoffice/Bank/Domain/Entity/BankTest.php:28
    severity: low
  - summary: >-
      Los tests que construyen sesiones ya caducadas con SessionMother::active(expiresAt: <pasado>) siguen produciendo filas con caducidad anterior a su createdAt, y el guardarraíl no lo ve.
    evidence: |-
      El guardarraíl compara relojes en la lectura; una caducidad explícita pasada a la Mother no pasa por ningún reloj. Ej.: PruneRetiredSessionsTest::activeSession('-91 days') sella createdAt=NOW y expiresAt=NOW-91d. Preexistente. Lo resolvería construir cada sesión bajo un reloj ambiental en expiresAt-TTL, o una aserción createdAt<=expiresAt en la Mother.
    location: >-
      api/tests/Unit/Iam/Session/Domain/Entity/Mother/SessionMother.php
    severity: medium
---

<intent-contract>

## Intent

**Problem:** Los agregados sellan `createdAt`/`updatedAt` desde el `SystemClock` ambiental (fijado por la suite en `2050-06-15T12:00:00+00:00`), mientras los casos de uso calculan caducidades desde el `Clock` inyectado; los tests que inyectan un `FixedClock` en otro instante producen agregados incoherentes (`StartSessionTest`: `createdAt = 2050`, `expiresAt = 2026`) en verde. El ledger estimaba 5 ficheros; **medido el 2026-09-24** (instrumentando `FixedClock::now()` sobre la suite completa, 3864 tests): **38 ficheros de test** leen un `FixedClock` inyectado que discrepa del ambiental. Además `Timestamped::setCreatedAt`/`setUpdatedAt` tienen 0 llamantes en `api/src` y en fixtures Alice, y 23 en `api/tests`: existen sólo para esquivar el sello ambiental y son la forma que el checklist de seguridad prohíbe (setters de campos de auditoría en la entidad).

**Approach:** Convertir la regla de `docs/rules/testing.md` («siembra del reloj que lee el sujeto») en un guardarraíl mecánico dentro del doble `FixedClock`: leer un `FixedClock` inyectado cuyo instante difiere del ambiental lanza. Alinear después cada test que el guardarraíl ponga en rojo, y borrar los dos setters de `Timestamped`, sembrando esos tests bajo el reloj ambiental en su lugar.

## Boundaries & Constraints

**Always:**
- El guardarraíl vive en `api/tests/Double/Clock/FixedClock.php::now()`: si este reloj no es el ambiental y `SystemClock::now()` ≠ su instante (comparación de instante, `!=` sobre `DateTimeImmutable`), lanza `\LogicException` con un mensaje que nombre ambos instantes (`format('c')`) y la regla (seed from the clock the subject reads; `SystemClock::set()` to align). Sin reflexión sobre privados de producción: la reentrada (el ambiental ES un `FixedClock`) se corta con un flag estático privado del doble, liberado en `finally`; para ello la clase deja de ser `readonly` y la propiedad promovida pasa a `private readonly`.
- Alineación por test: el idioma documentado `SystemClock::set($clock)` (o sembrar el `FixedClock` desde `SystemClock::now()` cuando el test no necesita un instante literal). Un test que avanza el tiempo instala el reloj posterior con `SystemClock::set()` **antes** de invocar al sujeto que lo lee; los agregados creados antes conservan su sello anterior. Restaurar, si hace falta dentro del test, con `FreezeSystemClockExtension::pin()`, nunca `SystemClock::reset()`.
- Los helpers compartidos (`BuildsLockoutRegistrar.php`, `RedeemsRecoverySecrets.php`, `BuildsFailureHandler.php`, `StoredBankAccountFixture.php`, `InMemorySessionRepository.php`) se corrigen en el helper cuando la divergencia nace ahí.
- Setters: se eliminan ambos métodos de `Timestamped`. Cada llamante se reescribe construyendo el agregado bajo `SystemClock::set(FixedClock::at(...))`/`new FixedClock(...)` con el instante deseado; si una fila necesita `updatedAt` ≠ `createdAt`, se construye en `createdAt` y se ejecuta un mutador real bajo el reloj de `updatedAt`. En `InMemorySessionRepository::flipToRevoked()` (espejo de un `UPDATE` DQL que no hidrata) `updatedAt` se escribe con `ReflectionProperty`, igual que sus dos columnas hermanas.
- Toda aserción existente conserva su intención; ninguna se debilita ni se borra para ponerse en verde.
- Comentarios: sin IDs de story/ledger ni prosa relativa al cambio (`CLAUDE.md` → Code comments). Documentar el guardarraíl en `docs/rules/testing.md` (sección del reloj: qué hace, qué no ve) y corregir el docblock de `FreezeSystemClockExtension` que afirma que el pin agranda la divergencia de relojes inyectados.

**Never:**
- No tocar código de producción salvo borrar los dos setters de `Timestamped.php` (ni `AggregateRoot`, ni `SystemClock`, ni `DomainEvent:38`, ni inyectar `Clock` en agregados — la opción de producción quedó cerrada en #975).
- No añadir opt-out, allowlist ni flag para desactivar el guardarraíl; no usar `SystemClock::reset()`.
- No editar `deferred-work.md`, `CLAUDE.md` ni ningún `sprint-status*.yaml`.
- No tocar Behat (`tests/Behat/`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ambiental es este reloj | `SystemClock::set($c)`; `$c->now()` | devuelve su instante | — |
| Inyectado coincide | ambiental y `$c` en el mismo instante (objetos distintos, zonas distintas incluidas) | devuelve su instante | — |
| Inyectado diverge | ambiental = pin 2050, `$c` = 2026 | — | `LogicException` con ambos instantes |
| Tiempo avanzado bien | agregado creado en T, luego `SystemClock::set($later)`, sujeto lee `$later` | verde; `createdAt` = T | — |

</intent-contract>

## Code Map

- `api/tests/Double/Clock/FixedClock.php` -- doble único del `Clock`; `final readonly class`, `now()` devuelve el instante. Aquí va el guardarraíl.
- `api/src/Shared/Clock/Domain/SystemClock.php` -- `set()/reset()/now()` estáticos; `now()` crea `NativeClock` perezosamente si no hay reloj. Solo lectura.
- `api/tests/Support/PHPUnit/FreezeSystemClockExtension.php:114` -- `SUITE_INSTANT`; `pin()` fija ambiental + Symfony en `PreparationStarted`/`Finished`; docblock l.~70-90 habla de la divergencia.
- `api/src/Shared/Kernel/Domain/Entity/Timestamped.php:24,36` -- los dos setters a borrar.
- `api/src/Shared/Kernel/Domain/Aggregate/AggregateRoot.php:28-29` -- sella `createdAt/updatedAt` con `SystemClock::now()`. Solo lectura.
- Ficheros medidos con divergencia (38, bajo `api/tests/`): `Functional/Iam/Session/DoctrineSessionRepositoryTest.php`; `Unit/Backoffice/Health/Infrastructure/Controller/{DatabaseHealthControllerTest,HealthControllerTest}.php`; `Unit/Frontoffice/Health/Infrastructure/Controller/HealthControllerTest.php`; `Unit/Iam/Identity/Application/{AdministratorSetLockOrderTest,ChangeMyPasswordTest,ChangeUserRolesTest,ChangeUserStatusTest,CompletePasswordResetNotificationTest,CompletePasswordResetTest,LoginAttemptRegistrarAuditTest,LoginAttemptRegistrarTest,MintRecoverySecretTest,NotifyLockedIdentitiesAuditTest,NotifyLockedIdentitiesTest,RequestPasswordResetTest,RevokeSessionsBestEffortTest}.php`; `Unit/Iam/Identity/Infrastructure/Cli/PruneExpiredPasswordResetTokensCommandTest.php`; `Unit/Iam/Identity/Infrastructure/Http/{RedeemRecoverySecretControllerTest,RequestPasswordResetControllerTest}.php`; `Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/PruneRetiredSessionsHandlerTest.php`; `Unit/Iam/Identity/Infrastructure/Security/{ProblemDetailsAuthenticationFailureHandlerTest,UserCheckerTest}.php`; `Unit/Iam/Invitation/Application/{AcceptInvitationTest,ResendInvitationTest,SendInvitationTest}.php`; `Unit/Iam/Invitation/Infrastructure/Cli/{CreateInvitationCommandTest,ResendInvitationCommandTest}.php`; `Unit/Iam/Invitation/Infrastructure/Http/CreateInvitationControllerTest.php`; `Unit/Iam/Session/Application/{PruneRetiredSessionsTest,RevokeAllSessionsTest,RevokeOtherSessionsTest,StartSessionTest}.php`; `Unit/Iam/Session/Infrastructure/Cli/PruneRetiredSessionsCommandTest.php`; `Unit/Iam/Session/Infrastructure/Controller/RevokeOtherSessionsControllerTest.php`; `Unit/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepositoryStoreUnavailableTest.php`; `Unit/Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogHandlerTest.php`; `Unit/Shared/Audit/Infrastructure/SealedAuditEntryFactoryTest.php`. La medición omitió los casos con ambiental `null` (tras `reset()`): el guardarraíl es la lista autoritativa.
- Llamantes de los setters (12 ficheros bajo `api/tests/`): `Functional/Backoffice/BankAccount/{DoctrineBankAccountCollectionSearchRepositoryTest,DoctrineBankAccountIbanLookupRepositoryTest}.php`, `Functional/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepositoryTest.php`, `Functional/Iam/Session/DoctrineSessionRepositoryTest.php`, `Functional/Shared/Persistence/{FilterApplierTemporalRangeTest,KeysetBaseQueryScopeTest,KeysetGoToDateSeamTest,KeysetOrderStabilityPropertyTest}.php`, `Unit/Backoffice/Bank/Infrastructure/Http/BankResourceMapperTest.php`, `Unit/Iam/Session/Application/{InMemorySessionRepository,InMemorySessionRepositoryContractTest,InMemorySessionRepositoryBulkRevocationContractTest}.php`.

## Tasks & Acceptance

**Execution:**
- `api/tests/Double/Clock/FixedClock.php` -- añadir el guardarraíl según *Always*; actualizar su docblock (qué impone y por qué) -- convierte la regla en rojo mecánico.
- `api/tests/Unit/Double/Clock/FixedClockTest.php` (nuevo, espejo de `tests/Double/Clock`, como `tests/Unit/Support/PHPUnit/FreezeSystemClockExtensionTest.php` lo es de `tests/Support/PHPUnit`) -- cubrir las cuatro filas de la matriz; si `make php.lint.gate-placement` lo clasifica como gate, añadir su línea `mirrored :: tests/Double/Clock` a `api/.artifact-gate-placement` (precedente: línea 215) -- prueba que el guardarraíl dispara y no dispara donde debe.
- Los 38 ficheros medidos y los que el guardarraíl añada -- alinear el reloj ambiental con el inyectado según *Always* -- ningún sujeto lee un reloj distinto del que sella sus agregados.
- `api/src/Shared/Kernel/Domain/Entity/Timestamped.php` -- borrar `setCreatedAt`/`setUpdatedAt` -- setters de auditoría sin llamante de producción.
- Los 12 llamantes de los setters -- sembrar bajo el reloj ambiental / mutador real / `ReflectionProperty` en el doble, según *Always*.
- `docs/rules/testing.md` y `api/tests/Support/PHPUnit/FreezeSystemClockExtension.php` -- documentar el guardarraíl y sus límites (no ve `new DateTimeImmutable()` desnudo, `DomainEvent`, relojes Symfony `MockClock`, Behat).

**Acceptance Criteria:**
- Given la suite PHPUnit completa, when se ejecuta `make php.unit`, then termina en verde sin tests nuevos saltados.
- Given el árbol, when se busca `setCreatedAt\|setUpdatedAt` en `api/`, then no hay ninguna coincidencia.
- Given `StartSessionTest`, when crea la sesión, then `expiresAt` es 7 días posterior a su `createdAt` (asertado en el test).
- Given un test que inyecta `new FixedClock(<instante ≠ SUITE_INSTANT>)` sin alinear el ambiental, when el sujeto lee el reloj, then el test falla con la `LogicException` del guardarraíl (demostrado en `FixedClockTest`).

## Spec Change Log

## Review Triage Log

### 2026-09-24 — Review pass
- verdicts: 20 findings — high 0, medium 2, low 8, false 10, maybe-false 0
- findings:
  - `[medium]` `[patch]` (Blind) El docblock de FreezeSystemClockExtension afirma «only disagree in a red, never in a green», falso para un reloj inyectado que no se lee — reformulado a «whenever the subject reads the injected clock» en el docblock y en testing.md.
  - `[low]` `[patch]` (Blind) La lista de puntos ciegos omite el reloj global de Symfony que pin() también fija — añadido en ambos sitios.
  - `[false]` `[reject]` (Blind) Restauración inconsistente del reloj entre helpers funcionales (flush con el ambiental aún movido) — ningún listener lee SystemClock en flush en esos tests y ninguna aserción depende de ello; si fuera real sería low.
  - `[low]` `[reject]` (Blind) set→build→restore duplicado sin try/finally; proponer helper — el pin de Finished ya restaura en la frontera del test; el helper añade abstracción para un fallo improbable.
  - `[low]` `[patch]` (Blind) FixedClockTest no cubre divergencia sub-segundo ni que el flag se libere tras un rechazo — añadidos `aDivergenceOfOneMicrosecondStillRefuses` y `aRefusalLeavesTheCheckArmedForTheNextRead`.
  - `[low]` `[patch]` (Blind) El caso de avance de tiempo lee sólo el reloj que ES el ambiental y no ejercita la comparación — ahora lee un FixedClock inyectado distinto al instante posterior.
  - `[false]` `[reject]` (Blind) assertSame por identidad sobre createdAt en InMemorySessionRepositoryContractTest — si el doble copiara el instante fallaría en rojo, no en verde.
  - `[low]` `[reject]` (Blind) UserCheckerTest::checker() mueve el ambiental como efecto lateral — documentado en su docblock; los tests son correctos hoy; reestructurar no compensa.
  - `[false]` `[reject]` (Blind) ReflectionProperty con nombre en string en InMemorySessionRepository — mismo idioma que las dos columnas hermanas, ya comentado como espejo del UPDATE.
  - `[low]` `[reject]` (Blind) Ningún gate impide que vuelvan los setters — añadir un gate es complejidad nueva para un riesgo improbable; review lo cubre.
  - `[low]` `[defer]` (Blind) CLAUDE.md raíz no actualizado — fichero de contexto de agente; diferido.
  - `[low]` `[patch]` (Blind) El mensaje de la excepción sólo nombra SystemClock::set() — ahora nombra ambos remedios.
  - `[false]` `[reject]` (Blind) setUp del trait BuildsLockoutRegistrar sobrescribible e #[Override] inconsistente — si una clase lo sobrescribe, el guardarraíl lanza (rojo), no pasa en verde; php.quality verde.
  - `[low]` `[patch]` (Blind) La retirada de `readonly` no se explica — añadida la frase (una clase readonly no admite el flag estático).
  - `[medium]` `[patch]` (Edge) PruneRetiredSessionsTest::revokedSession deja el ambiental en el instante de revocación y las sesiones activas posteriores se sellan a NOW-31d — restaurado el ambiental a NOW tras revoke().
  - `[false]` `[reject]` (Edge) setUp del trait reemplazado en silencio — no ocurre hoy (ninguna clase usuaria declara setUp) y, si ocurriera, el guardarraíl lanza.
  - `[medium]` `[patch]` (VerifGap) DoctrineSessionRetentionTest inyecta FixedClock(2026) sin alinear el ambiental y crea sesiones 2050/2026; conserva reset() — alineado en setUp, tearDown eliminado, saveRevokedSession restaura a NOW en vez de pin(). (Agrupado con el primer hallazgo por la misma causa: el guardarraíl sólo ve lecturas.)
  - `[low]` `[defer]` (VerifGap) Cinco reset() preexistentes contradicen la regla — no causados por este cambio; diferido.
  - `[false]` `[reject]` (Intent) El guardarraíl es un invariante proxy, no «expiry ≥ createdAt» — la intención pide aplicar la regla «seed from the same clock»; eso es exactamente lo que impone. El residuo real (caducidades explícitas en Mothers) queda diferido.
  - `[false]` `[reject]` (Intent) Sólo StartSessionTest afirma la propiedad; la retirada de setters toca producción; los ~5 ficheros no se identifican — la intención pide evaluar la retirada «as part of it» (0 llamantes en src); el spec documenta la medición de 38 ficheros que sustituye a la estimación de 5.

## Design Notes

El guardarraíl vive en el doble y no en una extensión de PHPUnit porque sólo el doble sabe cuándo se le **lee**; comprobar al construirlo daría falsos rojos en los tests que preparan dos relojes y avanzan el tiempo. El flag estático corta la reentrada cuando el ambiental es un `FixedClock`:

```php
if (!self::$consultingAmbient) {
    self::$consultingAmbient = true;
    try { $ambient = SystemClock::now(); } finally { self::$consultingAmbient = false; }
    if ($ambient != $this->now) { throw new LogicException(...); }
}
return $this->now;
```

## Verification

**Commands:**
- `make php.unit` -- expected: exit 0, `OK` (sólo los 3 skipped preexistentes).
- `make php.stan` -- expected: exit 0.
- `make php.quality` -- expected: exit 0.
- `git grep -n "setCreatedAt\|setUpdatedAt" -- api` -- expected: sin salida.

## Auto Run Result

Status: done

**Resumen.** El doble `FixedClock` impone ahora la regla «siembra del reloj que lee el sujeto»: leer un `FixedClock` inyectado cuyo instante difiere del `SystemClock` ambiental lanza `LogicException`. Con él en rojo se alinearon todos los tests divergentes (38 medidos, frente a los ~5 que estimaba el ledger), y se borraron `Timestamped::setCreatedAt`/`setUpdatedAt` (0 llamantes en `src` y en fixtures), sembrando sus 12 ficheros llamantes bajo un reloj ambiental congelado. `StartSessionTest` afirma ahora `expiresAt = createdAt + 7 días`.

**Ficheros.**
- `api/src/Shared/Kernel/Domain/Entity/Timestamped.php` — borrados los dos setters (único cambio de producción).
- `api/tests/Double/Clock/FixedClock.php` — guardarraíl en `now()` (flag estático de reentrada, comparación `U.u`, mensaje con ambos remedios).
- `api/tests/Unit/Double/Clock/FixedClockTest.php` — nuevo, 6 casos (matriz + sub-segundo + flag rearmado).
- 38 tests unitarios/funcionales y helpers (`BuildsLockoutRegistrar`, `BuildsFailureHandler`, `RedeemsRecoverySecrets`, `InMemorySessionRepository`) — reloj ambiental alineado con el inyectado; `reset()` retirado donde se tocó.
- 12 llamantes de los setters (Keyset*, FilterApplier*, repositorios Doctrine, BankResourceMapper, contratos InMemory) — filas construidas bajo un `FixedClock` ambiental; `updatedAt` distinto vía `rename()` real.
- `api/tests/Functional/Iam/Session/DoctrineSessionRetentionTest.php` — alineado (hallazgo de review).
- `docs/rules/testing.md`, `api/tests/Support/PHPUnit/FreezeSystemClockExtension.php` — regla, garantía exacta y puntos ciegos.

**Review.** 20 hallazgos: 7 parches aplicados (2 medium, 5 low), 3 diferidos (ver frontmatter `deferred`), 10 rechazados con su motivo en el Review Triage Log.

**Revisión de seguimiento recomendada:** false — parches: 0 high, 2 medium (≥2 medium ⇒ en principio `true`); el riesgo que nombraría (filas mal sembradas que el guardarraíl no ve) es exactamente el diferido de las caducidades explícitas en Mothers, que ya está registrado, y ambos parches medium se re-verificaron con la suite completa. Sin riesgo no verificado nombrable ⇒ false.

**Verificación.**
- Medición previa: `FixedClock::now()` instrumentado sobre la suite completa (3864 tests) → 161 lecturas divergentes en 38 ficheros.
- `make php.unit` → exit 0, 3870 tests, 21594 aserciones, 3 skipped preexistentes (tras los parches).
- `make php.quality` → exit 0 (tras los parches).
- `git grep -n "setCreatedAt\|setUpdatedAt" -- api` → sin salida.
- `make php.unit c='--filter FixedClockTest'` → 4/4 antes de los parches; 19 OK en el filtro de los ficheros parcheados.

**Riesgos residuales.** El guardarraíl sólo ve lecturas de `FixedClock`: un reloj inyectado nunca leído, el reloj global de Symfony, `new DateTimeImmutable()` desnudo, el `occurredOn` de `DomainEvent`, Behat y las caducidades explícitas pasadas a Mothers quedan fuera (documentado; el último, diferido). Los tests de Health ya no distinguen si el controlador lee el reloj inyectado o el ambiental.
