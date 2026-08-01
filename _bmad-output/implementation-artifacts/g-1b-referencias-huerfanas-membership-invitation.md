---
baseline_commit: 417b14ab
---

# Story 1.3 (G-1b): Cerrar las referencias huérfanas de `Membership` e `Invitation` — y el invariante ≥1 ADMIN que ocultan

Status: ready-for-dev

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **LA ENTREGA ES G-1a + G-1b JUNTAS, EN UNA SOLA PR.** Esta historia es la segunda mitad. El gate que instala
> G-1a aterriza en **rojo** por `Membership::$userId` e `Invitation::$invitedUserId`, y sale **verde aquí, porque
> la cadena de erasure las ejecuta** — no porque se declare un dueño. El rojo intermedio es la evidencia del
> segundo AC de esta historia y **tiene que quedar visible en la secuencia de commits**: primero el mecanismo de
> G-1a, después esta historia. **No colapses ambos pasos en un commit único.** `main` nunca queda roto.

> **UNA DECISIÓN ABIERTA, TOMADA Y REGISTRADA** (Sergio, 2026-08-01): el tercer AC del corte partía de una premisa
> medida como **falsa**. Ver *Decisión registrada · D1*. **Léela entera antes de tocar código** — el AC que el
> corte escribió es insatisfacible tal cual, y el reencuadre cambia qué hay que construir.

## Story

Como **sujeto de datos borrado**,
quiero que mi id desaparezca también de la membresía y de la invitación que me trajeron a la organización,
para que el borrado sea completo y no deje una identidad fantasma con permisos de administrador.

**Eje que instala:** las dos referencias adquieren dueño y la cadena de erasure las **ejecuta**.
**Invariantes que consume:** SI-21/NFR1 (vía G-1a), SI-19/NFR6, NFR7.
**Invariantes que establece:** ninguno nuevo — **previene** que una consecuencia de seguridad latente se vuelva
viva (D1).
**Dependencias:** Story 1.2 (G-1a), en la misma PR. Ninguna posterior.

## Estado medido (`main` @ `417b14ab`)

### La cadena de erasure real: 6 eslabones y 2 guardas previas, no 4 pasos

El corte la nombra `eraseIdentitySubject → auditActorAnonymiser → auditResourceAnonymiser → purgeUserSessions`.
El **orden relativo de esos cuatro es correcto**, pero la secuencia medida en
[`FulfilIdentityErasure.php`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) tiene seis
eslabones y dos guardas que el corte omite:

| # | Línea | Llamada |
|---|-------|---------|
| pre-1 | `:95` | `Uuid::ensure($subjectId)` |
| pre-2 | `:96` → `:142` | `refuseSelfErasure()` — usa `\strcasecmp`, con prohibición explícita del `===` en `:144-145` |
| 1 | `:100-102` | `holdsAdministratorRole()` → `AdministratorErasureRequiresDemotion` (409) |
| 2 | `:104` | `eraseIdentitySubject->execute()` |
| 3 | `:105` | `auditActorAnonymiser->anonymise()` |
| 4 | `:108-111` | `auditResourceAnonymiser->anonymise(AuditResource::of('User', …), $pseudonym)` |
| 5 | `:112` | `purgeUserSessions->purge()` |
| 6 | `:122-133` | `auditLogger->log('GDPR_ERASURE_EXECUTED', …)` — **condicionado a `$result->erasedAnything()`** |

**Dónde entran los dos eslabones nuevos: entre 5 y 6.** Después del 6 no sirve: los conteos se ensamblan en
`:114-120` y la puerta `erasedAnything()` de `:122` no los vería.

La transacción la abre el **puerto** `TransactionManager`
([`Shared/Persistence/Application/TransactionManager.php:13-22`](../../api/src/Shared/Persistence/Application/TransactionManager.php)),
en `FulfilIdentityErasure.php:98`; el adaptador delega en `wrapInTransaction`
([`DoctrineTransactionManager.php:36`](../../api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php)).
No hay `EntityManager` en `Application/`.

