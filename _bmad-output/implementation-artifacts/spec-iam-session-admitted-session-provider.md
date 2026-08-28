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
