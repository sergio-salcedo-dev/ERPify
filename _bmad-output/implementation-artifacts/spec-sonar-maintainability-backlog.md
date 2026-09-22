---
title: 'Vaciar el backlog de mantenibilidad de SonarCloud: arreglar 13, argumentar 10, ninguna supresión nueva'
type: 'refactor'
created: '2026-09-20'
status: 'in-progress'
baseline_commit: '20acf52a'
review_loop_iteration: 1
context:
  - '{project-root}/docs/rules/clean-code.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-sonar-open-issues.md'
  - '{project-root}/_bmad-output/implementation-artifacts/sonar-write-plan-maintainability-backlog.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema.** SonarCloud no tiene **ninguna** issue abierta (medido 2026-09-19, análisis de las 13:57Z:
`OPEN+CONFIRMED = 0` en las tres calidades). Quedan **23 de mantenibilidad silenciadas** — 13 `ACCEPTED`
y 10 `FALSE_POSITIVE` — y **ninguna lleva comentario** (medido: `additionalFields=comments` devuelve 0/23):
el argumento por el que cada una se calló no existe en ningún sitio auditable. Peor que el vacío es la
mezcla: `FALSE_POSITIVE` **afirma que el analizador se equivoca**, y sólo 2 de las 10 lo sostienen; las
otras 8 son código donde Sonar acierta y se decidió no actuar, que es `ACCEPTED`. `php:S1142` está a los
dos lados del reparto, y un parámetro que una firma abstracta obliga a declarar figura como `ACCEPTED`.

**Enfoque.** Dar a cada una la salida que la casa ya define (`docs/rules/clean-code.md:43`: *fix the code
or accept the finding in Sonar*), con idiomas que el repo ya usa.

**Estado objetivo** de las 23, y es la única aritmética que cuenta:

| Resultado | Nº | Cómo se alcanza |
|---|---|---|
| `FIXED` | 12 | Arreglo en código. Lo pone SonarCloud al re-analizar, **nunca a mano**. |
| `ACCEPTED` | 9 | 6 ya lo están · **3 reclasificadas desde `FALSE_POSITIVE`**. Todas con comentario. |
| `FALSE_POSITIVE` | 2 | Se quedan: son defectos reales del analizador. Con comentario. |
| Supresiones nuevas | 0 | Ni una, de ningún tipo. |

Sobreviven silenciadas **11** (9 + 2), y cada una recibe su justificación escrita en la propia issue.

## Boundaries & Constraints

**Always:**
- **Contrato funcional idéntico, con tres excepciones decididas y nombradas.** No cambian los contratos
  documentados: exit codes, render y el resultado de serialización. Lo que sí cambia, a propósito:
  (a) la fila 10 cambia el **tipo** interno de excepción, admisible porque ningún contrato expuesto lo
  publica, y de paso el mensaje que ve el operador — nada lo aseveraba; (b) los dos `parseStrict` dejan de
  aceptar el año cero, que llegaba a PostgreSQL como `22008` y salía por un 500; (c) el applier de
  auditoría deja de aceptar un offset irreal. (b) y (c) no *cambian* el contrato: **restauran el que los
  docblocks de ambas clases ya prometían** y el código incumplía, y cada uno lleva su falsificador.
- **La precedencia es el contrato de un `match (true)`.** El orden de los brazos reproduce la primera
  condición verdadera del flujo anterior; queda prohibido reordenar por brevedad o simetría.
- **Ninguna abstracción movida por el analizador.** Un predicado extraído (fila 5) o una excepción nueva
  (fila 10) sólo se acepta si **nombra una condición semántica** que mejora la lectura del llamante o el
  diagnóstico del consumidor. Si sólo agrupa condiciones o renombra una clase para bajar un contador, la
  fila se degrada a `ACCEPTED` con ese argumento.
- **Una constante nombra el concepto que hace iguales a sus ocurrencias**, nunca el literal
  (`IDENTITY_MAINTENANCE_INTERVAL`, no `ONE_DAY`). Si no hay concepto que nombrar, no hay constante.
- La justificación va **donde mira el siguiente lector**: un comentario en la propia issue. Si ya está en
  el docblock (`@SuppressWarnings` de PHPMD con su razón), el comentario lo cita en vez de reinventarlo.
