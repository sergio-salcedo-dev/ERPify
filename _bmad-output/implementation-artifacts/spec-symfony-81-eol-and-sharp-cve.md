---
title: 'Symfony 8.1 antes del EOL de 8.0 y sharp fuera del rango vulnerable'
type: 'chore'
created: '2026-07-22'
status: 'in-review'
baseline_commit: '6243f123fb115f8f3ce7178dc25b1cbf3b2eb6c7'
review_loop_iteration: 3
context:
  - '{project-root}/api/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Symfony 8.0 pierde los parches de seguridad en 07/2026 (`symfony.com/releases/8.0.json`: `eom` y `eol` = `07/2026`; último parche `8.0.14`, el que corremos). Aparte, `pwa/package.json` declara `sharp: ^0.35.0` pero instalado hay `sharp@0.34.5` marcado `invalid`, arrastrado por el `optionalDependencies: ^0.34.5` de `next@16.2.10`. 0.34.5 cae dentro de `GHSA-f88m-g3jw-g9cj` (4 CVEs heredados de libvips), cuyo fix es justamente `0.35.0`.

**Approach:** Mover el pin de Flex de la app a `8.1.*`, subiendo con él los pines `8.0.*` del árbol aislado de Behat (ya probado resoluble). Para `sharp`, imponer la línea parcheada con `overrides` en vez de rebajar el rango declarado.

## Boundaries & Constraints

**Always:**
- Todo "verde" sale de una ejecución nueva con su exit code, nunca de logs previos.
- `api/tools/behat/` sigue aislado: no puede arrastrar hacia atrás el árbol de la app.
- La precedencia de autoload entre `api/vendor` y `api/tools/behat/vendor` se **mide**; el comentario que ya hay en `run.php` no vale como fuente.

**Ask First:**
- Si `make php.behat` no queda verde con Behat 3.32, **parar** antes de meter `behat/behat 4.0.0-alpha1`: es la escalada acordada, pero mete un alpha en la cadena de aceptación.
- Si `sharp@0.35.x` rompe el build de la PWA, parar antes de tocar `next.config.ts`.

**Never:**
- No bajar `sharp` a `^0.34.5`: codificaría la vulnerabilidad en el manifiesto.
- No tocar `behat/behat` ni el resto del tooling más allá de los pines `symfony/*`.
- Fuera de alcance: mergear PR #548, desinstalar la app de Semgrep, cerrar falsos positivos en Aikido (no producen diff).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| App a 8.1 | `extra.symfony.require: "8.1.*"` | `composer update "symfony/*"` instala `framework-bundle 8.1.x` | Si algo no se mueve, `composer why-not` |
| Tooling acompaña | pines `8.0.*` → `8.1.*` en el tooling | Resuelve; `console`/`DI`/`config` siguen en 7.4 en ese árbol (techo de `behat/behat 3.32`) | Si no resuelve, HALT |
| Autoload | `run.php` carga app y luego tooling | Se mide qué árbol gana para un FQCN compartido | Si gana el tooling, el pin `8.1.*` pasa de cosmético a obligatorio |
| sharp forzado | `overrides` a `^0.35.0` | `npm ls sharp` sin `invalid`, versión `0.35.x` | Si el build falla, HALT |

</frozen-after-approval>

## Code Map

- `api/composer.json` -- `extra.symfony.require: "8.0.*"` es el pin real de Flex; `symfony/intl: "8.0.*"` es el único `symfony/*` sin caret. El resto ya son `^8.0.x` y de por sí admiten 8.1.
- `api/tools/behat/composer.json` -- seis pines `8.0.*`: `browser-kit`, `http-client`, `css-selector`, `dom-crawler`, `dotenv`, `mime`.
- `api/tools/behat/run.php` -- puente entre los dos árboles de vendor. Comentario de cabecera desfasado: dice "Behat 3.31" y atribuye el techo `^7.x` a DI/Config/HttpKernel; el techo medido es `behat/behat 3.32.0` → `symfony/console`. También afirma que gana el autoload registrado primero.
- `api/CLAUDE.md` -- "Rules that bite" dice que Behat "pins `symfony/*` to `^7` while the app is on 8".
- `pwa/package.json:83` -- `sharp: ^0.35.0`; no hay bloque `overrides`.
- `pwa/next.config.ts:24` -- `output: "standalone"`, motivo de declarar `sharp` directamente.

