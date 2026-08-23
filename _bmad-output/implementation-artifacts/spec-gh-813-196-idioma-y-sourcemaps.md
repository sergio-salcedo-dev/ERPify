---
title: 'La superficie que no hablaba su idioma, y los source maps que nadie subía — #813, #196'
type: 'fix'
created: '2026-08-22'
baseline_commit: '868c29a48055d6df02dc9e5ec0b4a65387b1d59b'
status: 'in-review'
review_loop_iteration: 1
context: []
---

## Intent

Dos issues, un solo eje: **una declaración que el árbol no cumplía**.

**#813** — `app/layout.tsx` fija `lang="en"` y el módulo i18n (roadmap 0.6) no existe, así que
el inglés no es una preferencia en esta superficie: es el único locale que el documento
declara. #809 cerró este mismo defecto **sólo sobre el chrome** (un título aquí, una
`metadata.description` allá) y dejó detrás tres páginas de documentación y **toda** la
superficie de auditoría en español de punta a punta. Nada se puso rojo, porque nada miraba.

**#196** — el SDK de Sentry lleva meses enviando eventos por el túnel `/monitoring`, pero
`sourcemaps.disable` estaba fijado a `true` y no había credenciales, así que cada traza de
producción llegaba minificada: offsets de bundle en lugar de ficheros y líneas.

## Approach

- **#813**: traducir, no internacionalizar. Introducir i18n sería resolver un problema que
  el roadmap ya tiene asignado (0.6) y que nadie pidió aquí.
- **#196**: subida *opt-in* sobre la presencia de credenciales. Su ausencia no es un error —
  un fork, un colaborador y cualquier job de CI que sólo hace typecheck construyen sin el
  secreto, y fallar ahí convertiría el token en requisito para **compilar** la app en vez de
  para **simbolizar** sus trazas.

## Adversarial pass

Lectura hostil de la propia rama antes de abrir la PR. **Limitación declarada por delante:
la pasada la ejecutó el autor.** CLAUDE.md pide explícitamente un lector distinto (contexto
fresco, otro modelo o una persona) y la restricción de sesión de este agente prohíbe delegar
en subagentes salvo petición explícita del usuario. Ese conflicto se deja **registrado, no
resuelto en silencio**: lo que sigue vale por sus hallazgos verificables, no por su
independencia, y una pasada independiente sigue debiéndose.

Cuatro comprobaciones se hicieron ejecutando algo, no leyendo el diff:

- **`data-testid` — interfaz QA publicada.** El único cambio de línea con `data-testid` en
  todo el diff es un reflow de Prettier sobre `audit-filter-bar`; el **valor** no cambia.
  Ningún locator de Playwright se rompe.
- **Valores de enum y constantes.** `git diff` sobre `audit/domain` y `audit/_lib` no
  devuelve **ninguna** asignación `<clave>: "<valor>"` modificada: `AuditView.Journey` sigue
  valiendo `"journey"`, así que el estado en la URL y los deep links siguen resolviendo.
  Sólo cambian etiquetas y comentarios.
- **Suites e2e.** Ningún spec de Playwright toca la superficie de auditoría ni las tres
  páginas de documentación, así que no hay aserciones e2e que arrastrar.
- **El gate de idioma se falsificó dos veces, y la primera vez FALLÓ.** Con el umbral
  original (tres palabras función sin tilde) se plantó `"Progreso global de la obra"` — un
  encabezado realista, indiscutiblemente español — y el gate **pasó en verde**. Es un falso
  negativo real, no hipotético. Bajado a dos, atrapa esa cadena y `src/` sigue reportando
  **cero**, de modo que el apriete no cuesta ninguna exención en todo el árbol. La medición
  quedó escrita en la cabecera del gate en lugar de en el historial.

### GRAVE — dos afirmaciones falsas sobre la propiedad de seguridad de #196

Ambas se encontraron leyendo los **tipos instalados**, no el diff, y ambas iban a shipear.

