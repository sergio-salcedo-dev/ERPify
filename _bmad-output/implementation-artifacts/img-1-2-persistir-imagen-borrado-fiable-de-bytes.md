---
baseline_commit: f86b2662dd4be317d449cd4bebcdc46c77b1814d
---

# Story 1.2: Persistir la imagen y garantizar el borrado fiable de sus bytes

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a bounded context consumidor (la futura épica que cablee `Bank.logoImageId` o `User.avatarImageId`),
I want que la imagen procesada quede almacenada de forma recuperable bajo su `ImageId`, con un mecanismo
fiable para pedir su borrado más adelante,
so that puedo referenciar `ImageId` desde mi propio agregado sin conocer dónde ni cómo se guardan los bytes,
ni cómo se liberan cuando decida que ya no los necesito.

## Frontera de esta historia — leer antes de tocar código

- **Sí entrega**: el puerto `ImageStorage` y su adaptador Flysystem local; el puerto `ImageRepository` y su
  adaptador Doctrine; el mapeo ORM del agregado `Image` y su migración; la extensión de `UploadImage` con los
  pasos de storage y persistencia; el caso de uso de borrado con su orden fijo (storage → fila); la señal de
  observabilidad de fallo de storage; y la nota documentada de `#[PersonalData]`/`#[PersonSubjectReference]`
  para la épica de consumidor.

- **No entrega** (y no debe tocarse en esta PR): ningún controlador HTTP (ni de subida ni de lectura), el
  `Content-Type`/`ETag`/`304`/`Cache-Control` de la ruta de lectura, `IS_AUTHENTICATED_FULLY`, el scan
  formal de NFR6 y sus tests de `#[MapUploadedFile]`, Behat, auditoría, y el consumidor real
  (`Bank.logoImageId` / `User.avatarImageId`). Todo eso es Story 1.3 o la épica de consumidor.

- **Decision firewall de la épica (no reabrir aquí, listado textual de `epics-images.md:442-455`)**:
  `ImageId ≠ digest` · `ImageId ≠ storage key suministrada por el caller` · `Image` no contiene bytes ·
  no contiene `owner` · no contiene `filename` · no contiene `url` · no contiene `variant` · sin
  deduplicación · sin refcount · sin GC · sin content-addressed storage · sin event sourcing sobre `Image` ·
  sin contexto `Documents` · sin ingestión de evidencia · sin consumidor `Bank` · sin consumidor `User` ·
  sin voter de ownership genérico · sin auditoría genérica de lectura · borrado de bytes ≠ transacción
  síncrona del propietario · `ImageStorage` nunca devuelve una URL · ruta de lectura ≠ path de filesystem ·
  sin abstracción `ImagePipelineProducer`/`ImageUploadSource` anticipatoria · sin URL de variante.

- **`UploadImage` se EXTIENDE, no se reabre ni se duplica.** La firma pública
  `upload(string $bytes, ?string $declaredMediaType = null): Image` la fijó la Story 1.1 y sigue igual.
  No crear un segundo caso de uso, no renombrar a `ImageCreator` — `docs/rules/cqrs-naming.md:42-59` documenta
  esta historia por su nombre: *"`UploadImage` predates the persistence it will eventually add in Story 1.2"*.

- **El mecanismo outbox NO es decisión de esta historia — ya está cerrado.** Es el punto GRAVE-1 del pase
  adversarial de la épica (`epics-images.md:80-87`): el inventario dejaba abierto en NFR7 lo que NFR4 ya
  prohibía, y *"una story escrita contra NFR7 literal podría haber reintroducido un borrado síncrono que NFR4
  ya prohibía"*. Lo que esta historia decide es la **semántica de `delete()` como operación** (idempotencia y
  comportamiento ante fallo). El *cuándo* se publica (tras commit, consumido después) lo fija NFR4.

- **Dos cláusulas del ADR D6 que el épico ANULA — no implementar D6 al pie de la letra.** D6 dice
  *"the read is audited like any other"* y *"the story that serves bytes declares the voter it expects"*. El
  épico decide **cero filas de `audit_log`** y `IS_AUTHENTICATED_FULLY` **sin voter**
  (`epics-images.md:47-60`). Un dev que lea el ADR sin esta nota implementaría lo contrario.

- **Resolución de una tensión textual — "fiable" no significa "transaccional".** El ADR pone en negrita que
  el módulo debe un `delete(ImageId)` *fiable* (D5), y a la vez esta rebanada acepta explícitamente que un
  fallo de persistencia tras una escritura de storage deje un objeto huérfano
  (`epics-images.md:361-370`). No es contradicción: *fiable* se predica del **borrado** (idempotente, nunca
  éxito silencioso sobre un fallo real), no de un acoplamiento atómico entre Postgres y el filesystem, que no
  existe y que esta épica prohíbe fabricar. Lo que se promete es un **orden** que deja siempre un estado
  reintentable.

- **Nota de diseño propia, no del listado anterior — a validar/discutir, no a tomar como mandato.** La forma
  de persistencia del agregado `Image` (`final readonly` con VO de identidad vs. el patrón de casa
  `AggregateRoot`+`Identifiable`) **no está decidida por ninguna AC ni por el firewall**, y las dos opciones
  tienen coste real. Está desarrollada en *Dev Notes → La decisión abierta*. **No la cierres tú solo: es una
  decisión de modelado/persistencia, que `CLAUDE.md` reserva al usuario.**

## Adversarial pass

**Estado: dos pases.** El primero, autoadministrado sobre el borrador, es el bloque A-1..A-7 de abajo —
insuficiente por sí solo. El segundo, **externo**, corrió en una sesión distinta y está registrado más abajo
con sus hallazgos y su alcance; corrigió el A-4 de esta lista. El `CLAUDE.md` raíz exige la lectura hostil por
alguien distinto del autor, y el propio ADR lo repite para esta historia en concreto
(`docs/adr/images-vs-documents-conservation-contract.md:129`): *"any story touching erasure or derivative
lifecycle needs the recorded adversarial pass required by the root `CLAUDE.md`; self-certification does not
close it."* Esta historia toca borrado. **La secuencia no fue la que el `CLAUDE.md` prescribe**: el gate exige
que el pase externo se registre *antes* de `gh pr create`, y aquí el PR se abrió primero por instrucción
explícita del usuario. Queda dicho en vez de disimulado.

Hallazgos de la redacción, todos medidos contra el árbol en `f86b2662`:

- **A-1 — El agregado `Image` no es mapeable tal cual, y ninguna AC lo dice.** `Image` es
  `final readonly class` con `ImageId $id` (VO) y `createdAt` estampado en el cuerpo del constructor
  (`api/src/Shared/Images/Domain/Image.php:21-56`). No existe **ni una sola** entidad ORM bajo
  `api/src/Shared/`, y toda entidad del árbol es un `AggregateRoot` mutable con los traits
  `Identifiable`/`Timestamped`. Medido en `vendor/`: ORM 3.6.8 sobre Symfony 8 ya no dispone de
  `ProxyHelper::generateLazyGhost` — el propio código lo anota, *"This method has been removed in Symfony 8"*
  (`api/vendor/doctrine/orm/src/Proxy/ProxyFactory.php:166`) — y cae en objetos perezosos nativos
  (`ProxyFactory.php:217`). Elevado a decisión abierta, no resuelto en el borrador.

- **A-2 — Aunque se le pongan atributos `#[ORM]`, Doctrine no vería la entidad.**
  `api/config/packages/doctrine.yaml:12` declara `auto_mapping: false` y `:13-31` sólo tres mappings:
  `Backoffice`, `Iam`, `Organization`. **No hay entrada `Shared`.** Convertido en tarea explícita; sin ella
  la migración saldría vacía y el fallo sería silencioso.

- **A-3 — `Timestamped` no encaja con el esquema mínimo.** El trait mapea `created_at` **y** `updated_at`,
  ambos `NOT NULL` (`api/src/Shared/Kernel/Domain/Entity/Timestamped.php:13-17`), pero el esquema cerrado de
  `Image` son exactamente siete campos sin `updatedAt` (`epics-images.md:420-424`). Adoptar el patrón de casa
  sin más añadiría una columna que el firewall no contempla. Incorporado a la decisión abierta como coste
  de la opción B.

- **A-4 — La señal de lifecycle necesita una línea de clasificación, y el caso mixto ya tiene respuesta
  escrita.** `api/.persistent-transport-policy` exige clasificar cada `aggregateType()`, y el gate lo pide
  **esté enrutado o no**: *"every `aggregateType()` declared in `src` must be classified, routed or not"*
  (`PersistentTransportPolicyGateTest:28`). Dejar la señal sin enrutar por tanto **no exime de la línea** —
  en cuanto exista el `DomainEvent`, la build se pone roja por completitud. Y el argumento de que `Image` no
  admite clasificación (persona-denotante para un avatar, no-persona para un logo) es del eje de
  **auditoría**, donde no auditar significa que no hay `resource_type`; no se traslada al transporte, donde
  no enrutar sí deja un `aggregateType()` declarado. El registro prevé exactamente este caso: *"Where a type
  is mixed, the conservative verdict is the correct one, and routing any of its events then needs an argued
  ADR exception"* (`api/.persistent-transport-policy:27-31`), con precedente vivo `Iam.Session => person`
  (`:67`). Resuelto en las AC con el veredicto conservador `Image => person :: <ruta del ADR>` y enrutado a
  `async`, que es lo que NFR4 exige (publicar tras commit, consumir después).

- **A-5 — Las excepciones del módulo no entran en el pipeline RFC 9457.** El directorio `Domain/Exception/`
  tiene siete ficheros, pero son **cinco** clases de excepción, una `interface` y un `enum`. Las cinco
  extienden la `\DomainException` nativa de PHP, no
  `Shared\ErrorContract\Domain\Exception\DomainException`, y **ninguna implementa un marcador del contrato
  de error** — sí implementan `ImageProcessingException`, que es el marcador *propio del módulo* para leer
  la `FailureCategory` uniformemente, y que no participa en el mapeo marcador→status. Hoy salen como 500
  `unhandled-exception` y llegan a Sentry. La Story 1.3 necesita distinguir *ausencia confirmada* (404) de
  *fallo transitorio* (5xx) (`epics-images.md:776-780`), y esa distinción no la puede inventar la ruta de
  lectura: la tiene que exponer el puerto de esta historia. Incorporado como AC.