## Tasks & Acceptance

**Execution:**
- [x] `api/tools/behat/run.php` -- medido: **gana el árbol del tooling** para los cuatro FQCN compartidos probados (`Mime\Email`, `BrowserKit\AbstractBrowser`, `Console\Application`, `DependencyInjection\Container`). El comentario del fichero afirma lo contrario. El pin del tooling es por tanto **obligatorio**, no cosmético.
- [x] `api/composer.json` -- `extra.symfony.require` y `symfony/intl` a `8.1.*`; 31 carets a `^8.1.x`. Instalado: `framework-bundle v8.1.1`.
- [x] `api/tools/behat/composer.json` -- los seis pines a `8.1.*`; reinstalado sin conflicto (`console`/`DI`/`config`/`yaml` siguen en 7.4 dentro de ese árbol, como se esperaba).
- [x] `api/tools/behat/run.php` -- comentario de cabecera reescrito al techo real (`behat/behat` capa `console`/`config`/`DI`/`event-dispatcher`/`translation`/`yaml`/`filesystem`/`process` en `^7.0`) y a la precedencia real (gana el autoload registrado **último**, luego los pines del tooling son load-bearing).
- [x] `api/CLAUDE.md` -- "Rules that bite" corregida: el techo es `behat/behat` sobre `symfony/console`, no un pin global `^7`, y se nombra el acoplamiento de precedencia de autoload.
- [x] `pwa/package.json` -- **sin cambios: ya estaba resuelto en `main`.** El `overrides.sharp: ^0.35.0` y la resolución `0.35.3` del lock entraron en PR #541. Instalación limpia en el worktree da `sharp@0.35.3`. El `0.34.5` sólo existe en el `node_modules` rancio del checkout primario.
- [x] **Multipart** -- **retirado de esta rama.** PR #557 eliminó la superficie de subida: `POST /banks` acepta sólo JSON y rechaza multipart con 415 en el resolver, fijado por `BankCreateAcceptsJsonOnlyFunctionalTest`. `CreateBankRequest` y la traducción en el controlador ya no tienen objeto. Ver Change Log.
- [x] **Invariante de transporte** -- `TransportOnlyUploadedFileDenormalizer` se queda en `Shared/Http/Infrastructure/`, sin consumidor en `src/` pero con su regresión reescrita contra un payload fixture de test que sí declara un `?UploadedFile`.
- [x] **Deprecación de `symfony/mercure-bundle`** -- `0.4.x-dev` (== `main`, commit `28e7502`): ya usa `DependencyInjection\Extension\Extension`. `failOnDeprecation="true"` se queda intacto.
- [x] **Migración a Behat 4 hecha en esta rama (decisión de Sergio, 2026-07-27)** -- `behat/behat 4.0.0-alpha1` levanta el techo `^7`, así que el árbol aislado desaparece. Ver Change Log.

**Acceptance Criteria:**
- Dado el árbol tras `composer update "symfony/*"`, cuando se consulta `composer show symfony/framework-bundle`, entonces la versión es `8.1.x`. -- **CUMPLE** (`v8.1.1`).
- Dado `pwa/`, cuando se ejecuta `npm ls sharp`, entonces no hay `invalid` y la versión es `0.35.x`. -- **CUMPLE** (`0.35.3`, cero diff en `pwa/`).
- Dada la suite PHP en frío, cuando se ejecutan `make php.unit`, `make php.stan`, `make php.deptrac` y `make php.quality`, entonces los cuatro salen con exit code 0. -- **CUMPLE** (2066 tests / 9045 aserciones; 0 errores; 0 violaciones).
- Dado un cuerpo de petición que *describe* una subida (`path` + `test: true`), cuando el serializador del contenedor lo mapea a un payload con miembro `?UploadedFile`, entonces se rechaza sin leer la ruta nombrada, y una ruta inexistente se rechaza en los mismos términos (sin oráculo de existencia). -- **CUMPLE** (`TransportOnlyUploadedFileDenormalizerFunctionalTest`).
- Dado el árbol único, cuando se ejecuta `make php.behat`, entonces exit code 0. -- **CUMPLE**: 373 escenarios, 3378 pasos, todos verdes.