**Dos comentarios de esa clase son vinculantes para esta historia.** El de `:123-126` prohíbe ids en el metadata
del auto-audit —*«Counts only. Recording the subject id beside its anonymisation pseudonym would be a reversible
crosswalk»*— porque esa fila comparte `correlation_id` con `GDPR_SUBJECT_ERASED`. Los conteos nuevos son
**counts, nunca ids**. El de `:55-60` enumera la cadena para justificar el `SuppressWarnings("PHPMD.CouplingBetweenObjects")`
de `:62`: **hay que extenderlo**, o el docblock miente mientras el acoplamiento crece.

### Las dos referencias huérfanas, medidas columna a columna

|                        | `membership.user_id`                                  | `iam_invitation.invited_user_id`                        |
|------------------------|-------------------------------------------------------|---------------------------------------------------------|
| Propiedad              | [`Membership.php:30-31`](../../api/src/Organization/Membership/Domain/Entity/Membership.php) | [`Invitation.php:49-50`](../../api/src/Iam/Invitation/Domain/Entity/Invitation.php) |
| Tipo SQL               | `UUID NOT NULL`                                       | `UUID NOT NULL`                                          |
| UNIQUE                 | **sí** (`UNIQ_86FFD285A76ED395`)                      | no — N filas por usuario                                 |
| Índice                 | sí (el propio unique)                                 | **sí** — `idx_iam_invitation_invited_user_id`            |
| FK                     | **ninguna**                                           | **ninguna**                                              |
| DDL                    | [`Version20260707141602.php:21-22`](../../api/migrations/2026/Version20260707141602.php) | [`Version20260713141511.php:24-25`](../../api/migrations/2026/Version20260713141511.php) |
| ¿Anulable en dominio?  | **no** — sin setter, sin mutador                      | **no** — sin setter; `accept()` solo lo relee            |
| `#[PersonalData]`      | no                                                    | no                                                       |

**El índice de `invited_user_id` ya existe** (también como metadato ORM en `Invitation.php:43`). El borrado
dirigido por esa columna es index scan; no hace falta migración de índice, y **el corte no lo registra**.

**Ninguna de las dos entidades permite anular la referencia**: `NOT NULL` en BD, tipo PHP no-nullable, cero
mutadores. **Hard delete de la fila es la única vía sin migración.** Anonimizar exigiría mutador de dominio +
mapping nullable + `ALTER … DROP NOT NULL`: tres cambios para un dato que no es más que un puntero.

Sin FK no hay cascada: hoy `EraseIdentitySubject.php:48` hard-borra la fila de `identity_user` y **las dos filas
sobreviven apuntando a un id muerto**. `grep -rin "membership" api/features` → **cero**. El canario de presupuesto
de queries de [`erase.feature:44`](../../api/features/backoffice/users/erase.feature) (15 round trips, itemizados
en `:38-43`) fija la omisión.

### La asimetría de los puertos — la parte sin medir de la historia

- [`MembershipRepository`](../../api/src/Organization/Membership/Domain/Repository/MembershipRepository.php)
  (`:19-28`): `save`, `remove`, `findByUserId(string): ?Membership`, `findByOrganizationId`. **No hay
  `deleteAllForUser`.**
- [`InvitationRepository`](../../api/src/Iam/Invitation/Domain/Repository/InvitationRepository.php) (`:19-29`):
  `save`, `findById`, `findByIdForUpdate`. **No hay ninguna forma de alcanzar filas por `invitedUserId`, ni
  borrado de ningún tipo.**

Implementadores totales de ambos puertos: **4 ficheros** (dos adaptadores Doctrine, dos dobles in-memory). Añadir
un método rompe exactamente esos cuatro y nada más.

**`MembershipRepository::remove()` tiene cero llamadores de producción** — solo el adaptador, el doble y
`DoctrineMembershipRepositoryTest`.

### El precedente exacto que hay que copiar: `PurgeUserSessions`

Es el único eslabón cross-context de la cadena, y es del mismo problema:
[`PurgeUserSessions.php:18-30`](../../api/src/Iam/Session/Application/PurgeUserSessions.php) —
`final readonly class`, **un** puerto del propio contexto, `Uuid::ensure()` y una llamada dirigida; devuelve
`int`; **no abre transacción propia**, y su docblock `:15-16` dice por qué: *«runs directed through the
repository, so when the caller wraps it in a transaction (the erasure does) the DELETE joins that unit of work and
rolls back with it»*. No publica evento (`:13-15`).

