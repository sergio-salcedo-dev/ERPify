---
baseline_commit: 4de018ff
---
# Story 1.2 (U-1): `/me` deriva permisos — gateo de cliente `<Can>` vivo

Status: done

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **ADMIN**,
quiero **que `/me` me devuelva los permisos que mis roles me conceden, y que la consola refleje el vocabulario real del backend**,
para **que el gateo de cliente `<Can>` deje de denegar todo y la UI deje de ofrecer capacidades que no existen**.

## Contexto (leer antes de tocar código)

U-1 de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0 está **done**
(PR #501 backend + #502 PWA, `main @ 4de018ff`). U-1 es **prerrequisito de toda superficie de acción**: U-2 (invitar) y
U-3 (cambio de estado) dependen de que `<Can>` funcione.

**Tres hechos verificados en `main @ 4de018ff` que contradicen notas previas** — no re-derives:

1. **`/me` responde envelope `{data}`, no flat.** `JsonResponder.php:21` envuelve incondicionalmente. El fix de PR #488
   fue el contrario de lo que se recordaba: llevó la PWA de flat → envelope, y `ApiIdentityRepository.test.ts:95-106`
   **rechaza** un body flat.
2. **La API no devuelve `permissions: []` — no devuelve `permissions` en absoluto.** `MeResource` tiene tres campos:
   `id`, `email`, `roles`. El `permissions: []` está **hardcodeado en la PWA**, `ApiIdentityRepository.ts:60`.
3. **El rename del enum PWA nunca ocurrió.** `Permission.ts` sigue con `users.write`/`users.delete`, sin `users.erase`,
   y con `projects.*`/`invoices.*` huérfanos. **El drift SI-20 está vivo hoy.**

El hueco tiene **dos mitades que se necesitan**: derivar sin renombrar deja los `<Can>` preguntando por `users.write`
—que la API **jamás** emitirá— y no cambia nada observable; renombrar sin retirar deja al ADMIN un botón «New user» que
cae en un stub 501. Van juntas.

- **A · Catálogo + derivación (API):** `PermissionCatalog` (dato) + `PermissionResolver` (catálogo × policy) → `/me`
  emite `permissions`. **El puerto `AuthorizationPolicy` NO se toca.**
- **B · Tripwire de completitud (API):** test que refleja sobre **todo `#[IsGranted]`** y asserta que está en el
  catálogo → olvidar registrar un permiso es **fallo de build**, no un gate muerto en silencio.
- **C · Vocabulario real + superficie honesta (PWA):** enum alineado byte-a-byte; fuera los botones/rutas CRUD del mock;
  **la consola pasa a gatearse por `users.read`** (el único `<Can>` que sobrevive a U-1, y el que lo hace demostrable).

> **Entrega: UN PR.** A+B+C son una unidad demostrable — el e2e de AC9 necesita las tres.

### FR4/FR5/FR6 y el addendum se enmiendan en este PR (parte de la historia)

FR4 decía *«los botones de acción se hacen visibles al ADMIN»* — falso tras U-1, que los **retira** (son CRUD del mock
con stubs *no-soportado*; SI-18 declara que la identidad no es CRUD). FR5/FR6 asignaban a U-2/U-3 los renames del enum
que U-1 absorbe. **No se «reinterpretan» desde la historia: se corrigen en su artefacto**, porque un requisito debe
describir el estado que deja **su** historia, no el que dejarán las siguientes.

Enmiendas incluidas en este PR (`_bmad-output/planning-artifacts/`): **FR4** → lo que U-1 deja de verdad (set derivado
vía catálogo + consola gateada por `users.read` + vocabulario alineado + superficie muerta retirada, como prerrequisito
de U-2/U-3/U-5); **FR5/FR6** → cada una enuncia la visibilidad de **su** botón (`users.invite` / `users.changeStatus`),
sin cláusula de rename; **addendum** → fila U-1 reescrita (catálogo + tripwire + SI-20 + «historia propia, no se pliega
a U-2») y filas U-2/U-3 sin la cláusula de rename. Menciónalo en la descripción del PR.

## Acceptance Criteria

1. **AC1 · `/me` emite el set derivado.** `GET /api/v1/me` responde
   `{"data":{"id","email","roles":[…],"permissions":[…]}}` con los permisos que los roles del solicitante conceden vía
   `AuthorizationPolicy`. **Casos exactos** (simulados contra `permits()`, no los re-derives):

   | Roles | `permissions` |
   |---|---|
   | `['ADMIN']` | los **12** del catálogo (cláusula superusuario) |
   | `['VIEWER']` | **exactamente 2**: `bank.read`, `bankAccount.read` |
   | `['AUDIT_READER']` | **exactamente 1**: `auditTrail.read` — **AUDIT_READER no está en `TIER_VERBS`**, es un grant ortogonal, **no** un peldaño: no tiene «su tier» |
   | `['VIEWER','AUDIT_READER']` | **3**: los 2 de VIEWER + `auditTrail.read` |
   | `['AUDIT_READER','MANAGER']` (fixture `alice`) | **8**: `bank.{read,write,delete}`, `bankAccount.{read,write,delete,changeStatus}`, `auditTrail.read` — ojo: `bankAccount.changeStatus` entra por `EXPLICIT_GRANTS → MANAGER`, **no** por tier |
   | `[]` | vacío |

2. **AC2 · Set derivado, jamás almacenado.** No existe `User.permissions` ni columna equivalente (SI-18); se calcula por
   request desde `roles`. Ninguna migración. El campo **no** aparece en `UserListResource`/`UserDetailResource` (sus
   tripwires siguen verdes, incluido `users-real-api.spec.ts:59`).
3. **AC3 · El puerto sigue cerrado.** `AuthorizationPolicy` conserva **exactamente un método**.
   `AuthorizationCoreIsClosedForModificationTest` pasa **sin enmienda**.
4. **AC4 · El catálogo es dato, no mecanismo — y hay un test que lo prueba.** El const del catálogo es literal puro,
   verificado por un tripwire `token_get_all` (extiende el DataProvider de `StaticAuthorizationPolicyIsDataOnlyTest`
   —`:76-78`— al fichero nuevo, o clona su mecánica). Los tres consts de `StaticAuthorizationPolicy` no se tocan.
5. **AC5 · Tripwire de completitud (el crux de B).** Un test recorre `api/src`, extrae por reflexión **todo**
   `#[IsGranted]` (clase **y** método), y asserta que cada permiso está en el catálogo. Añadir
   `#[IsGranted('foo.read')]` sin registrarlo **rompe el build**, nombrando permiso y fichero.
6. **AC6 · Vocabulario byte-idéntico (SI-20).** El enum PWA declara **exactamente** `users.read`, `users.invite`,
   `users.changeStatus`, `users.erase`. Fuera `users.write`, `users.delete`, `projects.*`, `invoices.*`.
7. **AC7 · La sesión del cliente porta permisos reales.** `ApiIdentityRepository.me()` deja de hardcodear
   `permissions: []`; valida el campo en el guard y **descarta los permisos que el enum PWA no declara** (*Crux B*), sin
   lanzar.
8. **AC8 · Cero superficie muerta.** No queda ningún control gateado por un permiso que la API no emita, ni ninguna
   ruta/botón que caiga en los stubs 501. Las rutas `users/new` y `users/[id]/edit` **no existen**. Ningún texto de UI
   invita a una acción retirada.
9. **AC9 · `<Can>` demostrablemente vivo (e2e).** La consola (`users/page.tsx`) y el detalle (`users/[id]/page.tsx`) se
   gatean con `<Can permission={Permission.USERS_READ} fallback={<EmptyState heading="Access denied" …/>}>`. Un spec
   real-API con sesión **ADMIN** prueba que la lista se renderiza (gate atravesado con permisos reales, **sin**
   `DevSessionSwitcher`).
10. **AC10 · Coste cero en queries — y queda medido.** La derivación no añade **ninguna** query (catálogo const + policy
    en memoria). Hoy **no existe** budget de query sobre `/me`: **añádelo** (step
    `N request(s) got executed only for doctrine connection "default"`, `DoctrineContext.php:46-58`) al escenario de
    `session.feature:10-17` para que el coste quede fijado.

## Tasks / Subtasks

### A — Catálogo + derivación · `api/src/Iam/Identity/Infrastructure/Security/` (AC1–AC4, AC10)

- [x] `PermissionCatalog.php` **nuevo**. `final class`, const array **literal** con los 12 permisos (*El catálogo*).
      `all(): list<Permission>`. Docblock: por qué central y no por-módulo (*Crux A*), y que se muda con el plano RBAC.
- [x] `PermissionResolver.php` **nuevo**. `final readonly class`; inyecta `PermissionCatalog` + `AuthorizationPolicy`.
      `forRoles(array $bareRoles): list<string>` → itera el catálogo, filtra por `permits()`, devuelve `toString()`.
- [x] `MeResource.php` — cuarto parámetro `public array $permissions` con `@param list<string>`.
- [x] `MeResourceMapper.php` — inyecta `PermissionResolver`; **reutiliza** la lista bare que ya calcula
      `stripRolePrefix()` (`:34-42`, disponible en `:25`). No dupliques el pelado de `ROLE_`.
- [x] Tests: `PermissionCatalogTest` (los 12; todos `isWellFormed`), `PermissionResolverTest` (**los 6 casos de la tabla
      de AC1**, vía `#[DataProvider]`), y el tripwire de literalidad de AC4.
- [x] `MeResourceMapperTest` — `:24` (`new MeResourceMapper()`) **romperá** por la firma: extiéndelo con los permisos.
- [x] `session.feature` — asserta `permissions` de `alice` (**los 8 de AC1**) + el step de budget de AC10.

### B — Tripwire de completitud · `api/tests/Unit/…` (AC5)

- [x] `PermissionCatalogCoversEveryGatedRouteTest.php` **nuevo**. **No hand-rollees el walk**: usa
      `Erpify\Tests\Support\ApiSourceFiles` (`root()` + `phpFiles()`, ya usado por 9 gates). El patrón
      **walk → FQCN PSR-4 → `class_exists` → `ReflectionClass`** está resuelto en
      `MarkerStatusMapContractTest.php:290-318` — cópialo.
- [x] Extrae con **`$attribute->newInstance()->attribute`**, **no** `getArguments()[0]`. Motivo verificado:
      `IsGranted::$attribute` es `string|Expression|\Closure` (`vendor/symfony/security-http/Attribute/IsGranted.php`), y
      `getArguments()` devuelve `['attribute' => …]` (no `[0]`) si el call site usa **argumento nombrado**, y un
      **objeto** si usa `Expression`. Con `strict_types`, pasar eso a `Permission::isWellFormed(string)` (`:45`) lanza
      **`TypeError`** → el build peta con un error incomprensible. **Guarda con `is_string(...)` antes de filtrar.**
- [x] Filtra con `Permission::isWellFormed()` (descarta `ROLE_*`/`IS_AUTHENTICATED_*`/`PUBLIC_ACCESS` — sin separador).
      `IsGranted` es `IS_REPEATABLE`: itera **todos** los atributos, no solo el primero.
- [x] Verifica contra los **19 call sites / 9 strings** de hoy (*Inventario `#[IsGranted]`*).

### C — Vocabulario, superficie honesta y gate real · `pwa/src/` (AC6–AC9)

- [x] `Permission.ts` — `USERS_READ`/`USERS_INVITE`/`USERS_CHANGE_STATUS`/`USERS_ERASE`. Fuera los 6 huérfanos.
- [x] `ApiIdentityRepository.ts` — `MeResponse.permissions: string[]`; `isMeResource` lo valida; mapea **filtrando** por
      el enum conocido (*Crux B*); **borra** el `permissions: []` (`:60`) y el JSDoc que lo justificaba (`:36-47`).
- [x] **Gate real (AC9):** envuelve `users/page.tsx` y `users/[id]/page.tsx` en
      `<Can permission={Permission.USERS_READ} fallback={<EmptyState heading="Access denied" …/>}>`. **Reutiliza** el
      patrón de fallback que hoy vive en `new/page.tsx:40` y `[id]/edit/page.tsx:76` — se borran, pero su patrón es el
      correcto y es el que sobrevive.
- [x] **Borra:** `users/new/page.tsx`, `users/[id]/edit/page.tsx`, `_components/UserForm.tsx`,
      `_components/DeleteUserButton.tsx`, `_components/UsersBulkBar.tsx`, `schemas/UserFormSchema.ts`.
      (`UserCreateSchema`, que `UserFormSchema` importa, **sobrevive**: lo usan `LoginSchema`/`ForgotPasswordSchema`.)
- [x] `UserRowActions.tsx` — **sobrevive** con `CopyButton` solo. Fuera el `<Can USERS_WRITE>`+Link Edit (`:82`), el
      `<Can USERS_DELETE>`+dropdown+`DeleteUserButton` (`:95`), el `useState(deleteOpen)` y las props
      `onUserDeleted`/`onUserDeleteFailed`. **Actualiza su docblock** (`:56-61`: describe el gateo que retiras).
- [x] Ripple de props: `UsersTable.tsx`, `UsersCards.tsx`, `UsersStackedList.tsx`, `users/page.tsx`.
- [x] `users/page.tsx` — fuera botón New user (`:215`), `emptyAction` (`:294`), `<UsersBulkBar>` y el estado huérfano.
      **Reescribe el copy del empty state** (`:291-292`: `"No users yet"` / `"Create the first user to get started."`
      instruye una acción que ya no existe → AC8).
- [x] `users/[id]/page.tsx` — fuera botón Edit (`:95`) y `<DeleteUserButton>` (`:108`), **y el estado huérfano**:
      `deleteProblem`/`setDeleteProblem` + el `<MutationError testId="users-detail__delete-error">` (`:114-120`).
      **Compila y ESLint no lo caza** (sigue "usado") → código muerto silencioso si no lo borras a mano.
- [x] `UserRepository.ts` — quita `permissions?: Permission[]` de `UserInput` (no es concepto de dominio, SI-18).
      **Los stubs `create/update/delete` se quedan**: el puerto sigue siendo `CrudRepository` (fuera de alcance).
- [x] `_lib/userRoutes.ts` — retira las rutas sin destino.
- [x] **Comentarios que quedan mintiendo** (regla de comentarios + boy-scout):
      `AuthProvider.tsx:54-56` (*«the identity's (currently empty) permissions»*) y
      `ApiUserRepository.ts:104-107` (*«the action buttons are gated behind `<Can>`»* → tras AC8 el argumento es «no hay
      superficie»). **`User.ts:13-18` NO se toca** — habla del read-side, sigue siendo cierto.
- [x] Tests: `useCan.test.tsx` (**invierte** el test que codifica «oculta mientras `/me` no concede permisos»);
      `authorize.test.ts` (usa `USERS_WRITE`/`USERS_DELETE` **y `INVOICES_READ`/`INVOICES_WRITE` en `:45-48`** →
      reescribe con dos permisos del enum nuevo, p. ej. `USERS_READ` vs `USERS_ERASE`);
      `ApiIdentityRepository.test.ts` (la aserción `permissions: []` de `:40-59` **debe** romper → reescríbela; **añade**
      el caso de `permissions` malformado, que hoy no existe).
- [x] e2e (AC9): **añade un test al `describe` existente** de `users-real-api.spec.ts` — el spec **ya** importa
      `authenticatedTest` (`:2`) y usa `workerStorageState` (`:29`).

### Verificaciones (Working principle 4)

- [x] `make php.stan` por fichero PHP tocado · `make php.quality` al final (deptrac + PHPMD + cs-fixer incluidos).
- [x] `make php.lint.bounded-context` — **verde sin tocar el allowlist** (*Crux A*).
- [x] `make php.unit` · `make php.behat` — verdes completos.
- [x] `make pwa.quality` · `make pwa.test.unit`.
- [x] e2e contra el stack del worktree: puerto efímero (`docker compose port php 443`) +
      `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`; ADMIN sembrado con
      `organization:administrator:create e2e@erpify.test`.
- [x] `curl -k` en vivo: `/me` con ADMIN → 12 permisos; con VIEWER → exactamente `bank.read` + `bankAccount.read`.

## Dev Notes

### Crux A — el catálogo es **central y de dato**, no un tagged iterator por módulo

La mesa y la consulta externa recomendaron catálogo **por-módulo vía tagged iterator** (precedente
`$projectors: !tagged_iterator erpify.projector`, `services.yaml:31`). **Se verificó y no es viable**:

- `Backoffice/Bank` **no importa nada** de `Iam` hoy. Un `BankPermissionProvider` tendría que implementar una interfaz
  de `Erpify\Iam\Identity\Infrastructure\Security\` y devolver `Permission` → **import cross-context**.
- Lo bloquean **dos gates**: `BoundedContextGateTest` y deptrac (`deptrac.yaml:284-298`:
  `Backoffice.Bank.Infrastructure` no lista `Iam.Identity.Infrastructure`). La salida serían **3+ entradas** en
  `api/.bounded-context-allowlist`, cuyo encabezado (`:23-25`) limita los motivos legítimos a *«a published
  cross-context seam (Application service interface / integration event) or a coupling explicitly justified in an
  ADR»* — un provider de permisos **no es** ninguno.
- Promover el core RBAC a `Shared/` es el movimiento que el allowlist ya anticipa (*«when the RBAC authorization plane is
  extracted to its own home»*) — **una épica, no U-1**.

**Decisión:** `final class PermissionCatalog` en `Iam/Identity/Infrastructure/Security/`, permisos como literal puro.
Cero imports nuevos en cualquier dirección (son **strings**, no tipos), cero allowlist. Vive junto al plano RBAC que
deptrac documenta como *parked* ahí, y **se muda con él**.

**El precedente que zanja el «¿no es un resquicio para esquivar el linter?»** — `StaticAuthorizationPolicy`, en este
mismo módulo, **ya contiene hoy** (mergeado, verde en ambos gates) permisos de **otros** módulos como strings:

```php
private const array EXPLICIT_GRANTS = [
    'auditTrail.read'          => [Role::AUDIT_READER->value],   // ← Backoffice/Audit
    'bankAccount.changeStatus' => [Role::MANAGER->value],        // ← Backoffice/BankAccount
    …
];
```

Sus únicos `use` son `Role` (mismo contexto) y `Override`. **El catálogo tiene exactamente esa forma.** No introduce una
clase de conocimiento nueva: el plano RBAC ya conoce el vocabulario que gobierna, como dato, sin depender de nadie. Es
el patrón establecido, no una excepción que se inventa U-1.

**La objeción viva, y su respuesta honesta** (no la sobrevendas en review): un catálogo central **sí empeora** el
diseño. `AuthorizationCoreIsClosedForModificationTest` **demuestra** que un recurso nuevo (sentinel `invoice`) se
gobierna con **cero** filas de policy; con catálogo pasa a costar **una línea declarativa**, y un recurso que olvide
registrarse sigue bien server-side pero **desaparece de `/me`** → deniega en silencio en el cliente.

**AC5 no borra ese coste — cambia su modo de fallo**, de *silencioso en runtime* a *fallo de build*. La formulación
correcta: *el diseño pierde una propiedad local (cero configuración por recurso) y gana una garantía global de
consistencia (imposible olvidarse)*. El intercambio se acepta porque el requisito nuevo —**enumerar**— es incompatible
con conservar ambas: no se puede enumerar sin mantener una enumeración. **Sin B, A es peor que el estado actual. No
entregues A sin B.**

**Lo que NO se rompe (distinción que evita el pánico en review):** el **motor** RBAC sigue abierto a recursos nuevos —
`invoice.read` sigue funcionando sin tocar `AuthorizationPolicy`, `Permission` ni `PermissionVoter`, y su test lo sigue
probando. El coste nuevo es **solo del read-model de `/me`**. Son responsabilidades distintas; el catálogo no toca el
motor.

### El catálogo: los 12 permisos (verificados)

```
auditTrail.read
bank.read · bank.write · bank.delete
bankAccount.read · bankAccount.write · bankAccount.delete · bankAccount.changeStatus
users.read · users.invite · users.changeStatus · users.erase
```

**9 tienen endpoint; 3 no** (`users.invite`/`changeStatus`/`erase`). **Los 3 van** — decisión de Sergio. El seam está
documentado como deliberado en `StaticAuthorizationPolicy.php:51-52` (*«They are deliberate, documented seam — not dead
code to cull»*) y el tripwire es **`catálogo ⊇ gated`**, no igualdad → un permiso sin endpoint no rompe AC5. Efecto
buscado: cuando U-2 añada el endpoint, el permiso **ya fluye**; con AC8 no hay botón que enseñar mientras tanto.

**Dos guardarraíles del catálogo** (anotados, no accionables hoy):

- **Estrictamente declarativo o se pudre.** El riesgo del registro central es convertirse en cajón desastre en cuanto
  admita comportamiento. AC4 lo impide mecánicamente (`token_get_all`), igual que ya se hace con la policy. Si alguna
  vez necesita lógica, es señal de que el diseño se movió — no de que haga falta relajar AC4.
- **Disparador de revisión: ~cientos de permisos.** Con 12 entradas, mantener la lista a mano es trivial y un mecanismo
  de descubrimiento sería sobreingeniería. Si el vocabulario crece a **cientos**, el mantenimiento manual empieza a
  dominar el coste y la variante de descubrimiento (D1b) merece re-evaluarse. Nota gemela de la del `TIER_OPT_OUT`
  (~5 sano → ~15 migrar) del addendum.

### Inventario `#[IsGranted]` — 19 call sites, 9 strings (para AC5)

`auditTrail.read` ×2 (`AuditEventDetailController:29`, `AuditTimelineSearchController:29`) · `bank.read` ×4
(`BankCount:21`, `BankGet:16`, `BankRealtimeAuthorize:29`, `BankSearch:21`) · `bank.write` ×2 (`BankPost:24`,
`BankPut:20`) · `bank.delete` ×1 (`BankDelete:16`) · `bankAccount.read` ×4 (`Get:21`, `RealtimeAuthorize:36`,
`SearchCollection:37`, `Search:33`) · `bankAccount.write` ×2 (`Post:23`, `Put:20`) · `bankAccount.delete` ×1
(`Delete:16`) · `bankAccount.changeStatus` ×1 (`PatchStatus:20`) · `users.read` ×2 (`UserGet:21`, `UserSearch:26`).

**Trampas:** los dos `RealtimeAuthorize` llevan el atributo a **nivel de método** (`:29` y `:36`, indentados); el resto a
nivel de clase — refleja ambos. `MeController` **no** lleva `#[IsGranted]` (deliberado, `:15-17`: *«every authenticated
identity may read its own identity»*); solo lo menciona su docblock, y la reflexión ve atributos, no comentarios — **no
lo gatees**.

### Crux B — el cliente debe **descartar** los permisos que no conoce (AC7)

`Identity.permissions` es `HeldPermission[]` = `Permission | "*"`. La API emitirá `bank.read`, `bankAccount.write`… que
el enum PWA **no declara** (ni debe: la PWA no gatea nada de bancos → YAGNI). Mapear el array crudo **viola el tipo en
runtime**:

```ts
const known = new Set<string>(ALL_PERMISSIONS);
permissions: data.permissions.filter((p): p is Permission => known.has(p)),
```

Un permiso que nada gatea es **inútil** al cliente → descartarlo es correcto. **SI-20 exige igualdad byte-a-byte por
string, no igualdad de conjuntos** — un subconjunto es conforme.

El guard `isMeResource` (`:20-29`) es **permisivo con claves extra**, así que un despliegue API-primero no rompe la PWA:
el campo llega y se ignora hasta que C aterrice. Eso hace el orden de despliegue irrelevante, pero **no partas el PR**:
el e2e de AC9 necesita las dos mitades.

### Crux C — Scope 1: retirar botones no es scope creep

El addendum asigna los renames a U-2/U-3, pero se escribió antes de verificar que **el rename nunca ocurrió en U-0**.
Lo que se retira **no lo reconstruye nadie**:

- El `UserForm` actual es **CRUD del mock**: password, status y checkboxes de `permissions` sobre un enum con
  `projects.*` inventado. U-2 construye un form de **invitación** (email + roles; sin password, sin status) — **no
  sobrevive una línea**.
- `[id]/edit` **no tiene destino en ninguna historia**: SI-18 fija email inmutable y la inexistencia de `UpdateUser`. No
  es deuda, es ficción.
- `UsersBulkBar` (bulk delete) **no estaba gateado en absoluto** — agujero preexistente, alcanzable sin `users.delete`.
  Se retira con el resto: no se «arregla» gateándolo, porque respalda un delete que no existe.

**`users.erase` en el enum (AC6) — por qué aquí y no en U-5.** El addendum (`:37`) y FR8 lo asignan a U-5. **Se adelanta
a propósito, y esto NO es scope creep** — es alineación de **contrato compartido**, no entrega parcial de U-5. La
distinción que lo justifica (ratificada por consulta externa):

> Una historia puede adelantar un cambio asignado a otra **solo si** el cambio es (1) **puramente declarativo**,
> (2) **no produce comportamiento observable por sí mismo**, y (3) **elimina una incoherencia de un contrato
> compartido**. Si falla cualquiera de las tres, no se adelanta.

`users.erase` cumple las tres: es una línea de vocabulario; no aparece botón, endpoint ni flujo (U-5 sigue dueña de la
capacidad **y** del type-to-confirm); y elimina un hueco conocido del contrato que SI-20 declara prohibido. Un cambio
**funcional** (endpoint, botón, validación, flujo) nunca se adelantaría.

**Trazabilidad — el riesgo real aquí no es técnico.** Que no aparezca en silencio en el diff: nómbralo en el commit y en
la descripción del PR (*«se adelanta la entrada de vocabulario para sostener SI-20; la funcionalidad sigue siendo de
U-5»*).

### Ficheros a tocar (verificado en `main @ 4de018ff`)

| Fichero | Acción |
|---|---|
| `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php` | **NUEVO** — dato (12 literales) + `all()` |
| `api/src/Iam/Identity/Infrastructure/Security/PermissionResolver.php` | **NUEVO** — catálogo × policy → `list<string>` |
| `api/src/Iam/Identity/Application/Resource/MeResource.php` | +`public array $permissions` (`list<string>`) |
| `api/src/Iam/Identity/Infrastructure/Http/MeResourceMapper.php` | inyecta el resolver; reutiliza `stripRolePrefix()` |
| `api/tests/…/PermissionCatalogCoversEveryGatedRouteTest.php` | **NUEVO** — el tripwire (AC5) |
| `api/tests/…/PermissionCatalogTest.php` · `PermissionResolverTest.php` | **NUEVOS** (+ literalidad, AC4) |
| `api/tests/Unit/Iam/Identity/Infrastructure/Http/MeResourceMapperTest.php` | romperá por firma → extender |
| `api/features/backoffice/identity/session.feature` | `permissions` de alice + budget de query (AC10) |
| `pwa/src/context/shared/access/domain/Permission.ts` | vocabulario real (AC6) |
| `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts` | guard + filtro; muere `permissions: []` |
| `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` | comentario `:54-56` queda falso |
| `pwa/src/app/backoffice/users/new/page.tsx` · `[id]/edit/page.tsx` | **BORRAR** |
| `pwa/src/app/backoffice/users/_components/{UserForm,DeleteUserButton,UsersBulkBar}.tsx` | **BORRAR** |
| `pwa/src/context/backoffice/user/application/schemas/UserFormSchema.ts` | **BORRAR** |
| `pwa/src/app/backoffice/users/_components/UserRowActions.tsx` | solo `CopyButton`; −2 props; docblock |
| `pwa/src/app/backoffice/users/_components/{UsersTable,UsersCards,UsersStackedList}.tsx` | ripple de props |
| `pwa/src/app/backoffice/users/page.tsx` · `[id]/page.tsx` | `<Can users.read>` + fuera controles/estado/copy |
| `pwa/src/context/backoffice/user/{domain/UserRepository.ts,infrastructure/ApiUserRepository.ts}` | `UserInput`; comentario `:104-107` |
| `pwa/tests/context/shared/access/{useCan.test.tsx,domain/authorize.test.ts,ApiIdentityRepository.test.ts}` | actualizar |
| `pwa/tests/e2e/backoffice/users-real-api.spec.ts` | +test de gateo ADMIN (AC9) |

**Ningún test unitario referencia hoy** `UserForm`/`DeleteUserButton`/`UsersBulkBar`/`UserRowActions` (`git grep -ln …
-- tests` → vacío): las deleciones no rompen la suite. `buildUsersColumns` vive **dentro** de `UsersTable.tsx:55` → la
lista de ripple está completa.

### Testing (patrones del repo)

- **Tripwires estructurales = estilo de la casa**: `StaticAuthorizationPolicyIsDataOnlyTest` (`token_get_all`),
  `AuthorizationCoreIsClosedForModificationTest` (reflexión), `BoundedContextGateTest` + `MarkerStatusMapContractTest`
  (walk + reflexión). AC5 va en esa familia — **unit, sin kernel**.
- **Ubicación de AC5:** los gates que barren `api/src` viven en `tests/Unit/Shared/Architecture/`; este se propone en
  `tests/Unit/Iam/Identity/Infrastructure/Security/` porque el catálogo es de `Iam` y se mudará con él. Si al dev le
  cuadra más la familia `Shared/Architecture`, es aceptable — **decídelo y di por qué en las Completion Notes**.
- **PHPMD `TooManyPublicMethods` (10) aplica a tests.** `StaticAuthorizationPolicyTest` está **en el tope** → **no le
  añadas métodos**; los casos nuevos van en clases nuevas (motivo por el que existe
  `StaticAuthorizationPolicyUsersResourceTest`). `CouplingBetweenObjects` (≤13) también aplica a tests.
- **`#[DataProvider]` estático cuenta** para `TooManyPublicMethods` — con 6 casos en `PermissionResolverTest`, usa **un**
  provider, no 6 métodos.
- **`/me` no tiene golden test funcional** (a diferencia de `UserDetailResponseGoldenFunctionalTest`). Añadir uno fija
  AC1 sin ambigüedad — recomendado; el Behat es el mínimo.
- **Cobertura de controladores finos**: si tocas un wire-gate funcional, `#[CoversClass(…)]`, nunca `#[CoversNothing]`.

### Gotchas heredados (verificados en épicas previas)

- **`TestDebugDataHolder`** descarta el auth-lookup por call-site `UserProvider` (no por tabla) — el número del budget de
  AC10 es el de la request, no el de la sesión.
- **`php.stan` puede segfaultear** en el worker web (exit 139) → `make php.stan PHP_SERVICE=messenger_worker`.
- **Rector** borra `/** @var T */` sin nombre sobre `return` en tests → usa `/** @phpstan-var T */`.
- **e2e en worktree:** `PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` al puerto efímero; EACCES → `rm -rf pwa/.next-e2e && rm -f
  pwa/next-env.d.ts`.
- **`users` es PLURAL** (SI-20) — `users.read`, nunca `user.read`.

### Decisiones ya tomadas — no re-abrir

| # | Decisión | Argumento |
|---|---|---|
| D1 | Catálogo **central de dato**, no tagged iterator | Providers por módulo = import cross-context → 3+ entradas de allowlist sin motivo legítimo. Strings ≠ dependencias — y `EXPLICIT_GRANTS` **ya** lo hace hoy con `auditTrail.read`/`bankAccount.changeStatus`, verde en ambos gates. Ratificado por consulta externa (retiró su propia propuesta de tagged iterator al ver el gate) |
| D1b | **Descartada** la variante «descubrir por reflexión las clases `*Permission` de cada módulo» | Evita el import, sí — pero cambia doce strings por reflexión + convención de nombres + clases obligatorias, y obliga a revertir la decisión reciente de no crear `UsersPermission`/`AuditTrailPermission` «para una acción». Contra el «boring over clever» declarado |
| D2 | El puerto **no se toca**; el test OCP **no se enmienda** | Opción B rompía un gate deliberado **y** seguía necesitando el catálogo: precio sin beneficio. *(Para el acta: `assertCount(1)` sobrealcanza su justificación —habla de subject/row-level— pero un gate ancho que no estorba no se arregla hoy.)* |
| D3 | **No** emitir `*` para ADMIN | Solo resuelve ADMIN; mete en el wire un token que `Permission::isWellFormed()` rechaza en el mismo servidor |
| D4 | Reflexión en el **test**, no en producción | Escaneo por request → compiler pass → infra que mantener. Y es ciega a checks fuera de controladores, que `project-context.md` avisa que llegarán |
| D5 | Los 3 permisos sin endpoint **van** al catálogo | Seam documentado; tripwire es `⊇` no `=`; U-2 se encuentra el permiso fluyendo |
| D6 | El cliente **filtra** lo que no declara | `HeldPermission[]` no admite `bank.read`; SI-20 exige igualdad por string, no de conjuntos |
| D7 | **Scope 1** + gate real por `users.read` | El form actual es CRUD del mock: U-2 no reconstruye, construye otra cosa. El gate de consola es lo que hace U-1 demostrable |

### Fuera de alcance (frontera explícita)

- **Test de drift SI-20 cross-repo** (catálogo API ↔ enum PWA). El catálogo lo **hace posible por primera vez** — hoy es
  inescribible. Sería el **primer** test cross-repo del monorepo: merece su propia decisión. **Follow-up.**
  *(Ojo al vender esto: el catálogo **no** logra DRY — la PWA conserva su enum, siguen siendo dos listas. Lo que compra
  es **verificabilidad**. Dos consultas externas independientes lo han vendido como DRY; no es exacto.)*
- **Promover el core RBAC a `Shared/`** — el allowlist lo anticipa; es una épica. Cuando ocurra, el plano se muda
  **entero y junto**: `Permission` + `AuthorizationPolicy` + `PermissionVoter` + **`PermissionCatalog`**. Dejar el
  vocabulario separado del motor sería peor que el estado de hoy.
- **Migrar la consola a puertos identity-shaped** (SI-18). Los stubs `create/update/delete` **se quedan**.
- **Gatear banks/bank-accounts** (`<Can>` cero hoy pese a existir `BankPermission`). Preexistente, otra historia.
- **Gatear la entrada de nav «Users»** (`backofficeMenu.ts:209`) — `NavSubItem` no tiene campo de permiso y el sidebar
  está lleno de entradas especulativas sin gatear; hacerlo bien es su propia historia.
- **Endpoint de invitación / cambio de estado** → U-2 / U-3. U-1 **no** añade superficie de acción.
- **`DevSessionSwitcher`** — dev-only, funciona con el enum nuevo (usa `ALL_PERMISSIONS`/`PERMISSION_WILDCARD`, sin
  símbolos concretos). No lo toques.

### Project Structure Notes

- `PermissionCatalog`/`PermissionResolver` en `Iam/Identity/Infrastructure/Security/`, junto al core RBAC. Ambos
  Infrastructure; `MeResourceMapper` (Infrastructure/Http) los consume: mismo contexto, **misma layer deptrac**
  (`Iam.Identity.Infrastructure`) → limpio, sin allowlist.
- `MeResource` sigue en `Application/Resource/` y recibe `list<string>` plano — **no** importa `Permission`
  (Application → Infrastructure sería violación de capa, `deptrac.yaml:241-247`). La derivación ocurre en el **mapper**.
  `ResourceDtoContractTest` admite `array` (`NORMALIZER_SAFE_TYPES`, `:60`) → `permissions` pasa igual que `roles`.
- Sin migraciones, sin cambios de entidad, sin eventos, sin transporte.

### References

- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-16…SI-20; fila U-1; nota *«U-1 es solo el
  gateo de cliente»*.
- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#FR4`] — *set derivado, no almacenado*; NFR10, NFR11.
  Ver *Cómo se lee FR4 aquí*.
- [Source: `_bmad-output/implementation-artifacts/u-0-read-side-lista-detalle-conectados-backend.md`] — precedente:
  opt-out + grants, stubs 501, `<Can>` deniega todo hasta U-1.
- [Source: `docs/adr/rbac-authorization-model.md`] — D1–D9 (D9: sin ABAC/row-level). **Ojo:** sus rutas dicen
  `Backoffice/Identity/…`; el código está en `Iam/Identity/…` (movido, ADR sin actualizar).
- [Source: `api/.bounded-context-allowlist:23-25`] — motivos legítimos de una entrada; seam transitorio
  `Membership → Iam Role` y su nota *«when the RBAC authorization plane is extracted to its own home»*.
- [Source: `docs/project-context.md`] — *«Auth checks at the Application layer — don't rely solely on controller-level
  `#[IsGranted]`»* (el motivo de D4).

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

Gates finales, todos con logs frescos contra el stack del worktree (`erpify-iam-me-permissions-can-kuzw`,
HTTPS efímero `33389`):

| Gate | Resultado |
|---|---|
| `make php.quality` (stan+deptrac+PHPMD+cs-fixer+PHPCS) | **EXIT=0** |
| `make php.lint.bounded-context` | **EXIT=0** — 8 tests, **sin tocar el allowlist** (D1) |
| `make php.lint.error-contract` | **EXIT=0** |
| `make php.deptrac` | **EXIT=0** — 0 violations, 0 errors |
| `make php.unit` | **EXIT=0** — **1966** tests, 8625 asserts (1951 en U-0 → +15) |
| `make php.behat` | **EXIT=0** — **310** escenarios, 2812 steps (308 en U-0 → +2) |
| `make pwa.quality` | **EXIT=0** |
| `make pwa.test.unit` | **EXIT=0** — **1067** tests (1061 en U-0 → +6) |
| e2e `users-real-api` | **EXIT=0** — 3/3 |
| `curl -k` en vivo `/me` (ADMIN) | 12 permisos, envelope `{data}` |

### Completion Notes List

**Los dos gates nuevos se verificaron por sabotaje, no por observar que pasan.** Un tripwire que no puede
fallar no vale nada, y AC5 es lo único que hace defendible el catálogo central:

- **AC5:** quitado `users.read` del catálogo → el build falla con
  *«Permission "users.read" gates UserSearchController but is absent from PermissionCatalog»*. Restaurado.
- **AC9:** forzado `permissions: []` en el cliente → el e2e falla (la consola no renderiza). Restaurado.
  Un *probe* aparte confirmó que el denegado ve el fallback **«Access denied»**, no una página en blanco.

**Corrección a mi propio test de AC9.** La primera versión asertaba `Access denied → count(0)` **antes** de
esperar a la lista: pasaba de forma vacua contra una página sin hidratar — justo el estado que deja un gate
roto. Reordenado: primero `data-state=ready`, después la ausencia del fallback.

**Desviación deliberada (AC4).** La historia proponía extender el DataProvider de
`StaticAuthorizationPolicyIsDataOnlyTest`. **No se hizo**: ese test está referenciado por nombre en 4 sitios,
incluido `docs/adr/rbac-authorization-model.md:80`, así que renombrarlo para cubrir dos clases habría hecho
ripple hasta el ADR; y clonar sus ~120 líneas de máquina de tokens habría disparado la duplicación de Sonar.
En su lugar se extrajo la mecánica a **`api/tests/Support/ConstantValueTokens.php`**, consumida por el test
existente (que conserva nombre, `#[CoversClass]` y las referencias del ADR) y por el nuevo
`PermissionCatalogIsDataOnlyTest`. Es el precedente literal de `ApiSourceFiles` («centralising it here keeps
the two gates from drifting apart»). Ambos verdes: 4 tests, 200 asserts.

**Decisión de alcance no anticipada por la historia: la selección de filas.** Al retirar `UsersBulkBar`
(control alcanzable que caía en el stub 501, AC8) la selección quedó **huérfana** — checkboxes que
seleccionan y no ofrecen ninguna acción. Se retiró su cableado de `page.tsx`. **Las props de selección de
`UsersTable`/`UsersCards`/`UsersStackedList` se conservan** (son opcionales, y U-3 —cambio de estado— es su
consumidor natural); lo que desaparece es su uso. Cualquier revisor debería mirar esto.

**Ubicación de AC5** (la historia pedía decidir y justificar): `tests/Unit/Iam/Identity/Infrastructure/Security/`
y no la familia `tests/Unit/Shared/Architecture/`, pese a que ahí viven los demás gates que barren `api/src`.
Motivo: el gate no describe una regla de arquitectura global, sino una **propiedad del catálogo** — y se muda
con el plano RBAC el día que se extraiga.

**Verificaciones que confirmaron la historia, no al revés:**

- `PermissionResolverTest` pasa con la tabla exacta de AC1 (ADMIN 12 · VIEWER 2 · AUDIT_READER **1** ·
  VIEWER+AUDIT_READER 3 · alice 8 · `[]` 0) → la simulación a mano de `permits()` era correcta, incluida la
  trampa de que AUDIT_READER **no** está en `TIER_VERBS`.
- AC10 verificado, no asumido: el escenario Behat con budget **0 queries** pasa — derivar los 12 permisos no
  toca la base de datos.
- D1 verificado contra el gate real: `php.lint.bounded-context` verde **sin una sola entrada nueva** en
  `api/.bounded-context-allowlist`.

**Gotchas encontrados (para las historias siguientes):**

- **`make php.behat` resetea la DB** y se lleva por delante el ADMIN sembrado del e2e → **siembra después de
  Behat**, no antes (`organization:provision` + `organization:administrator:create e2e@erpify.test
  e2ePassword123`; la password es `E2E_USER_PASSWORD` de `tests/e2e/constants.ts`, y el comando la toma
  posicional, no con `--password=`).
- **`docker compose ps` a pelo miente en un worktree**: usa el proyecto derivado del directorio, no el
  `COMPOSE_PROJECT_NAME` del Makefile → parece que el stack está caído. Usa `make docker.ps`.
- **Flake preexistente**, no introducido por U-1: `BankStoredObjectMultipartFunctionalTest` aborta con
  *«Premature end of PHP process»* de forma intermitente. Verificado stasheando los cambios de U-1: la suite
  **base** también falla así, y con los cambios aplicados pasa 1966/1966.
- **Rector × PHPCS**: `AddArrayFunctionClosureParamTypeRector` impone el tipo del parámetro **inlineando el
  FQCN**, lo que revienta el límite de 120 caracteres. Solución: importar el tipo y usar el nombre corto —
  así ninguna de las dos herramientas tiene opinión.

### File List

**API — nuevos**

- `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php`
- `api/src/Iam/Identity/Infrastructure/Security/PermissionResolver.php`
- `api/tests/Support/ConstantValueTokens.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogIsDataOnlyTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogCoversEveryGatedRouteTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionResolverTest.php`

**API — modificados**

- `api/src/Iam/Identity/Application/Resource/MeResource.php`
- `api/src/Iam/Identity/Infrastructure/Http/MeResourceMapper.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Http/MeResourceMapperTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyIsDataOnlyTest.php`
- `api/features/backoffice/identity/session.feature`

**PWA — borrados**

- `pwa/src/app/backoffice/users/new/page.tsx`
- `pwa/src/app/backoffice/users/[id]/edit/page.tsx`
- `pwa/src/app/backoffice/users/_components/UserForm.tsx`
- `pwa/src/app/backoffice/users/_components/DeleteUserButton.tsx`
- `pwa/src/app/backoffice/users/_components/UsersBulkBar.tsx`
- `pwa/src/context/backoffice/user/application/schemas/UserFormSchema.ts`

**PWA — modificados**

- `pwa/src/context/shared/access/domain/Permission.ts`
- `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts`
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx`
- `pwa/src/app/backoffice/users/page.tsx`
- `pwa/src/app/backoffice/users/[id]/page.tsx`
- `pwa/src/app/backoffice/users/_components/UserRowActions.tsx`
- `pwa/src/app/backoffice/users/_components/UsersTable.tsx`
- `pwa/src/app/backoffice/users/_components/UsersCards.tsx`
- `pwa/src/app/backoffice/users/_components/UsersStackedList.tsx`
- `pwa/src/app/backoffice/users/_lib/userRoutes.ts`
- `pwa/src/context/backoffice/user/domain/UserRepository.ts`
- `pwa/src/context/backoffice/user/infrastructure/ApiUserRepository.ts`
- `pwa/tests/context/shared/access/useCan.test.tsx`
- `pwa/tests/context/shared/access/domain/authorize.test.ts`
- `pwa/tests/context/shared/access/ApiIdentityRepository.test.ts`
- `pwa/tests/e2e/backoffice/users-real-api.spec.ts`

**Artefactos de planificación (enmendados en este PR)**

- `_bmad-output/planning-artifacts/epics-users-admin.md` (FR4/FR5/FR6 + resúmenes + AC de U-2/U-3)
- `_bmad-output/planning-artifacts/arch-addendum-users-admin.md` (fila U-1/U-2/U-3 + DAG)
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

### Change Log

| Fecha | Cambio |
|---|---|
| 2026-07-17 | Historia creada `ready-for-dev` (commit `087ecee7`), con FR4/FR5/FR6 + addendum enmendados |
| 2026-07-17 | A: `PermissionCatalog` + `PermissionResolver`; `/me` emite el set derivado (12 para ADMIN) |
| 2026-07-17 | B: tripwire de completitud sobre todo `#[IsGranted]`; verificado por sabotaje |
| 2026-07-17 | C: vocabulario byte-idéntico, superficie CRUD del mock retirada, consola gateada por `users.read` |
| 2026-07-17 | AC4: mecánica `token_get_all` extraída a `ConstantValueTokens`, compartida por los dos tripwires |
| 2026-07-17 | Todos los gates verdes con logs frescos; historia → `review` |

### Review Findings

Code-review 2026-07-17 (3 capas adversariales — Blind Hunter · Edge Case Hunter · Acceptance Auditor — sobre
`4de018ff..62eaf4e4`, incluidas las enmiendas de planificación). Todos los AC verificados como cumplidos por
las 3 capas (la tabla AC1 re-simulada a mano contra `permits()` = exacta; AC5 muerde; SI-20 byte-idéntico uno
a uno; frontera de alcance respetada). 8 patch, 2 defer, resto descartado con evidencia.

- [x] [Review][Patch] `EmptyState` usa `variant="first-run"` (icono Sparkles) en los fallbacks de «Access denied» — existe `variant="permission-denied"` (icono Lock), hecho para esto [pwa/src/app/backoffice/users/page.tsx:137 · pwa/src/app/backoffice/users/[id]/page.tsx:31]
- [x] [Review][Patch] Enmienda incompleta: FR8/SI-19/fila U-5 siguen diciendo «`users.erase` **gana** entrada en el enum» — falso tras U-1, que ya la mete. Es la clase exacta de defecto que el PR se propuso corregir; se arreglaron FR5/FR6/U-2/U-3 pero no los gemelos de U-5 [_bmad-output/planning-artifacts/epics-users-admin.md:97,288 · arch-addendum-users-admin.md:17,37]
- [x] [Review][Patch] Docblock que miente: `UsersStackedList` describe checkbox/selección («Shift extends the selection range… Checkbox and actions are always visible») que ya no se renderiza tras retirar el cableado de selección — boy-scout, fichero tocado [pwa/src/app/backoffice/users/_components/UsersStackedList.tsx:25-30]
- [x] [Review][Patch] Comentario e2e «the real API returns no `permissions`» contradice al test hermano que este PR añade justo debajo (`/me` SÍ trae permissions) — la aserción sigue válida (AC2), la justificación no [pwa/tests/e2e/backoffice/users-real-api.spec.ts:92-93]
- [x] [Review][Patch] Docblock de `me()` «never a wildcard the server did not send» induce a error: el cliente filtra `*` **siempre** (no está en `ALL_PERMISSIONS`), venga de donde venga. Reformular a «descarta lo que el enum no declara, wildcard incluido» [pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts:60]
- [x] [Review][Patch] ID de invariante en comentario de código — prohibido en `main` (CLAUDE.md → Code comments). Borrar «(SI-20)»; el argumento se sostiene sin él [api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogTest.php:60]
- [x] [Review][Patch] `\n` literal dentro de comillas simples en el mensaje de fallo del tripwire → imprime `\n` literal, no un salto (degrada el propio gate de AC5) [api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogCoversEveryGatedRouteTest.php:66]
- [x] [Review][Patch] `PermissionCatalog` es `final class` mientras sus vecinos (incl. `PermissionResolver`, mismo diff) son `final readonly class`; y `all()` no declara `@throws InvalidPermission` pese a llamar `Permission::fromString()` en cada `/me` — consistencia + honestidad, sin coste [api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php:26,52]
- [x] [Review][Defer] El fetch de la lista/detalle se dispara aunque `<Can>` deniegue (`useResourceList`/`useResourceItem` corren en el cuerpo antes del gate) → petición desperdiciada + un 403 por visita denegada. Sin agujero (enforcement server-side). Fix limpio requiere que los hooks compartidos acepten un flag `enabled` → `Shared/` está **fuera de alcance** de U-1 [pwa/src/app/backoffice/users/page.tsx:82 · [id]/page.tsx:24] — deferred, toca infra compartida
- [x] [Review][Defer] El tripwire de AC5 es ciego a `#[IsGranted(new Expression(...))]`, a las reglas `access_control` de `security.yaml`, y a subclases de `IsGranted` (match exacto) — todos **latentes hoy** (0 en el árbol, verificado). Es un límite conocido del barrido, no un bug activo [api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionCatalogCoversEveryGatedRouteTest.php:112] — deferred, gap latente documentado

**Descartados con evidencia (no ruido, verificados):** el riesgo de comportamiento del wildcard («si `/me` devuelve `*` → todo se cierra») es **imposible bajo D3** (la API nunca emite `*`; `Permission::isWellFormed` lo rechaza) — solo el docblock era real (patch arriba). La retención de props de selección en las 3 listas es **desviación ya declarada** (Completion Notes, para U-3) — solo el docblock que miente es accionable. El order-coupling del escenario Behat es *pinning exhaustivo deliberado* y el contra-ejemplo del revisor es erróneo (un permiso que alice no posee no desplaza sus índices). `contains()` como 2º método público es benigno, correcto y necesario para el tripwire. La forma de `/me` sin doc: verificado que **no existe** doc que la fije hoy (0 hits de `iam_me`/`GET /me` en `api/docs` y `docs`) → sin drift.