## Design Notes

El techo que bloquea 8.1 **no es** el que registraba el backlog. Medido con `composer update --dry-run` forzando `8.1.*` en contenedor limpio:

```
behat/behat v3.32.0 requires symfony/console ^5.4.9 || ^6.4 || ^7.0
  -> found [...v7.4.14] but it conflicts with your root composer.json require (8.1.*)
```

`friends-of-behat/symfony-extension` está en **2.7.0** (`symfony/dependency-injection: ^7.4 || ^8.0`): ya no bloquea. El techo es `behat/behat` sobre `symfony/console`, y sólo afecta al árbol de tooling. Por eso la app puede ir a 8.1 sin tocar `behat/behat`: `console`, `dependency-injection`, `config` y `event-dispatcher` se quedan en 7.4 **dentro de `api/tools/behat/vendor`**, igual que ya ocurre hoy con la app en 8.0.14.

Escalada si no aguanta: `behat/behat 4.0.0-alpha1` (acepta `symfony/console ^8.0`; `symfony-extension 2.7.0` ya admite `behat ^4.0`) — previa aprobación.

## Verification

**Commands:**
- `make composer c='update "symfony/*" --with-all-dependencies'` -- expected: exit 0, `framework-bundle` en `8.1.x`
- `make php.behat.install` -- expected: exit 0, sin conflicto
- `make php.stan` / `make php.quality` / `make php.unit` / `make php.behat` -- expected: exit 0 cada uno
- `npm ls sharp --prefix pwa` -- expected: exit 0, `0.35.x`, sin `invalid`
- `make pwa.quality` -- expected: exit 0

**Manual checks:**
- Confirmar en Aikido que el hallazgo de `sharp` desaparece tras el siguiente escaneo y que el de Symfony EOL se cierra al llegar 8.1.

## Spec Change Log

### 2026-07-23 -- reconciliación con `main` tras #557: el multipart sale del alcance

`main` avanzó seis commits bajo la rama. El decisivo es **#557 — `chore(api): remove the image upload surface`**, que borra `Shared/Media`, `Shared/Storage`, las dos features de Behat, el fixture `minimal-logo.png` y los miembros de fichero de `Bank`, y deja `POST /banks` en JSON puro: `#[StrictRequestPayload(acceptFormat: ['json'])]`, con `BankCreateAcceptsJsonOnlyFunctionalTest` fijando que un multipart o un `form` se rechazan con **415 antes de que corra handler alguno**.

Eso deja sin objeto el problema que motivaba dos de los cuatro commits de esta rama. El cambio de comportamiento de 8.1 (`mapRequestPayload()` fusiona `$request->files` en el payload antes de denormalizar) sólo muerde a un endpoint que acepte `form`; ya no existe ninguno. Retirados en el merge:

- `Backoffice/Bank/Infrastructure/Http/CreateBankRequest` y la traducción a `CreateBankCommand` en el controlador -- el controlador vuelve a la versión de `main`.
- `BankLogoMultipartFunctionalTest` -- borrado en `main` junto con la superficie que ejercitaba.

Se conserva `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer` (decisión de Sergio). Hoy no tiene consumidor en `src/`, pero la invariante que defiende — *una subida llega por `$request->files` o no llega* — es precisamente la que se perdió sin que nadie lo notara cuando desaparecieron los `#[MapUploadedFile]`, y la épica de subidas volverá. Su regresión se reescribe: en vez de atacar `POST /banks`, denormaliza a través del **serializador del contenedor** hacia `Fixtures\UploadBearingPayload`, un DTO de test que declara `?UploadedFile`. Conducirlo por el contenedor y no por la clase aislada es deliberado — un test unitario del denormalizador sigue verde cuando el servicio ya no está en la cadena de normalizadores, que es la forma más probable de perder la protección.

