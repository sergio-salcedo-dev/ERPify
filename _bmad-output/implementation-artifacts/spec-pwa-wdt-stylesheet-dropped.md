---
title: 'El toolbar de Symfony pinta sin estilos sobre toda la pantalla: la hoja se pierde al montar el fragmento'
type: 'fix'
created: '2026-09-01'
status: 'in-review'
review_loop_iteration: 1
context: []
baseline_commit: '31423b68'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema.** El frontend estaba roto en dev: sobre cualquier pantalla se pintaba el Web Debug
Toolbar de Symfony **sin estilos**, como texto plano, tapando el viewport. Medido contra el stack
vivo antes de tocar nada: el host del toolbar medía **1045 px en un viewport de 1047** (99,8 %), y
`/_wdt/styles` no aparecía en ninguna parte del documento.

**Causa raíz.** `symfony/web-profiler-bundle` **v8.1.4** movió la hoja de estilos del toolbar desde
una inyección en runtime por Sfjs a un `<link>` emitido al principio de `toolbar_js.html.twig`,
delante del markup, para que el toolbar «nunca se pinte sin estilos». Medido contra los tags de
upstream: v8.1.1 y v8.1.2 abren por el `<div class="sf-toolbar">`; v8.1.4 abre por el `<link>`
(v8.1.3 no existe). `mountFragment` leía sólo `parsed.body.childNodes`, y el parser HTML **hoistea
un `<link>` inicial al `<head>`** — así que desde el bump v8.1.1 → v8.1.4 (2026-08-13, *«chore(deps):
batch ten dependabot bumps across actions, npm and composer»*) la hoja se descartaba en silencio.

**Por qué nada lo vio.** Los seis tests que ya existían alimentan un fixture *body-only* (cuatro de
ellos; los otros dos no alimentan ninguno), así que `parsed.body` no podía perder nada por
construcción. Y `app/layout.tsx:41` suprime el toolbar bajo Playwright a propósito, para que su DOM
no infle los locators de e2e. La superficie no tenía a nadie mirándola.

**No es una story.** No existe artefacto previo: la issue #262 y las PRs #294/#311 son el historial
cerrado de haber construido este componente. Este spec se crea para llevar el registro del pase
adversarial, que es lo que la PR necesita en la rama antes de abrirse.

</frozen-after-approval>

## Boundaries & Constraints

- **Dev-only.** El componente se monta tras `isDevToolsAvailable()` (`NODE_ENV !== "production"`) y
  se elimina por dead-code del build de producción. En prod el contenedor liga un
  `NoopDebugTokenObserver` y la API no emite cabeceras de debug-token.
- **`reviveNode` ejecuta scripts a propósito** — un `<script>` parseado o clonado es inerte por
  especificación, así que se recrea para que corra. El cambio **amplía** lo que se monta y por tanto
  lo que se ejecuta: ahora también los nodos que el parser hoistea al `<head>`.
- **La premisa de confianza es explícita y preexistente**: origen same-origin, dev-only, salida de
  nuestro propio Symfony, ruta acotada a `[\w-]+`. Es la misma que ya justificaba evitar `innerHTML`.
- **No se toca la CSP.** `style-src 'self' 'unsafe-inline'` ya admite la hoja same-origin.

## Code Map