- **A-6 — Los bytes no tienen dónde vivir en ningún entorno.** Ningún `compose*.yaml` declara volumen de
  storage: `caddy_data`, `caddy_config`, `database_data` y las cachés de dev, nada más
  (`compose.yaml:169-172`, `compose.dev.yaml:303-307`). En dev caerían en el bind mount `./api:/app/api`
  (`compose.dev.yaml:76`) → `api/storage` en el host, y **`storage` no está en ningún `.gitignore`**. En prod
  el servicio `php` no declara volúmenes: los bytes mueren en cada redespliegue. Incorporado como tarea.

- **A-7 — La idempotencia del `delete()` rescatable es un TOCTOU, no una propiedad.** El
  `FlysystemStorage::delete()` retirado hace `fileExists()` y luego `delete()`
  (`git show 08f8199^:api/src/Shared/Storage/Infrastructure/FlysystemStorage.php`). La documentación oficial
  de Flysystem **no declara** el contrato de `delete()` sobre fichero ausente — se comprobó. Convertido en
  tarea de medición contra el `vendor/` instalado, no en una copia.

**Dos balas de `deferred-work.md` que esta historia cierra**, y que por tanto deben **borrarse** del registro
(es pending-only) en la misma PR: la de la semántica indefinida de `delete()`
(`deferred-work.md:56`) y la de la asimetría del puerto (`:57`). Las otras dos del mismo bloque **no** son de
esta historia y se quedan: `:58` (mutable/inmutable, diferido a que haya consumidor de variantes) y `:59`
(borde transaccional del consumidor, literalmente *"la primera historia de consumo"*).

### Pase adversarial externo — `bmad-code-review`, 2026-08-28

Lectura hostil por sesión distinta de la que redactó la historia, sobre el árbol en `f86b2662`. Capas
ejecutadas: Edge Case Hunter, Acceptance Auditor y una capa de verificación de premisas de la sesión
conductora. **Dos capas cayeron por límite de sesión y no aportaron**: Blind Hunter (adversarial general)
y el verificador exhaustivo de citas — la deriva de citas de abajo se detectó de refilón, no por barrido
completo, así que **quedan citas sin verificar**.

Toda afirmación de abajo está medida contra el árbol, con el fichero y la línea que la sostiene.

- [x] [Review][Decision] **RESUELTO por Sergio: opción A — enrutar a `async` con `Image => person :: <ruta ADR>`.** **AC 12 instala el borrado síncrono que NFR4, el firewall y el invariante 4 del ADR prohíben — y la premisa que la justifica (A-4) es falsa** — Cadena medida: (a) `api/.persistent-transport-policy:27-31` prevé literalmente el caso mixto que A-4 declara imposible — *"Where a type is mixed, the conservative verdict is the correct one, and routing any of its events then needs an argued ADR exception"* — con precedente vivo `Iam.Session => person` (`:67`); (b) no enrutar **no exime**: `PersistentTransportPolicyGateTest:28` exige línea para *"every `aggregateType()` declared in `src` … **routed or not**"*, luego la build se pone roja en cuanto exista el `DomainEvent`; (c) sin enrutar ⇒ síncrono, porque `RunProjectionsOnDomainEvent` es `#[AsMessageHandler(handles: DomainEvent::class)]` y los casos de uso publican dentro de `transactional(...)` (`BankUpdater.php:41-45`, el call site que esta historia cita) — el borrado de bytes correría dentro de la transacción del propietario y un fallo de Flysystem **rollearía la escritura de negocio**, dejando referencia viva sobre bytes destruidos. Tres documentos lo prohíben por separado: NFR4 (`epics-images.md:242-245`), el firewall (`:449-450`, *"borrado de bytes ≠ transacción síncrona del propietario"*) y el invariante 4 del ADR (`:116`, *"Byte removal is not a synchronous side effect inside the owner's transaction … the port story confirms it"*). Es además el GRAVE-1 del pase adversarial del propio épico (`:83-86`), reintroducido. Y esta historia se contradice: `:46` declara el outbox *"ya cerrado"*, AC 10 reproduce la AC del épico **borrando la palabra `(outbox)`**, y `outbox` no aparece en ninguna AC. **Causa raíz**: la historia traslada al eje del transporte el argumento que el épico escribió para `api/.audit-resource-types` (`:483-500`, *"vale igual para el eje del transporte"*) — medido, no vale: no auditar significa que no hay `resource_type`, mientras que no enrutar **no** significa que no haya `aggregateType()`. Las dos salidas legítimas tienen coste distinto y la elección es del usuario: **(A)** `Image => person :: <ruta ADR>` y enrutar a `async`, lo que obliga a escribir el ADR que argumente por qué se encola igualmente; **(B)** dejarlo sin enrutar y poner el borrado como efecto **post-commit en el caso de uso del consumidor**, nunca en un handler — que es lo que `CLAUDE.md` prescribe, pero renuncia al reintento del worker sobre el que AC 2 apoya su semántica. AC 18(a) además documenta un residual (*"mensaje perdido / fallo del transporte Messenger"*) que sin transporte no puede ocurrir.
- [x] [Review][Patch] La tabla de registros promete un verde que será rojo [`img-1-2-persistir-imagen-borrado-fiable-de-bytes.md`:507] — *"`persistent-transport` | **No, si sigues AC 12** | Sólo clasifica eventos **enrutados**"* es falso contra `PersistentTransportPolicyGateTest:28`. Corregir con independencia de qué salida se elija en la decisión anterior.
- [x] [Review][Patch] AC 4 se verifica por **nombre de clave** y la storage key contiene el `ImageId` como **valor** [`img-1-2…md`:154-157, 338-339] — Task 8 dice *"Claves prohibidas en el contexto, verificadas por test: `imageId`, `digest`, storage key…"*; un contexto `['path' => 'images/01H9…']` pasa ese test y filtra el id íntegro. Es el modo de fallo que `CLAUDE.md` ya registra dos veces (el filtro `query` de Caddy enumerando **nombres** de parámetro). La aserción debe ser sobre valores, no sobre nombres.
- [x] [Review][Patch] Task 5 permite un despliegue sin volumen, lo que **niega AC 1** para el 100 % de las imágenes [`img-1-2…md`:302-303] — *"o, si se decide no hacerlo en esta rebanada, dejarlo escrito como residual"*. Medido: `compose.yaml` declara `caddy_data`, `caddy_config`, `database_data` y nada más; el servicio `php` no declara volúmenes. Con ese residual aceptado, cada redespliegue borra todos los bytes y conserva todas las filas, y NFR3 prohíbe el bookkeeping que lo detectaría. Un residual que niega una AC no es un residual.
- [x] [Review][Patch] `event_store` retiene el `ImageId` real esté enrutado o no, y la historia no lo nombra [`img-1-2…md`:194-197, 507] — `PersistDomainEventMiddleware` persiste **todo** evento despachado antes de que Messenger elija transporte; `api/.persistent-transport-policy:43-45` lo declara como punto ciego: *"Every dispatched event is appended there with its real `aggregate_id` regardless of routing."* La historia enumera un solo sumidero (`messenger_messages`). Para un avatar, el ADR (`:118`) dice que ese id **es** dato personal. Documentar como residual en AC 18.
- [x] [Review][Patch] La aritmética de A-5 es falsa en dos direcciones [`img-1-2…md`:111-113, 445] — *"**Las siete** extienden la `\DomainException` nativa … y **ninguna implementa un marcador**"*. Medido: 7 ficheros pero **5** clases extienden `\DomainException`; `ImageProcessingException` es una `interface` cuyo docblock dice *"Marker every domain/application exception of this module implements"*, y `FailureCategory` es un `enum`. La conclusión (500 `unhandled-exception` → Sentry) es correcta; la evidencia que la sostiene no. El cuerpo del PR repite el error.
- [x] [Review][Patch] AC 2 y AC 3 se contradicen sobre `delete()`, y el puerto nunca enumera sus métodos [`img-1-2…md`:144-152] — AC 3 exige exponer *"ausencia confirmada"* como resultado distinto sin acotar la operación; AC 2 dice que en `delete()` la ausencia es **éxito**. Un dev que implemente la ausencia como excepción de `delete()` cumple AC 3 y rompe AC 2. Enumerar la superficie del puerto (`store`/`read`/`delete`) y decir a qué operación pertenece cada semántica.
- [x] [Review][Patch] AC 4 cierra el vocabulario de `operation` a `store`/`delete`, dejando sin señal la lectura y la integridad [`img-1-2…md`:154-157, 336-337] — AC 1 exige releer, AC 3 es semántica de lectura y Task 4 traduce `UnableToReadFile`; NFR9 nombra `images.read.miss` e `images.storage.integrity_failure` entre sus métricas de referencia. Además `FailureCategory` es un enum cerrado cuyos siete casos son de pipeline: ningún `failure_category` cubre un fallo de storage, y NFR9 sólo admite `format`/`operation`/`failure_category`.
- [x] [Review][Patch] La tercera rama de NFR7 no tiene AC, y Task 4 elimina el mecanismo que la establecía [`img-1-2…md`:144-147, 282-286] — El épico dice *"éxito si el objeto está ausente …, **fallo si la existencia no puede establecerse** o el borrado no puede completarse"* (`:278-280`); AC 2 no recoge esa rama, y Task 4 manda retirar el `fileExists()` previo, que era justo lo que la establecía.
- [x] [Review][Patch] El orden de AC 7 escribe en storage **antes** de validar el agregado [`img-1-2…md`:167-170, 311-312] — `Image::__construct` (`Image.php:35-53`) valida digest, dimensiones, `byteSize > 0` y mediaType, y lanza. Con el orden literal `process() → store() → persistir`, un `CanonicalImage` degenerado produce un huérfano por una ruta de **validación**, no de infraestructura, mientras AC 7 prohíbe compensar. Construir `Image` antes de `store()`; sólo *persistir* va después.
- [x] [Review][Patch] La lista de claves prohibidas se aplica sólo al texto de `delete()` [`img-1-2…md`:343-345] — Flysystem pone la ruta en el mensaje (`UnableToWriteFile::atLocation($path)`), y el adaptador retirado que la historia bendice como plantilla hace lo mismo: `sprintf('Cannot read object storage key "%s".', $key)`. Ese texto llega a `messenger_messages` vía `ErrorDetailsStamp` y a Sentry — el sumidero que la propia Task 8 cita. Extender la regla a `store()`, `read()` y al `$previous` conservado.
- [x] [Review][Patch] Falta la clase de fallo **permanente**, y el borde del repositorio no traduce [`img-1-2…md`:149-152, 211-213, 261-270] — `ENOSPC`, `EACCES`, montaje ausente o path mal configurado no son ausencia ni fallo transitorio; con vocabulario binario se mapean a 503 y el consumidor reintenta indefinidamente sobre algo que ningún reintento arregla. Y `DoctrineTransactionManager` sólo traduce `RetryableException` y `ForeignKeyConstraintViolationException`: una `UniqueConstraintViolationException` cruza cruda con el valor de la PK (el `ImageId`) en el mensaje del driver. AC 16 sólo cubre Flysystem.
- [x] [Review][Patch] La matriz AC → test da por cubiertas ocho AC cuyo test nombrado no puede observar su fallo [`img-1-2…md`:564-585] — AC 1 (un filesystem temporal sano nunca produce la escritura parcial que la AC dice detectar, y Task 4 deja sin decidir si la verificación es de `store()` o de un test), AC 2 mitad de fallo (doble, no adaptador real), AC 3, AC 6 (*"revisión de la migración"* y `doctrine:mapping:info` son lectura humana; el fallo de A-2 es silencioso por diseño), AC 10, AC 12, AC 13 (`publicUrl()` y `read()` comparten tipo de retorno), AC 15. Las tres que sí observan su fallo sin reserva: AC 7, AC 11 y AC 14.
- [x] [Review][Patch] Deriva de citas de línea en un artefacto cuyo método declarado es «medido, no supuesto» [`img-1-2…md`:245, 612, 79, 152, 205] — `api/config/services.yaml:19-21` (citado dos veces) son parámetros de dimensiones; la exclusión `'../src/**/Domain/Entity/'` está en `:43`. ADR `:127` → real `:129`. Épica `:779-784` → real `:776-780`. Épica `:602-608` y `:547-552` también desplazadas. Correctas y verificadas: `deferred-work.md:56/57/58/59`, `epics-images.md:442-455`, `:420-424`, `:361-370`, `phpunit.dist.xml:50`, `doctrine.yaml:12`, `Timestamped.php:13-17`, la firma pública de `UploadImage` y el inventario de lo que no existe.
- [x] [Review][Patch] Barrer los IDs de historia/requisito que la Story 1.1 dejó mergeados en los ficheros que esta historia edita [`api/src/Shared/Images/`] — Medido: **23** apariciones en `api/src/Shared/Images/` y **12** en sus tests; entre ellas `UploadImage.php:20` (*"Story 1.2 extends this same class…"*), que es exactamente el fichero de Task 6, y `InterventionImageProcessor.php:40`. `CLAUDE.md` → *Code comments* los prohíbe y manda la regla del boy-scout al editar. Ninguna task las barre.
- [x] [Review][Patch] El comentario del tracker queda falso y el diff no lo actualiza [`sprint-status-images.yaml`:73] — *"Stories 1.2 y 1.3 siguen en backlog (solo cortadas dentro de epics-images.md, **sin fichero propio todavía**)"*.
- [x] [Review][Defer] Behat queda sin dueño en toda la épica — La épica promete *"unit + integration + Behat contra el propio seam"* (`:486-487`); 1.1 cerró sin ninguna feature del módulo, 1.2 lo excluye explícitamente y ninguna AC de 1.3 lo nombra. Es deuda de la épica, no de esta historia. Por la regla *"An epic does not grow its own deferred pile"*, refundir en la Story 1.3 en vez de anotarlo en `deferred-work.md`.

