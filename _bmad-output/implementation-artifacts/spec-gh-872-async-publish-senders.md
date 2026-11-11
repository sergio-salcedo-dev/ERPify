---
title: 'gh-872: todos los senders de un mensaje vigilado son async, y el primer publicador de ImageDeletionRequested se para en un gate'
type: 'bugfix'
created: '2026-11-11'
status: 'done'
baseline_commit: 'b4883b536c48d1f4604d545741a80de7cdced5da'
route: 'dispatch'
review_loop_iteration: 1
context:
  - '{project-root}/docs/adr/image-deletion-signal-transport.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `RequireTransactionalPublishMiddleware` solo inspecciona `async`. Un `DomainEvent` enrutado únicamente a otro transporte recibe solo la comprobación de transacción, y un mensaje enrutado a `async` **y** a otro transporte pasa sin que nadie inspeccione el segundo. En ambos casos la publicación sale de la transacción sin ninguna alarma. Además, el único residual vigilado de #872 (el primer publicador real de `ImageDeletionRequested`, cuyo consumidor destruye bytes de forma irreversible) depende de que alguien lea una issue abierta, y no hay nada que salte cuando ese publicador aparece.

**Approach:** Endurecer el guard para que el invariante sea «si un mensaje vigilado (un `DomainEvent`, o cualquier mensaje enrutado a `async`) tiene senders, todos son `async` o `sync`», y que lo contrario sea un rechazo antes de escribir nada. **Decisión del usuario (2026-11-11, revisión 1):** se admite `sync` además de `async`, porque `SyncTransport` vuelve a despachar en línea dentro de la transacción y la política de transporte persistente ya lo sanciona como vía de escape. Añadir un artifact gate que fije el conjunto de ficheros de `api/src` que nombran `ImageDeletionRequested`: un publicador nuevo lo pone en rojo hasta que se registre junto a un test funcional que exista. #872 sigue **abierta**, acotada a ese publicador (decisión del usuario tras consultar a un tercero: opción 2).

## Boundaries & Constraints

**Always:**
- Un `DomainEvent` **sin enrutar** sigue siendo válido: se maneja en proceso a propósito (16 de los 24 de `api/src`, entre ellos los eventos sobre personas). Solo se rechazan los enrutados que tengan algún sender distinto de `async` y `sync`.
- `sync` es un sender admitido: corre en proceso dentro de la transacción, y `api/.persistent-transport-policy` lo sanciona. El guard y esa política no pueden contradecirse.
- Un sobre recibido (`ReceivedStamp`) sigue pasando sin comprobar.
- El rechazo ocurre antes de `PersistDomainEventMiddleware`, así que no escribe ni en `event_store` ni en el outbox.
- El mensaje de error nombra solo clases y alias de transporte, nunca un DSN.
- El gate debe declarar en su cabecera qué prueba un verde y qué no. En particular, que la existencia de un test no demuestra que la transacción del publicador sea la de la escritura de negocio. Su mensaje de fallo debe incluir la receta de prueba.
- Las etiquetas `@accepted-risk #872` se mantienen y la issue no se cierra.

