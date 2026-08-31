# Retrospectiva — Épica *Shared/Images: subida y lectura de representaciones fungibles (primera rebanada)*

- **Fecha:** 2026-08-31
- **Alcance:** 1 épica, 3 historias (img-1-1, img-1-2, img-1-3), ventana 2026-08-26 → 2026-08-31
- **Estado:** 3/3 historias `done`; el marcador de épica se cierra en el mismo PR que esta retro
- **Facilitación:** solo-dev + IA, lentes analíticas reales — el estilo fijado en users-admin y mantenido en gdpr-hardening, no party-mode ficticio
- **Principio rector:** la épica entregaba un **seam compartido sin consumidor**. Esa es su condición peculiar y la vara con la que se mide: sin un consumidor real, casi ninguna garantía tiene quien la ejercite, así que lo único que separa «implementado» de «afirmado» es que exista un control capaz de enrojecer. Esta retro pregunta dónde lo hubo y dónde la garantía se quedó en prosa.

## Resumen de entrega

| Historia | PR(s) | Qué entregó |
|---|---|---|
| img-1-1 subida y representación canónica | #859 · #862 | Módulo `Shared/Images` (Domain + Application + Infrastructure) sin ORM ni HTTP; `InterventionImageProcessor` sobre `intervention/image` v4.3; preflight de recursos antes del decode, allowlist de MIME, defensa anti-polyglot; `ext-exif` añadido a la imagen (sin él la corrección EXIF era un no-op silencioso en todos los entornos) |
| img-1-2 persistencia y borrado fiable de bytes | #869 · #873 | Tabla `Image` (1 migración); puerto `ImageStorage` con adaptador Flysystem local; borrado bytes-primero con la ventana «fila presente + objeto ausente» enumerada y probada; señal de ciclo de vida enrutada por el outbox; `retry_strategy` declarada para todo el bus |
| img-1-3 lectura canónica segura | #880 · #899 | `GET` autenticado de los bytes canónicos; traducción cerrada de fallo → 404/503/500 afirmada a través de la factory; cache condicional (ETag/304) con `Vary: Cookie`; CSP `default-src 'none'; sandbox` + CORP `same-origin`; ADR `image-read-failure-signal-bound.md`; ventana de supresión de 60 s por `(operation, category)` |

| Métrica | Baseline (fin gdpr-hardening) | Fin épica | Δ |
|---|---|---|---|
| Ficheros tocados por PR de código | — | 49 / 95 / 67 | objetivo del corte: **~30** |
| Diff total | — | 211 ficheros, 13 266 inserciones | — |
| Módulo `Shared/Images` | 0 | 38 ficheros `src` (2 541 líneas) · 58 ficheros de test `.php` + 13 fixtures binarias · **156** métodos de test (144 `test*` + 12 `#[Test]`, sin solape) | nuevo |
| Escenarios Behat del módulo | 0 | **16** ejecutados (14 `Scenario` + 1 `Scenario Outline` × 2 filas) | +16 |
| Gates de artefacto propios | 0 | **5** (`ImageConsumerAuthorization`, `ImageLifecycleListener`, `ImagePixelBudget`, `ImageTransportSurface`, `ImageWriterSurface`) | +5 |
| Targets `php.lint.*` nuevos | 9 | **0 nuevos** (uno *reparado y cableado*: `php.lint.yaml`) | 0 |
| Registros declarativos nuevos en `api/` | 5 | **0 nuevos** (4 existentes extendidos) | 0 |
| Migraciones de esquema | — | **1** (`Version20260828134621`) | +1 |
| Bloques de lectura hostil registrados | — | img-1-1: **2** · img-1-2: **cuatro bloques bajo una cabecera que declara «tres pases»** · img-1-3: **4**, numerados | — |
| Reverts | — | 0 | — |
| Entradas nuevas en `deferred-work.md` | — | **0** | — |

## Qué fue bien

