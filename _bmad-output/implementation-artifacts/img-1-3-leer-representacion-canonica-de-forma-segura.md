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
  `304`; `Cache-Control`, `Content-Type`, `Content-Length`, `X-Content-Type-Options`; la ampliación de CORS
  que hace usable el cache condicional desde un origen cruzado; la traducción del vocabulario de fallo del
  puerto a status HTTP; la cota de los productores de log del camino de lectura; el scan formal de NFR6
  (ejes tipo **y** valor) con su test de falsabilidad; la primera feature Behat del módulo **con su fixture
  de imagen**; el benchmark de los tres límites de recursos y el vetting de `intervention/gif`; y la
  ampliación de los residuales de `PRODUCTION_SECURITY_CHECKLIST.md` §7.

- **No entrega** (y no debe tocarse en esta PR): ningún endpoint de **subida** de producción; `Bank.logoImageId`
  / `User.avatarImageId` ni ninguna integración de consumidor; auditoría de la ruta de lectura; voter de
  ownership; variantes de imagen y su esquema de URL; `Range` real (`206`/`416`); streaming; adaptador S3;
  contexto `Documents`; y cualquier cambio al contrato de canonicalización de 1.1 o a la **superficie** del
  puerto de 1.2 (sus tres métodos son intocables; AC 6 sí añade una traducción *encima* del puerto, que es
  otra cosa).

- **Decision firewall de la épica (no reabrir aquí, 23 ítems, listado textual de `epics-images.md:442-454`)**:
  `ImageId ≠ digest` · `ImageId ≠ storage key suministrada por el caller` · `Image` no contiene bytes · no
  contiene `owner` · no contiene `filename` · no contiene `url` · no contiene `variant` · sin deduplicación ·
  sin refcount · sin GC · sin content-addressed storage · sin event sourcing sobre `Image` · sin contexto
  `Documents` · sin ingestión de evidencia · sin consumidor `Bank` · sin consumidor `User` · **sin voter de
  ownership genérico** · **sin auditoría genérica de lectura** · borrado de bytes ≠ transacción síncrona del
  propietario · **`ImageStorage` nunca devuelve una URL** · **ruta de lectura ≠ path de filesystem** · sin
  abstracción `ImagePipelineProducer`/`ImageUploadSource` anticipatoria · **sin URL de variante
  `/{imageId}/{hash}/{variant}`**.

- **Dos cláusulas del ADR que el épico ANULA — léelas antes de tocar la ruta.**
  `docs/adr/images-vs-documents-conservation-contract.md:70` dice literalmente *"the read is audited like any
  other"* y *"the story that serves bytes declares the voter it expects"*. El épico decide lo contrario en
  `epics-images.md:45-57`: **cero filas de `audit_log`** (`:45-51`) y **`IS_AUTHENTICATED_FULLY` sin voter**
  (`:52-57`), porque un único `resource_type` no puede ser a la vez persona-denotante (avatar) y no-persona
  (logo), y porque no existe todavía relación de consumidor sobre la que votar. Un dev que lea el ADR sin
  esta nota implementaría exactamente lo contrario. **La jerarquía documental del repo (`epics.md` >
  arquitectura) resuelve el conflicto a favor del épico**, y las AC 19 y 20 lo convierten en algo comprobable
  en vez de en una intención.

- **Cerrado por 1.1 y 1.2, y esta historia lo consume sin reabrirlo**: el contrato de canonicalización v1 de
  ocho propiedades; `digest = SHA-256(bytes canónicos)`, v1 implícito sin campo de versión; el esquema exacto
  de siete campos; la superficie del puerto `store`/`read`/`delete` y su vocabulario de tres clases; el orden
  de borrado bytes→fila; el sharding por la **cola** del `ImageId`; y `ImageId` normalizado a minúsculas en su
  constructor privado (`api/src/Shared/Images/Domain/ImageId.php:34`).

## Adversarial pass

**Estado: dos pases.** El primero, **autoadministrado** sobre el borrador, es el bloque A-1..A-14 — insuficiente
por sí solo, y así estaba declarado antes de que corriera el segundo. El segundo, **externo**, corrió en tres
lecturas paralelas desde sesiones distintas de la que redactó el artefacto, sobre el artefacto ya commiteado
en `b5817a96`, y está registrado más abajo con sus hallazgos y su disposición uno a uno. **Cambió el
resultado, no lo confirmó**: tumbó cuatro AC y la premisa entera de Task 0.

**Sobre el gate, dicho para que nadie lea su verde como lo que no es.** `scripts/adversarial-pass-check.sh`
mide sus dos suelos (`MIN_RECORD_LINES=3`, `MIN_RECORD_CHARS=200`) sobre el **delta** contra el pool de
secciones `## Adversarial pass` de la base. Este fichero es nuevo en la rama, así que el pool base está vacío
y la sección entera cuenta como delta: **el gate habría dado verde sobre el pase autoadministrado solo**. Eso
no es un defecto del gate — el `CLAUDE.md` raíz ya dice que *"a green proves the form, never the substance"* —
pero sí es la razón exacta por la que el pase externo tenía que correr y quedar escrito aquí antes de
`gh pr create`, y no después.

Todos los hallazgos están medidos contra el árbol, con el fichero y la línea que los sostiene.

### Pase 1 — autoadministrado, sobre el borrador (2026-08-29)

- **A-1 (GRAVE) — la decisión "cero filas de `audit_log`" depende del NOMBRE de la ruta, y de una rama que hoy
  es código muerto que no pincha nadie.** `AccessLogAuditListener` audita en `kernel.terminate` toda petición
  **main**, con respuesta **2xx**, cuyo path empiece por `/api/` (`ApiRequestMatcher.php:17`). `AuditPolicy`
  audita entonces **todo `GET`** salvo cinco formas, y la quinta es
  `\str_starts_with($route, 'shared_')` (`api/src/Shared/Audit/Domain/AuditPolicy.php:66`), cuyo propio
  docblock la describe como *"asset/object serving (`shared_*`)"* (`:56`). Medido: **cero rutas** del árbol
  empiezan por `shared_`, y el data provider de casos no auditables de
  `api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php:82-87` rinde **seis** casos — tres health, realtime,
  hot-reload y `_count` — y **ninguno `shared_`**. Hoy esa rama es inalcanzable y borrarla deja todo en verde.
  Un controlador llamado `images_get` escribiría una fila `ACTIVITY` con acción `ROUTE_IMAGES_GET` por cada
  lectura con éxito, **y ningún gate se pondría rojo** — `RequestAuditResourceExtractor` devuelve `null` sin
  `_audit_resource_type`, así que `api/.audit-resource-types` ni se entera. El fósil confirma la intención: el
  controlador retirado se llamaba **`shared_stored_object_get`**. Incorporado como AC 19 y AC 20.

- **A-2 (GRAVE) — la AC del épico ofrece tres cotas para el log de ausencia y una de las tres es letra
  muerta.** `epics-images.md:794-803` admite *"muestreo, contador agregado o nivel `debug`"*. Medido:
  `api/tests/Unit/Gate/ObservabilityChannelGateTest.php:42` fija `private const string PINNED_LEVEL = 'info'`
  y `:85` lo afirma para **test y prod**; el handler `observability` de prod es `level: info`
  (`api/config/packages/monolog.yaml:84-89`). Un `$logger->debug(...)` se **descarta en todos los entornos**.
  Quedan dos opciones — y el pase externo demostró después que ninguna de las dos ataca al productor correcto
  (ver E-5).

- **A-3 (GRAVE) — `src/Shared/` no lo carga ningún recurso de routing, así que un `#[Route]` ahí no registra
  nada y todo el árbol se queda verde.** `api/config/routes.yaml` declara seis recursos de atributos
  (`../src/Backoffice/`, **cuatro** directorios acotados de `Iam`, `../src/Frontoffice/`) y **ninguno cubre
  `src/Shared/`**; medido, `grep -rn '#\[Route' api/src/Shared/` devuelve **cero**. Incorporado como AC 18 y
  Task 1.

- **A-4 (GRAVE) — el épico escribe la ruta como `GET /images/{imageId}`, y en esa ruta literal el firewall no
  la ve.** La regla terminal de `api/config/packages/security.yaml:74` es
  `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }`, acotada a `^/api` *"(not `^/`)"* (`:41-44`) para que
  `/_dev`, `/_wdt`, Mercure y la PWA reverse-proxeada queden fuera. Un `/images/{imageId}` literal sería
  **anónimo por construcción**. La ruta va a `/api/v1/images/{imageId}` y por tanto **no necesita ninguna línea
  nueva de `access_control` ni entrada en `api/.public-access-exemptions`**. *(Corrección del pase externo: la
  frase "ese gate lee `security.yaml`, nunca el router" que este hallazgo llevaba en su primera redacción es
  falsa — `PublicAccessExemptions` lee **también** las fuentes de routing. Lo que no hace es evaluar si una
  ruta cualquiera cae bajo la catch-all, que es de donde sale el desenlace correcto.)* Incorporado como AC 1 y
  AC 18.