- Comentarios de código que explican el **diseño**, jamás la regla: ni `NOSONAR`, ni `SXXXX`, ni jerga de
  «presupuesto de returns» (`docs/rules/clean-code.md:43`, `pwa/CLAUDE.md` → *No linter-narration*).
- Gates verdes con **exit code impreso de una corrida fresca**.

**Ask First:**
- **Todo write a SonarCloud.** Ver *SonarCloud Write Plan*: nada se envía sin OK explícito sobre el
  texto exacto.
- **`BankAccount::create`**: bajar de 8 parámetros pide un parameter object, y eso cambia la API de una
  factoría de `Domain/`. Se propone con su coste; decide Sergio.
- Cualquier hallazgo que obligue a tocar el **comportamiento** de `ConsoleCommandRedactionProcessor`.

**Never:**
- Introducir **ninguna supresión nueva**: `NOSONAR`, `@phpstan-ignore`, `eslint-disable`,
  `@SuppressWarnings` o equivalente.
- Tocar las supresiones **existentes**: están fuera del alcance de esta PR y no se eliminan ni modifican
  salvo que una fila del Code Map lo diga expresamente.
- Reescribir código correcto para contentar al analizador. Si el arreglo empeora la lectura, la issue se
  queda `ACCEPTED` con su argumento — es la salida legítima, no la derrota.
- Tocar issues fuera de estos 23 **issue keys**, ni `.sonarcloud.properties`, ni el quality profile o gate.

## I/O & Edge-Case Matrix

La equivalencia de los dos `parseStrict` se demuestra **conservando el conjunto de casos que sus tests ya
cubren**, no inventando una batería nueva; las filas de abajo son las ramas que el refactor podría alterar.

| Scenario | Input / State | Expected | Error Handling |
|---|---|---|---|
| `parseStrict` tras el colapso | byte nulo (`ValueError`), no parseable (`false`), offset UTC+15, no canónico | `null` en los cuatro, como hoy | ningún `ValueError` escapa como 500 |
| `parseStrict` feliz | valor canónico bajo el formato | el mismo `DateTimeImmutable`, en UTC | N/A |
| `renderFieldValue` con `match` | `false`, `null`, `0`, `'a'`, `['x']`, recurso | `'false'`, `'null'`, `'0'`, `'a'`, `'["x"]'`, `'[unserializable]'` | encode fallido ⇒ marca visible, nunca campo perdido |
| `safePath` tras el `match` | ruta vacía · primer segmento no declarado · ruta de 64 chars · de 65 | `(root)` · `(unrecognised member)` · la ruta entera · truncada con `…` | la guarda de ruta vacía va ANTES del split, o el primer segmento deja de estar garantizado |
| `onException` tras el colapso | sub-request / no-API / no-AccessDenied / token pleno | no sustituye el throwable en ninguno | N/A |
| bound con año cero | `0000-01-01T00:00:00+00:00` en cualquiera de las dos lanes | `InvalidSearchValue` → 422 | nunca un `22008` del driver saliendo por un 500 |
| bound con offset irreal, lane de auditoría | `+25:00`, `-13:00`, `+99:00` | `InvalidSearchValue` → 422, igual que su gemelo | el instante no se desplaza en silencio |
| JSX `DeleteResourceButton` | cualquier `resourceLabel` | `…etiqueta? This cannot be undone.` — sin espacio antes del `?` | N/A |
| JSX leyenda de `flow` | — | separa `gap-2`, **no** un espacio de texto | N/A |

</frozen-after-approval>

## Code Map

Triaje completo y única fuente del *porqué*. La **issue key** identifica la issue; la columna `Regla` es
la *rule key* y **no** identifica nada (seis filas comparten `S1142`). Rutas relativas a `api/` salvo las
tres del PWA, relativas a `pwa/`.