| Fichero | Cambio |
|---|---|
| `pwa/src/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.tsx` | `mountFragment` monta `[...parsed.head.childNodes, ...parsed.body.childNodes]`; el latch sólo se levanta si se montó algún elemento; telemetría cuando la hoja no carga |
| `pwa/tests/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.test.tsx` | Gate: fixture con la forma real del loader, precondición de hoisting pinneada, aserción por identidad |
| `api/tests/Functional/.../WebDebugToolbarLoaderFunctionalTest.php` | Afirma el acoplamiento de la hoja contra la **plantilla real** |
| `docs/architecture-pwa.md` | Corrige «on each new token fetches» (falso desde #311) y documenta el orden head-first |
| `pwa/CLAUDE.md` | Recoge el hoisting en la viñeta que ya posee las reglas de montaje |

## Tasks & Acceptance

- [x] `mountFragment` monta los nodos del `head` por delante de los del `body`.
- [x] Verificado en navegador real contra el loader vivo de 48 561 bytes: la hoja entra en
      `document.styleSheets` y el host pasa de 1045 px a 0 px.
- [x] Gate falsificado: revertir el arreglo lo pone en rojo, y es el único de los siete que cae.
- [x] `make pwa.quality` exit 0; `make pwa.test.unit` exit 0 (256 ficheros, 1686 tests).

## Adversarial pass

Tres capas en paralelo (Blind Hunter · Edge Case Hunter · Acceptance Auditor), read-only, sobre el
worktree `pwa-wdt-stylesheet-dropped-kawe`, antes de abrir la PR. **Un pase que no encuentra nada
también cuenta; éste encontró once cosas, y la más grave iba contra el propio guardián de la
corrección.** Las severidades de las capas se descartaron y se reasignaron leyendo el código, como
manda el proceso: una capa trabaja con asimetría de información deliberada.

### Hallazgos aplicados

1. **(alta, Edge Case Hunter) El gate podía quedar verde sobre el defecto.** El test dependía de que
   el fixture *hoistee*, y esa precondición no estaba afirmada. Medido: un solo carácter no-blanco
   delante del `<link>` (o un NBSP) corta el hoisting; el link se queda en el `body`, el código
   pre-arreglo lo monta, `querySelector` lo encuentra y `compareDocumentPosition` sigue diciendo
   FOLLOWING — **las tres aserciones pasaban con el defecto presente**. La falsificación original
   sólo probaba el fixture de aquel momento, no el universo del gate. Cerrado afirmando la
   precondición (el `<link>` está en `head` y **no** en `body`) y sustituyendo la aserción de orden
   por una de identidad (`host.firstElementChild === sheet`).
2. **(alta, Acceptance Auditor) La rama no llevaba registro del pase adversarial**, así que
   `gh pr create` habría sido rechazado por el hook y el workflow servidor habría enrojecido. Este
   fichero lo cierra.
3. **(media, Blind Hunter) El test afirmaba un `href` inventado.** El loader real emite
   `https://localhost/_wdt/styles` — absoluto, porque el `url()` de Twig es `ABSOLUTE_URL` — y el
   test afirmaba `toBe("/_wdt/styles")`, con el comentario del fuente repitiendo el mismo literal
   falso. La única aserción que parecía verificar la URL real verificaba una invención del propio
   test. Corregido a la forma real, con constante nombrada.
4. **(media, Blind Hunter + Edge Case Hunter, hallazgo independiente en ambas) Orden de DOM ≠ orden
   de carga.** El comentario acreditaba el `<script>` vacío de upstream como lo que «hace esperar al
   parser», pero **en esta ruta no hay parser**: se hace `appendChild`, y un `<link>` que no ha
   creado el parser no bloquea el pintado. La ventana de pintado sin estilos sigue existiendo en la
   primera carga de cada ventana de 10 minutos (`Cache-Control: max-age=600, private`). Corregido el
   comentario para que no acredite un mecanismo inoperante.
5. **(media, Edge Case Hunter) Un 200 vacío o de texto plano levantaba el latch sin montar nada** —
   `mountedRef.current = true` iba incondicional tras `mountFragment` —, dejando el toolbar muerto
   para toda la sesión, sin reintento y sin telemetría. Además, un 200 de texto plano pintaba texto
   crudo en el host fijo a `z-index: 2147483646`.
6. **(media, Blind Hunter) Nada pinneaba que los `<script>` del `head` se monten y ejecuten.** La
   respuesta natural al hallazgo 12 —«filtrar el head a sólo `link`»— pasaba los siete tests, y si
   upstream mueve el bootstrap de Sfjs a un `<script src>` inicial, el toolbar deja de arrancar en
   silencio.
7. **(media, Blind Hunter) El functional test sobre la plantilla real no afirmaba nada de la hoja.**
   Es el único sitio que renderiza el twig de verdad a través de un kernel. Se le añaden las
   aserciones, **diciendo qué compran**: enrojecen si upstream *quita o renombra* el acoplamiento,
   no si lo *añade* — que es la dirección que produjo este bug.
8. **(media, Acceptance Auditor) `docs/architecture-pwa.md` describía mal el componente**: decía
   «on each new token fetches» cuando el código carga una sola vez desde #311. Los otros dos
   documentos que describen el mismo mecanismo (`pwa/CLAUDE.md`, `docs/development-guide-api.md`) ya
   decían «once per session»: tres descripciones y una mentía.
9. **(media, Acceptance Auditor) `pwa/CLAUDE.md` no recogía el hoisting**, siendo la viñeta que ya
   posee las reglas de montaje no-obvias de este componente y donde mirará el próximo lector.
10. **(baja, Acceptance Auditor) El mensaje de commit era impreciso**: dice que «los seis tests
    existentes alimentan todos un fixture body-only». Son **cuatro**; los otros dos no alimentan
    ninguno. La inferencia que sostiene la frase sí vale para los seis. Se corrige aquí y en el
    cuerpo de la PR en vez de reescribir un commit ya pusheado.
11. **(baja, Edge Case Hunter) Un 404 de la hoja era silencioso**, reproduciendo el síntoma exacto
    que se acaba de arreglar sin ninguna señal. `ProfilerController::toolbarStylesheetAction()`
    llama a `denyAccessIfProfilerDisabled()`, mientras que nuestro controlador del loader renderiza
    el twig sin consultar al profiler: el loader puede devolver 200 con un `<link>` cuyo destino
    da 404.

### Descartado

- **La mitad `<title>` del hallazgo de Blind Hunter.** Midió que un `<title>` montado secuestra
  `document.title`; contra-medido, eso sólo ocurre en un documento **sin** título previo. El layout
  raíz siempre fija uno y precede en orden de árbol, así que `document.title` no se altera y el
  anunciador de rutas de Next no se toca. Falso positivo en esta aplicación.

### Contradicción entre capas, resuelta por medición

Blind Hunter y Edge Case Hunter se contradijeron sobre el `<title>`. Se midió con jsdom en ambas
configuraciones: con título previo → `"Erpify"` (Edge Case Hunter acierta); sin título previo →
`"hijacked"` (que es lo que Blind Hunter midió). La mitad `<base>` del mismo hallazgo **sí** es real:
`baseURI` pasó de `/backoffice/banks` a `/otro-sitio/` y un `<a href="x">` se re-resolvió contra él.

### Hallazgo 12 — abierto, escalado a decisión humana

**El montaje del `head` va sin filtrar**, así que un elemento con efecto de documento que upstream
llegara a emitir tomaría efecto global. Medido: `baseURI` pasó de `/backoffice/banks` a
`/otro-sitio/` y un `<a href="x">` se re-resolvió contra él.

**Corrección de una cota que yo di por buena y era falsa.** Afirmé que `base-uri 'self'` acotaba
esto. No lo hace: `'self'` restringe el *origen* del `<base>`, y toda la medición era same-origin,
así que esa CSP lo permite entero. La cota no existe.

Consultados Winston (arquitecto) y Amelia (implementadora). **Coinciden en tres cosas y discrepan en
la conclusión**, y la discrepancia se deja aireada en vez de resuelta por mí:

- **Coinciden:** `parsed.head` es el tamiz equivocado — un `<base>` emitido *tras* el primer `<div>`
  se queda anidado en `body` y ningún filtro del lado head lo ve (medido). Y el `<script>` que
  revivimos a propósito **domina** al `<base>`: un fragmento **sin ningún `<base>`** movió
  `document.baseURI` a `/inyectado/` con tres líneas de su propio JS (medido). Ninguno de los dos
  quiere una issue: el diff cabe en esta PR.
- **Amelia — opción 1, cero código de producción.** El filtro cubriría una de tres grafías en el
  markup y cero contra el script; es la forma de enumeración que ya falló dos veces aquí, y
  contradice el docblock de confianza que justifica ejecutar esos scripts: un control que tapa el
  vector débil mientras el fuerte queda abierto por diseño es una lectura falsa para el próximo
  revisor. El entregable es el comentario que **nombra** el vector.
- **Winston — filtrar el fragmento entero, no el head.** El defecto de raíz es que `mountFragment`
  nunca declaró qué acepta: antes el contrato era accidentalmente «sólo body» y perdió la hoja;
  ahora es accidentalmente «todo» y gana autoridad de documento. Es el mismo defecto con dos caras.
  Niega el conjunto **cerrado** (`BASE`/`META`/`TITLE`, cerrado por el spec) y admite el abierto, con
  un `warn` que hace auditable el descarte. No es un control de seguridad y no debe registrarse como
  tal; el valor es un contrato enunciable — «montamos recursos, nunca declaraciones del documento».

Ambos coinciden en que la opción 3 tal como yo la enuncié es incoherente: sin filtro, un test que
afirme `document.baseURI` invariante enrojece en el commit que lo introduce.

**Resuelto: A + D** — sin filtro en producción, más un canario del disparador en el functional test
que ya renderiza el twig real, más el comentario que nombra el vector.

Consultado el tercer criterio externo, que en la primera vuelta recomendó **B** y en la segunda,
ante la medición, se movió a **A + D**. Lo que movió la decisión no fue el recuento de opiniones —
dos de tres decían B — sino dos mediciones que refutaban la premisa que los tres compartían:

- **B costaba más de lo tasado.** El filtro top-level que se escribe de primeras **deja pasar** un
  `<base>` anidado (medido). Cubrir el árbol obliga a `reviveNode` con retorno nullable, guarda en
  el caller y dos tests. La única de las tres voces que había escrito el código lo tasó bien y
  quedó en minoría.
- **El contrato de B no era la línea limpia que se vendía.** «Montamos recursos, nunca declaraciones
  del documento» no separa categorías: el `<link rel="stylesheet">` que montamos *a propósito* tiene
  efecto de documento, y sólo está acotado porque upstream nombra sus selectores `.sf-*` — convención
  suya, no mecanismo nuestro (medido: cero selectores sin acotar en 18 KB de CSS).
- **D dejó de costar un gate nuevo.** `WebDebugToolbarLoaderFunctionalTest` ya existe, ya renderiza
  el twig real y ya corre en CI; el canario es una aserción más. La objeción de que `api/vendor/`
  gitignored impide correrlo en CI es cierta para `git ls-files` y falsa para el runtime — ese test
  renderiza ese mismo twig y pasa.

**El coste aceptado, dicho y no escondido: D detecta, B contiene.** Si upstream introduce un `<base>`
y alguien mergea por encima de un CI rojo, surte efecto. Se compra esa diferencia porque el aviso
llega en el bump, cuando una persona puede decidir, en vez de descartar en silencio para siempre un
elemento que upstream podría llegar a necesitar legítimamente.

El canario afirma sobre `<base` y **no** sobre `<title`: medido, `<title` aparece hoy dentro de un
comentario JavaScript de Sfjs, así que esa aserción enrojecería sin defecto. Falsificado
sustituyendo `<base` por `<script`: rojo contra el cuerpo real, luego no pasa en vacío.

Lo que **no** cubre, escrito en el comentario del fuente para que nadie lo lea como contención: un
script ejecutado puede tomar autoridad sobre el documento, y ése es el precio permanente de
ejecutarlos.

### Límites de este pase

Lo que este pase **no** prueba: que el toolbar funcione en un navegador que no sea Chrome; que la
plantilla de una versión futura de upstream siga encajando (el fixture es una transcripción a mano,
y esa deriva se acepta a conciencia — la alternativa, leer el twig desde `api/vendor/`, es un input
gitignored que puede desaparecer y llevarse el gate con él, la forma que este repo prohíbe
explícitamente); ni que el hallazgo 12 esté cerrado — está escalado a decisión humana.

## Verification

| Comprobación | Resultado |
|---|---|
| Falsificación del gate original | Único de 7 en rojo al revertir |
| Falsificación de la precondición nueva | Un carácter delante del `<link>` → rojo |
| `make pwa.quality` | exit 0 |
| `make pwa.test.unit` | exit 0 — 256 ficheros, 1686 tests |
| Navegador real, loader vivo (48 561 B) | Hoja en `document.styleSheets`; host 1045 px → 0 px |
