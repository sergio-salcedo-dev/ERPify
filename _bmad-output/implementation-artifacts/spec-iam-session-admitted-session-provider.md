---
title: 'Matar el accesor estático de la sesión admitida (residuo 1 de la ronda 3 de #871)'
type: 'chore'
created: '2026-08-29'
status: 'in-review'
baseline_commit: '82db9406'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-deferred-work-sweep.md'
  - '{project-root}/docs/rules/architecture.md'
---

## Intent

**Problema.** `SessionAdmissionGate::admittedSession(Request): ?Session` es un accesor **estático** de una clase
concreta de `Infrastructure\Security`, consumido estáticamente por `MySessionsController` y
`RevokeOtherSessionsController`. La secuencia «resolver la sesión admitida o 401» —cuatro sentencias, dos
excepciones, un fallback— estaba **transcrita literalmente en los dos**. Registrado y no arreglado en la
tercera ronda adversarial de la PR #871 por ser una refactorización, no la regla del boy-scout.

**Lo que se descartó, y por qué importa que se descartara.** El arreglo que el hallazgo registraba era un
puerto `CurrentAdmittedSession` en `Application/`. Se rechazó tras **medirlo**, no por gusto:

- **No tendría consumidores en su propia capa.** El único uso de su puerto hermano `CurrentSessionReference`
  dentro de `Application/` es `StartSession.php:34`, y llama a `set()`. Todos los consumidores de «la sesión
  admitida» son controllers HTTP. Un puerto en `Application` con cero consumidores en `Application` es un
  colaborador de Infrastructure disfrazado de namespace.
- **No eliminaría el estático**: el adaptador tendría que llamarlo igual.

**Un argumento que se usó para rechazarlo y que era FALSO, corregido aquí porque quedó escrito.** Se sostuvo
que un adaptador con `RequestStack` introduciría un fallo en `kernel.terminate` —la pila se vacía en el
`finally` de `HttpKernel::handle()` (`:96`) y el runner llama a `terminate()` después
(`FrankenPhpWorkerRunner.php:74-76`)— *que la forma elegida no puede tener*. La segunda mitad es falsa: el
provider inyecta `CurrentSessionReference`, cuyo único adaptador es `SymfonySessionCorrelationStore`, y su
`get()` hace `requestStack->getCurrentRequest()` (`:35`). Desde `terminate` esta forma da el **mismo** 401,
una línea antes. El rechazo del puerto se sostiene sobre el primer punto —cero consumidores en su capa—, que
sí está medido; el segundo no era una diferencia entre las dos formas.

**Approach.** Un colaborador de `Infrastructure/Security/` que recibe el `Request` **por argumento**:

- `AdmittedSession` — par `final readonly` de `SessionId` + `Session`.
- `AdmittedSessionProvider::requireAdmitted(Request): AdmittedSession` — `final readonly`.

Las cuatro sentencias se mueven **una vez, sin cambiar el orden ni las excepciones**, de modo que la cobertura
Behat existente sigue siendo arnés de regresión válido.

## Boundaries & Constraints

**Always:**

- **El `SessionId` sale de la CORRELACIÓN, jamás del agregado.** `SessionId::fromString` llama a `Uuid::ensure`
  (`SessionId.php:36`), que lanza `InvalidUuidException` → **400 `invalid-uuid`**, en un camino cuyas únicas
  salidas son 401 y 503. Ese 400 es inalcanzable en la práctica porque la columna es un `UUID` nativo de
  PostgreSQL (`Version20260710192657.php:28`), y ése es justamente el problema: la garantía viviría en el tipo
  de columna y no en el código, o sea no sería falsable. Por eso el par lleva dos miembros y no uno.
- **Ambas clases son `final readonly`, y es una garantía de ciclo de vida, no de estilo.** FrankenPHP corre en
  modo worker (`FRANKENPHP_RESET_KERNEL` no está puesto en ningún sitio del repo, verificado), así que el
  contenedor sobrevive a la petición y una sesión memoizada se serviría a la SIGUIENTE.
- **El nombre de las clases nuevas no puede contener `SessionAdmissionGate` ni `UserProvider`.** Ver el gate
  nuevo, abajo.