| # | Issue key | Fichero:línea | Regla | Hoy | Final | Acción y porqué |
|---|---|---|---|---|---|---|
| 1 | `AZ7DGUEmgfB4D_M8NyjH` | `src/Shared/Mailer/Infrastructure/PlainTextNotificationMailer.php:65` | S1142 | FP | FIXED | Despacho por tipo → `match (true)`. No era FP: 4 returns reales, como dice el propio issue. |
| 2 | `AaAAgaU5yvo70u5u9kLb` | `src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php:440` | S1142 | FP | FIXED | `safePath` secuencial → `match (true)`, con `$segments` calculado antes. |
| 3 | `AZ67oOo9ZBZf2NUkZYvC` | `src/Shared/Search/Infrastructure/Persistence/Doctrine/FilterApplier.php:249` | S1142 | FP | FIXED | Tres `return null` idénticos → una guarda con predicado nombrado. |
| 4 | `AZ8ELY1UAWYzuNNhOQ7A` | `src/Backoffice/Audit/Infrastructure/Persistence/Dbal/AuditTimelineFilterApplier.php:170` | S1142 | A | FIXED | Mismo colapso. **No quedan iguales**: el de auditoría no tiene guarda de offset y el compartido sí — divergencia preexistente, ver decisiones abiertas. |
| 5 | `AZ8pTBHmz13szBXudoo6` | `src/Iam/Identity/Infrastructure/Security/UnauthenticatedAccessListener.php:55` | S1142 | A | FIXED | **Cuatro `return;` idénticos** → predicado que nombra el caso positivo. Sujeto al límite anti-abstracción. |
| 6 | `AaAB6tw8TV2yRQvmWVCc` | `src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php:98` | S1192 | FP | ACCEPTED | **Revertida en review (2 capas).** El docblock de la clase dice en negrita *«The periods are set by what each check observes, not by symmetry»* y da TRES razones distintas para los tres `1 day` — el prune es *«a third reason again»*. No son un concepto: una constante afirmaría un acoplamiento que la clase niega. **Reclasificar**. |
| 7 | `AZ_obfVh0FCPfX5GnPzh` | `src/Iam/Identity/Infrastructure/Cli/InspectStoredIdentityIntegrityCommand.php:146` | S1192 | FP | FIXED | `'%d identity(ies).'` ×3 → constante; tres `sprintf` iguales divergen al primer retoque. |
| 8 | `AZ_eL9FHQn1YLhAmwh7m` | `src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php:74` | S1192 | FP | FIXED | Predicado DQL ×3 → constante; un rename se arregla en un sitio. |
| 9 | `AZ8ELY0ZAWYzuNNhOQ6_` | `src/Backoffice/Audit/Infrastructure/Persistence/Dbal/DbalAuditTimelineRepository.php:73` | S1488 | A | FIXED | Hipótesis: `$page` sólo porta el `@var`. **Falsificador: `make php.stan` rojo tras usar `@phpstan-var` sobre el `return`** ⇒ se conserva el código actual y la fila acaba en `ACCEPTED`. No se busca una segunda transformación. |
| 10 | `AZ9BGTZl9FzKsEyqKmDI` | `src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php:132` | S112 | A | FIXED | `RuntimeException` → excepción que nombra la invariante rota. **Si no hay nombre que informe al diagnóstico, no se crea y la fila acaba en `ACCEPTED`.** |
| 11 | `AZ7IKM5sveQgd5znOruH` | `src/components/erpify/DeleteResourceButton.tsx:198` | S6772 | A | FIXED | Texto tras `</span>` explícito; ni un carácter del render se mueve. |
| 12 | `AZ63Xl6fS_aXducykv1o` | `src/app/backoffice/docs/flow/page.tsx:199` | S6772 | A | FIXED | Ídem en la leyenda. **No puede introducir un espacio de texto.** |
| 13 | `AZ63Xl6fS_aXducykv1p` | `src/app/backoffice/docs/flow/page.tsx:203` | S6772 | A | FIXED | Ídem. |
| 14 | `AaAott-m88Jwv2L8cXxP` | `src/Shared/Monitoring/Infrastructure/Monolog/ConsoleCommandRedactionProcessor.php:126` | S1142 | A | ACCEPTED | Returns con desenlaces **distintos** de un fail-closed medido; colapsarlos borra el argumento. |
| 15 | `AaAott-m88Jwv2L8cXxQ` | `…/ConsoleCommandRedactionProcessor.php:167` | S1142 | A | ACCEPTED | Ídem. |
| 16 | `AZ_L_gSOFKD1CCdticE0` | `src/Iam/Identity/Infrastructure/Cli/ReconcileErasedSubjectReferencesCommand.php:76` | S1142 | A | ACCEPTED | Cuatro exit codes distintos; su docblock dice que el contrato del comando ES su exit code. |
| 17 | `AZ7-WvmwCrXU0HpsiLW5` | `src/Shared/Audit/Application/AuditLogEntry.php:58` | S107 | A | ACCEPTED | 11 params ya razonados en el `@SuppressWarnings` de PHPMD. |
| 18 | `AZ6z3u9hEwbPSLU0L6zH` | `src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php:318` | S107 | FP | ACCEPTED | **Reclasificar**: Sonar cuenta bien; es descomposición interna de un pipeline. |
| 19 | `AZ6NcGLTSvixX7ahYiCl` | `src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:101` | S107 | FP | ACCEPTED | **Reclasificar**. El parameter object lo decide Sergio (*Ask First*). |
| 20 | `AZ_d5jwNDr67SZkIY0il` | `src/Iam/Invitation/Domain/Event/CarriesInvitationSnapshot.php:90` | S1172 | A | ACCEPTED | La firma la impone `DomainEvent::fromPrimitives()`; quitar `$body` rompe LSP. |
| 21 | `AZ5u7vPaz_2SGvF-EwYV` | `src/components/erpify/CopyButton.tsx:57` | S1874 | A | ACCEPTED | `execCommand` es el único fallback en contexto no seguro, y el código ya lo dice. |
| 22 | `AZ5u7vFRz_2SGvF-EwVm` | `src/Kernel.php:64` | S1144 | FP | FALSE_POSITIVE | Correcto: lo invoca el alias de `MicroKernelTrait`, que sonar-php no resuelve. |
| 23 | `AaARGPNTRXPx87p8HuP7` | `tests/Unit/Gate/ScheduleConsumptionGateTest.php:209` | S3415 | FP | FALSE_POSITIVE | Correcto: el par *(constante, variable)* dispara la regla invertida — medido; el swap contradiría la firma de PHPUnit. |

