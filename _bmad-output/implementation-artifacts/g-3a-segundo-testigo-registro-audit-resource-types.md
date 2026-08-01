---
baseline_commit: 9310efeb
---

# Story 1.5 (G-3a): Segundo testigo del registro — que el check de vigencia deje de autosatisfacerse

Status: in-progress

> **LA DECISIÓN ESTÁ TOMADA Y REGISTRADA** (ver *Decisión registrada*): el testigo es el **escenario de
> aceptación que ya existe**, declarado como tercer segmento de la línea del registro, y el check de vigencia
> se **estrecha a las líneas `non-person`**. La precondición normativa de la épica queda satisfecha por ese
> bloque. **No la re-abras**, pero **léela entera**: descansa en un **reencuadre** de qué atestigua el testigo,
> y construir sin haberlo entendido produce un control que parece el decidido y no lo es.

> **Esta historia no protege ningún dato hoy, y el PR tiene que decirlo.** Nada en `api/src` escribe todavía una
> fila con `resource_type = 'User'`, así que el eje de persona de `audit_log` está **vacío en producción**: esto
> es un seguro echado sobre un arma sin disparar. Protege el momento en que `User` pase a ser auditado. Es valor
> legítimo — pero si no se declara, el PR se lee como si cerrara un hueco vivo, que es el hallazgo I-16 de G-4a
> repitiéndose.

## Story

Como **desarrollador que confía en un gate verde**,
quiero que el check de vigencia del registro no pueda satisfacerse con la propia declaración que verifica,
para que un verde signifique algo.

**Eje que instala:** el **segundo testigo** que SI-22 exige sobre la única entrada manual del registro.
**Invariantes que consume:** SI-22/NFR2, SI-23/NFR3.
**Dependencias:** ninguna. Independiente de 1.1–1.4 y paralelizable.
**No depende de la Story 1.2:** su escáner recorre **propiedades**, y `SUBJECT_RESOURCE_TYPE` es una constante
de un servicio de `Application/`. La reflexión sobre propiedades no la alcanza, así que 1.2 no vuelve derivable
esta entrada ni elimina su necesidad de testigo.

## Estado medido (`main` @ `9310efeb`)

> *Procedencia:* pase de medición **read-only** (nada ejecutado, nada escrito). Las coordenadas se dan para que
> el dev las re-verifique, no para que las cite de memoria.

