---
title: 'GH #760 + #420 — guardarraíl propio para la navegación dura y declive del trait StringValue'
type: 'chore'
created: '2026-08-18'
status: 'done'
baseline_commit: '89c0b206ed95c03211831f0c8c3ee1193ddfc606'
review_loop_iteration: 3
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
- Dado el selector, cuando corro `tests/eslint/hardNavigationGate.test.ts`, entonces afirma **las dos direcciones** contra la config real importada: 8 positivos y 4 negativos de dominio, más los puntos ciegos declarados. Falsificado en ambas: quitar la rama del receptor tumba 4 positivos; ensancharlo a «cualquier objeto con `.location`» resucita el falso positivo. Sustituye a los cinco rojos provocados a mano, que probaban el gate una vez y en un artefacto que se borra.
- Dado el hoist de `setIsSidebarOpen(false)`, cuando lo revierto, entonces el caso de sign-out del test parametrizado del cajón se pone rojo y el de navegación normal sigue verde. Los **7** arreglos tienen ya test propio falsificado, menos el que es una corrección de comentario.
- Dado el cambio a `replace()`, cuando devuelvo el código a `assign()`, entonces el test se pone **rojo**. Un verde tras un renombrado puede ser vacuo.
- Dado el diff guardado antes de `make pwa.quality`, cuando el gate termina, entonces es **byte a byte el mismo**.
- Dado `api/src`, entonces `git diff --stat -- api/` está vacío.
- De los 7 arreglos preexistentes: **5** tienen test propio y cada uno se puso rojo al revertir **su** arreglo, medido uno a uno; **1** es una corrección de comentario, que ningún test puede falsificar; y **1 —el hoist de `setIsSidebarOpen(false)`— NO tiene test**: revertirlo deja la suite verde. La redacción anterior de este criterio («los 7, cada uno con su test») era falsa y la auditoría de aceptación la refutó. La primera versión del test del doble logout además era vacua: pasaba sin la guarda, porque el menú se cierra al primer clic y el segundo no alcanzaba ningún manejador; se reescribió para reabrir el menú.

### Review Findings

`bmad-code-review`, 2026-08-19. Tres capas en paralelo (Blind Hunter, Edge Case Hunter, Acceptance Auditor); ninguna falló. Severidad asignada aquí por consecuencia, descartando la que asignó cada capa.

**Decision (2) — resueltas por el humano, consultadas antes con un modelo externo, Winston y Amelia:**

- [x] [Review][Decision] **La cota de 3 s del sign-out** — resuelta como **(c) sola**: `replace()` en el sitio de sign-out, `keepalive` **declinado sobre medición** (el aborto no se reproduce en 4 configuraciones). Reabrible con evidencia de revokes que no llegan.
- [x] [Review][Decision] **Si el gate propio merece existir** — resuelta arreglando su **sujeto**, no su anchura: receptores globales enumerados, mensaje honesto, puntos ciegos en `pwa/CLAUDE.md`, y test que lo fija en las dos direcciones.

**Patch (17) — aplicados:**

- [x] [Review][Patch] El selector casaba cualquier objeto con `.location` — falso positivo sobre campo de dominio [`pwa/eslint.config.mjs`]
- [x] [Review][Patch] Agujero: `document.location = url` invisible [`pwa/eslint.config.mjs`]
- [x] [Review][Patch] Agujero: acceso computado `location["assign"](u)` invisible [`pwa/eslint.config.mjs`]
- [x] [Review][Patch] El mensaje afirmaba una cobertura total que la medición refuta [`pwa/eslint.config.mjs`]
- [x] [Review][Patch] El comentario de la config explicaba un mecanismo imposible (la regla de Next no resuelve receptor sin `globals`) [`pwa/eslint.config.mjs`]
- [x] [Review][Patch] El `catch` del transporte defendía un `SecurityError` que `replace()` no puede lanzar; residual declarado [`pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts:82`]
- [x] [Review][Patch] El comentario de `isLeaving` alegaba un rebote que este mismo diff hizo imposible [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:76`]
- [x] [Review][Patch] `isLeaving` no tenía vía de liberación — logout muerto en ese documento [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:103`]
- [x] [Review][Patch] `router.push` sin proteger durante la ventana de salida [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:108`]
- [x] [Review][Patch] El sign-out usaba `assign()`, dejando la página autenticada a un Atrás [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:101`]
- [x] [Review][Patch] `afterMs` tiraba el handle del timer — 3 s retenidos incluso en el camino feliz [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:33`]
- [x] [Review][Patch] Un test dejaba un `setTimeout` real de 3 s sin cancelar [`pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx:156`]
- [x] [Review][Patch] El A/B del transporte estaba sobredeterminado: fallaba por `TypeError` de forma-de-stub, no por su aserción [`pwa/tests/context/shared/http-client/infrastructure/FetchHttpClient.test.ts:523`]
- [x] [Review][Patch] Un test decía fijar un `SecurityError` que no fija [`pwa/tests/context/shared/http-client/infrastructure/FetchHttpClient.test.ts:604`]
- [x] [Review][Patch] El arreglo del sidebar no tenía test; añadido parametrizado sobre las dos ramas [`pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx`]
- [x] [Review][Patch] El AC de «los 7 arreglos con test propio» era falso; corregido a 5 + 1 comentario + 1 sin test (ya cubierto)
- [x] [Review][Patch] Artefacto y PR desalineados: `Code Map`, `baseline_commit` pre-rebase, contradicción del cuerpo de PR sobre el sitio de logout, el sitio B sin nombrar su caso legítimo, línea de 101 caracteres, y `pwa/CLAUDE.md` sin su párrafo de puntos ciegos

**Defer (7) — preexistentes o fuera de alcance:**

- [x] [Review][Defer] El logout está acoplado al **destino** del ítem de menú, no a su intención — issue #771
- [x] [Review][Defer] La UI de error del 401 se pinta durante la ventana de unload — issue #772
- [x] [Review][Defer] `eslint.config.mjs` no declara `languageOptions.globals`, dejando una regla de Next inerte en todo el repo — issue #773
- [x] [Review][Defer] La cota vive en la capa de presentación; el invariante «ninguna petición cuelga» es del transporte [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:28`]
- [x] [Review][Defer] Sin afordancia de «saliendo» durante hasta 3 s: el menú se cierra y no hay señal [`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx`]
- [x] [Review][Defer] Si la navegación es **ignorada** (sandbox) no se lanza nada, así que el latch sobrevive y los 401 posteriores se tragan — residual declarado en el comentario [`pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts:82`]
- [x] [Review][Defer] Puntos ciegos irreductibles del selector: receptor con alias, `location.reload()`, `window.open(u,"_self")` — documentados, no corregibles con un selector sintáctico