Conflictos y su resolución:

| Fichero | Conflicto | Resolución |
|---|---|---|
| `api/CLAUDE.md` | ambos lados reescribieron viñetas adyacentes | ambos: la de Semgrep y la de regresión upstream de `main`, más la corrección de precedencia de autoload de Behat de esta rama |
| `api/composer.lock` | 8.1 aquí vs. `doctrine-bundle 3.3.1` + retirada de `flysystem`/`intervention` en `main` | regenerado desde el `composer.json` fusionado (`composer update "symfony/*" symfony/mercure-bundle --with-all-dependencies`) |
| `BankPostController` | JSON-only en `main` vs. multipart aquí | versión de `main` |
| `BankLogoMultipartFunctionalTest` | borrado en `main`, modificado aquí | borrado |

Sin cambios en lo que sí sigue siendo el objeto de la PR: el pin de Flex a `8.1.*`, los seis pines del árbol de Behat, `mercure-bundle` en `0.4.x-dev` y la corrección de `run.php`. El bloqueo por la migración a Behat 4 sigue vigente.

### 2026-07-23 -- reparación del artefacto y verificación en fresco

El fichero venía **truncado** de una escritura interrumpida: terminaba en un `</content>` suelto y
referenciaba esta sección, que no existía. Reparado.

**El "verde" anterior no estaba medido.** Ejecutado en fresco sobre el worktree:

- `composer show symfony/framework-bundle` -> `v8.1.1`. Confirmado.
- Precedencia de autoload entre los dos árboles de vendor, remedida con `ReflectionClass` sobre los
  cuatro FQCN compartidos (`Console\Application`, `DependencyInjection\Container`, `Mime\Email`,
  `BrowserKit\AbstractBrowser`): los cuatro resuelven a `api/tools/behat/vendor/`. **Gana el árbol
  del tooling**, pese a registrarse el autoload de la app primero.
- Techo del tooling reconfirmado: `behat/behat` capa `symfony/console|config|dependency-injection|
  event-dispatcher|translation|yaml|filesystem|process` en `^7.0`; `symfony/console` resuelve a
  `v7.4.14` dentro de ese árbol. `friends-of-behat/symfony-extension 2.7.0` no bloquea.
- `make php.unit` -> **exit 1**. `Tests: 2064, Assertions: 8987, Failures: 4`. El spec daba la suite
  por verde sin haberla corrido.

### Regresión de multipart en Symfony 8.1 (bloqueante, decisión abierta)

Las 4 caídas tienen **una sola** causa raíz.
`RequestPayloadValueResolver::mapRequestPayload()` en 8.1 mezcla los ficheros en el payload de forma
incondicional antes de denormalizar:

```php
$data = $this->mergeParamsAndFiles($data, $request->files->all());
```

En 8.0 las partes de fichero viajaban en `$request->files` y nunca llegaban al payload -- invariante
que el propio test documentaba: *"The file part rides `$request->files` and never reaches the
extra-attribute check, so the upload survives strict mapping."*

Como `StrictRequestPayload` fuerza `ALLOW_EXTRA_ATTRIBUTES => false` y `CreateBankCommand` (capa
Application) no declara `image`/`storedObject`, **todo POST multipart a `/banks` responde 422**. No es
un fallo de tests: el endpoint está roto para clientes reales.

**Vía descartada por medición.** La propuesta de inyectar `AbstractNormalizer::IGNORED_ATTRIBUTES`
(por call site o vía un parámetro nuevo en `StrictRequestPayload`) **no funciona**: `isAllowedAttribute()`
devuelve `false` para un atributo ignorado, y esa es exactamente la rama de
`AbstractObjectNormalizer::denormalize()` que alimenta `$extraAttributes[]`. Sonda contra 8.1.1:

```
ALLOW_EXTRA=false only                       => THREW ExtraAttributesException ("storedObject" is unknown)
ALLOW_EXTRA=false + IGNORED=[storedObject]   => THREW ExtraAttributesException ("storedObject" is unknown)
```

