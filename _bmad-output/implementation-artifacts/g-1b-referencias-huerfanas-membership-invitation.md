---
baseline_commit: 417b14ab
---

# Story 1.3 (G-1b): Cerrar las referencias huérfanas de `Membership` e `Invitation` — y el invariante ≥1 ADMIN que ocultan

Status: done

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
de `:62`: **hay que extenderlo**, o el docblock miente mientras el acoplamiento crece. *Es obligación de revisión,
no gate:* la regla está suprimida entera sobre esta clase, `phpmd.xml:69` incluye `design.xml` sin override (por
defecto `minimum: 13`) y `ExcessiveParameterList` está en `minimum="12"` (`phpmd.xml:53-58`), así que 10
parámetros promovidos no rompen nada. Ningún comando lo detecta si se omite — por eso se escribe aquí.

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

Sin FK no hay cascada: hoy `EraseIdentitySubject.php:49` hard-borra la fila de `identity_user` y **las dos filas
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
crece. **Una** aserción fija la forma exacta de ese array —`FulfilIdentityErasureTest.php:101-106`— y se pone roja
por diseño. Las otras dos coincidencias de `assertSame` sobre metadata son **arrays distintos** y no se tocan:
`EraseIdentitySubjectTest.php:44` fija `GDPR_SUBJECT_ERASED` y
`EraseActorAuditTrailCommandTest.php:99-101` una fila de otro caso de uso.

**(A-bis) `FulfilIdentityErasure` se construye en DOS sitios, no en uno.** Además de la factoría del test de la
clase, `EraseIdentitySubjectCommandTest.php:113-136` lo arma posicionalmente. Al insertar los dos parámetros
nuevos en las posiciones 6 y 7, ese segundo sitio no da `ArgumentCountError` sino **`TypeError` antes**: su 6º
posicional pasa un `PurgeUserSessions` donde ya se espera un `PurgeUserMembership`.

**(A-ter) El informe de operador del CLI consume los conteos.** `EraseIdentitySubjectCommand.php:129-138` imprime
los cinco, y el aviso de `:121-124` enumera cuatro artefactos. Con siete conteos —y con el AC5 exigiendo que un
borrado que solo alcance membresía o invitación produzca evidencia— quedarse corto hace que el operador no vea
dos de siete resultados y que el copy de «nothing to erase» mienta sobre lo que se comprobó. El docblock del DTO
(`FulfilIdentityErasureResult.php:11`) dice explícitamente que los conteos *«feed the operator's report»*.

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

Como `EraseIdentitySubject.php:49` hard-borra esa fila, la membresía huérfana es **invisible** al invariante: no
puede satisfacerlo. Tres pruebas independientes:

1. **El censo de llamadores.** `keepsAnActiveAdminWithout` tiene exactamente tres consumidores —
   `ChangeUserStatus.php:85`, `ChangeUserRoles.php:128` y (con `holdsAdministratorRole`)
   `FulfilIdentityErasure.php:100`— y **un solo adaptador de producción**, cuyo `FROM` es `identity_user` y que
   no menciona `membership` en ninguna de sus dos consultas
   (`DoctrineActiveAdministratorDirectory.php:45-58`, `:73-86`). **No hay tercer lector.**
   *Lo que NO sirve como prueba, y hay que no repetirlo:* «los 409 de `UserPatchStatusFunctionalTest.php:84` y
   `UserPatchRolesFunctionalTest.php:76` siguen verdes tras `demoteEveryAdministrator()`». Esos tests **no cargan
   fixtures Alice** (`AuthenticatesFunctionalRequests.php:25`) y siembran su administrador sin fila de membresía
   (`:152-156`), así que `membership_admin` no está en esa BD y su verde es compatible con los dos mundos.
2. `ActiveAdministratorDirectory.php:13-15` dice explícitamente que se re-apuntará a `Membership` *«only if
   tenancy ever moves the authoritative source»*.
