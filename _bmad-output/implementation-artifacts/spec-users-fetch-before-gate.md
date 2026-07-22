---
title: 'Backoffice · Users — el gate `<Can>` por delante del fetch (fin de los `ACCESS_DENIED` falsos en la traza)'
type: 'bugfix'
created: '2026-07-22'
status: 'done'
baseline_commit: '52f42642'
review_loop_iteration: 0
context:
  - '{project-root}/pwa/CLAUDE.md'
  - '{project-root}/docs/rules/frontend.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Las dos páginas de Users llaman al hook de datos **por encima** del gate
`<Can permission={Permission.USERS_READ}>` (`users/page.tsx:83` vs `:134`; `users/[id]/page.tsx:33` vs `:55`).
`users.read` es **solo ADMIN** (`StaticAuthorizationPolicy.php:60` + `TIER_OPT_OUT` en `:72`) y la entrada
«Users» del sidebar **no está gateada** (`backofficeMenu.ts:209`). Resultado: VIEWER, EDITOR, MANAGER y
AUDIT_READER que pulsan «Users» montan la página → sale la petición → 403 → `AccessDeniedAuditListener` (que
**no** consulta `AuditPolicy`) escribe una fila `ACCESS_DENIED` a nivel `AuditLevel::SECURITY`. El usuario
solo ve «Access denied»; el coste real es **contaminar la traza regulatoria con eventos de seguridad falsos**.

**Approach:** Subir el gate por encima de los hooks: el `export default` de cada `page.tsx` queda como
cáscara que solo renderiza `<Can …>` y el cuerpo actual baja a un componente interno. Los hooks compartidos
no se tocan y la salida renderizada no cambia: solo cambia **cuándo** se montan.

## Boundaries & Constraints

**Always:**
- La salida del gate se mantiene **byte-idéntica**: mismo `<EmptyState variant="permission-denied">` con el
  mismo `heading`/`description`, mismo DOM y **mismos `data-testid`** que en `{baseline_commit}`.
- El componente interno vive en `users/_components/`, con `"use client"` y **export nombrado** (el default
  solo lo lleva el `page.tsx` que Next exige).
- Cobertura unitaria del camino denegado: hoy es **cero**.

**Ask First:**
- Tocar `useResourceList` / `useResourceItem` (opción A, ya descartada).
- Gatear el menú lateral (`NavItem.permission` + filtrado).
- Cambiar el copy o los `data-testid` de «Access denied».

**Never:**
- Añadir un flag `enabled` a los hooks compartidos: 2 consumidores reales, Regla de Tres no cumplida, y
  afectaría a un hook que usan 4 listados.
- Tocar el `api/` (`AccessDeniedAuditListener` se queda como está; es el detector, no el defecto).
- Extraer una abstracción común entre lista y detalle: dos cáscaras de 15 líneas, no un patrón.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lista concedida | Sesión ADMIN con `users.read` | Se monta el interno; **una** llamada a `repository.search`; listado igual que hoy | N/A |
| Lista denegada | Sesión VIEWER/EDITOR/MANAGER/AUDIT_READER | `EmptyState` permission-denied; **cero** llamadas a `search` → sin 403 → sin fila de auditoría | N/A |
| Detalle denegado | Misma sesión, ruta `/backoffice/users/<id>` | `EmptyState` permission-denied; **cero** llamadas a `find` | N/A |
| Sesión en hidratación → concedida | `AuthProvider` renderiza `children` con `session === null` hasta que `/me` resuelve | Mientras `null` el gate deniega y **no sale ninguna petición**; al resolver a ADMIN se monta el interno y sale **exactamente una** | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/users/page.tsx` — lista: hook `:83`, gate `:134`. Queda como cáscara.
- `pwa/src/app/backoffice/users/[id]/page.tsx` — detalle: hook `:33`, gate `:55`; el helper local `Field` (`:217`) viaja con el cuerpo.
- `pwa/src/app/backoffice/users/invite/page.tsx` — precedente de la forma «cáscara con gate».
- `pwa/src/context/shared/access/infrastructure/ui/Can.tsx` — el render condicional que sostiene el fix (solo lectura).
- `pwa/src/context/shared/resource/application/useResourceList.ts` (`search`) · `useResourceItem.ts` (`find`) — **no se modifican**.
- `pwa/tests/app/backoffice/users/userDetailPage.test.tsx` — arnés existente: `AuthProvider` + `container.get` por token.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/app/backoffice/users/_components/UsersListView.tsx` — crear: mover ahí íntegro el cuerpo
  actual de la lista (hooks, `useStoredPreference`, JSX interno del gate); export nombrado `UsersListView`.