**Dismissed (5)** — ruido o refutados midiendo: «la cota cancela el revoke» (no se reproduce en 4/4 configuraciones); `logout()` puede rechazar y producir una unhandled rejection (`AuthProvider` captura y tiene `finally`, no puede); colisiones de `endsWith` con `revoke-current` (medidas: ninguna); `/login/` con barra final (Next 308-redirige); el asunto del commit sin referencia a issue (el `(#NNN)` del log es el número de PR del squash, y el check B de `bmad.status.audit` va de tags de historia, que aquí no hay).

**Violación de proceso, registrada porque no tiene parche:** los hallazgos de la iteración 1 entraron en este artefacto **nueve minutos después** de `gh pr create`, en trabajo de superficie de autenticación. El gate de `CLAUDE.md` los quiere escritos **antes** de abrir.

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

- **Iteración 3 — `bmad-code-review` (3 capas) + consulta a un modelo externo, Winston y Amelia.** Dos decisiones cerradas con medición propia, y **dos premisas refutadas, una de ellas de un revisor**:
  - **D1 → (c) sola. `keepalive` declinado.** La afirmación «la navegación a los 3 s aborta el POST en vuelo» **no se reproduce**: sondeo con servidor propio de mismo origen y handler que duerme 4 s, navegando en el mismo tick. Cuatro configuraciones —con y sin `keepalive`, red local y con 3 s de latencia + subida estrangulada por CDP— y en **las cuatro** el POST llega y el servidor completa. En una, la navegación aterriza antes de que el POST llegue siquiera. Era un precondicionante («no hay `keepalive`»), no una consecuencia observada. Queda la descomposición: con servidor lento y red normal —el caso que hace saltar la cota— la petición ya llegó y solo se cancela la lectura de la respuesta, así que la revocación se compromete igual. Un negativo no prueba que el aborto nunca ocurra: no se pudo construir el caso de petición genuinamente sin enviar. Reabrible con evidencia de revokes que no llegan.
  - **D2 → arreglar el *sujeto* del selector, no su anchura** (opción que ninguna de las cuatro del dosier contemplaba). Los falsos positivos no venían de ser ancho sino de casar *cualquier* objeto con `.location`, que en un ERP es medio dominio. Enumerando los receptores globales: FP de dominio a **0** y dos agujeros cerrados (`document.location = u`, acceso computado), medido sobre el árbol entero. Enumerar el sujeto **no es** una allowlist de exenciones.
  - **Premisa mía, refutada por Winston:** cité «una regla que necesita allowlist está mal escrita» como estándar general del repo. Es sobre dependency-cruiser; `pwa/CLAUDE.md:83,158` documenta a los selectores hermanos como *syntactic heuristic* cuya *completeness rests on review*. El estándar real es declarar los puntos ciegos con la misma precisión que la cobertura — de ahí el párrafo nuevo en `pwa/CLAUDE.md`. Ese error viajó al dosier y ChatGPT lo heredó.
  - **Medido y descartado:** declarar `languageOptions.globals` no enrojece nada (exit 0) y despierta la regla de Next en los cuatro receptores — pero no crea gate mientras sea `warn` bajo `eslint .` sin `--max-warnings`, y sigue sin ver `replace` ni destinos no literales. **PR aparte.** `no-restricted-properties` no puede sustituir a los selectores: alcanza `location.assign` pelado pero no `globalThis.location.assign`, que es nuestro código.
  - El `catch` del transporte defendía un `SecurityError` que `replace()` no puede lanzar (es `assign()` quien lo lanza, y un navegable en sandbox *ignora* la navegación sin excepción). Comentario corregido y **residual declarado**: el caso ignorado no lo cubre nadie.

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
