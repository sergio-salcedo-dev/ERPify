---
title: 'Cierre de las decisiones que dejó abiertas la PR #770 — #771, #773, defers y el gate adversarial'
type: 'chore'
created: '2026-08-19'
baseline_commit: 'f438fe15a24371c9f251d424c89284366288112d'
status: 'done'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** la PR #770 dejó siete decisiones vivas y un artefacto `done` que `/prune-done-specs` puede borrar en cualquier momento, llevándose lo único que registra que el gate de la pasada adversarial falló por tercera vez. Además dejó dos defectos medidos: el sign-out se dispara por el **destino** de una entrada de menú y no por su intención —y llega al manejador desde tres superficies, una de ellas a través de un componente de la capa de diseño que sólo reenvía el path—, y los selectores de navegación dura **no ven un miembro `location` computado**, forma que hoy sólo vigila una regla de Next inerte para cuatro de sus cinco receptores.

**Approach:** cerrarlas por la vía más barata que cada medición sostiene. Destilar el registro de proceso a `CLAUDE.md` y abrir el issue del mecanismo **antes** de podar el artefacto; **ensanchar** los selectores propios al miembro computado y sólo entonces declarar los globals y apagar la regla de Next, que así no retira cobertura; y dar a la entrada de menú un discriminador de intención que cruce hasta las tres superficies, con guardián sobre todo el modelo y afordancia de salida. #772 sale del lote: su arreglo toca un punto de montaje que tapa el back-office entero y necesita su propia revisión.

## Boundaries & Constraints

**Always:** los selectores sólo se ensanchan con la medición de sus dos direcciones — cobertura nueva **y** falso positivo de dominio en 0. Cada issue nace con su medición dentro. El registro durable del gate aterriza en la rama **antes** de que exista la PR, y este artefacto lleva sus propios hallazgos adversariales antes de `gh pr create`. `deferred-work.md` es sólo-pendientes: una bala que se hace issue se borra, no se anota. Todo A/B de reversión debe fallar **por su aserción**, nunca por un `TypeError` de forma-de-stub ni porque el DOM rechazara el clic.

**Ask First:** tocar `api/`. Añadir dependencias a `pwa/package.json`. Cambiar el contrato de error de `HttpClient`. Ensanchar el selector más allá del miembro computado. Que `SidebarItem` importe cualquier cosa de `@/app/**`.

**Never:** importar el paquete `globals` — no está en el lock y `npm ci` lo borra. Apagar la regla de Next **antes** de que los selectores cubran el miembro computado. Implementar #772 en esta PR. Mergear a `main`.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Comportamiento esperado | Manejo de error |
|---|---|---|---|
| Sign-out, cualquiera de las 3 superficies | Se pulsa la entrada con `action: "sign-out"` en barra lateral, cajón móvil o desplegable | Revoca y navega a `Routes.HOME` con `replace()` | Si el revoke falla o agota los 3 s, navega igual |
| Entrada nueva a `/` sin intención | Una entrada apunta a `Routes.HOME` y **no** lleva `action` | `router.push("/")`, la sesión **sobrevive** | N/A |
| Segundo sign-out en vuelo | `isLeaving` puesto | Se descarta; las tres superficies muestran `disabled` + «Signing out…» | N/A |
| Intención fuera del menú de cuenta | Un sub-ítem de `backofficeMenuGroups` declara `action: "sign-out"` | El guardián **falla**: la intención vive en una sola entrada | N/A |
| Navegación dura con miembro computado | `globalThis["location"].assign(u)` | Los selectores propios reportan a `error` | N/A |
| Campo de dominio, computado o no | `warehouse.location.replace(u)`, `site["location"].assign(u)` | **Ningún** reporte | N/A |

</frozen-after-approval>

## Code Map