- [x] `pwa/src/app/backoffice/users/page.tsx` — reducir a la cáscara: `<Can permission fallback={…}><UsersListView /></Can>`, con el `fallback` copiado sin tocar; borrar los imports que ya no usa.
- [x] `pwa/src/app/backoffice/users/_components/UserDetailView.tsx` — crear: cuerpo del detalle + el helper `Field`; export nombrado `UserDetailView`.
- [x] `pwa/src/app/backoffice/users/[id]/page.tsx` — reducir a la cáscara equivalente.
- [x] `pwa/tests/app/backoffice/users/usersListPage.test.tsx` — crear: sesión sin `users.read` → no se llama a `search` y sale el `EmptyState`; sesión ADMIN → `search` una vez y el listado pinta.
- [x] `pwa/tests/app/backoffice/users/usersListPage.test.tsx` — añadir el test de transición: con `me()` diferido (promesa resuelta a mano) afirmar `search` no llamado, resolver a ADMIN y afirmar `search` llamado **una sola vez**.
- [x] `pwa/tests/app/backoffice/users/userDetailPage.test.tsx` — añadir el caso denegado (`find` nunca llamado) sin tocar los casos existentes.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — anexar la propuesta de gatear el menú lateral.

**Acceptance Criteria:**
- Dado un `git diff` contra `{baseline_commit}`, cuando se inspecciona, entonces ningún `data-testid` ni el
  copy del `EmptyState` han cambiado y `useResourceList`/`useResourceItem` no aparecen en el diff.
- Dada una sesión ADMIN, cuando se monta la lista, entonces el comportamiento observable (skeleton →
  listado, filtros, paginación, peek) es el mismo que en `{baseline_commit}`.
- Dada cualquier sesión sin `users.read`, cuando se navega a lista o detalle, entonces no se emite ninguna
  petición al repositorio.
- Dada una sesión en hidratación, cuando `/me` aún no ha resuelto, entonces no sale ninguna petición; y
  cuando resuelve a ADMIN, sale exactamente una (ni cero por un montaje perdido, ni dos por doble montaje).

## Spec Change Log

- **Vuelta 1 (revisión adversarial, 2 pasadas read-only).** Ningún hallazgo tocó código de producción: la
  fidelidad del traslado se verificó token-a-token y el razonamiento de seguridad se confirmó en los cuatro
  estados de sesión (hidratando · no autenticada · autenticada sin permiso · con permiso pero no ACTIVE).
  Todo se clasificó `patch`; no hubo `bad_spec` porque las AC eran correctas y re-derivar habría reproducido
  el mismo código ya validado con 7/7 e2e. Aplicado:
  - Los comentarios de ambos view components afirmaban la supresión del fetch sin decir que el control real
    es del API. Se añadió el descargo explícito — la línea «el enforcement es server-side» vivía en el bullet
    de `deferred-work.md` que este PR borra al resolverlo, y se habría perdido del repo.
  - Tres aserciones de test no demostraban lo que enunciaban: `follow` nunca se alcanza sin paginar (vacua,
    eliminada); el `waitFor` sobre `toHaveBeenCalledTimes(1)` evaluaba con el contador ya en 1 (sustituido por
    aserción directa tras el pintado de la fila); y «Access denied» no distingue *sin permiso* de *sesión sin
    hidratar* (añadida una sonda `useSession` que fija la identidad observada).
  - El stub del contenedor devolvía un catch-all para cualquier token; como ambos call sites tragan
    excepciones, un token mal escrito habría degradado la vista a error y dejado pasar un «no hubo petición».
    Ahora lanza ante token desconocido.
  - Añadido el caso «tiene el permiso pero está SUSPENDED» — la fila que faltaba de la tabla de verdad.
  - Registrado que las dos cáscaras dejan de ser Client Components (ver Design Notes).

