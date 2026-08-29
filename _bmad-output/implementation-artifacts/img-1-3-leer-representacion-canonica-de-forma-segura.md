---
baseline_commit: 202767ab7bfc865c5611774b027ec2a29b7ada84
---

# Story 1.3: Leer la representación canónica de una imagen de forma segura

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a bounded context consumidor (o su interfaz de usuario, cuando exista),
I want recuperar los bytes canónicos de una imagen por su `ImageId` a través de una ruta autenticada, con
soporte de cache HTTP condicional,
so that puedo servir la imagen sin conocer dónde ni cómo se almacena, y sin pagar el coste de una respuesta
completa cuando el cliente ya tiene la versión vigente.

## Frontera de esta historia — leer antes de tocar código

- **Sí entrega**: el recurso de routing que hace registrable el módulo; el controlador de lectura
  canonical-only bajo `/api/v1`; el caso de uso de lectura en `Application/`; la lectura **verificada**
  contra `Image.digest` antes de comprometer cabeceras; el `ETag` derivado del digest y la doble puerta del
  `304`; `Cache-Control`, `Content-Type`, `Content-Length`, `X-Content-Type-Options`; el mapeo del
  vocabulario de fallo del puerto a status HTTP; la cota del productor de log de ausencia; el scan formal de
  NFR6 (ejes tipo **y** valor) con su test de falsabilidad; los dos tests de `#[MapUploadedFile]`; la primera
  feature Behat del módulo; el benchmark de los tres límites de recursos y el vetting de `intervention/gif`;
  y la ampliación de los residuales de `PRODUCTION_SECURITY_CHECKLIST.md` §7.

- **No entrega** (y no debe tocarse en esta PR): ningún endpoint de **subida** de producción; `Bank.logoImageId`
  / `User.avatarImageId` ni ninguna integración de consumidor; auditoría de la ruta de lectura; voter de
  ownership; variantes de imagen y su esquema de URL; `Range` real (`206`/`416`); adaptador S3; contexto
  `Documents`; y cualquier cambio al contrato de canonicalización de 1.1 o al contrato del puerto de 1.2.

- **Decision firewall de la épica (no reabrir aquí, listado textual de `epics-images.md:442-455`)**:
  `ImageId ≠ digest` · `ImageId ≠ storage key suministrada por el caller` · `Image` no contiene bytes · no
  contiene `owner` · no contiene `filename` · no contiene `url` · no contiene `variant` · sin deduplicación ·
  sin refcount · sin GC · sin content-addressed storage · sin event sourcing sobre `Image` · sin contexto
  `Documents` · sin ingestión de evidencia · sin consumidor `Bank` · sin consumidor `User` · **sin voter de
  ownership genérico** · **sin auditoría genérica de lectura** · borrado de bytes ≠ transacción síncrona del
  propietario · **`ImageStorage` nunca devuelve una URL** · **ruta de lectura ≠ path de filesystem** · sin
  abstracción `ImagePipelineProducer`/`ImageUploadSource` anticipatoria · **sin URL de variante
  `/{imageId}/{hash}/{variant}`**.

- **Dos cláusulas del ADR que el épico ANULA — léelas antes de tocar la ruta.** `docs/adr/images-vs-documents-conservation-contract.md`
  D6 dice literalmente *"the read is audited like any other"* y *"the story that serves bytes declares the
  voter it expects"*. El épico decide lo contrario en `epics-images.md:45-60`: **cero filas de `audit_log`** y
  **`IS_AUTHENTICATED_FULLY` sin voter**, porque un único `resource_type` no puede ser a la vez
  persona-denotante (avatar) y no-persona (logo), y porque no existe todavía relación de consumidor sobre la
  que votar. Un dev que lea el ADR sin esta nota implementaría exactamente lo contrario. **La jerarquía
  documental del repo (`epics.md` > arquitectura) resuelve el conflicto a favor del épico**, y la AC 19 lo
  convierte en algo comprobable en vez de en una intención.

- **Cerrado por 1.1 y 1.2, y esta historia lo consume sin reabrirlo**: el contrato de canonicalización v1 de
  ocho propiedades; `digest = SHA-256(bytes canónicos)`, v1 implícito sin campo de versión; el esquema exacto
  de siete campos; la superficie del puerto `store`/`read`/`delete` y su vocabulario de tres clases; el orden
  de borrado bytes→fila; el sharding por la **cola** del `ImageId`; y `ImageId` normalizado a minúsculas en su
  constructor privado (`api/src/Shared/Images/Domain/ImageId.php:34`).

## Adversarial pass

**Estado: un pase, autoadministrado sobre el borrador, y por sí solo NO cierra el gate.** El bloque A-1..A-14
de abajo es la lectura hostil de esta sesión contra el árbol en `202767ab`. El `CLAUDE.md` raíz exige que la
lectura hostil la haga alguien distinto del autor y que sus hallazgos estén **escritos en el artefacto y
commiteados antes de `gh pr create`** — no después, y no en una PR de seguimiento. Esta historia toca una
superficie de autenticación y un sumidero de log, así que el pase externo es obligatorio y su ausencia es la
única razón por la que este artefacto todavía no puede abrir PR. Cuando corra, se registra aquí abajo con sus
hallazgos y su disposición, hallazgo a hallazgo.

Todos los hallazgos de abajo están medidos contra el árbol, con el fichero y la línea que los sostiene.

- **A-1 (GRAVE) — la decisión "cero filas de `audit_log`" depende del NOMBRE de la ruta, y de una rama que hoy
  es código muerto que no pincha nadie.** `AccessLogAuditListener` audita en `kernel.terminate` toda petición
  **main**, con respuesta **2xx**, cuyo path empiece por `/api/` (`ApiRequestMatcher.php:17`). `AuditPolicy`
  audita entonces **todo `GET`** salvo cinco formas, y la quinta es
  `\str_starts_with($route, 'shared_')` (`src/Shared/Audit/Domain/AuditPolicy.php:66`), cuyo propio docblock la
  describe como *"asset/object serving (`shared_*`)"* (`:56`). Medido: **cero rutas** del árbol empiezan por
  `shared_` (48 nombres de ruta declarados en `src`), y el data provider de casos no auditables de
  `tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php:82-87` rinde **seis** casos — tres health, realtime,
  hot-reload y `_count` — y **ninguno `shared_`**. Es decir: hoy esa rama es inalcanzable y borrarla deja todo
  en verde. Un controlador llamado `images_get` o `backoffice_image_get` escribiría una fila `ACTIVITY` con
  acción `ROUTE_IMAGES_GET` por cada lectura con éxito, **y ningún gate se pondría rojo** — `RequestAuditResourceExtractor`
  devuelve `null` sin `_audit_resource_type`, así que `api/.audit-resource-types` ni se entera. Y el fósil
  confirma la intención: el controlador retirado se llamaba **`shared_stored_object_get`**
  (`git show 08f8199^:api/src/Shared/Storage/Infrastructure/Controller/StoredObjectGetController.php`), o sea
  que esa exclusión se escribió para exactamente este caso y lleva desde `08f8199` sin usuario. Incorporado
  como AC 19 y AC 20, con test propio de la rama.

- **A-2 (GRAVE) — la AC del épico ofrece tres cotas para el log de ausencia y una de las tres es letra
  muerta.** `epics-images.md:788-800` admite *"muestreo, contador agregado o nivel `debug` para la ausencia en
  `read()` frente a `info` en `delete()`"*. Medido: `tests/Unit/Gate/ObservabilityChannelGateTest.php:42` fija
  `private const string PINNED_LEVEL = 'info'` y `:85` lo afirma para **test y prod**, con el mensaje
  *"LOWERING it to `debug` opens an unrotated stream with no declared owner of its erasure to every framework
  record"*; el handler `observability` de prod es `level: info` (`api/config/packages/monolog.yaml`). Un
  `$logger->debug(...)` se **descarta en todos los entornos**: satisface la letra de la AC y destruye el
  argumento de contabilidad que img-1-2 estableció. Quedan dos opciones reales, y elegir entre ellas es
  Task 0.

- **A-3 (GRAVE) — `src/Shared/` no lo carga ningún recurso de routing, así que un `#[Route]` ahí no registra
  nada y todo el árbol se queda verde.** `api/config/routes.yaml` declara seis recursos de atributos
  (`../src/Backoffice/`, tres directorios acotados de `Iam`, `../src/Frontoffice/`) y **ninguno cubre
  `src/Shared/`**; medido, `grep -rn '#\[Route' api/src/Shared/` devuelve **cero**. Un controlador nuevo bajo
  `Shared/Images/Infrastructure/Http/` compila, pasa PHPStan, deptrac y PHPUnit, y su ruta responde 404. La
  historia añade el recurso; sin él, la mitad de las AC son inobservables. Incorporado como AC 18 y Task 2.

- **A-4 (GRAVE) — el épico escribe la ruta como `GET /images/{imageId}`, y en esa ruta literal el firewall no
  la ve.** La regla terminal de `api/config/packages/security.yaml:74` es
  `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }`, y su propio comentario dice que está acotada a `^/api`
  *"(not `^/`)"* para que `/_dev`, `/_wdt`, Mercure y la PWA reverse-proxeada queden fuera. Un `/images/{imageId}`
  literal sería **anónimo por construcción**, y `make php.lint.public-access` seguiría **verde** porque ese
  gate lee `security.yaml`, nunca el router. La ruta va a `/api/v1/images/{imageId}` y por tanto **no necesita
  ninguna línea nueva de `access_control` ni ninguna entrada en `api/.public-access-exemptions`**: cae en la
  catch-all, igual que `/api/v1/me*`. Incorporado como AC 1 y AC 18.

- **A-5 (SERIO) — el `ContentAddressedHttpCache` que el épico manda rescatar emite caché COMPARTIDA, y esta
  ruta es autenticada.** Medido en `08f8199^`: `applyHeaders()` llama `$response->setPublic()` y fija
  `Cache-Control: public, max-age=31536000, immutable`. Copiarlo tal cual pondría una respuesta autenticada en
  cachés compartidas, servible entre usuarios distintos — exactamente lo que la AC de `Cache-Control` del
  épico prohíbe (`epics-images.md:158-166`, MEDIA-7: *"`private` porque la ruta exige autenticación"*). Lo
  rescatable es **`isNotModified()`**, no `applyHeaders()`. Incorporado como Task 6 con la instrucción
  explícita.

- **A-6 (SERIO) — la "doble puerta" del `304` que el épico manda rescatar se apoya en un `exists()` que el
  puerto de 1.2 no tiene, y que no debe añadirse.** El controlador retirado hacía
  `existsAnyWithContentHash($hash) && $storagePort->exists($key)` antes de responder `304`. `ImageStorage` tiene
  exactamente tres métodos (`store`/`read`/`delete`) y su predicado interno `objectExists()` es privado y
  **lanza** cuando la existencia no es decidible — que es precisamente el GRAVE-1 del tercer pase de img-1-2
  (una guarda que inspeccionaba el directorio contenedor se saltaba a sí misma con un shard superior a modo
  0000). Exponer un `exists()` público reabre esa decisión y contradice el contrato cerrado del puerto. **La
  consecuencia hay que decirla en vez de esconderla: el `304` cuesta una lectura completa de storage**, igual
  que el `200`; lo único que ahorra es el cuerpo en el cable. Es el precio de no responder `304` sobre un
  objeto ausente y de no reabrir el puerto, y se paga a sabiendas. Incorporado como AC 13 y Dev Notes.