3. `Membership` **no tiene columna de estado**: no puede expresar liveness. Un lector basado en ella necesitaría
   `JOIN identity_user`, que es la lectura cross-context que `deptrac.yaml:237-243` y
   `api/.bounded-context-allowlist` gobiernan. (`MembershipOrganizationForeignKeySchemaListener.php:17-20`
   **no** sirve de cita aquí: justifica la ausencia de FK física, no prohíbe lecturas.)

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
**Given** el gate de la Story 1.2 en rojo porque esas dos propiedades **no tienen línea** en
`api/.person-reference-policy` (rojo de completitud),
**When** se cierra esta historia,
**Then** `make php.lint.person-reference` sale **verde**, y sale verde **por el check de cableado**: la línea
`person :: <ruta>` de cada referencia nombra un fichero que **ejecuta** su borrado, no uno que se limite a
existir.
*Declarar un dueño falso o ablandar el gate satisface la letra y falsea el eje — es exactamente SI-23.*

**AC3 — Un ADMIN huérfano no puede rescatar el invariante, y consta quién lo lee (D1).**
**Given** el invariante «≥1 ADMIN activo»,
**When** se inspecciona su lector,
**Then** un test fija que hoy lo resuelve `identity_user` y **que una membresía ADMIN cuyo usuario no es identidad
viva no lo satisface**. `ChangeUserStatusTest.php:100`
(`testAPhantomAdminMembershipDoesNotRescueTheLastActiveAdministrator`) ya codifica esa semántica **contra el
doble**; lo que falta es fijarla contra el adaptador real.
*El PR declara que esta mitad es **prevención**: la consecuencia es latente hoy y se volvería viva el día que la
auth se re-apunte a membership (`CreateInitialAdministratorCommand.php:31-33` lo anuncia).*
*Nómbralo por lo que es —un test de caracterización— y di que su único rojo posible es un futuro re-apuntado de
`DoctrineActiveAdministratorDirectory`: hoy su SQL no menciona `membership`, así que ningún estado sembrado lo
pone en rojo. Es legítimo, pero no es evidencia que produzca esta historia.*

**AC4 — Se cruza por identidad y puerto publicado, sin importar `Domain/` ajeno (NFR7).**
**Given** que el borrado lo orquesta un contexto distinto del que posee cada referencia,
**When** corren `make php.lint.bounded-context` y `make php.deptrac`,
**Then** siguen **verdes**, con **dos líneas nuevas en `api/.bounded-context-allowlist`** (una por seam, con su
prosa) y **dos líneas de valor bajo la clave importadora `Erpify\Iam\Identity\Application\FulfilIdentityErasure`
de `skip_violations`, que YA EXISTE** (`deptrac.yaml:481`) — no es una entrada nueva.
**Y** `deptrac.baseline.yaml` **no aparece en el diff**.
*Ojo con lo que este AC NO detecta:* la sincronía entre las dos listas la enforcea `DeptracSeamSyncGateTest`
(`:23-24`, `:38-56`), que corre bajo **`make php.unit`** — ni bajo `php.lint.bounded-context` (filtra a
`BoundedContextGateTest`, `make/php-quality.mk:92-93`) ni bajo `php.deptrac`. La deriva la caza AC6, no este.

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
      los dos conteos al metadata de `:127-132`. Extender la enumeración del docblock `:55-60`.
      **Los DOS sitios de construcción** (hecho (A-bis)): la factoría `useCase()` de `FulfilIdentityErasureTest.php`
      y `EraseIdentitySubjectCommandTest.php:113-136`. Olvidar el segundo es `TypeError`, no un fallo de aserción.
      **El informe de operador** (hecho (A-ter)): los dos conteos nuevos en el `success()` de
      `EraseIdentitySubjectCommand.php:129-138` y el aviso de `:121-124` enumerando los seis artefactos.
      Una sola aserción de forma exacta del metadata se pone roja: `FulfilIdentityErasureTest.php:101-106`.
      Corregir el docblock de `EraseIdentitySubject.php:19-20` (hecho (C)) — **sin narrar el cambio**.

- [ ] **Tarea 3-bis — Declarar las dos referencias en el eje que instala G-1a.** Sin esto el gate sigue rojo por
      **completitud** y el AC2 no cierra: añadir la línea de cada propiedad a `api/.person-reference-policy`
      (`… => person :: src/<contexto>/Application/PurgeUser*.php`; `person` a secas no es ortografía válida) **y**
      el `#[PersonSubjectReference]` en la propiedad, como llevan las dos semillas
      (`PasswordResetToken.php:41`, `Session.php:39`). Lo exige `CLAUDE.md:97`, no solo el gate.