1. **Sobredeclaración.** El comentario del código, el mensaje de commit,
   `PRODUCTION_SECURITY_CHECKLIST.md` y `pwa/CLAUDE.md` describían
   `deleteSourcemapsAfterUpload: true` como *«la propiedad de seguridad load-bearing»*, sin
   la cual *«cada `/_next/static/**/*.js.map` es una URL pública»*. **Es falso**: la opción ya
   viene a `true` por defecto en `@sentry/nextjs` 10.70.0 — verificado contra el docblock de
   `build/types/config/types.d.ts:236-241`. Escribirla es un **pin**, no un interruptor: hace
   que un cambio de default o un borrado casual sean visibles en vez de silenciosos. Eso vale
   la pena y merece gate; llamarlo lo que se sostiene entre el árbol y un source map público
   no. Corregido en los cuatro sitios.

2. **Agujero en el propio gate.** `filesToDeleteAfterUpload` **anula** por completo a
   `deleteSourcemapsAfterUpload` (mismo docblock, líneas 242-246). La primera versión del gate
   afirmaba únicamente que el flag valía `true`, así que un glob estrecho en esa otra opción
   habría borrado sólo lo que nombra y **servido todo lo demás, con el gate en verde** — la
   forma exacta de falso verde que este repo ya ha pagado dos veces. Cerrado con una tercera
   aserción: la opción debe estar **ausente**, porque añadirla es una decisión que se defiende
   en review, no un ajuste de configuración.

### Falsificación de los gates nuevos

Ninguno se declara verde sin haberlo visto rojo. Cada mutación pone roja **exactamente una**
fila, y el árbol restaurado vuelve a verde:

| # | Mutación                                                        | Resultado |
|---|-----------------------------------------------------------------|-----------|
| 1 | `deleteSourcemapsAfterUpload` → `false`                          | 1 roja    |
| 2 | token como `ARG SENTRY_AUTH_TOKEN`                               | 1 roja    |
| 3 | quitar el `--mount=type=secret` del paso de build                | 1 roja    |
| 4 | prefijo `NEXT_PUBLIC_` sobre el token                            | 1 roja    |
| 5 | añadir `filesToDeleteAfterUpload` con un glob estrecho           | 1 roja    |
| 6 | plantar `"Progreso global de la obra"` en `roadmap/page.tsx`     | 1 roja    |

### Qué NO se verificó, y por qué

La política de egress de este contenedor devuelve **403** en tres hosts distintos: el registry
de Docker, `api.github.com`/`codeload.github.com` y el PPA de PHP en apt. Consecuencias, sin
suavizar:

- **No se construyó ninguna imagen real.** El wiring del secreto se validó al nivel que sí es
  alcanzable: `docker compose config` sobre `compose.yaml + compose.prod.yaml` resuelve con
  exit 0 tanto con `SENTRY_AUTH_TOKEN` **vacío** como **totalmente ausente**, que es la ruta
  de degradación afirmada. Que un `docker build` real monte el secreto y que `sentry-cli`
  suba y borre los maps **no está probado aquí**.
- **Ningún gate PHP se ejecutó** — `api/vendor` no existe (`phpstan/phpstan` es el único
  paquete del lock sin fuente git, y su dist está bloqueado). Este diff no toca PHP, así que
  no hay nada que esos gates cubrieran; se registra porque acota lo que este verde significa.
- **Nadie leyó una traza simbolizada real en Sentry.** Eso necesita un token, un deploy y un
  error de producción.

## Code review — ronda independiente (post-PR)

La pasada adversarial de arriba declaraba, por delante, que una lectura independiente seguía
debiéndose. Se ejecutó, y **encontró ocho hallazgos, todos en la mitad de idioma**. La mitad de
Sentry salió sin hallazgos: el revisor verificó contra `@sentry/nextjs` 10.70.0 que
`deleteSourcemapsAfterUpload` sí viene por defecto a `true` en la ruta cliente
(`config/webpack.js:236`), que `filesToDeleteAfterUpload` lo anula, que una subida fallida aún
ejecuta `deleteArtifacts()` (`handleRecoverableError(e, false)` no relanza), y que
`docker compose config` resuelve con `SENTRY_AUTH_TOKEN` ausente.

**El hallazgo serio es una regresión de accesibilidad introducida por esta misma rama.** En
`AuditPagination` se tradujo la mitad `aria-label` y se dejó el texto visible en español:
`text: "Anterior"` contra `label: "Previous page"`. El nombre accesible ya no contiene el texto
visible, que es un fallo de **WCAG 2.5.3 Label in Name** — y ningún gate del repo lo ve, porque
`jsx-a11y` no compara ambas mitades.