- **Los cinco action items de la retro anterior están cerrados, 5/5, y verificados contra el árbol y no contra el registro.** «Afirmar la siembra» vive en `docs/rules/testing.md:43`; el `Dev Agent Record` de G-1a/G-1b declara dónde vive su registro; el pase adversarial como puerta de *abrir* el PR es hoy un hook `PreToolUse` con 101 fixtures de autotest más un workflow server-side; el tag de historia volvió al asunto y aquí se cumplió **6/6**, de modo que el defecto que enmudeció `bmad.status.audit` en gdpr-hardening **no recurrió**; y la promesa de D12 está ablandada en los dos ADR (`event-store-and-projections.md:407`, `audit-activity-log.md:284`).
- **El pase adversarial dejó de confirmar y pasó a cambiar el resultado.** El Pase 2 de img-1-3 tumbó **cuatro AC y la premisa entera de Task 0**: 34 hallazgos, **cero rechazados**, seis GRAVE — entre ellos un brazo 503 mecánicamente imposible (`ProblemDetailsFactory` sólo lee marcadores dentro del brazo `instanceof DomainException`) y una AC que emparejaba dos tests sobre resolvers **disjuntos** de Symfony, de forma que el segundo pasaba con el guard borrado y el test correcto ya existía en el árbol desde antes de la épica.
- **Tres ejes independientes convergiendo es señal; el mismo eje repetido no lo es.** En el Pase 4 las tres capas dieron **por separado** con que `ImageGetController` mete un `mediaType` hidratado sin pasar por el constructor en un `Content-Type` renderizable, en el origen que también sirve la PWA — con **cero ocurrencias** de CSP, CORP y `X-Frame-Options` en todo `api/`, y `nosniff` que no defiende de un tipo *declarado*.
- **Dos GRAVE en img-1-2 que habrían llegado a `main` con las 90 pruebas del módulo en verde.** `delete()` confirmaba borrados sobre bytes vivos (la guarda inspeccionaba sólo el directorio contenedor; con el shard exterior a 0000 la propia comprobación se saltaba a sí misma) y **el test que existía para esa rama restauraba a 0755 justo el único nivel que sí estaba guardado**. Y la raíz de almacenamiento no la creaba ningún despliegue: el módulo estaba muerto en cualquier deploy.
- **Un target que existía sólo como nombre.** `php.lint.yaml` estaba **rojo sobre un árbol limpio** —rechazaba `!tagged_iterator`, una etiqueta que define el propio framework y que `config/services.yaml` usa desde que existen los iteradores de proyector y de referencias-a-persona— y no era miembro de ningún agregado. Reparado (`--parse-tags`) y metido en `php.quality` **y** en `php.quality.dry-run`, que es la mitad que impide que vuelva a ser invisible.
- **Medir venció a la propuesta, cuatro veces seguidas.** La consulta externa sobre D1–D4 corrigió dos: 64 MiB **no era el techo** (el stream PNG pre-deflate es `d × (d × 4 + 1)` = 67 112 960 B, 4 096 bytes por encima), y la premisa de la ADR *«worker mode offers no state for free»* resultó **falsa medida** (`import worker.Caddyfile` sin guarda, `FRANKENPHP_RESET_KERNEL` sin poner en ningún fichero, nada etiquetado `kernel.reset`).
- **Un gate escrito en la historia mordió la primera firma que la propia historia añadió después.** `FailureSignalWindow::admits(string $key)` enrojeció el eje de valor de NFR6; tenía razón en sospechar y se equivocaba en el fondo, así que el arreglo fue el nombre. Es la mejor evidencia disponible de que ese gate no es decorativo.
- **Los residuales se cerraron con código, no con issues, y el caso testigo es el IDOR.** El parche literal rompía la slice —el permiso que sugería no lo tiene ningún rol, así que la ruta habría respondido 403 a todo el mundo y habrían caído los 16 escenarios— pero su *argumento* era válido y señalaba una promesa que nada sostenía. Cerrado con `ImageConsumerAuthorizationGateTest`, que enrojece en el diff que introduzca el primer consumidor, falsificado plantando `$logoImageId` en `Bank`.
- **La épica no alimentó su propia pila de diferidos.** `deferred-work.md` no ganó **ni una** bala de Images: el residual de img-1-2 (la ausencia confirmada como productor de log sin cota) se refundió en img-1-3, que es quien expone la ruta y quien podía decidir la cota.

## Qué costó