El repositorio detrás hace **borrado masivo dirigido devolviendo el conteo**, no load-then-`remove()`:
[`DoctrineSessionRepository.php:105-116`](../../api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php)
(`->delete(Session::class, 's')->where(...)`, luego `\is_int($affected) ? $affected : 0`), con el contrato de
idempotencia declarado en el puerto (`SessionRepository.php:53-59`). Gemelo idéntico:
[`DoctrinePasswordResetTokenRepository.php:66-77`](../../api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrinePasswordResetTokenRepository.php).

Su coste de frontera fue **dos líneas**: `.bounded-context-allowlist:117` y `deptrac.yaml:481-482`.

**NO existe precedente de reacción cross-context por evento de dominio.** Todo `use Erpify\<Top>\<Ctx>\Domain\Event\…`
en `api/src` es del propio contexto. No inventes uno.

### Las fronteras: 4 líneas en 2 ficheros, y el baseline NO se toca

`Iam.Identity.Application` solo puede depender de `Iam.Identity.Domain` + `Shared.*`
([`deptrac.yaml:237-243`](../../api/tools/deptrac/deptrac.yaml)). `Organization.Membership.*` e
`Iam.Invitation.*` son ambos cross-context desde ahí (capas en `:93-98` y `:117-122`) — que `Iam.Session` sea
hermano bajo `Iam/` y aun así necesite entrada lo demuestra.

- [`api/.bounded-context-allowlist`](../../api/.bounded-context-allowlist): forma `<ruta relativa a api/> => <Fqcn>`,
  22 entradas activas, todas de esa forma. Cada grupo lleva su prosa de justificación.
- `deptrac.yaml` → `skip_violations` (`:446-482`): la clave es el **FQCN importador**, el valor una lista de FQCN
  importados. Sintaxis distinta de la del allowlist; `:444-445` obliga a mantener las dos listas en sync.
- [`deptrac.baseline.yaml`](../../api/tools/deptrac/deptrac.baseline.yaml): **no grandfathea nada** de `Iam/*` ni
  `Organization/*`, y su cabecera `:10-13` prohíbe expresamente meter seams ahí. **No lo toques.**

El gate `make php.lint.bounded-context` es un test PHPUnit
([`BoundedContextGateTest.php`](../../api/tests/Unit/Shared/Architecture/BoundedContextGateTest.php), 831 líneas),
`make/php-quality.mk:92-93`. Nivel 1 falla (`:102`, `$this->fail()` en `:125`); nivel 2 solo avisa por STDERR
(`:128-149`, FKs cross-context). Dos tests más fallan el build si una entrada del allowlist **nombra un fichero o
una clase que no existe** (`:278`, `:308`) — la entrada nueva tiene que apuntar a código real.

### Hechos medidos que el corte NO registra

**(A) `FulfilIdentityErasureResult` es un DTO de 5 campos** y `erasedAnything()` hace OR de los cinco
([`FulfilIdentityErasureResult.php:21-37`](../../api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php)).
Dos conteos nuevos → **7 campos**, y el array de metadata de `FulfilIdentityErasure.php:127-132` (hoy 4 claves)
crece. **Tres tests fijan la forma exacta del array** y se ponen rojos por diseño.

**(B) `InMemoryMembershipRepository::remove()` es un flag no-op**
([`InMemoryMembershipRepository.php:31-34`](../../api/tests/Unit/Organization/Membership/Application/InMemoryMembershipRepository.php)):
pone `$removeCalled = true` y **nunca saca la fila de `$saved`**. Un test de «la membresía ya no está» contra ese
doble **pasa en falso**. Esta es la trampa más cara de la historia.

**(C) El docblock de `EraseIdentitySubject.php:19-20` ya afirma que ningún `user_id` sobrevive al sujeto** — es
falso hoy para las dos referencias. Corregirlo es parte del diff, sin narrar el cambio.