## Acceptance Criteria

1. **Integridad de ida y vuelta, no mera recuperabilidad (NFR7).** Dados bytes canónicos producidos por
   `ImageProcessor`, cuando `ImageStorage::store(ImageId, bytes)` retorna éxito, leer por el mismo `ImageId`
   devuelve **exactamente** esos bytes, y su `SHA-256` coincide con `Image.digest`. La promesa es más fuerte
   que "algo es recuperable": detecta una escritura parcial o corrupta que el filesystem da por terminada
   correctamente.

2. **`delete()` idempotente hacia la ausencia, nunca hacia el fallo (NFR7).** Sobre un `ImageId` cuyo objeto
   ya fue borrado o nunca existió, `ImageStorage::delete(ImageId)` retorna éxito. Ante un fallo real de
   infraestructura retorna fallo — **nunca** se convierte en éxito silencioso, porque el consumidor outbox
   necesita saber si puede reintentar. **Tercera rama, que el épico enuncia y no puede perderse**: si la
   existencia del objeto **no puede establecerse** (permiso denegado, montaje ausente, I/O que no responde),
   la operación retorna fallo, no el éxito idempotente de la ausencia — *"fallo si la existencia no puede
   establecerse o el borrado no puede completarse"* (`epics-images.md:278-280`). Un `delete()` que no
   distingue "no estaba" de "no pude mirar" convierte una configuración rota en una erasure confirmada.

3. **La ausencia confirmada y el fallo transitorio son distinguibles en el puerto (A-5, y requisito de la
   Story 1.3).** El puerto declara su superficie completa — `store`, `read`, `delete` — y **dice a qué
   operación pertenece cada semántica**, porque no es la misma en todas: en `delete()` la ausencia es
   **éxito** (AC 2), mientras que en `read()` la ausencia confirmada es un resultado distinguible del fallo
   transitorio. Sin esa separación explícita, una implementación que modele la ausencia como excepción de
   `delete()` cumple esta AC y rompe la anterior. Hay además una **tercera clase, permanente**: `ENOSPC`,
   `EACCES`, montaje ausente o path mal configurado no son ni ausencia ni fallo transitorio, y colapsarlos
   en transitorio entrena al consumidor a reintentar indefinidamente algo que ningún reintento arregla. La
   Story 1.3 mapea ausencia→404 y transitorio→5xx (`epics-images.md:776-780`) y **no puede inventar la
   distinción**: la provee esta historia.

4. **Señal de observabilidad privacy-safe para storage (NFR9).** Ante un fallo de `store()`, `read()` o
   `delete()` se emite una señal cuyo `operation` cubre las **tres** operaciones, más el fallo de integridad
   de AC 1 — NFR9 nombra `images.read.miss` e `images.storage.integrity_failure` entre sus métricas de
   referencia, así que un vocabulario limitado a `store`/`delete` deja dos fallos sin señal. `FailureCategory`
   es un enum cerrado cuyos siete casos son todos de pipeline: necesita casos de storage, o un vocabulario
   propio, porque NFR9 sólo admite `format`, `operation` y `failure_category` como dimensiones.
   La señal **nunca** incluye `ImageId`, `digest`, la storage key derivada, bytes ni filename, y **el test lo
   afirma sobre los VALORES del contexto, no sobre los nombres de sus claves**: un contexto
   `['path' => 'images/01H9…']` pasa cualquier chequeo por nombre y filtra el id íntegro. Es el modo de fallo
   que `CLAUDE.md` ya registra dos veces (el filtro `query` de Caddy, que enumeraba **nombres** de
   parámetro). La distinción entre "objeto ya ausente" (éxito idempotente) y "fallo real" es observable sin
   que la señal sea ella misma el vector de fuga que NFR9 prohíbe.

5. **La storage key es función exclusiva de `ImageId`.** Cualquier implementación del adaptador deriva la
   key sólo del `ImageId` — nunca de filename, MIME, dimensiones ni ningún valor suministrado por el caller.

6. **El esquema persistido es exactamente el mínimo.** La tabla contiene `ImageId`, `digest`, `mediaType`,
   `width`, `height`, `byteSize`, `createdAt` y nada más. No lleva `ownerId`, `filename`, `storagePath`,
   `url`, `variant`, los bytes canónicos ni un campo de versión de canonicalización (MEDIA-8 lo resolvió
   **sin** nuevo campo: la canonicalización es v1 implícito).

7. **El orden de creación, y el huérfano como deuda aceptada (NFR3).** El orden es generar `ImageId` →
   procesar → **construir `Image`** → escribir en storage → persistir `Image`. El agregado se construye
   **antes** de tocar storage: `Image::__construct` (`Image.php:35-53`) valida digest, dimensiones,
   `byteSize > 0` y mediaType, y lanza — con el orden inverso, un `CanonicalImage` degenerado produciría un
   huérfano por una ruta de **validación**, no de infraestructura, y esta AC prohíbe compensarlo. Lo que va
   después de la escritura es *persistir*, no *construir*. Si la persistencia falla tras una escritura exitosa,
   el objeto de storage queda huérfano y **ningún mecanismo de esta historia intenta compensar, revertir ni
   recolectarlo**. Una escritura exitosa no se trata como acoplada transaccionalmente a la persistencia.

8. **Nadie conoce un `ImageId` antes de que exista su fila.** `UploadImage` devuelve el `ImageId` al caller
   **sólo después** del commit de la persistencia de `Image` — cierra por construcción cualquier carrera
   entre una lectura y una subida en vuelo.

9. **Orden de borrado: primero los bytes, después la fila — nunca al revés.** Al procesar la señal de
   lifecycle para borrar un `ImageId`, se ejecuta `ImageStorage::delete(ImageId)` y **después** se borra la
   fila `Image`. Si el segundo paso falla tras el primero, la fila sigue existiendo y el borrado completo
   sigue siendo **reintentable**. El orden inverso queda explícitamente descartado: dejaría un objeto de
   storage sin ninguna fila que lo referencie, es decir el bookkeeping que NFR3 prohíbe construir. No se
   promete atomicidad entre ambos pasos — no hay transacción que cruce Postgres y el filesystem —; se promete
   un orden que garantiza que cualquier fallo a medio camino deja un estado reintentable.

10. **Ningún listener de ciclo de vida de Doctrine borra bytes.** El módulo no contiene ningún
    `#[AsEntityListener]`/`postRemove` que borre storage como efecto lateral de un flush o un remove del
    propietario. Es el contraejemplo exacto del invariante 4 que el ADR nombra
    (`BankStoredObjectRemoveListener`, retirado). El seam queda preparado para que un futuro propietario
    publique tras commit y el borrado físico se consuma después **(outbox)**, sin que el módulo decida
    cuándo un consumidor deja de poseer una imagen.