- **A-7 (SERIO) — RESIDUAL-1 del épico está mal escrito: sí hay rate limiting hoy.** `epics-images.md:137-146`
  y `:860-868` dicen que *"rate-limiting sobre esta ruta queda como candidato explícito para la épica del
  primer consumidor real"*, lo que se lee como que no hay ninguno. Medido:
  `src/Shared/ErrorContract/Infrastructure/Http/EventListener/RateLimitListener.php:113-131` gatea sólo por
  main request, prefijo `/api/` y método ≠ `OPTIONS`, **no consulta la autenticación en ningún punto**, y
  consume del limitador `anonymous_api` keyado por **IP de cliente**: `RATE_LIMIT_ANONYMOUS_API_LIMIT=120` por
  minuto (`api/.env:89`), `5` en test (`api/.env.test:18`). Luego la enumeración de UUIDv7 está acotada hoy a
  120/min/IP; lo que falta es un límite **por identidad y por ruta**, que es otra cosa. El residual se
  reescribe con esa distinción en AC 22.

- **A-8 (SERIO) — la AC de `#[MapUploadedFile]` pide un test de integración de "la vía real de entrada" para
  una vía que esta rebanada no construye.** `epics-images.md:833-840` exige *"un test de integración
  independiente que reproduce la resolución real del argumento"*, y la propia AC dice que el guard en aislado
  *"no es evidencia suficiente sin este segundo test"*. Medido: `#[MapUploadedFile]` no aparece en `api/src` en
  absoluto — su única ocurrencia en el árbol es una cadena de fixture dentro de
  `tests/Unit/Gate/StrictRequestPayloadGateTest.php:154` —, y esta historia no entrega endpoint de subida. Sin
  resolver esto, la AC es insatisfacible o se degrada a un unit test disfrazado. **El precedente para
  resolverlo ya existe y es exacto**: `api/config/routes/test.yaml` monta bajo `when@test` diecisiete
  controladores desechables en `/api/test/_throw-*` cuyas clases viven en
  `api/tests/Functional/.../Fixtures/`, *"so the ExceptionResponder listener is exercised through a real
  Symfony kernel"*. Incorporado como Task 10 con ese mecanismo.

- **A-9 (SERIO) — la AC de observabilidad pide un tercer `failure_category` que el enum no tiene.**
  `epics-images.md:782-786` exige distinguir `404`, `5xx` e **"integridad"** como `failure_category` distintos.
  `StorageFailureCategory` tiene hoy tres casos —`storage_confirmed_absence`, `storage_transient_failure`,
  `storage_permanent_failure`— y ninguno es integridad; `StorageOperation::VerifyIntegrity` sí existe, pero es
  el eje `operation`, no el eje `failure_category`, y hoy sólo lo usa `store()`. Añadir un caso al enum es
  correcto y hay que hacerlo respetando su invariante documentado: **el valor tiene que seguir siendo disjunto**
  de todo valor de `Shared\Images\Domain\Exception\FailureCategory`, para que `failure_category` siga siendo un
  universo cerrado por unión. Incorporado como AC 8 y Task 5.

- **A-10 (SERIO) — no existe marcador para el fallo PERMANENTE, y mapearlo a `ServiceUnavailable` entrena al
  cliente a reintentar lo irreintentable.** El mapa completo es `NotFound`→404, `Conflict`→409, `Forbidden`→403,
  `Unauthenticated`→401, `InvariantViolation`→422, `InvalidInput`→400, `RateLimited`→429,
  `InvalidSearchCriteria`→422, `ServiceUnavailable`→503 (`ProblemDetailsFactory::MARKER_STATUS_MAP`). No hay
  marcador de "error permanente del servidor". `ImageStorageFailed` cubre `ENOSPC`, `EACCES`, raíz ausente o
  no atravesable e identificador ya ocupado; img-1-2 dejó escrito que *"la Story 1.3 no debe mapear [eso] al
  mismo 5xx reintentable que un I/O temporal"*. **La salida correcta es no minar marcador**: sin marcador, la
  excepción sale `500 unhandled-exception` por el mismo pipeline RFC 9457 y llega a Sentry, que es donde un
  fallo permanente de substrato pertenece — es un problema de operador, no del cliente, y el cliente no debe
  reintentarlo. Cerrado así en AC 6, con el argumento escrito, en vez de dejarlo a que el implementador
  descubra el mapa y elija `ServiceUnavailable` por proximidad.

- **A-11 (MENOR) — la mitad del controlador retirado que NO se rescata.** `StoredObjectGetController` devuelve
  `new Response('Not Found', Response::HTTP_NOT_FOUND)`: un cuerpo de texto plano que se salta el pipeline
  RFC 9457 entero. Hoy eso es exactamente lo que `make php.lint.error-contract` existe para refusar. Se lanza
  una excepción con marcador y se deja al `ExceptionResponder` construir el cuerpo. Dicho en Task 6 para que el
  rescate no lo arrastre.

- **A-12 (MENOR) — el `requirements` del controlador retirado, aplicado al `{imageId}`, convertiría el 400 en un
  404 de router.** El retirado declara `requirements: ['hash' => '[a-f0-9]{64}']`. Un `requirements` de UUID
  sobre `{imageId}` haría que un id malformado **no llegue al controlador**: el router respondería 404 y se
  conflacionaría con "no existe", que es justo la distinción que la AC de `Uuid::ensure()` → 400 quiere
  preservar. Incorporado como AC 21.

- **A-13 (MENOR) — Behat se quedó sin dueño en toda la épica, y el contexto que parece servir es una trampa.**
  El épico promete *"unit + integration + Behat contra el propio seam"* (`epics-images.md:487`); 1.1 cerró sin
  ninguna feature, 1.2 la excluyó explícitamente, y ninguna AC del corte de 1.3 la nombra — la review de
  img-1-2 lo refundió aquí en vez de a `deferred-work.md`. Además: `api/tests/Behat/Context/Json/JsonErrorContext.php`
  se documenta como *"Validates RFC 9457 Problem Details error payloads"*, pero sus siete steps están
  clasificados `idle` y afirman un envelope **legacy** `{errors:[…], meta:{requestId}}`, no el `type`/`title`/`status`
  plano que el contrato emite hoy. Un implementador que lo busque por el nombre escribe una feature que no
  prueba nada. Incorporado como AC 23 y Task 11, con la advertencia.

- **A-14 (MENOR) — `docs/architecture-api.md:102` ya es falso hoy, antes de esta historia.** Afirma
  *"Attribute-only routing (`#[Route]`) on controllers under each bounded context's `Infrastructure/Controller/`"*,
  y hay **seis** controladores con `#[Route]` bajo `Iam/*/Infrastructure/Http/`
  (`CompletePasswordResetController`, `LoginController`, `RequestPasswordResetController`,
  `AcceptInvitationController`, `CreateInvitationController`, `RevokeUserInvitationController`). Esta historia
  lo corrige por regla del boy-scout, y **no puede presentarlo como algo que rompa ella**.

## Acceptance Criteria

1. **Given** una petición **no autenticada** sobre un `ImageId` existente, **When** se invoca
   `GET /api/v1/images/{imageId}`, **Then** la API deniega en la frontera de autenticación antes de resolver
   ningún dato **And** el orden es siempre **auth → validación de formato del `ImageId` → lookup en repositorio
   → 404 si no existe** **And** un no autenticado recibe **el mismo cuerpo y el mismo status** tanto si el
   `ImageId` es sintácticamente inválido como si es válido pero inexistente como si existe — nunca se filtra
   esa distinción a quien no está autenticado.

2. **Given** un `ImageId` con formato inválido (no es un UUID), **When** una petición **autenticada** lo usa,
   **Then** se rechaza con `400` `invalid-uuid` vía `Uuid::ensure()` **antes** de cualquier lookup en
   repositorio y **antes** de tocar `ImageStorage`.

3. **Given** un `ImageId` sintácticamente válido pero sin fila `Image`, **When** una petición autenticada lo
   solicita, **Then** responde `404` por el pipeline RFC 9457 — nunca `500`, nunca un cuerpo que revele estado
   interno del módulo, y nunca un cuerpo que no sea Problem Details.

4. **Given** una petición autenticada sobre un `ImageId` existente y recuperable, **When** se invoca la ruta,
   **Then** la respuesta lleva los **bytes canónicos** con `Content-Type`, `Content-Length` y
   `X-Content-Type-Options: nosniff` **And** el `Content-Type` es siempre `Image::mediaType()` — el mediaType
   canónico registrado en la fila —, **nunca** inferido de los bytes en el momento de servir, ni de una
   cabecera de la petición, ni de la extensión de nada.

5. **Given** una fila `Image` que existe pero cuyos bytes ya no son recuperables, **When** el adaptador
   confirma la ausencia (`ImageBytesNotFound`), **Then** la ruta responde `404` — el mismo `404` que la fila
   ausente, porque desde fuera son el mismo hecho: la imagen no es servible.

6. **Given** un fallo de storage al leer, **When** se resuelve la respuesta, **Then** los tres veredictos del
   puerto se mapean a **tres resultados distintos y ninguno se conflaciona**: `ImageBytesNotFound`
   (`ConfirmedAbsence`) → `404`; `ImageStorageUnavailable` (`Transient`) → `503` vía el marcador
   `ServiceUnavailable`; `ImageStorageFailed` (`Permanent`) → **`500` `unhandled-exception`, deliberadamente
   sin marcador**, porque `ENOSPC`, `EACCES`, la raíz ausente o no atravesable son problemas de operador que
   ningún reintento del cliente resuelve, y `503` le diría lo contrario **And** el `500` llega a Sentry por el
   camino normal del contrato de error, que es donde pertenece.

7. **Given** una lectura de storage que podría fallar a mitad de camino, **When** se sirve la respuesta,
   **Then** la implementación completa una lectura **verificada** — bytes íntegros y
   `SHA-256(bytes) === Image::digest()` — **antes** de comprometer status o cabeceras **And** un desajuste de
   digest **nunca** se sirve como cuerpo: es un fallo, no una respuesta degradada **And** no se abre ningún
   camino de streaming en esta historia (el puerto devuelve la cadena completa, así que la propiedad es
   satisfacible por construcción y no hay que inventar un buffer).

8. **Given** un fallo de lectura o un desajuste de digest, **When** se emite la señal de observabilidad,
   **Then** distingue `404` (ausencia confirmada), `5xx` (transitorio y permanente, ya distinguidos entre sí
   por el enum) e **integridad** como `failure_category` distintos **And** el caso de integridad es un caso
   **nuevo** de `StorageFailureCategory` cuyo valor sigue siendo **disjunto** de todo valor de
   `Shared\Images\Domain\Exception\FailureCategory` **And** la señal no incluye `ImageId`, `digest`, la storage
   key ni bytes, **afirmado sobre los VALORES serializados del contexto buscando la subcadena, nunca sobre los
   nombres de las claves**.