- **La familia del test vacuo recurrió, cambiada de forma.** La contramedida de la retro anterior ataca el **setup vacío**; lo que apareció aquí es la misma clase un nivel más arriba —**la aserción que no puede fallar**— por tres vías que la viñeta actual no nombra: comprobar la **clase** de excepción donde la AC promete un **status** (B-3: los tres ficheros que nombran las dos clases de dominio nunca afirman el 404/503/500, y `ErrorContractGateTest` no las ve, así que un `extends RuntimeException` dejaba todo verde mientras dos documentos seguían publicando 503); emparejar un test con un **resolver disjunto** del que la AC nombra (AC 16); y un setup que restaura justo el nivel que la guarda cubre (GRAVE-1). La regla escrita cubre un caso de una familia.
- **El corte erró su propia métrica de planificación por 1,6×–3,2×.** `epics-images.md` §5 fija *«objetivo ~30 ficheros modificados por story/PR»*; llegaron **49 / 95 / 67**, y nadie lo re-midió durante la épica. Un objetivo que no se mide en curso no es un objetivo, es una intención.
- **El orden que el `CLAUDE.md` prescribe se violó una vez más, declarado en vez de disimulado.** img-1-2 lo escribe en su propio artefacto: *«La secuencia no fue la que el `CLAUDE.md` prescribe … el PR se abrió primero por instrucción explícita del usuario»*. Cuarto punto de datos de la misma regla; el primero desde que existe el hook.
- **Los hilos de review de la PR no los leyó ninguna de las tres capas** — los encontró Sergio. Un CORP ya aplicado seguía abierto porque el re-review on push de Strix está desactivado y su comentario queda sellado en el commit que leyó. Ya está corregido como regla, pero el fallo fue de proceso y costó una vuelta.
- **Dos deslices de edición por script, cazados por verificar el EFECTO y no la ejecución**, que es la única razón por la que no shipearon: una inserción por `str.index()` que enganchó un comentario y metió `php.lint.yaml` **dos veces** en `php.quality` y **ninguna** en el dry-run; y un regex no-greedy que cerró en el paréntesis de `RecordingLogger()` y dejó `new ReadFailureReporter(new RecordingLogger(, ...))` en tres ficheros.
- **Las dos balas de `Shared/Images` de `deferred-work.md` nadie las volvió a leer.** Medido: **ninguna de las dos está cerrada** —sus disparadores (cacheo de variantes con consumidor real; la primera historia de consumo) no han llegado— pero el paisaje de ambas cambió bajo ellas: `Image` es hoy entidad Doctrine y el borrado es bytes-primero con la señal por el outbox, y las balas siguen redactadas contra el árbol de julio.
- **Un artefacto cuenta mal sus propios pases, y es el que más cuidado puso en contarlos.** La cabecera de img-1-2 declara *«Estado: tres pases»* sobre una sección que lleva **cuatro** bloques con cabecera propia (`Pase adversarial externo — bmad-code-review, 2026-08-28`, `Segunda lectura externa — 2026-08-28`, `Tercera lectura externa … 2026-08-29`) más el autoadministrado A-1..A-7 sin cabecera, más su `### Review Findings`. La prosa numera primero/segundo/tercero y no casa con los `###` que la siguen. No cambia ninguna conclusión de la épica; cambia lo que se puede citar de ella sin volver a contar.
- **El artefacto de img-1-3 llegó a 1 894 líneas** para una historia de 67 ficheros. Es el registro más completo de la épica y también el más caro de releer; el Pase 4 gastó parte de su presupuesto corrigiendo el propio artefacto (una cita fabricada, cuatro rangos de línea desplazados, un registro inventado, cinco recuentos inflados).