**Never:**
- No se valida «cualquier transporte transaccional» de forma genérica: los únicos senders admitidos son `async` (que además se valida) y `sync`. `failed` se rechaza aunque sea Doctrine sobre la misma conexión: es la cola de fallos, no un destino de enrutamiento.
- No se añade ningún helper de test para el publicador futuro (YAGNI).
- No se crea ningún publicador de `ImageDeletionRequested`.
- No se edita la issue #872 antes de que terminen las tres capas de revisión.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Enrutado solo a async | `DomainEvent` → `[async]`, en transacción, async Doctrine | pasa | N/A |
| Sin enrutar | `DomainEvent` sin senders, en transacción | pasa | N/A |
| Solo a otro alias | `DomainEvent` → `[failed]` | rechazo | `MessageRoutedOffTransactionalTransport` nombrando el alias |
| Solo a sync | `DomainEvent` → `[sync]`, en transacción | pasa | N/A |
| async + sync | `DomainEvent` → `[async, sync]`, en transacción, async Doctrine | pasa | N/A |
| async + otro | cualquier mensaje → `[async, failed]` | rechazo | ídem; nombra `failed` |
| No vigilado | `stdClass` → `[failed]` | pasa (no es `DomainEvent` ni va a async) | N/A |
| Recibido | cualquiera con `ReceivedStamp` | pasa | N/A |
| Fuera de transacción | `DomainEvent` → `[failed]`, sin transacción | rechazo | se mantiene `PublishedOutsideTransaction` (primera comprobación) |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Event/Infrastructure/Messenger/RequireTransactionalPublishMiddleware.php` -- `guard()` y `isRoutedToAsync()`: hoy hace `array_key_exists('async', iterator_to_array($senders))`. Hay que devolver los alias y comprobar que no haya ninguno distinto de `async`. Conservar el `catch (Throwable)` → `AsyncTransportNotTransactional`. Actualizar el docblock.
- `api/src/Shared/Event/Infrastructure/Messenger/AsyncTransportNotTransactional.php` -- modelo de la excepción nueva. No se reutiliza porque su remedio («arregla MESSENGER_TRANSPORT_DSN») no corresponde a un problema de enrutamiento.
- `api/tests/Unit/Shared/Event/Infrastructure/Messenger/RequireTransactionalPublishRoutingTest.php` -- monta el guard con el `SendersLocator` real. Los casos nuevos van aquí. `aMessageWhoseOnlySenderIsAnotherAliasIsNotTreatedAsRouted` (`stdClass` → `failed`) sigue verde.
- `api/tests/Unit/Shared/Event/Infrastructure/Messenger/BuildsPublishGuard.php` -- trait de construcción, para reutilizar.
- `api/tests/Unit/Gate/MessengerSendBoundaryGateTest.php` -- plantilla del gate nuevo (`ApiSourceFiles::phpFiles()`, normalización de `\\`, autoprueba del barrido).
- `api/src/Shared/Images/{Domain/Event/ImageDeletionRequested.php, Domain/Storage/ImageStorage.php, Infrastructure/Messenger/DeleteImageOnDeletionRequested.php}` -- los tres ficheros que hoy nombran la clase. Ninguno la publica.
- `make/php-quality.mk:193` -- `php.lint.persistent-transport`, con un `--filter` por clase. El gate nuevo entra ahí.
- `api/.artifact-gate-placement` -- clasificar el gate nuevo como `home`.
- `PRODUCTION_SECURITY_CHECKLIST.md:2492` -- fila de #872 en el registro de §7, que hoy es una celda de unas 400 palabras.
- `api/tests/Support/MessengerRoutingConfig.php` (`NON_PERSISTED_TRANSPORT = 'sync'`, resolución de cada forma de enrutamiento: clase, padre, interfaz, comodín, `#[AsMessage]`, cada entorno) y `api/tests/Unit/Gate/PersistentTransportRoutingShapeGateTest.php` -- base para la comprobación en build del invariante de senders.
- `api/src/Backoffice/Bank/Application/BankCreator.php:39`, `BankDeleter.php:46-53` -- forma real de publicar: el agregado hace `record(new X(...))` y el caso de uso publica `pullDomainEvents()` sin nombrar la clase del evento.
- `docs/architecture-api.md:317` -- descripción del guard en la arquitectura; hay que ponerla al día.
- `docs/adr/image-deletion-signal-transport.md` D2/D3 -- dueño del detalle de lo que ya no se acepta.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Event/Infrastructure/Messenger/MessageRoutedOffTransactionalTransport.php` -- crear una `RuntimeException` (clase del mensaje + alias ofensivos; remedio: enrutar solo a `async` o dejarlo sin enrutar) -- el diagnóstico es distinto al del DSN
- [x] `api/src/Shared/Event/Infrastructure/Messenger/RequireTransactionalPublishMiddleware.php` -- calcular los alias de los senders. Vigilado = `DomainEvent` o enrutado a async. Orden: transacción → alias distintos de `async` → validador de async. Docblock al día -- cierra el punto ciego B
- [x] `api/tests/Unit/Shared/Event/Infrastructure/Messenger/RequireTransactionalPublishRoutingTest.php` -- cubrir las filas de la matriz: solo otro alias, async + otro, `sync`, sin enrutar, `stdClass` no vigilado -- red por cada rama
- [x] `api/tests/Unit/Gate/ImageDeletionRequestedPublisherGateTest.php` -- fijar `NAMING_SITES` (los 3 ficheros actuales) y `PUBLISHERS` (`ruta => test funcional`, hoy vacío). Asertar que los ficheros que nombran la clase sean exactamente la unión de ambos, que cada test exista bajo `api/tests` y nombre al publicador, y que el barrido alcance el árbol. Mensaje de fallo con la receta y #872 -- convierte el disparador en un rojo
- [x] `make/php-quality.mk`, `api/.artifact-gate-placement` -- cablear y clasificar el gate
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` -- reducir la fila de #872 a riesgo, disparador más receta y aceptado. Quitar B de los puntos ciegos y llevar el detalle de lo ya cerrado a D3 del ADR, si no está ya allí
- [x] `docs/adr/image-deletion-signal-transport.md` -- D3: recoger el detalle movido, el invariante de senders y el gate. Las etiquetas se mantienen
- [ ] Después de las tres capas de revisión: reescribir el cuerpo de #872 con el estado actual, dejando la historia en los comentarios