Nota de alcance: bajo 8.0 este control **nunca** cubrió ficheros -- una parte de fichero no mapeada
(`curl -F bogus=@x.png`) se ignoraba en silencio. Restaurar la semántica 8.0 no degrada el control
respecto a lo que ya iba en producción.

### 2026-07-23 -- multipart resuelto (Option F), y Behat sale a PR propia

**Multipart.** Consultadas tres fuentes independientes. Una IA externa propuso inyectar
`IGNORED_ATTRIBUTES` desde un parámetro nuevo en `StrictRequestPayload`: **descartado por medición**
(ver entrada anterior -- no suprime la excepción, la provoca). Amelia descartó además la vía de
filtrar en `UnknownPayloadMemberListener`: es un listener de `kernel.exception`, el controlador ya
no corrió, y dejar el array de extras vacío hace que el listener no sustituya el throwable -- la
`ExtraAttributesException` cruda llega a `ExceptionResponder` y sale **500**, no 201. Habría
convertido 3 de los 4 fallos de 422 en 500.

Implementado lo que Winston y Amelia proponen por separado: un **request DTO en Infrastructure**.

- `api/src/Backoffice/Bank/Infrastructure/Http/CreateBankRequest.php` (nuevo) declara `name`,
  `shortName`, `?UploadedFile $image` y `?UploadedFile $storedObject`.
- `BankPostController` recibe un único argumento y traduce a `CreateBankCommand`; desaparecen los
  dos `#[MapUploadedFile]`.
- `api/src/Shared/Http/` **no se toca**: la política de strictness compartida no se debilita para los
  otros call sites.

Sonda contra 8.1.1 antes de escribir código:

```
declared file part lands on the DTO    => OK. image=UploadedFile(logo.png)
UNdeclared file part still rejected    => THREW ExtraAttributesException ("bogus" is unknown)
```

El contrato de ficheros pasa de *inferido* a *declarado*: conserva el endurecimiento de 8.1 en vez de
revertirlo. Precedente en el repo: los cuatro payloads de `Iam/**/Infrastructure/Http/`; Bank era el
outlier que mapeaba directo sobre el comando de Application.

**Cambio de contrato observable, a nombrar en el PR:** una parte de fichero no declarada pasa de
ignorarse en silencio (201) a 422. Cubierto por test nuevo. `BankPutController` usa
`StrictRequestPayload` sin ficheros, así que un PUT multipart con cualquier parte de fichero también
pasa a 422 -- no se toca aquí.

**Behat: bloqueante estructural, diferido a PR propia.** `make php.behat` -> exit 255, el kernel no
arranca. Symfony 8.1 movió la maquinaria de kernel a `symfony/dependency-injection/Kernel/`
(`AbstractKernel`, `KernelTrait`, `ServicesBundle`), namespace que no existe en el árbol del tooling
porque `behat/behat` capa DI en `^7.0`. Medidas **ambas** órdenes de autoload:

| Orden | Resultado |
|---|---|
| Tooling gana (actual) | `ServicesBundle::getNamespace()` undefined |
| App gana (invertido) | `Extension::process()` incompatible con `CompilerPassInterface::process(): void` |

Ninguna combinación de pines lo arregla: `behat/behat 3.32` es incompatible **a nivel de fuente** con
Symfony 8 DI. El puente de dos árboles sobrevivió a 8.0 sólo porque el kernel de la app no necesitaba
el `Kernel/` de DI.

`behat/behat 4.0.0-alpha1` **sí** resuelve e instala (medido, luego revertido): sube el tooling entero
a Symfony 8.1.1, lo que elimina la causa raíz y de paso el desfase `symfony/http-kernel v8.0.14` del
lock del tooling -- desfase que hacía que bajo Behat corriera el resolver de 8.0 **sin**
`mergeParamsAndFiles`, de modo que Behat habría salido verde con el bug de multipart intacto. Pero
Behat 4 cambia la config de YAML a PHP: 54 líneas, 21 contexts en orden significativo, 49 features, y
un delta sin medir en la API de steps y en la compatibilidad de `symfony-extension`.

