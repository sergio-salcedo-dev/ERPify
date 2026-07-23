---
title: 'Eliminar la superficie de imágenes de la API (Media + Storage + multipart de Bank)'
type: 'chore'
created: '2026-07-23'
status: 'done'
baseline_commit: 'ed2251da1469e2b121bd2e61b4d6fcbb47ab62a4'
review_loop_iteration: 0
context:
  - '{project-root}/docs/rules/database.md'
  - '{project-root}/docs/adr/api-resource-dtos.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La API construyó una superficie completa de subida de imágenes (`Shared\Media`, `Shared\Storage`, multipart en `POST /banks`) que **nadie consume**: la PWA tiene cero `FormData`/`type="file"`/`logoUrl`, las dos features Behat que la cubrían están enteramente comentadas, y no hay datos de imágenes en producción. La subida a Symfony 8.1 destapó además una lectura arbitraria de ficheros en ese multipart. Migrar código condenado a 8.1 es pagar dos veces.

**Approach:** Borrar la superficie entera de raíz —los dos namespaces, el multipart de Bank, sus rutas, su wiring de config, sus tres dependencias de Composer, sus columnas y la tabla `media`— junto con todo lo que queda huérfano (dos clases de `Shared/Http`, el volumen `storage_data` y su pipeline de backup/restore, y la documentación que describe el contrato). Sergio rehará imágenes desde cero en una épica nueva, diseñada ya contra el resolver de 8.1.

## Boundaries & Constraints

**Always:**
- Nada de código muerto ni wiring colgando: cada puerto borrado se lleva sus adaptadores, sus tags, sus route loaders y su entrada de bundle. El criterio de "hecho" es que `git grep -iF` de `Media`, `StoredObject`, `Flysystem` e `Intervention` no devuelva hits vivos en `api/src`, `api/config`, `api/tests`, `api/features`, `api/tools` ni `scripts/`.
- La migración es destructiva y su `down()` debe recrear la estructura completa (tabla `media` + índice único, FK `FK_D860BF7ABAAE86A3`, índice `IDX_D860BF7ABAAE86A3`, las 4 columnas `stored_object_*` y `logo_media_id`).
- Todo "verde" sale de una ejecución fresca con su exit code impreso. `make ... | tail` devuelve el exit code de `tail`, no el de `make`.
- Los comentarios relativos-al-cambio y los IDs de historia se barren del propio diff antes del commit final.
- Trabajo con superficie de seguridad → **pase adversarial registrado** (aquí se *quita* superficie: hay que probar que no sobrevive ninguna ruta ni servicio, y que la migración no deja columnas colgando).

**Ask First:**
- Cualquier rama ADICIONAL a `chore/remove-image-upload-surface-uzpl`.
- Cualquier merge a `main` (permiso explícito por-merge, siempre).
- Si `make db.diff` genera algo que **no** coincide con el delta esperado del apartado *Design Notes* — parar y enseñarlo antes de editar la migración a mano.
- Si aparece un consumidor vivo de la superficie que esta spec no lista.