**(D) El docblock de `MembershipRepository.php:13-15` afirma que `findByOrganizationId()` respalda el invariante
≥1 ADMIN. Es prosa aspiracional: ese método tiene cero llamadores de producción.** Único uso fuera del adaptador y
el doble: un helper de test (`BootstrapCommandsTest.php:139-147`). Escrito en su commit de nacimiento (#460) y
nunca revisado.

**(E) Los estados terminales de `Invitation` (`ACCEPTED`, `REVOKED`, `EXPIRED`) siguen llevando el id.** El
borrado tiene que alcanzarlos igual: el estado de la invitación no cambia que la columna sea un dato personal.

**(F) `Invitation` NO es `AuditedEntity` a propósito** (`Invitation.php:38-41`, para que `token_hash` no entre en
el trail). Borrarla no emite fila de auditoría propia — lo que se registre, lo registra el caso de uso.

## Decisión registrada (precondición normativa de la épica: SATISFECHA)

### D1 — El tercer AC del corte parte de una premisa falsa · **ELEGIDA: reencuadre a prevención**

**Lo que el corte escribió** (`epics-gdpr-hardening.md:541-544`): *«una membresía con rol ADMIN cuyo usuario ya no
es una identidad viva … deja de leer satisfecho el invariante ≥1 ADMIN activo — **hoy lo lee**»*.

**Medido: hoy NO lo lee.** El único lector del invariante es
[`ActiveAdministratorDirectory`](../../api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php),
y su adaptador consulta **`identity_user` y solo esa tabla**
([`DoctrineActiveAdministratorDirectory.php:46-53`](../../api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php)):

```sql
SELECT id FROM identity_user
WHERE status = :active AND roles::jsonb @> CAST(:adminRole AS jsonb)
ORDER BY id FOR UPDATE
```

Como `EraseIdentitySubject.php:48` hard-borra esa fila, la membresía huérfana es **invisible** al invariante: no
puede satisfacerlo. Tres pruebas independientes:

1. `demoteEveryAdministrator()`
   ([`AuthenticatesFunctionalRequests.php:71-92`](../../api/tests/Functional/AuthenticatesFunctionalRequests.php))
   vacía el pool tocando **solo `identity_user`**, y los 409 de `UserPatchStatusFunctionalTest.php:84` y
   `UserPatchRolesFunctionalTest.php:76` siguen verdes. Si membership fuera lector, `membership_admin` —que
   conserva `['ADMIN']`— los rescataría y esos tests fallarían.
2. `ActiveAdministratorDirectory.php:13-15` dice explícitamente que se re-apuntará a `Membership` *«only if
   tenancy ever moves the authoritative source»*.
3. `Membership` **no tiene columna de estado**: no puede expresar liveness. Un lector basado en ella necesitaría
   `JOIN identity_user`, que es justo la lectura cross-context que la arquitectura prohíbe
   (`MembershipOrganizationForeignKeySchemaListener.php:17-20`).

**Elegida: ① prevención.** G-1b **borra** la membresía, así la referencia huérfana nunca llega a existir, y el AC
falsable pasa a ser el borrado más un test que **fija quién es hoy el lector** y que nada lo rescata. En el PR se
declara que la consecuencia de seguridad es **latente, no viva** — el mismo movimiento que G-3a con su *«esta
historia no protege ningún dato hoy»*.

**Descartada ② construir ya el lector basado en membership.** Re-apuntar el invariante haría viva la
consecuencia, pero: cruza contexto en el hot path de auth, rompe los cinco casos de
`DoctrineActiveAdministratorDirectoryTest.php:63-133` (su `seed()` solo escribe `identity_user`) y los dos
Functional de arriba, y exige un `JOIN` que la arquitectura veta. Es otra historia, con su propia decisión de
frontera.

**Descartada ③ borrar el AC.** Perdería el test que fija el lector actual, que es precisamente lo que impide que
el reencuadre se olvide cuando alguien re-apunte la auth.

**Corolario que entra por regla del boy-scout:** el docblock de `MembershipRepository.php:13-15` (hecho (D))
promete un respaldo que no existe. Se corrige en esta PR y se nombra en el resumen.

## Acceptance Criteria

**AC1 — Las dos referencias no sobreviven al borrado (FR5, SI-19).**
**Given** un sujeto con membresía e invitación (incluidas invitaciones en estado terminal — hecho (E)),
**When** se ejecuta su borrado,
**Then** no queda **ninguna** fila de `membership` con ese `user_id` ni de `iam_invitation` con ese
`invited_user_id`.
*Testigo de grado aceptación, copiando la forma del tercer testigo de la cadena*
(`erase.feature:46-59`): sembrar ambas filas por SQL, `DELETE`, y comprobar por SQL directo sobre las columnas
huérfanas que salen **0 records**. Es la única evidencia que no depende de dobles.

**AC2 — El gate de G-1a pasa a verde PORQUE la cadena las ejecuta (SI-23).**
**Given** el gate de la Story 1.2 en rojo por `Membership::$userId` e `Invitation::$invitedUserId`,
**When** se cierra esta historia,
**Then** `make php.lint.person-reference` sale **verde**, y sale verde **por el check de cableado**: la línea
`person :: <ruta>` de cada referencia nombra un fichero que **ejecuta** su borrado, no uno que se limite a
existir.
*Declarar un dueño falso o ablandar el gate satisface la letra y falsea el eje — es exactamente SI-23.*

**AC3 — Un ADMIN huérfano no puede rescatar el invariante, y consta quién lo lee (D1).**
**Given** el borrado de un sujeto que era ADMIN en `membership`,
**When** termina el borrado,
**Then** no queda fila de `membership` con su id — **la referencia huérfana no llega a existir**.
**Y Given** el invariante «≥1 ADMIN activo»,
**When** se inspecciona su lector,
**Then** un test fija que hoy lo resuelve `identity_user` y **que una membresía ADMIN cuyo usuario no es identidad
viva no lo satisface**. `ChangeUserStatusTest.php:100`
(`testAPhantomAdminMembershipDoesNotRescueTheLastActiveAdministrator`) ya codifica esa semántica **contra el
doble**; lo que falta es fijarla contra el adaptador real.
*El PR declara que esta mitad es **prevención**: la consecuencia es latente hoy y se volvería viva el día que la
auth se re-apunte a membership (`CreateInitialAdministratorCommand.php:30-32` lo anuncia).*

**AC4 — Se cruza por identidad y puerto publicado, sin importar `Domain/` ajeno (NFR7).**
**Given** que el borrado lo orquesta un contexto distinto del que posee cada referencia,
**When** corren `make php.lint.bounded-context` y `make php.deptrac`,
**Then** siguen **verdes**, con exactamente **una entrada nueva por seam en cada uno de los dos ficheros** —
`api/.bounded-context-allowlist` y `skip_violations` de `deptrac.yaml`— y su prosa de justificación.
**Y** `deptrac.baseline.yaml` **no aparece en el diff**.

**AC5 — Los conteos entran en la evidencia sin abrir un crosswalk.**
**Given** los dos eslabones nuevos entre el 5 y el 6 de la cadena,
**When** se emite `GDPR_ERASURE_EXECUTED`,
**Then** su metadata gana **conteos**, nunca ids (`FulfilIdentityErasure.php:123-126`), y `erasedAnything()` los
tiene en cuenta — un borrado que solo alcance membresía/invitación **también** produce evidencia.
**Y** el `SuppressWarnings` de `:62` y la enumeración de `:55-60` quedan coherentes con la cadena real.

**AC6 — Sin regresión, y el canario re-medido.**
`make php.quality`, `make php.unit`, `make php.behat`, cada uno desde **ejecución fresca con exit code impreso**.
El presupuesto de queries de `erase.feature:44` **se re-mide y se re-itemiza** (`:38-43`): su propio comentario
`:43` dice *«A shift means an added round trip — re-measure, don't just bump the number»*. Al terminar la entrega
**todo está verde**.

## Tasks / Subtasks

- [ ] **Tarea 1 — Puertos y adaptadores (la parte sin medir).**
      `MembershipRepository::deleteAllForUser(string $userId): int` e
      `InvitationRepository::deleteAllForInvitedUser(string $userId): int`, ambos declarados **idempotentes** en el
      docblock del puerto como `SessionRepository.php:53-59`. Adaptadores por DQL dirigido devolviendo el conteo,
      copiando `DoctrineSessionRepository.php:105-116`. **No** uses load-then-`remove()`: evita un round trip y
      esquiva la trampa del hecho (B).
      Actualiza los **dos dobles in-memory** — y **arregla el `remove()` no-op** de `InMemoryMembershipRepository.php:31-34`
      mientras estás en el fichero.
      Corrige el docblock rancio de `MembershipRepository.php:13-15` (D1, corolario).

- [ ] **Tarea 2 — Los dos casos de uso publicados, uno por contexto dueño.**
      `Erpify\Organization\Membership\Application\PurgeUserMembership` y
      `Erpify\Iam\Invitation\Application\PurgeUserInvitations`, calcados de `PurgeUserSessions.php:18-30`:
      `final readonly`, un solo puerto propio, `Uuid::ensure()`, una llamada dirigida, devuelve `int`,
      **sin transacción propia** y **sin evento**. Un test unitario cada uno con la forma de
      `PurgeUserSessionsTest.php:25-47`.

- [ ] **Tarea 3 — Enganchar la cadena entre el eslabón 5 y el 6.**
      Inyectar ambos casos de uso en `FulfilIdentityErasure`, llamarlos tras `purgeUserSessions` y **antes** del
      ensamblado de `:114-120`. Ampliar `FulfilIdentityErasureResult` a 7 campos y su `erasedAnything()`. Añadir
      los dos conteos al metadata de `:127-132`. Extender la enumeración del docblock `:55-60`. Actualizar la
      factoría `useCase()` de `FulfilIdentityErasureTest.php:328-348` (un solo sitio) y las tres aserciones de
      forma exacta del array.
      Corregir el docblock de `EraseIdentitySubject.php:19-20` (hecho (C)) — **sin narrar el cambio**.

- [ ] **Tarea 4 — Fronteras.** Una entrada por seam en `api/.bounded-context-allowlist` (forma
      `<ruta> => <Fqcn>`, con prosa) y el par correspondiente en `skip_violations` de `deptrac.yaml`
      (clave = FQCN importador). **Cuatro líneas, dos ficheros, cero en el baseline.**

- [ ] **Tarea 5 — El testigo de aceptación y el canario.** Escenario en `erase.feature` con la forma de
      `:46-59`: sembrar membresía + invitaciones (una terminal), `DELETE`, comprobar 0 records en ambas columnas.
      Reutiliza el vocabulario existente (`SqlQueryContext.php:59-60,120`) — `api/CLAUDE.md` prohíbe añadir un
      step casi-duplicado; busca antes con `make php.behat c='-dl'`. **Re-mide** el presupuesto de queries y
      re-itemiza `:38-43`.

- [ ] **Tarea 6 — El test del lector del invariante (D1/AC3).** Fijar contra el adaptador real que una membresía
      ADMIN cuyo usuario no es identidad viva no satisface el invariante. Nota: los escenarios Behat de 409
      (`status.feature:84`, `roles.feature:112`) apuntan a `…a66`, que es ADMIN en **ambas** tablas — **no
      discriminan** qué lector está cableado. Cualquier cobertura nueva tiene que sembrar la divergencia.

- [ ] **Tarea 7 — Cierre.** Los tres gates desde ejecución fresca con exit code impreso. Pase adversarial por
      contexto distinto del autor, **registrado**, declarando dónde quedó (`CLAUDE.md` → *Security review* →
      Process). Barrer el diff de IDs de historia y comentarios relativos al cambio.

## Dev Notes

### Reuse map — lo que ya existe y NO se reinventa

| Necesidad | Ya existe | No hagas |
|---|---|---|
| Caso de uso de purga cross-context | `PurgeUserSessions.php:18-30` | una clase con transacción propia |
| Borrado dirigido con conteo | `DoctrineSessionRepository.php:105-116`, `DoctrinePasswordResetTokenRepository.php:66-77` | `findBy` + `remove()` en bucle |
| Contrato de idempotencia | `SessionRepository.php:53-59` | inventar redacción nueva |
| Registro de seam | `.bounded-context-allowlist:117` + `deptrac.yaml:481-482` | tocar el baseline |
| Testigo por SQL directo | `erase.feature:46-59` | un step Behat nuevo |
| Doble de repositorio | `InMemorySessionRepository` (reutilizado cross-namespace en `FulfilIdentityErasureTest.php:338-343`) | mockear el caso de uso ajeno |

### Anti-patrones concretos que esta historia invita a cometer

1. **Asertar «la membresía ya no está» contra `InMemoryMembershipRepository`** — hecho (B): el doble miente.
   Arréglalo o asierta por conteo devuelto y por SQL en el testigo de aceptación.
2. **Meter un id en el metadata del auto-audit** — `FulfilIdentityErasure.php:123-126`. Solo conteos.
3. **Colgar los eslabones nuevos después del auto-audit** — `erasedAnything()` no los vería.
4. **Reaccionar por evento de dominio cross-context** — no hay precedente en el árbol; el gate no lo pillaría, y
   además rompe la atomicidad que el docblock de `:29-35` promete (*«A failure in any link rolls everything
   back»*).
5. **Anonimizar en vez de borrar** — exige mutador + mapping nullable + migración, para un puntero sin más
   contenido.
6. **Subir el número del canario de queries sin re-medir** — lo prohíbe su propio comentario.
7. **Dejar `Story 1.3` / `G-1b` / `FR5` en comentarios de código** — scaffolding, se barre antes del commit final.

### Arquitectura y fronteras

Cruce por **identidad y caso de uso publicado**, en la dirección `Iam → Organization` que el allowlist ya
anticipa por escrito (`:71-75`: *«The same recurring Iam → Organization direction invitation will reuse»*), y en
la dirección `Iam.Identity → Iam.Invitation`, que pese a ser hermana bajo `Iam/` necesita entrada igual.

### Testing

Convención confirmada: `api/tests/{Unit,Functional}/…` espeja `api/src/…` fichero a fichero. Los dobles viven al
lado de sus tests, no en un `Double/` por contexto, y se reutilizan cross-namespace. No hay contexto Behat de
erasure: `erase.feature` está escrito íntegramente con el vocabulario genérico.

### Seguridad (checklist de `CLAUDE.md` aplicada a este diff)

- **Inyección** — DQL parametrizado en ambos adaptadores; el único input es un UUID ya validado por
  `Uuid::ensure()` en el borde.
- **AuthZ** — sin superficie HTTP nueva: los eslabones cuelgan de la cadena ya guardada por
  `holdsAdministratorRole` y por el voter del controlador existente.
- **Migraciones y datos** — **ninguna migración**: hard delete sobre columnas existentes. Hard delete es el
  default de la casa y aquí es además lo que exige el borrado GDPR.
- **Eventos / Messenger** — no se publica nada (el precedente `PurgeUserSessions` tampoco), así que no se abre
  ninguna fuga de las que persigue G-4a/G-5.
- **Secretos** — `Invitation.token_hash` es un digest de credencial: la fila se **borra**, no se loguea ni se
  audita (hecho (F)).
- **Pase adversarial** — obligatorio y registrado (definición de hecho de la épica). Que no encuentre nada
  también cuenta: se registra y se dice.

### Inteligencia de las historias anteriores

**De G-1a (misma PR):** las tres decisiones D1/D2/D3 están tomadas; el gate aterriza en rojo **a propósito** por
estas dos referencias. Su hallazgo adversarial A-17 —marcar `ready-for-dev` con decisiones abiertas— es la razón
de que el D1 de esta historia se cerrara antes de escribir código.

**De G-4a (#613, `0d2d45d2`):** el patrón vigente de registro declarativo + gate obligatorio, y la costumbre de
declarar los puntos ciegos en la cabecera para que un build verde no se lea como prueba de más de lo que cubre.

**Del ancestro directo de la cadena (#558, `080faa16`, *erase the subject where the trail names them*):** añadió
el eje de recurso con la misma estructura —puerto + adaptador DBAL + registro + gate + escenario testigo—. Es el
molde de esta historia a escala.

### Git intelligence

`HEAD` = `417b14ab` (artefacto de contexto de G-1a). La épica se cortó en `f4dbe4d1` (#609). Commits recientes
tocando `api/src/Iam/`: `0d2d45d2` (#613, G-4a) y `080faa16` (#558, eje de recurso). Rama de trabajo:
`feat/shared-person-reference-erasure-axis-nwhr`, worktree
`.claude/worktrees/shared-person-reference-erasure-axis-nwhr`.

## References

- Corte de épica: `_bmad-output/planning-artifacts/epics-gdpr-hardening.md:509-548` (Story 1.3), `:97` (FR4/FR5),
  `:241-243` (orden safe-first).
- Addendum: `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` (SI-21…SI-23).
- Artefacto hermano: `_bmad-output/implementation-artifacts/g-1a-eje-declaracion-atributo-registro-verificado-gate.md`.
- Reglas: `docs/rules/database.md` (aislamiento de contextos, hard delete), `docs/rules/security.md`,
  `docs/adr/audit-activity-log.md` (D4), `docs/adr/regulatory-audit-trail.md` (D15).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