11. **El payload de la señal de lifecycle transporta sólo `ImageId`** — nunca `storageKey`, `path`,
    `filename`, `digest` ni `absolutePath`.

12. **La señal de lifecycle se publica tras commit y se consume después — outbox, nunca síncrono (NFR4).**
    Se enruta a `async` en `api/config/packages/messenger.yaml`, y su `aggregateType()` se clasifica en
    `api/.persistent-transport-policy` con el **veredicto conservador** y su excepción argumentada:
    `Image => person :: <ruta del ADR>`. Las tres razones, medidas:
    (a) el gate exige la línea **esté enrutada o no** (*"routed or not"*,
    `PersistentTransportPolicyGateTest:28`), así que no enrutar no compra ninguna exención;
    (b) un `DomainEvent` sin enrutar **se maneja en proceso**, y como los casos de uso publican dentro de
    `TransactionManager::transactional(...)`, su handler correría **dentro de la transacción del
    propietario** — exactamente lo que NFR4 (`epics-images.md:242-245`), el decision firewall (`:449-450`) y
    el invariante 4 del ADR (`:116`) prohíben, con el agravante de que un fallo de Flysystem rollearía la
    escritura de negocio del consumidor y dejaría una referencia viva sobre bytes ya destruidos;
    (c) el registro prevé el caso mixto y le da respuesta: *"Where a type is mixed, the conservative verdict
    is the correct one, and routing any of its events then needs an argued ADR exception"*
    (`api/.persistent-transport-policy:27-31`), con precedente vivo `Iam.Session => person` (`:67`).
    El ADR que la línea nombra es entregable de esta historia (Task 7) y argumenta por qué se encola un id
    potencialmente persona-denotante: su coste es que el `ImageId` vive en `messenger_messages` sin TTL, y
    la contrapartida es que el borrado deja de ser síncrono y gana el reintento del worker sobre el que
    AC 2 apoya su semántica.

13. **`ImageStorage` no devuelve URLs.** Ningún método del puerto retorna una URL — la entrega es
    responsabilidad de la Story 1.3, no de storage.

14. **Dos subidas idénticas producen dos objetos de storage independientes (NFR2).** Mismos bytes de entrada
    → `ImageId` distintos → mismo digest → **dos objetos de storage independientes**. Es la mitad del test de
    regresión obligatorio de NFR2 que la Story 1.1 dejó explícitamente pendiente
    (`epics-images.md:547-552`). Ningún índice único sobre el digest.

15. **Sólo los bytes canónicos alcanzan storage.** Los bytes originales subidos nunca se persisten. Es la
    mitad de storage de la garantía anti-polyglot que la Story 1.1 sólo pudo probar localmente
    (`epics-images.md:602-608`).

16. **Las excepciones de Flysystem no cruzan a `Application/` sin traducir** — mismo principio hexagonal que
    la traducción de las excepciones de Intervention en la Story 1.1. Nada de `catch (\Throwable)`: se captura
    la jerarquía concreta de la librería.

17. **La nota de `#[PersonalData]`/`#[PersonSubjectReference]` queda documentada en el contrato del puerto.**
    Un futuro campo consumidor que referencie la imagen de una persona física deberá declararse en
    `api/.person-reference-policy`; `Image` **nunca** determina por sí mismo si un `ImageId` es dato personal.

18. **Los dos residuales quedan documentados, no resueltos.** (a) Un mensaje de lifecycle perdido deja bytes
    y fila vivos indefinidamente, sin monitorización en esta rebanada (RESIDUAL-2). (b) Sin refcounting
    (NFR3 lo prohíbe), un futuro consumidor que duplique una referencia y viole NFR4 no tiene safety net: un
    `delete()` legítimo borra bytes que otro poseedor cree tener (RESIDUAL-3). (c) **`event_store` retiene
    el `ImageId` real para siempre y ningún routing lo cambia**: `PersistDomainEventMiddleware` appendea todo
    evento despachado antes de que Messenger elija transporte, y el registro lo declara como su propio punto
    ciego — *"Every dispatched event is appended there with its real `aggregate_id` regardless of routing"*
    (`api/.persistent-transport-policy:43-45`). La erasure del sujeto reescribe por **valor del identificador
    del sujeto**, y el `ImageId` no es ese valor, así que la fila sobrevive; para un avatar el ADR (`:118`)
    dice que ese id **es** dato personal (RESIDUAL-4). No lo cierra esta rebanada — se documenta para que la
    épica de consumidor no lo redescubra, y porque es el eje que el enrutado no toca en ninguna dirección.
    Se documentan para que la épica de consumidor no los redescubra por accidente.

## Tasks / Subtasks

- [ ] **Task 0 — Cerrar la decisión de forma de persistencia ANTES de escribir código** (AC 6)
  - [ ] Leer *Dev Notes → La decisión abierta*. Es una decisión de modelado/persistencia: `CLAUDE.md`
        (*Per-aggregate persistence strategy*) la reserva al usuario. **Preséntala con sus costes y espera
        respuesta.** No la cierres unilateralmente ni la infieras del resto de la historia.
  - [ ] Registrar la opción elegida y su argumento en *Dev Agent Record → Completion Notes*.

- [ ] **Task 1 — Habilitar el mapeo Doctrine del shared kernel** (AC 6) — `api/config/packages/doctrine.yaml`
  - [ ] Añadir el bloque `mappings:` que falta. Hoy `auto_mapping: false` y sólo hay `Backoffice`, `Iam`,
        `Organization` (`doctrine.yaml:12-31`); sin esto los atributos `#[ORM]` sobre `Image` son inertes y
        `make db.diff` genera una migración vacía **sin error**.
  - [ ] Decidir y argumentar el `prefix`/`dir`: `Erpify\Shared` completo abre a mapeo todo el shared kernel;
        acotarlo a `Erpify\Shared\Images` es más estrecho pero introduce un mapping por módulo. Mide el
        efecto sobre `make db.diff` (no debe aparecer ninguna tabla inesperada).
  - [ ] Verificar con `make sf c='doctrine:mapping:info'` que `Image` aparece listada. Un mapeo ausente es
        silencioso; esta comprobación es la única que lo delata antes de la migración.