9. **Given** que a partir de esta historia la ausencia confirmada pasa a ser un productor de log **disparable
   por el cliente**, **When** `read()` reporta `ConfirmedAbsence`, **Then** ese registro está **acotado** por
   el mecanismo que Task 0 decida (muestreo o contador agregado) **And** el mecanismo **no** es bajar el nivel
   a `debug`, que `ObservabilityChannelGateTest:42,85` descarta en todos los entornos **And** la ausencia en
   `delete()` conserva su nivel `info` actual, porque allí el volumen lo acota el trabajo real **And** existe
   un test que falla si la cota desaparece.

10. **Given** una petición que incluye la cabecera `Range`, **When** se resuelve la respuesta, **Then** la ruta
    **ignora** `Range` y devuelve siempre el cuerpo completo con `200` — nunca `206`, nunca `416` **And** no se
    emite `Accept-Ranges`, porque anunciar una capacidad que no se implementa es peor que no anunciarla.

11. **Given** una respuesta exitosa, **When** se construyen sus cabeceras de cache, **Then** incluye
    `Cache-Control: private, max-age=31536000, immutable` junto al `ETag` — **`private`** porque la ruta exige
    autenticación y una caché compartida no debe servir la respuesta entre usuarios distintos, e `immutable`
    porque esta rebanada no expone ninguna operación que reemplace los bytes de un `ImageId` ya creado
    **And** existe un test que falla si la directiva vuelve a `public` (que es lo que emite el helper
    rescatable tal cual).

12. **Given** la implementación del controlador, **When** resuelve la respuesta, **Then** la cadena es
    `ImageId → ImageRepository → ImageStorage` **And** en ningún punto se construye ni se acepta un path de
    filesystem desde la petición **And** ninguna firma del camino acepta una storage key.

13. **Given** una respuesta exitosa, **When** se construye, **Then** el `ETag` deriva del **`digest`**, nunca
    del `ImageId` **And** es un ETag **fuerte** **And** una petición posterior con `If-None-Match` que coincide
    responde `304` **únicamente si el objeto sigue siendo recuperable en storage** — nunca un `304` optimista
    sobre un objeto ya ausente **And** la puerta de recuperabilidad es la **misma lectura verificada de la
    AC 7**, no un predicado de existencia nuevo en el puerto: el coste declarado y aceptado es que un `304`
    cuesta la misma E/S de storage que un `200` y sólo ahorra el cuerpo en el cable **And** el emparejamiento
    de `If-None-Match` acepta las tres formas válidas (fuerte `"h"`, débil `W/"h"`, sin comillas `h`) y `*`.

14. **Given** que el almacenamiento subyacente pudiera devolver un stream ya posicionado en EOF (gotcha
    heredado, `epics-images.md:371-373`), **When** se sirven los bytes, **Then** la implementación no confía
    nunca en la posición de un stream recibido **And** si en algún punto se maneja un stream, se lee
    explícitamente desde el offset 0 — un `stream_get_contents` desde EOF devuelve `''`, no `false`, y esa
    cadena vacía sería servible y cacheable como cuerpo.

15. **Given** cualquier variante de la petición, con o sin parámetros adicionales, **When** no hay sesión
    autenticada, **Then** el conocimiento del `ImageId` por sí solo nunca concede acceso a los bytes (test de
    regresión explícito, no derivado de la AC 1).

16. **Given** un upload HTTP que use `#[MapUploadedFile]`, **When** Symfony resuelve el argumento, **Then**
    existe un test de guard **en aislado** que reconoce y rechaza objetos construibles desde path **And**
    existe un test de **integración independiente** que reproduce la resolución real del argumento a través de
    un kernel real y demuestra que un path de filesystem arbitrario no puede materializarse como origen de la
    subida **And** ese segundo test se monta sobre una ruta `when@test` desechable, porque esta rebanada no
    entrega endpoint de subida de producción — el guard en aislado no es evidencia suficiente sin él.

17. **Given** el árbol de `Application/` del módulo, **When** se ejecuta el scan de arquitectura de NFR6,
    **Then** falla si aparece un tipo de transporte (`UploadedFile`, `File`, `SplFileInfo`, `SplFileObject`,
    `Psr\Http\Message\UploadedFileInterface`, `Psr\Http\Message\StreamInterface` o sucesor) en cualquier firma
    pública **And** falla si aparece un parámetro de valor elegido por el caller (path/filename/URL/storage
    key) en cualquier firma pública **And** un test de regresión falla si el scan deja de matchear tras un
    rename dentro del árbol **And** el scan es kernel-free y no acredita cobertura de producción
    (`#[CoversNothing]`), o el registro de colocación de gates no lo verá.

18. **Given** el montaje de la ruta, **When** se declara, **Then** existe un recurso de routing que carga
    `src/Shared/Images/` **And** la ruta resuelve bajo `/api/v1`, de modo que la catch-all
    `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }` (`security.yaml:74`) la cubre sin ninguna línea nueva
    de `access_control` **And** no se añade ninguna entrada a `api/.public-access-exemptions` **And** el
    controlador **no** lleva `#[IsGranted]`, porque el firewall es la frontera completa de esta rebanada y un
    voter violaría el decision firewall.

19. **Given** la decisión del épico de escribir **cero** filas en `audit_log`, **When** la ruta responde `200`,
    **Then** no se escribe ninguna fila **And** eso se consigue con un nombre de ruta que empiece por
    `shared_` — la única exclusión de `AuditPolicy::lacksBusinessSemantics()` (`AuditPolicy.php:60-68`) que
    aplica a esta ruta — **And** existe un caso en el data provider de casos no auditables de `AuditPolicyTest`
    que ejercita esa rama, que hoy no ejercita nadie **And** existe una prueba de extremo a extremo que afirma
    que una lectura con éxito deja `audit_log` sin filas nuevas, porque el nombre de la ruta es un acoplamiento
    invisible que ningún gate vigila.

20. **Given** la misma decisión, **When** se declara la ruta, **Then** **no** declara `_audit_resource_type` ni
    `_audit_canonical` en sus `defaults` — declarar el primero metería `Image` en el universo de
    `api/.audit-resource-types` y forzaría la clasificación persona/no-persona que el épico difiere
    explícitamente al primer consumidor real.

21. **Given** la declaración de la ruta, **When** se define su parámetro, **Then** `{imageId}` **no** lleva un
    `requirements` que restrinja su forma — un id malformado tiene que **llegar al controlador** para producir
    el `400` de la AC 2; con `requirements` el router respondería `404` y conflacionaría malformado con
    ausente.

22. **Given** el riesgo residual de enumeración, **When** se documenta, **Then** queda escrito con la medición
    en la mano y no como una carencia total: `ImageId` es UUIDv7 (time-ordered, más barato de enumerar en una
    ventana temporal que un UUIDv4), cualquier sesión autenticada puede leer cualquier imagen, y **existe hoy**
    un limitador global `anonymous_api` de 120 req/min por IP que se aplica a toda petición `/api/*` sin mirar
    la autenticación (`RateLimitListener.php:113-131`, `api/.env:89`) **And** lo que falta —y queda para la
    épica del consumidor— es un límite **por identidad y por ruta**, no "rate limiting" **And** `ImageId` no se
    considera nunca un mecanismo de autorización ni un secreto **And** el residual se añade al bloque de
    `Shared/Images` de `PRODUCTION_SECURITY_CHECKLIST.md` §7 (hoy en `:1534`, *"the four things it does not"*),
    ampliándolo en vez de abrir un bullet paralelo.

23. **Given** que la épica prometió Behat contra el propio seam (`epics-images.md:487`) y ninguna historia lo
    entregó, **When** se cierra esta historia, **Then** existe al menos una feature en
    `api/features/shared/images/` que ejercita la ruta de extremo a extremo por el kernel real **And** cubre el
    `401` anónimo, el `400` malformado, el `404` ausente, el `200` con sus cabeceras y el `304` condicional
    **And** todo patrón de step nuevo queda clasificado en `api/.behat-step-vocabulary` en el **mismo commit**,
    y todo patrón `idle` que la feature alcance **cambia a `used`** en ese mismo commit.

24. **Given** los tres límites de recursos que 1.1 fijó sin validar (`max_input_bytes` 20 MB,
    `max_decoded_pixels` 40 MP, `max_input_dimension` 10000 px) y la dependencia transitiva
    `intervention/gif`, **When** se cierra esta historia, **Then** los límites quedan **medidos** contra el
    worker real con su resultado escrito (memoria pico y tiempo por formato), no ajustados a ojo **And** el
    vetting de `intervention/gif` queda registrado **And** si la medición no cambia ningún valor, eso se dice
    explícitamente — un "no hizo falta cambiarlos" medido es un resultado, un silencio no.

25. **Given** la ausencia de un consumidor real, **When** se documenta la ruta, **Then** queda explícito que es
    una **prueba de infraestructura** —bytes recuperables a través de la frontera de `Images`— y no una API de
    producto lista para exponerse; no establece ownership ni autorización semántica de ningún consumidor.

26. **Given** el defecto que el code review de img-1-2 encontró por el lado del borrado (la storage key se
    derivaba de la **grafía** del `ImageId` mientras la fila se selecciona por su **valor**), **When** se lee
    por la ruta, **Then** existe un test de regresión que afirma que
    `GET /api/v1/images/{ID-EN-MAYÚSCULAS}` devuelve **exactamente los mismos bytes y el mismo `ETag`** que la
    forma en minúsculas — la normalización vive en `ImageId::__construct` (`ImageId.php:34`) y esta AC prueba
    que la ruta la hereda en vez de esquivarla con un `$request->attributes->get()` crudo.

## Tasks / Subtasks

### Task 0 — GATE: decidir la cota del log de ausencia (AC 9). Ninguna implementación empieza antes.

Esta es la decisión abierta de la historia y es de Sergio, como lo fue la forma de persistencia de `Image` en
1.2. El épico ofrece tres cotas y **una es letra muerta** (A-2). Quedan dos, y tienen costes distintos:

- **Opción A — muestreo.** Emitir la línea 1 de cada N ausencias (N configurable por parámetro
  `erpify.images.read_miss_sample_rate`). *Coste*: la señal deja de ser un conteo exacto, así que un
  despliegue no puede responder "cuántas lecturas fallaron" leyendo el log; hay que multiplicar. *Beneficio*:
  conserva la forma actual de la línea, el nivel `info` y el gate de portadores intactos, y el diagnóstico
  cualitativo (que hay misses, de qué operación) sobrevive.