## Design Notes

`<Can>` devuelve `children` **o** `fallback` (`Can.tsx:31`): React nunca monta el subárbol denegado, así que
sus hooks no llegan a ejecutarse. Basta con bajar el cuerpo un nivel; no hace falta condicionar el hook.

Sin latencia añadida: `RequireAuth` devuelve `null` hasta `status === AUTHENTICATED` (`RequireAuth.tsx:35`) y
envuelve todo el back-office, así que en producción la página solo monta con la sesión ya resuelta. Para un
ADMIN el gate concede en el mismo commit de render y el fetch sale cuando sale hoy: no hay cascada.

Las dos cáscaras dejan de llevar `"use client"`: solo montan `<Can>` con un `fallback` sin hooks, así que
pasan a Server Components — la misma forma que `users/invite/page.tsx` ya tiene en `main`. El árbol de
imports de la lista deja de entrar por la ruta cliente de la página.

El arnés unitario **no** monta `RequireAuth`, y `AuthProvider` sí renderiza `children` durante `hydrating`
(el corte lo hace el guard, no el provider). Por eso la transición `null → ADMIN` es observable directamente
en test y merece afirmación propia: fija que la autorización se evalúa **antes** de cualquier efecto, de modo
que una futura «optimización» que renderice contenido protegido durante la hidratación falle aquí en vez de
reintroducir el fetch prematuro en silencio.

## Verification

**Commands:**
- `make pwa.test.unit c='tests/app/backoffice/users'` -- expected: exit 0, incluidos los casos denegados nuevos
- `make pwa.test.unit` -- expected: exit 0 (si aparece rojo en `bankAccountsListMutations` / `bankFormMutationError` / `bankAccountsListRealtime`, es el cluster de flakes conocido: re-ejecutar en aislamiento antes de atribuirlo al diff)
- `make pwa.quality` -- expected: exit 0

## Suggested Review Order

**El fix: el gate por delante del efecto**

- Punto de entrada: la página entera es ahora la puerta, y nada más.
  [`page.tsx:8`](../../pwa/src/app/backoffice/users/page.tsx#L8)

- El hook de lectura, ya dentro del subárbol que el gate solo monta si concede.
  [`UsersListView.tsx:89`](../../pwa/src/app/backoffice/users/_components/UsersListView.tsx#L89)

- El descargo que evita leer el gate como si fuera el control de acceso.
  [`UsersListView.tsx:66`](../../pwa/src/app/backoffice/users/_components/UsersListView.tsx#L66)

- Misma forma en el detalle; `useParams` baja con el cuerpo al lado cliente.
  [`UserDetailView.tsx:38`](../../pwa/src/app/backoffice/users/_components/UserDetailView.tsx#L38)

- La cáscara del detalle, ya sin `"use client"`.
  [`[id]/page.tsx:8`](../../pwa/src/app/backoffice/users/[id]/page.tsx#L8)

**La prueba de que no sale la petición**

- La transición hidratando → ADMIN: sin lectura antes de resolver, exactamente una después.
  [`usersListPage.test.tsx:140`](../../pwa/tests/app/backoffice/users/usersListPage.test.tsx#L140)

- La sonda que distingue «sin permiso» de «sesión sin hidratar» — sin ella la aserción era ambigua.
  [`usersListPage.test.tsx:81`](../../pwa/tests/app/backoffice/users/usersListPage.test.tsx#L81)

- Token desconocido lanza: ambos call sites tragan excepciones y enmascararían un stub mal puesto.
  [`usersListPage.test.tsx:19`](../../pwa/tests/app/backoffice/users/usersListPage.test.tsx#L19)

- Con el permiso pero SUSPENDED: la fila que faltaba de la tabla de verdad.
  [`usersListPage.test.tsx:130`](../../pwa/tests/app/backoffice/users/usersListPage.test.tsx#L130)

- El mismo caso denegado en el detalle, sobre el arnés preexistente.
  [`userDetailPage.test.tsx:125`](../../pwa/tests/app/backoffice/users/userDetailPage.test.tsx#L125)

**Registro**

- Se borra el bullet resuelto y se anexa el gateo del menú como pendiente.
  [`deferred-work.md`](./deferred-work.md)