## SonarCloud Write Plan

Vive aparte, en [`sonar-write-plan-maintainability-backlog.md`](sonar-write-plan-maintainability-backlog.md): 10 comentarios y 2 cambios de estado sobre un
servicio compartido, con preflight, idempotencia, read-back y aborto. Nada de eso se ejecuta
sin OK explícito, y no es trabajo de código.

## Tasks & Acceptance

**Execution** (el porqué de cada una está en su fila del Code Map):

- [x] Filas 1–2 -- reescribir ambos métodos como `match (true)` **preservando la precedencia**, conservando sus docblocks.
- [x] Filas 3–4 -- colapsar los `return null` repetidos de cada `parseStrict` tras un predicado nombrado.
- [x] Fila 5 -- extraer las cuatro guardas de `onException` a un predicado privado, **sujeto al límite anti-abstracción**.
- [x] Filas 6–8 -- extraer cada literal repetido a una constante nombrada por su concepto.
- [x] Fila 9 -- probar `@phpstan-var` sobre el `return`; correr `make php.stan` **antes** de cerrarla y aplicar el falsificador.
- [x] Fila 10 -- crear la excepción dedicada **sólo si su nombre informa**; en otro caso degradar la fila.
- [x] Filas 11–13 -- texto JSX explícito; **verificar el render en el stack vivo**, no sólo el lint.
- [x] Cobertura -- por cada cambio, identificar la cobertura existente **de la rama o contrato que el refactor podría alterar**; se añade test sólo cuando ese comportamiento no esté ya protegido por una aserción viva.
- [x] `tmp/sonar-justifications.md` -- redactado, aprobado y ejecutado: 11 comentarios y 3 transiciones, con preflight y read-back. Estado final medido 16/7/0.
- [ ] Registrar en la PR el **SHA del commit** con los 13 arreglos y el **timestamp del análisis** de SonarCloud contra el que se verificó el cierre.

**Acceptance Criteria:**
- Dado el árbol tras los arreglos, cuando corren `make php.quality` y `make pwa.quality`, entonces salen 0 con exit code impreso.
- Dado que ningún arreglo cambia un contrato, cuando corren `make php.unit` y `make pwa.test.unit`, entonces pasan **sin editar ni un test existente**.
- Dado el diff contra `origin/main` acotado a `api/src api/tests pwa/src pwa/tests`, cuando se buscan supresiones y jerga de reglas **en las líneas añadidas**, entonces no hay ninguna. Esto prueba que el cambio **no introduce** supresiones; **no** prueba nada sobre las preexistentes del árbol, que están fuera de alcance.
- Dado el análisis de SonarCloud **correspondiente al commit que contiene los arreglos** (no uno anterior de la rama), cuando se consultan esas issue keys, entonces cada una devuelve `issueStatus: FIXED` — verificado que la API lo expone así, con 9 casos vivos — y ninguna se mutó a mano.
- Dadas las 10 supervivientes, cuando se consulta cada issue key con `additionalFields=comments` **después** del write, entonces cada una tiene exactamente un comentario y las filas 18–19 devuelven `ACCEPTED`.