**Revisión 1 (patches y enmiendas):**
- [x] Guard y excepción: admitir `sync` según la matriz. El texto de `MessageRoutedOffTransactionalTransport`, el docblock del middleware y el de `RequireTransactionalPublishSendersTest` dicen que `async` y `sync` son los senders **admitidos**, no que sean los únicos transaccionales (`failed` es Doctrine sobre la misma conexión).
- [x] `RequireTransactionalPublishSendersTest`: cubrir las filas «Solo a sync» y «async + sync»; un caso con dos alias fuera (`implode`); renombrar `aStampAddingAnotherAliasBesideAsyncIsRefused` (el stamp sustituye el enrutamiento, no añade); quitar `anUnguardedMessageRoutedToAnotherAliasPassesInsideATransaction`, que duplica `RoutingTest`.
- [x] `api/tests/Functional/Shared/Event/RequireTransactionalPublishFunctionalTest.php`: en el bus del contenedor, un `DomainEvent` con `TransportNamesStamp(['failed'])` dentro de una transacción lanza `MessageRoutedOffTransactionalTransport` y no deja fila en `event_store`.
- [x] Comprobación en build del invariante: en `PersistentTransportRoutingShapeGateTest` (o su motor en `api/tests/Support/`), toda clase `DomainEvent` de `api/src` se resuelve, con la configuración real de cada entorno, a senders ⊆ {`async`, `sync`}; con un caso plantado que lo ponga en rojo (`failed`, y `async`+`failed`). Su cabecera nombra lo que no ve (`TransportNamesStamp`, mensajes que no son `DomainEvent`).
- [x] Gate del publicador: la clave de `PUBLISHERS` es el **sitio que crea el evento** (`new ImageDeletionRequested` o su factoría: en este código, normalmente el agregado). Su test funcional declarado tiene que llamar al **caso de uso** que publica lo que ese sitio registra. `NAMING_SITES` queda solo para la declaración, el handler y la documentación, y la receta deja de ofrecer `NAMING_SITES` como salida para un fichero que construya el evento: el gate falla en cualquier fichero nuevo que contenga `new ImageDeletionRequested` o `ImageDeletionRequested::` fuera de la propia clase y que no esté en `PUBLISHERS`. La cabecera nombra el punto ciego: un segundo caso de uso que reutilice un método de agregado ya registrado no salta. Comprobación de ruta con `realpath` bajo `api/tests/Functional`. El bucle de registro sale a un método que recibe el mapa y se ejercita con mapas plantados (tres rojos: fuera de `Functional/`, inexistente, no nombra al publicador; y un verde). Quitar la referencia a ticket y la reformulación del riesgo del docblock (el `#872` del texto de `RECIPE` se queda).
- [x] `make/php-quality.mk`: corregir «the other» (redacción de dos clases), el texto de ayuda menciona el tripwire y la comprobación en build, y un `--filter` por cada clase nueva.
- [x] `docs/architecture-api.md:317`: describir el invariante de senders, `MessageRoutedOffTransactionalTransport` y que un evento sin enrutar sigue siendo válido.
- [x] Fila de #872 en §7: recuperar, de forma breve o remitiendo explícitamente a la cabecera de `MessengerSendBoundaryGateTest`, los puntos ciegos del gate de envío (tools, scripts, fixtures, cableado en `config/*.yaml`, class-string calculado, alias, import agrupado, lo que hace un fichero fijado con su bus), el límite de deletreo del INSERT, §8 y el motivo de la memoización. Añadir el punto ciego «un mensaje que no es `DomainEvent` enrutado solo fuera de `async` no está vigilado» (afecta a `ScanDocumentMessage`, `PRODUCTION_SECURITY_CHECKLIST.md:2701-2703`). En la columna «Accepted»: `the product owner, 2026-11-11 (kept open, narrowed to the first publisher)`.
- [x] ADR: D2 (línea 29) remite a D3 en vez de repetir las observaciones, y corrige la remisión al párrafo; D3 refleja `sync` admitido.