- [ ] **Tarea 4 — Fronteras.** Una entrada por seam en `api/.bounded-context-allowlist` (forma
      `<ruta> => <Fqcn>`, con prosa) y el par correspondiente en `skip_violations` de `deptrac.yaml`
      (clave = FQCN importador). **Cuatro líneas, dos ficheros, cero en el baseline.**

- [ ] **Tarea 5 — El testigo de aceptación y el canario.** Escenario en `erase.feature` con la forma de
      `:46-59`: sembrar membresía + invitaciones (una terminal), `DELETE`, comprobar 0 records en ambas columnas.
      **Trampa medida:** Behat **sí** carga Alice, y el sujeto de `erase.feature:15-44` es `user_mallory`
      (`…4a5c`), que **ya tiene `membership_mallory`** (`Membership.yaml:10-15`) — un `INSERT` de membresía para
      él viola `UNIQ_86FFD285A76ED395`. Apunta a un sujeto sin membresía fixture (`user_trent` `…4a5d`,
      `user_victor` `…4a5e`, `user_edith` `…4a5f`) o siembra solo la invitación. Del lado invitación sí hace
      falta sembrar: la única fixture es `invitation_iris` (`Invitation.yaml:1-12`), sin escenario de borrado.
      Nota además que el escenario del canario **empezará a borrar una fila fixture real**, que es parte de lo
      que AC6 manda re-medir.
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

## Pase adversarial — CONTRATO. REGISTRADO (`CLAUDE.md` → *Security review* → **Process**)

Ejecutado en **contexto fresco por un lector distinto del autor** del artefacto, contra el árbol, con mandato de
romperlo. Queda registrado **aquí**; el pase sobre el *código* es la Tarea 7 y se declara en el PR. 15 hallazgos.

**Incorporados a este artefacto:**

- **A-1 (CRÍTICO) — ninguna tarea declaraba la referencia en el eje que instala G-1a**, así que seguir las tareas
  al pie dejaba `php.lint.person-reference` **rojo por completitud** y el AC2 sin cerrar. **Añadida la Tarea 3-bis**
  (línea en `api/.person-reference-policy` + `#[PersonSubjectReference]`), y corregido el *Given* del AC2: el rojo
  de partida es de completitud, no de cableado.
- **A-2 (ALTA) — «un solo sitio» era falso.** `EraseIdentitySubjectCommandTest.php:113-136` es el segundo, y falla
  con `TypeError`, no con `ArgumentCountError`. **Corregido en la Tarea 3** (hecho (A-bis)).
- **A-3 (ALTA) — «tres tests fijan la forma del array» era uno.** Las otras dos coincidencias son arrays de otros
  casos de uso. **Corregido en el hecho (A).**
- **A-4 (ALTA) — la primera prueba de D1 era insostenible.** Los Functional no cargan Alice, así que el verde de
  los 409 es compatible con los dos mundos. **Sustituida por el censo de llamadores.** *La conclusión de D1
  aguanta* — verificada de forma independiente: tres consumidores, un adaptador, `identity_user` y nada más, sin
  tercer lector.
- **A-5 (ALTA) — la Tarea 3 no tocaba el informe de operador**, que el docblock del DTO nombra como consumidor.
  **Añadido** (hecho (A-ter)).
- **A-6 (MEDIA) — el testigo Behat chocaba con las fixtures.** `user_mallory`, sujeto de `erase.feature`, ya tiene
  `membership_mallory`: sembrar la suya viola la UNIQUE. **Corregida la Tarea 5** con los sujetos libres.
- **A-7 (MEDIA) — el AC3 duplicaba el AC1 y vendía como evidencia un test que no puede fallar.** **Reetiquetado**
  como test de caracterización, con su único rojo posible declarado.
- **A-8 (MEDIA) — faltaba esta sección**, que el artefacto hermano sí lleva. **Es la que estás leyendo.**
- **A-10 (MEDIA) — el `SuppressWarnings` se presentaba como si un gate lo enforceara.** Está suprimido entero y
  ningún umbral se rompe. **Reetiquetado como obligación de revisión.**