## Spec Change Log

- `2026-09-22` — **Unificado el parseo estricto RFC 3339, que es la raíz de la que salieron B y C.** La
  recomendación cambió de signo por una medición, no por gusto: tras B+C la duplicación entre los dos
  appliers eran **cuatro métodos y cuatro constantes, idénticos carácter a carácter** salvo el texto de un
  comentario y una `||` escrita como dos `return`. Y la costura ya existía — el applier de auditoría
  importaba ocho cosas de `Shared\Search` —, así que extraer no minta ninguna dependencia nueva.
  `Shared\Search\Domain\StrictRangeBound` se queda con los formatos, los tres gates y la normalización a
  UTC; **no** con `Filter` ni con `InvalidSearchValue`: qué error es un rechazo, con qué campo y qué
  posición, sigue siendo del applier que posee el contrato de error. `dateTimeBound` queda en una línea.
  `scalarValue` NO se extrae — dos ocurrencias de ocho líneas atadas al mensaje de error del llamante,
  Regla de Tres.
  **Un hallazgo del Edge Case Hunter que seguía sin fijar, ahora pinchado**: los límites inclusivos del
  offset (`+14:00` y `-12:00`) no los cubría nada; las suites viejas prueban `+25:00` y `-13:00`, que caen
  fuera. Medido: al volver `<=`/`>=` en `<`/`>`, el test nuevo da **2 fallos** y las dos suites viejas
  siguen **verdes** — el guardián no existía.
  Coste pagado: 20 tests unitarios nuevos sin base de datos donde antes toda la cobertura del parseo era
  funcional y pasaba por dos appliers.

- `2026-09-22` — **Un hallazgo MINOR que se me quedó sin cerrar, encontrado al releer mi propia afirmación.**
  El cuerpo de la PR decía «every `patch` finding was applied» y no era exacto: el Acceptance Auditor
  señaló que `isUnauthenticatedApiDenial` nombraba tres de sus cuatro conjuntos — `isMainRequest()` es
  ámbito de **despacho**, no una propiedad de la denegación — y yo ni lo apliqué ni registré una decisión.
  La guarda vuelve a `onException` como early return propio: el ámbito de despacho es asunto del listener,
  el predicado pasa a decir exactamente lo que testea, y `onException` se queda en 2 returns. El test de
  sub-request sigue vivo — re-falsificado tras el cambio.

- `2026-09-22` — **Las tres decisiones abiertas, resueltas por Sergio.** El bloque congelado se corrige
  (recuento 13/10, reparto 12/9/2, y la matriz de E/S gana la fila de `safePath` que nunca tuvo más las dos
  de los arreglos nuevos), y la restricción de contrato pasa a nombrar sus **tres** excepciones en vez de una.
  **B y C se arreglan aquí**, y el encuadre importa: no *cambian* el contrato, **restauran el que los
  docblocks de ambas clases ya prometían**. Año cero — medido por mí contra el servidor, no sólo por la capa:
  `0000-01-01` devuelve `date/time field value out of range`, mientras `0001-01-01` y `9999-12-31` se
  almacenan; PHP lo parsea, le da offset 0 y hace round-trip byte a byte, así que pasaba **todas** las
  guardas. Con año de cuatro dígitos es el único instante inalmacenable que estos formatos pueden expresar.
  Offset irreal — el applier de auditoría no tenía guarda ninguna y PHP acepta hasta ±99:00 con round-trip
  canónico, así que el mismo valor daba 422 en una lane y un desplazamiento silencioso de hasta cuatro días
  en la otra. **Los tres guards nuevos, falsificados uno a uno**: quitar cada conjunto enrojece (EXIT=2), y
  los dos ficheros vuelven byte a byte por checksum.
  **El gate de idioma se amplía, y la medición previa evitó que fuese decorativo**: leer el tipo de nodo
  *por sí solo* no habría cazado ninguna de las dos cadenas que lo motivaron, porque `del` y `al` son una
  sola palabra función contra un umbral de dos y ni `mapa` ni `flujo` ni `paso` estaban en el léxico. Hacen
  falta las dos mitades, y el texto estático se lee **unido** por sus huecos, porque `${…}` parte la frase en
  trozos que individualmente caen por debajo de todos los umbrales. Al encenderlo **enrojeció el árbol**: una
  tercera cadena, `"Paso"`, en el mismo fichero; y un barrido a mano encontró una cuarta, `"Entre
  bastidores:"`, que el gate ampliado **tampoco** ve — una palabra función y un sustantivo desconocido no
  cruzan ningún umbral. Es la propiedad de suelo-y-no-techo, observada en vez de argumentada. Las cuatro
  traducidas y fijadas verbatim.