**Acceptance Criteria:**
- Given un `DomainEvent` enrutado a un alias distinto de `async` y `sync` (solo o junto a ellos), when se despacha en transacción, then se lanza `MessageRoutedOffTransactionalTransport` y el stack siguiente no se invoca.
- Given una línea de `routing:` o un `#[AsMessage]` en el repo que envíe un `DomainEvent` a un sender distinto de `async`/`sync`, when corre `make php.lint.persistent-transport`, then falla en build.
- Given un fichero nuevo de `api/src` que construye `ImageDeletionRequested` (o que la nombra sin estar fijado), when corre `make php.lint.persistent-transport`, then falla nombrando el fichero, la receta de prueba y #872.
- Given el árbol actual, when corren `make php.lint.persistent-transport` y `make php.unit`, then salen con exit 0.

## Design Notes

Receta que piden el gate y la fila de §7 para el primer publicador: un test funcional que llame al **caso de uso real**, deje que haga su escritura de negocio y publique, haga rollback de esa transacción y lea desde una **segunda conexión** que no estén **ni** la fila de negocio **ni** el mensaje en `messenger_messages`. El gate solo comprueba que ese test esté declarado y exista; que pruebe lo que dice queda en manos de la revisión.

## Verification

**Commands:**
- `make php.unit c='--filter RequireTransactionalPublish'` -- expected: verde
- `make php.lint.persistent-transport` -- expected: exit 0. Se falsifica plantando un fichero que nombre `ImageDeletionRequested` (rojo) y restaurándolo copiando bytes
- `make php.stan` y `make php.quality` -- expected: exit 0

## Implementation Notes

- La regla de senders en build vive en `api/tests/Support/PublishSenderPolicy.php`, que compone `PersistentTransportPolicy` para reutilizar su resolución (`sendersFor()`, `attributeTransportsFor()`). Ponerla dentro de esa clase la llevaba a 434 líneas y complejidad 58, por encima de los umbrales de PHPMD (400/50). Además es otra pregunta: aquella decide qué agregado puede encolarse; esta, adónde puede ir un mensaje vigilado.
- Revisión en capas, todas en esta sesión y en paralelo, solo lectura y sobre el worktree: pasada 1 (Blind Hunter, Edge Case Hunter, Verification Gap, Acceptance Auditor) y pasada 2 (las mismas cuatro sobre el árbol de la revisión 1). Triaje en «Review Triage Log».
- Verificación final en limpio: `make php.quality` exit 0, `make php.quality.dry-run` exit 0, `make php.unit` exit 0, `make php.behat` exit 0 (537 escenarios).
- Un `sync` reenviado con `ReceivedStamp` vuelve a pasar por `PersistDomainEventMiddleware`, que no mira el sello y vuelve a hacer el append; lo absorbe `ON CONFLICT (event_id) DO NOTHING` de `DbalEventStore` (`anEventAddressedToSyncInsideATransactionIsStoredOnce` lo fija).