> **Corrección durante la propia retro, igual que en gdpr-hardening y users-admin.** La tabla de arriba nació con **tres cifras equivocadas**, las tres detectadas al ir a verificarlas en vez de al escribirlas: *«71 ficheros de test»* eran 71 **rutas** bajo `api/tests` que casan «Images», de las cuales 13 son fixtures binarias (`.png`, `.jpg`, `.gif`, `.webp`) y sólo **58** son ficheros de test; *«144 métodos»* contaba `public function test*` e ignoraba los **12** marcados con `#[Test]`, que no llevan el prefijo — la union es **156**; y *«15 escenarios Behat»* contaba líneas `Scenario`, no escenarios: hay 14 más un `Scenario Outline` cuya tabla tiene dos filas, o sea **16** ejecutados, que es además el número que el propio artefacto de img-1-3 cita al descartar el parche de IDOR. Las tres son la misma forma —**contar el proxy en vez del sujeto**— y es la forma que esta épica encontró en el código cuatro veces. Se dejan escritas en vez de corregidas en silencio.

## Insights

1. **Un seam sin consumidor no tiene quien ejercite sus garantías, así que todas sus promesas nacen en prosa.** Los cinco gates de artefacto de la épica existen exactamente por eso: `ImageConsumerAuthorizationGateTest` no prueba una autorización, prueba que **el día que aparezca un consumidor alguien tenga que decidirla**. Es el patrón transferible de esta épica: cuando no puedes probar la garantía, pon un control que enrojezca en el diff que la haga necesaria.
2. **Esta épica consumió el patrón de la casa en vez de extenderlo, y eso es madurez, no estancamiento.** Cero registros nuevos y cero targets nuevos, con cuatro registros existentes extendidos y cinco gates mirroreados sobre el módulo. La forma «registro declarativo + gate obligatorio» ya no necesita estrenarse por cada dominio nuevo.
3. **«Afirmar la siembra» era el caso particular; la regla general es falsificar borrando la guarda.** Los tres modos que recurrieron aquí comparten forma: el test pasa con el mecanismo que dice proteger **retirado**. La siembra vacía es sólo la variante donde lo retirado es el sujeto.
4. **Un target rojo sobre árbol limpio es indistinguible de un target que no existe, y peor: su nombre aparece en la lista.** `php.lint.yaml` llevaba tiempo así. La reparación fue barata; lo caro habría sido citarlo como control en un documento.
5. **Contar el proxy en vez del sujeto es el modo de fallo de las métricas, y aparece igual en el código y en la retro.** `grep -c 'Scenario'` no cuenta escenarios, `public function test*` no cuenta tests, y una ruta que casa «Images» no es un fichero de test — igual que comprobar la clase de excepción no comprueba el status. En los dos sitios la defensa es la misma: preguntar por el sujeto, no por algo correlacionado con él.
6. **La convergencia sigue sin ser verificación, y aquí se midió por tercera vez.** Dos de las cuatro decisiones D1–D4 cayeron ante una duda externa, y ambas premisas caídas venían de documentos propios del repo (un literal de 64 MiB y una D2 de la propia ADR).

## Continuidad con la retro previa (gdpr-hardening, 2026-08-04)