**Never:**
- No tocar `api/src/Shared/Http/Infrastructure/StrictRequestPayload.php` ni `UnknownPayloadMemberListener.php` — política de estrictez compartida, independiente de imágenes.
- No tocar la rama ni los ficheros de #553 (`chore/symfony-81-and-sharp-alignment-u7nc`).
- No quitar la extensión `gd` de `api/Dockerfile:33` — está en la lista de extensiones requeridas del proyecto; tocarla arriesga el build a cambio de nada.
- No reescribir artefactos `_bmad-output/` con `status: done` (registro histórico).
- No reintroducir la funcionalidad "mejorada" ni dejar shims de compatibilidad: se borra, no se deprecia.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Crear banco (JSON) | `POST /banks` con `{name, shortName}` | 201; `data` con **5** claves (antes 7) — sin `logoUrl` ni `storedObjectUrl` | igual que hoy |
| Crear banco (multipart) | `POST /banks` con `Content-Type: multipart/form-data` | **415** o el error de formato-no-aceptado que produzca `StrictRequestPayload` con `acceptFormat: ['json']` — verificar cuál y fijarlo en un test | RFC 9457, sin traza |
| Detalle de banco | `GET /banks/{id}` | 200; `data` con **6** claves (antes 8) — sin `logoUrl` ni `storedObjectUrl` | igual que hoy |
| Ruta de media | `GET /api/v1/media/{hash}` | **404** — la ruta ya no existe en el router | — |
| Ruta de stored object | `GET /api/v1/stored-objects/{hash}` | **404** — la ruta ya no existe en el router | — |
| Migración arriba | `make db.migrate` sobre BD con esquema actual | tabla `media` fuera; `bank` sin `logo_media_id` ni las 4 `stored_object_*` | falla ruidosa si hay filas referenciando |
| Migración abajo | `make db.migrate c='prev'` | esquema estructuralmente idéntico al de partida (datos no, es destructiva) | — |
| Evento de dominio | banco creado/renombrado | `BankSnapshot` con **4** campos; el payload Mercure no cambia (ya omitía los de media) | — |

</frozen-after-approval>

## Code Map

**Se borran enteros** (29 + 8 + 2 + 3 + 2 ficheros):
- `api/src/Shared/Media/` — 16 ficheros; `api/src/Shared/Storage/` — 13 ficheros.
- `api/tests/Unit/Shared/Media/` (6) y `api/tests/Unit/Shared/Storage/` (2).
- `api/src/Shared/Http/Infrastructure/ContentHashUrlGenerator.php` y `ContentAddressedHttpCache.php` — **huérfanos medidos**: sus únicos consumidores son los 4 ficheros de arriba. Más `api/tests/Unit/Shared/Http/Infrastructure/ContentAddressedHttpCacheTest.php`.
- `api/src/Backoffice/Bank/Domain/Repository/BankStoredObjectQueries.php`, `Infrastructure/Persistence/Doctrine/BankStoredObjectRemoveListener.php`, `Infrastructure/Storage/BankStoredObjectReferenceInspector.php` (+ el directorio `Infrastructure/Storage/`).
- `api/tests/.../Bank/.../BankLogoMultipartFunctionalTest.php` (153 líneas), `BankStoredObjectMultipartFunctionalTest.php` (127), `api/tests/Unit/Backoffice/Bank/Application/BankCollaboratorStubs.php`.
- `api/tests/Functional/Shared/Persistence/EntityManagerResetRepositoryReuseTest.php` — existe *solo* para blindar `MediaConcurrentInsertResolver`; muere con él.
- `api/features/backoffice/bank/create_with_logo.feature` y `create_with_stored_object.feature` (ambas ya 100 % comentadas).
- `api/config/packages/media.yaml`, `api/config/packages/flysystem.yaml`.
- `docs-info/media-upload.md`, `docs-info/object-storage.md`, `docs/media-document-system.md`.