## Spec Change Log

- **Revisión 1 (2026-11-11).** Disparador: hallazgo 1 del triaje (intent_gap: rechazar `sync` contradecía la política de transporte persistente) y hallazgos 3 y 6 (bad_spec: qué es un «publicador» en un código donde el agregado registra y el caso de uso publica; ninguna comprobación en build del invariante). Enmienda: el usuario decidió admitir `sync` (bloque congelado actualizado); se añaden la definición de publicador y la comprobación en build en «Revisión 1». Estado malo que se evita: un 500 en la primera publicación para quien siga el registro de transporte persistente con todos los gates en verde, y un tripwire que salta en el agregado mientras el caso de uso publica sin observación. **KEEP:** la excepción separada `MessageRoutedOffTransactionalTransport`; el orden transacción → senders → validador; `senderAliases()` sobre el locator real con su `catch (Throwable)`; el trait `BuildsPublishGuard::guardRoutedBy()`; la separación `RoutingTest`/`SendersTest`; el cableado con un `--filter` por clase; la fila de §7 recortada y D3 partido en párrafos. La reimplementación parte del árbol actual: no se revierte, se lleva a conformidad con la spec enmendada.

## Review Triage Log

Pasada 1 (2026-11-11). Capas: Blind Hunter (BH), Edge Case Hunter (ECH), Verification Gap (VG), Acceptance Auditor (AA), en paralelo, solo lectura, sobre el worktree.