- **`SessionAdmissionGate` conserva su propia resolución.** El refactor va de 3 sitios a **2**, no a 1, y eso
  se dice en voz alta en vez de fingir lo contrario: el gate además decide ADMISIÓN (tira la sesión nativa al
  rechazar), y dos sitios con semánticas de rechazo distintas no ganan una tercera abstracción.

## Tasks

1. `AdmittedSession` y `AdmittedSessionProvider` en `api/src/Iam/Session/Infrastructure/Security/`.
2. `MySessionsController` y `RevokeOtherSessionsController` delegan; el segundo pierde **dos** dependencias
   (`CurrentSessionReference` y `SessionRepository`).
3. `AdmittedSessionProviderTest` — 7 casos, primera cobertura que la rama de fallback ha tenido nunca.
4. Escenario aislado del presupuesto de queries de `POST /sessions/revoke-others`.
5. `QueryBudgetExclusionMarkerGateTest` + su línea en `api/.artifact-gate-placement`.

## Hallazgos encontrados por el camino (cerrados aquí, no diferidos)

**El comentario de `session.feature` afirmaba una cobertura que no tenía.** Decía que sin su conteo de queries
*«both controllers could be reverted to their own lookup with every test green»*, pero la aserción existía
**sólo** en el escenario de `GET /sessions`. Revertir la optimización en `RevokeOtherSessionsController` dejaba
toda la suite verde. Cerrado con un escenario aislado (el conteo se resetea por ESCENARIO, no por petición, así
que no podía añadirse al escenario existente: habría sumado tres peticiones). **Falsado**: con el fallback
forzado a consultar siempre, `GET /sessions` va 2→3 y `revoke-others` 9→10; antes sólo habría disparado el
primero.

**El arnés del presupuesto excluye por SUBCADENA, y nadie lo vigilaba.**
`api/tests/Doctrine/TestDebugDataHolder.php` descarta toda consulta cuyo backtrace contenga una clase cuyo
nombre contenga `UserProvider` (`:194`) o `SessionAdmissionGate` (`:217`). Un segundo portador de cualquiera de
las dos vaciaría los presupuestos **en silencio**. `UserProvider` es el filo peligroso: es un nombre genérico,
una segunda implementación es algo ordinario de añadir, y su exclusión afecta a **todos** los presupuestos de
la suite, no a los de una feature. Cerrado con `QueryBudgetExclusionMarkerGateTest`, que deriva los marcadores
del arnés en vez de repetirlos.

## Verificación

Todas sobre el árbol final, ejecución fresca, código de salida impreso:

| Gate | Exit | Medido |
|---|---:|---|
| `make php.quality` | 0 | |
| `make php.quality.dry-run` | 0 | la variante que corre CI |
| `make php.unit` | 0 | 3323 tests, 15308 aserciones, 2 skipped (base: 3315) |
| `make php.behat` | 0 | 472 escenarios, 4378 pasos (base: 471 / 4374) |
| `make php.lint.gate-placement` | 0 | |

### Matriz de mutación — cada falsador provocado en rojo por separado

| Mutación | Enrojece |
|---|---|
| M1 · derivar el id de la entidad | T1 (sola) |
| M2 · ignorar el atributo publicado y consultar siempre | T1, T2 |
| M3 · borrar la rama de fallback | T3, T6 |
| M5 · envolver el fallback en `try/catch` | T6 (sola) |
| M6 · quitar `readonly` | T7 (sola) |
| M7 · borrar la guarda final | T5 (sola) |
| M8 · borrar la guarda de correlación | T4 (sola) |
| M9 · el provider consulta siempre (Behat) | presupuestos 2→3 y 9→10 |
| M10 · el fallback consulta por un id ajeno | T3 (sola) |

No hay M4: se buscó una mutación que enrojeciera **sólo** la guarda de correlación por su cláusula «no toca el
almacén» y toda candidata plausible resultaba o bien un error de tipos que PHPStan ya rechaza, o bien
indistinguible de M8. Se deja dicho en vez de numerar un hueco.