- **A-5 (SERIO) — el `ContentAddressedHttpCache` que el épico manda rescatar emite caché COMPARTIDA, y esta
  ruta es autenticada.** Medido en `08f8199^`: `applyHeaders()` llama `$response->setPublic()` y fija
  `Cache-Control: public, max-age=31536000, immutable`. Copiarlo tal cual pondría una respuesta autenticada en
  cachés compartidas — lo contrario de lo que exige `epics-images.md:813-814` (*"`private` porque la ruta
  exige autenticación"*). Lo rescatable es **`isNotModified()`**, no `applyHeaders()`. Incorporado en Task 6.

- **A-6 (SERIO) — la "doble puerta" del `304` que el épico manda rescatar se apoya en un `exists()` que el
  puerto de 1.2 no tiene, y que no debe añadirse.** El controlador retirado hacía
  `existsAnyWithContentHash($hash) && $storagePort->exists($key)`. `ImageStorage` tiene exactamente tres
  métodos y su predicado interno `objectExists()` es privado y **lanza** cuando la existencia no es decidible
  (`FlysystemImageStorage.php:255-271`) — que es el GRAVE-1 del tercer pase de img-1-2. Exponer un `exists()`
  reabre esa decisión. **Consecuencia declarada: el `304` cuesta una lectura completa de storage**, igual que
  el `200`; sólo ahorra el cuerpo en el cable. Incorporado como AC 13.

- **A-7 (SERIO) — RESIDUAL-1 del épico está mal escrito: sí hay rate limiting hoy.** `epics-images.md:867`
  dice que *"rate-limiting sobre esta ruta queda como candidato explícito para la épica del primer consumidor
  real"*, lo que se lee como que no hay ninguno. Medido:
  `src/Shared/ErrorContract/Infrastructure/Http/EventListener/RateLimitListener.php:113-131` gatea sólo por
  main request, prefijo `/api/` y método ≠ `OPTIONS`, **no consulta la autenticación**, y consume del limitador
  `anonymous_api` keyado por **IP de cliente** (`:156-161`): 120/min (`api/.env:89`), 5 en test
  (`api/.env.test:18`). Reescrito en AC 22 — con las tres debilidades que el pase externo añadió.

- **A-8 (SERIO) — la AC de `#[MapUploadedFile]` pide un test de integración para una vía que esta rebanada no
  construye.** `epics-images.md:839-844` exige *"un test de integración independiente que reproduce la
  resolución real del argumento"* (`:842-843`). Medido: `#[MapUploadedFile]` no aparece en `api/src`; su única
  ocurrencia es una cadena de fixture en `tests/Unit/Gate/StrictRequestPayloadGateTest.php:154`. *(El pase
  externo demostró después que este hallazgo, aun siendo cierto, apuntaba a la solución equivocada — ver
  E-2.)*

- **A-9 (SERIO) — la AC de observabilidad pide un tercer `failure_category` que el enum no tiene.**
  `epics-images.md:789-792` exige distinguir `404`, `5xx` e **"integridad"**. `StorageFailureCategory` tiene
  tres casos y ninguno es integridad. *(El pase externo demostró que añadir el caso rompe un test y contradice
  el invariante del propio enum — ver E-4.)*

- **A-10 (SERIO) — no existe marcador para el fallo PERMANENTE, y mapearlo a `ServiceUnavailable` entrena al
  cliente a reintentar lo irreintentable.** El mapa completo (`ProblemDetailsFactory::MARKER_STATUS_MAP`,
  `:113-123`) es `NotFound`→404, `Conflict`→409, `Forbidden`→403, `Unauthenticated`→401,
  `InvariantViolation`→422, `InvalidInput`→400, `RateLimited`→429, `InvalidSearchCriteria`→422,
  `ServiceUnavailable`→503. No hay marcador de "error permanente del servidor". Sin marcador, la excepción sale
  `500 unhandled-exception` por el mismo pipeline RFC 9457 y llega a Sentry, que es donde un fallo de substrato
  pertenece. Cerrado así en AC 6.

- **A-11 (MENOR) — la mitad del controlador retirado que NO se rescata.** `StoredObjectGetController` devuelve
  `new Response('Not Found', Response::HTTP_NOT_FOUND)`: un cuerpo de texto plano que se salta el pipeline
  RFC 9457. **Corrección del pase externo, y el error era mío**: la primera redacción afirmaba que *"hoy eso es
  exactamente lo que `make php.lint.error-contract` existe para refusar"*. Es **falso**.
  `tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php:17` enuncia el invariante como
  *"Controllers MUST NOT catch-and-respond with `new JsonResponse(...)`"* y lo matchea (`:52`, `:333-356`) sólo
  como `new JsonResponse(` **dentro del cuerpo de un `catch`**. Un `new Response('Not Found', 404)` fuera de un
  `catch` es invisible en las dos dimensiones. La prohibición de Task 4 se mantiene; **lo que la sostiene es la
  revisión, no un gate**.

- **A-12 (MENOR) — el `requirements` del controlador retirado, aplicado al `{imageId}`, convertiría el 400 en
  un 404 de router.** El retirado declara `requirements: ['hash' => '[a-f0-9]{64}']`. Incorporado como AC 21.

- **A-13 (MENOR) — Behat se quedó sin dueño en toda la épica, y el contexto que parece servir es una trampa.**
  El épico promete *"unit + integration + Behat contra el propio seam"* (`epics-images.md:486-487`); ninguna
  historia lo entregó, y la review de img-1-2 lo refundió aquí. Además
  `api/tests/Behat/Context/Json/JsonErrorContext.php` se documenta como validador de RFC 9457 pero sus siete
  steps están `idle` y afirman un envelope **legacy** `{errors:[…], meta:{requestId}}`. Incorporado como AC 23
  y Task 11.

- **A-14 (MENOR) — `docs/architecture-api.md:103` ya es falso hoy, antes de esta historia.** Afirma
  *"Attribute-only routing (`#[Route]`) on controllers under each bounded context's `Infrastructure/Controller/`"*,
  y hay **seis** controladores con `#[Route]` bajo `Iam/*/Infrastructure/Http/`. Esta historia lo corrige por
  regla del boy-scout, y **no puede presentarlo como algo que rompa ella**. *(La primera redacción citaba
  `:102`, que está en blanco — corregido.)*

### Pase 2 — externo, tres lecturas paralelas sobre el artefacto commiteado (2026-08-30)

Tres capas independientes (adversarial sin spec · barrido exhaustivo de bordes · auditoría de aceptación y
citas), en sesiones distintas de la que redactó el artefacto. Cada hallazgo de abajo fue **re-verificado a
mano contra el árbol** por la sesión conductora antes de aplicarse: la severidad de una capa es orientativa, y
dos de las tres capas se contradijeron entre sí en un recuento (40 vs 38 atributos `#[Route(`), lo que por sí
solo obliga a medir en vez de arbitrar.

**Hallazgos que cambiaron el diseño (GRAVE, todos aplicados):**

- **E-1 — el brazo `503` de la AC 6 era mecánicamente imposible tal como estaba escrito.**
  `ProblemDetailsFactory` resuelve el marcador dentro de un `match (true)` cuyo primer brazo es
  `$throwable instanceof DomainException` (`:266`), y `buildDomainExceptionResponse()` (`:351-357`) es el
  **único** llamante de `firstMatchingMarker()` (`:494`). `ImageStorageUnavailable extends RuntimeException`,
  así que ponerle `implements ServiceUnavailable` —que es lo que la AC ordenaba, nombrando esa clase— cae al
  `default` y produce **500 `unhandled-exception`**, idéntico al brazo *Permanent* que la propia AC decía que
  no se puede conflacionar. Las tres clases del árbol que llevan `ServiceUnavailable` extienden todas
  `DomainException`; ninguna AC lo decía. **Aplicado**: AC 6 reescrita con traducción en la frontera, y la
  superficie del puerto queda intacta.

- **E-2 — la AC 16 emparejaba dos tests que recorren caminos disjuntos de Symfony, y el test bueno YA
  EXISTE.** `#[MapUploadedFile]` lo resuelve `RequestPayloadValueResolver::mapUploadedFile()`
  (`:273-275`), que lee `$request->files->get(...)` **sin pasar por el serializador**;
  `TransportOnlyUploadedFileDenormalizer` es un `DenormalizerInterface` y sólo vive en el camino
  `#[MapRequestPayload]` (`:253`). Un test sobre `#[MapUploadedFile]` pasa **idéntico con el denormalizador
  borrado** — la cobertura vacua que la propia AC del épico existía para impedir. Y
  `api/tests/Functional/Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizerFunctionalTest.php`
  ya hace lo correcto, por el contenedor real, con un docblock que dice el argumento que la AC reinventaba.
  **Aplicado**: AC 16 y Task 10 reescritas.

- **E-3 — el literal de `Cache-Control` de la AC 11 no es lo que sale por el cable, por dos motivos
  independientes.** `HeaderBag::getCacheControlHeader()` hace `ksort($this->cacheControl)` (`:259`), así que
  las directivas se serializan en orden alfabético. Y como el firewall `main` es *stateful*,
  `AbstractSessionListener` reescribe la cabecera en `kernel.response`: `setPrivate()` +
  `addCacheControlDirective('must-revalidate')` + un `Expires` (`:203-214`), escapable sólo con
  `NO_AUTO_CACHE_CONTROL_HEADER` (`:41`). `must-revalidate` junto a `immutable` es una contradicción
  semántica, y el precedente vivo de que esto pasa de verdad está en el árbol
  (`api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:139`). **Aplicado**: AC 11 reescrita
  sobre directivas presentes en vez de sobre una cadena, con la decisión del `NO_AUTO_CACHE_CONTROL_HEADER`
  explícita.

- **E-4 — el caso de integridad de la AC 8 pone rojo un test existente y contradice el invariante del enum.**
  `StorageFailureVocabularyTest:50-54` itera `StorageFailureCategory::cases()` exigiendo que cada caso tenga
  una clase productora entre las tres del puerto, más `assertCount()` (`:48`). Añadir el caso obliga a minar
  una cuarta `ImageStorageException`, lo que contradice `StorageFailureCategory.php:13-15` (*"A storage
  category is a verdict on the SUBSTRATE, and says nothing about the bytes at all"*). Y el emisor no existe
  donde Task 5 lo ponía: `emit()` construye su contexto desde el propio fallo y **el adaptador no tiene acceso
  a `Image::digest()`**. **Aplicado**: la integridad deja de ser un caso del enum de storage y pasa a ser una
  dimensión propia emitida desde donde vive la comparación.

- **E-5 — Task 0 estaba construida sobre una premisa falsa, heredada sin medir.** La AC del épico
  (`:794-803`) y el `[Review][Defer]` de img-1-2 dicen que *"N identificadores aleatorios desalojan el log"*.
  Bajo el orden que la propia Task 3 manda —`findById()` → si `null`, 404 → *y sólo entonces* `read()`— un
  `ImageId` aleatorio inexistente **no toca storage jamás** y emite cero líneas. El productor sólo es
  alcanzable con una fila viva y bytes ausentes. Y el productor **real** es otro y más ruidoso:
  `guardRootIsUsable()` corre **antes** de `objectExists()` (`FlysystemImageStorage.php:139` vs `:141`), así
  que con la raíz desmontada cada lectura de cualquier fila existente emite `warning` **más** el flush de
  hasta cincuenta registros del `fingers_crossed` de prod, vía el `error` que `ExceptionResponder` escribe
  para un 5xx en el canal por defecto. **Aplicado**: Task 0 reescrita sobre el conjunto correcto de
  productores; AC 9 reescrita.

- **E-6 — ninguna fixture puede sembrar una `Image` con bytes, así que siete filas de la matriz eran
  inconstruibles.** Medido: `api/tests/DataFixtures/` no contiene nada que mencione imágenes, y ninguna
  `.feature` del árbol las menciona. Un `200` necesita **dos** almacenes sembrados coherentemente (fila +
  bytes bajo la raíz de Flysystem, con digest que cuadre); la BD se restaura por feature y el volumen
  `image_storage` **no lo toca nadie**, así que una siembra con id fijo va verde la primera vez y roja la
  segunda (`store()` refusa un identificador ya ocupado). **Aplicado**: Task 11 gana la propiedad de la
  fixture.

**Hallazgos aplicados sin cambiar el diseño (SERIO/MENOR), por eje:**

| Hallazgo | Disposición |
|---|---|
| CORS bloquea el cache condicional: `allow_headers` no lleva `If-None-Match` y `expose_headers` es sólo `['Link']` (`api/config/packages/nelmio_cors.php:19-20`, sobre `paths: ^/api/`) | **Aceptado** — AC 24 nueva |
| Las cabeceras del propio `304` no estaban especificadas en ninguna AC; `setNotModified()` conserva lo que ya esté puesto, así que un `304` sin `ETag`/`Cache-Control` satisface todo lo escrito y rompe el bucle condicional | **Aceptado** — AC 13 |
| El router responde **antes** que el firewall, así que `/api/v1/images/` (vacío), `.../a/b` y un `OPTIONS` no-preflight no dan 401 sino 404/405 | **Aceptado** — AC 1 acotada a "mismo status y mismo `type`/`title`", con las formas que el router se come nombradas |
| "el mismo cuerpo" era literalmente insatisfacible: el cuerpo lleva `instance` (contiene el `ImageId`) y `correlation-id` per-request | **Aceptado** — AC 1 |
| `immutable` + `max-age` de un año sobre un módulo cuyo contrato **es** el borrado: nada invalida una copia cacheada tras una erasure | **Aceptado** — residual explícito en AC 22, y es el residual con más carga de dato personal de los que añade esta historia |
| El puerto devuelve la cadena completa y nada acota el objeto; un agotamiento de memoria es **fatal**, no `Throwable`, así que `ExceptionResponder` no corre y el cuerpo no es Problem Details | **Aceptado** — residual en AC 22 y nota en AC 3 |
| El emisor de integridad viviría en `Application/`, donde deptrac refusa `#[Autowire]` (`deptrac.yaml:302-306`: sólo `Vendor.Psr`/`SymfonyUid`/`PassiveMetadata`) y el gate de reporters exige el canal | **Aceptado** — Task 5 nombra el `services.yaml` y el `REPORTERS` |
| El precedente `/api/v1/me*` que Task 13 mandaba copiar dice *"the subject is always the caller's own identity … and there is no resource another identity could govern"* — las dos mitades elididas son **falsas** aquí | **Aceptado** — Task 13 |
| Cita fabricada: *"Behat is preferred over PHPUnit functional tests for HTTP behaviour"* atribuida a `api/CLAUDE.md`. **No existe en el árbol**; el texto real es `**PHPUnit** + **Behat** for tests. (Behat preferred)` (`api/CLAUDE.md:12`) | **Aceptado — defecto grave de método**, corregido |
| Cuatro rangos de línea del pase 1 apuntaban a la AC contigua (`:158-166`, `:782-786`, `:788-800`, `:833-840`) | **Aceptado** — corregidos a `:813-814`, `:789-792`, `:794-803`, `:839-844` |
| `api/.accepted-risk` no existe como fichero | **Aceptado** — la fila nombra ahora el mecanismo real (tags + `AcceptedRiskTagGateTest`) |
| Recuentos inflados: 48 nombres de ruta (real: **38** atributos `#[Route(`), diecisiete rutas desechables (real: **16**), cuatro responders JSON (real: **2** entre 4 ficheros), "los cuatro funcionales" abren transacción (real: **3** de 4), tres recursos `Iam` (real: **4**) | **Aceptado** — corregidos |
| `ErrorContractGateTest` descrito de dos formas contradictorias en el mismo documento (`:179` vs `:464`) | **Aceptado** — tiene dos mitades y ahora se dicen las dos |
| Filas 2, 6, 12, 18, 20 de la matriz reclamaban cobertura que su nivel no puede dar | **Aceptado** — reescritas |
| Task 13 ofrecía "resuelve la bala y bórrala", reabriendo un diferido que el épico deja fuera (`:374-378`) | **Aceptado** — rama eliminada |
| `If-Modified-Since` / `If-Match` no decididos; `HEAD` sin nombrar; fuente de `Content-Length` sin fijar | **Aceptado** — AC 10 y AC 4 |
| `php.lint.yaml` no es miembro de `php.quality` | **Aceptado** — dicho en Task 1 |
| El tag de historia debe repetirse en el commit que lleve **código**, o check B de `bmad.status.audit` enmudece | **Aceptado** — Task 14 |
| `CanonicalImage` no tiene guardas de constructor, así que "léelas" era instrucción vacua | **Aceptado** — Task 3 |
| `PrivacyContext` es vacuo si no hay identidades sembradas y sólo mira `audit_log.metadata` contra `identity_user`: no compra nada a este módulo | **Aceptado** — retirado de "Reutilización" |
| El razonamiento `!==` vs `hash_equals` de `verifyStoredBytes()` se apoya en "no hay parte remota", premisa que deja de valer en la ruta de lectura (la conclusión sí aguanta) | **Aceptado** — nota en Task 3 |
| Task 9 describía un "orden alfabético" que el registro no sigue ni exige | **Aceptado** — retirado |
| `?_rsc=1` lo desvía Caddy a la PWA antes de llegar a la API, así que la AC 15 no es observable a nivel de kernel para ese parámetro | **Aceptado** — reserva en la matriz |
| El limitador tiene tres debilidades documentadas (pool local por despliegue, sin `lock_factory`, por IP) que AC 22 aplanaba a "120/min" | **Aceptado** — AC 22 |

**Rechazado, con su razón:** ninguno. Los 34 hallazgos triados se aceptaron; los que no cambian el diseño
están en la tabla, y los seis que sí lo cambian están arriba.

**Lo que este pase NO cubre, dicho en vez de implicado:** las tres capas leyeron el **artefacto**, no código
implementado — no hay código todavía. Un pase sobre el código es obligatorio antes de que la historia llegue a
`done`, y el precedente de img-1-2 dice por qué: allí el tercer pase, ya sobre el código y con todas las
puertas en verde, encontró dos GRAVE que los dos pases sobre el artefacto no podían ver.

## Acceptance Criteria

1. **Given** una petición **no autenticada** sobre un `ImageId` existente, **When** se invoca
   `GET /api/v1/images/{imageId}`, **Then** la API deniega en la frontera de autenticación antes de resolver
   ningún dato **And** el orden es **auth → validación de formato del `ImageId` → lookup en repositorio →
   404** **And** un no autenticado recibe **el mismo status y el mismo par `type`/`title`** tanto si el
   `ImageId` es sintácticamente inválido, como si es válido pero inexistente, como si existe. **La propiedad
   es de status y `type`, no de cuerpo idéntico**: el cuerpo Problem Details lleva `instance` (que contiene el
   id pedido) y un `correlation-id` por petición, así que exigir igualdad literal sería insatisfacible.
   **And** queda escrito que dos formas *no* dan 401 porque el router responde antes que el firewall: un id
   vacío (`/api/v1/images/`) y uno con `/` dentro dan **404 de router**, y un `OPTIONS` que no es preflight da
   **405**. Ninguna filtra la distinción que la AC protege, y por eso se declaran en vez de perseguirse.

2. **Given** un `ImageId` con formato inválido (no es un UUID), **When** una petición **autenticada** lo usa,
   **Then** se rechaza con `400` `invalid-uuid` vía `Uuid::ensure()` **antes** de cualquier lookup en
   repositorio y **antes** de tocar `ImageStorage`.

3. **Given** un `ImageId` sintácticamente válido pero sin fila `Image`, **When** una petición autenticada lo
   solicita, **Then** responde `404` por el pipeline RFC 9457 — nunca `500`, nunca un cuerpo que revele estado
   interno **And** queda anotado el único camino por el que un fallo de esta ruta puede **no** ser Problem
   Details: un agotamiento de memoria es un error fatal, no un `Throwable`, así que `ExceptionResponder` no
   llega a correr (ver el residual de la AC 22).

4. **Given** una petición autenticada sobre un `ImageId` existente y recuperable, **When** se invoca la ruta,
   **Then** la respuesta lleva los **bytes canónicos** con `Content-Type`, `Content-Length` y
   `X-Content-Type-Options: nosniff` **And** el `Content-Type` es siempre `Image::mediaType()` —el canónico de
   la fila—, **nunca** inferido de los bytes ni de una cabecera de la petición **And** `Content-Length` es
   `\strlen($bytes)` sobre los bytes que se sirven, no `Image::byteSize()`: la verificación de digest de la
   AC 7 los hace coincidir, y tomar el que se sirve hace que un desajuste sea imposible por construcción en
   vez de por invariante **And** queda dicho que el allowlist de tipos vive en el productor
   (`MediaTypeEncoderFactory`), no en una invariante de la entidad (`Image` sólo guarda
   `'' !== trim($mediaType)`), así que este segundo lector hereda esa disciplina y no la refuerza.

5. **Given** una fila `Image` que existe pero cuyos bytes ya no son recuperables, **When** el adaptador
   confirma la ausencia (`ImageBytesNotFound`), **Then** la ruta responde `404` — el mismo `404` que la fila
   ausente, porque desde fuera son el mismo hecho: la imagen no es servible.

6. **Given** un fallo de storage al leer, **When** se resuelve la respuesta, **Then** los **tres veredictos
   del puerto** se traducen a tres resultados y ninguno se conflacia con otro veredicto del puerto:
   `ImageBytesNotFound` → `404`; `ImageStorageUnavailable` → `503`; `ImageStorageFailed` → `500`
   `unhandled-exception`, **deliberadamente sin marcador**, porque `ENOSPC`, `EACCES` o una raíz ausente no se
   arreglan reintentando y `503` diría lo contrario.
   **And** la traducción ocurre en una excepción **nueva del módulo que extiende
   `Shared\ErrorContract\Domain\Exception\DomainException`**, no añadiendo un marcador a las clases del
   puerto: medido, `ProblemDetailsFactory` sólo consulta marcadores dentro del brazo
   `$throwable instanceof DomainException` de su `match (true)` (`:266`, con `firstMatchingMarker()` en `:494`
   llamado únicamente desde `:351-357`), así que un `RuntimeException` con marcador cae al `default` y sale
   `500` — el mismo resultado que el brazo permanente, colapsando la distinción que esta AC existe para
   preservar. Las tres clases del árbol que llevan `ServiceUnavailable` extienden todas `DomainException`.
   **And** la superficie del puerto (`store`/`read`/`delete` y sus tres excepciones) **no se toca**: la
   traducción vive encima.
   **And** queda dicho que integridad (AC 7) y fallo permanente comparten status y `type` en el cable, y que
   eso es deliberado: se separan en el eje `failure_category` de la señal, no en el eje HTTP, porque para el
   cliente ambos son "el servidor está roto y reintentar no ayuda".

7. **Given** una lectura de storage que podría fallar a mitad de camino, **When** se sirve la respuesta,
   **Then** la implementación completa una lectura **verificada** — bytes íntegros y
   `SHA-256(bytes) === Image::digest()` — **antes** de comprometer status o cabeceras **And** un desajuste de
   digest **nunca** se sirve como cuerpo **And** queda dicho que la propiedad "no se comprometen cabeceras
   antes" es **estructural**, no probada: el puerto devuelve la cadena completa, así que no existe el estado
   intermedio que haría falta para violarla. No se abre ningún camino de streaming en esta historia.

8. **Given** un fallo de lectura o un desajuste de digest, **When** se emite la señal de observabilidad,
   **Then** la señal distingue ausencia confirmada, fallo transitorio, fallo permanente e **integridad**
   **And** la integridad **no** se añade como caso de `StorageFailureCategory`: ese enum es un veredicto sobre
   el **substrato** y lo dice en su docblock (`:13-15`), `StorageFailureVocabularyTest:50-54` exige una clase
   productora del puerto por cada caso, y el adaptador no tiene acceso a `Image::digest()` para detectar el
   desajuste. La integridad se emite desde donde vive la comparación, con su propia dimensión, y el conjunto
   de valores sigue siendo cerrado y disjunto entre los dos enums existentes
   **And** la señal no incluye `ImageId`, `digest`, la storage key ni bytes, **afirmado sobre los VALORES
   serializados del contexto buscando la subcadena, nunca sobre los nombres de las claves**.

9. **Given** que esta historia despierta los productores de log del camino de lectura, **When** se resuelve
   qué hacer con su volumen, **Then** la señal se deja **deliberadamente sin acotar**, y la historia
   **declara por qué** con su medición delante — supersediendo la AC del épico que ordenaba acotarla, con la
   excepción argumentada en
   [`docs/adr/image-read-failure-signal-bound.md`](../../docs/adr/image-read-failure-signal-bound.md) y la
   AC del épico enmendada para apuntar ahí, de modo que **ningún requisito del árbol sigue ordenando una cota
   que el código no implementa**
   **And** los tres productores quedan nombrados: (P1) ausencia confirmada, un `info` por petición mientras
   exista una fila huérfana — y **es un estado permanente, no un incidente pasajero**, porque nace de una
   petición de borrado nunca consumida (residual dos de `PRODUCTION_SECURITY_CHECKLIST.md` §7, *"silent and
   permanent"*), así que cada petición posterior escribe otra línea, para siempre, directa al sumidero y sin
   depender de ningún flush; (P2) raíz de storage inusable, que `guardRootIsUsable()` comprueba **antes** de
   mirar el objeto (`FlysystemImageStorage.php:139` vs `:141`), así que afecta a **cualquier** fila existente;
   (P3) fallo transitorio
   **And** queda escrito que la premisa que el épico y el defer de img-1-2 daban por buena —*"N identificadores
   aleatorios desalojan el log"*— es **falsa** bajo el orden que la AC 12 impone: un id inexistente responde
   404 desde el repositorio sin tocar storage
   **And** queda escrito por qué las cotas disponibles no sirven: están en el **punto de control equivocado**,
   porque una petición P2/P3 escribe la línea de `observability` *y* un registro `error` en el canal por
   defecto (`ExceptionResponder.php:293`) que activa el handler bufferizado y es inalcanzable desde este
   módulo; que un cap por petición es un **no-op** (una lectura por petición, una línea por fallo); y que
   `debug` está descartado por `ObservabilityChannelGateTest:42,85`
   **And** queda escrito que el canal **no lo escucha nadie** —`stream` plano en los tres entornos, handler de
   Sentry comentado y excluyéndolo, ningún colector externo en ningún compose—, así que estas líneas son
   evidencia forense y **no una alarma**, y describirlas como alarma sería falso
   **And** la ausencia en `delete()` **no se toca**: allí el volumen lo acota el trabajo real y la
   contabilidad que img-1-2 argumentó sigue en pie
   **And** no se añade código: lo que esta AC exige es la medición de la Task 0, el ADR y el residual.

10. **Given** una petición con cabeceras de rango o condicionales que esta rebanada no implementa, **When** se
    resuelve la respuesta, **Then** `Range` se **ignora** y se devuelve el cuerpo completo con `200` — nunca
    `206`, nunca `416` — **and** no se emite `Accept-Ranges`, porque anunciar una capacidad no implementada es
    peor que no anunciarla (ampliación argumentada sobre `epics-images.md:805-809`, que sólo prohíbe los dos
    códigos)
    **And** `If-Modified-Since` se ignora y **no** se emite `Last-Modified`, aunque `Image::createdAt()` esté
    disponible: emitirlo abriría un segundo eje de validación que nada de esta rebanada mantiene
    **And** `If-Match` no se evalúa; queda declarado que un `GET` con `If-Match` no coincidente **no** responde
    `412` en esta rebanada
    **And** `HEAD` funciona por construcción (el router lo empareja con `GET` y el framework retira el cuerpo)
    y queda declarado que paga la lectura y el digest completos, igual que el `200`.

11. **Given** una respuesta exitosa, **When** se construyen sus cabeceras de cache, **Then** están presentes
    las directivas `private`, `max-age=31536000` e `immutable`
    **And la AC se afirma sobre las directivas presentes, nunca sobre la cadena literal**, por dos motivos
    medidos: `HeaderBag::getCacheControlHeader()` hace `ksort($this->cacheControl)` (`:259`), así que el orden
    de serialización es alfabético y no el que se escribió; y como el firewall `main` es *stateful*,
    `AbstractSessionListener` reescribe la cabecera en `kernel.response` añadiendo `must-revalidate` y un
    `Expires` (`:203-214`) — precedente vivo del mismo efecto en
    `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:139`
    **And** la historia **decide** qué hacer con esa reescritura y lo escribe: o se acepta `must-revalidate`
    junto a `immutable` (y entonces se dice que la contradicción semántica es consciente y qué gana), o se
    marca la respuesta con `AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER` (`:41`) y se argumenta por
    qué esta ruta puede eximirse cuando el resto de la API no
    **And** existe un test que falla si la directiva vuelve a `public` — que es lo que emite el helper
    rescatable tal cual.

12. **Given** la implementación del controlador, **When** resuelve la respuesta, **Then** la cadena es
    `ImageId → ImageRepository → ImageStorage` **And** en ningún punto se construye ni se acepta un path de
    filesystem desde la petición **And** ninguna firma del camino acepta una storage key. **El eje valor de
    esta AC alcanza también al controlador**, que vive en `Infrastructure/` y por tanto queda fuera del scan
    de la AC 17 salvo que ese scan lo incluya explícitamente (ver AC 17).

13. **Given** una respuesta exitosa, **When** se construye, **Then** el `ETag` deriva del **`digest`**, nunca
    del `ImageId`, y es **fuerte**
    **And** una petición posterior con `If-None-Match` que coincide responde `304` **únicamente si el objeto
    sigue siendo recuperable** — nunca un `304` optimista sobre un objeto ya ausente
    **And** la puerta de recuperabilidad es la **misma lectura verificada de la AC 7**, no un predicado de
    existencia nuevo en el puerto: coste declarado y aceptado, un `304` cuesta la misma E/S que un `200` y
    sólo ahorra el cuerpo en el cable
    **And** el emparejamiento acepta las tres formas válidas (fuerte `"h"`, débil `W/"h"`, sin comillas `h`) y
    `*`
    **And la respuesta `304` lleva sus propias cabeceras `ETag` y `Cache-Control`**: `setNotModified()`
    conserva lo que ya esté puesto y retira `Content-Type`/`Content-Length`, así que un `304` construido sobre
    una respuesta vacía sale sin validador ni directiva de frescura — satisfaciendo todo lo demás escrito aquí
    y rompiendo el bucle condicional que esta AC paga una lectura completa por sostener.

14. **Given** que el almacenamiento subyacente pudiera devolver un stream ya posicionado en EOF (gotcha
    heredado, `epics-images.md:371-373`), **When** se sirven los bytes, **Then** la implementación no confía
    nunca en la posición de un stream recibido **And** si en algún punto se maneja un stream, se lee desde el
    offset 0 — `stream_get_contents` desde EOF devuelve `''`, no `false`, y esa cadena vacía sería servible y
    cacheable. **No hay stream en el camino de esta historia**, así que esto es una nota de diseño sin test
    ejecutable, y la matriz lo dice en vez de fingir cobertura.

15. **Given** cualquier variante de la petición, con o sin parámetros adicionales, **When** no hay sesión
    autenticada, **Then** el conocimiento del `ImageId` por sí solo nunca concede acceso a los bytes (test de
    regresión explícito, no derivado de la AC 1).

16. **Given** la restricción dura del resolver de Symfony 8.1, **When** se verifica que un path de filesystem
    arbitrario no puede materializarse como origen de una subida, **Then** la evidencia es un test que recorre
    **la vía que el guard realmente cubre**, y esa vía es `#[MapRequestPayload]`, no `#[MapUploadedFile]`:
    medido, `RequestPayloadValueResolver::mapUploadedFile()` (`:273-275`) lee `$request->files->get(...)` sin
    tocar el serializador, mientras `TransportOnlyUploadedFileDenormalizer` es un `DenormalizerInterface` que
    sólo participa en el camino `#[MapRequestPayload]` (`:253`) — un test sobre el primero pasa **idéntico con
    el guard borrado**, que es la cobertura vacua que la AC del épico existía para impedir
    **And** ese test **ya existe** y es correcto:
    `api/tests/Functional/Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizerFunctionalTest.php`
    resuelve el denormalizador **del contenedor** y su docblock enuncia el argumento (*"a unit test … passes
    just as happily when the service is no longer in the normalizer chain, which is the way the protection is
    most likely to be lost"*)
    **And** por tanto esta historia **no construye ninguna ruta desechable ni fixture nueva** para esto: cita
    el test existente como la evidencia que la AC del épico pedía, y si el pase de revisión encuentra un hueco
    real en su cobertura, se amplía **ese** test.

17. **Given** el árbol del módulo, **When** se ejecuta el scan de arquitectura de NFR6, **Then** falla si
    aparece un tipo de transporte (`UploadedFile`, `File`, `SplFileInfo`, `SplFileObject`,
    `Psr\Http\Message\UploadedFileInterface`, `Psr\Http\Message\StreamInterface` o sucesor) en cualquier firma
    pública de `Application/` **And** falla si aparece un parámetro de valor elegido por el caller
    (path/filename/URL/storage key) en cualquier firma pública **And** el **eje valor** se aplica también a
    `Infrastructure/Http/` del módulo, porque ahí vive el controlador y es donde la AC 12 pone su segunda
    mitad **And** un test de regresión falla si el scan deja de matchear tras un rename **And** el scan es
    kernel-free y `#[CoversNothing]`, o el registro de colocación de gates no lo verá.

18. **Given** el montaje de la ruta, **When** se declara, **Then** existe un recurso de routing que carga el
    directorio del controlador **And** la ruta resuelve bajo `/api/v1`, de modo que
    `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }` (`security.yaml:74`) la cubre sin línea nueva de
    `access_control` **And** no se añade entrada a `api/.public-access-exemptions` **And** el controlador **no**
    lleva `#[IsGranted]`, porque el firewall es la frontera completa de esta rebanada.

19. **Given** la decisión del épico de escribir **cero** filas en `audit_log`, **When** la ruta responde `200`,
    **Then** no se escribe ninguna fila **And** eso se consigue con un nombre de ruta que empiece por
    `shared_` — la única exclusión de `AuditPolicy::lacksBusinessSemantics()` (`:60-68`) que aplica **And**
    existe un caso en el data provider de `AuditPolicyTest` que ejercita esa rama, que hoy no ejercita nadie
    **And** existe una prueba de extremo a extremo que afirma que una lectura con éxito deja `audit_log` sin
    filas nuevas, contando **antes y después** (una tabla ya vacía no prueba nada).

20. **Given** la misma decisión, **When** se declara la ruta, **Then** sus `defaults` se afirman por
    **igualdad con el conjunto esperado**, no por ausencia de dos claves concretas: un tercer default que
    afecte a la auditoría, añadido más adelante, pasaría un test enumerado en negativo. En particular no
    declara `_audit_resource_type` (metería `Image` en el universo de `api/.audit-resource-types`) ni
    `_audit_canonical`.

21. **Given** la declaración de la ruta, **When** se define su parámetro, **Then** `{imageId}` **no** lleva un
    `requirements` que restrinja su forma — un id malformado tiene que **llegar al controlador** para producir
    el `400` de la AC 2; con `requirements` el router respondería `404` y conflacionaría malformado con
    ausente.

22. **Given** los riesgos que esta ruta abre y no cierra, **When** se documentan, **Then** se añaden al bloque
    de `Shared/Images` de `PRODUCTION_SECURITY_CHECKLIST.md` §7 (hoy `:1534-1579`, *"the four things it does
    not"*), **ampliándolo** en vez de abrir un bullet paralelo, y son cuatro:
    **(a) Enumeración.** `ImageId` es UUIDv7 (time-ordered, más barato de enumerar en una ventana que un
    UUIDv4) y cualquier sesión autenticada puede leer cualquier imagen. **Existe** un limitador global
    `anonymous_api` que se aplica a toda petición `/api/*` sin mirar la autenticación
    (`RateLimitListener.php:113-131`), pero su bound real es más blando de lo que sugiere el número: el pool es
    local y por despliegue —la propia config dice que un despliegue multi-worker tras balanceador necesita
    Redis compartido— y `lock_factory: null` significa que workers concurrentes *"may over- or under-count"*
    (`api/config/packages/rate_limiter.yaml:10-24`). Lo que falta es un límite **por identidad y por ruta**.
    **(b) Una copia cacheada sobrevive a la erasure.** `immutable` con `max-age` de un año significa que un
    cliente conforme no revalida: borrados los bytes y la fila, cada visor sigue sirviendo la imagen hasta un
    año sin que ninguna petición llegue al servidor. En un módulo cuyo contrato **es** el borrado fiable, y con
    avatares como consumidor previsto, es el residual con más carga de dato personal que añade esta historia.
    **(c) El objeto entero vive en memoria.** El puerto devuelve `string` y nada acota el tamaño del objeto
    canónico (`max_input_bytes` acota la **entrada** del procesador, `max_output_dimension` acota **píxeles**).
    Un agotamiento de memoria es un error **fatal**, no un `Throwable`, así que `ExceptionResponder` no corre y
    la respuesta no es Problem Details.
    **(d) Sin auditoría de lectura.** Consciente y decidido por el épico; nada registra quién leyó qué.
    **(e) El sumidero de log no tiene aislamiento entre productores.** El access log de Caddy, el canal por
    defecto y `observability` compiten por **un** presupuesto de retención (`json-file`, 10m × 5) y la
    expulsión es **por volumen, no por ownership ni TTL**, así que un fallo P2/P3 sostenido desaloja la
    historia de subsistemas que no tienen nada que ver — y **nada alerta**, porque el canal no lo escucha
    nadie. El encuadre correcto de este residual no es "demasiados logs" sino **shared-sink eviction entre
    productores independientes**. La decisión de no acotarlo desde aquí está argumentada en
    [`docs/adr/image-read-failure-signal-bound.md`](../../docs/adr/image-read-failure-signal-bound.md) D2 y
    D4; el punto de control correcto es la propia infraestructura de logging y **no es de esta épica**.
    **And** `ImageId` no se considera nunca un mecanismo de autorización ni un secreto.

23. **Given** que la épica prometió Behat contra el propio seam (`epics-images.md:486-487`) y ninguna historia
    lo entregó, **When** se cierra esta historia, **Then** existe al menos una feature en
    `api/features/shared/images/` que ejercita la ruta por el kernel real, cubriendo `401` anónimo, `400`
    malformado, `404` ausente, `200` con sus cabeceras y `304` condicional
    **And** existe el mecanismo de **fixture de imagen** que esos dos últimos necesitan (ver Task 11): hoy no
    hay ninguno, y sin él los tres escenarios que no necesitan bytes son los únicos construibles
    **And** todo patrón de step nuevo queda clasificado en `api/.behat-step-vocabulary` en el **mismo commit**,
    y todo patrón `idle` que la feature alcance **cambia a `used`** en ese mismo commit.

24. **Given** que la ruta ofrece cache condicional, **When** un cliente de origen cruzado intenta usarla,
    **Then** puede: `If-None-Match` entra en `allow_headers` y `ETag` en `expose_headers` de
    `api/config/packages/nelmio_cors.php` (hoy `:19` no lleva el primero y `:20` es sólo `['Link']`, sobre
    `paths: ^/api/`). Sin esto el preflight de una petición condicional se refusa y `fetch()` no puede leer el
    validador — la feature entera es inutilizable desde los orígenes que el propio despliegue configura, y
    falla **en silencio** porque Behat conduce el kernel por BrowserKit y nunca pasa por Caddy ni por un
    navegador. **And** el ensanchamiento se argumenta en la PR como lo que es: dos cabeceras de cache HTTP, no
    una relajación de credenciales.

25. **Given** los tres límites de recursos que 1.1 fijó sin validar (`max_input_bytes` 20 MB,
    `max_decoded_pixels` 40 MP, `max_input_dimension` 10000 px) y la dependencia transitiva
    `intervention/gif`, **When** se cierra esta historia, **Then** los límites quedan **medidos** contra el
    worker real con su resultado escrito (memoria pico por formato y tiempo), usando `memory_get_peak_usage`
    y **no** `docker stats`, cuyo muestreo pierde picos **And** el vetting de `intervention/gif` queda
    registrado **And** la medición dice de antemano **qué resultado cambiaría un límite**, o es un número que
    no decide nada **And** si no cambia ninguno, eso se dice explícitamente.

26. **Given** la ausencia de un consumidor real, **When** se documenta la ruta, **Then** queda explícito que es
    una **prueba de infraestructura** —bytes recuperables a través de la frontera de `Images`— y no una API de
    producto lista para exponerse; no establece ownership ni autorización semántica de ningún consumidor.

27. **Given** el defecto que el code review de img-1-2 encontró por el lado del borrado (la storage key se
    derivaba de la **grafía** del `ImageId` mientras la fila se selecciona por su **valor**), **When** se lee
    por la ruta, **Then** existe un test de regresión que afirma que
    `GET /api/v1/images/{ID-EN-MAYÚSCULAS}` devuelve **exactamente los mismos bytes y el mismo `ETag`** que la
    forma en minúsculas — la normalización vive en `ImageId::__construct` (`ImageId.php:34`) y esta AC prueba
    que la ruta la hereda en vez de esquivarla.

## Tasks / Subtasks

### Task 0 — La señal no se acota: escribir el ADR y tomar la medición de coste por evento (AC 9, AC 22e)

**Decisión tomada el 2026-08-30, ya no es un gate.** Se eligió **no acotar** (opción D), tras dos rondas de
consulta externa y tres mediciones sobre el árbol. El razonamiento completo, con las cuatro alternativas
descartadas una a una, vive en
[`docs/adr/image-read-failure-signal-bound.md`](../../docs/adr/image-read-failure-signal-bound.md). Lo que
queda es ejecutarlo.

Resumen de por qué, para que nadie lo reabra sin leer el ADR: la premisa era falsa (un id aleatorio no toca
storage); `debug` es letra muerta; un cap por petición es un **no-op**; el muestreo reduce **una** línea de al
menos dos, porque el registro `error` que dispara el handler bufferizado está en otro canal y es inalcanzable
desde aquí; y el canal **no lo escucha nadie**, así que tampoco hay alarma que preservar. El punto de control
correcto es el aislamiento del sumidero, que es infraestructura transversal.

- [ ] **Escribir el ADR** si no está ya en la rama, y comprobar que `docs/index.md` lo lista.
- [ ] **Enmendar la AC del épico** (`epics-images.md`, el bloque de la señal de lectura) para que apunte al
      ADR. Un requisito escrito no se reinterpreta desde la historia que lo incumple.
- [ ] **Medir el coste POR EVENTO**, que es lo único obtenible: bytes de una línea `image_storage_failure`
      con el formatter de prod, bytes del registro `error` correspondiente, y bytes de una línea representativa
      del access log de Caddy. De ahí, la aritmética de contribución relativa bajo una **tasa hipotética** de
      P2/P3.
- [ ] **Declarar explícitamente lo que esa medición NO es**: no mide frecuencia real ni comportamiento de
      producción, porque **no hay despliegue de producción** (`PRODUCTION_SECURITY_CHECKLIST.md:1198,1222,1458`,
      una de ellas un *"Accepted 2026-08-18 (Sergio)"*). Es un coste unitario, nunca una observación de
      exposición. Un número presentado como lo segundo sería exactamente el tipo de afirmación que el pase
      adversarial de esta historia existió para cazar.
- [ ] **Cero código**: no hay parámetro de muestreo, no hay contador, `emit()` no se toca y `delete()` menos.
- [ ] El residual (e) de la AC 22 va a §7 en Task 13.
- [ ] **Hallazgo en tránsito, nombrado y no arreglado aquí**: el comentario del bloque `log` de
      `api/frankenphp/Caddyfile` afirma que *"no compose file declares a `logging:` driver, so it is the default
      json-file driver with neither rotation nor TTL"*. Ya es **falso**: `x-logging` declara `10m × 5`. El
      problema de aislamiento sigue siendo válido con rotación —la expulsión es por volumen, no por
      ownership—, así que la frase está desfasada, no equivocada en su conclusión. No es fichero de esta
      historia; corrígelo sólo si acabas editándolo por otro motivo.

### Task 1 — Montar el módulo en el router (AC 18, AC 21)

- [ ] Añadir a `api/config/routes.yaml` un recurso de atributos que cargue **el directorio del controlador**
      —no `../src/Shared/` entero, para que montar el router sobre el shared kernel no exponga cualquier futuro
      `#[Route]` de otro módulo compartido—, con `prefix: /api/v1` y `defaults: {_format: json}`. La forma más
      cercana es `api_v1_iam_session`.
- [ ] `#[Route('/images/{imageId}', name: 'shared_image_get', methods: ['GET'])]` — **sin `requirements`**
      (AC 21), **sin `_audit_resource_type` ni `_audit_canonical`** (AC 20), **sin `#[IsGranted]`** (AC 18).
- [ ] Verificar el montaje real: `make sf c='debug:router' | grep images`. Y `make php.lint.yaml` sobre la
      config — **córrelo a mano**, porque ese target **no** es miembro de `php.quality` ni de
      `php.quality.dry-run`, así que CI no lo cubre.
- [ ] Falsificar: borra el recurso de `routes.yaml` y comprueba que la feature Behat se pone **roja**.

### Task 2 — Fijar el nombre de la ruta y pinchar la rama de auditoría (AC 19, AC 20)

- [ ] Nombre `shared_image_get`. **Es un requisito, no un estilo**: es lo único que hace verdadera la decisión
      de cero auditoría (`AuditPolicy.php:60-68`). El fósil `shared_stored_object_get` confirma que la
      exclusión se escribió para esta familia.
- [ ] Añadir un caso `shared_` al data provider de rutas no auditables de `AuditPolicyTest` (hoy `:82-87`,
      seis casos, ninguno). Falsifica borrando `AuditPolicy.php:66` y comprobando que el caso nuevo se pone
      rojo — hoy borrarla deja todo verde.
- [ ] Prueba de extremo a extremo de que un `200` no deja filas. **No hace falta step nuevo**: el vocabulario
      ya tiene `I execute the SQL query :query` y el contador de registros del resultado, ambos `used`.
      Cuenta antes y después.
- [ ] Documentar en `api/docs/adding-endpoints.md` que el nombre de ruta gobierna la auditoría genérica, y
      añadir `Shared` a una tabla de convención que hoy sólo contempla "office".

### Task 3 — El caso de uso de lectura en `Application/` (AC 4, 5, 7, 12)

- [ ] Clase nueva en `api/src/Shared/Images/Application/`. Por `docs/rules/cqrs-naming.md:88` un caso de uso de
      lectura es un `Finder`; propuesta `CanonicalImageFinder`. **No** dejes que el controlador hable con
      `ImageRepository` y `ImageStorage` directamente: además de saltarse la capa, dejaría el scan de NFR6 sin
      superficie que vigilar.
- [ ] Orquestación: `findById()` → si `null`, ausencia (404) → `read()` → **verificar el digest contra
      `Image::digest()`** → devolver. El repositorio ya garantiza que `null` es fila confirmadamente ausente y
      que un fallo de BD lanza.
- [ ] La verificación es una línea: `hash('sha256', $bytes)` comparado con `Image::digest()`. **No reutilices
      `CanonicalImage` para esto**: medido, no tiene guardas de constructor (`:22-30`) —así que "léelas" no
      significaría nada— y construirlo obligaría a fabricar `mediaType`/`width`/`height` desde la fila para
      derivar algo que ya es una línea. Regla de Tres: no hay tercer caso.
- [ ] Nota al copiar el patrón: `verifyStoredBytes()` justifica su `!==` sobre `hash_equals` con *"no hay parte
      remota ni oráculo de tiempo"*. En la ruta de lectura **sí hay parte remota**. La conclusión sigue
      valiendo (ambos operandos son del servidor, nada es adivinable), pero la razón escrita no se traslada —
      si copias el comentario, reescribe el argumento.
- [ ] La firma pública no acepta ni devuelve path, URL, storage key ni tipo de transporte.

### Task 4 — La traducción de fallo a status (AC 3, 5, 6)

- [ ] **Mina una excepción nueva del módulo que extienda
      `Erpify\Shared\ErrorContract\Domain\Exception\DomainException`** e implemente el marcador. Es obligatorio,
      no estilístico: `ProblemDetailsFactory` sólo consulta marcadores dentro del brazo
      `instanceof DomainException` (`:266`/`:351-357`/`:494`), así que añadir el marcador a
      `ImageStorageUnavailable` (que extiende `RuntimeException`) produciría **500**, no 503.
- [ ] Tabla cerrada, cada rama alcanzable en un test:
      fila ausente → `404` · `ImageBytesNotFound` → `404` · desajuste de digest → `500` sin marcador ·
      `ImageStorageUnavailable` → `503` (`ServiceUnavailable`) · `ImageStorageFailed` → `500` sin marcador.
- [ ] **No toques la superficie del puerto.** La traducción vive encima, en el borde
      `Application`/`Infrastructure`; las tres excepciones de 1.2 se quedan como están.
- [ ] Captura por **especificidad decreciente**; nunca `catch (\Throwable)`; nunca capturar primero la interfaz
      `ImageStorageException` (deja ramas inalcanzables).
- [ ] **Ningún `new JsonResponse(...)` ni `new Response('...', 404)` en el controlador.** Y que quede claro
      qué lo sostiene: `ErrorContractGateTest` tiene **dos mitades** —una barre **todo** `api/src` buscando
      `new JsonResponse(` **dentro de un `catch`** (`:52`, `:333-356`), y la otra, la de citación en la doc,
      está acotada a `MARKER_DIRECTORY` (`:94`)—. Un `new Response('…', 404)` fuera de un `catch` **no lo ve
      ninguna de las dos**: aquí manda la revisión, no un gate.
- [ ] El texto de cualquier excepción nueva no lleva `ImageId`, digest ni storage key: llega a
      `messenger_messages` vía `ErrorDetailsStamp` y a Sentry.
- [ ] Si minas un `type` nuevo, `docs/api-error-contract.md` es obligatorio por NFR26 — y la mitad del gate que
      lo vigilaría no alcanza a `Shared/Images/Domain/Exception/`.

### Task 5 — Observabilidad: la dimensión de integridad y la cota (AC 8, AC 9)

- [ ] **No añadas un caso a `StorageFailureCategory`.** Medido: `StorageFailureVocabularyTest:50-54` itera
      `cases()` exigiendo una clase productora del puerto por caso (más `assertCount()` en `:48`), así que el
      caso nuevo se pone rojo salvo que mines una cuarta `ImageStorageException` — y eso contradice el
      docblock del propio enum (`:13-15`: *"a verdict on the SUBSTRATE, and says nothing about the bytes at
      all"*). La integridad no es un veredicto sobre el substrato.
- [ ] Emite la integridad **desde donde vive la comparación** (el finder de Task 3), con su propia dimensión, y
      mantén cerrado y disjunto el universo de valores frente a los dos enums existentes.
- [ ] **Eso trae dos consecuencias que hay que escribir, no descubrir**: (1) `BestEffortReportChannelGateTest`
      deriva su población de cualquier clase de `api/src` con `LoggerInterface` + `$this->logger->`, así que el
      finder entra en `REPORTERS` automáticamente y queda obligado al canal `observability`; (2) deptrac
      **refusa** `#[Autowire]` en `Shared.Application` (`deptrac.yaml:302-306` admite sólo `Vendor.Psr`,
      `Vendor.SymfonyUid`, `Vendor.PassiveMetadata`), así que el canal se ata en `services.yaml`, no con el
      atributo. Medidos: los diez `#[Autowire(service: 'monolog.logger.observability')]` del árbol están todos
      en `Infrastructure/`. Ojo al tercer orden que el propio gate documenta: un bloque explícito colocado
      **encima** del prototipo `Erpify\` revierte la clase al canal autowired en silencio.
- [ ] Implementar la cota decidida en Task 0 **conservando la forma que los gates exigen**: dos llamadas por
      nivel (`->info()` / `->warning()`), nunca `log($level, …)`; el `try { … } catch (Throwable) {}` se queda.
- [ ] La ausencia en `delete()` **conserva `info`**. La cota es sólo del camino de lectura.
- [ ] Test de no-fuga **por valor**: serializa el contexto y busca `ImageId`, digest y key como **subcadena**.
      Un test por nombre de clave pasa sobre `['path' => 'images/ab/cd/01H9…']`.
- [ ] Falsifica la cota: quítala y comprueba el rojo.

### Task 6 — Cache condicional: rescatar la política, no el helper (AC 11, AC 13)

- [ ] Rescatar **`isNotModified()`** de `git show 08f8199^:api/src/Shared/Http/Infrastructure/ContentAddressedHttpCache.php`
      (43 líneas) y su test (71). Es la política de `If-None-Match`: `*`, fuerte, débil, sin comillas.
- [ ] **NO rescatar `applyHeaders()`**: llama `setPublic()` y fija `public, …`. Esta ruta es autenticada.
- [ ] **Renombrar al integrarlo** (p. ej. `HttpCacheValidator`): esta épica no adopta content-addressing.
- [ ] `ETag` **fuerte**. Para afirmar la fuerza en Behat usa `the header :name should match :regex` — el step
      `should be equal to` compara en minúsculas por ambos lados y **no distingue `W/"abc"` de `w/"ABC"`**.
- [ ] **Construye el `304` sobre la respuesta que ya lleva `ETag` y `Cache-Control`**, o ponlos explícitamente:
      `setNotModified()` conserva lo presente y retira `Content-Type`/`Content-Length`. Un `304` desnudo
      satisface todas las demás AC y rompe el bucle.
- [ ] La doble puerta: la recuperabilidad la prueba la lectura verificada, **no** un `exists()` en el puerto.
      Escribe el porqué y el coste en el código.
- [ ] **Decide y escribe qué se hace con `AbstractSessionListener`** (AC 11): aceptar `must-revalidate` +
      `Expires`, o marcar la respuesta con `NO_AUTO_CACHE_CONTROL_HEADER` y argumentar la excepción.

### Task 7 — Las cabeceras de la respuesta (AC 4, AC 10)

- [ ] `Content-Type` = `Image::mediaType()`. Nunca `finfo` sobre los bytes servidos, nunca una cabecera de la
      petición.
- [ ] `Content-Length` = `\strlen($bytes)` sobre lo que se sirve.
- [ ] `X-Content-Type-Options: nosniff`.
- [ ] `Range` ignorado, sin `Accept-Ranges`. `If-Modified-Since` ignorado, sin `Last-Modified`. `If-Match` no
      evaluado. `HEAD` documentado como equivalente en coste.
- [ ] Comprueba qué más viaja: `X-Correlation-Id` y `RateLimit-*` los ponen listeners que corren aquí también,
      y `AbstractSessionListener` **reescribe** `Cache-Control` (Task 6).

### Task 8 — CORS para el cache condicional (AC 24)

- [ ] `api/config/packages/nelmio_cors.php`: añadir `If-None-Match` a `allow_headers` (`:19`) y `ETag` a
      `expose_headers` (`:20`).
- [ ] Argumentarlo en la PR: son dos cabeceras de cache HTTP, no una relajación de credenciales
      (`allow_credentials` sigue en `false`).
- [ ] Decir en la PR que **ningún test del repo lo cubre**: Behat conduce el kernel por BrowserKit y no pasa
      por Caddy ni por un navegador, así que un fallo aquí es silencioso. La verificación es manual (un
      `curl -k` con `Origin` y `Access-Control-Request-Headers`, o el navegador).

### Task 9 — El scan de NFR6 y su falsabilidad (AC 17, AC 12)

- [ ] Gate nuevo bajo `api/tests/Unit/Shared/Images/`, `#[CoversNothing]`, kernel-free, motor en
      `api/tests/Support/` (**nunca** en `api/tests/Unit/Gate/Support/`, trinquete descendente).
- [ ] **Dos ejes**, y el eje valor cubre también `Infrastructure/Http/` del módulo (AC 12).
- [ ] Di en el docblock qué añade sobre deptrac, **medido**, en vez de implicar que deptrac no ve nada:
      `Shared.Application` ya rechaza `Vendor.Symfony`, así que `UploadedFile`/`File` revientan allí. Lo que
      deptrac **no** puede: (a) hablar de `Shared/Images/Application` en particular, porque su collector es
      `src/Shared/(.*/)?Application/.*` y pliega todos los módulos compartidos; (b) el eje valor entero, porque
      un `string $path` no es una dependencia; (c) refusar `Psr\Http\Message\*`, porque `Shared.Application`
      admite `Vendor.Psr` (`^Psr\\.*`) y ahí viven tipos de transporte HTTP genuinos. (c) es el menos evidente.
- [ ] Léelo con `token_get_all` sobre firmas, no por línea.
- [ ] Guarda de no-vacuidad: `assertNotSame([], $sources, …)`.
- [ ] Clasificar en `api/.artifact-gate-placement` como `mirrored :: src/Shared/Images`, siguiendo el
      precedente de `:186`.

### Task 10 — La evidencia de `#[MapUploadedFile]` (AC 16) — leer antes de escribir nada

- [ ] **No construyas ninguna ruta desechable ni fixture.** El test que la AC del épico pedía **ya existe**:
      `api/tests/Functional/Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizerFunctionalTest.php`,
      que resuelve el denormalizador **del contenedor** y cuyo docblock enuncia exactamente el argumento.
- [ ] Entiende por qué la AC del épico apuntaba mal, porque es el mismo error que casi se repite:
      `#[MapUploadedFile]` lo resuelve `RequestPayloadValueResolver::mapUploadedFile()` leyendo
      `$request->files->get(...)` **sin serializador** (`:273-275`); el guard es un `DenormalizerInterface` y
      sólo vive en `#[MapRequestPayload]` (`:253`). Un test sobre el primero pasa **idéntico con el guard
      borrado** — cobertura vacua.
- [ ] Cita el test existente como la evidencia en la PR. Si la revisión encuentra un hueco real en su
      cobertura, **amplía ese test**; no mines uno paralelo.

### Task 11 — Behat, la deuda de la épica, y la fixture que nadie tiene (AC 23)

- [ ] **La fixture es la mitad difícil y no existe.** Medido: `api/tests/DataFixtures/` no contiene nada de
      imágenes y ninguna `.feature` del árbol las menciona. Un `200` o un `304` necesitan **dos** almacenes
      coherentes: la fila `Image` y sus bytes bajo la raíz de Flysystem, con digest que cuadre.
- [ ] Los dos almacenes tienen ciclos de vida distintos: la BD se restaura por feature desde un template, y el
      volumen `image_storage` **no lo toca ningún teardown** y sobrevive a `make docker.down`. Una siembra con
      id fijo va verde la primera vez y roja la segunda, porque `store()` refusa un identificador ya ocupado.
      **Decide el mecanismo y escríbelo**: id por escenario, o limpieza explícita, o siembra idempotente.
- [ ] Siembra los bytes **a través del `ImageStorage` del contenedor**, no escribiendo el fichero a mano: así
      el cableado de la raíz queda ejercitado en vez de esquivado. `ImageStorageWiringTest` es el precedente de
      resolver el servicio real, y es el único test que vio el GRAVE-2 de img-1-2.
- [ ] Feature en `api/features/shared/images/`. **La ubicación es load-bearing**: `BehatSuiteCoverageGateTest`
      refusa un `.feature` fuera de una raíz declarada, y una feature bajo una cuarta raíz quedaría verde en
      todos los gates porque nadie la parsea.
- [ ] Modelos: `api/features/backoffice/bank/access_control.feature` para el bloque anónimo (usa `@anonymous`),
      `api/features/backoffice/users/get.feature` para el trío 200/400/404.
- [ ] **Trampa**: `JsonErrorContext` se anuncia como validador de RFC 9457 pero sus siete steps están `idle` y
      afirman un envelope **legacy**. Los Problem Details se afirman campo a campo con `JsonNodeContext`.
- [ ] **Estado por escenario**: `HttpRequestContext::$headers` no se resetea entre `When`. Para "sin, con, sin"
      usa `And I remove "If-None-Match" header` (existe, `used`).
- [ ] **Cita las cabeceras entre comillas**: el token de placeholder del gate de vocabulario es más ancho que
      el de Behat, así que un `If-None-Match` sin comillas **matchea en el gate y queda indefinido en Behat**.
      Es la única dirección en que ese gate falla abierto, y sólo `--strict` la caza.
- [ ] Para el `304` hace falta reinyectar el `ETag` como `If-None-Match`. **Decide**: (a) step nuevo
      `I add :name header equal to the response header :header` —no existe ninguno que capture una cabecera de
      respuesta, aunque sí el análogo JSON, `idle` en `:158`— clasificado `used` en el mismo commit; o (b)
      digest literal en la feature, viable porque la canonicalización es determinista sobre una fixture. Elige
      y di por qué.
- [ ] Correr `make php.gherkin` y `make php.behat c='features/shared/images/<fichero>.feature'`.

### Task 12 — Benchmark de límites y vetting de `intervention/gif` (AC 25)

- [ ] Medir los tres límites contra el worker real con `memory_get_peak_usage`, **no** `docker stats`.
- [ ] Registrar el vetting de `intervention/gif` (5.0.1), que decodifica bytes GIF no confiables y entró como
      transitiva sin mención en la sección de seguridad de la PR de 1.1.
- [ ] Decir **antes** qué resultado cambiaría un límite. Escribir el resultado aunque no cambie nada.

### Task 13 — Documentación y residuales (AC 22, AC 26)

- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` §7: **ampliar** el bloque de `:1534-1579` con los **cinco**
      residuales de la AC 22. No abras un bullet paralelo. El quinto —shared-sink eviction entre productores
      independientes— cambia el encuadre del bloque: no es "demasiados logs", es que un productor puede
      desalojar la historia de otro y **nada alerta**.
- [ ] El ADR `docs/adr/image-read-failure-signal-bound.md` y su entrada en `docs/index.md` van **en esta
      rama**, no en una posterior: son el artefacto que gobierna la excepción de la AC 9, y sin ellos la
      historia contradice al épico en silencio.
- [ ] **Issue de infraestructura, sólo con autorización explícita de Sergio** (es superficie hacia fuera): el
      sumidero del contenedor no tiene aislamiento ni routing entre el access log de Caddy, el canal por
      defecto y `observability`. Es transversal a todos los contextos y **no** es de esta épica — deferral
      genuino y argumentado, no la pila de diferidos que `CLAUDE.md` prohíbe alimentar desde dentro de una
      épica.
- [ ] `docs/architecture-api.md`: bullet de la ruta en `## API design` y la ruta en el bullet de `Images/`
      (`:64`). **Corregir `:103`** (`:102` está en blanco), que afirma que los controladores con `#[Route]`
      viven bajo `Infrastructure/Controller/` cuando hay seis bajo `Iam/*/Infrastructure/Http/`.
- [ ] **No copies el argumento de `/api/v1/me*` (`:110`).** Su texto completo dice *"the subject is always the
      caller's own identity … and there is no resource another identity could govern"*, y las dos mitades son
      **falsas** aquí: cualquier sesión lee cualquier imagen, incluida la de otra persona. Escribe el argumento
      propio: el firewall es la frontera **provisional** de una rebanada sin consumidor, y la AC 26 lo dice.
- [ ] `api/docs/adding-endpoints.md`: la convención de nombre para un módulo de `Shared` (Task 2).
- [ ] `api/docs/postman/erpify-api.postman_collection.json` + su `README.md`: obligatorio en la misma PR,
      re-derivando con `make sf.routes f='api'`.
- [ ] `docs/api-error-contract.md`: **sólo si** minas un `type` nuevo (Task 4).
- [ ] `_bmad-output/implementation-artifacts/deferred-work.md`: la bala de mutable/inmutable **se queda
      intacta**. La AC 11 la roza al justificar `immutable`, pero el épico deja ese modelado
      *deliberadamente fuera* (`:374-378`), así que la salida correcta es acotar `immutable` a esta rebanada y
      decir que la pregunta de la URL de variantes sigue abierta — **no** resolverla ni borrar la bala.
- [ ] Si declaras el residual de enumeración como riesgo aceptado **en código**, el tag `@accepted-risk #N`
      exige una **issue abierta** (gate estructural en `php.quality` + workflow de estado vivo). Si se queda
      sólo en §7, no hace falta.

### Task 14 — Barrido y cierre

- [ ] Regla del boy-scout sobre lo que toques: fuera IDs de historia/requisito y comentarios relativos al
      cambio.
- [ ] `make php.stan` sobre cada fichero PHP cambiado, según avanzas.
- [ ] Al final, con el código de salida impreso: `make php.quality` · `make php.unit` · `make php.behat` ·
      `make pwa.quality` (la PWA sí: `pwa/tests/client-minted-problem-types.test.ts` barre `api/src` buscando
      `network-error`/`request-timeout`/`malformed-response-envelope`, y un `type` nuevo que colisione rompe
      **ahí**, no en `php.quality`).
- [ ] **PHPMD**: techo de coupling-between-objects 13 y 10 métodos públicos por clase de test. Un controlador
      con traducción de errores, cache condicional y cabeceras va a ese techo; parte por concern desde el
      principio.
- [ ] **El commit que lleve el código nombra la historia en el subject** (`… (img-1-3)`). El commit de
      creación de esta historia es `docs(...)` y sólo toca `_bmad-output/`, así que check B de
      `make bmad.status.audit` lo descarta por diseño — si el tag no se repite en el commit de código, la
      auditoría **enmudece**.

## Dev Notes

### La ruta: dónde monta, cómo se llama, y por qué el nombre es un requisito

Path `/api/v1/images/{imageId}`, nombre `shared_image_get`. Las tres decisiones están acopladas:

- **El prefijo `/api/v1`** mete la ruta bajo la catch-all `^/api` (`security.yaml:74`). El
  `GET /images/{imageId}` del épico sería una ruta anónima.
- **El recurso de routing** no existe: ningún `resource:` de `api/config/routes.yaml` cubre `src/Shared/`.
  Hasta que lo añadas, el `#[Route]` es decoración.
- **El nombre `shared_`** es lo que hace verdadera la decisión de cero auditoría (`AuditPolicy.php:66`). Hoy
  ninguna ruta lo usa y ningún test lo pincha.

**Directorio del controlador: decisión abierta y menor, resuélvela y dilo.** El árbol tiene las dos formas, y
`docs/architecture-api.md:103` afirma sólo una y ya es falso. Recomendación: `Infrastructure/Http/`, porque
aquí no hay Resource DTO ni mapper (el cuerpo es binario) y `Http/` describe mejor "el adaptador HTTP del
módulo". Lo que no vale es elegir en silencio: cambia el `resource:` y la frase de la doc.

### Estado actual medido del módulo (`202767ab`)

28 ficheros bajo `api/src/Shared/Images/`. Lo que esta historia consume:

| Pieza | Firma / hecho | Nota para 1.3 |
|---|---|---|
| `Domain/ImageId` | ctor **privado** con `\strtolower($value)` (`:34`); `fromString()` llama `Uuid::ensure()` (`:45-50`) | El 400 de la AC 2 y la normalización de la AC 27 |
| `Domain/Entity/Image` | `final readonly`, 7 campos; `id()`, `digest()`, `mediaType()`, `width()`, `height()`, `byteSize()`, `createdAt()` | `digest()` es el `ETag`; `mediaType()` el `Content-Type` |
| `Domain/Repository/ImageRepository` | `findById(ImageId): ?Image` — `null` es ausencia **confirmada**; un fallo de BD lanza | El 404 de la AC 3 |
| `Domain/Storage/ImageStorage` | `store` · `read` · `delete` | **Cerrado. No añadas `exists()`** |
| `read()` | devuelve la **cadena completa**; `@throws` las tres; **no** verifica digest | La verificación de la AC 7 es tuya |
| `StorageFailureCategory` | `ConfirmedAbsence` / `Transient` / `Permanent`; docblock `:13-15` lo declara veredicto sobre el **substrato** | **No le añadas integridad** (AC 8) |
| `FlysystemImageStorage::read()` | `guardRootIsUsable()` en `:139`, `objectExists()` en `:141`, `report(new ImageBytesNotFound())` en `:142`; `emit()` en `:388-406` (`info` `:397`, `warning` `:402`) | Los tres productores de la AC 9 |
| Excepciones del módulo | **ninguna** extiende `Shared\ErrorContract\…\DomainException` ni lleva marcador | Por eso la AC 6 traduce encima en vez de marcar |

Y lo que **no** existe, medido: ningún controlador en `api/src/Shared/`; ningún recurso de routing para
`src/Shared/`; ningún caso de uso de lectura; ninguna feature Behat ni **ninguna fixture** de imagen; ningún
responder de cuerpo binario (`Shared/Http/Infrastructure/Responder/` tiene cuatro ficheros pero **dos**
responders — los otros son la interfaz y un DTO de paginación); y **ningún llamante en producción de
`ImageStorage::read()`**.

### La traducción de fallo → status, tabla cerrada

| Origen | Veredicto | Status | Marcador | Sentry |
|---|---|---|---|---|
| `findById() === null` | fila ausente | `404` | `NotFound` (en excepción nueva del módulo) | no |
| `ImageBytesNotFound` | `ConfirmedAbsence` | `404` | `NotFound` (traducido) | no |
| digest ≠ `Image::digest()` | integridad | `500` | ninguno | **sí** |
| `ImageStorageUnavailable` | `Transient` | `503` | `ServiceUnavailable` (traducido) | **sí** |
| `ImageStorageFailed` | `Permanent` | `500` | ninguno | **sí** |
| `Uuid::ensure()` falla | — | `400` `invalid-uuid` | `InvalidInput` | no |

Tres filas que hay que saber defender:

- **La traducción es obligatoria, no estilística.** El marcador sólo se lee dentro del brazo
  `instanceof DomainException`; una clase del puerto con marcador saldría 500.
- **El fallo permanente no lleva marcador.** `503` le dice al cliente "reintenta"; `ENOSPC` y una raíz ausente
  no se arreglan reintentando.
- **Integridad y permanente comparten status y `type`.** Deliberado: para el cliente ambos son "roto, no
  reintentes". Se separan en la señal, no en el cable — y la AC 6 lo dice en vez de prometer tres resultados
  distinguibles que no lo son.

### Los productores de log del camino de lectura

`read()` no tenía llamante en producción. Los tres productores y su ruido relativo están en la Task 0. Lo que
importa aquí es la corrección: **la premisa heredada era falsa**, y la razón por la que sobrevivió tres
documentos es que nadie la midió contra el orden de resolución. El sumidero sí es como se describía: canal
`observability` fuera del `fingers_crossed`, siempre encendido a `php://stderr`, acotado sólo por volumen
(50 MB, compartidos con el handler por defecto, `deprecation` y el access log de Caddy) y sin TTL ni dueño de
erasure.

### ETag, 304 y la doble puerta

El `ETag` deriva del `digest`, y merece una frase porque el ADR dice que el digest *"se vuelve irreversible el
día que entra en una URL"* (`:90`): un `ETag` **no** es una URL — no direcciona, no es enlazable, no crea
`/{imageId}/{hash}/…`. Es el uso del digest **como atributo** que el invariante 2 permite (`:109`).

La doble puerta del controlador retirado era `existsAny && exists`. Aquí la primera mitad es `findById()` y la
segunda **no puede ser un `exists()`** (A-6). Coste declarado: un `304` cuesta la misma E/S que un `200`.

### NFR6: qué añade el scan sobre deptrac, medido

Ver Task 9. En una línea: deptrac ya cubre media docena de tipos de `Vendor.Symfony`, y no puede cubrir la
granularidad por módulo, el eje valor entero, ni `Psr\Http\Message\*` — que admite explícitamente.

### Registros y gates que morderán

| Registro / gate | ¿Toca? | Por qué |
|---|---|---|
| `api/config/routes.yaml` | **Sí, obligatorio** | Sin recurso el `#[Route]` no registra nada y todo queda verde |
| `api/config/packages/nelmio_cors.php` | **Sí** | AC 24; sin ello el cache condicional es inutilizable desde un origen cruzado y falla en silencio |
| `api/.artifact-gate-placement` · `php.lint.gate-placement` | **Sí** | El scan de NFR6, `mirrored :: src/Shared/Images`. Fuera del *home*, un gate con `#[CoversClass]` **no entra en el universo** y su línea se queda huérfana → rojo |
| `api/.behat-step-vocabulary` · `php.lint.step-vocabulary` | **Sí, muy probablemente** | No existe step que capture una cabecera de respuesta; y todo `idle` alcanzado pasa a `used` en el mismo commit |
| `AuditPolicyTest` (data provider) | **Sí** | La rama `shared_` no la pincha nadie |
| `StorageFailureVocabularyTest` | **Sí, pero no como se pensaba** | **No** por añadir un caso al enum (eso lo pone rojo, AC 8), sino porque hay que dejarlo verde |
| `BestEffortReportChannelGateTest` | **Sí** | El finder entra en `REPORTERS` automáticamente al loguear, y queda atado al canal por `services.yaml` (deptrac refusa el atributo en `Application/`) |
| `pwa/tests/client-minted-problem-types.test.ts` | **Sí, indirectamente** | Barre `api/src`; un `type` nuevo que colisione rompe en `make pwa.quality` |
| `PRODUCTION_SECURITY_CHECKLIST.md` §7 | **Sí, obligatorio** | Cambio sensible a seguridad. Ampliar `:1534-1579` |
| `docs/api-error-contract.md` | **Sólo si minas un `type`** | La tabla marcador→status no gana fila. La mitad del gate que lo vigila está acotada a `MARKER_DIRECTORY` |
| `api/.audit-resource-types` | **No** | Sin `_audit_resource_type` (AC 20), `Image` no entra en el universo |
| `api/.person-reference-policy` | **No** | `Image::$id => non-person` ya está (`:219`), con comentario que pre-empt la pregunta (`:215-218`). **No retipes la columna** |
| `api/.persistent-transport-policy` | **No** | `:74`. Sólo mordería si la señal se emitiera como mensaje — es un `LoggerInterface` |
| `api/.bounded-context-allowlist` | **No** | `Erpify\Shared\…` siempre importable |
| `api/.event-dispatch-allowlist` · `php.lint.event-bus` | **No** | Barrido `*/Application/`; sí mordería si el finder alcanzara un manager de Doctrine |
| `api/.project-context-versions` | **No, salvo paquete nuevo** | El cache condicional es `Response::setEtag()`/`isNotModified()`, ya en el árbol |
| `api/tools/deptrac/deptrac.yaml` | **No para el controlador** | `Shared.Infrastructure` colecciona a cualquier profundidad y admite `Vendor.Symfony`. **Sí importa para `Application/`**: ver Task 5 |
| `api/.public-access-exemptions` · `php.lint.public-access` | **No, montando bajo `/api/v1`** | La catch-all cubre. Precisión: ese gate **sí lee las fuentes de routing** además de `security.yaml`; lo que no hace es evaluar si una ruta cae bajo la catch-all |
| tags `@accepted-risk #N` · `php.lint.accepted-risk` | **Decide** | **No hay fichero `api/.accepted-risk`**: son tags en `src` y specs, leídos por `AcceptedRiskTagGateTest` más un workflow de estado vivo que exige la issue **abierta** |

### Naming

- Puertos por **capacidad**, adaptador `<Tecnología><Puerto>` — ya fijado por 1.1 y 1.2.
- Caso de uso de lectura → `Application/<Noun>Finder` (`docs/rules/cqrs-naming.md:88`). **No** inventes
  `ReadImage`: la categoría 6 (`Upload`) es para ingestión de bytes externos, no para lectura. Una categoría
  nueva se añade **con argumento de principio / objetivo / coste**.
- Controlador: uno por operación, `<Nombre>Controller`.
- Dobles: `InMemory<Puerto>` si es implementación usable, `Stub<Puerto>` si devuelve un valor fijo.
- Las **tres nociones de MIME** de 1.1 siguen sin ser intercambiables: *declarado*, *detectado*, *canónico*. El
  `Content-Type` es el **canónico**, y no se vuelve a inspeccionar ningún byte.

### Reutilización — no reinventar

- **ETag / `If-None-Match`**: `isNotModified()` de `08f8199^`, renombrado. **No** `applyHeaders()`.
- **Identidad**: `Uuid::ensure()` vía `ImageId::fromString()`; no dupliques la validación en el controlador.
- **Contrato de error**: `ProblemDetailsFactory` y los nueve marcadores de
  `api/src/Shared/ErrorContract/Domain/Exception/`.
- **`#[MapUploadedFile]`**: el test funcional **ya existe** (Task 10). No escribas otro.
- **Observabilidad**: canal `observability` ya existente. **Nada de infraestructura de métricas nueva.** Ojo al
  cableado: `#[Autowire]` sólo en `Infrastructure/`; en `Application/` va por `services.yaml`.
- **Behat**: `HttpRequestContext` / `JsonNodeContext`; los steps de cabecera existen y están `used`.
  `JsonErrorContext` **no** sirve (envelope legacy, siete steps `idle`).
- **No cuentes con `PrivacyContext`**: es vacuo si el escenario no siembra identidades, y su consulta une
  `audit_log.metadata` contra `identity_user`, así que no puede ver un `ImageId`. No compra nada aquí.

### Testing

- Árbol espejo: `api/tests/Unit/Shared/Images/{Domain,Application,Infrastructure}/`. Funcionales en
  `api/tests/Functional/Shared/Images/`, contra **Postgres real**, extendiendo `KernelTestCase`; tres de los
  cuatro existentes abren transacción a mano y hacen `rollBack()` en `finally` (`ImageStorageWiringTest` no,
  porque su sujeto es el volumen).
- **El comportamiento HTTP va a Behat.** `api/CLAUDE.md:12` lo dice como `**PHPUnit** + **Behat** for tests.
  (Behat preferred)` — sin más cualificación; la preferencia por Behat para HTTP es el criterio de esta
  historia, apoyado en que un funcional de kernel no ve cabeceras que ponen listeners de `kernel.response`.
- Gates artefactuales: kernel-free, `#[CoversNothing]`, motor en `api/tests/Support/`.
- Por fichero: `declare(strict_types=1)`, `/** @internal */`, `#[CoversClass(...)]`, `final class …Test`,
  métodos con nombre de frase larga. Selección: `make php.unit c='--filter NombreDeClase'`.
- **Afirma la siembra antes que la ausencia.**
- **Trampas medidas en 1.2**: `catch (RuntimeException)` se traga el `$this->fail()`; un doble en memoria no
  falla en escritura parcial; un test que construye el adaptador a mano sobre un tmpdir **no prueba el
  despliegue**.
- **Falsifica cada gate nuevo mutando el código**, y al restaurar **copia los bytes de vuelta**, nunca
  `git checkout --`.

### Matriz AC → test

| AC | Qué prueba | Nivel | Reserva |
|---|---|---|---|
| 1 | Anónimo: mismo status y mismo `type`/`title` para malformado, ausente y existente | Behat `Scenario Outline` `@anonymous` | El cuerpo **no** es idéntico (`instance`, `correlation-id`); las formas que el router se come (id vacío, `/` interno, `OPTIONS`) se declaran, no se testean |
| 2 | 400 `invalid-uuid` antes de cualquier lookup | Behat + unit **del controlador** | El "antes de" **no** se puede probar en el finder: su firma es `find(ImageId)`, así que el id ya es válido cuando llega. El doble que falla si lo llaman va en el test del controlador |
| 3 | Id válido ausente → 404 Problem Details | Behat | — |
| 4 | Bytes, `Content-Type` canónico, `Content-Length`, `nosniff` | Behat + unit | Que el `Content-Type` no se infiera se prueba en unit: fila con `mediaType` que **no** coincide con los bytes |
| 5 | Fila presente + bytes ausentes → 404 | Funcional con storage real | — |
| 6 | Cada veredicto da su status **y su `type`** | Unit (la excepción que sale del caso de uso) **+ funcional/Behat (el `type` en el cable)** | El `type` lo mina `ProblemDetailsFactory` y lo escribe `ExceptionResponder`, un listener de `kernel.exception`: un unit kernel-free no puede verlo. Precedente: las rutas `/api/test/_throw-*` |
| 7 | Digest desajustado no se sirve | Unit con storage que devuelve bytes alterados | "No se comprometen cabeceras antes" es **estructural** (retorno completo), no testeado — dicho, no fingido |
| 8 | Cuatro categorías distintas; ningún valor prohibido **como valor** | Unit con `RecordingLogger`, serializando el contexto | — |
| 9 | La cota existe sobre los tres productores y quitarla pone rojo | Unit | — |
| 10 | `Range`/`If-Modified-Since`/`If-Match` ignorados; sin `Accept-Ranges` ni `Last-Modified`; `HEAD` sirve | Behat | — |
| 11 | Directivas `private`/`max-age`/`immutable` **presentes** | Behat (`should contain`) + unit | **No** afirmes la cadena completa: `ksort` la reordena y `AbstractSessionListener` añade `must-revalidate`. Un test extra que falle si aparece `public` |
| 12 | Ninguna firma acepta path/URL/key, controlador incluido | Scan de AC 17 **con `Infrastructure/Http/` en su alcance** | Si el scan sólo barre `Application/`, esta fila no está cubierta |
| 13 | ETag del digest, fuerte; 304 sólo si recuperable; **el 304 lleva sus cabeceras**; las tres formas y `*` | Unit (formas) + Behat (304 y sus cabeceras) + funcional (304 negado sobre objeto ausente) | El "304 negado" necesita fila viva con bytes borrados: constrúyelo |
| 14 | Offset 0 | **Ninguno ejecutable** — no hay stream | Nota de diseño |
| 15 | Conocer el id no basta sin sesión | Behat | `?_rsc=1` no es observable: Caddy lo desvía a la PWA antes de la API. La conclusión aguanta; la cobertura de "cualquier parámetro" no |
| 16 | Guard cubierto por la vía real | **Test existente**, citado | No se escribe nada nuevo |
| 17 | Scan de dos ejes + falsabilidad ante rename | Gate + gate de reglas | — |
| 18 | La ruta existe bajo `/api/v1` y exige sesión | Behat (401 anónimo) | El 401 prueba registro **y** cobertura del firewall a la vez. `debug:router` es diagnóstico, no test — fuera de la matriz |
| 19 | Un 200 no deja filas; la rama `shared_` pinchada | Behat (SQL, steps `used`) + caso nuevo en `AuditPolicyTest` | — |
| 20 | Los `defaults` **igualan** el conjunto esperado | Unit estructural | Enumerar ausencias dejaría pasar un tercer default futuro |
| 21 | Id malformado llega al controlador (400, no 404) | Behat | — |
| 22 | Cuatro residuales escritos | Revisión de doc | — |
| 23 | Feature + **fixture**, cinco casos, vocabulario al día | `make php.behat` + `php.lint.step-vocabulary` | Sin el mecanismo de fixture sólo son construibles 401/400/404 |
| 24 | CORS admite `If-None-Match` y expone `ETag` | **Verificación manual** | Ningún test del repo pasa por Caddy ni por un navegador |
| 25 | Límites medidos, vetting registrado | Medición escrita | — |
| 26 | Nota de "prueba de infraestructura" | Revisión de doc | — |
| 27 | Mayúsculas → mismos bytes y mismo ETag | Behat o funcional | Falsifícalo quitando el `strtolower` |

Filas sin test ejecutable que observe el fallo de su AC: **7 (parcial), 14, 22, 24, 25, 26** — marcadas.
**Re-deriva esta matriz al terminar de implementar**: la de 1.2 se escribió antes y el code review encontró
ocho filas que reclamaban lo que su test no podía ver.

### Project Structure Notes

```
api/src/Shared/Images/
├── Application/
│   └── CanonicalImageFinder.php          (NUEVO — lookup + read + verificación + señal de integridad)
├── Domain/Exception/
│   └── …                                 (NUEVO — traducción a DomainException + marcador, AC 6)
└── Infrastructure/
    ├── Http/ImageGetController.php        (NUEVO — o Controller/, decide y dilo)
    ├── Http/HttpCacheValidator.php        (NUEVO — isNotModified() rescatado y renombrado)
    └── FlysystemImageStorage.php          (MODIFICADO — cota de los productores de lectura)

api/config/routes.yaml                     (MODIFICADO — recurso del módulo, prefix /api/v1)
api/config/packages/nelmio_cors.php        (MODIFICADO — If-None-Match / ETag)
api/config/services.yaml                   (MODIFICADO — canal del finder; + parámetro si Task 0 lo pide)
api/.artifact-gate-placement                (MODIFICADO — líneas mirrored del scan)
api/.behat-step-vocabulary                  (MODIFICADO — step nuevo y/o idle→used)

api/features/shared/images/*.feature        (NUEVO)
api/tests/DataFixtures/…                    (NUEVO — la fixture de imagen, Task 11)
api/tests/Support/…                         (NUEVO — motor del scan de NFR6)
api/tests/Unit/Shared/Images/…              (NUEVO — scan + falsabilidad + unit del finder y del controlador)
api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php  (MODIFICADO — caso shared_)
api/tests/Functional/Shared/Images/…        (NUEVO — 304 negado)

docs/adr/image-read-failure-signal-bound.md (NUEVO — la excepción argumentada de la AC 9)
docs/index.md                               (MODIFICADO — entrada del ADR)
_bmad-output/planning-artifacts/epics-images.md  (MODIFICADO — AC de la señal, supersedida hacia el ADR)
docs/architecture-api.md                    (MODIFICADO — ruta nueva + corregir :103)
api/docs/adding-endpoints.md                (MODIFICADO)
api/docs/postman/…                          (MODIFICADO)
PRODUCTION_SECURITY_CHECKLIST.md            (MODIFICADO — ampliar §7 :1534-1579)
```

`api/config/services.yaml` excluye `'../src/**/Domain/Entity/'` del contenedor; nada de lo que añades cae ahí.
Y **no inventes un responder de cuerpo binario**: un `Response` con la cadena y sus cabeceras basta, y un
responder para un solo llamante es abstracción prematura.

### Fuera de alcance — no lo construyas aquí

Endpoint de subida de producción · ruta desechable para `#[MapUploadedFile]` (Task 10 explica por qué) ·
`Bank.logoImageId` / `User.avatarImageId` · auditoría de la ruta · voter de ownership · variantes y su URL ·
`Range` real · streaming · deduplicación · refcount · GC · content-addressed storage · adaptador S3 · event
sourcing sobre `Image` · contexto `Documents` · el campo de origen de derivada del ADR D5 · rate limiting por
identidad · reconciliación fila↔objeto (la prohíbe NFR3) · `ContentHashUrlGenerator`.

### References

- [`_bmad-output/planning-artifacts/epics-images.md`](../planning-artifacts/epics-images.md) — corte de la
  Story 1.3 `:739-868`; alcance `:45-57`; pase adversarial `:68-173`; RESIDUAL-1 `:137-140`; MEDIA-7 `:158-161`;
  gotcha EOF `:371-373`; mutable/inmutable fuera de alcance `:374-378`; esquema mínimo `:420-424`; decision
  firewall `:442-454`; promesa de Behat `:486-487`; verified read `:782-787`; observabilidad `:789-792`; cota
  del log `:794-803`; `Range` `:805-809`; `Cache-Control` `:811-816`; cadena `:818-821`; ETag `:827-831`; EOF
  `:833-837`; `#[MapUploadedFile]` `:839-844`; NFR6 `:846-852`; prueba de infraestructura `:854-858`; residual
  de enumeración `:860-868`; NFR9 `:297`.
- [`docs/adr/images-vs-documents-conservation-contract.md`](../../docs/adr/images-vs-documents-conservation-contract.md)
  — D6 `:70` (las dos cláusulas que el épico anula), digest en URL `:90`, invariante 2 `:109`.
- [`docs/adr/image-read-failure-signal-bound.md`](../../docs/adr/image-read-failure-signal-bound.md) — **la
  decisión de la AC 9**: por qué la señal de fallo de lectura se deja sin acotar, las cuatro alternativas
  descartadas con su razón, y qué **no** cierra. Supersede la AC del épico, que queda enmendada apuntando ahí.
- [`docs/adr/image-deletion-signal-transport.md`](../../docs/adr/image-deletion-signal-transport.md) — D3, los
  residuales que hereda la épica del consumidor. Es también el **precedente de forma**: una story anterior
  escribió un ADR porque un requisito escrito exigía una excepción argumentada, que es exactamente la
  situación de la AC 9.
- [`docs/api-error-contract.md`](../../docs/api-error-contract.md) — tabla marcador→status.
- [`docs/rules/cqrs-naming.md`](../../docs/rules/cqrs-naming.md) — `:88`, caso de uso de lectura; categoría 6.
- [`docs/rules/testing.md`](../../docs/rules/testing.md) — dónde vive un artifact gate; `--filter` como
  cableado.
- [`docs/rules/security.md`](../../docs/rules/security.md) — el texto de una excepción llega a
  `messenger_messages` vía `ErrorDetailsStamp`.
- `PRODUCTION_SECURITY_CHECKLIST.md:1534-1579` — el bloque que esta historia amplía; §7 empieza en `:765`.
- `api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php:113-123` (mapa), `:266` (el `match`),
  `:351-357` y `:494` (por qué la AC 6 traduce).
- `api/src/Shared/Audit/Domain/AuditPolicy.php:56,60-68,66` y
  `api/tests/Unit/Shared/Audit/Domain/AuditPolicyTest.php:82-87`.
- `api/config/packages/security.yaml:41-44,73,74` · `api/config/packages/nelmio_cors.php:19,20`.
- `api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/RateLimitListener.php:113-131,156-161`;
  `api/config/packages/rate_limiter.yaml:10-24`; `api/.env:89`; `api/.env.test:18`.
- `api/tests/Unit/Gate/ObservabilityChannelGateTest.php:42,85`;
  `api/tests/Unit/Gate/BestEffortReportChannelGateTest.php:63-64,89-93`.
- `api/src/Shared/Images/Infrastructure/FlysystemImageStorage.php:139,141,142,255-271,374-379,388-406`;
  `api/src/Shared/Images/Domain/ImageId.php:34,45-50`;
  `api/src/Shared/Images/Domain/Storage/StorageFailureCategory.php:13-15`;
  `api/tests/Unit/Shared/Images/Domain/StorageFailureVocabularyTest.php:48,50-54`.
- `api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php:17,52,94,333-356`.
- `api/tools/deptrac/deptrac.yaml:302-306` — por qué `#[Autowire]` no entra en `Application/`.
- `api/tests/Functional/Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizerFunctionalTest.php` —
  la evidencia que la AC 16 pedía, ya escrita.
- `vendor/symfony/http-kernel/Controller/ArgumentResolver/RequestPayloadValueResolver.php:253,273-275` — los
  dos resolvers disjuntos. *(Leído en el checkout primario: este worktree no tiene `api/vendor/`.)*
- `vendor/symfony/http-foundation/HeaderBag.php:259` (`ksort`) y
  `vendor/symfony/http-kernel/EventListener/AbstractSessionListener.php:41,203-214` — por qué la AC 11 no
  afirma una cadena literal; precedente vivo en
  `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:139`.
- `api/config/routes/test.yaml` — 16 controladores desechables bajo `/api/test/_throw-*`.
- `docs/architecture-api.md:64,103,110`; `api/CLAUDE.md:12`; `api/docs/adding-endpoints.md`.
- `git show 08f8199^:api/src/Shared/Http/Infrastructure/ContentAddressedHttpCache.php` — `isNotModified()`
  rescatable; `applyHeaders()` **no**.
- `git show 08f8199^:api/src/Shared/Storage/Infrastructure/Controller/StoredObjectGetController.php` — la doble
  puerta y el nombre fósil `shared_stored_object_get`; su `new Response('Not Found', 404)` y su `requirements`
  son las dos mitades que **no** se rescatan.
- [`img-1-2-persistir-imagen-borrado-fiable-de-bytes.md`](img-1-2-persistir-imagen-borrado-fiable-de-bytes.md)
  — contrato del puerto, los tres pases y el defer que crea la AC 9.
- [`img-1-1-subir-imagen-obtener-representacion-canonica.md`](img-1-1-subir-imagen-obtener-representacion-canonica.md)
  — contrato de canonicalización v1 y los diferidos que crean la AC 25.
- [`deferred-work.md`](deferred-work.md) — la bala de mutable/inmutable **no** se toca.

## Change Log

- 2026-08-29 — Historia creada. El análisis midió el árbol en `202767ab` contra el corte del épico y encontró
  catorce desajustes que ninguna AC anticipaba, cuatro capaces de dejar una decisión del épico falsa con todas
  las puertas en verde: la auditoría genérica que se activa por defecto y sólo se desactiva por el **nombre**
  de la ruta; la opción `debug` de la cota de log, descartada por un gate; la ausencia de recurso de routing
  para `src/Shared/`; y el path del épico, fuera del `^/api` del firewall. Corregido RESIDUAL-1.
- 2026-08-30 — **Pase adversarial externo, tres lecturas paralelas sobre el artefacto commiteado.** Cambió el
  resultado en vez de confirmarlo: 34 hallazgos, **cero rechazados**, seis de ellos GRAVE que tumbaron cuatro
  AC y la premisa entera de Task 0. (1) El brazo `503` de la AC 6 era mecánicamente imposible: el marcador sólo
  se lee dentro del brazo `instanceof DomainException`, así que marcar una clase del puerto habría producido
  500 — el mismo resultado que el brazo permanente que la AC decía no conflacionar. (2) La AC 16 emparejaba
  dos tests sobre resolvers disjuntos de Symfony, de modo que el segundo pasaba con el guard borrado, y el
  test correcto **ya existía en el árbol** desde antes de la épica. (3) El literal de `Cache-Control` de la
  AC 11 no es lo que sale por el cable, por `ksort` y por la reescritura de `AbstractSessionListener`. (4) El
  caso de integridad de la AC 8 ponía rojo un test existente y contradecía el invariante del propio enum, sin
  emisor posible donde Task 5 lo colocaba. (5) **Task 0 colgaba de una premisa falsa** heredada del épico y
  del defer de img-1-2 y nunca medida: bajo el orden `findById` → `read` que la propia historia manda, un id
  aleatorio no toca storage y emite cero líneas; los productores reales son otros y más ruidosos, y arrastran
  el flush del `fingers_crossed`. (6) Ninguna fixture puede sembrar una `Image` con bytes, así que siete filas
  de la matriz eran inconstruibles. Además: CORS bloquea el cache condicional que la historia entrega (AC 24
  nueva), las cabeceras del propio `304` no estaban especificadas, y se corrigieron una **cita fabricada**
  atribuida a `api/CLAUDE.md`, cuatro rangos de línea que apuntaban a la AC contigua, un nombre de registro
  inventado (`api/.accepted-risk`) y cinco recuentos inflados. La sección `## Adversarial pass` registra la
  disposición hallazgo a hallazgo, y dice que el gate habría dado verde sobre el pase autoadministrado solo.

- 2026-08-30 — **Task 0 resuelta: la señal de fallo de lectura se deja sin acotar (opción D).** Dos rondas de
  consulta externa más tres mediciones propias. La ronda 1 recomendó D *"provided the observability sink is
  explicitly accepted as an alarm channel"*; medido, esa condición es **falsa** —`stream` plano en los tres
  entornos, handler de Sentry comentado y excluyendo el canal, ningún colector en ningún compose— así que el
  argumento con el que se recomendó no se sostiene. D sobrevive por otra razón: **las cotas disponibles están
  en el punto de control equivocado**, porque un fallo 5xx escribe además un registro `error` en el canal por
  defecto (`ExceptionResponder.php:293`) que activa el handler bufferizado y es inalcanzable desde este
  módulo. La ronda 2 concedió el punto y corrigió el mío: *"we cannot bound everything, therefore bound
  nothing"* no vale como principio general, así que el argumento del término equivocado **rechaza A/B/C pero
  no prueba D por sí solo** — hace falta además el juicio sobre coste unitario y pérdida semántica. Su
  aportación decisiva fue de gobernanza: un requisito escrito no se reinterpreta desde la historia que lo
  incumple, así que la excepción se gobierna con **ADR + AC del épico enmendada + residual**, y no con una
  nota. Se añadió `docs/adr/image-read-failure-signal-bound.md` con las cuatro alternativas descartadas una a
  una (incluido que un cap por petición es un **no-op** medido), se enmendó `epics-images.md` conservando la
  redacción original para que el cambio sea auditable, AC 9 pasó de "acota" a "declara con la medición",
  AC 22 ganó un quinto residual reencuadrado como **shared-sink eviction entre productores independientes**, y
  la medición quedó acotada a **coste por evento** con la declaración explícita de que no mide frecuencia ni
  producción, porque no hay despliegue de producción. P1 se redescribe como invariante rota **permanente** —
  nace de una petición de borrado perdida— en vez de ruido puntual. Cero código.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