- [ ] **Task 2 — Mapear el agregado `Image`** (AC 6) — `api/src/Shared/Images/Domain/…`
  - [ ] Aplicar la forma elegida en Task 0. Si la entidad se mueve a `Domain/Entity/`, recuerda que
        `api/config/services.yaml:43` excluye `'../src/**/Domain/Entity/'` del contenedor — es la
        convención, y hoy `Image` está fuera de esa ruta.
  - [ ] Id `Types::GUID` app-asignado: `#[ORM\Id]` + `#[ORM\Column(type: Types::GUID)]` **sin**
        `#[ORM\GeneratedValue]` — `docs/rules/database.md:81-92` lo pin­ea, y re-añadir un generador
        reintroduce el desajuste de id que ya mordió antes (`IdentifiableAssignedIdentifierTest`).
  - [ ] `createdAt`: el repo tiene tipos DBAL propios `datetimetz`/`datetimetz_immutable` →
        `DateTimeTzMicrosType` (`doctrine.yaml:6-8`). Decide cuál usar y sé coherente con `Timestamped`, que
        usa `Types::DATETIME_IMMUTABLE`. **No añadas `updated_at`**: el esquema son siete campos (AC 6).
  - [ ] Camino de hidratación que **no re-estampe `createdAt`**. Hoy el constructor lo fija incondicionalmente
        con `SystemClock::now()` (`Image.php:56`); la review de la Story 1.1 difirió esto aquí de forma
        explícita (*"Story 1.2 (persistencia) necesitará un camino de hidratación desde fila de BD que no
        re-estampe `createdAt`"*).
  - [ ] Actualizar `api/tests/Unit/Shared/Images/Domain/ImageTest.php:89-105`, que fija por reflexión
        `isFinal()`, `isReadOnly()` y la lista exacta de parámetros del constructor. **Rompe por diseño**: si
        cambia el constructor, ese test se actualiza con argumento, no se relaja.

- [ ] **Task 3 — Puerto `ImageRepository` + adaptador Doctrine** (AC 6, 9) —
      `api/src/Shared/Images/Domain/…` + `Infrastructure/Persistence/Doctrine/…`
  - [ ] Interfaz en `Domain/`, mínima: guardar, buscar por `ImageId`, borrar. Plantilla exacta de forma:
        `api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` (`save`/`remove`/`findById`, docblock
        de una línea diciendo qué puerto es).
  - [ ] Adaptador en `Infrastructure/`, `final readonly`, `#[AsAlias(ImageRepository::class)]`, por
        **composición** con `EntityManagerInterface` inyectado — nunca herencia de una base ORM. Plantilla:
        `DoctrineBankRepository` (`:44-50`). Ojo: `InterventionImageProcessor` **no** lleva `#[AsAlias]`
        porque es implementación única; para un puerto nuevo copia el patrón explícito de
        `DoctrineTransactionManager` / `DoctrineBankRepository`.
  - [ ] La firma no acepta ni devuelve paths, URLs ni storage keys (NFR6, eje **valor**).
  - [ ] **Traducir también el borde del repositorio.** `DoctrineTransactionManager` sólo traduce
        `RetryableException` y `ForeignKeyConstraintViolationException`; una `UniqueConstraintViolationException`
        sobre la PK cruza cruda a `Application/` con **el valor de la PK (el `ImageId`) en el mensaje del
        driver**, y de ahí al mismo sumidero que Task 8 nombra. AC 16 sólo cubre Flysystem: cubre este borde
        con el mismo criterio.

- [ ] **Task 4 — Puerto `ImageStorage` + adaptador Flysystem** (AC 1, 2, 3, 5, 13, 16) —
      `api/src/Shared/Images/Domain/ImageStorage.php` + `Infrastructure/FlysystemImageStorage.php`
  - [ ] `composer require league/flysystem-bundle`. **Hoy no hay ninguna librería de filesystem instalada**
        — cero paquetes `league/*` (el único hit en `api/composer.lock` es el mapa `conflict` de
        `roave/security-advisories`). Config previa recuperable:
        `git show 08f8199^:api/config/packages/flysystem.yaml` (storage nombrado `erpify.storage`, env
        `STORAGE_LOCAL_PATH`) — **renombra el namespace a `images`**, no revivas el nombre viejo.
  - [ ] Naming por capacidad, no `*Interface`: puerto `ImageStorage`, adaptador `FlysystemImageStorage`
        (misma regla que produjo `ImageProcessor`/`InterventionImageProcessor`).
  - [ ] **Medir, no copiar, la idempotencia del `delete()`.** El adaptador retirado hacía
        `fileExists()` y luego `delete()` — un TOCTOU y un I/O de más. Lee
        `api/vendor/league/flysystem-local/…` tras el `composer require` y comprueba si `delete()` ya es
        idempotente ante fichero ausente; la documentación oficial **no** lo declara. Escribe la guarda
        contra lo que haga el código instalado, no contra lo que dice la doc. **Retirar el `fileExists()` no
        puede llevarse por delante la tercera rama de NFR7** (AC 2): si la existencia no puede establecerse
        —permiso denegado, montaje ausente— eso es *fallo*, no el éxito idempotente de la ausencia. Comprueba
        qué hace el adaptador instalado en ese caso concreto, no sólo con el fichero ausente.
  - [ ] Traducir la jerarquía de excepciones de Flysystem (`UnableToWriteFile`, `UnableToDeleteFile`,
        `UnableToReadFile`, `FilesystemException`) a excepciones de dominio distinguibles que separen
        **ausencia confirmada**, **fallo transitorio** y **fallo permanente** (AC 3). Nada de
        `catch (\Throwable)`. Ojo: `FilesystemException` es una **interfaz**, y la lista de cuatro tipos es
        cerrada — lo que caiga fuera (`UnableToCheckExistence`, errores nativos del adaptador local) cruzaría
        sin traducir. Captura por la interfaz y mapea los concretos, en ese orden.
  - [ ] Nuevo vendor → nuevo collector en `api/tools/deptrac/deptrac.yaml`: `Vendor.Flysystem`, permitido
        **sólo** en `Shared.Infrastructure`, a la misma granularidad agregada que `Vendor.Intervention`
        (`deptrac.yaml:207-211`, `:243-255`). La review de la Story 1.1 descartó explícitamente un hallazgo
        que pedía granularidad más fina: no la introduzcas.
  - [ ] Verificación de integridad de AC 1: tras `store()`, releer y comparar `SHA-256` contra
        `Image.digest`. Decide y argumenta si la verificación es del propio `store()` o de un test — si es
        del `store()`, es un I/O de lectura por subida y eso hay que decirlo, no esconderlo.

- [ ] **Task 5 — Superficie de despliegue de los bytes** (AC 5, y hallazgo A-6) — `compose*.yaml`, `.gitignore`
  - [ ] **Ningún compose declara volumen de storage hoy.** Sin esto, en dev los bytes caen en el bind mount
        `./api:/app/api` (host, sin `.gitignore`, contaminando `git status`) y en prod el servicio `php` no
        declara volúmenes en absoluto: **se pierden en cada redespliegue**.
  - [ ] **Declarar el volumen. No es opcional y no admite residual**: sin él, cada redespliegue borra todos
        los bytes y conserva todas las filas, de modo que *"una escritura aceptada es subsecuentemente
        recuperable"* (NFR7 / AC 1) sería falsa para el 100 % de las imágenes, nada lo detectaría, y NFR3
        prohíbe el bookkeeping que lo encontraría. Un residual que niega una AC de la propia historia no es
        un residual: es la AC incumplida. Anota además el volumen en `PRODUCTION_SECURITY_CHECKLIST.md`.
  - [ ] Añadir el path de storage a `.gitignore` pase lo que pase. Memoria del repo: `git add -A` ya barrió
        trabajo ajeno una vez; un directorio de bytes no ignorado es la siguiente ocasión.

- [ ] **Task 6 — Extender `UploadImage`** (AC 7, 8, 15) — `api/src/Shared/Images/Application/UploadImage.php`
  - [ ] Añadir los pasos de storage y persistencia **sin cambiar la firma pública**
        `upload(string $bytes, ?string $declaredMediaType = null): Image`. El test
        `UploadImageTest.php:55-65` la fija por reflexión.
  - [ ] Orden exacto: `ImageId::generate()` → `process()` → `ImageStorage::store()` → persistir `Image` →
        devolver. El retorno ocurre **después** del commit (AC 8).
  - [ ] `Application/` no importa Doctrine ni Messenger: usa
        `Erpify\Shared\Persistence\Application\TransactionManager::transactional(callable): mixed` y
        `Erpify\Shared\Event\Domain\EventBus::publish(DomainEvent ...)`. Gate: `make php.lint.event-bus`,
        más `make php.deptrac`. Call site de referencia: `BankUpdater.php:41-45`.
  - [ ] **No** envolver la escritura de storage en la transacción: AC 7 exige explícitamente que no estén
        acopladas, y una transacción no puede revertir el filesystem.

- [ ] **Task 7 — Borrado: caso de uso, orden y señal** (AC 9, 10, 11, 12, 18) — `Application/` + `Domain/Event/`
  - [ ] Caso de uso de borrado que ejecuta `ImageStorage::delete()` y **después** el borrado de la fila. El
        orden inverso está descartado con argumento en AC 9 — anótalo en el código, es exactamente el tipo de
        *why* que un futuro lector invertiría "simplificando".
  - [ ] Señal de lifecycle con payload de **sólo** `ImageId`. Si es un `DomainEvent`, plantilla:
        `BankCreatedDomainEvent` (`eventName()`, `aggregateType()`, `toPrimitives()`, `fromPrimitives()`).
  - [ ] **Enrútala a `async`** en `api/config/packages/messenger.yaml` (AC 12) — publicar tras commit y
        consumir después es lo que NFR4 fija, y dejarla sin enrutar la haría síncrona dentro de la
        transacción del propietario.
  - [ ] Añadir su línea a `api/.persistent-transport-policy` con el veredicto conservador y su excepción:
        `Image => person :: <ruta del ADR>`. La línea hace falta **enrutes o no** (*"routed or not"*,
        `PersistentTransportPolicyGateTest:28`), así que no enrutar no habría ahorrado la decisión.
  - [ ] **Escribir el ADR que la línea nombra**, argumentando por qué se encola un id potencialmente
        persona-denotante: qué se gana (el borrado sale de la transacción y gana reintento del worker), qué
        se paga (el `ImageId` vive en `messenger_messages`, sin TTL y sin camino de erasure) y por qué el
        veredicto conservador es `person` pese a que un logo de banco no lo sea. Sin ADR existente la línea
        no vale y `make php.lint.persistent-transport` la rechaza.
  - [ ] Confirma `make php.lint.persistent-transport` en verde **con el exit code impreso**, después de
        añadir la línea y el ADR — no antes.
  - [ ] Cero `#[AsEntityListener]` en el módulo (AC 10).
  - [ ] Documentar los dos residuales (AC 18) donde los vaya a leer la épica de consumidor.

- [ ] **Task 8 — Observabilidad de storage** (AC 4) — `Infrastructure/`
  - [ ] Reutilizar el canal Monolog `observability` vía
        `#[Autowire(service: 'monolog.logger.observability')]`, igual que `InterventionImageProcessor`. **No
        crear** `MetricsRecorder`/StatsD ni infraestructura de métricas nueva.
  - [ ] Extender los vocabularios cerrados existentes (`FailureCategory`, `operation`) en vez de inventar
        unos paralelos. `operation` gana `store`, `read` y `delete`, más el fallo de integridad de AC 1 —
        NFR9 nombra `images.read.miss` e `images.storage.integrity_failure`, así que un vocabulario limitado
        a `store`/`delete` deja dos fallos sin señal. `FailureCategory` es cerrado y sus siete casos son de
        pipeline: decide y argumenta si gana casos de storage o si el storage lleva el suyo propio, porque
        NFR9 sólo admite `format`, `operation` y `failure_category` como dimensiones.
  - [ ] Claves prohibidas en el contexto, verificadas por test **sobre los VALORES, nunca sobre los nombres
        de las claves**: `imageId`, `digest`, storage key, bytes, filename (NFR9). Un contexto
        `['path' => 'images/01H9…']` pasa cualquier chequeo por nombre y filtra el id íntegro — es
        literalmente el modo de fallo que `CLAUDE.md` registra dos veces con el filtro `query` de Caddy, que
        enumeraba nombres de parámetro. Serializa el contexto y busca el `ImageId` y el digest como
        subcadena.
  - [ ] **Registrar en `api/tests/Unit/Gate/BestEffortReportChannelGateTest.php:89-98` (`REPORTERS`) TODA
        clase nueva que loguee**, no sólo el adaptador: el gate deriva su universo de las clases con
        `LoggerInterface` que invocan `$this->logger->`, así que si también loguean el caso de uso de borrado
        o el repositorio, cada una necesita su línea o `make php.unit` se pone en rojo — le pasó a la
        Story 1.1 con `InterventionImageProcessor`.
  - [ ] Ojo con el sumidero: `docs/rules/security.md:73` recuerda que el mensaje de una excepción se persiste
        en `messenger_messages` vía `ErrorDetailsStamp`, tabla que ningún camino de erasure alcanza. El
        **texto de toda excepción del módulo** obedece la misma lista prohibida que el log — no sólo el de
        `delete()`. Flysystem pone la ruta en el mensaje (`UnableToWriteFile::atLocation($path)`) y el
        adaptador retirado que esta historia usa de plantilla hace lo mismo
        (`sprintf('Cannot read object storage key "%s".', $key)`), así que `store()`, `read()` y el
        `$previous` conservado filtran la key —es decir, el `ImageId`— si nadie lo impide.

- [ ] **Task 9 — Migración** (AC 6) — `api/migrations/2026/`
  - [ ] `make db.diff` y **leer** el SQL generado antes de `make db.migrate`. Si sale vacía, Task 1 no está
        hecha.
  - [ ] `getDescription()` de una línea + docblock de clase explicando el *porqué*, incluido qué restaura y
        qué no el `down()`. Plantilla: `api/migrations/2026/Version20260819091752.php`.
  - [ ] `down()` reversible. **Sin** índice único sobre el digest (NFR2/NFR3) y sin `updated_at`.
  - [ ] No revivir la tabla `media` ni `media_content_hash_uniq`: la destruyó a propósito
        `Version20260723104340.php` y `deferred-work.md:81` lo prohíbe explícitamente.

- [ ] **Task 10 — Registros y contrato de error** (AC 3, 17)
  - [ ] `api/.person-reference-policy`: si el mapeo introduce alguna columna `Types::GUID` en una entidad,
        **necesita línea** o `make php.lint.person-reference` rompe la build. Formato:
        `<Fqcn>::$<prop> => non-person` | `=> person :: <ruta del fichero que la borra>`. La PK propia del
        sujeto es la única exenta, y `Image` no es un sujeto: su id es `non-person` (el `ImageId` se vuelve
        dato personal **en el consumidor**, que es quien lo declara — AC 17).
  - [ ] Contrato de error: decide y argumenta si las excepciones del módulo entran en el pipeline RFC 9457
        ahora (marcadores de `Shared/ErrorContract`, p. ej. `ServiceUnavailable` → 503 para el fallo
        transitorio) o si eso pertenece a la Story 1.3 con la ruta HTTP. **Hoy no entran** (hallazgo A-5) y
        salen como 500 a Sentry. Si tocas el contrato, `docs/api-error-contract.md` es obligatorio (NFR26) y
        el gate es `make php.lint.error-contract`.

- [ ] **Task 11 — Tests** (todas las AC) — `api/tests/Unit/Shared/Images/…`, `api/tests/Functional/…`
  - [ ] Ver *Dev Notes → Matriz AC → test*. Dobles: `InMemoryImageStorage` (implementación alternativa
        usable) vs. `StubImageStorage` (valor fijo) — la convención de nombres está en
        `docs/rules/testing.md`. Viven junto a los tests que los usan.
  - [ ] El test de integridad (AC 1) y el de dos objetos independientes (AC 14) necesitan un storage **real**
        (filesystem temporal), no un doble: un doble en memoria no puede fallar en escritura parcial, que es
        justo lo que AC 1 afirma detectar.
  - [ ] Test funcional contra **Postgres real**, nunca SQLite; extiende `KernelTestCase`. Referencia:
        `api/tests/Functional/Shared/Persistence/`.
  - [ ] **Afirma la siembra antes que la ausencia** (`docs/rules/testing.md`): comprueba primero que se emitió
        la línea de log, después que no contiene las claves prohibidas — si el logger nunca se invocó, "no
        contiene `digest`" es verdad vacía. Mismo patrón para "el objeto de storage ya no existe": afirma
        primero que existía.
  - [ ] Nada de tests por reflexión para probar una ausencia; prueba el modelo observable.

- [ ] **Task 12 — Registro de pendientes y docs** — `deferred-work.md`, `docs/`
  - [ ] **Borrar** las balas `deferred-work.md:56` y `:57` (las cierra esta historia). Es un registro
        pending-only: se borra la bala, no se anota "hecho". **Dejar** `:58` y `:59`.
  - [ ] **Barrer los IDs de historia/requisito que la Story 1.1 dejó mergeados en los ficheros que toques.**
        Medido en `f86b2662`: 23 apariciones en `api/src/Shared/Images/` y 12 en sus tests, entre ellas
        `UploadImage.php:20` (*"Story 1.2 extends this same class…"*), que es justo el fichero de Task 6, e
        `InterventionImageProcessor.php:40`. `CLAUDE.md` → *Code comments* los prohíbe en `main` y manda la
        regla del boy-scout al editar; sin barrido explícito nadie los quita.
  - [ ] Actualizar `docs/architecture-api.md` si se añade transporte/evento, `docs/rules/database.md` si el
        mapeo del shared kernel establece patrón nuevo, y `PRODUCTION_SECURITY_CHECKLIST.md` por el volumen
        de storage y el residual que quede abierto.

- [ ] **Task 13 — Pase adversarial y cierre** (proceso, no código)
  - [ ] Lectura hostil **externa** sobre el código implementado (fresh context, otro modelo o una persona),
        registrada en `## Adversarial pass` de este fichero y **commiteada** ANTES de `gh pr create`. El ADR
        lo exige nominalmente para historias que tocan borrado
        (`images-vs-documents-conservation-contract.md:129`); la autocertificación no cierra el gate.
  - [ ] `make php.stan` sobre cada fichero nuevo/tocado; `make php.deptrac`; `make php.quality`;
        `make php.unit` completo. Cada uno con exit code impreso y fresco, no de una ejecución anterior.
  - [ ] Re-derivar el *File List* de `git diff --stat` antes de marcar done: el de la Story 1.1 se quedó
        corto en tres ficheros.

## Dev Notes

### La decisión abierta — forma de persistencia del agregado `Image`

**No la cierres tú solo.** `CLAUDE.md` (*Per-aggregate persistence strategy*) reserva al usuario las
decisiones de estrategia de persistencia y de frontera de agregado. Ninguna AC ni el decision firewall fijan
esto, y las dos opciones tienen coste medido.

Estado medido hoy (`api/src/Shared/Images/Domain/Image.php:21-56`): `final readonly class Image`, con
`private ImageId $id` (VO), cinco escalares privados y `public DateTimeImmutable $createdAt` asignado en el
cuerpo del constructor vía `SystemClock::now()`. Constructor único público, sin constructores nombrados. No
extiende `AggregateRoot`, no usa `Identifiable` ni `Timestamped`, no lleva ni un `use Doctrine\…`.

Contexto que restringe:

- **No hay ni una sola entidad ORM bajo `api/src/Shared/`.** `Image` sería la primera del shared kernel.
- Toda entidad del árbol sigue el mismo patrón: `AggregateRoot` mutable + traits `Identifiable`
  (`?string $id`, `#[ORM\Id]`, `Types::GUID`, sin `GeneratedValue`) y `Timestamped`.
- ORM 3.6.8 sobre Symfony 8 ya no dispone de `ProxyHelper::generateLazyGhost` — el propio código lo anota,
  *"This method has been removed in Symfony 8"* (`api/vendor/doctrine/orm/src/Proxy/ProxyFactory.php:166`) —
  y usa objetos perezosos nativos de PHP (`ProxyFactory.php:217`, `reflClass->newLazyGhost()`). No habilitarlos
  está deprecado camino de ORM 4.
- `Timestamped` mapea `created_at` **y** `updated_at`, ambos `NOT NULL`
  (`api/src/Shared/Kernel/Domain/Entity/Timestamped.php:13-17`). El esquema cerrado de `Image` son siete
  campos, sin `updatedAt`.

| | **A — mantener `final readonly` + VO de identidad** | **B — patrón de casa (`AggregateRoot` + traits)** |
|---|---|---|
| Coherencia con el módulo | Alta: `Image` ya es así, y el ADR dice que es un recurso técnico, no un proceso de negocio | Baja: rompe el VO `ImageId` como tipo de la identidad (`Identifiable` da `?string`) |
| Coherencia con el árbol | **Nula: sin precedente** | Alta: idéntico a `Bank`, `Session`, `Membership`… |
| Esquema mínimo (AC 6) | Se respeta tal cual | `Timestamped` añade `updated_at` → hay que **no** usar el trait, o justificar la columna extra |
| Riesgo Doctrine | Hidratación de `final readonly` + lazy objects nativos: sin precedente aquí, hay que **medirlo** | Cero: es el camino trillado del repo |
| Tests que rompe | `ImageTest.php:89-105` afirma `isFinal()`+`isReadOnly()` — sobrevive si el ctor no cambia | Ese mismo test se reescribe entero |
| Eventos de dominio | `Image` no puede `pullDomainEvents()` (no extiende `AggregateRoot`) → la señal la publica el caso de uso | Disponible de serie |

**Si eliges A, la medición es obligatoria y va primero**: un test funcional que persista y rehidrate una
`Image` contra Postgres real. No basta con que compile.

Hay una tercera opción intermedia — entidad mutable **sin** `AggregateRoot`, con `Identifiable` y un
`createdAt` mapeado a mano sin `updatedAt` — que evita la columna extra y el riesgo de `readonly` a cambio de
perder el VO en la identidad. Preséntala también.

### Estado actual medido del módulo (`f86b2662`)

15 ficheros PHP en `api/src/Shared/Images/`. Lo que **existe**: `ImageId`, `Image`, `CanonicalImage`, el puerto
`ImageProcessor`, siete clases de excepción + el enum `FailureCategory`, `UploadImage`,
`InterventionImageProcessor`, `ImagePreflightGuard`, `MediaTypeEncoderFactory`.

Lo que **NO existe** y esta historia crea — verificado, no supuesto:

- `ImageStorage` — cero apariciones en `api/src` y `api/config`; sólo en prosa de planificación.
- `ImageRepository` — igual.
- Atributos `#[ORM]` sobre `Image`, y entrada `Shared` en `doctrine.yaml`.
- Migración y tabla. La tabla `media` histórica fue destruida a propósito (`Version20260723104340.php`).
- Cualquier librería de filesystem. **Cero paquetes `league/*` instalados.**
- Eventos de dominio o rutas Messenger del módulo. `messenger.yaml:17-24` enruta 7 eventos, todos
  `Backoffice\Bank*`.
- Tests funcionales o Behat del módulo.
- Cualquier `type` de `ProblemDetails` en sus excepciones.

`CanonicalImage` calcula el digest **en Domain, sobre los bytes canónicos**, como propiedad derivada que no
se acepta por constructor (`CanonicalImage.php:16-30`) — precisamente para que no pueda divergir de los
bytes. Lo que `store()` recibe es `CanonicalImage::$bytes` y nada más (AC 15).

### Storage: dependencia nueva y arte previo

Hay una implementación retirada, medida como *"aprovechable a medias"* por el inventario de rescate
(`deferred-work.md:80`): `git show 08f8199^:api/src/Shared/Storage/Infrastructure/FlysystemStorage.php`.

- **Sirve**: la forma general (`#[AsAlias]` sobre el puerto, `#[Target('erpify.storage')]` para el operator
  nombrado, traducción de `UnableToReadFile`), y el fichero de config
  `git show 08f8199^:api/config/packages/flysystem.yaml`.
- **No sirve**: la clave derivada del hash (invariante 2 la prohíbe — AC 5 la ata a `ImageId`), y el
  `fileExists()` previo al `delete()`, que es un TOCTOU y un I/O extra si la librería ya es idempotente.
- **Regla del inventario** (`epics-images.md:379-382`): *"rescatar comportamiento, no nombres ni modelos
  mentales"*. El namespace pasa a `images`; no revivas `StoragePort`, `StoredObject`,
  `ContentAddressableObjectKey` ni `StoredImageObjectWriter` — el ADR los borra por construcción.

**Gotcha heredado que sobrevive aunque su clase no** (`epics-images.md:371-373`): un BLOB de Doctrine puede
devolverse con el puntero ya en EOF, y `stream_get_contents` desde EOF devuelve `''`, no `false` → corrupción
cacheable servida como cuerpo de respuesta. Leer siempre desde offset 0. Aplica en cuanto el adaptador
maneje streams.

### El evento de lifecycle: por qué se enruta y con qué línea

`api/.persistent-transport-policy` obliga a clasificar cada `aggregateType()`:

```
<AggregateType> => non-person
<AggregateType> => person
<AggregateType> => person :: <ruta del ADR que registra por qué se encola igualmente>
```

y `make php.lint.persistent-transport` rompe la build si falta la línea — **esté el evento enrutado o no**:
*"every `aggregateType()` declared in `src` must be classified, routed or not"*
(`PersistentTransportPolicyGateTest:28`), porque clasificar sólo lo enrutado *"would hand the decision to
whoever routes it, in the same diff that introduces the defect"*. Así que dejarlo sin enrutar **no ahorra la
decisión**; sólo la aplaza hasta que el dev vea el rojo.

Es cierto que `Image` sería persona-denotante para un avatar y no-persona para un logo
(`epics-images.md:47-54`), pero ese argumento está escrito para `api/.audit-resource-types` y **no se
traslada**: no auditar significa que no existe ningún `resource_type`, mientras que no enrutar sí deja un
`aggregateType()` declarado en `src`. El registro además prevé este caso exacto y le da respuesta —
*"Where a type is mixed, the conservative verdict is the correct one, and routing any of its events then
needs an argued ADR exception"* (`:27-31`)—, con precedente vivo en el mismo fichero: `Iam.Session => person`
(`:67`).

Y la alternativa no es neutra. Un `DomainEvent` sin enrutar **se maneja en proceso**:
`RunProjectionsOnDomainEvent` es `#[AsMessageHandler(handles: DomainEvent::class)]`, así que todo
`DomainEvent` tiene handler, y los casos de uso publican dentro de `TransactionManager::transactional(...)`
(`BankUpdater.php:41-45`). El handler correría por tanto **dentro de la transacción del propietario**: es el
efecto lateral síncrono que NFR4 (`epics-images.md:242-245`), el decision firewall (`:449-450`) y el
invariante 4 del ADR (`:116`) prohíben cada uno por su cuenta, y un fallo de Flysystem rollearía la escritura
de negocio dejando una referencia viva sobre bytes ya destruidos.

Por eso AC 12 enruta a `async` y clasifica `Image => person :: <ruta del ADR>`. Anota la razón en el código y
en el ADR: sin ella, el siguiente lector quita la ruta "porque `Image` no es una persona" y reintroduce el
borrado síncrono.

**Lo que ningún routing arregla**: `PersistDomainEventMiddleware` appendea todo evento despachado a
`event_store` **antes** de que Messenger elija transporte, con su `aggregate_id` real — *"regardless of
routing"* (`api/.persistent-transport-policy:43-45`). Ese eje queda igual se enrute o no, y es RESIDUAL-4 de
AC 18.

### Registros y gates que morderán

| Registro / gate | ¿Toca? | Por qué |
|---|---|---|
| `api/.person-reference-policy` · `make php.lint.person-reference` | **Sí, si el mapeo añade una columna `Types::GUID`** | La build rompe sin línea. `Image::$id` es `non-person`: el `ImageId` se vuelve dato personal en el **consumidor**, que es quien lo declara (AC 17) |
| `api/.persistent-transport-policy` · `make php.lint.persistent-transport` | **Sí, siempre** | Exige línea para todo `aggregateType()` declarado en `src`, **enrutado o no** (`PersistentTransportPolicyGateTest:28`). AC 12 añade `Image => person :: <ruta ADR>`, y el ADR debe existir o el gate la rechaza |
| `api/tools/deptrac/deptrac.yaml` · `make php.deptrac` | **Sí** | Nuevo vendor → collector `Vendor.Flysystem`, permitido sólo en `Shared.Infrastructure` |
| `make php.lint.event-bus` | **Sí (por omisión)** | `Application/` no puede importar Doctrine ni Messenger: usa `TransactionManager` y `EventBus` |
| `api/tests/Unit/Gate/BestEffortReportChannelGateTest.php` | **Sí** | Toda clase nueva que loguee en `observability` entra en `REPORTERS` (`:89-98`) o `make php.unit` rompe |
| `api/.artifact-gate-placement` · `make php.lint.gate-placement` | **Sólo si añades un gate** | Los gates viven en `api/tests/Unit/Gate/` y se clasifican ahí |
| `api/.audit-resource-types` | **No** | Esta épica no escribe filas de `audit_log` (`epics-images.md:47-54`) |
| `api/.bounded-context-allowlist` | **No** | `Erpify\Shared\…` es siempre importable; el propio fichero lo dice en su línea 7 |
| `api/.error-contract-allowlist` · `make php.lint.error-contract` | **Sólo si tocas el contrato** | Ver Task 10; si añades marcador, `docs/api-error-contract.md` es obligatorio (NFR26) |

Además, PHPMD mordió a la Story 1.1 con **coupling-between-objects máximo 13** (el adaptador llegó a 19) y
**10 métodos públicos por clase de test** (llegó a 20). Se resolvió extrayendo colaboradores y partiendo la
clase de test **por concern**, compartiendo helpers con un trait. Un adaptador de storage con traducción de
excepciones y logging va derecho al mismo techo — cuenta con ello desde el principio en vez de refactorizar
al final.

### Naming

- Puertos por **capacidad**, nunca `*Interface`; adaptador `<Tecnología><Puerto>`. `ImageProcessor` /
  `InterventionImageProcessor` fijaron el patrón → `ImageStorage` / `FlysystemImageStorage`,
  `ImageRepository` / `DoctrineImageRepository`.
- Dobles de test: `InMemory<Puerto>` si es implementación alternativa usable, `Stub<Puerto>` si sólo devuelve
  un valor fijo. Viven junto a los tests que los usan.
- `UploadImage` **no se renombra**. `docs/rules/cqrs-naming.md` categoría 6 existe por él, y su texto nombra
  esta historia. Cualquier clase nueva encaja en una categoría existente o añade una con su argumento de
  principio/objetivo/coste.
- Tres nociones de MIME que la Story 1.1 mantiene separadas y que no son intercambiables: *declarado* (no
  fiable, nunca selecciona decoder), *detectado* (`finfo`, única base fiable), *canónico*
  (`CanonicalImage::$mediaType`). Lo que persiste `Image.mediaType` es el **canónico**, y la Story 1.3 lo usa
  como `Content-Type` autoritativo sin volver a inspeccionar bytes.

### Reutilización — no reinventar

- Transacción: `Erpify\Shared\Persistence\Application\TransactionManager::transactional(callable): mixed`.
  Adaptador `DoctrineTransactionManager`, que además traduce `RetryableException` →
  `TransientTransactionFailure` y `ForeignKeyConstraintViolationException` → `ReferentialIntegrityViolation`.
- Publicación: `Erpify\Shared\Event\Domain\EventBus::publish(DomainEvent ...$events)`. Call site de
  referencia: `BankUpdater.php:41-45`.
- Reloj: `Erpify\Shared\Clock\Domain\SystemClock::now()`, reseteable en tests por la extensión PHPUnit ya
  registrada (`api/tools/phpunit/phpunit.dist.xml:50`). No inyectes un `Clock` por DI en una clase que se
  construye con `new`.
- Identidad: `Erpify\Shared\Uuid\Domain\Uuid` (`generate()` = UUID v7 RFC 4122; `ensure()` lanza
  `InvalidUuidException` → 400 `invalid-uuid`).
- Observabilidad: canal Monolog `observability` ya existente. Nada de infraestructura de métricas nueva.
- Idempotencia bajo entrega at-least-once, si algún día se enruta: el puerto
  `DomainEventHandlerDeduplicator` y la tabla `handled_domain_event` ya existen
  (`docs/adr/domain-event-handler-idempotency.md`).

### Testing

- Árbol espejo: `api/tests/Unit/Shared/Images/{Domain,Application,Infrastructure}/`. Funcionales en
  `api/tests/Functional/…`, contra **Postgres real**, extendiendo `KernelTestCase`.
- Fixtures binarias en `api/tests/Fixtures/Images/` — **nunca** en `api/tests/DataFixtures/Fixtures/`, que es
  para Alice/YAML.
- Convención por fichero: `declare(strict_types=1)`, `/** @internal */`, `#[CoversClass(...)]`, `final class
  …Test`, nombres de método como frase larga.
- Selección: `make php.unit c='--filter NombreDeClase'`.

### Matriz AC → test

| AC | Qué prueba | Nivel |
|---|---|---|
| 1 | `store()` + relectura → bytes idénticos y `SHA-256` == `Image.digest` | Integración, storage real. **Ojo**: un filesystem temporal sano nunca produce la escritura parcial que la AC dice detectar, así que este test prueba la propiedad débil. La detección real depende de dónde caiga la decisión de Task 4 (verificación en `store()` vs. en un test); si cae en el test, la AC no es una propiedad del sistema y hay que decirlo |
| 2 | `delete()` sobre ausente → éxito; existencia no establecible → fallo; fallo real de infra → fallo | Integración con **adaptador real** para las tres ramas (permisos retirados, montaje ausente); un doble sólo prueba que el caso de uso propaga, no la propiedad del `delete()` instalado |
| 3 | Ausencia confirmada, fallo transitorio y fallo permanente son tipos distintos, y cada semántica dice a qué operación pertenece | Unit + un test que enumere la superficie del puerto |
| 4 | Línea emitida con `operation` ∈ {`store`,`read`,`delete`,integridad}; ni `imageId`/`digest`/key/bytes/filename **como VALOR** (serializa el contexto y busca la subcadena; un chequeo por nombre de clave no vale) | Unit (afirma la línea **antes** que la ausencia) |
| 5 | La key deriva sólo de `ImageId` | Unit sobre el adaptador |
| 6 | Esquema exacto de siete campos | Unit estructural. **La revisión de la migración y `doctrine:mapping:info` son lectura humana, no gates**: si la entrada `Shared` de `doctrine.yaml` desaparece, `make db.diff` emite una migración vacía sin error y nada se pone rojo. Hace falta un test que afirme las columnas contra el esquema real |
| 7 | Fallo de persistencia tras `store()` exitoso deja huérfano y nadie compensa | Integración |
| 8 | El `ImageId` no es observable antes del commit | Integración |
| 9 | Orden storage→fila; fallo del 2.º paso deja estado reintentable | Integración |
| 10 | Cero `#[AsEntityListener]` en el módulo | Unit estructural. **Acotado al módulo por diseño, y ese es su límite**: `BankStoredObjectRemoveListener` vivía fuera de `Shared/Images/`, y un listener registrado por tag `doctrine.event_listener` en `services.yaml` o vía `#[AsDoctrineListener]` tampoco se ve. El test cubre la letra de la AC, no su propósito |
| 11 | Payload = sólo `ImageId` | Unit |
| 12 | El evento está enrutado a `async` y su línea de política existe con ADR vivo | `make php.lint.persistent-transport` (verde con la línea puesta) + un test que afirme la clave de routing, porque la revisión de YAML es humana |
| 13 | Ningún método del puerto retorna URL | Unit estructural con **lista explícita de nombres prohibidos** (`publicUrl`, `temporaryUrl`, `url`): `publicUrl(ImageId): string` y `read(ImageId): string` comparten tipo de retorno, así que el tipo no distingue |
| 14 | Mismos bytes → 2 `ImageId` → mismo digest → **2 objetos de storage** | Integración, storage real |
| 15 | Sólo bytes canónicos llegan a `store()` | Unit con doble espía. No observa bytes originales escritos en otro sitio (temporal, log, `$previous`), que es la mitad que la AC también afirma |
| 16 | Excepciones de Flysystem traducidas; sin `catch (\Throwable)` | Unit |
| 17 | Nota documentada en el contrato del puerto | Revisión de código/doc, no test ejecutable |
| 18 | Residuales documentados | Revisión de código/doc, no test ejecutable |

### Project Structure Notes

```
api/src/Shared/Images/
├── Domain/
│   ├── Image.php                     (MODIFICADO — mapeo/hidratación, según Task 0)
│   ├── ImageId.php                   (sin cambios)
│   ├── ImageStorage.php              (NUEVO — puerto)
│   ├── ImageRepository.php           (NUEVO — puerto; o Domain/Repository/, decide y sé coherente)
│   ├── Event/…                       (NUEVO — señal de lifecycle, payload sólo ImageId)
│   └── Exception/…                   (NUEVO — ausencia confirmada vs. fallo transitorio)
├── Application/
│   ├── UploadImage.php               (MODIFICADO — + storage + persistencia, MISMA firma)
│   └── …Delete…                      (NUEVO — orden storage → fila)
└── Infrastructure/
    ├── FlysystemImageStorage.php     (NUEVO)
    └── Persistence/Doctrine/DoctrineImageRepository.php   (NUEVO)

api/config/packages/doctrine.yaml     (MODIFICADO — falta el mapping del shared kernel)
api/config/packages/flysystem.yaml    (NUEVO)
api/config/packages/messenger.yaml    (MODIFICADO — ruta async de la señal de lifecycle, AC 12)
api/.persistent-transport-policy      (MODIFICADO — Image => person :: <ruta ADR>)
docs/adr/…                            (NUEVO — el ADR que la línea de política nombra)
api/migrations/2026/Version2026….php  (NUEVO)
api/tools/deptrac/deptrac.yaml        (MODIFICADO — Vendor.Flysystem)
compose*.yaml / .gitignore            (MODIFICADO — volumen de storage)
```

Ojo: `api/config/services.yaml:43` excluye `'../src/**/Domain/Entity/'` del contenedor. `Image` está hoy
en `Domain/Image.php`, **fuera** de esa ruta. Si la conviertes en entidad, muévela a `Domain/Entity/` para
que la exclusión aplique — es la convención del resto del árbol.

### Fuera de alcance — no lo construyas aquí

Controlador HTTP de lectura o de subida · `Content-Type`/`ETag`/`304`/`Cache-Control`/`Range` ·
`IS_AUTHENTICATED_FULLY` · el scan formal de NFR6 y los tests de `#[MapUploadedFile]` · auditoría de lectura ·
`Bank.logoImageId` / `User.avatarImageId` · variantes y su URL · deduplicación · refcount · GC ·
content-addressed storage · adaptador S3 · event sourcing sobre `Image` · contexto `Documents` · el campo de
origen de derivada que el ADR D5 describe (fuera de esta rebanada por el esquema mínimo de siete campos,
`epics-images.md:420-424`; el firewall no lo nombra) · benchmark de los límites
de recursos y vetting de `intervention/gif` (**son de la Story 1.3, no los absorbas ni los dejes caer**).

### References

- [`_bmad-output/planning-artifacts/epics-images.md`](../planning-artifacts/epics-images.md) — corte de la
  Story 1.2 en `:631-737`; pase adversarial `:68-173`; NFR3/4/7/9 en `:230-234`, `:235-257`, `:271-282`,
  `:290-299`; frontera transaccional `:361-370`; esquema mínimo `:420-424`; decision firewall `:442-455`.
- [`docs/adr/images-vs-documents-conservation-contract.md`](../../docs/adr/images-vs-documents-conservation-contract.md)
  — D5 (borrado fiable), D6 (primera rebanada y la asimetría del puerto), invariante 4 y sus tres
  consecuencias, y en `:129` la exigencia nominal de pase adversarial para esta historia.
- [`docs/adr/event-driven-architecture.md`](../../docs/adr/event-driven-architecture.md) — outbox, el
  invariante `EventBus` → outbox, y `transactional(save+publish)`.
- [`docs/adr/domain-event-handler-idempotency.md`](../../docs/adr/domain-event-handler-idempotency.md) — por
  qué NFR7 exige idempotencia: la entrega es at-least-once.
- [`docs/rules/database.md`](../../docs/rules/database.md) — identificadores UUID v7 app-asignados `:81-92`;
  mecanismo de persistencia `:94`; política de borrado duro `:30`.
- [`docs/rules/cqrs-naming.md`](../../docs/rules/cqrs-naming.md) — categoría 6, que nombra esta historia en
  `:42-59`.
- [`docs/rules/testing.md`](../../docs/rules/testing.md) — convención de nombres de dobles; afirmar la
  siembra antes que la ausencia.
- [`docs/api-error-contract.md`](../../docs/api-error-contract.md) — tabla marcador→status; `ServiceUnavailable`
  → 503 es el candidato natural para el fallo transitorio.
- [`docs/rules/security.md`](../../docs/rules/security.md) — `:73`, el texto de una excepción se persiste en
  `messenger_messages` vía `ErrorDetailsStamp`, sumidero que ningún erasure alcanza.
- [`_bmad-output/implementation-artifacts/img-1-1-subir-imagen-obtener-representacion-canonica.md`](img-1-1-subir-imagen-obtener-representacion-canonica.md)
  — frontera 1.1/1.2, decisión *fail-closed* de la review, y los diferidos que apuntan aquí.
- [`_bmad-output/implementation-artifacts/deferred-work.md`](deferred-work.md) — `:56` y `:57` los cierra esta
  historia (bórralas); `:58` y `:59` no; `:80-81` el inventario de rescate y la lista de lo que no revivir.
- `git show 08f8199^:api/src/Shared/Storage/Infrastructure/FlysystemStorage.php` — adaptador retirado:
  plantilla de forma, **no** de la clave ni del `fileExists()`.
- `git show 08f8199^:api/config/packages/flysystem.yaml` — config previa del bundle (renombrar a `images`).
- `api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` y
  `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` — patrón
  puerto/adaptador por composición.
- `api/migrations/2026/Version20260819091752.php` — plantilla de migración (descripción, docblock del
  *porqué*, `down()` que restaura forma y no contenido).
- Flysystem: la [documentación oficial de la API](https://flysystem.thephpleague.com/docs/usage/filesystem-api)
  **no declara** el comportamiento de `delete()` sobre fichero ausente — consultada 2026-08-27. Mide contra
  el `vendor/` instalado; no cites esta URL como si respondiera la pregunta.

## Change Log

- 2026-08-27 — Historia creada. El análisis midió el árbol contra el corte del épico y encontró siete
  desajustes que ninguna AC anticipaba (agregado no mapeable, mapping del shared kernel ausente en
  `doctrine.yaml`, `Timestamped` incompatible con el esquema mínimo, la clasificación imposible del
  transporte persistente, las excepciones fuera del pipeline RFC 9457, la ausencia total de volumen de
  storage en los tres compose, y el TOCTOU del `delete()` rescatable); todos incorporados como AC o tareas.
  La forma de persistencia de `Image` queda como decisión abierta para Sergio, no cerrada en el borrador.
- 2026-08-28 — Pase adversarial externo (`bmad-code-review`, sesión distinta). Hallazgo grave: el A-4 original
  era falso en dos direcciones —el gate de transporte exige línea *"routed or not"*, y el registro ya prevé el
  caso mixto con veredicto conservador más excepción ADR—, y la AC 12 que de él se derivaba reinstalaba el
  borrado síncrono dentro de la transacción del propietario que NFR4, el decision firewall y el invariante 4
  del ADR prohíben, reintroduciendo el GRAVE-1 del pase adversarial del propio épico. Resuelto por Sergio
  eligiendo enrutar a `async` con `Image => person :: <ruta ADR>`. Se aplicaron 16 patches: AC 2 recupera la
  tercera rama de NFR7, AC 3 enumera la superficie del puerto y añade la clase de fallo permanente, AC 4
  cubre `read` e integridad y afirma por valor en vez de por nombre de clave, AC 7 construye el agregado
  antes de escribir, AC 10 recupera la palabra `(outbox)`, AC 18 añade el residual de `event_store`, se
  corrigió la aritmética de A-5, se retiró la escapatoria de Task 5 que negaba AC 1, se anotaron las ocho
  filas de la matriz cuyo test no observa el fallo de su AC, y se enderezaron las citas de línea desviadas.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