Gate nuevo, falsado en tres direcciones: segundo portador de `SessionAdmissionGate` → rojo; segundo portador
de `UserProvider` → rojo; helper renombrado en el arnés → rojo. Control tras restaurar → verde.

Toda restauración tras mutar fue por **copia de bytes** desde una copia pristina, nunca `git checkout --`.

**Lo que un verde aquí NO prueba.** No se ejecutó `pwa.test.e2e` (este cambio no toca `pwa/`). El barrido del
gate nuevo lee sólo `api/src`: la exclusión casa cualquier clase del backtrace, `vendor/` incluido, y
`UserProvider` es una convención de nombres de Symfony Security, así que una clase del framework que la lleve
también descarta consultas y es invisible aquí. El presupuesto de 9 acarrea las escrituras de la propia
revocación: lo que pincha es la AUSENCIA de una consulta más, no la composición de las nueve.

## Ronda 2 — la puerta de cobertura de código nuevo

Todo lo demás de la PR estaba verde; el único rojo era el quality gate de Sonar, `new_coverage` **68,4 %**
contra un umbral de 80. El resto de condiciones OK (`new_reliability_rating` 1, `new_security_rating` 1,
`new_maintainability_rating` 1, `new_duplicated_lines_density` 0,0, `new_security_hotspots_reviewed` 100) y
cero issues para la PR, así que era cobertura y no un defecto.

**No era atribución, y eso se midió antes de escribir una línea.** Ambas clases nuevas ya llevaban su
`#[CoversClass]` y `AdmittedSessionProvider` marcaba 100 %. El desglose por fichero:

| Fichero | Líneas nuevas cubribles | Sin cubrir |
|---|---:|---:|
| `AdmittedSessionProvider.php` | 11 | 0 |
| `AdmittedSession.php` | 4 | 2 — `userId()` |
| `MySessionsController.php` | 2 | 2 |
| `RevokeOtherSessionsController.php` | 2 | 2 |

13 de 19 = 68,42 %, que es exactamente la cifra del gate. Los dos controllers estaban a **0 % también en
`main`**: Behat los ejercita y no alimenta clover en absoluto. Ese 0 % de `main` se leyó del propio Sonar
(`get_file_coverage_details` sobre la rama `main`: 15 líneas cubribles, 15 sin cubrir, frente a 9 y 9 en la
PR) — **conteo de Sonar, no de clover**, que cuenta distinto; el clover local da 7 sentencias para el mismo
fichero. La conclusión no depende de cuál se use: cero cubiertas en ambos.

**Lo que se añade no es relleno para el gate.** Los casos nuevos pinchan lo único que ninguna otra cosa
puede pinchar: producción mantiene de acuerdo a los dos miembros del par (el gate publica la misma fila que
cargó para el id correlacionado), así que **ningún escenario de aceptación, ni el presupuesto de consultas
que ya existe, distingue «pedirle el id al par» de «alcanzarlo a través de la entidad»**. Es el mismo
argumento que el docblock de `AdmittedSessionProviderTest` hace para sí mismo, una capa más arriba.

Ese desacuerdo tiene dirección: un controller que exceptuara la fila *acarreada* revocaría el dispositivo
del propio llamante y dejaría vivo otro.

**Las guardas de vacuidad leen el par del que cada caso depende, y fallan CERRADAS.** Ese es el hallazgo
GRAVE del pase adversarial, y merece decirse aquí porque la primera versión no lo hacía: una guarda que
compara contra la constante de OTRA clase sólo es equivalente a la invariante por coincidencia. Medido:
`CORRELATED_ID` y `SessionMother::DEFAULT_ID` son el mismo literal en dos ficheros sin vínculo. Ahora cada
guarda lee por reflexión las constantes de su propia clase y afirma que la lectura encontró algo antes de
comparar — `getConstant()` devuelve `false` para un nombre que ya no existe, y `assertNotSame('<uuid>',
false)` pasa, así que sin esa aserción un renombrado dejaba la guarda decorativa.