**Se podan** (el fichero sobrevive):
- Bank src: `Application/BankCreator.php` (2 deps de ctor + 2 params), `Resource/BankCreate|DetailResource.php` (2 props c/u), `Resource/BankUpdateResource.php` (solo docblock), `Domain/Entity/Bank.php` (ManyToOne + Embedded + 2 getters + args de evento), `Domain/Event/BankSnapshot.php` (4 campos + `nullableStringField()` queda muerto), `Domain/Repository/BankRepository.php` (docblock), `Infrastructure/Controller/BankPostController.php` (2 `#[MapUploadedFile]`, `assertValidUpload`, `toUploadedImage`, `#[Autowire]` de `max_upload_bytes`, `acceptFormat` → `['json']`), `Infrastructure/Http/BankResourceMapper.php` (ctor queda vacío, 3 métodos privados fuera), `Infrastructure/Messenger/RefreshRealtimeOnBankChanged.php` (docblock), `Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` (`#[AsAlias]`, `implements`, 2 métodos).
- Config: `api/config/routes.yaml:57-65` (**dos route loaders — sin esto el contenedor no arranca**), `api/config/bundles.php:15` (`FlysystemBundle`), `api/config/packages/doctrine.yaml:32-43` (`SharedMedia`+`SharedStorage`), `api/config/packages/monolog.yaml:7` (canal `media`, ya sin consumidor), `api/.env.example:62-83`, `api/.env.test:16,19`, `api/tools/phpunit/phpunit.dist.xml:23`.
- Tests Bank a podar: `BankCreatorTest`, `BankTest`, `BankMother`, `BankSnapshotTest`, `BankSnapshotMother`, `BankListRowContractTest`, `BankResourceMapperTest`, `RefreshRealtimeOnBankChangedTest`, `BankDetailResponseGoldenFunctionalTest`.
- Tests ajenos que usan `Media` como fixture incidental: `api/tests/Functional/Doctrine/IdentifiableAssignedIdentifierTest.php` y `api/tests/Functional/Shared/Audit/AuditWriteCaptureListenerFunctionalTest.php` → **re-apuntar a una entidad superviviente**, no borrar. `api/tests/Unit/Shared/Architecture/BoundedContextGateTest.php:264` usa `Media` en un heredoc de fixture → sustituir el nombre.
- Behat vivas: `create.feature` (7→5 elementos, quitar asserts `logoUrl`/`storedObjectUrl` y filas `| logo | null |`), `get.feature` (8→6), `update.feature:23-24` (asserts vacuos), `search.feature:332-341` (usa `storedObjectKey` como columna-sonda "real pero fuera del allow-list" → sustituir por otra columna real, p. ej. `nameNormalized`).
- Deps y tooling: `api/composer.json:34-36`, `api/tools/deptrac/deptrac.yaml` (:19, layers `Vendor.Flysystem`/`Vendor.InterventionImage` :184-189, 12 rulesets), `api/tools/deptrac/deptrac.baseline.yaml:54-55`.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Media/`, `api/src/Shared/Storage/`, `api/tests/Unit/Shared/{Media,Storage}/` -- borrar recursivamente -- es el corte de raíz
- [x] `api/config/routes.yaml`, `bundles.php`, `packages/doctrine.yaml`, `packages/monolog.yaml`; borrar `packages/media.yaml` y `packages/flysystem.yaml` -- desconectar el wiring -- **hazlo en el mismo commit que el borrado: si no, el kernel no arranca**
- [x] `api/src/Shared/Http/Infrastructure/{ContentHashUrlGenerator,ContentAddressedHttpCache}.php` + su test -- borrar -- huérfanos medidos; NO tocar `StrictRequestPayload`/`UnknownPayloadMemberListener` del mismo directorio
- [x] `api/src/Backoffice/Bank/` -- borrar los 3 ficheros de stored-object y podar los 10 restantes -- deja `POST /banks` como endpoint JSON puro
- [x] `api/tests/Unit/Backoffice/Bank/` + los 2 tests multipart funcionales -- podar/borrar -- que la suite refleje el contrato nuevo
- [x] `api/tests/Functional/{Doctrine/IdentifiableAssignedIdentifierTest,Shared/Audit/AuditWriteCaptureListenerFunctionalTest}.php` + `BoundedContextGateTest.php` -- re-apuntar a una entidad superviviente -- **conservar su cobertura**: no son tests de imágenes, solo usaban `Media` de fixture
- [x] `api/features/backoffice/bank/` -- borrar las 2 comentadas, podar `create`/`get`/`update`/`search` -- `search.feature` necesita columna-sonda de repuesto o pierde su sentido
- [x] `api/composer.json` -- quitar `intervention/image`, `league/flysystem`, `league/flysystem-bundle`; `make composer c='update --lock'` -- medido: 1 fichero usaba cada una
- [x] `api/tools/deptrac/deptrac.yaml` + `deptrac.baseline.yaml` -- quitar layers vendor, rulesets y la entrada de `StoredObjectOrphanCleaner` -- la baseline es un ratchet: al borrar código sobran líneas
- [x] `api/migrations/2026/` -- `make db.diff`, revisar a mano contra *Design Notes*, `make db.migrate` -- destructiva; `down()` reversible en estructura
- [x] `compose.prod.yaml`, `.env.prod.example`, `scripts/deploy/{backup-prod.sh,restore-prod.sh,lib/common.sh,README.md}`, `make/deploy.mk:50` -- quitar el volumen `storage_data` y la mitad object-storage del backup pareado -- sin escritor, el archivo de backup sería siempre vacío y el simulacro de DR inejecutable
- [x] `pwa/src/app/backoffice/roadmap/_lib/roadmap.ts:291-318` -- corregir el copy F0/F1 -- página visible que hoy afirma "Bank ya sube/recupera ficheros" y "Flysystem ya provee la abstracción"
- [x] Docs de contrato -- `api/docs/postman/{README.md,erpify-api.postman_collection.json}`, `api/docs/adding-endpoints.md:21`, `docs/architecture/event-catalog.md:87-90,141`, `docs/adr/api-resource-dtos.md:46,51,71,77-79` -- cambia la forma del wire
- [x] Docs de arquitectura -- `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/index.md`, `docs/project-overview.md`, `docs/project-context.md:33,129,353`, `docs/rules/{architecture.md:36-41,database.md:33+101-102,security.md:121}`, `docs/adr/shared-module-organization.md:14-15`, `docs/development-guide-api.md:114`, `api/CLAUDE.md:23`, `PRODUCTION_SECURITY_CHECKLIST.md:330` -- `rules/architecture.md` cita `StoredObject.php` como **único** ejemplo de embeddable: hay que sustituirlo, no solo borrarlo
- [x] Docs de despliegue -- `docs/deployment-guide.md:197,201,204`, `docs/vps-deployment.md` (volumen, backup, simulacro de DR), `docs/claude-code-quickref.md:27`, `docs-info/production-deployment.md:7,69,164` -- acompañan al pipeline
- [x] `docs/adr/media-vs-documents-upload-boundary.md` -- cabecera `> Status: Superseded` -- una decisión histórica se marca, no se borra
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- **borrar** la bala "Revive the commented-out create_with_logo.feature…" y **añadir** la de la épica nueva de imágenes -- registro solo-pendientes; el ítem viejo ya no es alcanzable

**Acceptance Criteria:**
- Given el stack levantado, when se piden `GET /api/v1/media/{hash}` y `GET /api/v1/stored-objects/{hash}`, then ambas devuelven 404 y `make sf c='debug:router'` no lista ninguna ruta de media/stored-object.
- Given la app arrancada, when se ejecuta `make sf c='lint:container'`, then pasa sin servicios no resolubles ni tags `stored_object.reference_inspector` colgando.
- Given la migración aplicada, when se inspecciona el esquema, then `media` no existe y `bank` no tiene `logo_media_id` ni columnas `stored_object_*`; y al revertirla, la estructura vuelve idéntica.
- Given un `git grep -iF` de `Media`, `StoredObject`, `Flysystem`, `Intervention` sobre `api/src api/config api/tests api/features api/tools scripts`, when se filtran los falsos positivos (media types HTTP, imágenes Docker), then no queda ningún hit vivo.
- Given la suite completa, when corre `make php.unit`, then pasa y la cobertura de `IdentifiableAssignedIdentifierTest`, `AuditWriteCaptureListenerFunctionalTest` y `BoundedContextGateTest` sigue existiendo (re-apuntada, no eliminada).
- Given el pase adversarial obligatorio, when un contexto fresco audita el diff, then queda registrado en la descripción de la PR con su veredicto — incluso si no encuentra nada.

## Spec Change Log

Desviaciones encontradas al implementar. Se registran para que una re-derivación no repita el error.

- **`BankCollaboratorStubs.php` se poda, no se borra.** El Code Map lo listaba como borrado entero. Medido antes de tocarlo: `BankUpdaterTest` también usa el trait, por su `passThroughValidator()`. Se retiraron solo `mediaRegistrar()` y `storedImageObjectWriter()`. *Estado malo evitado:* borrar el fichero rompía `BankUpdaterTest`, ajeno a imágenes.
- **`routes.yaml` y `bundles.php` no estaban en el alcance de entrada y son bloqueantes.** `api/config/routes.yaml` cargaba dos *route loaders* apuntando a los directorios borrados y `api/config/bundles.php` registraba `FlysystemBundle`. Sin quitarlos el kernel no arranca, así que ningún gate intermedio da señal fiable. *KEEP:* borrado + desconexión de config van en el mismo paso.
- **Desconfigurar el bundle vía Composer reescribe `bundles.php`.** El recipe de Flex se llevó `declare(strict_types=1)`, la forma `$bundles = [...]` y el condicional de FriendsOfBehat. Hay que restaurar el fichero a mano después. *KEEP:* verificar `bundles.php` tras cualquier `composer remove/update` de un paquete con recipe.
- **Los tests ajenos a Bank necesitaron tres tratamientos distintos, no uno.** `IdentifiableAssignedIdentifierTest` y `AuditWriteCaptureListenerFunctionalTest` se re-apuntaron (a `User` y a `Organization`); `EntityManagerResetRepositoryReuseTest` se borró porque existía solo para blindar `MediaConcurrentInsertResolver`; y `AuditPolicyTest` —que la spec no listaba— referenciaba los nombres de ruta `shared_media_get` / `shared_stored_object_get` y hubo que podarlo. *KEEP:* el barrido `git grep` final es lo que encontró `AuditPolicyTest`; no se sustituye por la lista de la spec.
- **`Membership` no sirve como fixture no-auditado: tiene FK física.** Primer intento fallido (`fk_membership_organization`, violación de FK). La BD solo tiene dos FKs; `Organization` no tiene ninguna y tampoco es auditada. *KEEP:* medir las FKs contra la BD, no contra la migración baseline — el listener `postGenerateSchema` reinyecta FKs que la baseline no muestra.
- **`AuditWriteCaptureListener` pierde toda cobertura de su rama de asociaciones to-one.** El `ManyToOne` de `Bank` hacia `Media` era la última asociación mapeada de la aplicación. Se conserva el código (capacidad general del listener) y se borra su test por falta de sujeto; queda registrado en `deferred-work.md`.
- **`BankCreateResource` y `BankUpdateResource` quedan con el mismo juego de claves** (y `list` con el de `detail`): las URLs eran lo que distinguía las cuatro vistas del ADR. Se mantienen los cuatro DTOs —evolución independiente por vista— y el ADR se actualizó para explicar por qué. *No se colapsan unilateralmente:* es una decisión de diseño del usuario.
- **`make php.behat` sale verde, no rojo.** La expectativa de entrada venía del handoff. La incompatibilidad Behat↔Symfony 8 es de la rama de #553 (Symfony 8.1); esta rama está en 8.0.x.
- **`make composer.check.all` sale rojo por 8 símbolos preexistentes** (`Symfony\Component\Security\Http\*`, `Symfony\Component\Finder`, `Twig\Environment`). Medido: el diff de `composer.json` son exactamente las 3 líneas de dependencia y el lock retira exactamente 6 paquetes, ninguno de los cuales provee esos símbolos; los tres siguen instalados y nunca fueron dependencias directas.

### Pase adversarial (registrado — 2026-07-23)

Dos revisores independientes en contexto fresco (Blind Hunter adversarial + Edge Case Hunter) sobre el diff completo. **15 hallazgos deduplicados**; 11 parcheados, 1 rechazado con evidencia, 4 diferidos, 2 elevados a decisión de usuario. Los tres que más pesaban, verificados a mano antes de actuar:

- **Borré un test que NO era de imágenes.** `EntityManagerResetRepositoryReuseTest` blinda `resetManager()` + repositorio ya inyectado, invariante del que depende `BankDeleter.php:63` para devolver 409 en vez de 500 bajo TOCTOU. La spec afirmaba que "existe solo para blindar `MediaConcurrentInsertResolver`" — **falso**. Restaurado, re-apuntado a `BankAccountRepository`. *KEEP:* al borrar un test, leer sus consumidores reales, no fiarse de su docblock.
- **Los docs reescritos afirmaban que Postgres es el único volumen con estado.** `compose.yaml:131-134` declara además `caddy_data`, que guarda la clave de cuenta ACME y los certificados; un operador siguiendo el runbook perdería los certificados y chocaría con el rate-limit de Let's Encrypt. Corregido en 6 sitios.
- **Huérfanos que el barrido `git grep` no podía ver**: `api/storage/.gitkeep`, `api/storage-test/.gitkeep`, `api/tests/DataFixtures/Fixtures/minimal-logo.png` y el bloque object-storage de `api/.gitignore`. Ninguno contiene los tokens buscados. *KEEP:* un criterio de "hecho" basado en grep de tokens es ciego a ficheros cuyo nombre no los lleva.

**Rechazado con evidencia:** "Create≡Update permite un mapper swap silencioso" — falso, los métodos declaran tipos de retorno concretos y PHPStan level max lo garantiza; las aserciones `assertInstanceOf` añadidas eran tautológicas y se revirtieron.

**Decisiones del usuario:** sin bump a `eventVersion` v2 (la app no está en producción, `event_store` vacío → la regla no tiene sujeto); `acceptFormat: ['json']` extendido a los **once** sitios `#[StrictRequestPayload]`, no solo `POST /banks`.