Decisión: **esa migración va en su propia PR, y esta rama no se mergea hasta que exista.** No se toca
`ci.yml`: `api-behat` sigue gating y seguirá rojo, que es la señal honesta. Nota para esa PR: las
features `features/backoffice/bank/create_with_logo.feature` y `create_with_stored_object.feature`
están enteramente comentadas, así que hoy la única red del endpoint multipart es PHPUnit.

**Propuestas NO incluidas en este diff** (candidatas a issue): Symfony 8.1 trae
`DenormalizerInterface::COLLECT_EXTRA_ATTRIBUTES_ERRORS`, que produce nativamente el mismo 422 y
haría redundante `UnknownPayloadMemberListener` (-93 líneas), a cambio de cambiar el texto de la
violación y tocar `docs/api-error-contract.md`.

### 2026-07-23 -- el review adversarial encontró un fallo CRÍTICO en la propia Option F

Blind Hunter y Edge Case Hunter, en contextos separados y sin hablarse, convergieron en el mismo
hallazgo. Confirmado por reproducción propia.

**Declarar `?UploadedFile` en el DTO lo convirtió en miembro denormalizable, y `UploadedFile` es
construible desde el cuerpo de la petición.** `path` y `originalName` son parámetros de su constructor,
y `test: true` hace que `isValid()` responda true sin consultar `is_uploaded_file()` -- que es el único
corto-circuito de `FileValidator`. Como el endpoint acepta `json`, no hacía falta ni multipart:

```
F1 CONFIRMADO: se construyó un UploadedFile desde JSON
  pathname : /etc/hostname   isValid(): true   contenido: bdbcb0344c9b
```

Cualquier principal con `bank.write` podía leer **cualquier fichero del contenedor** que oliera a
JPEG/PNG/WebP y cupiera en `%erpify.media.max_upload_bytes%`, quedando persistido y servido en
`logoUrl`. Y una ruta inexistente lanzaba `FileNotFoundException`, sin marcador de error-contract, luego
**500** -- un oráculo de existencia del sistema de ficheros a petición por intento.

La tesis original de la Option F ("endurece el control") era **cierta para multipart y falsa para JSON**.
El invariante que se perdió al quitar `#[MapUploadedFile]` no era el de nombres, sino el de transporte:
*un upload entra por `$request->files` o no entra*.

**Arreglo:** `api/src/Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer.php`. Reclama
`UploadedFile` **sólo cuando el dato no lo es ya** y lo rechaza con `NotNormalizableValueException`, que
el resolver ya traduce a 422. Un upload real llega como el objeto mismo y pasa intacto; un cuerpo que
*describe* uno se rechaza antes de tocar el disco, lo que cierra también el oráculo de existencia.

Detalle que costó dos iteraciones y merece quedar escrito: `getSupportedTypes()` debe devolver
`[UploadedFile::class => false]`. Con `true` el serializer cachea la decisión **por tipo** y deja de
consultar `supportsDenormalization()`, de modo que el upload legítimo también se rechazaba.

```
A) upload real desde $request->files : PASA intacto (misma instancia)
B) JSON sintetizado: RECHAZADO
C) ruta inexistente: RECHAZADO antes de tocar el disco -> sin oracle de existencia
D) string en miembro de fichero: RECHAZADO
```

**El test se probó contra sí mismo.** Ataca `/app/api/docs/ide-config/composer.png`, un PNG real dentro
del límite de tamaño, para que el 422 no pueda venir del chequeo MIME. Con el arreglo desactivado el
test falla devolviendo **201 Created** -- la exfiltración completándose. Con el arreglo, 422.

Va en `Shared/Http/` a propósito, no en el controlador: la misma razón por la que `StrictRequestPayload`
mete la política en el tipo -- que ningún endpoint futuro pueda adoptarla a medias.

### 2026-07-27 -- Behat 4 dentro de la rama: el árbol aislado desaparece y destapa tres reconciliaciones de 8.1