- **A-11 (BAJA) — `EraseIdentitySubject.php:48` es el `if`; el borrado es `:49`.** **Corregidas las dos citas.**
- **A-12 (BAJA) — la cláusula de `CreateInitialAdministratorCommand` está en `:33`,** fuera del rango citado.
  **Corregido a `:31-33`.**
- **A-13 (BAJA) — «dos líneas» eran tres, y la clave importadora de deptrac ya existe.** **Corregido el AC4.**
- **A-14 (BAJA) — la sincronía allowlist↔deptrac está máquina-enforzada** por `DeptracSeamSyncGateTest`, bajo
  `php.unit` y no bajo los dos comandos que el AC4 nombra. **Declarado en el AC4.**
- **A-15 (BAJA) — el listener de FK no prohíbe lecturas**, solo justifica la ausencia de FK física. **Sustituida
  la cita** por deptrac + allowlist.

**Hallazgo RECHAZADO por medición (lo declaro para que el silencio no se lea como omisión):**

- **A-9 — «`deleteAllForInvitedUser` se desvía de `deleteAllForUser` sin argumento».** El argumento existe y está
  escrito en el docblock del puerto: la columna es `invited_user_id`, no `user_id`, y a diferencia de su hermana
  **puede borrar varias filas**. La desviación es argumentada, que es lo que la casa admite. El check de cableado
  la acepta además por prefijo (`PersonReferences.php:43`).

**Verificado y correcto** (para que la ausencia de hallazgo sea también un dato): los seis eslabones y las dos
guardas con sus líneas; que los nuevos van entre el 5 y el 6; el DTO de 5 campos; las dos columnas medidas una a
una (UNIQUE, índice, sin FK, no anulables); la asimetría de puertos y los cuatro implementadores; el hecho (B)
del `remove()` no-op; el hecho (D) del docblock aspiracional; el precedente `PurgeUserSessions` completo; la
ausencia de precedente de evento cross-context; las fronteras y el gate; el canario y los pasos Behat a reutilizar;
y el cableado DI (autowiring de `services.yaml:12-27` basta, sin `#[AsAlias]`, y ninguno de los cuatro nombres
propuestos colisiona).

## Pase adversarial — IMPLEMENTACIÓN. REGISTRADO (`CLAUDE.md` → *Security review* → **Process**)

**Dónde queda el registro: aquí, y reproducido en el cuerpo de la PR #616.** **Cuándo:** 2026-08-01, sobre el
código ya escrito y con los tres gates en verde — es decir, sobre lo que un build verde NO detecta.
**Quién:** tres lecturas hostiles independientes, en contexto fresco, por revisores **distintos del autor**,
con instrucción explícita de refutar, prohibición de aceptar como cierta ninguna afirmación del código, de los
docblocks o del cuerpo de la PR, y obligación de re-medir contra el árbol: un pase adversarial general, un
cazador de casos límite sobre el motor, y un auditor de aceptación contra los dos artefactos y la épica.
**Alcance declarado:** los tres commits de la entrega, **cubriendo las dos historias** — el eje de G-1a y,
por G-1b, la cadena ejecutando `Membership::$userId` e `Invitation::$invitedUserId`, el método de puerto
nuevo de `InvitationRepository`, y el invariante ≥1 ADMIN. **No cubre:** ejecución de Behat (prohibida a los
revisores por resetear la BD; la corrió el autor) ni PWA (cero cambios).

**Veredicto: el eje aguanta; la implementación tenía un agujero grave y varias afirmaciones falsas.**

- **GRAVE — el test del invariante era vacuo, y los tres lo habrían dejado pasar salvo por la medición.** Su
  `INSERT ... SELECT ... FROM organization LIMIT 1` insertaba **cero filas** (la BD de test se migra y nunca
  se provisiona), así que la membresía fantasma nunca existía y las dos aserciones ya eran ciertas sin ella.
  Verde, y sin probar nada — exactamente el anti-patrón 3 de G-1a («un AC que no puede fallar»), cometido por
  el autor tras haberlo leído. **Corregido:** el test crea su propia organización y **afirma que el INSERT
  afectó a 1 fila** antes de asertar.