**Un trait y una factoría, y el motivo del trait es una presión de diseño real.** PHPMD
`CouplingBetweenObjects` (límite 13, sin baseline y sin exclusión para `tests`) marcó 20 y 16.
`AdmitsASessionRequest` tiene dos consumidores. `ResourceResponderBuilder` vive en `api/tests/Support/`
junto a `PhpSource`, con su test en `tests/Unit/Support/`: es una factoría estática, no un trait — no toca
`$this`, así que un trait sólo le habría negado usarse desde un data provider o un `setUpBeforeClass`.
Ensambla colaboradores `final readonly` en vez de abstraer sobre ellos, y **lleva sus metadatos de
serialización**: un `new ObjectNormalizer()` desnudo no lee ninguno, así que un `#[SerializedName]` sobre un
Resource DTO sería invisible aquí y efectivo en producción — `ResourceDtoContractTest` cierra la dirección
del tipo no escalar, nunca la del renombrado. `ResourceResponderBuilderTest` hace esa mitad falsable.

Sigue sin ser el servicio del contenedor: éste lleva un normalizador y ningún encoder, y aquél el
`serializer` completo. Para un Resource DTO plano y escalar-only por contrato eso basta; un DTO que se
saliera de ese contrato lo detiene `ResourceDtoContractTest` antes.

### Verificación — ronda 2

Ejecución fresca sobre el árbol final, código de salida impreso:

| Gate | Exit | Medido |
|---|---:|---|
| `make php.quality` | 0 | los fixers no tocaron nada fuera de los ficheros de esta ronda |
| `make php.quality.dry-run` | 0 | la variante que corre CI — enrojeció primero, ver abajo |
| `make php.md` | 0 | 0 violaciones (era 2 antes de extraer el trait) |
| `make php.stan` | 0 | 1545 ficheros, sin errores |
| `make php.lint.gate-placement` | 0 | con la línea nueva del registro |
| `make php.unit.coverage` | 0 | clover: **0 sentencias sin cubrir** en los cuatro ficheros (22 cubribles) |

`php.quality` y `php.quality.dry-run` **no son intercambiables aquí**, y esta ronda lo midió: con el builder
en `tests/Unit/Shared/…`, el modo apply escribía `@see ResourceResponderBuilderTest` y el dry-run pedía la
forma FQCN — verde en local, `Error 2` en CI. Se resolvió colocándolo donde el precedente lo pone
(`tests/Support`, como `PhpSource`), no suprimiendo la regla.

### Matriz de mutación — ronda 2

Cada una provocada por separado contra la forma **final** de los tests, y restaurada por **copia de bytes**
desde una copia pristina, nunca `git checkout --`:

| Mutación | Enrojece |
|---|---|
| F1 · `MySessionsController` deriva el id del mapper de la entidad | «flags the correlated device» (sola) |
| F2 · `MySessionsController` lista por el id de sesión en vez de por el sujeto | los dos casos del fichero |
| F3 · `RevokeOtherSessionsController` exceptúa la fila acarreada | «spares the correlated session» (sola) |
| F4 · `AdmittedSession::userId()` deriva el sujeto del id | «the subject it delegates» (sola) |
| G1 · `A_DIFFERENT_ID` pasa a coincidir con `CORRELATED_ID` | la guarda de «comes from the correlation» |
| S1a · `A_SUBJECT_ID` pasa a coincidir con `CORRELATED_ID` | la guarda de «the subject it delegates» |
| S1b · `SUBJECT_ID` del trait pasa a coincidir con `CORRELATED_ID` | las dos guardas de sujeto de los controllers |
| S2 · la lectura por reflexión apunta a un nombre inexistente | las dos guardas del trait (antes pasaba) |
| S3 · el normalizador pierde sus metadatos | `ResourceResponderBuilderTest` (sola) |

G1 y S2 son las que importan: **bajo la forma anterior de las guardas, las dos pasaban en verde**. Es la
diferencia entre una guarda y un comentario que dice que hay una.

Dos ejecuciones intermedias murieron con `Error 137` (OOM del contenedor `php`, con tres stacks Docker
arriba) y se repitieron: un exit distinto de cero cuyo log no contiene un fallo de aserción no es una
falsificación.

**Lo que un verde aquí NO prueba.**

1. La cobertura sigue siendo de PHPUnit: Behat no alimenta clover, así que los escenarios de aceptación de
   `session.feature` no cuentan.