Los otros siete son residuo de traducción: `"Todo"` y `"Cambios"` en los segmentos de nivel
(que además discrepaban en pantalla con el badge, ya traducido a `"Change"` en el mismo diff),
`<Section title="Cambios">` en el drawer, `"Cualquiera"` en el select de tipo de actor,
`"Sin metadata"`, `title="Ordenar por hora"` sobre un botón que ya leía "Time", y ~45 `name`
españoles en `roadmap.ts` bajo una cabecera que este diff acababa de reescribir para afirmar
que toda la superficie es inglesa.

**Lo que importa no es el residuo, es que el gate estaba verde sobre todo él.** Los dos
detectores originales exigen una *frase* — una tilde o varias palabras función — así que ven
prosa y son ciegos a la etiqueta corta, que es la mayor parte de una UI. El gate era, sobre esa
clase de cadena, decorativo, mientras `CLAUDE.md` y `pwa/CLAUDE.md` ya declaraban la invariante
como sostenida. Corregido con un tercer detector: un léxico curado de palabras de contenido
españolas, cuya regla de pertenencia es que la palabra **no** sea también inglesa ni un
fragmento de Tailwind (`media`, `total`, `actor`, `metadata`, `global`, `error` quedan fuera a
propósito). Al activarlo encontró **un noveno** string que ni la revisión ni un barrido manual
habían visto (`"Recibir y enviar eventos a sistemas externos (webhooks)."`). Los ocho quedan
fijados literalmente como casos de regresión.

`sin` es la única palabra función promovida al léxico, y por una razón medida: `"Sin metadata"`
empareja una preposición española con un sustantivo inglés, así que escapaba a los tres
detectores; promoverla añade **cero** hallazgos sobre `src/`. `con` no se promovió — en inglés
existe "pros and cons".

## Outcome

- `pwa/tests/ui-copy-language.test.ts` — 3 casos, `src/` en cero.
- `pwa/tests/sentry-sourcemap-exposure.test.ts` — 5 casos.
- Suite completa: eslint 0, prettier limpio, dependency-cruiser limpio (507 módulos),
  `tsc` 0, vitest **1503 passed / 247 ficheros**.

### Review Findings

Ronda BMAD (`bmad-code-review`, 2026-08-22) sobre `868c29a4..a52ef6de` — tres capas en
paralelo, ninguna falló. Severidades reasignadas en el triaje leyendo el código en su sitio,
descartando las que asignaron las capas. 0 descartados por ruido.

**Contexto que cambia el coste de todo lo de abajo: esto ya está mergeado en `main`.** Ningún
patch es una enmienda; todos requieren rama de seguimiento.

- [ ] [Review][Decision] El secreto de compose puede no llegar nunca a buildx — `compose.prod.yaml` rinde `id=sentry_auth_token,type=env,env=SENTRY_AUTH_TOKEN`, y buildx resuelve `type=env` del entorno del proceso invocante, mientras `make/config.mk:81-87` entrega los secretos de prod **solo** por `--env-file .env.prod.local`. Si los valores del env-file no alcanzan el entorno del proceso, la ruta documentada produce upload-off — y, por el hallazgo del `silent`, en silencio. No verificable aquí (sin daemon); se zanja con un comando.