- **ALTO — dos huecos estructurales en la derivación.** Una columna GUID `private` declarada en un padre
  abstracto (la forma de un `#[ORM\MappedSuperclass]`) y una asociación to-one con `#[ORM\JoinColumn]`
  —que persiste una clave foránea sin declarar `#[ORM\Column]` alguno— eran **invisibles** a la barrida.
  Ambas son la vía idiomática de Doctrine para mintear un `user_id`. **Corregidas ampliando la derivación**;
  las que no se cierran (varias clases por fichero, nombre ≠ ruta) están ahora **enumeradas en la cabecera**.
- **ALTO — el registro afirmaba en seis sitios que estas dos referencias eran «las únicas sin FK física».**
  Medido contra el esquema vivo: la base de datos tiene **dos** claves foráneas y **ninguna** apunta a
  `identity_user`, así que *ninguna* referencia a persona cascadea. La frase enseñaba justo la lección
  equivocada («FK ⇒ seguro»). **Corregida en los seis.**
- **MEDIO — la mitad del atributo era vacua**, demostrado empíricamente: borrar los cuatro
  `#[PersonSubjectReference]` del árbol dejaba el build verde, porque los dos checks iteran las propias
  declaraciones. **Corregido** con una pata de anti-vacuidad sobre `declaredOwners()`.
- **MEDIO — el prompt de consentimiento del CLI entendía mal el alcance del acto irreversible**: enumeraba
  identidad, tokens, trail y sesiones, no la membresía ni las invitaciones. Para una operación cuyo diseño
  entero descansa en un consentimiento informado y auditado, eso es un defecto. **Corregido**, junto al
  `setHelp()`, el docblock de la clase y el del controlador.
- **MEDIO — `make php.lint.*` salía verde con un `--filter` que no casaba con nada** (`failOnEmptyTestSuite`
  no estaba puesto). Afectaba a los cuatro gates de lint, no solo al nuevo. **Corregido en la config de
  PHPUnit**, y verificado: un filtro con typo ahora sale distinto de cero.
- **MEDIO — los dobles in-memory comparaban UUID con distinción de mayúsculas** mientras las columnas son
  `uuid` de Postgres, que no la hace. Divergencia en la dirección «el test es más estricto que producción»:
  ningún test podía fallar por ella. **Corregidos con `strcasecmp`** y fijado con un test que borra pasando
  el id en mayúsculas.
- **BAJO, corregidos:** el check de cableado rechazaba el estilo fluido del propio repo; una clave sin `::`
  degeneraba el regex; un FQCN sin namespace perdía la primera letra; un atributo repetido abortaba con un
  `Error` que no nombraba la propiedad; los adaptadores reportaban 0 en silencio si el driver devolvía el
  conteo como string; tres ramales del parser no estaban falsificados; dos tests prometían más de lo que
  afirmaban; y el trait `Identifiable` no avisaba de la trampa de anotarlo.

**Lo que los tres atacaron y NO pudieron romper** (se declara para que el silencio no se lea como omisión):
el parser del registro (duplicados, clasificación no reconocida, `person` sin dueño, fichero ausente, comentarios
en línea — todos fallan cerrado); las guardas de ruta (`..`, directorio, fuera de `src/`); el acotamiento de
los dos DELETE al sujeto (fuerza bruta sobre los 510 ficheros de `src` aceptó solo los semánticamente
correctos); su participación en la transacción y su idempotencia; el orden de los eslabones nuevos dentro de
la cadena; la aritmética del canario de queries; la anti-vacuidad de los fixtures; y la clasificación
`User::$id => person`.

**Hallazgo aceptado y NO cerrado aquí, declarado en la PR y abierto en el checklist:** el eje no tiene control
**detective** ni backfill. El gate prueba que el borrado está *escrito*, nunca que la fila *se fue*, y los
sujetos borrados antes de esta entrega conservan sus filas huérfanas. El hermano ya resuelve esto con
`identity:gdpr:reconcile-subject-references`; el equivalente para estas dos columnas es trabajo aparte.

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