| # | Hallazgo (capas) | Veredicto | Evidencia | Ruta |
|---|---|---|---|---|
| 1 | El guard rechaza `sync`, que la política de transporte persistente sanciona como vía de escape (BH, AA2) | medium | `api/.persistent-transport-policy:17-18`, `MessengerRoutingConfig::NON_PERSISTED_TRANSPORT`, `PersistentTransportRoutingShapeGateTest:126-136`; `SyncTransport::send()` vuelve a despachar en línea con `ReceivedStamp`, así que no sale de la transacción. La fila de la matriz congelada dice lo contrario | intent_gap |
| 2 | El bucle de registro publicador→test del gate no se ejecuta nunca (`PUBLISHERS` vacío) (VG) | medium | Romper `assertFileExists` o el prefijo `Functional/` deja el gate en verde | patch |
| 3 | «Publicador» ≠ fichero que nombra la clase: el agregado hace `record(new …)` y el caso de uso publica `pullDomainEvents()` sin nombrarla; un segundo caso de uso que reutilice el método no salta; la receta permite archivar un publicador como `NAMING_SITES` (ECH, BH, AA9) | medium | `BankCreator.php:39`, `BankDeleter.php:46-53`; `RECIPE` en `ImageDeletionRequestedPublisherGateTest` | bad_spec |
| 4 | Ruta `Functional/../Unit/X.php` pasa el prefijo (ECH) | low | Comparación de prefijo sobre una ruta sin normalizar | patch |
| 5 | Un fallo del locator (alias de stamp desconocido) se informa como problema de transporte/DSN (ECH, BH) | low | Comportamiento previo del `catch (Throwable)`; es un error de programación que ya revienta en alto | rechazado (low, improbable, el arreglo añade ramas) |
| 6 | Nada comprueba en build que el enrutamiento del repo cumpla el invariante; un `routing:` a `failed` solo falla en runtime (BH) | medium | `PersistentTransportPolicy`/`MessengerRoutingConfig` ya resuelven cada forma de enrutamiento | bad_spec |
| 7 | `docs/architecture-api.md:317` describe el guard viejo (BH, AA3) | medium | Solo lista dos comprobaciones | patch |
| 8 | La fila de §7 perdió residuales (puntos ciegos del gate de envío, deletreo del INSERT, §8, motivo de la memoización) y su resumen es más ancho que el guard (un mensaje no-`DomainEvent` enrutado fuera de `async` pasa: afecta a `ScanDocumentMessage`) (BH, AA5, AA6) | medium | `git show b4883b53:PRODUCTION_SECURITY_CHECKLIST.md`; `PRODUCTION_SECURITY_CHECKLIST.md:2701-2703` | patch |
| 9 | La columna «Accepted» sigue en `not recorded` pese a la decisión de 2026-11-11 (BH) | low | Fila de #872 | patch |
| 10 | ADR D2 (línea 29) duplica D3 y su remisión «paragraph on the fourth residual» apunta mal (BH, AA7) | low | Tras partir D3, el guard está en el párrafo siguiente | patch |
| 11a | Orden de las refusas con `async` no transaccional + otro alias sin test (BH) | false | `aMessageRoutedToAsyncAndAnotherAliasIsRefusedNamingTheOther` usa `InMemoryTransport` sin admitir y espera la excepción de enrutamiento | rechazado |
| 11b | Varios alias fuera de `async` nunca se ejercitan (`implode`) (BH) | low | Solo se asierta un alias | patch |
| 11c | Ningún test funcional ejercita la nueva refusa en el bus del contenedor ni comprueba que `event_store` quede vacío (BH, AA) | medium | `RequireTransactionalPublishFunctionalTest` solo cubre `PublishedOutsideTransaction` | patch |
| 12 | Nombres de test engañosos (`TransportNamesStamp` sustituye, no añade) y un test duplicado (BH) | low | `aStampAddingAnotherAliasBesideAsyncIsRefused`; `anUnguardedMessageRoutedToAnotherAliasPassesInsideATransaction` ≈ `RoutingTest::aMessageWhoseOnlySenderIsAnotherAliasIsNotTreatedAsRouted` | patch |
| 13 | Texto de ayuda de `php.lint.persistent-transport` y redacción «the other» de dos clases (BH, AA8) | low | `make/php-quality.mk:189,196` | patch |
| 14 | La excepción y los docblocks afirman que `async` es «el único que publica dentro de la transacción»; `failed` es Doctrine sobre la misma conexión y `sync` corre en proceso (AA1) | medium | `messenger.yaml:33,37`; el texto llega a log y Sentry | patch |
| 15 | La tarea de la spec nombra `RoutingTest` y los casos viven en `SendersTest` (AA4) | low | Arreglarlo edita la spec de este build | rechazado (regla) |
| 16 | Docblock del gate con referencia a ticket y riesgo reformulado (AA10) | low | Regla de comentarios de CLAUDE.md | patch |
| 17 | Sobre recibido con alias fuera de `async` sin test combinado (AA) | low | `guard()` no se alcanza con `ReceivedStamp` | rechazado (low, improbable) |

Pasada 2 (2026-11-11), mismas cuatro capas sobre el árbol de la revisión 1.