- **Los cinco items: CERRADOS.** Detalle y evidencia arriba, en *Qué fue bien*.
- **Un item se cerró y su consecuencia declarada se revirtió después.** El item 3 decía *«un PR que necesita ojos antes de estar listo es un **draft**, y el pase es lo que lo saca de draft»*. `CLAUDE.md` dice hoy lo contrario —aquí no se usan drafts— y la puerta se sostiene por el hook, no por el estado del PR. La regla sobrevivió; su mecanismo propuesto no. Vale registrarlo porque es la segunda vez que un action item de retro acierta el *qué* y falla el *cómo*.
- **La misma regla falló una tercera vez (#770) antes de ganar el hook, y una cuarta aquí (img-1-2), esta vez declarada.** El hook cubre el caso accidental; el caso de instrucción explícita queda fuera por construcción, y así está escrito.
- **El principio «resolver, no acumular» aguantó otra épica:** cero entradas nuevas en `deferred-work.md`, y el único residual se refundió en la historia siguiente de la misma épica.
- **La lección de «las notas de planificación se pudren más rápido que el código» recurrió**, ahora en el propio artefacto de la historia en curso: cita fabricada, rangos de línea desplazados, registro inventado y recuentos inflados, todos dentro de img-1-3 y todos cazados por el pase externo.

## Readiness

- **Funcional:** 3/3 historias, 1 migración, 0 reverts, 0 incidentes (no existe entorno de producción). El módulo tiene identidad, estado y ciclo de vida mecánico propio; lo que no existe es un consumidor externo que referencie un `ImageId` — por decisión del corte, no por omisión.
- **Seguridad:** diez bloques de lectura hostil registrados, cada uno declarando su alcance. La primera ruta `/api/*` que devuelve un cuerpo renderizable llegó con CSP y CORP, que el origen no tenía en ninguna forma. La autorización de lectura es `IS_AUTHENTICATED_FULLY` como frontera **provisional y acotada a esta slice**, con `ImageConsumerAuthorizationGateTest` como el control que obliga a revisarla.
- **Huecos declarados, no descubiertos por accidente:** issue **#879** abierta (el sumidero de log del contenedor no aísla productores independientes — transversal a todos los contextos, no de esta épica); los residuales de `PRODUCTION_SECURITY_CHECKLIST.md` §7 sobre la amplificación del 304, el presupuesto por IP y lo que `read()` devuelve; y las dos balas de `Shared/Images` en `deferred-work.md`, cuyos disparadores siguen sin llegar.
- **Despliegue:** `main` lleva #899 desde 2026-08-31 16:22Z. No hay entorno de producción, así que ninguna cifra de esta retro se ha ejercitado bajo carga real.
- **Veredicto:** la épica hizo lo que decía, y su condición peculiar —un seam sin consumidor— la obligó a inventarse controles para garantías que nadie podía ejercitar. El riesgo residual no está en lo que dejó abierto, que está nombrado y con trigger: está en que **el modo de fallo titular de la retro anterior recurrió con otra cara**, y la regla escrita sólo nombra la cara vieja.

## Épica siguiente

**El primer consumidor real, y no está definida.** Lo que ya la espera, escrito y con dueño:

- `ImageConsumerAuthorizationGateTest` **enrojece en su primer diff** — es el mecanismo que fuerza la decisión de autorización que esta slice dejó provisional.
- La clasificación person/non-person de `api/.audit-resource-types` se decide **por consumidor** (`BankLogo` vs `UserAvatar`, tipos distintos aunque compartan tabla), nunca genéricamente sobre `Image`.
- Las dos balas de `deferred-work.md` la nombran explícitamente: *«es **la** decisión de la primera historia de consumo»* (borde transaccional) y la mutabilidad de `Image` frente a la URL de variantes.

## Action items

> Triaje en *resoluble ya* vs *decisión propia*, con el principio heredado: **resolver, no acumular**. Lo que ya está en `deferred-work.md` o en §7 con su trigger **no** se duplica aquí.

### Resoluble ya

1. **Generalizar la regla del test vacuo a la aserción que no puede fallar.** La viñeta actual de `docs/rules/testing.md` ataca el setup vacío; los tres modos que recurrieron en esta épica comparten otra forma. Añadir el criterio general —*falsifica el test retirando el mecanismo que dice proteger; si sigue verde, no es el test de esa AC*— con los tres casos medidos (status vs clase de excepción, resolver disjunto, setup que restaura el nivel guardado). — *API / proceso*
2. **Refrescar las dos balas de `Shared/Images` en `deferred-work.md` contra el árbol de hoy.** Ninguna está cerrada, pero ambas describen un `Image` y un borde de borrado que ya no son los que hay. Reescribirlas con lo que la épica fijó, o declararlas verificadas con fecha. — *artefactos*

### Decisión propia — pendientes de Sergio

3. **El objetivo de ~30 ficheros por story/PR del corte.** Se erró por 1,6×–3,2× y nadie lo midió en curso. Tres salidas: corregir el número para la épica de consumidor, retirarlo por inútil, o aceptar que el corte por *guarantee-axis* produce PRs de este tamaño y decirlo en el corte en vez de fijar un número que no se cumple.
4. **El listón de pases adversariales para una historia de esta clase.** img-1-3 acumuló cuatro. El Pase 2 tumbó cuatro AC y una premisa entera; el Pase 4 encontró el hallazgo de las tres capas convergentes más tres SERIOUS nuevos. ¿Se fija en cuatro para historias con superficie HTTP nueva, o el listón sigue siendo tres y el cuarto se decide por contenido?