## Design Notes

**Delta esperado de la migración** (contrastar contra lo que genere `make db.diff` — hoy solo existe la baseline squasheada `Version20260616201857.php`):

```sql
ALTER TABLE bank DROP CONSTRAINT FK_D860BF7ABAAE86A3;
DROP INDEX IDX_D860BF7ABAAE86A3;
ALTER TABLE bank DROP logo_media_id, DROP stored_object_key, DROP stored_object_mime_type,
                 DROP stored_object_byte_size, DROP stored_object_content_hash;
DROP TABLE media;   -- media(content_hash VARCHAR(64), mime_type VARCHAR(64), byte_size INT,
                    --       raw_bytes BYTEA, id UUID PK, created_at, updated_at)
                    -- + UNIQUE INDEX media_content_hash_uniq (content_hash)
```

**Orden de ejecución que evita un árbol roto:** borrado de namespaces + desconexión de config + poda de Bank deben ir juntos. Entre el borrado de `Shared/Media/` y la limpieza de `routes.yaml`/`bundles.php` el kernel no arranca, así que ningún `make` intermedio dará señal fiable.

**Poda, no borrado, en tres tests ajenos.** `IdentifiableAssignedIdentifierTest`, `AuditWriteCaptureListenerFunctionalTest` y `BoundedContextGateTest` usan `Media` como entidad de conveniencia, no como sujeto. Borrarlos perdería cobertura de identificadores asignados, del listener de captura de auditoría y del gate de contextos — todo ajeno a imágenes.