- **Opción B — contador agregado.** No emitir por ausencia; acumular en proceso y emitir un resumen periódico
  o al final de la petición. *Coste*: es infraestructura nueva en un módulo que hoy no tiene ninguna, y el
  épico prohíbe expresamente crear infraestructura de métricas (NFR9: *"No se exige aquí la infraestructura de
  métricas"*). Un contador por petición HTTP degenera en una línea por petición, que es la cota que se quería
  evitar. Un contador por proceso necesita un punto de flush que FrankenPHP en modo worker no ofrece
  gratis. *Beneficio*: conteo exacto.

**Recomendación argumentada: opción A.** El principio en juego es YAGNI más "la cota más barata que resuelve
el problema": el problema es el volumen desalojando log retenido, no la fidelidad del conteo, y el muestreo
ataca exactamente el volumen sin introducir un subsistema. La opción B compra exactitud que nadie ha pedido a
cambio de un mecanismo de flush que el runtime no regala.

**Antes de decidir, mide (no lo afirmes):** serializa una línea real de ausencia con el
`monolog.formatter.json` de prod, cuenta sus bytes, y divide 50 MB (`json-file`, `max-size: 10m` ×
`max-file: 5`) entre ese tamaño. Eso da el número exacto de peticiones necesarias para desalojar el log
retenido, y a 120 req/min por IP (AC 22) da su duración desde una sola IP. **Ese número es lo que decide un N
proporcionado**, y sin él cualquier N es un número inventado. Escribe la medición en las Completion Notes.

- [ ] Medir el tamaño de la línea JSON y el umbral de desalojo; escribirlo.
- [ ] Presentar A/B a Sergio con esa medición; registrar la decisión y su argumento aquí.
- [ ] No empezar Task 5 hasta que esté decidida.

### Task 1 — Montar el módulo en el router (AC 18, AC 21)

- [ ] Añadir a `api/config/routes.yaml` un recurso de atributos que cargue el directorio del controlador, con
      `prefix: /api/v1` y `defaults: {_format: json}`. Copia la forma de `api_v1_iam_session`, que es la más
      cercana (recurso acotado a un directorio, no a un contexto entero) — **acótalo al directorio del
      controlador, no a `../src/Shared/`**, para que montar el router sobre el shared kernel no exponga
      inadvertidamente cualquier futuro `#[Route]` de otro módulo compartido.
- [ ] `#[Route('/images/{imageId}', name: '<ver Task 2>', methods: ['GET'])]` — **sin `requirements`**
      (AC 21), **sin `_audit_resource_type`**, **sin `_audit_canonical`** (AC 20), **sin `#[IsGranted]`**
      (AC 18).
- [ ] Verificar el montaje real: `make sf c='debug:router' | grep images` y `make php.lint.yaml`. Un
      `#[Route]` sin recurso que lo cargue no aparece ahí, y ese es el fallo silencioso de A-3.
- [ ] Falsificar: borra el recurso de `routes.yaml` y comprueba que la feature Behat se pone **roja**. Si
      sigue verde, la feature no está probando la ruta.

### Task 2 — Fijar el nombre de la ruta y pinchar la rama de auditoría (AC 19, AC 20)

- [ ] Nombre `shared_image_get`. **Es un requisito, no un estilo**: es la única forma de que
      `AuditPolicy::lacksBusinessSemantics()` (`AuditPolicy.php:60-68`) excluya la ruta y la decisión de "cero
      filas de `audit_log`" sea cierta. La gramática de nombres del repo es `<contexto>_<módulo>_<acción>` y
      `shared` es el contexto aquí; el fósil `shared_stored_object_get` del controlador retirado confirma que
      la exclusión se escribió para esta familia.
- [ ] Añadir un caso al data provider de rutas no auditables de
      `api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php` (hoy `:82-87`, seis casos, ninguno `shared_`).
      Falsifica borrando la línea 66 de `AuditPolicy.php` y comprobando que el nuevo caso se pone rojo — hoy
      borrarla deja todo verde.
- [ ] Prueba de extremo a extremo (Behat o funcional): una lectura con `200` deja `audit_log` **sin filas
      nuevas**. Cuenta antes y después; afirma la siembra antes que la ausencia (una tabla que ya estaba vacía
      no prueba nada).
- [ ] Documentar en `api/docs/adding-endpoints.md` que el nombre de ruta gobierna la auditoría genérica. Hoy
      esa tabla no lo dice y es exactamente el conocimiento que se pierde.

### Task 3 — El caso de uso de lectura en `Application/` (AC 4, 5, 6, 7, 12)

- [ ] Clase nueva en `api/src/Shared/Images/Application/`. Por `docs/rules/cqrs-naming.md` (*"read use case →
      `Application/<Noun>{Finder|Searcher}`"*) el nombre es un `Finder`; propuesta `CanonicalImageFinder` con
      `find(ImageId $id): <resultado>`. **No** metas el controlador a hablar con `ImageRepository` y
      `ImageStorage` directamente: además de saltarse la capa, dejaría el scan de NFR6 (Task 9) sin superficie
      que vigilar.
- [ ] Orquestación exacta: `findById()` → si `null`, ausencia (404) → `ImageStorage::read()` → **verificar el
      digest contra `Image::digest()`** → devolver. El repositorio ya garantiza que `null` significa fila
      **confirmadamente ausente** y que un fallo de BD lanza, así que no hace falta distinguirlo aquí.
- [ ] **Reutilización a evaluar, no a asumir**: `Domain/CanonicalImage` ya deriva `digest` y `byteSize` de los
      bytes en su constructor, así que construirlo con los bytes leídos y comparar su `digest` con
      `Image::digest()` **es** la verificación de integridad sin escribir un VO nuevo. Antes de adoptarlo,
      **lee sus guardas de constructor** y comprueba que no rechazan nada legítimo en el camino de lectura; si
      lo hacen, escribe el resultado propio y dilo. No fuerces el reuso si no encaja.
- [ ] La firma pública no acepta ni devuelve path, URL, storage key ni tipo de transporte (lo va a comprobar
      Task 9).

### Task 4 — El mapeo de fallo a status (AC 3, 5, 6)

- [ ] Tabla cerrada, y **cada rama tiene que ser alcanzable en un test**:
      - fila ausente (`findById() === null`) → `404`
      - `ImageBytesNotFound` → `404`
      - desajuste de digest → **no** es un `404`: es un fallo de integridad (ver AC 7 y Task 5)
      - `ImageStorageUnavailable` → `503` vía marcador `ServiceUnavailable`
      - `ImageStorageFailed` → `500` `unhandled-exception`, **sin marcador, deliberadamente** (A-10)
- [ ] Las excepciones nuevas del módulo que necesiten marcador van en `Shared/Images/Domain/Exception/` e
      implementan el marcador de `Shared/ErrorContract`. **Ojo con el punto ciego**: `ErrorContractGateTest`
      sólo barre `api/src/Shared/ErrorContract/Domain/Exception/`, así que una excepción tuya con marcador y
      `type` propio **pasa el gate en silencio** — la actualización de `docs/api-error-contract.md` es una
      obligación manual de NFR26, no algo que se ponga rojo.
- [ ] Captura por **especificidad decreciente**; nunca `catch (\Throwable)`; nunca capturar primero la interfaz
      `ImageStorageException` (deja ramas inalcanzables — defecto que el primer pase de img-1-2 introdujo y el
      segundo corrigió).
- [ ] **Ningún `new JsonResponse(...)` ni `new Response('...', 404)` en el controlador** (A-11). Lanza; el
      `ExceptionResponder` construye el cuerpo.
- [ ] El texto de cualquier excepción nueva **no** lleva `ImageId`, digest ni storage key: llega a
      `messenger_messages` vía `ErrorDetailsStamp` y a Sentry, sumideros que ningún erasure alcanza. Misma
      regla que 1.2 aplicó a `store`/`read`/`delete` y al `$previous`.

### Task 5 — Observabilidad: el caso de integridad y la cota (AC 8, AC 9)

- [ ] Añadir el caso de integridad a `StorageFailureCategory`. **Su valor tiene que ser disjunto** de todo
      valor de `Shared\Images\Domain\Exception\FailureCategory`; hay un test que lo afirma
      (`StorageFailureVocabularyTest`) — extiéndelo, no lo esquives.
- [ ] Implementar la cota decidida en Task 0 dentro de `FlysystemImageStorage::emit()` /`report()`
      (`:388-407`, `:374-379`), **conservando la forma que los gates exigen**: dos llamadas por nivel
      (`->info()` / `->warning()`), nunca `log($level, …)`, porque el gate de portadores clasifica por el
      nombre del método y rechaza la forma PSR-3. El `try { … } catch (Throwable) {}` que hace la señal no
      load-bearing se queda.
- [ ] **No mover la señal de canal.** `BestEffortReportChannelGateTest::REPORTERS` ya lista
      `FlysystemImageStorage.php` y lo ata a `monolog.logger.observability`; si añades una clase nueva que
      loguee, entra en `REPORTERS` o `make php.unit` se pone rojo.
- [ ] La ausencia en `delete()` **conserva `info`**: allí el volumen lo acota el trabajo real y img-1-2
      argumentó por qué tiene que ser contable. La cota es sólo del camino `read()`.
- [ ] Test de no-fuga **por valor**: serializa el contexto y busca el `ImageId`, el digest y la key como
      **subcadena**. Un test por nombre de clave pasa sobre `['path' => 'images/ab/cd/01H9…']` — es el defecto
      que el pase adversarial de img-1-2 encontró y el mismo modo de fallo que el filtro `query` de Caddy.
- [ ] Falsifica la cota: quítala y comprueba que el test se pone rojo.

### Task 6 — Cache condicional: rescatar la política, no el helper (AC 11, AC 13)

- [ ] Rescatar **`isNotModified()`** de `git show 08f8199^:api/src/Shared/Http/Infrastructure/ContentAddressedHttpCache.php`
      (43 líneas) y su test (71). Es la política de emparejamiento de `If-None-Match`: `*`, fuerte `"h"`, débil
      `W/"h"` y sin comillas `h`. Es agnóstica del hash y sirve tal cual.
- [ ] **NO rescatar `applyHeaders()`.** Medido: llama `setPublic()` y fija
      `Cache-Control: public, max-age=31536000, immutable`. Esta ruta es autenticada y la AC 11 exige
      `private`. Copiarlo pondría la respuesta de un usuario en cachés compartidas.
- [ ] **Renombrar al integrarlo** (p. ej. `HttpCacheValidator`), porque esta épica no adopta content-addressing:
      el `ETag` deriva del **digest como atributo**, no del `ImageId` y no como storage key. La regla del
      inventario de rescate es *"rescatar comportamiento, no nombres ni modelos mentales"*.
- [ ] `ETag` **fuerte** (`Response::setEtag($digest)` sin `weak: true`). Nota para la feature Behat: el step
      `the header :name should be equal to :value` compara en minúsculas por ambos lados, así que **no puede
      distinguir `W/"abc"` de `w/"ABC"`** — para afirmar la fuerza usa `the header :name should match :regex`.
- [ ] La doble puerta del `304`: **la recuperabilidad se prueba con la lectura verificada, no con un
      `exists()`**. No añadas `exists()` a `ImageStorage` (A-6). Escribe en el código el porqué y el coste.

### Task 7 — Las cabeceras de la respuesta (AC 4, AC 10, AC 14)

- [ ] `Content-Type` = `Image::mediaType()`, el canónico. Nunca `finfo` sobre los bytes servidos, nunca la
      extensión, nunca una cabecera de la petición. 1.2 dejó escrito que es el mediaType canónico el que
      persiste, precisamente para esto.
- [ ] `Content-Length` explícito y `X-Content-Type-Options: nosniff`.
- [ ] `Range` ignorado: cuerpo completo con `200`, y **sin** `Accept-Ranges`.
- [ ] No hay stream en el camino (el puerto devuelve `string`), pero deja la nota del gotcha de EOF en el
      código: si alguien introduce un stream aquí, lee desde offset 0 — `stream_get_contents` desde EOF
      devuelve `''`, no `false`, y esa cadena vacía sería un cuerpo servible y cacheable.
- [ ] Comprueba qué más viaja en la respuesta: `X-Correlation-Id` y las cabeceras `RateLimit-*` las ponen
      listeners que corren también sobre esta ruta. No las pises.

### Task 8 — Regresión de grafía vs valor (AC 26)

- [ ] Test que pide el mismo recurso con el `ImageId` en mayúsculas y afirma **mismos bytes y mismo `ETag`**.
- [ ] Falsifica: quita el `\strtolower()` de `ImageId::__construct` (`ImageId.php:34`) **copiando los bytes de
      vuelta al restaurar, nunca con `git checkout --`** y comprueba que el test se pone rojo. Si no lo hace,
      el test está construyendo el `ImageId` por una vía que no pasa por el constructor.

### Task 9 — El scan de NFR6 y su falsabilidad (AC 17)

- [ ] Gate nuevo bajo `api/tests/Unit/Shared/Images/`, `#[CoversNothing]`, kernel-free, con el motor de reglas
      en `api/tests/Support/` (**nunca** en `api/tests/Unit/Gate/Support/`, que es un trinquete descendente).
- [ ] **Dos ejes.** Tipo: `UploadedFile`, `File`, `SplFileInfo`, `SplFileObject`, `Psr\Http\Message\UploadedFileInterface`,
      `Psr\Http\Message\StreamInterface`. Valor: parámetro cuyo nombre denote path/filename/URL/storage key.
- [ ] **Di en el docblock qué añade sobre deptrac, medido, en vez de implicar que deptrac no ve nada.**
      `Shared.Application` **sí** rechaza `Vendor.Symfony`, así que `UploadedFile` y `File` ya reventarían
      allí, y `SplFileInfo` saldría como dependencia no cubierta bajo `--fail-on-uncovered`. Lo que deptrac
      **no** puede: (a) hablar de `Shared/Images/Application` en particular, porque su collector es
      `src/Shared/(.*/)?Application/.*` y pliega todos los módulos compartidos en una capa; (b) ver el eje
      valor en absoluto, porque `find(ImageId $id, string $path)` no es una dependencia; (c) refusar
      `Psr\Http\Message\*`, porque `Shared.Application` permite `Vendor.Psr` (`^Psr\\.*`) y ahí viven tipos de
      transporte HTTP genuinos. Ese (c) es el hueco más fácil de pasar por alto.
- [ ] Test de falsabilidad: el scan tiene que ponerse **rojo** si el árbol se renombra y deja de matchear. Un
      check basado en paths que silenciosamente no cubre nada tras un rename es el modo de fallo contra el que
      el ADR pide diseñar.
- [ ] Guarda de no-vacuidad: `assertNotSame([], $sources, …)`, como hace `ImageLifecycleListenerGateTest`.
- [ ] Clasificar las clases nuevas en `api/.artifact-gate-placement` como `mirrored :: src/Shared/Images`, en
      orden alfabético dentro del bloque de módulo. El precedente vivo es
      `tests/Unit/Shared/Images/ImageLifecycleListenerGateTest.php :: mirrored :: src/Shared/Images` (`:186`).

### Task 10 — Los dos tests de `#[MapUploadedFile]` (AC 16)

- [ ] Test del guard **en aislado** sobre `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer`
      (existe hoy; ancla en `SplFileInfo`, no en `UploadedFile`, porque el vector es la constructibilidad desde
      un path).
- [ ] Test de **integración de la vía real**, y aquí está el problema que A-8 nombra: `#[MapUploadedFile]` no
      aparece en `api/src` y esta rebanada no entrega endpoint de subida. **Mecanismo**: monta un controlador
      desechable bajo `when@test` en `api/config/routes.yaml`/`api/config/routes/test.yaml`, con la clase
      fixture en `api/tests/Functional/Shared/Images/Fixtures/`. El precedente es exacto y ya está en el árbol:
      `api/config/routes/test.yaml` monta diecisiete controladores en `/api/test/_throw-*` cuyas clases viven en
      `api/tests/Functional/.../Fixtures/`, *"so the ExceptionResponder listener is exercised through a real
      Symfony kernel"*.
- [ ] Ese path cae bajo la regla `- { path: '^/api/test/', roles: PUBLIC_ACCESS }` ya existente, así que no
      hace falta tocar `security.yaml` — **verifícalo** en vez de asumirlo, y si eligieras otro path, la
      entrada correspondiente de `api/.public-access-exemptions` es obligatoria.
- [ ] Di en el test por qué el guard en aislado no basta: la superficie retirada tomaba sus subidas por ese
      atributo, que es un resolver distinto del que el guard cubría originalmente.

### Task 11 — Behat, la deuda de la épica (AC 23)

- [ ] Feature en `api/features/shared/images/` (el árbol es `features/{backoffice,frontoffice,shared}/<agregado>/<caso_de_uso>.feature`,
      snake_case; `features/shared/` ya tiene `audit/`, `console/`, `error_contract/`, `rate_limiting/`).
      **La ubicación es load-bearing**: `BehatSuiteCoverageGateTest` refusa un `.feature` fuera de una raíz
      declarada, y una feature bajo una cuarta raíz quedaría verde en todos los gates porque nadie la parsea —
      llegando incluso a marcar patrones como `used` que ninguna ejecución alcanza.
- [ ] Escenarios: `401` anónimo (`@anonymous`, sobre id malformado **y** id ausente, mismo cuerpo — es el
      `Scenario Outline` que la AC 1 pide), `400` malformado autenticado, `404` ausente, `200` con
      `Content-Type`/`Content-Length`/`nosniff`/`ETag`/`Cache-Control`, y `304` condicional.
- [ ] Modelos a copiar: `api/features/backoffice/bank/access_control.feature` para el bloque anónimo,
      `api/features/backoffice/users/get.feature` para el trío 200/400/404.
- [ ] **Trampa**: `api/tests/Behat/Context/Json/JsonErrorContext.php` se anuncia como validador de RFC 9457
      pero sus siete steps están `idle` y afirman un envelope **legacy**. Los Problem Details se afirman campo
      a campo con `JsonNodeContext`.
- [ ] **Estado por escenario**: `HttpRequestContext::$headers` no se resetea entre `When` dentro de un
      escenario. Si haces "sin `If-None-Match`, luego con, luego sin", usa `And I remove "If-None-Match" header`
      (existe y está `used`).
- [ ] **Cita las cabeceras entre comillas en la feature**: el token de placeholder del gate de vocabulario es
      más ancho que el de Behat, así que un `If-None-Match` sin comillas **matchea en el gate y queda
      indefinido en Behat**. Es la única dirección en la que ese gate falla abierto, y sólo `--strict` la
      caza.
- [ ] Para el `304` hace falta reinyectar el `ETag` devuelto como `If-None-Match`. **Decisión explícita, no la
      dejes al implementador**: (a) escribir un step nuevo del tipo
      `I add :name header equal to the response header :header` —no existe ninguno que capture una cabecera de
      respuesta para replay, aunque sí existe el análogo para nodos JSON, clasificado `idle`— y clasificarlo
      `used` en `api/.behat-step-vocabulary` en el mismo commit; o (b) fijar el digest esperado como literal en
      la feature, viable porque la canonicalización es determinista sobre una fixture. (a) es más caro y
      reutilizable; (b) es más barato y acopla la feature al contenido de la fixture. Elige y di por qué.
- [ ] Todo patrón `idle` que la feature alcance **cambia a `used`** en el mismo commit, o
      `make php.lint.step-vocabulary` se pone rojo. Y si añades un contexto nuevo, regístralo en
      `api/behat.dist.php` o todos sus steps leerán `idle`.
- [ ] Correr `make php.gherkin` y `make php.behat c='features/shared/images/<fichero>.feature'`.

### Task 12 — Benchmark de límites y vetting de `intervention/gif` (AC 24)

- [ ] Medir los tres límites de `api/config/services.yaml` (`max_input_bytes` 20 MB, `max_decoded_pixels`
      40 MP, `max_input_dimension` 10000) contra el worker real: memoria pico por formato y tiempo de
      procesado. Usa `memory_get_peak_usage`, **no** `docker stats` — el muestreo de `docker stats` pierde
      picos.
- [ ] Registrar el vetting de `intervention/gif` (5.0.1): decodifica bytes GIF no confiables y entró como
      dependencia transitiva sin mención en la sección de seguridad de la PR de 1.1.
- [ ] Escribir el resultado aunque no cambie nada. Un "medido, sin cambios" es un resultado; un silencio no.

### Task 13 — Documentación y residuales (AC 22, AC 25, y las obligaciones de `CLAUDE.md`)

- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` §7: **ampliar** el bloque de `Shared/Images` que hoy vive en `:1534`
      (*"the four things it does not"*) con los residuales de la lectura — enumeración con la medición del
      limitador (AC 22), la cota del log y lo que no cierra, y la ausencia deliberada de auditoría de lectura.
      No abras un bullet paralelo: el bloque existe precisamente porque cada residual es invisible desde los
      otros.
- [ ] `docs/architecture-api.md`: bullet de la ruta nueva en `## API design`, junto a los de `/api/v1/me*` y
      health — el argumento de `/api/v1/me*` (*"Neither carries `#[IsGranted]`: … the firewall's `^/api`
      `IS_AUTHENTICATED_FULLY` rule … are the whole authorization story"*) es literalmente el de esta ruta.
      Añadir la ruta al bullet de `Images/` en el árbol (`:64`). Y **corregir `:102`**, que afirma que los
      controladores con `#[Route]` viven bajo `Infrastructure/Controller/` cuando hay seis bajo
      `Iam/*/Infrastructure/Http/` — es falso hoy, antes de esta historia (A-14).
- [ ] `api/docs/adding-endpoints.md`: la convención de nombre de ruta para un módulo de `Shared` (Task 2), que
      hoy no contempla `Shared` como "office".
- [ ] `api/docs/postman/erpify-api.postman_collection.json` + su `README.md`: el README obliga a actualizar la
      colección en la misma PR que añade endpoints, re-derivando la lista con `make sf.routes f='api'`. Hoy la
      colección sólo tiene carpetas `Backoffice` y `Frontoffice`.
- [ ] `docs/api-error-contract.md`: **sólo si** minas un `type` nuevo. La tabla marcador→status **no** gana
      fila (no hay marcador nuevo). Recuerda que el gate no vigila esta dirección (Task 4).
- [ ] `_bmad-output/implementation-artifacts/deferred-work.md`: la bala *"(Shared/Images — modelado) Si un
      `Image` es mutable o inmutable no está decidido"* queda **rozada** por la AC 11, que justifica
      `immutable` con *"esta rebanada no expone ninguna operación que reemplace los bytes"*. **Decide**: o la
      resuelves y **borras la bala** (el registro es pending-only), o dejas escrito en la historia que
      `immutable` está acotado a esta rebanada y que la pregunta de la URL de variantes sigue abierta — en cuyo
      caso la bala se queda intacta. La segunda bala del bloque (borde transaccional del consumidor) **no** es
      de esta historia; no la toques.
- [ ] Escribir en el código y en la doc que la ruta es una **prueba de infraestructura**, no una API de
      producto (AC 25).

### Task 14 — Barrido y cierre

- [ ] Regla del boy-scout sobre los ficheros que toques: fuera IDs de historia/requisito (`Story 1.x`, `NFR…`,
      números de AC) y fuera comentarios relativos al cambio. img-1-2 encontró 23 apariciones en
      `api/src/Shared/Images/` y 12 en sus tests; barre las que queden en lo que edites.
- [ ] `make php.stan` sobre cada fichero PHP cambiado, según avanzas.
- [ ] Al final, y con el código de salida impreso: `make php.quality` · `make php.unit` · `make php.behat` ·
      `make pwa.quality` (sí, la PWA: `pwa/tests/client-minted-problem-types.test.ts` barre `api/src` buscando
      los tres nombres que sólo el cliente puede minar —`network-error`, `request-timeout`,
      `malformed-response-envelope`— y un `type` nuevo que colisione **rompe ahí, no en `php.quality`**).
- [ ] **Ojo con PHPMD**: el techo es coupling-between-objects 13 y 10 métodos públicos por clase de test. Un
      controlador con traducción de errores, cache condicional y cabeceras va derecho a ese techo, y las clases
      de test de 1.1 llegaron a 20 métodos. Cuenta con partir por concern desde el principio en vez de
      refactorizar al final.
- [ ] `make php.lint.gate-placement`, `make php.lint.step-vocabulary`, `make php.gherkin` y `make php.deptrac`
      son los que más probablemente muerdan; están dentro de `php.quality`, pero córrelos antes para no
      descubrirlos al final.

## Dev Notes

### La ruta: dónde monta, cómo se llama, y por qué el nombre es un requisito

Path final `/api/v1/images/{imageId}`, nombre `shared_image_get`. Las tres decisiones están acopladas y ninguna
es cosmética:

- **El prefijo `/api/v1`** es lo que mete la ruta bajo `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }`
  (`security.yaml:74`). El `GET /images/{imageId}` que escribe el épico es, literalmente, una ruta anónima —
  y `make php.lint.public-access` no lo vería, porque ese gate lee `security.yaml`, no el router.
- **El recurso de routing** no existe: ningún `resource:` de `api/config/routes.yaml` cubre `src/Shared/`.
  Hasta que lo añadas, el `#[Route]` es decoración.
- **El nombre `shared_`** es lo que hace verdadera la decisión de cero auditoría. `AuditPolicy` audita por
  defecto y exime por excepción; la excepción que aplica aquí es `str_starts_with($route, 'shared_')`
  (`AuditPolicy.php:66`). Hoy ninguna ruta la usa y ningún test la pincha, así que es una rama viva sólo en
  intención — y el controlador retirado que la motivó se llamaba `shared_stored_object_get`.

**Directorio del controlador: decisión abierta y menor, resuélvela y dilo.** El árbol tiene las dos formas:
`Backoffice/*/Infrastructure/Controller/` y `Iam/*/Infrastructure/Http/`, ambas con `#[Route]`.
`docs/architecture-api.md:102` dice `Infrastructure/Controller/` y ya es falso. Recomendación:
`Shared/Images/Infrastructure/Http/`, porque en este módulo no hay Resource DTO ni mapper (el cuerpo es
binario, no JSON) y `Http/` describe mejor "el adaptador HTTP del módulo" que `Controller/`. Lo que no vale es
elegir en silencio: la elección cambia el `resource:` de `routes.yaml` y la frase de `architecture-api.md`.

### Estado actual medido del módulo (`202767ab`)

28 ficheros bajo `api/src/Shared/Images/`. Lo que esta historia consume, con su firma exacta:

| Pieza | Firma / hecho | Nota para 1.3 |
|---|---|---|
| `Domain/ImageId` | ctor **privado** que hace `\strtolower($value)` (`:34`); `fromString()` llama `Uuid::ensure()` y lanza `InvalidUuidException` | El 400 de la AC 2 sale de aquí; la normalización de la AC 26 también |
| `Domain/Entity/Image` | `final readonly`, `#[ORM\Entity] #[ORM\Table(name:'image')]`, 7 campos; getters `id()`, `digest()`, `mediaType()`, `width()`, `height()`, `byteSize()`, `createdAt()` | `digest()` es el `ETag`; `mediaType()` es el `Content-Type` |
| `Domain/Repository/ImageRepository` | `findById(ImageId): ?Image` — `null` es **ausencia confirmada**, un fallo de BD lanza | El 404 de la AC 3 |
| `Domain/Storage/ImageStorage` | `store(ImageId, string): void` · `read(ImageId): string` · `delete(ImageId): void` | **Tres métodos, cerrado.** No añadas `exists()` |
| `read()` | `@throws ImageBytesNotFound \| ImageStorageFailed \| ImageStorageUnavailable`; devuelve la **cadena completa**; **no** verifica digest | La verificación de la AC 7 es tuya; el retorno completo es lo que la hace satisfacible sin streaming |
| `StorageFailureCategory` | `ConfirmedAbsence` / `Transient` / `Permanent`, con `isOutcome()` | Le falta el caso de integridad (AC 8) |
| `StorageOperation` | `Store` / `Read` / `Delete` / `VerifyIntegrity` | El eje `operation` ya cubre la lectura |
| `FlysystemImageStorage::read()` | `:142` lanza `report(new ImageBytesNotFound())`; `emit()` en `:397` (`info`) y `:402` (`warning`) | El productor de log de la AC 9 |
| Excepciones del módulo | **ninguna** implementa marcador del contrato de error | Hoy todo saldría 500 `unhandled-exception`. AC 6 decide qué cambia y qué no |

Y lo que **no** existe, medido: ningún controlador en todo `api/src/Shared/`; ningún recurso de routing para
`src/Shared/`; ningún caso de uso de lectura (`Application/` tiene exactamente `UploadImage.php` y
`DeleteImage.php`); ninguna feature Behat que mencione imágenes; ningún responder para cuerpo binario (los
cuatro de `Shared/Http/Infrastructure/Responder/` son JSON); y **ningún llamante en producción de
`ImageStorage::read()`** — esta historia es literalmente quien lo despierta, y con él el productor de log.

### El contrato de fallo → status HTTP, tabla cerrada

| Origen | Veredicto | Status | Marcador | Sentry |
|---|---|---|---|---|
| `findById() === null` | fila ausente | `404` | `NotFound` | no |
| `ImageBytesNotFound` | `ConfirmedAbsence` | `404` | `NotFound` | no |
| digest ≠ `Image::digest()` | integridad | `500` | ninguno | **sí** |
| `ImageStorageUnavailable` | `Transient` | `503` | `ServiceUnavailable` | **sí** (`ServiceUnavailable` no extiende `ClientError`) |
| `ImageStorageFailed` | `Permanent` | `500` | **ninguno, a propósito** | **sí** |
| `Uuid::ensure()` falla | — | `400` `invalid-uuid` | `InvalidInput` | no |

Las dos filas que hay que saber defender en review:

- **El fallo permanente no lleva marcador.** `ServiceUnavailable`→503 le dice al cliente "vuelve a intentarlo";
  `ENOSPC` y una raíz de storage ausente no se arreglan reintentando, y img-1-2 dejó escrito explícitamente
  que 1.3 no debe conflacionarlos. Sin marcador la excepción sale `500 unhandled-exception` **por el mismo
  pipeline RFC 9457** —no se salta el contrato, sólo no reclama un status— y llega a Sentry, que es donde un
  fallo de substrato pertenece.
- **La integridad también es 500, no 404.** Un digest que no cuadra significa que el objeto está ahí y está
  mal; decir "no existe" ocultaría una corrupción detectada, que es justo lo que la verificación existe para
  no hacer.

### La cota del log de ausencia — por qué es esta historia y no la anterior

`read()` no tenía llamante en producción hasta ahora. En cuanto exista la ruta, la ausencia confirmada pasa a
ser un productor de línea de log **elegido por el cliente**: el canal `observability` está **fuera** del
`fingers_crossed` de prod (`main` lo excluye por nombre), va siempre encendido a `php://stderr` a `level: info`,
y lo único que lo acota es la rotación por volumen (`json-file`, 10 MB × 5). Es decir, la cota es de **volumen,
no de tiempo**: un despliegue ocioso conserva su línea más vieja para siempre, y N peticiones a ids inexistentes
desalojan lo retenido sin dejar rastro propio — incluidas las líneas de otros subsistemas.

Lo que **no** es correcto decir: que no hay ninguna cota. El limitador `anonymous_api` acota a 120 req/min por
IP toda petición bajo `/api/`, y no mira la autenticación
(`RateLimitListener.php:113-131`, `api/.env:89`). Lo que falta es un límite por identidad.

En img-1-2 el argumento contrario es correcto y se mantiene: un despliegue que responde "ya ausente" a todo
tiene que ser contable. Allí el volumen lo acota el trabajo real; aquí lo acota el cliente. Por eso la cota es
sólo del camino `read()` y `delete()` no se toca.

### ETag, 304 y la doble puerta

El `ETag` deriva del `digest`. Merece una frase, porque el ADR dice que el digest *"se vuelve irreversible el
día que entra en una URL"*: un `ETag` **no** es una URL — no direcciona nada, no es enlazable, no crea
`/{imageId}/{hash}/…` y no reabre la semántica de identidad que RECHAZADO-2 del épico descartó. Es el uso del
digest **como atributo** que el invariante 2 permite explícitamente.

La doble puerta del controlador retirado era `existsAnyWithContentHash($hash) && $storagePort->exists($key)`:
fila **y** blob, antes de conceder el `304`. Aquí la primera mitad es `findById()` y la segunda **no puede ser
un `exists()`**, porque el puerto no lo tiene y añadirlo reabre el GRAVE-1 de img-1-2 (una guarda de existencia
que confunde "no está" con "no he podido mirar"). La segunda mitad es la lectura verificada. Coste declarado:
**un `304` cuesta la misma E/S de storage que un `200`**, y sólo ahorra el cuerpo en el cable. Se paga a
sabiendas; la alternativa es un `304` que puede mentir.

### NFR6: qué añade el scan sobre deptrac, medido

No escribas el gate como si deptrac no viera nada — eso es falso y se nota en review.

- **Lo que deptrac YA hace**: `Shared.Application` no puede depender de `Vendor.Symfony`, así que
  `UploadedFile` y `File` revientan allí, y `SplFileInfo` sale como dependencia no cubierta bajo
  `--fail-on-uncovered`.
- **Lo que deptrac NO puede**, y es la razón de existir del scan:
  1. **Granularidad.** Su collector es `src/Shared/(.*/)?Application/.*`: una sola capa para todos los módulos
     compartidos. No puede decir nada de `Shared/Images/Application` en particular sin partir la capa y
     reubicar las reglas de todos los demás.
  2. **El eje valor, entero.** `find(ImageId $id, string $path)` no es una dependencia; deptrac lee referencias
     a clases. Es exactamente el defecto que la implementación retirada convirtió en lectura arbitraria de
     ficheros.
  3. **`Psr\Http\Message\*`.** `Shared.Application` permite `Vendor.Psr` (`^Psr\\.*`), así que
     `UploadedFileInterface` y `StreamInterface` —tipos de transporte HTTP genuinos— **pasan deptrac**. Este es
     el hueco menos evidente de los tres y el que más justifica el scan.
- **Y al revés**: un import sin usar rojea el scan y pasa deptrac; un `\Symfony\…\UploadedFile` inline pasa el
  scan (si el scan mira imports) y rojea deptrac. Léelo con `token_get_all` sobre firmas, no por línea — el
  gate del bus de eventos aprendió esa lección con imports agrupados, anidados y `;` en la línea siguiente.

### Registros y gates que morderán

| Registro / gate | ¿Toca? | Por qué |
|---|---|---|
| `api/config/routes.yaml` | **Sí, obligatorio** | Sin recurso, el `#[Route]` no registra nada y todo queda verde (A-3) |
| `api/.artifact-gate-placement` · `make php.lint.gate-placement` | **Sí** | El scan de NFR6 y su test de falsabilidad, `mirrored :: src/Shared/Images`. Ojo: fuera del *home*, un gate con `#[CoversClass]` **no entra en el universo** y su línea se queda huérfana → rojo |
| `api/.behat-step-vocabulary` · `make php.lint.step-vocabulary` | **Sí, muy probablemente** | No existe step que capture una cabecera de respuesta para replay. Y todo patrón `idle` que la feature alcance cambia a `used` en el mismo commit |
| `AuditPolicyTest` (data provider) | **Sí** | La rama `shared_` no la pincha nadie hoy (A-1) |
| `StorageFailureVocabularyTest` | **Sí** | El caso de integridad nuevo, y su disyunción con `FailureCategory` |
| `BestEffortReportChannelGateTest` | **Sí, si añades clase que loguee** | `REPORTERS` ya lista `FlysystemImageStorage.php`; una clase nueva que loguee entra o `php.unit` rompe |
| `pwa/tests/client-minted-problem-types.test.ts` | **Sí, indirectamente** | Barre `api/src` buscando `network-error`/`request-timeout`/`malformed-response-envelope`. Un `type` nuevo que colisione rompe en `make pwa.quality`, **no** en `php.quality` |
| `PRODUCTION_SECURITY_CHECKLIST.md` §7 | **Sí, obligatorio** | Cambio sensible a seguridad. Ampliar `:1534`, no duplicar |
| `docs/api-error-contract.md` | **Sólo si minas un `type`** | La tabla marcador→status no gana fila. Y el gate **no** vigila esta dirección |
| `api/.audit-resource-types` | **No — y AC 20 es lo que lo mantiene así** | Sin `_audit_resource_type`, `Image` no entra en el universo. Declararlo forzaría la clasificación que el épico difiere |
| `api/.person-reference-policy` | **No** | `Image::$id => non-person` ya está (`:219`), con un comentario escrito para pre-empt esta pregunta. **No retipes la columna**: un tipo DBAL propio la sacaría del barrido |
| `api/.persistent-transport-policy` | **No** | `Shared.Image => person :: docs/adr/image-deletion-signal-transport.md` (`:74`). Esta historia no añade evento. Sólo mordería si la señal de ausencia se emitiera como mensaje — es un `LoggerInterface`, y punto |
| `api/.bounded-context-allowlist` | **No** | `Erpify\Shared\…` es siempre importable. Sólo mordería si el controlador importara `Backoffice\`/`Iam\`/`Organization\` |
| `api/.event-dispatch-allowlist` · `make php.lint.event-bus` | **No** | Su barrido es `*/Application/`; un controlador no lo toca. Sí mordería si el `Finder` alcanzara un manager de Doctrine |
| `api/.project-context-versions` | **No, salvo paquete nuevo** | No añadas dependencia: el cache condicional es `Response::setEtag()`/`isNotModified()`, ya en el árbol |
| `api/tools/deptrac/deptrac.yaml` | **No** | `Shared.Infrastructure` ya colecciona a cualquier profundidad y ya permite `Vendor.Symfony` |
| `api/.public-access-exemptions` · `make php.lint.public-access` | **No, si montas bajo `/api/v1`** | La catch-all cubre. Si el test de `#[MapUploadedFile]` usa `/api/test/`, esa exención ya existe — **verifícalo** |
| `api/.accepted-risk` (tags `@accepted-risk #N`) | **Decide** | Si declaras el residual de enumeración como riesgo aceptado **en código**, el tag exige una issue **abierta** (gate estructural en `php.quality` + workflow de estado vivo). Si se queda sólo en §7, no hace falta |

### Naming

- Puertos por **capacidad**, nunca `*Interface`; adaptador `<Tecnología><Puerto>`. Ya fijado por
  `ImageProcessor`/`InterventionImageProcessor`, `ImageStorage`/`FlysystemImageStorage`,
  `ImageRepository`/`DoctrineImageRepository`.
- Caso de uso de lectura → `Application/<Noun>Finder` (`docs/rules/cqrs-naming.md`). **No** inventes
  `ReadImage`: la categoría 6 (`Upload`) existe por `UploadImage` y es para ingestión de bytes externos no
  confiables, no para lectura. Si crees que hace falta categoría nueva, se añade **con su argumento de
  principio / objetivo / coste**, como se hizo con la 6.
- Controlador: uno por operación, `<Nombre>Controller`, siguiendo `BankGetController`.
- Dobles de test: `InMemory<Puerto>` si es implementación alternativa usable, `Stub<Puerto>` si devuelve un
  valor fijo. Viven junto a los tests que los usan.
- Las **tres nociones de MIME** de 1.1 siguen sin ser intercambiables: *declarado* (no fiable, nunca selecciona
  decoder), *detectado* (`finfo`), *canónico* (`Image.mediaType`). El `Content-Type` de la respuesta es el
  **canónico**, y no se vuelve a inspeccionar ningún byte.

### Reutilización — no reinventar

- **ETag / `If-None-Match`**: `git show 08f8199^:api/src/Shared/Http/Infrastructure/ContentAddressedHttpCache.php`
  → rescata `isNotModified()`, **no** `applyHeaders()` (emite `public`). Renombra al integrar.
- **Identidad**: `Erpify\Shared\Uuid\Domain\Uuid::ensure()` → `InvalidUuidException` → 400 `invalid-uuid`.
  `ImageId::fromString()` ya lo llama; no dupliques la validación en el controlador.
- **Contrato de error**: `ProblemDetailsFactory` con `MARKER_STATUS_MAP` / `MARKER_DEFAULT_TYPE_MAP`. Los nueve
  marcadores viven en `api/src/Shared/ErrorContract/Domain/Exception/`.
- **Integridad**: `Domain/CanonicalImage` ya deriva `digest` y `byteSize` en el constructor — candidato natural
  para la verificación de la AC 7 **si sus guardas encajan**; compruébalo, no lo asumas.
- **Observabilidad**: canal `observability` ya existente, vía `#[Autowire(service: 'monolog.logger.observability')]`.
  **Nada de infraestructura de métricas nueva** (NFR9 lo dice, y Task 0 opción B choca con ello).
- **Ruta desechable para test**: `api/config/routes/test.yaml` + fixtures en `api/tests/Functional/.../Fixtures/`.
- **Behat**: `HttpRequestContext` / `JsonNodeContext`; los steps de cabecera ya existen y están `used`.
  `JsonErrorContext` **no** sirve (envelope legacy, siete steps `idle`).
- **Invariante gratis**: `PrivacyContext` corre sobre todos los escenarios y afirma que ninguna fila de
  `audit_log.metadata` lleva identificador vivo de persona. No hay que escribir nada.

### Testing

- Árbol espejo: `api/tests/Unit/Shared/Images/{Domain,Application,Infrastructure}/`. Funcionales (con kernel)
  en `api/tests/Functional/Shared/Images/`, contra **Postgres real**, extendiendo `KernelTestCase`; los cuatro
  existentes abren transacción a mano y hacen `rollBack()` en `finally`.
- **Comportamiento HTTP va a Behat, no a un funcional de PHPUnit** (`api/CLAUDE.md`: *"Behat is preferred over
  PHPUnit functional tests for HTTP behaviour"*).
- Gates artefactuales: kernel-free, `#[CoversNothing]`, motor en `api/tests/Support/`.
- Por fichero: `declare(strict_types=1)`, `/** @internal */`, `#[CoversClass(...)]`, `final class …Test`,
  nombres de método como frase larga. Selección: `make php.unit c='--filter NombreDeClase'`.
- **Afirma la siembra antes que la ausencia.** Antes de decir "no hay filas nuevas en `audit_log`", afirma que
  la petición ocurrió y respondió 200. Una tabla vacía porque el escenario no llegó a correr pasa igual.
- **Trampas medidas en 1.2, no las repitas**: `catch (RuntimeException)` se traga el `$this->fail()` (un
  `AssertionFailedError` **es** un `RuntimeException`) y las aserciones del `catch` corren sobre el camino de
  éxito; un doble en memoria no puede fallar en escritura parcial, hace falta filesystem real; y un test que
  construye el adaptador a mano sobre un tmpdir **no prueba el despliegue** — `ImageStorageWiringTest` es el
  precedente de resolver el servicio del contenedor, y es el único que vio el GRAVE-2 de la raíz inexistente.
- **Falsifica cada gate nuevo mutando el código y viendo el rojo**, y al restaurar **copia los bytes de
  vuelta**, nunca `git checkout --`.

### Matriz AC → test

| AC | Qué prueba | Nivel | Reserva |
|---|---|---|---|
| 1 | Anónimo recibe el mismo 401 para id malformado, ausente y existente | Behat, `Scenario Outline` `@anonymous` | — |
| 2 | Id malformado autenticado → 400 `invalid-uuid` antes de cualquier lookup | Behat + unit del caso de uso | El "antes de" se prueba con un repositorio doble que **falla si lo llaman** |
| 3 | Id válido ausente → 404 Problem Details | Behat | — |
| 4 | Bytes, `Content-Type` canónico, `Content-Length`, `nosniff` | Behat | Que el `Content-Type` no se infiera se prueba mejor en unit: fila con `mediaType` que **no** coincide con los bytes servidos |
| 5 | Fila presente + bytes ausentes → 404 | Funcional con storage real | — |
| 6 | Los tres veredictos dan tres status distintos | Unit con dobles del puerto por cada rama | El 500 sin marcador se afirma sobre el `type` (`unhandled-exception`), no sobre el status a secas |
| 7 | Digest desajustado no se sirve; nada se escribe antes de verificar | Unit con storage que devuelve bytes alterados | "No se comprometen cabeceras antes" es difícil de observar sin streaming; con retorno completo la propiedad es estructural — **dilo, no finjas que el test la prueba** |
| 8 | Cuatro `failure_category` distintos; ningún valor prohibido **como valor** | Unit con `RecordingLogger`, serializando el contexto | — |
| 9 | La cota existe y quitarla pone rojo | Unit | — |
| 10 | `Range` → 200 completo, sin `Accept-Ranges` | Behat | — |
| 11 | `private, max-age=31536000, immutable` | Behat + unit | Un test que sólo compare la cadena entera no dice **por qué**; añade uno que falle específicamente si aparece `public` |
| 12 | Ninguna firma del camino acepta path/URL/key | Cubierto por el scan de AC 17 | — |
| 13 | ETag del digest, fuerte; 304 sólo si recuperable; las tres formas de `If-None-Match` y `*` | Unit (formas) + Behat (304) + funcional (304 negado sobre objeto ausente) | El caso "304 negado" necesita fila viva con bytes borrados: constrúyelo, no lo simules |
| 14 | Offset 0 | **Ninguno ejecutable hoy** — no hay stream en el camino | Es una nota de diseño, no una propiedad testeable. **Dilo en la matriz en vez de fingir cobertura** |
| 15 | Conocer el id no basta sin sesión | Behat, escenario propio | Solapa con AC 1 a propósito: es la regresión que el épico pide nominalmente |
| 16 | Guard aislado + resolución real por kernel | Unit + funcional sobre ruta `when@test` | — |
| 17 | Scan de dos ejes + falsabilidad ante rename | Gate + gate de reglas | — |
| 18 | La ruta existe bajo `/api/v1` y exige sesión | Behat (401 anónimo) + `debug:router` en Task 1 | El 401 prueba la cobertura del firewall; que **no** haya línea nueva en `security.yaml` es revisión, no test |
| 19 | Un 200 no deja filas en `audit_log`; la rama `shared_` está pinchada | Behat/funcional + caso nuevo en `AuditPolicyTest` | — |
| 20 | La ruta no declara `_audit_resource_type` ni `_audit_canonical` | Unit estructural sobre las `defaults` de la ruta | — |
| 21 | Sin `requirements`: un id malformado llega al controlador | Behat (400, no 404) | Es la observación que distingue las dos formas |
| 22 | Residual escrito con su medición | Revisión de doc, no test | — |
| 23 | Feature existe, cubre los cinco casos, vocabulario al día | `make php.behat` + `make php.lint.step-vocabulary` | — |
| 24 | Límites medidos, vetting registrado | Medición escrita, no test | — |
| 25 | Nota de "prueba de infraestructura" | Revisión de doc | — |
| 26 | Mayúsculas → mismos bytes y mismo ETag | Behat o funcional | Falsifícalo quitando el `strtolower` |

Cuatro filas no observan el fallo de su AC con un test ejecutable (7 parcialmente, 14, 22, 24, 25) y están
marcadas. **Re-derívala al terminar de implementar**: la matriz de 1.2 se escribió antes y el code review
encontró que ocho filas daban por cubierto lo que su test no podía ver.

### Project Structure Notes

```
api/src/Shared/Images/
├── Application/
│   └── CanonicalImageFinder.php          (NUEVO — lookup + read + verificación de digest)
├── Domain/
│   ├── Storage/StorageFailureCategory.php (MODIFICADO — caso de integridad)
│   └── Exception/…                        (NUEVO si minas marcador; ver AC 6)
└── Infrastructure/
    ├── Http/ImageGetController.php        (NUEVO — o Controller/, decide y dilo)
    ├── Http/HttpCacheValidator.php        (NUEVO — isNotModified() rescatado y renombrado)
    └── FlysystemImageStorage.php          (MODIFICADO — cota de la ausencia en read())

api/config/routes.yaml                     (MODIFICADO — recurso para el módulo, prefix /api/v1)
api/config/routes/test.yaml                (MODIFICADO — ruta desechable de #[MapUploadedFile])
api/config/services.yaml                   (MODIFICADO si Task 0 añade parámetro de muestreo)
api/.artifact-gate-placement                (MODIFICADO — dos líneas mirrored)
api/.behat-step-vocabulary                  (MODIFICADO — step nuevo y/o idle→used)

api/features/shared/images/*.feature        (NUEVO)
api/tests/Support/…                         (NUEVO — motor del scan de NFR6)
api/tests/Unit/Shared/Images/…              (NUEVO — scan NFR6 + falsabilidad + unit del finder)
api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php  (MODIFICADO — caso shared_)
api/tests/Functional/Shared/Images/…        (NUEVO — 304 negado, #[MapUploadedFile] real)

docs/architecture-api.md                    (MODIFICADO — ruta nueva + corregir :102)
api/docs/adding-endpoints.md                (MODIFICADO — nombre de ruta gobierna auditoría)
api/docs/postman/erpify-api.postman_collection.json + README.md (MODIFICADO)
PRODUCTION_SECURITY_CHECKLIST.md            (MODIFICADO — ampliar §7 :1534)
```

Nota: `api/config/services.yaml` excluye `'../src/**/Domain/Entity/'` del contenedor; nada de lo que añades
cae ahí. Y los cuatro responders de `Shared/Http/Infrastructure/Responder/` son JSON — no hay responder de
cuerpo binario y **no hace falta inventar uno**: un `Response` con la cadena y sus cabeceras es suficiente, y
un responder para un solo llamante sería una abstracción prematura (Regla de Tres).

### Fuera de alcance — no lo construyas aquí

Endpoint de subida de producción · `Bank.logoImageId` / `User.avatarImageId` · auditoría de la ruta ·
voter de ownership · variantes y su URL `/{imageId}/{hash}/{variant}` · `Range` real (`206`/`416`) ·
streaming · deduplicación · refcount · GC · content-addressed storage · adaptador S3 · event sourcing sobre
`Image` · contexto `Documents` · el campo de origen de derivada del ADR D5 · rate limiting por identidad (es
de la épica del consumidor, y AC 22 dice por qué) · reconciliación fila↔objeto (la prohíbe NFR3) ·
`ContentHashUrlGenerator` (RECHAZADO-2 del épico: el hash reabriría semántica de identidad).

### References

- [`_bmad-output/planning-artifacts/epics-images.md`](../planning-artifacts/epics-images.md) — corte de la
  Story 1.3 en `:739-868`; decisiones de alcance `:45-60`; pase adversarial `:68-173` (MEDIA-7 en `:158`,
  RESIDUAL-1 en `:137`); gotcha del BLOB en EOF `:371-373`; esquema mínimo `:420-424`; decision firewall
  `:442-455`; promesa de Behat `:487`; riesgo residual de enumeración `:860-868`.
- [`docs/adr/images-vs-documents-conservation-contract.md`](../../docs/adr/images-vs-documents-conservation-contract.md)
  — D6 (la ruta de lectura pertenece a la rebanada; la asimetría del puerto), invariantes 1, 2 y 6. **Sus dos
  cláusulas sobre auditoría y voter están anuladas por el épico**; ver *Frontera de esta historia*.
- [`docs/adr/image-deletion-signal-transport.md`](../../docs/adr/image-deletion-signal-transport.md) — D3
  enumera los residuales que la épica del consumidor hereda; el de `event_store` sigue vivo y no es de esta
  historia.
- [`docs/api-error-contract.md`](../../docs/api-error-contract.md) — tabla marcador→status; *"Who may mint a
  `type`"*; `Cache-Control: no-store` en respuestas de error (no choca con la AC 11, que es del camino de
  éxito).
- [`docs/rules/cqrs-naming.md`](../../docs/rules/cqrs-naming.md) — plantilla de caso de uso de lectura;
  categoría 6 y por qué no aplica aquí.
- [`docs/rules/testing.md`](../../docs/rules/testing.md) — dónde vive un artifact gate, la excepción
  `mirrored`, y que el nombre de clase es cableado cuando un `php.lint.*` lo selecciona por `--filter`.
- [`docs/rules/security.md`](../../docs/rules/security.md) — el texto de una excepción se persiste en
  `messenger_messages` vía `ErrorDetailsStamp`, sumidero que ningún erasure alcanza.
- `PRODUCTION_SECURITY_CHECKLIST.md` `:1534-1579` — el bloque de residuales de `Shared/Images` que esta
  historia amplía.
- `api/src/Shared/Audit/Domain/AuditPolicy.php:56,60-68` y
  `api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php:82-87` — la rama `shared_` y el data provider que no
  la cubre.
- `api/config/packages/security.yaml:74` — la catch-all `^/api`.
- `api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/RateLimitListener.php:113-131`,
  `api/.env:89`, `api/.env.test:18` — el limitador global y su presupuesto.
- `api/tests/Unit/Gate/ObservabilityChannelGateTest.php:42,85` — por qué `debug` no es una opción.
- `api/src/Shared/Images/Infrastructure/FlysystemImageStorage.php:142,374-379,388-407` — el productor de log.
- `api/src/Shared/Images/Domain/ImageId.php:34` — la normalización cuya regresión prueba la AC 26.
- `api/config/routes/test.yaml` — el precedente de ruta desechable bajo `when@test`.
- `git show 08f8199^:api/src/Shared/Http/Infrastructure/ContentAddressedHttpCache.php` — `isNotModified()`
  rescatable; `applyHeaders()` **no** (emite `public`).
- `git show 08f8199^:api/src/Shared/Storage/Infrastructure/Controller/StoredObjectGetController.php` — la
  doble puerta del 304 y el nombre fósil `shared_stored_object_get`; su `new Response('Not Found', 404)` y su
  `requirements` son las dos mitades que **no** se rescatan.
- [`_bmad-output/implementation-artifacts/img-1-2-persistir-imagen-borrado-fiable-de-bytes.md`](img-1-2-persistir-imagen-borrado-fiable-de-bytes.md)
  — contrato del puerto, los tres pases adversariales y el `[Review][Defer]` que crea la AC 9.
- [`_bmad-output/implementation-artifacts/img-1-1-subir-imagen-obtener-representacion-canonica.md`](img-1-1-subir-imagen-obtener-representacion-canonica.md)
  — contrato de canonicalización v1 y los dos diferidos que crean la AC 24.
- [`_bmad-output/implementation-artifacts/deferred-work.md`](deferred-work.md) — la bala de mutable/inmutable
  que la AC 11 roza; la del borde transaccional del consumidor **no** es de esta historia.

## Change Log

- 2026-08-29 — Historia creada. El análisis midió el árbol en `202767ab` contra el corte del épico y encontró
  catorce desajustes que ninguna AC anticipaba, cuatro de ellos capaces de dejar una decisión del épico falsa
  con todas las puertas en verde: la auditoría genérica que se activa por defecto y sólo se desactiva por el
  **nombre** de la ruta (con la rama que lo permite muerta y sin pinchar desde `08f8199`); la opción `debug`
  de la cota de log, descartada por un gate que fija `info` en test y prod; la ausencia total de recurso de
  routing para `src/Shared/`, que deja un `#[Route]` sin registrar y sin ruido; y el path `GET /images/{imageId}`
  del épico, que cae fuera del `^/api` del firewall sin que `php.lint.public-access` lo note. Se corrigió
  además RESIDUAL-1, que describía como inexistente un limitador global de 120 req/min por IP que sí se aplica.
  La cota del log queda como decisión abierta para Sergio (Task 0), con las dos opciones vivas, sus costes y la
  medición que hay que hacer antes de elegir.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