2. Por `#[CoversClass]`, la cadena responder/mapper/`RevokeOtherSessions` **no recibe crédito de cobertura**
   desde los tests de controller aunque se ejecute entera en ellos.
3. El caso del sujeto afirma a quién se le piden las sesiones, no que la respuesta las filtre.
4. `ResourceResponderBuilder` no es el servicio del contenedor (un normalizador, ningún encoder).
5. Nada pincha la consistencia interna del par en la ruta real (`$admitted->session->getId() ===
   $admitted->id->toString()`), mientras cuatro casos normalizan deliberadamente lo contrario. Es
   endurecimiento barato y no un defecto vivo: `SessionAdmissionGate` publica la misma fila que cargó.
6. Los dobles NO son todos de árbol. El listado usa `InMemorySessionRepository` (y por eso el orden asertado
   es una propiedad del puerto), pero los dos casos con expectativas usan `createMock(SessionRepository)`,
   que es lo que `docs/rules/testing.md` pide cuando hay `expects()`.

## Adversarial pass

Lectura hostil en contexto fresco e independiente sobre el diff completo, antes de abrir la PR. **Cambió el
resultado, no lo confirmó**: tres GRAVE y cinco SERIO, todos verificados contra el árbol antes de actuar y
**dos de ellos falsados empíricamente** (el gate pasaba verde con el defecto puesto).

**GRAVE · G1 — el docblock que justificaba el diseño era falso.** Afirmaba que pasar el `Request` por
argumento evita el fallo de `kernel.terminate` «que esta forma no puede tener». Puede: el provider depende
transitivamente de `RequestStack` vía `SymfonySessionCorrelationStore:28,35`. **Corregido** en el docblock y
arriba en este spec; lo que el argumento sí compra se enuncia ahora en su tamaño real.

**GRAVE · G2 — la regex del gate se deslizaba fuera del cuerpo de la función.** `.*?` con `/s` es perezoso
pero ilimitado: en cuanto el primer helper dejara de casar la forma literal (comillas dobles, una constante,
la llamada partida en dos líneas), el match saltaba al literal del segundo helper, los dos marcadores salían
iguales y `UserProvider` quedaba sin vigilar **en verde**. **Falsado empíricamente**: comillas dobles +
`CachingUserProvider` → el gate viejo exit 0. **Corregido** extrayendo el cuerpo de cada helper, aceptando
ambos estilos de comilla y afirmando que los marcadores son distintos.

**GRAVE · G3 — la aserción «≥2 exclusiones» no podía fallar.** `$markers` se construía iterando una lista
literal de dos elementos, así que su cuenta era siempre 2. Tautología con un mensaje que describía una
condición que estructuralmente no podía observar — y el hueco real (un tercer helper de exclusión) no lo
cubría nadie. **Corregido** derivando los helpers del propio arnés; la cuenta ahora es sobre un universo
descubierto. **Falsado**: un `isMercureLookup` añadido con dos portadores → rojo.

**SERIO · S1 — el comentario del presupuesto atribuía mal el 9.** Decía que el número era mayor «porque
carga las escrituras de la propia revocación». Sólo **una** de las nueve es la revocación (un `UPDATE`
masivo); el resto es la envoltura transaccional y el `event_store` + catch-up de proyecciones del outbox, y
**ninguna** es auditoría, porque `AuditPolicy::isGenericActivityRead:48` exige `GET`. **Corregido**: el
comentario dice ahora qué pincha (la ausencia de UNA consulta más) y nombra los dos cambios previstos que lo
moverán sin ser regresiones.

**SERIO · S2 — el gate comparaba basenames y el arnés compara FQCN.** `Erpify\Shared\UserProvider\Resolver`
es un portador que su basename esconde. **Falsado empíricamente**: el gate viejo exit 0 con ese fichero
presente. **Corregido**: se compara un pseudo-FQCN PSR-4 derivado de la ruta.

**SERIO · S3 — el test del fallback no pinchaba el ARGUMENTO.** `createStub`+`willReturn` responde a
cualquier id, así que «¿puede el fallback consultar por un id que no es del llamante?» quedaba sin cubrir por
los siete casos. **Corregido** con `expects(once())->with(...)`, y **falsado**: consultar por un id ajeno
enrojece ahora ese caso y sólo ese.