**Mejora en scope (regla del boy-scout, nombrada para revisión):** `BankResourceMapper` se queda con el constructor vacío una vez fuera los dos generadores de URL. Si al terminar la clase no tiene estado, conviértela en métodos sin dependencias o elimina el constructor; no dejes un `__construct()` vacío.

## Verification

**Commands:** (todos DESDE el worktree, en frío, con su exit code impreso)
- `make php.stan` -- expected: 0 errores
- `make php.deptrac` -- expected: exit 0 con la baseline ya recortada
- `make php.unit` -- expected: suite verde
- `make php.quality` -- expected: exit 0 (PHPMD/cs-fixer no tienen baseline)
- `make pwa.quality` -- expected: exit 0 **con** el diff de `roadmap.ts`
- `make db.migrate` -- expected: aplica la migración nueva; `make db.validate` verde después
- `make composer.check.all` -- expected: sin deps sin usar tras quitar las tres
- `make sf c='lint:container'` y `make sf c='debug:router'` -- expected: contenedor válido, cero rutas de media/stored-object
- `make php.behat` -- expected: verde. La incompatibilidad `behat/behat 3.32` ↔ DI de Symfony 8 muerde en **8.1**; esta rama está en 8.0.x. Medido: exit 0, 370 escenarios, 3352 steps.