- `2026-09-20` — **Review de tres capas (Blind Hunter · Edge Case Hunter · Acceptance Auditor, en paralelo).**
  Ninguna GRAVE; el Blind Hunter **no pudo romper la equivalencia**, verificada método a método por álgebra
  booleana. Aplicados nueve parches; **tres hallazgos los levantó más de una capa a la vez**.
  **El más caro es aritmético y era mío**: el Intent dice «14 ACCEPTED y 9 FALSE_POSITIVE» y la medición dice
  **13 y 10** — la columna por fila del Code Map siempre estuvo bien, así que la tabla contradecía a la prosa
  desde el primer día y sólo la tercera capa lo vio. Corolario: son **8** mal clasificadas, no 7.
  **La fila 6 se revierte** (2 capas): `DAILY_SWEEP_INTERVAL` acoplaba tres cadencias que el docblock de la
  clase argumenta, en negrita, como derivadas independientemente — violaba la restricción de esta misma spec
  sobre nombrar el concepto y no el literal. Reparto final: **12 FIXED / 9 ACCEPTED / 2 FP**.
  **La excepción nueva pasa a `LogicException`**: el censo del commit decía «dos precedentes» y eran cuatro —
  `AggregateRoot::id()` guarda la misma invariante en el kernel compartido y la clasifica como error de
  programación, que es lo correcto cuando el id se mina en proceso antes de persistir.
  **Tres testigos añadidos donde no había ninguno**: la guarda de sub-request del listener (borrarla dejaba la
  suite verde — medido antes y después), los brazos `(root)` y de truncado de `safePath`, y la excepción nueva.
  **Una aserción se retiró por no ser falsable**: pinchar la clase base es tautológico a `level: max` en todas
  sus grafías (`assertInstanceOf` y `get_parent_class`, ambas medidas), y las salidas restantes eran ofuscar la
  referencia o silenciar el analizador.
  **Correcciones de prosa**: la fila 1 decía 5 returns y el issue dice 4; la fila 4 afirmaba que los dos
  `parseStrict` quedaban iguales y no es cierto; el docblock de `INVITED_USER_PREDICATE` decía «same set»
  donde sus dos vecinos dicen «overlapping», que es la palabra que el argumento ABBA necesita; y el alcance del
  write plan eran 10 keys, no 12. **Y el marco «mismos mensajes» era falso**: el mensaje al operador del
  comando SÍ cambió, y ningún test lo afirmaba.
  **Boy-scout**: dos cadenas en castellano en el fichero del flow, invisibles al gate de idioma por
  construcción (sólo lee literales sin sustitución), traducidas.

- `2026-09-20` — **Implementación de los 13 arreglos.** Dos resultados que el spec dejaba abiertos y ahora
  están medidos. **Fila 9:** el falsificador NO se disparó — `@phpstan-var` sobre el `return` pasa
  `make php.stan` con `[OK] No errors`, así que la fila se queda en `FIXED` y `$page` desaparece.
  **Fila 10:** el límite anti-abstracción se resolvió a favor de arreglar, y no por el analizador: el árbol
  ya nombra esta misma condición dos veces (`InvitedIdentityUnavailable::withoutId()` en `SendInvitation` y
  `OrganizationNotProvisioned` en `GrantMembership`), y el comando era el único de los tres con un
  `RuntimeException` sin nombre — tercera ocurrencia, Regla de Tres. La excepción nueva extiende
  `RuntimeException` siguiendo a `PersonReferenceProbeFailed` del mismo módulo, y **no** `DomainException`
  como sus dos hermanos: ellos se alcanzan por HTTP y necesitan un `ProblemDetails.type`; éste sólo lo
  alcanza una consola, y acuñar un nombre de error de wire para una ruta que nunca contesta una petición
  metería en ese vocabulario un miembro que nada puede emitir.
  **Una regresión que cazó PHPStan:** mover `preg_split` delante de la guarda de cadena vacía en `safePath`
  perdía el estrechamiento de `$path` a no-vacío, dejando `$segments[0]` sin garantía. La guarda vuelve a ser
  early return y el `match` cubre los tres casos restantes.
  **Equivalencia del JSX probada, no afirmada:** los dos tests nuevos pasan contra el código nuevo Y contra
  el del baseline (copiado byte a byte, restaurado por checksum), y enrojecen con un espacio plantado,
  devolviendo el diff exacto. Se comparan con `textContent`, nunca con `toHaveTextContent`, que normaliza
  los espacios y habría pasado por encima del defecto vigilado.
  **Rector no reexpandió** la cadena `&&` de la fila 5: la reescritura determinista que hay anotada afecta a
  `||`, no a `&&`.