**SERIO · S4 — la garantía de `readonly` estaba sobrevendida.** «Memoising becomes a compile error» es falso
para una `static` dentro del cuerpo del método, que es la forma más natural de escribir un memo. Y el mensaje
llamaba «servicio mutable» a `AdmittedSession`, que es un valor. **Corregido**: se enuncia como suelo, no
como prueba, y cada mitad con su motivo real.

**SERIO · S5 — comentario relativo al cambio.** «previously transcribed into each of them» está prohibido por
`CLAUDE.md`. **Borrado.**

**MENORES atendidos**: la lista de puntos ciegos del gate ahora nombra `api/tests` (cuatro portadores ya
existentes, y `tests/Unit/Doctrine/Stubs/` como el quinto probable) además de `vendor/`; `A_DIFFERENT_ID`
lleva una aserción de que sigue difiriendo de `SessionMother::DEFAULT_ID`, sin la cual el único caso que
distingue correlación de entidad se volvería «x es igual a x» en silencio; `RecursiveDirectoryIterator` usa
`SKIP_DOTS`; el tren de choque `$current->session->userId()` que el diff introducía en dos sitios se
sustituye por `AdmittedSession::userId()`; y la justificación del escenario aislado dice ahora «atribución» y
no «una suma no pincharía», que era falso.

**MENORES registrados y NO arreglados, con su motivo**:

1. **Los dos helpers de exclusión siguen sin cobertura de comportamiento.** `TestDebugDataHolderTest` no
   ejerce ninguno; este gate lee su TEXTO, nadie comprueba que descarten nada. Es trabajo del arnés, no de
   esta rama, y abrirlo aquí arrastra la suite de Doctrine entera.
2. **`DoctrineSessionRepository:33-34,227-229` queda menos cierto.** Dice que «revoke-others corre la lectura
   del listado y luego el UPDATE»; tras el cambio ese controller no toca el repositorio, y «the listing read»
   ya era el nombre equivocado antes (`findByUserId` es el listado). Impreciso antes, más impreciso ahora, y
   fuera del alcance de esta rama.
3. **El estático sobrevive con un llamante.** El título del trabajo promete más de lo que entrega: mejora la
   testabilidad (inyectable), no la inversión de dependencias — los controllers dependen ahora de otra clase
   concreta sin interfaz. Argumentado arriba y dicho en voz alta aquí.
4. **La rama de fallback parece muerta en todo camino real** y se conserva —con su primer test— porque este
   refactor es de equivalencia. Borrarla es un cambio de comportamiento y una decisión aparte.

**Lo que el pase NO pudo verificar**: no ejecutó nada (los exit 0 de la tabla de arriba son de esta sesión,
no suyos), no reprodujo la matriz de mutación, y la composición de las nueve consultas la derivó por lectura
— la parte que sí se verificó aquí es que la revocación es una sola sentencia y que un POST no se audita.

### Pase adversarial — ronda 2 (cobertura)

Segunda lectura hostil, contexto fresco e independiente, sobre el diff completo de esta ronda y **antes de
commitear**. **Cambió el resultado, no lo confirmó**: 1 GRAVE, 5 SERIO y 7 MENOR. Todos verificados contra
el árbol antes de actuar; todos aplicados salvo los que se registran abajo como no-hallazgos.

**GRAVE · G1 — la guarda de vacuidad comparaba el par equivocado.**
`AdmittedSessionProviderTest::testTheIdItAnswersWithComesFromTheCorrelationAndNeverFromTheEntity` depende de
`A_DIFFERENT_ID ≠ CORRELATED_ID`, y la guarda afirmaba `A_DIFFERENT_ID ≠ SessionMother::DEFAULT_ID`. Sólo
resultaba equivalente por coincidencia: **verificado, `CORRELATED_ID` y `SessionMother::DEFAULT_ID` son el
mismo literal `…5c6d` en dos ficheros sin vínculo alguno**. Poner `A_DIFFERENT_ID = CORRELATED_ID` dejaba la
guarda verde y el caso pasando con las dos implementaciones — es decir, exactamente la vacuidad que su propio
comentario decía impedir. **Corregido** con un helper que lee por reflexión las constantes de la propia
clase, y **falsado**: la mutación que antes pasaba ahora enrojece.