**Manual checks:**
- `git grep -iF` de los 4 términos sobre `api/ scripts/ docs/ compose*.yaml` — revisar cada hit superviviente y justificarlo (media type HTTP, imagen Docker, `localStorage`, `<Logo>` de marca, `logout`).
- Diff completo leído buscando comentarios relativos-al-cambio e IDs de historia antes del commit final.

## Suggested Review Order

**El corte y su wiring** — empieza aquí: si esto no cuadra, nada más importa

- Entrada: el endpoint queda JSON puro; sin `#[MapUploadedFile]` ningún fichero llega a la app
  [`BankPostController.php:29`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php#L29)
- Los dos *route loaders* borrados: sin esto el kernel no arranca
  [`routes.yaml:50`](../../api/config/routes.yaml#L50)
- `FlysystemBundle` desregistrado; el recipe de Flex reescribió el fichero y hubo que restaurarlo
  [`bundles.php:5`](../../api/config/bundles.php#L5)

**El agregado Bank** — qué pierde el modelo

- Sin `ManyToOne` ni `#[ORM\Embedded]`: era la última asociación to-one de la app
  [`Bank.php:62`](../../api/src/Backoffice/Bank/Domain/Entity/Bank.php#L62)
- El caso de uso baja de 6 a 4 colaboradores
  [`BankCreator.php:25`](../../api/src/Backoffice/Bank/Application/BankCreator.php#L25)
- Payload de evento de 8 a 4 campos; sin bump de versión por decisión (store vacío, sin producción)
  [`BankSnapshot.php:16`](../../api/src/Backoffice/Bank/Domain/Event/BankSnapshot.php#L16)
- El mapper se queda sin constructor: ya no sintetiza URLs
  [`BankResourceMapper.php:22`](../../api/src/Backoffice/Bank/Infrastructure/Http/BankResourceMapper.php#L22)

**Esquema** — lo único irreversible

- `abortIf` rechaza correr sobre filas reales; `down()` restaura tabla, FK, índices y 5 columnas
  [`Version20260723104340.php:24`](../../api/migrations/2026/Version20260723104340.php#L24)

**Superficie de seguridad** — lo que cierra la puerta

- Los once sitios `#[StrictRequestPayload]` fijan `['json']`: un multipart muere en 415
  [`PRODUCTION_SECURITY_CHECKLIST.md:257`](../../PRODUCTION_SECURITY_CHECKLIST.md#L257)
- El test que lo fija: multipart y form-encoded rechazados, JSON aceptado
  [`BankCreateAcceptsJsonOnlyFunctionalTest.php:48`](../../api/tests/Functional/Backoffice/Bank/Infrastructure/Controller/BankCreateAcceptsJsonOnlyFunctionalTest.php#L48)

**Hallazgos del pase adversarial** — mira estos dos con lupa

- Test que borré por error y restauré: `BankDeleter` depende de este invariante para dar 409, no 500
  [`EntityManagerResetRepositoryReuseTest.php:36`](../../api/tests/Functional/Shared/Persistence/EntityManagerResetRepositoryReuseTest.php#L36)
- La retención ahora sí barre los archivos `objects-*.tar.gz` de backups antiguos
  [`backup-prod.sh:90`](../../scripts/deploy/backup-prod.sh#L90)

**Periféricos**

- Baseline de deptrac recortada: es un ratchet, al borrar código sobran líneas
  [`deptrac.baseline.yaml:54`](../../api/tools/deptrac/deptrac.baseline.yaml#L54)
- Registro solo-pendientes: fuera el ítem inalcanzable, dentro la épica nueva y 5 diferidos
  [`deferred-work.md:3`](./deferred-work.md#L3)
