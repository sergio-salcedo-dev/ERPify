---
title: 'GH #760 + #420 — guardarraíl propio para la navegación dura y declive del trait StringValue'
type: 'chore'
created: '2026-08-18'
status: 'in-review'
baseline_commit: '85c1687cd3dd836d2692eda2d93e7bc693c8ed8b'
review_loop_iteration: 2
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `@next/next/no-location-assign-relative-destination` no puede enunciar el invariante que nos importa. Resuelve un **prefijo estático de string** y se rinde con lo que no pliega a literal, así que **no ve** `location.assign(Routes.HOME)` —un binding importado— mientras que **sí marca** la misma constante escrita como template literal, y **no cubre `replace()` en absoluto**. Una cobertura que depende de cómo se deletree el destino no es cobertura: un lint verde no prueba ausencia del patrón. Y #420 fijó como disparador el **tercer** VO de string único, criterio que la medición refuta por mecánico.

**Approach:** dejar de documentar el agujero y cerrarlo. #760: un selector `no-restricted-syntax` **propio** que casa por la **llamada** (`location.assign|replace`, `location.href =`), no por su argumento, de modo que todo salto de documento del árbol sea visible y cada uno argumente en su línea; los dos sitios existentes quedan con directiva **legítima** —la regla dispara de verdad en ambos— y el sitio de expiración de sesión pasa a `replace()`, que además saca del historial la página muerta. #420: **declinar** la abstracción con la evidencia medida y un disparador nuevo falsable; cero código.

## Boundaries & Constraints

**Always:** el argumento viaja con el código. Cada `eslint-disable-next-line` del selector nuevo lleva encima la razón, y esa razón dice **cuál de los dos casos legítimos** es (salir de zona autenticada / no hay contexto React). El selector **sin allowlist y sin excepciones en la config**: la excepción se declara en la línea que la necesita. `make pwa.quality` verde y **sin modificar** lo escrito.

**Ask First:** publicar en GitHub. Tocar `api/src`. Cambiar el comportamiento del sitio de logout (`BackOfficeLayoutClient`) — el cambio a `replace()` se autorizó **solo** para el bounce de sesión expirada.

**Never:** crear el trait `StringValue`, añadir `__toString()` o tocar los tres VO. Añadir líneas de excepción al selector en `eslint.config.mjs`. Un bloque `files:` nuevo para el selector — anularía los seis `no-restricted-syntax` existentes en esa ruta. Tocar lo reservado por las otras sesiones.

</frozen-after-approval>

## Code Map

- `pwa/eslint.config.mjs:54` — bloque `no-restricted-syntax` (severidad `error`) del config que cubre `**/*.{ts,tsx,js,jsx}`. Los dos selectores nuevos van **aquí**, no en un bloque propio.
- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts:63-70` — `redirectToLoginOnSessionExpiry()`: función libre de módulo (no la clase `@injectable()`, que está en :107). Bounce de sesión expirada.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:73-96` — `handleNavigation()`: logout hacia HOME. Su comentario de 8 líneas ya justificaba la navegación dura; **se editó** una frase para alinearla con la cota (`wait up to REVOKE_BUDGET_MS`).
- `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx` — tests del layout; el diff los modifica.
- `pwa/tests/context/shared/http-client/infrastructure/FetchHttpClient.test.ts:502-601` — mockea `location`; sigue al comportamiento.
- `api/src/Iam/Identity/Domain/{Email,HashedPassword}.php`, `api/src/Iam/Session/Domain/SessionId.php` — los tres VO. **No se modifican.**

## Tasks & Acceptance