`behat/behat 4.0.0-alpha1` acepta `symfony/console|config|dependency-injection|event-dispatcher|
translation|yaml` en `^8.0`, y `friends-of-behat/symfony-extension 2.7.0` ya admitía `behat ^4.0`
junto a `symfony ^8.0` en DI y http-kernel. Medido con `composer require --dev --dry-run` sobre
`api/composer.json`: resuelve limpio con todo `symfony/*` en 8.1.1. Ya no queda ningún techo en `^7`,
que era la condición de desbloqueo escrita en `api/tools/behat/UPGRADE.md`. Behat pasa a
`require-dev` y `api/tools/behat/` se borra entero.

El exit 255 queda explicado con precisión: el árbol de tooling ganaba cada FQCN compartido, así que
el componente DI se cargaba **por mitades** -- `ContainerBuilder` desde el tooling (7.4) y
`Kernel\KernelTrait` (sólo existe en 8.1) desde la app -- y FrameworkBundle llamaba `getNamespace()`
sobre un `ServicesBundle` que no lo tiene. No era cuestión de pines.

Tres hallazgos que sólo aparecen al poder ejecutar la suite:

1. **`GHERKIN_32` es el parser por defecto en Behat 4** y deja de quitar el `@` de los tags.
   `SecurityContext` compara `'anonymous'` a pelo, así que 24 escenarios `@anonymous` se
   autenticaban y devolvían 403 donde afirman 401. Se fija `LEGACY`: es el modo con el que se
   escribieron los 47 features, y adoptar paridad cucumber cambia ocho comportamientos de parsing a
   la vez. Aviso operativo: `FileCache` de gherkin **no incluye el modo en la clave** (sólo versión
   de librería, ruta y mtime), así que cambiar de modo sirve ASTs rancios hasta tocar los `.feature`.

2. **PHPStan analizaba la app contra Symfony 7.4.** `phpstan.neon` cargaba
   `../behat/vendor/autoload.php` como `bootstrapFiles`, el mismo problema de precedencia dentro del
   gate estático. Al quitarlo salieron 7 errores reales en `CorrelationIdListenerFunctionalTest`.

3. **`InMemoryTransport::get()` recibe en 8.1 un `$fetchSize` que por defecto vale 1** y corta ahí.
   `OutboxContext` y `MessengerConsumerContext` lo llamaban sin argumentos esperando la cola entera,
   así que toda aserción de cola leía como mucho un mensaje. Las de 0 y 1 seguían pasando: sólo la
   única que afirma 2 lo destapó. Se pasa el tamaño explícitamente en ambos.

**Contrato de error.** `BackedEnumNormalizer` de serializer 8.1 parte el caso inválido en dos: si el
tipo no casa con el backing type lanza con `expectedTypes = [$backingType]`; si el tipo es correcto
pero el valor no es un case válido lanza **sin** `expectedTypes` y con un mensaje mejor
(`The data must be one of the following values: "detailed", "light"`), que el resolver deja en
`parameters['hint']`. Como `buildViolations()` sólo leía `getMessage()`, la API respondía *peor* que
en 8.0 mientras el framework ofrecía más. Se prefiere el hint **sólo** sobre la plantilla
`This value was of an unexpected type.`: el hint no es un canal seguro -- el mismo normalizador marca
como presentable un mensaje hermano que nombra la clase destino, y emitirlo pondría un FQCN interno
(`Erpify\Shared\Search\Domain\PaginationMode`) en un cuerpo de error público. Ambas ramas quedan
fijadas por `_throw-validation-hint` en `validation_violations.feature`, que además afirma que el
cuerpo no contiene el token `Erpify`.

Verificación en fresco: `php.stan` 0, `php.unit` 0, `php.quality` 0, `php.behat` 0 (373/373).

### 2026-07-28 -- code review por capas (`/bmad-code-review`) sobre la PR #553

Dos capas adversariales independientes (hostil general + recorrido exhaustivo de ramas), read-only,
sobre el diff de rama contra `main` sin lockfiles. 18 hallazgos brutos → 14 tras deduplicar: 13
parcheados aquí, 1 diferido, 1 descartado. Ninguno invalidó el diseño de la rama; la mayor parte son
aberturas de un invariante que la rama ya afirmaba, o afirmaciones de gobierno que nacían
desactualizadas.

Los tres con consecuencia real:

- **El ancla del guard de subida estaba un nivel por debajo del vector.** `SplFileInfo` es donde
  empieza la construibilidad desde una ruta (`filename` es su parámetro de constructor, y
  `SplFileObject` además abre el fichero); anclado en `File`, un miembro tipado `?\SplFileInfo` o
  `?\SplFileObject` no lo reclamaba ni esta regla ni `DataUriNormalizer` y caía en `ObjectNormalizer`.
  Reanclado en `SplFileInfo`, con `SplFileBearingPayload` fijando la forma.
- **La preferencia por `hint` confiaba en un conjunto abierto de productores.** El gate era por
  plantilla, no por productor: cualquier denormalizador (vendor o propio) que lance sin
  `expectedTypes` y con mensaje presentable emitía su texto crudo al cuerpo público. Añadido un gate
  de contenido -- un hint con backslash (el marcador de FQCN que ninguna frase de rechazo redactada
  lleva) cae a la plantilla. Fijado con una tercera violación en `_throw-validation-hint`.
- **`symfony/translation` entraba en dev/test y bifurcaba el render de violaciones.** FrameworkBundle
  activa el translator con el componente presente, así que la suite fijaba el contrato de error por el
  camino *con* translator mientras producción (`--no-dev`) renderiza por `strtr`. Cerrado con
  `config/packages/translation.yaml` (`translator.enabled: false`): un solo camino en todo entorno.

El resto: pin de retirada para `^4.0@alpha`; corregido «single breach» de `minimum-stability` (el lock
lleva tres flags, una la introduce esta misma rama); ítem de supply-chain para el pin `0.4.x-dev` en
`PRODUCTION_SECURITY_CHECKLIST.md`; documentadas las dos recetas Flex con ficheros declinados
adrede; escenarios 403-antes-de-422 para las tres escrituras de BankAccount (las que exponen IBAN) y
para la query string de `MapQueryString`; gotcha del `FileCache` de gherkin movido al comentario de
`behat.dist.php`; guard de needle vacío en el test de exfiltración; ancla vendor de
`VIOLATION_UNINFORMATIVE_TEMPLATE`.

**Diferido** (pre-existente, no de esta rama): la mitad git-aware del gate de frescura de NFR26 nunca
corre porque `/app` no es un repositorio git dentro del contenedor php. **Descartado**: conservar
`TransportOnlyUploadedFileDenormalizer` sin consumidor en `src/` -- es una decisión ya tomada y
registrada, no un hallazgo.

Verificación en fresco tras los parches al final de esta entrada.

Verificación en fresco tras los parches de review, con su exit code impreso, en el stack del worktree:

| Gate | Resultado |
|---|---|
| `make php.stan` | **0** — 1094 ficheros, sin errores |
| `make php.unit` | **0** — 2052 tests, 8996 aserciones (los 2 notices son preexistentes en `Iam/Session`) |
| `make php.behat` | **0** — 378 escenarios, 3413 pasos |
| `make php.quality` | **0** — deptrac 0 violaciones / 0 uncovered; segunda pasada sin más mutaciones |

Los 378 escenarios se explican: HEAD (`d121c05e`) ejecutaba 374 -- la tabla anterior dice 373 porque se
midió antes de ese último commit, que añadió el escenario invalid-body de `bank/access_control` -- más los
4 que añade esta review.

El guard reanclado **se demostró capaz de ir a rojo**: revertido el ancla a `File` y ejecutado el test del
payload `?\SplFileInfo`, falla -- y con un síntoma peor que "sin reclamar": la forma cae en
`DataUriNormalizer::denormalize()`, que hace `preg_match()` sobre el array y lanza `TypeError`
(`vendor/symfony/serializer/Normalizer/DataUriNormalizer.php:88`), o sea un 500, no un 422. Restaurado el
ancla, los 7 tests vuelven a verde.

Nota de fixers: `php.quality` normalizó `\SplFileInfo` a `use SplFileInfo;` en los tres ficheros nuevos.
Cambio semánticamente idéntico; `php.stan`, `php.unit` y `php.behat` se re-ejecutaron **después** de esa
mutación y los tres siguen en 0.