| # | Hallazgo (capas) | Veredicto | Evidencia | Ruta |
|---|---|---|---|---|
| 18 | `CREATION` toma `ImageDeletionRequested::class` (y cualquier `::`) como creación; un handler `handles: …::class` no tendría salida (ECH, VG, AA, BH) | medium | El patrón `\bImageDeletionRequested::`; el autochequeo no tiene un caso negativo con `::class` | patch |
| 19 | El autochequeo de `CREATION` no fija el deletreo `new ImageDeletionRequested(` sin calificar (VG) | medium | Volver obligatorio el grupo de namespace deja verdes las tres cadenas asertadas | patch |
| 20 | La comparación de conjuntos creación↔`PUBLISHERS` solo corre sobre dos conjuntos vacíos (VG) | medium | Invertir los operandos de `array_diff` deja la clase en verde | patch |
| 21 | «Nombra su caso de uso» es una subcadena sin límite de palabra; el propio nombre `<UseCase>…Test` la satisface (ECH, AA, BH) | medium | `registrationProblem()` con `str_contains(basename)` | patch |
| 22 | La ruta del caso de uso no se confina con `realpath` bajo `api/src` (ECH, BH) | low | `is_file()` a secas | patch |
| 23 | `preg_match` que devuelve `false` cuenta como «no coincide» (ECH) | low | Fallo de PCRE en un fichero grande deja pasar el gate | patch |
| 24 | El fallo por un fichero que solo nombra la clase imprime una receta que el gate rechaza (AA) | low | `RECIPE` remite a `PUBLISHERS` y la comprobación de creación lo pone en rojo | patch |
| 25 | La comprobación en build solo ve `DomainEvent`; un `ScanDocumentMessage: [async, failed]` pasa el build y falla en runtime; los docs dicen «la misma regla» (ECH, VG, BH) | medium | `eventsRoutedOutsideTheAdmittedSenders()` recibe `eventsInSource()` | patch |
| 26 | El conjunto admitido está escrito dos veces (middleware y política) y nada lo mantiene igual (BH) | medium | `ADMITTED_SENDERS` con `'sync'` literal y `[PERSISTED, NON_PERSISTED]` | patch |
| 27 | Admitir `sync` por nombre descansa en `misdeclaredNonPersistedTransports()`, que no se cita (AA, BH) | low | Docblock del middleware y ADR D3 | patch |
| 28 | Ningún test en el bus del contenedor recorre el camino `sync` admitido (BH) | medium | Solo hay senders falsos; reenvío con `ReceivedStamp` y doble append sin observar | patch |
| 29 | El test funcional nuevo asierta un sumidero (`async`) que no puede recibir nada; el que importa es `failed` (AA, BH) | low | El stamp lo dirige solo a `failed` | patch |
| 30 | No hay caso `[sync, otro]` (AA) | low | Misma ruta de código que `[async, otro]` | patch |
| 31 | El consejo de `MessageRoutedOffTransactionalTransport` contradice `async`+`sync` y no menciona el stamp (BH) | low | «Route it to async (or sync) alone» | patch |
| 32 | El `catch` de `senderAliases()` atribuye a `async` el fallo de construir cualquier sender y corre antes de la comprobación de transacción (BH) | low | carried: misma raíz que el #5 (rechazado); solo se corrige el docblock, que afirma un orden que esa ruta no cumple | patch (solo docblock) |
| 33 | `TransportNamesStamp` en `api/src` no lo ve ningún gate; un token en `MessengerSendBoundaryGateTest` lo cerraría (BH) | medium | `TRANSPORT_TOKENS` no lo incluye | patch |
| 34 | La columna «Accepted» nombra una aceptación que #872 todavía no registra (BH) | low | Regla del registro: solo donde la issue lo registre | resuelto por la tarea de reescritura de #872, que registrará la decisión |
| 35 | Encabezado de D3 («does not close») frente a «what is already closed is in D3»; «the aggregate creates…» se afirma como si existiera (AA, BH) | low | ADR D3 y fila de §7 | patch |
| 36 | `docs/claude-code-quickref.md:74`, `event-catalog.md:376-381` (paso 3) y `event-catalog.md:50-51` («seven events», ya desfasado) (AA, BH) | low | Catálogo de comandos y del evento | patch |
| 37 | Mención en el CLAUDE.md raíz (AA, BH) | low | Contexto de agente; el catálogo de comandos es el quickref | rechazado (regla: un arreglo en fichero de contexto de agente se difiere; el quickref lo cubre) |
| 38 | `RECIPE` fija `#872` y «D3» (BH) | low | El AC exige `#872` en el mensaje | rechazado (lo exige el AC) |
| 39 | Docblocks hermanos de `TransportTest`/`MiddlewareTest` incompletos (AA) | low | Remiten solo a `MiddlewareTest` | patch (boy scout) |