**Execution:**
- [x] `pwa/eslint.config.mjs` — dos selectores: llamada `location.assign|replace` (cualquier receptor `location`) y asignación `location.href`. Mensaje que enuncia el invariante y los dos casos legítimos.
- [x] `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` — `assign` → `replace`; directiva del selector nuevo con la razón corregida (función libre de módulo, no adaptador DI; sin contexto React).
- [x] `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — directiva del selector nuevo. Se **borra** la explicación de por qué no había directiva: ya no aplica y era registro de la deliberación, no del código.
- [x] `pwa/tests/.../FetchHttpClient.test.ts` — el stub y las aserciones pasan a `replace`.
- [x] GitHub #420 — cerrado con la evidencia medida y el disparador nuevo (`not planned`).
- [x] Los 7 hallazgos preexistentes que sí son parches — cerrados con test propio, cada uno falsificado contra su arreglo. Ampliación de alcance autorizada explícitamente por el humano.
- [x] Cuerpo de la PR — revisión de seguridad por clase; hallazgos de la pasada adversarial; #420 cierra sin código; los 2 rediseños declinados con su argumento.

**Acceptance Criteria:**
- Dado el árbol, cuando corro `grep -Rn "no-location-assign-relative-destination" pwa/src`, entonces hay **0** coincidencias: la regla de Next ya no dispara en ningún sitio y ninguna directiva suya sobrevive sin uso.
- Dado el árbol, cuando busco `location.assign|replace` y `location.href =` en `pwa/src`, entonces cada coincidencia lleva directiva del selector nuevo y una razón encima.
- Dado el selector, cuando provoco cada uno de sus rojos —quitar la directiva de A, quitar la de B, un tercer `location.assign` con constante importada, un `location.href =`, un `window.location.replace`—, entonces **los cinco** dan `exit=2`. Un guardarraíl que no se ha visto rojo no está probado.
- Dado el cambio a `replace()`, cuando devuelvo el código a `assign()`, entonces el test se pone **rojo**. Un verde tras un renombrado puede ser vacuo.
- Dado el diff guardado antes de `make pwa.quality`, cuando el gate termina, entonces es **byte a byte el mismo**.
- Dado `api/src`, entonces `git diff --stat -- api/` está vacío.
- De los 7 arreglos preexistentes: **5** tienen test propio y cada uno se puso rojo al revertir **su** arreglo, medido uno a uno; **1** es una corrección de comentario, que ningún test puede falsificar; y **1 —el hoist de `setIsSidebarOpen(false)`— NO tiene test**: revertirlo deja la suite verde. La redacción anterior de este criterio («los 7, cada uno con su test») era falsa y la auditoría de aceptación la refutó. La primera versión del test del doble logout además era vacua: pasaba sin la guarda, porque el menú se cierra al primer clic y el segundo no alcanzaba ningún manejador; se reescribió para reabrir el menú.

## Spec Change Log

- **Iteración 1 — pasada adversarial (Blind Hunter + Edge Case Hunter).** Hallazgo disparador: el comentario del sitio B y el *Intent* de este spec afirmaban que la regla «resuelve el argumento estáticamente y no ve la constante». **Refutado midiendo**: una constante pelada (`"/"`) **sí** dispara; `Routes.HOME` escapa por ser un **binding importado** que `getStringIfConstant` no resuelve; y `` `${Routes.HOME}` `` dispara hoy. El comentario no solo era falso: enseñaba a silenciar un aviso legítimo metiendo la URL en una constante. Amendado el mecanismo en *Intent*, y el alcance para incluir el guardarraíl propio (autorizado por el humano) que hace innecesaria toda la asimetría directiva/comentario. Estado malo evitado: dejar en `main` un comentario que, siendo lo único que sostiene la decisión, enseña lo contrario de lo que ocurre. **KEEP:** la exigencia de idempotencia del gate y la de provocar los rojos; ambas siguen siendo lo que distingue una afirmación de una medición.

- **Iteración 2 — `bmad-code-review`, tres capas en paralelo (Blind Hunter, Edge Case Hunter, Acceptance Auditor).** Ninguna falló. Lo que refutó, medido:
  1. **El comentario de `eslint.config.mjs` es una premisa falsa.** La config no declara `languageOptions.globals`, así que el `isGlobalReference()` de la regla de Next solo resuelve `globalThis.`; `location.…`, `window.location.…` y `document.location.…` dan **cero** reportes con cualquier argumento. El discriminante ancho es el **receptor**, no el deletreo del destino. Efecto: `window.location.assign("/")` está sin vigilar en todo el repo.
  2. **El try/catch del transporte defiende un mecanismo imposible.** `Location.replace()` es cross-origin-callable y no comprueba seguridad; es `assign()` quien lanza `SecurityError`. Al pasar a `replace()` se eliminó la única llamada que podía lanzarlo, y un navegable en sandbox **ignora** la navegación en vez de rechazarla: el latch se queda en `true` y los 401 posteriores se tragan — justo lo que el catch dice impedir.
  3. **El gate tiene agujeros medidos** (`document.location = url`, alias, acceso computado, `.bind`, `Reflect.apply`, `location.reload()`, `window.open(u,"_self")` → 0 hits) y un **falso positivo sobre código de dominio** (`warehouse.location.replace(…)` → 1 hit), lo que en un ERP fuerza `eslint-disable` en líneas sin relación con navegación.
  4. **La cota de 3 s cancela su propio revoke** (no hay `keepalive` en el http-client), y al expirar deja la página autenticada a un Atrás de distancia porque `setSession(null)` no ha corrido.
  5. El comentario de `isLeaving` lo refuta **este mismo diff** (añadir `revoke-current` a la lista de handshake hace imposible el rebote que alega); `isLeaving` no tiene vía de liberación; `router.push` no está protegido durante la ventana; un test fuga un `setTimeout` real de 3 s.
  6. **Violación de proceso, registrada como tal:** los hallazgos de la iteración 1 entraron en este artefacto **nueve minutos después** de `gh pr create` (PR 21:30:44Z; commits 21:39:34 y 21:40:18), en trabajo de superficie de autenticación. El gate de `CLAUDE.md` pide que estén escritos **antes** de abrir.
  Los puntos 1-4 están abiertos como decisiones para el humano, consultados en paralelo con un modelo externo, Winston y Amelia. **KEEP** de la iteración 1 intacto y honrado.

## Design Notes

Medido en este worktree (ESLint 10.8.1, plugin 16.3.1, PHP 8.5.9):

1. La regla de Next marca un template literal cuyo prefijo estático es `""` (`isRelativeUrl("")` es cierto) y **falla** ante `Routes.HOME`, un `MemberExpression` sobre un import. Constante pelada `"/"` → dispara. `` `${Routes.HOME}` `` → dispara. `location.replace(...)` → **nunca** dispara.
2. Es `warn` en `recommended` y en `core-web-vitals`, y `pwa/package.json` corre `eslint .` sin `--max-warnings`: **nunca** pudo poner rojo ningún gate. Nuestro selector es `error`, que es lo que convierte la decisión en algo que CI sostiene.
3. Una directiva sin uso es warning propio (`Unused eslint-disable directive`) y `eslint --fix` la borra — medido. Por eso ninguna directiva del árbol apunta ya a la regla de Next: con `replace()` en A y el binding importado en B, no dispara en ningún sitio.

Para #420: solo **3** de las 7 clases con `toString(): string` son VO de string único (las otras 4 son compuestas), comparten ~12 líneas mecánicas y difieren en factoría, invariante y excepción. El barrido de `final readonly class` con propiedad string privada y sin `toString()` da **cero** candidatos ocultos. Y `readonly` cierra la forma obvia del trait: `Readonly class X cannot use trait with a non-readonly property` es fatal.

## Verification

**Commands:**
- `make pwa.lint.dry-run` — exit 0, sin warnings.
- Los cinco rojos del selector, uno a uno — cada uno `exit=2`.
- `make pwa.test.unit` — suite completa verde; y el A/B `replace`→`assign` en rojo.
- `make pwa.quality` — exit 0, e idempotente sobre un diff no vacío.
- `grep -Rn "no-location-assign-relative-destination" pwa/src` — 0.
- `git diff --stat -- api/` — vacío.