**La circularidad, exacta.** `theRegistryDeclaresNoTypeThatNothingWrites()`
([`PersonResourceErasureGateTest`](../../api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php))
acepta el literal `'User'` **en cualquier punto de `src`**. Ese literal aparece **una sola vez en todo `api/src`**,
en [`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) (`:75`,
`SUBJECT_RESOURCE_TYPE`) — el fichero que la propia entrada de
[`.audit-resource-types`](../../api/.audit-resource-types) señala. Lo satisface **el consumidor**, no un escritor.

**Hay DOS consumidores vivos, y el gate no puede ver el segundo.**

- `FulfilIdentityErasure.php:109` pasa `AuditResource::of(self::SUBJECT_RESOURCE_TYPE, …)` a `anonymise()` —
  predicado de **`UPDATE`**.
- [`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
  (`:45`) llama `unerasedIdsOfType(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE)` — predicado de **`SELECT`**, a
  través de una **constante importada**, que es literalmente el punto ciego que la cabecera del registro confiesa.

Consecuencia: `User` **no está solo «por delante de su productor»**. Toda una tubería detectiva depende hoy de esa
constante. Borrar la entrada borraría el registro de la obligación, no la obligación.

**El defecto de fondo está aguas arriba del staleness.** El registro dice listar los tipos **escritos** en
`audit_log`; el check de completitud implementa *«todo tipo que llega a `AuditResource::of()`»*. **No son el mismo
conjunto:** `AuditResource` es el vocabulario del camino de escritura
([`AuditWriteCaptureListener`](../../api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php),
`:96`) **y** del de borrado/consulta. `User` entró por una llamada de consumidor y la completitud no puede saberlo.
**El staleness es el síntoma; la conflación productor/consumidor es la causa.**

**El testigo ya está en el árbol, commiteado, cuatro veces.**

| Artefacto | Qué hace |
|---|---|
| [`erase.feature`](../../api/features/backoffice/users/erase.feature) `:50` | Siembra una fila real con `resource_type='User'` por la conexión de seed |
| `:52-53` | `DELETE /backoffice/users/{id}` real → 204 |
| `:55-56` | Comprueba **cero** filas que sigan nombrando a la persona en el eje de recurso |
| `:58-59` | Ambos ejes colapsados sobre **el mismo** seudónimo, `resource_erased = TRUE` |
| `AuditResourceAnonymiserFunctionalTest.php:48`, `PersonResourceReferencesFunctionalTest.php:35`, `AuditActorAnonymiserFunctionalTest.php:113` | El mismo tipo, contra Postgres real |

Y hay **precedente de leer `api/features` desde un gate unitario**:
[`BehatSuiteCoverageGateTest`](../../api/tests/Unit/Shared/Architecture/BehatSuiteCoverageGateTest.php) (`:45`,
`:123`). El escenario se lee como **corpus de texto**; el gate **nunca lo ejecuta**. Ninguna de las trampas de
Behat (reset de BD, conexión de seed sin trackear) aplica.

## Decisión registrada (precondición normativa de la épica: SATISFECHA)

**Decidido:** 2026-08-01. **Quién:** Sergio, sobre lectura de arquitectura + pase de medición independiente.
**Dónde queda el registro:** este bloque, y el cuerpo del PR debe reproducirlo.

### El reencuadre, que es la decisión de verdad

El testigo **no** es *«alguien escribe `User`»*. Es:

> **«el camino de borrado declarado demuestra que borra una fila `User` que él mismo no declaró».**

**Por qué el reencuadre es la lectura correcta y no un ablandamiento.** Las dos lecturas atacan riesgos
**distintos**. El check de vigencia existe contra un **cementerio**: un registro que declara tipos que nadie usa.
Para una línea `non-person` ése *es* el riesgo, y ahí el check funciona hoy (`Bank` y `BankAccount` son
productores genuinos vía `AuditedEntity`). Para una línea `person` el riesgo **no es el cementerio**: es **una
obligación sin ejecutar**. Un solo check no puede servir a dos modos de fallo distintos, y forzarlo es lo que
produjo la circularidad. **El check se parte por la frontera de riesgo real**, no por conveniencia. Además, la
lectura literal exigiría al gate juzgar la *liveness* de un productor, cuando SI-21 le prohíbe expresamente
juzgar la clasificación y le reserva solo las propiedades mecánicas.

### El mecanismo

La línea crece un tercer segmento, con el vocabulario `::` que el registro ya usa:

```
User => person :: src/Iam/Identity/Application/FulfilIdentityErasure.php :: features/backoffice/users/erase.feature
```

El gate exige, para **toda** línea `person`: que el fichero testigo **exista**, que **no sea** el fichero de
erasure, que **contenga una escritura** de ese `resource_type`, y que **contenga una aserción de que desaparece**.
La declaración apunta; el gate **lee el artefacto apuntado** — estructuralmente idéntico al check de cableado ya
aceptado, que lee la fuente sin comentarios del fichero de erasure.

**Alternativas descartadas:**

- **«Existe un escritor de producción» (lectura literal).** Descartada, pero **no por el motivo registrado en el
  corte**. No está bloqueada por falta de planificación: que `User` implemente `AuditedEntity` son dos métodos
  ([`AuditedEntity`](../../api/src/Shared/Audit/Domain/AuditedEntity.php) `:21,:23`). La deja fuera **la cola**:
  cada `flush` de usuario pasaría por `PiiDiffSealer` estrenando superficie de crypto-shredding y retención,
  cuatro canarios de presupuesto de queries se moverían, y `ChangeUserRoles` necesitaría un `AuditLogger` que hoy
  no inyecta. Es una historia propia, no un prerrequisito.
- **Testigo descubierto, no declarado** (que el gate busque *cualquier* fichero de `features/` o `tests/` que
  escriba y asegure la desaparición del tipo). Sobrevive a renames, pero **el registro deja de apuntar a su
  evidencia** y el revisor no ve qué la avala. El patrón de la casa es *registro revisable*: se paga la fricción
  del rename porque **falla ruidosa**.
- **Partir el vocabulario del registro en `written` / `erasable`.** Es el arreglo del defecto de fondo y **es
  correcto**, pero toca el check de completitud, la cabecera y posiblemente el reconciliador. **Historia aparte.**
  Aquí se corrige la frase falsa de la cabecera y se deja la costura limpia.

## Acceptance Criteria

**AC1 — La circularidad se demuestra ANTES de corregirla (FR7, NFR3).**
**Given** el check de vigencia tal como está hoy,
**When** se ejecuta sobre el tipo `User`,
**Then** una prueba demuestra que **lee verde por construcción**, y esa demostración **precede** a la corrección.

**AC2 — El testigo no es el fichero que el registro nombra (FR7, SI-22).**
**Given** la definición elegida,
**When** corre el check,
**Then** queda satisfecho por un artefacto que **no** es el fichero de erasure declarado en la misma línea.

**AC3 — El gate cae cuando el testigo desaparece.**
**Given** que el testigo se borra o deja de escribir/asegurar el tipo,
**When** corre el gate,
**Then** **falla**. *Esto exige un motor extraíble — ver AC4; no es demostrable borrando el artefacto real.*

**AC4 — El gate es falsable, y eso es criterio de aceptación, no refactor.**
**Given** el gate,
**When** se quiere ejercer su camino rojo,
**Then** se hace **con fixtures**, no borrando artefactos reales del repo. Hoy es imposible: todo son métodos
privados sobre constantes fijas. Se extrae un motor `AuditResourceTypeRegistry` en `api/tests/Support/`, con raíz
inyectable, espejo de [`PersistentTransportPolicy`](../../api/tests/Support/PersistentTransportPolicy.php).
*Un control cuyo rojo no se puede provocar no es un control — que es la tesis entera de esta épica.*

**AC5 — El staleness se estrecha a `non-person`.**
**Given** una línea `non-person` cuyo tipo ya no escribe nadie,
**When** corre el gate,
**Then** **falla** (el cementerio sigue vigilado). **Given** una línea `person`, **Then** su vigencia la establece
el testigo, no la búsqueda del literal en `src`.

**AC6 — La cabecera declara lo que sigue sin cubrir, y corrige una afirmación falsa.**
**Given** el registro,
**When** se lee su cabecera,
**Then** (a) la frase *«tipos escritos en `audit_log`»* está **corregida** —el registro lista tipos que el
vocabulario de auditoría conoce, escritores y consumidores por igual— y (b) enumera al menos: que **nada escribe
`User` en producción hoy**; que el testigo prueba que el borrado funciona **sobre** una fila así, no que se
produzca alguna; que testigo y declaración **pueden morir en el mismo PR** (el gate lo encarece y lo hace
visible, no imposible); que la fila del escenario es SQL a mano y **no detecta drift de columnas**; y que el
punto ciego ya declarado *«no puede seguir una constante importada»* está **vivo y demostrado** en
`ReconcileErasedSubjectReferences.php:45`.

**AC7 — Sin regresión.**
`make php.lint.audit-resource`, `make php.quality`, `make php.unit` y `make php.behat`, cada uno desde **ejecución
fresca con exit code impreso**. Todo verde.

## Tasks / Subtasks

- [x] **Tarea 1 — Registrar la decisión (PRECONDICIÓN).** Hecha: ver *Decisión registrada*. Reprodúcela en el PR.
- [x] **Tarea 2 — Demostrar la circularidad (AC1).** Test que fije el comportamiento actual **antes** de tocarlo.
- [x] **Tarea 3 — Extraer el motor (AC4).** `api/tests/Support/AuditResourceTypeRegistry.php`, mayormente
      **movido** desde el gate. Reutiliza `AllowlistFile::entries()` (el gate hoy parsea a mano) y
      `ApiSourceFiles::phpFiles()`. Raíz inyectable, como `PersistentTransportPolicy::fromGateLocation()`.
- [x] **Tarea 4 — Tercer segmento y check de testigo (AC2, AC3, AC5).** Validar la ruta con `is_file()` **y no**
      `assertFileExists()`, que acepta **directorios** — el gate actual (`:84`) tiene ese defecto y **no se copia**.
- [x] **Tarea 5 — Fixtures (AC3, AC4).** Registro sintético + testigo sintético, más su gemelo limpio.
- [x] **Tarea 6 — Cabecera (AC6).** Corregir la afirmación falsa y ampliar el bloque de puntos ciegos siguiendo
      la versión madura de [`.persistent-transport-policy`](../../api/.persistent-transport-policy).
- [ ] **Tarea 7 — Gates y pase adversarial (AC7 + definición de hecho de la épica).** Ejecuciones frescas con exit
      code. **Pase adversarial por alguien distinto del autor, REGISTRADO, declarando dónde.** Sin él no hay `done`.

## Dev Notes

- **Vocabulario Behat: esta historia no gasta ni un step.** `I execute the SQL query …` y
  `there should have N records in SQL result` ya están gastados en `erase.feature:55-59`. No busques ni añadas.
- **Coste de runtime: cero.** Sigue siendo `bin/phpunit --filter=PersonResourceErasureGateTest`, puro sistema de
  ficheros. Sin Behat, sin BD, sin contenedor.
- **El target no cambia de cuerpo**, solo su comentario en `make/php-quality.mk`.
- **Anti-patrón principal:** «reforzar» el staleness para `person` buscando el literal en más sitios. No arregla
  nada — el problema no es dónde se busca, es **quién puede satisfacerlo**.

## Dev Agent Record

**Rama:** `feat/shared-g3a-segundo-testigo-audit-resource-types-13r9` (worktree aislado, base `main` @ `8f1f853c`).

### Lo que se construyó

- `api/tests/Support/AuditResourceTypeRegistry.php` — motor: parseo del registro, barrido de tipos en `src`,
  cableado del borrador y staleness. Raíz inyectable (`fromGateLocation()` para el árbol real, constructor de
  tres rutas para fixtures).
- `api/tests/Support/AuditWitnessScenario.php` — el testigo, separado del motor. Lee el `.feature` como texto:
  ¿siembra una fila del tipo y asierta que ninguna sobrevive?
- `api/tests/Support/DeclaredPath.php` — travesía, forma del artefacto y **`is_file()`** (no `file_exists()`,
  que acepta directorios). Lo usan los dos.
- `api/tests/Support/PersonResourceDeclaration.php` — los dos caminos de una línea `person`, con nombre.
- `PersonResourceErasureRulesGateTest` + `Fixture/PersonResource/` — falsabilidad con fixtures.
- Registro y cabecera reescritos; `make php.lint.audit-resource` pasa a filtro de **prefijo común**.

### Tres cosas que la medición cambió respecto al artefacto

1. **El check de identidad testigo≠borrador era código muerto.** El borrador exige `src/…php` y el testigo
   `features/…feature`: nunca pueden coincidir. Se eliminó la comparación y la disyunción queda donde de
   verdad muerde — la regla de ruta — con su propio caso rojo (`theWitnessCheckRejectsTheErasureOwnerItself`).
2. **PHPMD tumbó el motor por complejidad 57 (umbral 50).** Se arregló separando responsabilidades
   (`AuditWitnessScenario` + `DeclaredPath`), no subiendo el umbral ni suprimiendo.
3. **Coordenadas al día:** el literal `'User'` vive en `FulfilIdentityErasure.php:85` (no `:75`) y el consumidor
   ciego en `ReconcileErasedSubjectReferences.php:45`. El escenario testigo es `erase.feature:49-62`.

### Puertas (ejecuciones frescas, exit code impreso)

| Gate | Resultado |
|---|---|
| `make php.lint.audit-resource` | OK (14 tests, 24 aserciones) — exit 0 |
| `make php.quality` | exit 0 |
| `make php.quality.dry-run` | exit 0 |
| `make php.unit` | 2135 tests, 9148 aserciones — exit 0 |
| `make php.behat` | 383 escenarios, 3470 steps — exit 0 |

Las 2 *notices* de PHPUnit son preexistentes (`DoctrineSessionRepositoryStoreUnavailableTest`, mocks sin
expectativas) y no las toca esta historia.

### File List

- `api/.audit-resource-types` (M)
- `api/tests/Support/AuditResourceTypeRegistry.php` (A)
- `api/tests/Support/AuditWitnessScenario.php` (A)
- `api/tests/Support/DeclaredPath.php` (A)
- `api/tests/Support/PersonResourceDeclaration.php` (A)
- `api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php` (M)
- `api/tests/Unit/Shared/Architecture/PersonResourceErasureRulesGateTest.php` (A)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/Source/AuditResourceFixtureWriter.php` (A)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/features/witness-complete.feature` (A)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/features/witness-without-erasure.feature` (A)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/features/witness-without-write.feature` (A)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/registry.{complete,stale,duplicate,unrecognised,no-witness}` (A)
- `make/php-quality.mk` (M)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (M)

### Pendiente

**Tarea 7 — pase adversarial por alguien distinto del autor, REGISTRADO.** Es la definición de hecho de la
épica y sin él la historia no llega a `done`.

## References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` — FR7; NFR2/SI-22, NFR3/SI-23, NFR10.
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-22, SI-23.
- `docs/adr/audit-activity-log.md` — D4, y el porqué de la obligación distribuida que el registro vigila.
- `_bmad-output/implementation-artifacts/g-4a-fuga-passwordresetcompleted-transportes-messenger.md` — el
  precedente de registro + gate y sus dos pases adversariales.