- `CLAUDE.md` — *Security review* → **Process**, primera bala: cita #616 y #620; ahí entra la tercera ocurrencia.
- `_bmad-output/implementation-artifacts/spec-gh-760-420-nav-lint-y-string-value.md` — el artefacto a podar; `deferred-work.md:3` es hoy su única referencia y desaparece con la sección.
- `_bmad-output/implementation-artifacts/deferred-work.md:3-8` — encabezado y cuatro balas.
- `pwa/eslint.config.mjs:24-31` (`languageOptions`), `:41-45` (spreads de Next, tras los cuales va el `"off"`), `:56-79` (los dos selectores de navegación).
- `pwa/tests/eslint/hardNavigationGate.test.ts:18-22` — `navigationErrorsIn` filtra `ruleId === "no-restricted-syntax"`: **no puede observar** la regla de Next, hace falta un segundo contador.
- `pwa/CLAUDE.md:82` — afirma como hecho medido que la config «declares no `languageOptions.globals`». G2 lo vuelve falso.
- `pwa/src/app/backoffice/_lib/backofficeMenu.ts:59-64` (`NavSubItem`), `:248-251` (el docblock que enseña el acoplamiento), `:284` (la hoja Logout).
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:79`, `:121`, `:122` — la igualdad por destino. **Cinco** sitios reenvían al manejador: `:187` y `:205` (vía `SidebarItem`), `:275`, `:320` (cajón móvil), `:452` (desplegable).
- `pwa/src/components/erpify/SidebarItem.tsx:7-13` (`SubItem` local), `:20` (`onClick: (path: string) => void`), `:58`, `:104` — la barra lateral de escritorio. Reenvía **sólo el path**; y como el segundo parámetro sería opcional, `tsc` no detecta la omisión.
- `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx:70`, `:72` — el arnés repite la igualdad por destino; si no migra, el A/B mide el arnés. `:161-183` — el test del doble sign-out, que la afordancia `disabled` vuelve vacuo (Base UI `Menu.Item` no dispara `onClick` deshabilitado). `:212-229` — el test móvil, hoy vacuo: sólo afirma que el cajón se cierra, y `setIsSidebarOpen(false)` corre antes de las dos ramas.

## Tasks & Acceptance

**Execution:**
- [x] `CLAUDE.md` — tercera ocurrencia con su cronología y el matiz que la distingue de #616/#620: allí la pasada llegó en **otra PR**; aquí llegó en la misma, nueve minutos tarde, mientras el cuerpo afirmaba conformidad. Es el modo de fallo *siguiente* al que la regla endurecida ya cubría.
- [x] GitHub — issue del mecanismo del gate, con las tres ocurrencias medidas y las opciones (hook `PreToolUse` sobre `gh pr create`, comando `/open-pr`, o nada) con su coste.
- [x] GitHub — los cuatro issues de los defers, cada uno con la medición de su bala. El de la afordancia se cierra en esta PR; el del punto ciego del alias nace declarando que es un **límite estructural registrado**, no trabajo pendiente.
- [x] `deferred-work.md` — borrar las cuatro balas y su encabezado, **una a una y sólo contra la URL de su issue ya creado**.
- [x] `spec-gh-760-420-nav-lint-y-string-value.md` — borrar, después de todo lo anterior.
- [x] `pwa/eslint.config.mjs` — ensanchar ambos selectores al miembro `location` computado; declarar `languageOptions.globals` inline con los seis receptores; `"@next/next/no-location-assign-relative-destination": "off"` con el comentario que enuncia la medición de las dos tablas.
- [x] `pwa/tests/eslint/hardNavigationGate.test.ts` — segundo contador para la regla de Next; corpus de sondeo como array literal; y la aserción de **contención** con la regla de Next forzada a `error`: para toda fixture, si Next reporta entonces los selectores propios también. Eso sí puede ponerse rojo.
- [x] `pwa/CLAUDE.md` — corregir la cláusula de `languageOptions.globals`, añadir la cobertura del miembro computado y dejar los puntos ciegos que siguen vivos.
- [x] `pwa/src/app/backoffice/_lib/backofficeMenu.ts` — `NavSubItem` gana `action?: "sign-out"`; la hoja Logout lo declara; se borra el párrafo del docblock que enseña el acoplamiento.
- [x] `pwa/src/components/erpify/SidebarItem.tsx` — `onClick: (path: string, action?: "sign-out") => void`, el `SubItem` local gana el campo, y un `isBusy` opcional que se pinta como `aria-disabled` — nunca `disabled`, que impediría al clic alcanzar la guarda y la volvería infalsificable. Sin importar nada de `@/app/**`.
- [x] `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — los tres sitios de la igualdad ramifican por `action`; las cinco llamadas reenvían la intención; la afordancia llega a las tres superficies.
- [x] `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx` — migrar `:70` y `:72` a `action`; guardián sobre **todo** el modelo (`backofficeMenuGroups` + `accountMenuItem`); `it.each` de sign-out sobre los tres testids afirmando `logout` una vez y `replace` con `Routes.HOME`; reapuntar el test del doble sign-out a una superficie que la afordancia no deshabilita; y dar aserciones reales a la rama `isSignOut` del test móvil.
- [x] GitHub #772 — enriquecerlo con las siete mediciones nuevas (binding condicional que apagaría G4 en producción, ciclo `Container`↔`FetchHttpClient`, punto de publicación frente al `try`, replay, alcance real de `RequireAuth`, `MockHttpClient` bajo Vitest, y que el evento debe ir **sin payload**). Queda abierto.
- [x] **Correr la pasada adversarial sobre el código** y escribir sus hallazgos en la sección *Adversarial pass* de este artefacto, **antes** de `gh pr create`.
- [x] Cuerpo de la PR — revisión de seguridad por clase; `Closes #771`, `#773` y `#785`; y dónde quedó registrada la pasada. #784, #786 y #787 quedan **abiertos**: los dos primeros son trabajo propio y el tercero es un límite registrado que no se cierra.

**Acceptance Criteria:**
- Dado `CLAUDE.md`, cuando leo la bala de proceso, entonces enuncia tres ocurrencias y distingue el modo de fallo de #770 del de #616/#620. Y `git log origin/chore/pwa-close-770-open-decisions-icvf -1 --format=%cI -- CLAUDE.md` es **anterior** al `createdAt` de la PR.
- Dada la sección *Adversarial pass* de este artefacto, entonces su commit en la rama remota es **anterior** al `createdAt` de la PR — el mismo par comprobable que el criterio anterior, y el que hace de esta PR la primera que no repite la violación que documenta.
- Dado el árbol, cuando corro `git grep -F spec-gh-760-420-nav-lint-y-string-value -- ':(exclude)_bmad-output/implementation-artifacts/spec-gh-771-772-773-*'`, entonces **0** coincidencias, y el fichero no existe.
- Dado `deferred-work.md`, cuando corro `git diff --numstat origin/main -- _bmad-output/implementation-artifacts/deferred-work.md`, entonces **0 añadidas y 7 borradas** (encabezado, blanca, cuatro balas y la blanca de cierre), y el texto `Ref:` de cada bala borrada aparece en el cuerpo de un issue abierto.
- Dado el corpus del gate test, cuando corro los siete deletreos de receptor más las cuatro formas computadas, entonces los selectores propios reportan **11** y —con la regla de Next forzada a `error`— **toda** línea que Next reporta está entre las que ellos reportan. Estrechar el selector al miembro no computado tumba esa contención.
- Dado `warehouse.location.replace(u)` y `site["location"].assign(u)`, entonces **ningún** selector reporta. Ensanchar el receptor a «cualquier objeto con `.location`» pone este caso rojo.
- Dado el menú, cuando pulso Logout en la **barra lateral de escritorio** (testid `bo-layout__sidebar-logout`, hoy sin ningún test), entonces `logout` se llama una vez y `replace` recibe `Routes.HOME`; y lo mismo por `--mobile` y `--menu`. Quitar el reenvío de `action` en cualquiera de las tres pone **su** caso rojo.
- Dado el modelo, cuando añado `action: "sign-out"` a un sub-ítem de `backofficeMenuGroups`, entonces el guardián falla; y cuando se lo quito a la hoja Logout, también — fallando por su aserción, no al cargar el módulo.
- Dado el guard del doble sign-out, cuando borro `if (isLeaving) return`, entonces su test se pone **rojo** — lo que exige que no lo pruebe sobre un elemento que la afordancia deshabilita.
- Dado `pwa/src/app/backoffice/_lib/backofficeMenu.ts`, entonces `git grep -c 'which is what makes the layout' pwa/src` es **0**.
- Dado el árbol final, entonces `make pwa.quality.dry-run` —el target que corre CI— sale **0** desde una corrida fresca con su exit code impreso, ya committeado; `make pwa.test.unit` sale 0; y `git diff --stat origin/main...HEAD -- api/ pwa/package.json pwa/package-lock.json` está vacío.

## Adversarial pass

Issues abiertos por esta PR: **#783** (mecanismo del gate), **#784** (cota de transporte), **#785** (afordancia, que esta PR cierra), **#786** (latch residual), **#787** (punto ciego del alias). #772 enriquecido con siete mediciones y **abierto**.

Corrida sobre el código antes de `gh pr create`, dos capas en paralelo con contexto fresco y sólo-lectura (`bmad-review-adversarial-general` y `bmad-review-edge-case-hunter`), sobre `git diff f438fe15..HEAD`. Ninguna falló; ~23 hallazgos distintos. **No confirmó el cambio: lo cambió.**

**Las dos que derriban una afirmación mía, ambas verificadas a mano después:**

1. **El selector de asignación se volvió un producto cruzado.** Al factorizarlo en dos `:matches()` independientes, `href` pasó a emparejarse con *cualquier* receptor de la enumeración: `parent.href = u`, `top.href`, `self.href` y `document.href` reportaban **1** donde `main` reportaba **0**. Son asignaciones de dominio ordinarias, y su `eslint-disable` es rule-wide — apagaría también `maxLength` y el contrato de test-id en esa línea, que es literalmente el coste que el comentario nuevo cita para apagar la regla de Next. Mi corpus no lo vio porque sólo probé `anchor.href`. Reescrito como ocho alternativas explícitas con `href` acoplado a un objeto `location`; reintroducir el producto cruzado enrojece los tres negativos nuevos.
2. **El test de contención era circular.** `seenByUpstream = POSITIVES.filter(...)` toma su universo de la lista que dos tests antes se afirma que los selectores marcan, así que una forma que la regla de Next reporte y los selectores no **jamás podía entrar en él**. La afirmación principal de G2 descansaba en un test estructuralmente incapaz de encontrar un hueco. Reescrito sobre una rejilla generada (7 receptores × 5 deletreos + asignaciones de `location`), independiente de `POSITIVES`: al retirar la rama computada, ahora **nombra los 8 huecos** en vez de pasar.

**Parches aplicados (10):** los dos de arriba; el `key` de React derivado del rótulo, que destruía el botón recién pulsado y tiraba el foco a `<body>` (fijado con una aserción de identidad de nodo); `aria-disabled="false"` emitido en los hermanos; `accountLinks` filtrando por `action === undefined` en vez de `!== "sign-out"`, que ocultaría cualquier acción futura; dos comentarios que afirmaban que `path` decide dónde aterriza el sign-out cuando el código lo tiene hard-codeado; la afordancia sin aserción en dos de las tres superficies (borrarla de cualquiera dejaba la suite verde — ahora `it.each` por superficie, cada una falsificada); una región `role="status"` porque en las dos superficies cuyo menú se cierra la entrada se desmonta antes de poder decir nada, mientras la guarda descarta en silencio toda otra navegación; el comentario de `globals`, que llamaba «entorno» a seis nombres; y dos puntos ciegos medidos que la documentación no nombraba (clave computada por template literal, y cadenas de receptor de más de un nivel — `window.top.location.assign(u)` escapa mientras `top.location.assign(u)` sí dispara).

**Coste conocido, ahora explícito:** `parent.location.replace(…)` es un falso positivo **preexistente** (1 en `main` y en HEAD) porque `parent`/`top`/`self`/`document` son receptores cross-frame reales *y* nombres de variable ordinarios; la rama computada lo extiende a `parent["location"]`. Fijado como test que **pasa**, para que estrechar la enumeración más adelante sea una decisión y no un descuido — estrecharla silenciaría `top.location.assign(u)`, que es navegación real.

**Rechazados (2), midiendo:** «la cota de 3 s mata el revoke» — es exactamente la afirmación que #770 refutó con un sondeo en cuatro configuraciones donde el POST llegó y el servidor completó en las cuatro; la capa la razonó desde el precondicionante («no hay `keepalive`»), sin evidencia de revokes que no lleguen, que es el disparador declarado para reabrirla. Y «`logout()` puede rechazar y producir una unhandled rejection» — `AuthProvider` captura y tiene `finally`; #770 ya lo descartó y añadir un `.catch` vacío sería código defensivo para un caso imposible.

**Diferidos (3), preexistentes:** la barra lateral compacta no renderiza sub-ítems, así que no tiene hoja de sign-out (sin cambio en este diff); el `catch` de recuperación sólo significa algo en el camino de presupuesto agotado (comentario estrechado, comportamiento intacto); y el cajón móvil se cierra antes de la guarda, así que durante la ventana traga el clic — mitigado por la región de estado. La pasada sobre **este spec** (tres capas de contexto fresco y sólo-lectura: Blind Hunter, Edge Case Hunter, Acceptance Auditor) está registrada en el *Spec Change Log*, iteración 1.

## Spec Change Log

- **Iteración 1 — pasada adversarial sobre el spec, tres capas en paralelo.** Ninguna falló; entre las tres, ~30 hallazgos distintos. Lo que refutó, medido y verificado a mano después:
  1. **La premisa central de G2 era falsa.** «La regla de Next no caza nada que los selectores propios no cacen» se sostenía en un sondeo que probó el acceso computado al **método** (`location["assign"]`) pero no al **objeto**. Medido: `globalThis["location"].assign(u)` y `globalThis["location"].href = u` los reporta **sólo** la regla de Next, porque nuestros selectores casan `[callee.object.property.name='location']` y en un miembro computado la propiedad es un `Literal` con `.value`. Apagarla habría sido pérdida neta de cobertura, y declarar los globals la habría extendido a `window[…]`/`document[…]` justo antes de retirarla. Ensanchar los selectores primero: **+2 formas a `error`, 0 falsos positivos de dominio nuevos**.
  2. **El sign-out se rompía en la barra lateral de escritorio, en silencio.** `SidebarItem.tsx:104` reenvía sólo el path, y un segundo parámetro **opcional** sigue siendo asignable a `(path: string) => void`, así que `tsc` queda verde; ningún test toca el testid pelado. Ramificar por `action` sin cruzar a la capa de diseño dejaba «Logout» navegando a `/` **sin revocar**, con cookie viva.
  3. **La afordancia habría vuelto vacuo el guard que #770 ya arregló una vez.** Base UI `Menu.Item` no dispara `onClick` deshabilitado, así que la segunda pulsación del test del doble sign-out no llegaría al manejador y el test pasaría con `if (isLeaving) return` borrado.
  4. **Cuatro criterios eran vacuos o insatisfacibles:** el `git grep` de la poda casa contra el propio spec; «la regla de Next reporta 0» es tautología sobre una regla en `off`; `git diff` sin revisión está vacío tras committear, que es cuando la regla de push aplica; y el criterio de `deferred-work.md` se cumplía reordenando el fichero y con **cero** issues abiertos.
  5. **G4 era más caro de lo escrito**, en siete direcciones medidas — entre ellas que espejar el binding de `DebugTokenObserver` liga el **no-op en producción** (`isDevToolsAvailable()` es `NODE_ENV !== "production"`), que resolver el observador desde `Container` dentro de `FetchHttpClient` es un ciclo que `no-circular` reprueba en CI, y que `RequireAuth` no tapa un área de contenido sino **todo** su subárbol, que es el back-office entero. Sacado del lote por decisión del humano; las mediciones van a #772.
  6. **La PR no cumplía el gate que documenta.** El registro de la pasada vivía en el cuerpo de la PR, que por construcción no puede preceder a la PR. Ahora vive en este artefacto, con un criterio comprobable a posteriori (fecha del commit en la rama remota contra `createdAt`).
  **KEEP:** la exigencia de que todo A/B falle por su aserción, y la de medir las dos direcciones de un selector — cobertura y falso positivo de dominio. Son lo que distinguió aquí una afirmación de una medición, dos veces.

## Design Notes

Medido en el worktree, instalación fiel al lock (`npm ci`), por stdin contra la config real.

**Receptor** (`.assign("/")`, salvo la fila de `replace`):

| Forma | Baseline | Con globals inline | Selectores propios |
|---|---|---|---|
| `window` / `document` / `self` / `location` | 0 de 4 | 4 de 4 (`warn`) | 4 de 4 (`error`) |
| `globalThis` | 1 (`warn`) | 1 (`warn`) | 1 (`error`) |
| `globalThis.location.href = "/"` | 1 (`warn`) | 1 (`warn`) | 1 (`error`) |
| `globalThis.location.replace("/")` | 0 | 0 | 1 (`error`) |
| `frames` / `opener` | 0 | 0 (ni declarándolos) | 0 |
| alias `const l = location` | 0 | 0 | 0 |

**Miembro `location` computado** — la tabla que faltaba y que invierte la conclusión:

| Forma | Baseline | Con globals | Selectores actuales | Ensanchados |
|---|---|---|---|---|
| `globalThis["location"].assign("/")` | NEXT | NEXT | **ninguno** | **OURS** |
| `globalThis["location"].href = "/"` | NEXT | NEXT | **ninguno** | **OURS** |
| `window["location"].assign("/")` | 0 | NEXT | **ninguno** | **OURS** |
| `warehouse.location.replace(u)` | 0 | 0 | 0 | **0** |
| `site["location"].assign(u)` | 0 | 0 | 0 | **0** |

El paquete `globals` no está en `package.json` ni en `package-lock.json`: en el primario existe sólo como residuo *extraneous* de `eslint-config-next`, y en instalación fiel `import globals from "globals"` da `ERR_MODULE_NOT_FOUND`. De ahí el objeto literal.

Para G3, los consumidores reales de `backofficeMenu` son dos, no cuatro: `sectionTitle.ts` importa `sectionTitleRules` y `roadmap.ts` sólo lo menciona en un comentario.

## Verification

**Commands:**
- `make pwa.quality` — pasada que arregla; luego `make pwa.quality.dry-run` **ya committeado**, que es lo que corre CI (`ci.yml:253`), con su exit code impreso.
- Idempotencia por hash, no por diff: `find pwa/src pwa/tests pwa/eslint.config.mjs -type f | sort | xargs sha256sum | sha256sum` antes y después.
- `make pwa.test.unit` — verde; y cada A/B de reversión en rojo, comprobado uno a uno y por su aserción.
- Sondeo por stdin: 7 receptores + 4 formas computadas + 2 negativos de dominio.
- `git grep -F spec-gh-760-420-nav-lint-y-string-value -- ':(exclude)…spec-gh-771-772-773-*'` — 0.
- `git diff --numstat origin/main -- …/deferred-work.md` — `0 7`.
- `git diff --stat origin/main...HEAD -- api/ pwa/package.json pwa/package-lock.json` — vacío.
- `gh issue view <n> --json body` por cada issue nuevo — contiene la medición de su bala.

## Suggested Review Order

**El invariante del lint, y por qué el orden es el arreglo**

- El punto de entrada: la regla de Next apagada, con la medición que lo permite.
  [`eslint.config.mjs:74`](../../pwa/eslint.config.mjs#L74)

- Las ocho alternativas explícitas. Factorizarlas creó un producto cruzado que enrojecía `parent.href`.
  [`eslint.config.mjs:104`](../../pwa/eslint.config.mjs#L104)

- La contención sobre rejilla generada — no sobre `POSITIVES`, que la volvía circular.
  [`hardNavigationGate.test.ts:141`](../../pwa/tests/eslint/hardNavigationGate.test.ts#L141)

- El coste que se acepta: un local llamado como un global entra igual. Test que **pasa**.
  [`hardNavigationGate.test.ts:104`](../../pwa/tests/eslint/hardNavigationGate.test.ts#L104)

- Seis nombres, no un entorno: lo que la declaración es y lo que no.
  [`eslint.config.mjs:42`](../../pwa/eslint.config.mjs#L42)

**El sign-out por intención**

- El discriminador. Sin él, el destino era el disparador.
  [`backofficeMenu.ts:64`](../../pwa/src/app/backoffice/_lib/backofficeMenu.ts#L64)

- La rama: `action`, nunca `path`. El destino de abajo está hard-codeado.
  [`BackOfficeLayoutClient.tsx:85`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L85)

- El contrato de la capa de diseño se ensancha; sin ella el sidebar no revocaba.
  [`SidebarItem.tsx:31`](../../pwa/src/components/erpify/SidebarItem.tsx#L31)

- `key` por identidad: derivarlo del rótulo destruía el botón recién pulsado.
  [`SidebarItem.tsx:118`](../../pwa/src/components/erpify/SidebarItem.tsx#L118)

- Una derivación para las tres superficies, y lo que eso **no** compra.
  [`BackOfficeLayoutClient.tsx:142`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L142)

- La región que sí habla cuando el menú ya se cerró sobre la entrada.
  [`BackOfficeLayoutClient.tsx:169`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L169)

**Lo que fija la regresión**

- El señuelo: una entrada a `/` sin intención no revoca.
  [`backOfficeLayoutSignOutIntent.test.tsx:84`](../../pwa/tests/app/backoffice/backOfficeLayoutSignOutIntent.test.tsx#L84)

- El guardián, en fichero propio para que falle por su aserción y no al cargar.
  [`backofficeMenu.test.ts:23`](../../pwa/tests/app/backoffice/backofficeMenu.test.ts#L23)

**El registro de proceso**

- La tercera ocurrencia, y dónde vive el guardarraíl que esta prosa no es.
  [`CLAUDE.md:183`](../../CLAUDE.md#L183)