**SERIO · S1 — los dos casos sobre el SUJETO llegaban sin guarda.** La ronda entera se argumenta sobre
«una guarda impide que se vuelva vacuo» y los casos que añade sobre el otro eje no tenían ninguna. El eje es
de autorización: con los literales coincidiendo, «el listado y la revocación van contra el sujeto admitido»
se pondría verde sobre un controller cableado al id de sesión. **Corregido** con
`assertTheSubjectDisagreesWithTheCorrelation()` y falsado en los dos ficheros.

**SERIO · S2 — la guarda por reflexión fallaba ABIERTA.** `ReflectionClass::getConstant()` devuelve `false`
para un nombre inexistente y `assertNotSame('<uuid>', false)` pasa, así que un renombrado la dejaba
decorativa sin romper nada. **Corregido** afirmando que la lectura encontró algo antes de comparar, y
falsado apuntando la lectura a un nombre que no existe.

**SERIO · S3 — el builder no era la cadena de producción en el eje que decide el cable.** Verificado contra
el contenedor compilado: el `ObjectNormalizer` inyectado lleva `ClassMetadataFactory` y name converter, y el
del builder no llevaba ninguno — así que `#[SerializedName]`, `#[SerializedPath]` e `#[Ignore]` sobre un
Resource DTO serían invisibles en test y efectivos en producción. **Corregido** cableando los metadatos, y
la afirmación pasó de docblock a test: `ResourceResponderBuilderTest`, falsado quitándolos.

**SERIO · S4 — `assertSame` sobre el payload entero pagaba la fragilidad de cinco clases sin cobrar la
cobertura de ninguna.** Sensible al orden de claves, así que reordenar el constructor de `SessionResource`
—invisible para cualquier consumidor JSON— lo enrojecía; y por `#[CoversClass]` ninguna de esas clases
recibía crédito. **Corregido**: el caso afirma ahora sólo su propia claim (qué fila lleva `current`), lo que
además eliminó toda la maquinaria de congelar el reloj ambiental.

**SERIO · S5 — afirmación falsa en este artefacto sobre los dobles.** Decía «los dobles son de árbol, según
la regla del proyecto»; los dos casos con expectativas usan `createMock(SessionRepository)`, y la regla
citada no existe con ese contenido — `docs/rules/testing.md` regula `createStub` vs `createMock`, que sí se
cumple. **Corregido arriba**, y el listado pasó a `InMemorySessionRepository`, con lo que el orden asertado
es ahora una propiedad del puerto y no del doble.

**MENORES atendidos**: `api/config/reference.php` había quedado modificado por la regeneración del
contenedor y se restauró en vez de entrar en un commit sólo-de-tests (nunca con `git add -A`); el builder
pasó de trait a factoría estática en `tests/Support` — no tocaba `$this`, y como trait no podía usarse desde
un data provider; el `FROZEN_INSTANT` del test de revocación se renombró a `USE_CASE_INSTANT`, porque en el
fichero hermano el mismo nombre designaba una congelación ambiental; el docblock que prometía pinchar
«delegado en vez de navegado» dice ahora que esa mitad no la pincha nada; y la lista de *lo que un verde NO
prueba* incorpora las cinco ausencias que el pase nombró.

**MENOR registrado y NO arreglado, con su motivo**: el conteo «15 líneas cubribles antes» es de Sonar y no
del clover, que cuenta distinto — se dice ahora de dónde sale en vez de dejarlo como una cifra sin fuente.

**Lo que el pase NO pudo verificar**: no ejecutó nada (los exit 0 de la tabla son de esta sesión, no suyos),
no provocó las mutaciones F1–F4 —las razonó por lectura— y no consultó Sonar; la única medición ajena que sí
auditó es el clover, y salió exacta al dígito. El servidor MCP de GitHub estaba caído (401), así que tampoco
pudo leer el estado remoto de la PR.