- [ ] [Review][Patch] Seis entradas del léxico están escritas sin tilde y no pueden matchear español real [pwa/tests/ui-copy-language.test.ts:165,170,171,177,183,187]
- [ ] [Review][Patch] El recorrido AST nunca visita literales de plantilla → tres nombres accesibles en español [pwa/tests/ui-copy-language.test.ts:306]
- [ ] [Review][Patch] ~21 cadenas españolas siguen renderizándose en superficies que este cambio declara inglesas [pwa/src/app/backoffice/roadmap/page.tsx:104,120-123,170,178; docs/flow/page.tsx:22,34,250,258; audit/ui/ActorChip.tsx:57,58; ErasedResource.tsx:28; AuditEntryDrawer.tsx:163; JourneySessionHeader.tsx:23; roadmap.ts:312,442,492,572,676,679]
- [ ] [Review][Patch] Seis aserciones de test fijan español y certifican el defecto [pwa/tests/.../ActorChip.test.tsx:24; AuditEntryDrawer.test.tsx:111; AuditTimelineTable.test.tsx:132; auditInvestigationScreen.test.tsx:107,117,125]
- [ ] [Review][Patch] La búsqueda de propiedades no está anclada al bloque `sourcemaps` y una clave entrecomillada derrota la invariante 2 — falla ABIERTO [pwa/tests/sentry-sourcemap-exposure.test.ts:58-72]
- [ ] [Review][Patch] `productionBrowserSourceMaps: true` publica todos los maps con el gate en verde [pwa/tests/sentry-sourcemap-exposure.test.ts:88-105]
- [ ] [Review][Patch] El chequeo de `ARG` no cubre `ENV` ni un `ARG` multi-nombre [pwa/tests/sentry-sourcemap-exposure.test.ts:107-122]
- [ ] [Review][Patch] Toda configuración errónea produce un build verde y mudo — `silent: !process.env.CI` y `CI` nunca está definido en la etapa builder; `|| true` enmascara además un montaje roto [pwa/next.config.ts:215; pwa/Dockerfile:83-85]
- [ ] [Review][Patch] Un token `sntrys_` con ámbito de organización se rechaza por exigir `SENTRY_ORG`, que el SDK exime [pwa/next.config.ts:194]
- [ ] [Review][Patch] La doc de despliegue del PWA sigue afirmando lo contrario de lo que el cambio hizo [pwa/docs/production-deployment.md:38]
- [ ] [Review][Patch] `docs/rules/security.md` no recoge el primer patrón de secreto BuildKit del repo [docs/rules/security.md]
- [ ] [Review][Patch] «defaults to true» es cierto solo para el build de cliente; los maps de servidor se generan y se conservan [pwa/next.config.ts:206; PRODUCTION_SECURITY_CHECKLIST.md:112; pwa/CLAUDE.md:72; .env.prod.example:16]
- [ ] [Review][Patch] La cabecera del gate se contradice con su propio código en tres puntos, y describe mal su lista de atributos omitidos [pwa/tests/ui-copy-language.test.ts:20-22,49]
- [ ] [Review][Patch] «ocho cadenas» no cuadra entre tres documentos del mismo cambio [CLAUDE.md; pwa/CLAUDE.md; pwa/tests/ui-copy-language.test.ts]
- [ ] [Review][Patch] La traducción rompió una concordancia previa: auditoría dice «Short name», el formulario «Code» [pwa/src/context/backoffice/audit/application/humanizeAuditField.ts:12]
- [ ] [Review][Patch] Comentarios relativos al cambio en los dos ficheros de test nuevos, prohibidos por CLAUDE.md [pwa/tests/sentry-sourcemap-exposure.test.ts; pwa/tests/ui-copy-language.test.ts]
- [ ] [Review][Patch] Comentarios obsoletos que nombran etiquetas ya sustituidas [pwa/src/app/backoffice/audit/_lib/auditFilter.ts:67; AuditInvestigationScreen.tsx:75; MetadataBlock.tsx:54]
- [ ] [Review][Patch] La referencia al gate no resuelve desde la raíz [.env.prod.example:58]
- [ ] [Review][Patch] El propio Outcome declara «3 casos» donde hay 4, y la medición «ninguna asignación clave:valor modificada» es falsa [este fichero]

- [x] [Review][Defer] Las cabeceras de día de auditoría renderizan fechas en español vía `Intl` — ningún barrido de cadenas puede verlo [pwa/src/context/shared/date-time-provider/infrastructure/DateFnsDateTimeProvider.ts:42] — deferred, pre-existing
- [x] [Review][Defer] El literal `10.70.0` se afirma en cuatro sitios sin nada que lo ate al manifiesto [pwa/next.config.ts:207; pwa/CLAUDE.md:72; PRODUCTION_SECURITY_CHECKLIST.md:112; pwa/tests/sentry-sourcemap-exposure.test.ts:21] — deferred, pre-existing
- [x] [Review][Defer] La lectura independiente aterrizó post-PR y sin `ADVERSARIAL_PASS_ACK` — el orden no es corregible retroactivamente [CLAUDE.md → Security review → Process] — deferred, pre-existing