- `2026-09-20` — **Review 1 (externa, pre-aprobación).** Aplicado: estado objetivo explícito (13/8/2/0);
  «comportamiento observable idéntico» → **contrato funcional idéntico**, que era incompatible con la
  fila 10; *Write Plan* separado con preflight, idempotencia, read-back y aborto; el gate de `git diff`
  dejó de afirmar una propiedad del árbol que no demuestra; issue key distinguida de rule key y añadida
  a las 23 filas; límite anti-abstracción para las filas 5 y 10; precedencia del `match` elevada a
  restricción; constantes que nombran concepto; cobertura por comportamiento, no por método; título a
  «ninguna supresión **nueva**».
  **Rechazado, con motivo:** (a) la «inconsistencia 23 vs 22 filas» — el propio cuerpo de la review la
  desmiente al recontar 23; (b) el marcador `[ERPify …]` en el cuerpo de los comentarios — la precondición
  de cero comentarios está medida, así que ensuciaría texto humano para siempre sin cerrar nada que la
  medición no cierre; (c) rebajar `FIXED` a «resuelto/cerrado según exponga la API» — **medido**: la API
  de este proyecto devuelve literalmente `issueStatus: FIXED` (9 issues así ahora mismo); (d) la
  reestructuración completa de secciones, que rompería el contrato de plantilla que leen los pasos 04–05.

## Design Notes

El reparto `ACCEPTED` vs `FALSE_POSITIVE` es la mitad del valor de esta PR, no cosmética.
`FALSE_POSITIVE` afirma algo sobre el **analizador**; `ACCEPTED` lo afirma sobre **nosotros**. Poner la
primera donde la regla acierta mete una mentira permanente en una herramienta compartida y desarma la
única señal que distingue un bug del analizador de una decisión de diseño.

Forma de la tabla de decisión, que el repo ya usa (`spec-sonar-open-issues.md`, los `preflight()`):

```php
return match (true) {
    \is_bool($value)   => $value ? 'true' : 'false',
    null === $value    => 'null',
    \is_scalar($value) => (string) $value,
    default            => $this->encodedOrMarked($value),
};
```

El orden de los brazos ES la precedencia: el booleano va primero porque `is_scalar(false)` es cierto y
`%s` lo convertiría en cadena vacía.

## Verification

**Commands:**
- `make php.stan` -- 0 errores, tras cada fichero PHP tocado.
- `make php.quality` / `make pwa.quality` -- exit 0 impreso, de corrida fresca.
- `make php.unit` / `make pwa.test.unit` -- verde sin tocar ningún test existente.
- `git diff origin/main... -- api/src api/tests pwa/src pwa/tests | grep -E '^\+[^+]' | grep -nE 'NOSONAR|@phpstan-ignore|eslint-disable|@SuppressWarnings|S1142|S1192|S107|S6772|S1488|S112|return budget'` -- sin salida. Lee **sólo líneas añadidas**: prueba que no se introduce supresión ni jerga, y nada más.
- `sonar api GET "/api/issues/search?…&additionalFields=comments"` sobre las 11 issue keys -- antes (preflight) y después (read-back).

**Manual checks:**
- Render del diálogo de borrado y de la leyenda de `/backoffice/docs/flow` en el stack vivo: el `?` sigue pegado a la etiqueta y la leyenda conserva su separación por `gap-2`.
