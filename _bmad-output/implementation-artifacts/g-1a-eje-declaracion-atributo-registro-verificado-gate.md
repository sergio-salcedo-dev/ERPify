---
baseline_commit: 9310efeb
---

# Story 1.2 (G-1a): El eje de declaración — atributo hermano, registro verificado contra el código y gate que rompe el build

Status: done

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **LAS TRES DECISIONES ESTÁN TOMADAS Y REGISTRADAS** (ver *Decisiones registradas*): D1=①, D2=híbrida,
> D3=dos estados + universo completo. La precondición normativa de la épica queda satisfecha por ese bloque;
> **no las re-abras**, pero **léelas enteras antes de tocar código** — el argumento que las sostiene es lo que
> impide implementar por accidente la variante que parece igual y no lo es.

> **La entrega es G-1a + G-1b JUNTAS, en una sola PR. El gate aterriza en rojo y sale verde dentro de ella.**
> El rojo proviene solo de `Membership::$userId` e `Invitation::$invitedUserId`, que hoy no tienen dueño de
> borrado; es el estado que FR5 y el segundo AC de la Story 1.3 exigen, así que **tiene que existir** y quedar
> visible en la secuencia de commits: primero el mecanismo con el gate en rojo, después la cadena de erasure
> ejecutando esas dos referencias y el gate en verde **porque las ejecuta**, no porque se hayan declarado.
> **No lo "arregles" declarando un dueño falso ni ablandando el gate**, y no lo escondas colapsando ambos pasos
> en un único commit: el rojo intermedio *es* la evidencia del AC. `main` **nunca** queda roto.

## Story

Como **desarrollador que añade un contexto nuevo que toca a una persona**,
quiero que el repositorio me pare si introduzco una referencia persistida a una persona sin dueño de borrado,
para que la obligación no dependa de que alguien se acuerde.

**Eje que instala:** los tres pasos, **sin mezclarlos** — ① atributo hermano en `Shared/Privacy`, ② universo
derivado por reflexión y verificado **en las dos direcciones** contra la fuente, ③ gate
`make php.lint.person-reference`.
**Nota de contrato, porque cambió después del corte:** SI-22 ya **no exige generar** el registro
(`arch-addendum-gdpr-hardening.md`, y FR3/NFR2 reconciliados en el mismo PR). Generar es implementación
admisible, no exigida — y aquí no compra nada: un generador solo emite la **clave**, que es justo lo que las dos
direcciones ya clavan, y **no puede aportar el dueño de borrado**, que es el único campo con significado.
**Esta historia NO construye un generador.**
**Invariantes que consume:** el patrón de la casa (registro revisable + gate obligatorio), NFR8.
**Invariantes que establece:** SI-21/NFR1, SI-22/NFR2, SI-23/NFR3.
**Dependencias:** ninguna. Habilita 1.3 (G-1b) y 1.4 (G-2).

## Estado medido (`main` @ `9310efeb`)

`api/` **sí** se movió desde el corte (`471ae66f`): el PR #613 (G-4a) aterrizó en `0d2d45d2` y trajo el precedente
más fresco de registro + gate. Todo lo de abajo se midió contra `9310efeb`.

### La capability que se replica — tres ficheros, medidos

`api/src/Shared/Privacy/` tiene **exactamente tres ficheros** y ninguna otra pieza (ni CLI, ni registro, ni config):

1. **`api/src/Shared/Privacy/Domain/PersonalData.php:16-19`** — `#[Attribute(Attribute::TARGET_PROPERTY)] final class
   PersonalData {}`. **Cero parámetros, cuerpo vacío, no repetible, `final`.** Su docblock (`:9-15`) es el
   argumento de FR2: *«infrastructure … only reads it to decide encrypt-vs-clear per column»* — contrato de
   **tratamiento**.
2. **`api/src/Shared/Privacy/Application/PersonalDataClassifier.php:11-19`** — un método:
   `personalFieldsOf(object|string $entityOrClass): array`, `list<string>` *«sorted for determinism»*.
3. **`api/src/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifier.php`** —
   `#[AsAlias(PersonalDataClassifier::class)]` (`:12`), caché por FQCN (`:15,:22`), `getProperties()` sin filtro
   (`:34`), `getAttributes(PersonalData::class)` (`:35`), `sort()` (`:40`).

**Consumidor de producción: uno solo.** `api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php:49`.
Anotaciones `#[PersonalData]` vivas: **tres, todas promovidas en el constructor de `BankAccount`**
(`api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:50,65,72`).

**Cableado DI:** el `#[AsAlias]` basta — `api/config/services.yaml:23-27` autoregistra `../src/` excluyendo
`Domain/Entity/`. Un adaptador nuevo con `#[AsAlias]` **no requiere tocar YAML**, y un atributo con parámetro
escalar requerido **tampoco rompe el contenedor**: los servicios privados sin consumidor se podan antes de
resolver argumentos, y el repo ya tiene el contraejemplo vivo —
`api/src/Shared/Validation/Infrastructure/EnumType.php:30-32` es un atributo con `public string $enumClass`
requerido, dentro del mismo recurso autoregistrado, y el contenedor compila.

**Deptrac:** `api/tools/deptrac/deptrac.yaml:132-138` usa colectores `src/Shared/(.*/)?Domain/.*`, que auto-pliegan
los módulos anidados. Un atributo nuevo en `Shared/Privacy/Domain/` **no necesita registro propio**, y la regla
`&domain` (`:192-205`) admite `Shared.Domain` desde los nueve layers `*.Domain`.

### Las dos semillas — quién las borra hoy, medido propiedad a propiedad

| Propiedad | Declaración | Columna | ¿La cadena la borra? | Dueño (el acto) |
|-----------|-------------|---------|----------------------|-----------------|
| `PasswordResetToken::$userId` | `api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php:39-40`, `private string`, `Types::GUID`, **declarada en el cuerpo** | `identity_password_reset_token.user_id` | **SÍ** — borra la **fila entera** | `api/src/Iam/Identity/Application/EraseIdentitySubject.php:43` → DQL `DELETE` en `DoctrinePasswordResetTokenRepository.php:66-77` |
| `Session::$userId` | `api/src/Iam/Session/Domain/Entity/Session.php:37-38`, `private string`, `Types::GUID`, **declarada en el cuerpo** (sus vecinas sí son promovidas, `:50-57`) | `iam_session.user_id` | **SÍ** — borra la **fila entera** | `api/src/Iam/Session/Application/PurgeUserSessions.php:29`, invocado desde `api/src/Iam/Identity/Application/FulfilIdentityErasure.php:112` → DQL `DELETE` en `DoctrineSessionRepository.php:105-116` |

Ninguna lleva `#[PersonalData]`. **Las dos semillas usan la forma declarada en el cuerpo, y esa forma no la
ejercita ningún test hoy**: las tres anotaciones vivas de `BankAccount` son promovidas, y
`ReflectionPersonalDataClassifierTest` tiene 3 tests (clase, instancia, vacío) que no la tocan. Que
`getProperties()` cubra ambas formas es cierto por semántica de PHP, **no está medido en este repo** — conviértelo
en fixture (Tarea 3) en vez de asumirlo.

**Las referencias huérfanas (trabajo de G-1b, contexto aquí):**

| Propiedad | Fichero:línea | ¿Erasure? |
|-----------|---------------|-----------|
| `Membership::$userId` | `api/src/Organization/Membership/Domain/Entity/Membership.php:30-31` | **NO** — `MembershipRepository::remove()` existe (`MembershipRepository.php:21`) y **no tiene llamante en `src/`** |
| `Invitation::$invitedUserId` | `api/src/Iam/Invitation/Domain/Entity/Invitation.php:49-50` | **NO**, y peor: `InvitationRepository` **no expone remove/delete** (solo `save`/`findById`/`findByIdForUpdate`, `:19,:21,:29`) |

### La plantilla exacta del gate y del registro — patrón de la casa, ya medido

- **Colocación del registro:** raíz de `api/`, sin extensión, tracked. Cinco precedentes:
  `.audit-resource-types`, `.bounded-context-allowlist`, `.error-contract-allowlist`, `.event-dispatch-allowlist`,
  `.persistent-transport-policy`.
- **Vocabulario, y es TRI-ESTADO — no binario.** `api/.persistent-transport-policy:10-13` admite tres formas:
  `<Clave> => non-person`, `<Clave> => person` **sin ruta**, y `<Clave> => person :: <ruta>`. La forma sin ruta
  **está viva y verde** (`:50`, `Iam.Identity => person`), y el motor la modela explícitamente
  (`api/tests/Support/PersistentTransportPolicy.php:26-31`: `null` = non-person, constante vacía = persona sin
  excepción sancionada, cualquier otro string = ruta). `api/.audit-resource-types:8-10` solo declara dos formas.
  **Esta diferencia es load-bearing para D1** — no la aplanes.
- **Cómo se valida un dueño** (`api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php:82-105`):
  ruta existe, y luego, **sobre la fuente sin comentarios** (`codeWithoutComments()`, `:249-262`), exige propiedad
  del tipo colaborador (`:91`), **la llamada** (`:98`) y el literal del tipo (`:105`). El comentario dice por qué
  se descartan los comentarios: *un docblock que nombra al colaborador es intención, y una intención no sustituye
  a la llamada*. **Ese triple check ES la mitad «correctamente cableada» de SI-21** — no es adorno.
- **`is_file()` y no `assertFileExists()`**: `assertFileExists` es `file_exists`, cierto para un **directorio**, así
  que `person :: docs` silenciaría la política sin ADR alguno. Corregido en
  `api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php:133-137` (`is_file()` + sufijo `.md`).
- **Parser de líneas:** `api/tests/Support/AllowlistFile.php:26`. `PersonResourceErasureGateTest` aún parsea a mano
  (`:145`), **rechaza duplicados** (`:170-173`) y toda clasificación no reconocida (`:181-191`), nunca la degrada.
- **Forma del test:** `#[CoversNothing]` a nivel de clase (las **11** clases de
  `api/tests/Unit/Shared/Architecture/` lo llevan; ninguna `#[CoversClass]`), `public const string
  FAILURE_PREAMBLE` para que CI la greppee (`EventDispatchGateTest.php:33-40`), camino verde con
  `addToAssertionCount(1)` (`:50-68`), anti-vacuidad (`:70-82`) y fixture sucio + gemelo limpio (`:84-112`).
- **Motor fuera del test:** `api/tests/Support/PersistentTransportPolicy.php:17-22` (*«so the rules are exercisable
  independently of the assertions»*). Su `eventsInSource()` (`:90-124`) es el molde del generador: recorre
  `ApiSourceFiles::phpFiles()`, reconstruye el FQCN por PSR-4, `class_exists`, descarta abstractas, filtra por
  herencia — *«Reflection rather than a regex»*.
- **Cableado del target** (`make/php-quality.mk`): sección + comentario + `@$(PHP_TEST) bin/phpunit
  --filter=<Clase>` (`:110-111`, `:120-121`), y **tres** inserciones: `:158`, `:176` y `.PHONY` (`:178-187`).
- **Cabecera del registro, cinco bloques** (`api/.persistent-transport-policy:1-44`): qué es y cuál es la clave · la
  regla con su porqué material · `Format, one per line:` · la clase del gate **y** el target `make` · bloque
  delimitado por guiones *"What it deliberately does NOT do"* con la frase que es el contrato con el lector:
  *«a green build proves what is below the line, and nothing above it»*.

### Hechos medidos que el corte de épica NO registra — léelos antes de decidir

**(A) El justificante de FR2 es FALSO en su último eslabón — y el correcto es PROSPECTIVO, no vivo.**

La épica (`epics-gdpr-hardening.md:70-74`) y el addendum (`arch-addendum-gdpr-hardening.md:25`) afirman que anotar
una clave foránea con `#[PersonalData]` haría que `PiiDiffSealer` *«la cifrara en el diff, rompiendo las búsquedas
que el propio reconciliador de erasure necesita»*. Medido:

- **`PiiDiffSealer` solo corre sobre `AuditedEntity`** — filtro en
  `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php:78`, llamada a `seal()` en `:87`.
  Lo implementan **solo** `Bank` (`Bank.php:29`) y `BankAccount` (`BankAccount.php:39`); `User` (`:37`), `Session`
  (`:29`) e `Invitation` (`:38`) declaran que no, y `PasswordResetToken` tampoco.
- **El sellador nunca toca la columna de la entidad.** Escribe `audit_log.metadata` y `encryption_scope_id`
  (`AuditWriteCaptureListener.php:87-100`). Prueba viva: `bank_account.iban` **está** marcado
  (`BankAccount.php:65`) y sigue `unique` y buscable en claro.
- **El reconciliador no lee ningún valor de diff.** `ReconcileErasedSubjectReferences.php:41-52` lee
  `SELECT DISTINCT resource_id FROM audit_log …` (`DbalPersonResourceReferences.php:36-41`) y `identity_user.id`
  por PK (`DoctrineUserRepository.php:42-45`).

**No re-propongas reutilizar `#[PersonalData]`: la conclusión de la épica es correcta, su argumento no.** Los dos
que la sostienen:

1. **Asignaría el dueño de borrado equivocado.** El scope de cifrado es `<ResourceType>:<id>` del **agregado
   propietario** (`PiiDiffSealer.php:55-56`), así que el id de la persona quedaría bajo la DEK de *ese* agregado:
   destruido con el borrado del propietario, superviviente al de la persona. **Honestidad debida: este argumento
   es PROSPECTIVO, no vivo** — con ninguna de las cuatro entidades siendo `AuditedEntity`, nada se sella hoy y el
   fallo tampoco ocurre. Es exactamente la razón por la que el argumento de la épica se declara falso, así que se
   etiqueta igual en vez de aplicarse doble rasero.
2. **El puerto no tiene dónde llevar un dueño.** `personalFieldsOf()` devuelve `list<string>`
   (`PersonalDataClassifier.php:18`). Reutilizarlo obligaría a cambiar la firma, con `PiiDiffSealer` como único
   consumidor afectado. **Este sí es estructural y vive hoy.**

**(B) La contradicción NO es FR4 consigo mismo: es FR4 contra FR5 y contra el AC formal de G-1b.**

FR4 dice *«llega verde»* (`epics-gdpr-hardening.md:91`) y *«cada hueco **posterior** es un gate en rojo»*
(`:93-94`, reforzado en `:314` con *«a partir de ahí»*). Leídas literalmente son **compatibles**: describen un
**trinquete** —verde al aterrizar, rojo para lo que llegue después—, que es el patrón que el repo ya implementa
(`api/tools/deptrac/deptrac.baseline.header.txt`: *«They predate the gate; it stays green today and fails on any
NEW inner-layer leak»*).

El choque real está en otras dos frases, y son **más duras que un párrafo de FR**:

- **FR5** (`:97`): *«**con el gate en rojo**, la cadena de erasure … adquiere dueño para `Membership::$userId` e
  `Invitation::$invitedUserId`»*.
- **El segundo AC formal de la Story 1.3** (`:488-490`): *«**Given** el gate de 1.2 **en rojo por esas dos
  referencias**, **When** se cierra la historia, **Then** pasa a verde **porque la cadena las ejecuta**, no porque
  se hayan declarado (SI-23)»*.
- **El DAG del addendum** (`arch-addendum-gdpr-hardening.md:44`): *«G-1b (#545 + #561; **el gate se pone rojo** y
  la historia lo cierra)»*.

Es decir: **el contrato ya exige que G-1a aterrice con el gate en rojo sobre esas dos referencias**, y a la vez
que «llega verde». La decisión de D1 no es *qué mecanismo prefiero* sino **qué frase de la épica es autoritativa**,
y la opción elegida obliga a corregir la otra **en este mismo PR**.

**(C) El dueño de borrado NO puede ser una referencia de clase (`::class`).**

`Session` vive en `Iam/Session/Domain` y su dueño es `Erpify\Iam\Identity\Application\FulfilIdentityErasure`: un
`use` cross-context desde `Domain/` es fallo **Nivel 1** de `BoundedContextGateTest` y violación deptrac
(`deptrac.yaml:192-205`). Incluso same-context, `PasswordResetToken` → `FulfilIdentityErasure` es **Domain →
Application**. Va como **ruta en string**, validada por lectura de fichero (`.audit-resource-types:24`).

**(D) El clasificador actual NO descubre clases, y su punto ciego real NO es el de G-4a.**

- `ReflectionPersonalDataClassifier` **recibe** la clase del llamante (`:18-23`). En `Shared/Privacy/` no hay
  enumeración de clases: *«análogo a `ReflectionPersonalDataClassifier`»* describe solo la mitad barata.
- **Cuidado con la analogía fácil.** El defecto I-2 de G-4a (`IS_INSTANCEOF` + `class_parents()`) existe porque
  **PHP no hereda atributos de CLASE**. Aquí el atributo es `TARGET_PROPERTY`, y `getProperties()` ya devuelve las
  propiedades heredadas con sus atributos intactos y aplana las de trait. Copiar el recorrido de padres de G-4a
  **no compra nada**. El hueco real y distinto: `getProperties()` **no ve propiedades `private` de una clase
  padre** — única en el repo, `AggregateRoot::$domainEvents`
  (`api/src/Shared/Kernel/Domain/Aggregate/AggregateRoot.php:23`). Y `$id` no lo declara una clase abstracta sino
  el **trait** `api/src/Shared/Kernel/Domain/Entity/Identifiable.php:19-22`, así que `getDeclaringClass()` devuelve
  la clase que lo usa: no sirve para filtrarlo.

**(E) No hay generador, y esta medición es la que lo enterró. Se conserva para que nadie lo reproponga.**

SI-22 se reescribió (2026-08-01) y ya **no exige generar**; FR3/NFR2 quedaron reconciliados en este mismo PR. Lo
que sigue es la medición que hace de esa decisión algo firme y no una preferencia:

- **No existe precedente en el repo.** Los únicos targets que reescriben un artefacto son `php.stan.baseline`
  (`php-quality.mk:11-12`, fichero **no tracked**) y `php.deptrac.baseline` (`:147-148`), que es un **script
  shell** (`api/tools/deptrac/regen-baseline.sh`). Ninguno de los **14** comandos Symfony de `api/src` escribe un
  fichero del árbol, y `docs/rules/` no tiene regla sobre artefactos generados.
- **El coste que se evita, enumerado, porque es el riesgo que la historia deja de correr:** orden determinista
  antes de escribir (un `RecursiveDirectoryIterator` sin `ksort` da rojo en CI y verde en local), cabecera en
  fichero aparte y fuera de la comparación de deriva, escritura segura sin `mv` (el contenedor corre como root y
  dejaría el fichero root-owned en el bind mount), y la primera ejecución sobre un destino inexistente.
- **Y lo que decide el asunto no es el coste sino el alcance:** un generador solo puede emitir la **clave**, y la
  clave ya queda clavada por las dos direcciones de FR3. El **dueño de borrado** —único campo con significado— es
  juicio humano y **ningún generador puede aportarlo**. Generar compraría la mitad ya comprada.

**Alcance exacto de la garantía read-only** (queda porque acota el gate, no el generador desaparecido):
`make/php-quality.mk:165-166` compromete a **los prerrequisitos de `php.quality.dry-run`** (`:176`), no a todo el
fichero. **El gate no escribe, punto**: un control que reescribe lo que comprueba lee verde por construcción.

**(F) El gate de G-4a está fuera del gate de LINT, no fuera de CI. La distinción importa.**

`make/php-quality.mk:121` filtra `PersistentTransportPolicyGateTest`; la segunda clase que #613 creó
(`PersistentTransportRoutingShapeGateTest`) no casa con ese regex. Medido con ejecución fresca:
`--filter=PersistentTransportPolicyGateTest --list-tests` → **7 tests**; la clase de formas → **13**;
`--filter='PersistentTransport.*GateTest'` → **20**.

**Pero esos 13 SÍ corren en CI**: `.github/workflows/ci.yml:122` invoca `make php.unit.coverage`, que en
`make/php-test.mk:49` es `bin/phpunit` **sin `--filter`** — la suite entera, y el propio comentario del workflow
declara que sigue siendo el gate de tests. Lo que se pierde es **diagnosticabilidad de frontera**: una
reintroducción de las cinco vías de routing rompe «PHPUnit», no «la política de transporte persistido», que es lo
que el cableado uno-a-uno de los `php.lint.*` existe para decir. **Si G-1a parte su gate en dos clases, el
`--filter` debe ser un prefijo regex común** — por la misma razón, y no por cobertura.

## Decisiones registradas (precondición normativa de la épica: SATISFECHA)

**Decidido:** 2026-08-01. **Quién:** Sergio. **Dónde queda el registro:** este bloque, y el cuerpo del PR debe
reproducirlo. El corte no las marcó porque nacen de mediciones que el corte no hizo, primero por el pase
adversarial del contrato (registrado abajo) y después por un pase de medición independiente que corroboró D2 y
D3 por separado y **refutó las alternativas de D1 que llegaban verdes**, por romper el segundo AC de la Story
1.3 (`epics-gdpr-hardening.md:488-490`), que presupone el gate **en rojo** por esas dos referencias.

### D1 — ¿Qué frase de la épica es autoritativa? · **ELEGIDA: ①** (el gate aterriza en rojo)

**Toda opción corregía contrato. La pregunta nunca fue qué mecanismo, sino qué frase manda.**

| Opción | Qué hace | Qué cuesta y qué contrato reescribe |
|--------|----------|-------------------------------------|
| **①** ✅ **ELEGIDA. Completitud estructural + dueño declarado.** El universo lo deriva el gate del código; el atributo aporta el dueño. Toda propiedad del universo sin línea, o con `person` y sin dueño → **rojo** | Es el estado que **FR5 (`:97`) y el AC de G-1b (`:488-490`) ya exigen**. Detección viva desde el día 1 | El rojo es real y **tiene que existir**, pero **no vive en `main`: vive dentro de la PR.** G-1a y G-1b se entregan **juntas** (Sergio, 2026-08-01), así que el gate aterriza rojo y sale verde en la misma entrega, *porque la cadena ejecuta las dos referencias*. `main` nunca se rompe y `CLAUDE.md` → *Gates first* se sigue pudiendo cumplir. Obliga a corregir el *«llega verde»* de FR4 (`:91`, `:314`, `:418`, `:449`) — **hecho en este PR** |
| **②** **Solo lo declarado** (los dos checks del corte, literal) | Llega verde | **Vetada, y no por gusto:** con ② el universo **es** el conjunto de propiedades anotadas, luego la afirmación verificada tiene como única evidencia las propias declaraciones — que es SI-23 (`una declaración nunca es su propia evidencia`), el invariante que esta historia declara **establecer**. Además obliga a reescribir el AC de G-1b (`:488-490`) y la anotación del DAG |
| **③** **Completitud estructural + sanción explícita** de las dos referencias conocidas, apuntando a un artefacto real | Verde al llegar **y** detección viva para la referencia siguiente | Necesita **ancla real** (un ADR, no un fichero `_bmad-output/` que la higiene BMAD puede borrar). Y choca con AC1: una línea sancionada necesita **verbo propio** (`deferred :: <ruta>`), porque `person :: <ruta>` significa *«éste es su dueño»* y ahí no hay dueño. Reescribe el AC de G-1b |
| **④** **Tri-estado del precedente:** `Membership::$userId => person` **sin ruta**, día 1 | Completo, verde, sin dueño falso. Es la gramática que `.persistent-transport-policy:12` admite y `:50` usa en vivo | **Descartada por mal port del precedente** — ver abajo |

**Por qué ①, en orden de peso:**

1. **El rojo es una afirmación verdadera.** `Membership.user_id` e `Invitation.invited_user_id` **no tienen dueño
   de borrado hoy**. Un control verde mientras el defecto está vivo es prosa con pasos extra — exactamente lo que
   la épica existe para eliminar (*«un control que puede ponerse rojo»*).
2. **④ conserva la gramática del precedente y pierde su semántica.** En `.persistent-transport-policy`,
   `Iam.Identity => person` es verde **porque la condición peligrosa —estar enrutado— está ausente**, y el tercer
   estado (`person :: <adr>`) existe justo para cuando *sí* lo está. Portado aquí, la condición peligrosa es **no
   tener dueño**, y en esas dos referencias **está presente**. El port fiel del precedente es rojo. ④ diría
   *«esto es una persona, nadie la borra, build verde»*, degradando SI-21 de *«tiene un dueño identificado»* a
   *«está declarada»*. **No la re-propongas sin refutar esto.**
3. **El propósito de FR4 sobrevive a ①.** La épica pedía *«llega verde»* por una razón que escribe: *«el mecanismo
   se estrena sobre comportamiento correcto»*. Bajo ①, **las dos semillas son verdes y demuestran justo eso**; el
   rojo viene de dos referencias distintas y genuinamente rotas. Lo que ① incumple es la **redacción literal** de
   FR4, no su intención — mientras que FR5, el AC de G-1b y el DAG piden el rojo de forma **operativa**: sin él,
   el segundo AC de la Story 1.3 es insatisfacible. Una frase de conveniencia pierde contra un criterio de
   aceptación de otra historia.

**② queda vetada por SI-23** (su universo *es* el conjunto de declaraciones, luego la afirmación verificada tiene
como única evidencia las propias declaraciones) y **③ por su ancla**: necesitaría un ADR para sancionar algo
deliberadamente temporal, que es lo contrario de un ADR.

### D2 — ¿Dónde vive el motor de descubrimiento? · **ELEGIDA: híbrida** (atributo en `Domain/`, motor en `tests/Support/`)

> **La pregunta original era «dónde vive el generador», y la reescritura de SI-22 la disolvió a medias: no hay
> generador.** Lo que queda —y sigue haciendo falta— es el **motor de descubrimiento** que deriva el universo por
> reflexión para alimentar las dos direcciones de FR3. La respuesta no cambia; el alcance sí, y a menos.

**Lo decidido, en una línea:** el **atributo sí va en `api/src/Shared/Privacy/Domain/`** — metadata pasiva que las
entidades importan, deptrac la auto-pliega, coste cero. **El puerto y el adaptador NO se construyen**, y el motor
de descubrimiento vive en `api/tests/Support/PersonReferences.php`, **en modo solo lectura**.

**El argumento, que no es estilístico:** `PersonalData` tiene forma de tres piezas **porque `PiiDiffSealer` consume
su puerto en producción**. Este registro **solo lo lee el gate**. Un puerto sin nadie a quien inyectarlo es
superficie de producción a cambio de nada (YAGNI — `docs/project-context.md`). Y `PersistentTransportPolicy` es el
precedente **medido** de esta forma exacta: registro consumido solo por un gate, motor en `tests/Support`. El día
que aparezca un consumidor de producción —la cadena de erasure leyendo el registro para *dirigir* el borrado—
promoverlo a `Infrastructure/` es un **movimiento, no una reescritura**.

**Restricción ratificada que esto refuta, citada en vez de rodeada:** `epics-gdpr-hardening.md:219-221` — *«El
hermano replica la estructura»*. Esa viñeta se escribió asumiendo simetría con `PersonalData`, antes de medir que
el hermano no tiene consumidor. **Corregida en este PR.**

| Opción | Coste medido |
|--------|--------------|
| **① Motor solo-lectura en `api/tests/Support/PersonReferences.php`** (espejo de `PersistentTransportPolicy`) | ✅ **ELEGIDA.** Donde ya viven los gates, sin arrancar kernel, sin escribir nada. **Contradice `epics:219-221`**, corregido en este PR |
| **② Adaptador en `Shared/Privacy/Infrastructure/`** + comando bajo `Infrastructure/Cli/` | **Era lo que la épica ratificó.** Cuesta arrancar el kernel para alimentar un gate que es puro `TestCase`, y deja código de producción sin consumidor de producción (YAGNI, `docs/project-context.md`) |
| **③ El propio test con `--update`** | **Muerta dos veces.** Ya no hay nada que actualizar (SI-22 no exige generar), y aunque lo hubiera: un control que reescribe lo que comprueba lee verde por construcción — #563 — y rompería el read-only de `php.quality.dry-run` |

**Nota de alcance del veto de ③:** lo prohibido es que **el gate** escriba. Una **clase distinta** en modo escritura
invocada desde un target propio (`php.lint.person-reference.regen`) no toca la garantía de `:165-166` — es el
patrón de los dos baselines.

### D3 — Forma de la clave y del universo · **ELEGIDA: dos estados, universo completo**

- **Clave:** `<Fqcn>::$<propiedad>` — derivable por reflexión, de la granularidad exacta del hecho (es la lección
  I-1 de G-4a aplicada: allí la clave resultó **más gruesa** que la propiedad que clasificaba y dio luz verde a la
  fuga).
- **Dos estados, no tres:** `<Fqcn>::$prop => non-person` | `<Fqcn>::$prop => person :: <ruta relativa a api/>`.
  Coherente con D1: `person` **sin** dueño *es* la violación, así que no puede ser un estado válido. Si algún día
  hace falta sancionar una excepción, lleva **verbo propio** (`deferred :: <adr>`), **nunca** `person` sin ruta.
- **Universo: TODAS las columnas `Types::GUID` de entidad, no las que "suenan" a persona.** Son ~16 líneas, la
  mayoría `non-person`. Es lo que hace `.persistent-transport-policy` —clasifica *todos* los `aggregateType`, no
  los sospechosos— y es lo que elimina el punto ciego que el pase adversarial destapó: `$actorId`, `$customerId`,
  `$createdBy` no casan con `*user*_id` y hoy pasarían en silencio. `audit_log.actor_id` es el contraejemplo vivo.

**Y esto cierra el hueco de SI-22 que el contrato tenía abierto.** La **clave** es huella del código, pero la
**clasificación persona/no-persona es juicio humano** (`organizationId` y `bankId` también son `Types::GUID`), y
SI-22 exige segundo testigo para lo manual. **El segundo testigo ya está en el diseño: es el check de cableado de
AC1.** Clasificar `organizationId` como `person` te obliga a nombrar un fichero que lo borre — y no existe, así que
el gate se pone rojo: **la clasificación falsa-positiva se refuta sola, sin declaración que la avale.** Lo que
queda descubierto es la dirección contraria —llamar `non-person` a una persona— y **eso va a la cabecera como punto
ciego con nombre propio**, no se calla. *(Nota de frontera: esto no invade la Story 1.5, que trata el segundo
testigo de la entrada **manual** de `.audit-resource-types`; aquí el testigo sale del mecanismo, no de un artefacto
nuevo.)*

## Acceptance Criteria

**AC1 — El gate falla cuando una referencia persistida a persona no declara dueño, Y cuando el dueño declarado no
la borra (FR4, SI-21).**
**Given** una columna `Types::GUID` de entidad sin línea en el registro (**el universo es completo, D3**),
**When** corre `make php.lint.person-reference`,
**Then** el gate **falla**, nombrando `<Fqcn>::$prop` y lo que falta.
**Y Given** una línea `person` **sin dueño**, o `person :: <ruta>` cuyo fichero existe pero **no ejecuta el
borrado de esa propiedad**,
**When** corre el gate,
**Then** **falla igualmente**. Esta segunda mitad es la parte *«correctamente cableada»* de SI-21
(`epics-gdpr-hardening.md:158-159`): sin ella, `person :: <cualquier fichero existente>` pasa y el registro
degenera en documentación, que es SI-23.
*Precedente del check:* `PersonResourceErasureGateTest.php:89-109` (propiedad del colaborador + **la llamada** +
el literal, sobre fuente sin comentarios). Para las semillas, lo que debe probarse ejecutado es
`deleteAllForUser` en `EraseIdentitySubject.php:43` y `PurgeUserSessions.php:29`.
**Y es además el segundo testigo de SI-22** (D3): una clasificación `person` falsa-positiva —`organizationId`,
`bankId`— no encuentra fichero que la borre y **se refuta sola**, sin declaración que la avale.
*Estado esperado al aterrizar:* **rojo**, por `Membership::$userId` e `Invitation::$invitedUserId`. Es el
resultado correcto, no un defecto que arreglar.

**AC2 — El registro se verifica contra el código en las DOS direcciones (FR3, SI-22, SI-23).**
**Given** un miembro del universo derivable **sin línea** en el registro,
**When** corre el gate,
**Then** **falla** (dirección 1: completitud).
**Y Given** una entrada del registro que **ya no corresponde a ningún miembro** del universo,
**When** corre el gate,
**Then** **falla** (dirección 2: obsolescencia — el registro es inventario vivo, no cementerio).
*Con las dos comprobadas, el contenido queda clavado a la fuente por ambos lados y una entrada **no puede
satisfacerse a sí misma**: es la propiedad de SI-22, y se obtiene **sin generar el fichero**.* Precedente exacto:
`everyAggregateTypeInSourceIsClassified` + `theRegistryDeclaresNoAggregateTypeThatNothingEmits` en
`PersistentTransportPolicyGateTest`.
*Cómo se pinna:* añadir al fixture una propiedad del universo sin línea → rojo; borrar del fixture la propiedad
que una línea nombra → rojo. **La primera es la que distingue detección de documentación** — sin ella, un gate
que solo verifica lo declarado pasa vestido de detector.

**AC3 — El atributo declara referencia + dueño, y no arrastra la FK al crypto-shredding (FR2, NFR8).**
**Given** una entidad `AuditedEntity` con un campo `#[PersonalData]` y una referencia a persona anotada con el
atributo nuevo,
**When** se sella su diff de auditoría,
**Then** el campo personal viaja cifrado y **la clave foránea viaja en claro**.
*Sí es ejecutable, y el arnés ya existe:* `api/tests/Unit/Shared/Audit/Infrastructure/Persistence/PiiDiffSealerTest.php:77`
construye `new PiiDiffSealer(new ReflectionPersonalDataClassifier(), $encryptor)` —el clasificador **real**— y lo
conduce con fakes `AuditedEntity` (`PlainAuditedFake`, `AuditedSubjectFake`). Un fake nuevo con las dos
anotaciones cierra el AC del corte tal como estaba escrito.
*Lo que NO vale como evidencia:* «un test de que `personalFieldsOf()` ignora el atributo nuevo». `PersonalData` es
`final` (`PersonalData.php:17`) y el lector no usa `IS_INSTANCEOF` (`ReflectionPersonalDataClassifier.php:35`):
ese test es **verde por construcción del lenguaje** y no puede fallar nunca.
**Y la mitad positiva de FR2, que también es AC:** el atributo es `TARGET_PROPERTY`, lleva el dueño como `string`
(hecho (C)), y **la línea generada para una propiedad anotada reproduce ese dueño** — falsable anotando una
propiedad fixture y regenerando.

**AC4 — El estreno del mecanismo sobre comportamiento correcto (FR4).**
**Given** el registro commiteado,
**When** se inspecciona,
**Then** contiene exactamente las dos líneas semilla con sus dueños medidos —
`PasswordResetToken::$userId => person :: src/Iam/Identity/Application/EraseIdentitySubject.php` y
`Session::$userId => person :: src/Iam/Session/Application/PurgeUserSessions.php` — y **ambas superan el check de
cableado de AC1**.
**Given** esas dos líneas,
**When** corre el gate,
**Then** sale **verde sobre ellas** — el mecanismo se estrena sobre comportamiento correcto.
*El rojo global del gate proviene **solo** de `Membership`/`Invitation` y lo cubre AC1.* No escribas un AC que
exija `make php.quality` verde: bajo D1=① es rojo a propósito.

**AC5 — La cabecera del registro declara qué NO detecta (FR3).**
**Given** el registro nuevo,
**When** se lee su cabecera,
**Then** enumera sus puntos ciegos siguiendo `api/.persistent-transport-policy:19-44`, incluyendo **como mínimo
los cuatro medidos**:

1. **No juzga la clasificación** — persona/no-persona es decisión humana sometida a revisión.
2. **No alcanza referencias nacidas en configuración** (FR9/G-4b).
3. **No alcanza las tablas sin entidad Doctrine** — `audit_log.actor_id` (`AuditLogSchemaListener.php:44`),
   `resource_id` (`:47`) y `metadata` (`:48`), y `event_store.aggregate_id`
   (`EventStoreSchemaListener.php:42`): las inyectan listeners `postGenerateSchema` y se escriben por SQL crudo,
   así que **ninguna propiedad de dominio las declara** y ninguna reflexión sobre propiedades las ve.
   `event_store.aggregate_id` es además **la fuga permanente de 1.7/G-5**.
4. **La dirección de error que el mecanismo NO refuta: llamar `non-person` a una persona.** Por D3 el universo es
   toda columna `Types::GUID` de entidad (**16** medidas: `BankAccount::$bankId` `:43`,
   `Invitation::$organizationId` `:46`, `Session::$organizationId` `:40`, `Membership::$organizationId` `:33` y
   `Identifiable::$id` `:20` en las 8 entidades, más las cuatro referencias a persona), así que **no hay filtro de
   nombre y ninguna columna se salta**. Y una clasificación `person` falsa se refuta sola (AC1: no hay fichero que
   la borre). Pero un `person` escrito como `non-person` **pasa**, porque el gate nunca juzga la clasificación —
   solo que exista y esté cableada. Ese es el reparto que SI-21 declara (*«el humano clasifica, la automatización
   verifica»*) y **la cabecera lo dice con esas palabras**, junto a que su control es la revisión de arquitectura.

*La cabecera se escribe a mano, como las de los cinco registros existentes* — no hay generador que la re-anteponga
(hecho (E)), así que el parser debe **ignorar comentarios y líneas vacías** igual que
`PersonResourceErasureGateTest.php:145` y `AllowlistFile::entries()`, y ninguna aserción de deriva puede depender
de sus bytes.

**AC6 — Cableado del gate (NFR11).**
**Given** el gate,
**When** se inspecciona su cableado,
**Then** está en `php.quality` **y** en `php.quality.dry-run` **y** en `.PHONY`, porque CI corre el *dry-run*
(`ci.yml:115`).
**Y** si el gate se parte en dos clases, el `--filter` es un **prefijo regex común** que las selecciona todas,
**verificado listando los tests que el target selecciona** — no razonándolo (hecho (F)).

**AC7 — El gate verifica y NO escribe (FR4, SI-23).**
**Given** el registro commiteado,
**When** corre `make php.lint.person-reference`,
**Then** su contenido es **byte-idéntico** antes y después. Un control que reescribe lo que comprueba lee verde
por construcción — #563 — y además rompería el `read-only / parallel-safe` que `make/php-quality.mk:165-166`
declara para toda la lista. *No hay nada que generar (hecho (E)), así que este AC es barato: pínnalo igual, es la
única aserción que impide que alguien "arregle" un rojo escribiendo el registro desde el gate.*

**AC8 — Sin regresión, y el rojo intermedio acotado.**
`make php.quality`, `make php.unit`, `make php.behat`, cada uno desde **ejecución fresca con exit code impreso**.
Al terminar la entrega **todo está verde**, porque G-1b cierra las dos referencias dentro de la misma PR. En el
commit intermedio —el que instala el mecanismo antes de que la cadena ejecute— **el único fallo admisible es
`php.lint.person-reference`, y solo por `Membership::$userId` e `Invitation::$invitedUserId`**; se declara en el
PR nombrando esas dos referencias, porque ese rojo **es la evidencia** del segundo AC de la Story 1.3. Cualquier
otro rojo, y cualquier rojo del gate nuevo por otra propiedad, es una regresión.

## Tasks / Subtasks

- [x] **Tarea 1 — Registrar D1/D2/D3 y corregir el contrato perdedor (PRECONDICIÓN).** HECHA: ver *Decisiones
      registradas*. Reprodúcelas en el cuerpo del PR; **no las re-abras**.
  - [x] D1=① → corregido el *«llega verde»* de FR4 en sus cuatro localizaciones (`epics:91`, `:314`, `:418`,
        `:449`); el AC de Story 1.3 (`epics:488-490`) y el DAG (`arch-addendum:44`) **se conservan intactos**,
        que era el punto.
  - [x] D1① se entrega con **G-1b en la misma PR** (Sergio, 2026-08-01), de modo que el rojo del gate vive
        **dentro** de la entrega y `main` nunca se rompe. Eso disuelve el conflicto con `CLAUDE.md` → *Gates
        first* en vez de declararlo: no hay ventana en la que empujar exija saltarse un gate. El rojo intermedio
        sigue siendo obligatorio y visible en la secuencia de commits, porque es la evidencia del AC de 1.3.
  - [x] D2 → corregido `epics:219-221` (*«el hermano replica la estructura»* → replica **solo el atributo**, con
        la razón medida).
  - [x] Corregida la justificación de FR2 en la épica y la de `#[PersonalData]` en `arch-addendum:25`, que el
        hecho (A) mide como falsas: sustituidas por el argumento del **dueño de cifrado equivocado** y el de la
        **firma del puerto**. NFR8 (`epics:220-221`) delega en FR2 (*«razón medida en FR2, no estética»*), así
        que queda correcta sin tocarla.
  - [x] Reconciliada la reescritura de **SI-22**: FR3, NFR2, las cláusulas de fallo de FR4, el mapa de cobertura
        y el paso ② de la fila G-1a del addendum. **Ya no se exige generar**, y esta historia **no construye
        generador** (hecho (E), D2).
- [ ] **Tarea 2 — El atributo hermano (AC3)**
  - [ ] `api/src/Shared/Privacy/Domain/<Nombre>.php`, `#[Attribute(Attribute::TARGET_PROPERTY)]`, pasivo, dueño
        como **string** (hecho (C)). Docblock que enuncie el contrato de **referencia**, sin narrar el cambio ni
        citar la historia.
  - [ ] Sembrar `PasswordResetToken::$userId` y `Session::$userId`. **Nunca en el trait `Identifiable`**: se
        propagaría a las 8 entidades.
  - [ ] Un atributo puesto **fuera** del universo derivado (propiedad no persistida, estática, DTO) debe
        **fallar**, nunca caer en silencio — si no, el dev declara un dueño que el registro nunca recoge.
- [ ] **Tarea 3 — El motor de descubrimiento, SOLO LECTURA (AC2, AC7)**
      *Era «el generador». SI-22 dejó de exigirlo (hecho (E)), así que esta tarea encoge a derivar el universo —
      que es lo que alimenta las dos direcciones de AC2— y **no escribe nada**.*
  - [ ] Descubrimiento por `ApiSourceFiles::phpFiles()` + reflexión, molde
        `api/tests/Support/PersistentTransportPolicy.php:90-124`. **No** `getAllMetadata()`: `auto_mapping: false`
        y `src/Shared/` fuera de los mappings (`doctrine.yaml:12-31`).
  - [ ] **El universo es toda columna `Types::GUID` de entidad** (D3), sin filtro de nombre. Cubre propiedades
        **promovidas y declaradas en el cuerpo**; excluye lo que no es propiedad persistida.
  - [ ] **Orden determinista** (`ksort` por `<Fqcn>::$prop`) al comparar y al reportar: el orden del
        `RecursiveDirectoryIterator` no está garantizado entre máquinas, y un mensaje de fallo que baila entre
        ejecuciones es la vía más barata a un rojo en CI que en local no reproduce.
  - [ ] El registro se escribe **a mano**, como los cinco existentes. **Ningún target lo reescribe** — si te ves
        añadiendo uno, para: eso es lo que SI-22 dejó de exigir y lo que AC7 prohíbe al gate.
  - [ ] Fixture de la forma **declarada en el cuerpo** (la de las dos semillas), que hoy no ejercita ningún test.
- [ ] **Tarea 4 — El gate (AC1, AC2, AC5, AC6, AC7)**
  - [ ] Test bajo `api/tests/Unit/Shared/Architecture/`, `#[CoversNothing]`, `public const string
        FAILURE_PREAMBLE`, camino verde con `addToAssertionCount(1)`.
  - [ ] Check de **cableado** del dueño (AC1, segunda mitad), sobre fuente sin comentarios.
  - [ ] Rechazar duplicados y clasificación no reconocida, **nunca degradarla a `non-person`**
        (`PersonResourceErasureGateTest.php:170-173,181-191`).
  - [ ] Validar la ruta con `is_file()`, **no** `assertFileExists()` (acepta directorios) — precedente
        `api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php:133-137`. Y rechazar rutas con
        `..` o fuera de `src/`: `is_file()` las acepta encantado.
  - [ ] Anti-vacuidad **también sobre el árbol de fixtures**: si el FQCN de los fixtures no se reconstruye bien,
        `class_exists` devuelve `false` para todos y el test del gemelo limpio pasa escaneando cero clases.
  - [ ] Evitar el deadlock entre aserciones que #613 pagó (I-7).
  - [ ] Target en `make/php-quality.mk` + las tres inserciones (`:158`, `:176`, `.PHONY`). **Verifica el `--filter`
        listando los tests que selecciona.**
- [ ] **Tarea 5 — Boy-scout propuesto: cerrar el hueco de diagnosticabilidad del filtro de #613 (hecho (F)).**
      `make/php-quality.mk:121` → prefijo regex común, para que un fallo de las cinco vías de routing se reporte
      como frontera rota y no como «PHPUnit». **No es un hueco de cobertura** —esos 13 tests corren en
      `php.unit.coverage`—, así que es mejora de diagnóstico, no fix de seguridad. **Ya está registrado en
      `deferred-work.md` con su medición**: si entra en este PR, **borra esa bala**; si no, se queda ahí.
- [ ] **Tarea 6 — Docs.** Los **seis** sitios que tocó #613: `CLAUDE.md` (*Required checks*),
      `docs/claude-code-quickref.md`, `docs/rules/security.md`, `PRODUCTION_SECURITY_CHECKLIST.md` (un ítem
      cerrado **y uno abierto** por lo que el gate no cierra), `docs/architecture-api.md` y
      `docs/architecture/event-catalog.md` — este último es el segundo cambio documental más grande de #613;
      inclúyelo o argumenta por qué no aplica. Evalúa `api/CLAUDE.md` → *Rules that bite*, que hoy no menciona
      `Shared/Privacy`.
- [ ] **Tarea 7 — Gates y pase adversarial de la IMPLEMENTACIÓN (AC8 + definición de hecho de la épica)**
  - [ ] `make php.quality`, `make php.unit`, `make php.behat` — frescos, con exit code.
  - [ ] Checklist de seguridad de `CLAUDE.md` sobre el diff.
  - [ ] **Pase adversarial sobre el código, por alguien distinto del autor, REGISTRADO.** El pase sobre el
        **contrato** ya está hecho y registrado abajo; éste es el segundo, y ninguno sustituye al otro.
  - [ ] **Su alcance cubre las DOS historias, porque la entrega es conjunta.** Además del eje de G-1a, tiene que
        atacar lo que G-1b trae y esta historia no: la cadena de erasure ejecutando `Membership::$userId` e
        `Invitation::$invitedUserId` —incluido que `InvitationRepository` **no expone hoy remove/delete**
        (`:19,:21,:29`), así que aparece método de puerto nuevo— y el **invariante ≥1 ADMIN activo**, que
        `keepsAnActiveAdminWithout` lee sobre `identity_user` y **no** sobre membership, de modo que una
        membership fantasma con rol `ADMIN` lo deja leyendo satisfecho. Ese invariante es de **seguridad**, es
        el más fácil de romper bajo concurrencia y ya tiene deuda registrada en `deferred-work.md` (#462: la
        guarda corre **fuera** de la transacción de escritura). **Declara en el PR que el pase lo cubrió**, no
        solo que cubrió el gate.

## Pase adversarial — CONTRATO. REGISTRADO (NFR10 / `CLAUDE.md` → *Security review* → **Process**)

**Dónde queda el registro: aquí, y reproducido en el cuerpo del PR #614.** **Cuándo:** 2026-07-31, sobre el
contrato, **antes de escribir código** — que con tres decisiones abiertas es el pase que más rinde.
**Quién:** tres lecturas hostiles independientes por revisores **distintos del autor**, en contexto fresco, con
instrucción explícita de refutar, prohibición de aceptar como cierta ninguna afirmación del artefacto y obligación
de re-medir contra `main` @ `9310efeb`: un pase adversarial general, un cazador de casos límite y un auditor de
aceptación contra `epics-gdpr-hardening.md` + `arch-addendum-gdpr-hardening.md`.
**Alcance declarado:** el artefacto y el código de `api/` que cita. **No cubre:** la implementación (no existe),
PWA, ni ejecución de gates más allá de los listados en *Debug Log References*.
**Veredicto: el eje aguanta; el contrato NO aguantaba.** Los hallazgos de abajo están **incorporados arriba**;
cada uno se re-verificó contra el código antes de aceptarlo, y tres se rechazaron por medición.

### A-1 (ALTA) — el hecho (B) señalaba la contradicción equivocada. **CORREGIDO.**

FR4 consigo mismo es **coherente**: *«llega verde»* + *«cada hueco **posterior** es rojo»* describe un trinquete,
el patrón de `deptrac.baseline.header.txt`. La contradicción real —que yo no cité— es FR4 contra **FR5 (`:97`)**,
contra el **AC formal de Story 1.3 (`:488-490`)** y contra el **DAG del addendum (`:44`)**, los tres exigiendo el
gate **en rojo**. Reescrito el hecho (B) y reencuadrada D1: la pregunta es qué frase manda, y toda opción corrige
contrato.

### A-2 (ALTA) — a D1 le faltaba la opción del precedente que el propio artefacto manda copiar. **AÑADIDA (④).**

`.persistent-transport-policy` es **tri-estado**: `=> person` sin ruta es forma legal (`:12`) y está viva y verde
(`:50`). Mis tres opciones asumían la gramática binaria de `.audit-resource-types`. Añadida ④ con lo que
sacrifica.

### A-3 (ALTA) — D1① se presupuestaba sin su coste dominante. **INCORPORADO.**

El gate entra en `php.quality.dry-run`, que CI corre para **todo PR** (`ci.yml:115`), y el DAG abre G-1b **y G-2 en
paralelo**: ① dejaría `main` rojo para trabajo no relacionado y choca con `CLAUDE.md` → *Gates first*. **Resuelto
entregando G-1a y G-1b juntas** (Sergio, 2026-08-01): el rojo vive dentro de la PR, así que el coste dominante
desaparece en vez de negociarse. G-2 deja de estar acoplada a la ventana y puede correr en paralelo como el DAG
preveía.

### A-4 (ALTA) — mi AC3 no podía fallar. **SUSTITUIDO.**

`PersonalData` es `final` y el lector no usa `IS_INSTANCEOF` (ambos hechos los medía yo mismo): «`personalFieldsOf()`
ignora el atributo nuevo» es verde por construcción del lenguaje. Era el AC vacío que yo acusaba al corte.

### A-5 (ALTA) — «el AC3 del corte no es ejecutable» era FALSO. **RESTAURADO con fixture.**

`PiiDiffSealerTest.php:77` ya conduce el sellador con el clasificador **real** y fakes `AuditedEntity`. El AC del
corte es ejecutable de extremo a extremo y **estrictamente más fuerte** que mi reemplazo.

### A-6 (ALTA) — «universo cerrado de 4 propiedades» era la salida de una heurística de nombre. **CORREGIDO.**

Medido: **16** propiedades `Types::GUID`; de 16 a 4 lo hace enteramente `*user*_id`. `audit_log.actor_id` es
contraejemplo vivo de referencia a persona sin «user». Ahora es punto ciego obligatorio de AC5, con su alternativa.

### A-7 (ALTA) — la mitad *«correctamente cableada»* de SI-21 vivía solo en una rama de AC1. **CORREGIDO.**

Tal como estaba, `person :: <cualquier fichero existente>` pasaba y el registro degeneraba en documentación —
SI-23. AC1 exige ahora el triple check del precedente en **ambas** ramas.

### A-8 (ALTA) — AC4 no podía fallar. **PARTIDO.**

*«El resultado es el que D1 haya decidido»* lo satisface cualquier cosa. Ahora fija lo que es verdad bajo **toda**
opción —las dos semillas, con sus dueños, verdes— y manda lo dependiente de D1 a AC1.

### A-9 (ALTA) — D2 refutaba en silencio una línea ratificada. **CITADA.**

`epics:219-221` (*«el hermano replica la estructura»*) manda adaptador en `Infrastructure/`. Mi recomendación la
contradecía sin nombrarla. Ahora es la restricción explícita de D2, y elegir ① obliga a corregirla en el PR.

### A-10 (ALTA) — el hecho (F) estaba sobredimensionado, y lo publiqué así. **CORREGIDO.**

Yo escribí *«deja 13 de sus 20 tests fuera de CI»*. Medido: `ci.yml:122` corre `make php.unit.coverage` →
`php-test.mk:49` es `bin/phpunit` **sin `--filter`**, la suite entera. Los 13 **sí** corren en CI. El defecto real
—y sigue siendo real— es que quedan fuera del **gate de lint**, es decir de la frontera nombrada. Tarea 5
re-cotizada como diagnosticabilidad. *(La afirmación errónea viajó al cuerpo del PR y a una nota de memoria;
ambas corregidas.)*

### A-11 (MEDIA) — la opción ② de D1 se ofrecía como neutra siendo SI-23. **VETADA.**

### A-12 (MEDIA) — ③ de D2 se vetaba solo por medición operativa. **RE-ORDENADO:** vetada por contrato
(FR4/SI-22: generar ≠ verificar) primero, por medición después. Y el veto se acota: los dos baselines escriben el
árbol desde targets propios sin tocar la garantía de `:165-166`.

### A-13 (MEDIA) — la mitad positiva de FR2 no tenía AC. **AÑADIDA a AC3** (targets, dueño como string, y que la
línea generada lo reproduzca).

### A-14 (MEDIA) — la analogía con el defecto I-2 no transfiere. **SUSTITUIDA.** I-2 existe porque PHP no hereda
atributos de **clase**; con `TARGET_PROPERTY`, `getProperties()` ya devuelve heredadas y aplana traits. El hueco
real es que no ve `private` del padre (`AggregateRoot::$domainEvents`).

### A-15 (MEDIA) — doble rasero en el hecho (A). **ETIQUETADO.** El argumento del scope de cifrado es tan
prospectivo como el que declaro falso; ahora lo dice.

### A-16 (MEDIA) — la Tarea 1 no corregía la justificación de FR2/NFR8 que el hecho (A) mide como falsa.
**AÑADIDO.**

### A-17 (MEDIA) — `ready-for-dev` con tres decisiones abiertas entrega la historia a un implementador
desatendido. **CORREGIDO a `blocked`.** `bmad-dev-auto/step-01-clarify-and-route.md:18-23` enruta `ready-for-dev`
directo a implementar y `blocked` a HALT; el comentario YAML no lo lee ninguna herramienta. G-4a nunca estuvo en
`ready-for-dev`.

### A-18 (MEDIA) — AC5 decía «los cuatro medidos» y enumeraba tres. **CORREGIDO** (el cuarto es la heurística de
nombre, A-6).

### A-19 (MEDIA→BAJA) — casos límite del generador sin cubrir. **INCORPORADOS en la Tarea 3 y 4:** orden no
determinista (rojo en CI, verde en local), placeholder que silencia la completitud, primera escritura root-owned
en el bind mount, cabecera dentro de la comparación de deriva, travesía `..` en la ruta del dueño, anti-vacuidad
del árbol de fixtures, atributo puesto fuera del universo, y el punto ciego de un id de persona persistido como
`Types::STRING` en vez de `Types::GUID`.

### A-20 (BAJA) — cifras y citas mal. **CORREGIDAS:** 19→**14** comandos, 12→**11** gates, 10→**9** tests,
5→**6** sitios de docs de #613 (faltaba `docs/architecture/event-catalog.md`); la corrección de `is_file()` está en
`PersistentTransportPolicyGateTest.php:133-137`, no en `PersistentTransportPolicy.php`; `AuditLogSchemaListener`
`actor_id`=`:44`, `resource_id`=`:47`, `metadata`=`:48`; `AuditWriteCaptureListener` filtro `:78`, `seal()` `:87`;
`#[PersonalData]` del `iban` en `BankAccount.php:65`.

### Hallazgos RECHAZADOS por medición (los declaro para que el silencio no se lea como omisión)

- **«Un atributo con `string $owner` requerido rompería el contenedor»** — refutado por contraejemplo vivo:
  `api/src/Shared/Validation/Infrastructure/EnumType.php:30-32` es un atributo con `public string $enumClass`
  requerido, dentro del `../src/` autoregistrado, y el contenedor compila. Los privados sin consumidor se podan
  antes de resolver argumentos.
- **«Filtrar `$id` con `getDeclaringClass()->isAbstract()`»** — mecanismo equivocado: `$id` vive en el **trait**
  `Identifiable.php:19-22`, y para una propiedad de trait `getDeclaringClass()` devuelve la clase que la usa. La
  consecuencia (un universo GUID barre `$id` ×8) es real y se fusionó con A-6.
- **«El artefacto viola el estilo de enlaces Markdown / prescribe IDs de historia hacia `src`»** — verificado y
  falso: cero enlaces `[](…)`, todo en código inline, y el anti-patrón 8 prohíbe justo eso.

### Lo que las tres capas verificaron y NO encontró hallazgo

Las mediciones de las semillas y de las huérfanas; la cadena de erasure completa; el cableado de AC6 en las dos
listas (declarado **más fuerte** que NFR11); la falsabilidad de AC2 en las dos direcciones originales; los cuatro
eslabones del hecho (A); y las citas de `php-quality.mk`, `ci.yml:115`, `deptrac.yaml`, `services.yaml`,
`doctrine.yaml`, `regen-baseline.sh`, `PersonResourceErasureGateTest`, `EventDispatchGateTest`, `ApiSourceFiles`,
`AllowlistFile`, `.audit-resource-types`, `.persistent-transport-policy`, `Identifiable` y `erase.feature:44`.

## Nota de cierre — `docs/architecture/event-catalog.md` NO aplica, y aquí queda el argumento

La Tarea 6 exige los seis sitios que tocó #613 e **incluye o argumenta** el catálogo de eventos. No aplica, y
la razón es estructural, no de conveniencia: ese documento cataloga **eventos de dominio** y su enrutado por
transporte, y este eje **no añade, no retira y no enruta ninguno**. Los dos casos de uso nuevos publican cero
eventos a propósito —igual que su precedente `PurgeUserSessions`—, precisamente para no abrir la fuga que
`.persistent-transport-policy` persigue. Un apartado allí describiría un eje que el catálogo no gobierna.

Lo que sí quedó anotado en el catálogo es lo que le corresponde: `Iam.Invitation` sigue clasificado
`non-person` porque su `aggregate_id` es el de la invitación, y el `invitedUserId` viaja en el **payload** —
que es el punto ciego declarado de aquel gate y sigue siendo FR9/G-4b, no este eje.

## Pase adversarial — IMPLEMENTACIÓN. REGISTRADO (`CLAUDE.md` → *Security review* → **Process**)

**Dónde queda el registro: aquí, y reproducido en el cuerpo de la PR #616.** **Cuándo:** 2026-08-01, sobre el
código ya escrito y con los tres gates en verde — es decir, sobre lo que un build verde NO detecta.
**Quién:** tres lecturas hostiles independientes, en contexto fresco, por revisores **distintos del autor**,
con instrucción explícita de refutar, prohibición de aceptar como cierta ninguna afirmación del código, de los
docblocks o del cuerpo de la PR, y obligación de re-medir contra el árbol: un pase adversarial general, un
cazador de casos límite sobre el motor, y un auditor de aceptación contra los dos artefactos y la épica.
**Alcance declarado:** los tres commits de la entrega, **cubriendo las dos historias** — el eje de G-1a y,
por G-1b, la cadena ejecutando `Membership::$userId` e `Invitation::$invitedUserId`, el método de puerto
nuevo de `InvitationRepository`, y el invariante ≥1 ADMIN. **No cubre:** ejecución de Behat (prohibida a los
revisores por resetear la BD; la corrió el autor) ni PWA (cero cambios).

**Veredicto: el eje aguanta; la implementación tenía un agujero grave y varias afirmaciones falsas.**

- **GRAVE — el test del invariante era vacuo, y los tres lo habrían dejado pasar salvo por la medición.** Su
  `INSERT ... SELECT ... FROM organization LIMIT 1` insertaba **cero filas** (la BD de test se migra y nunca
  se provisiona), así que la membresía fantasma nunca existía y las dos aserciones ya eran ciertas sin ella.
  Verde, y sin probar nada — exactamente el anti-patrón 3 de G-1a («un AC que no puede fallar»), cometido por
  el autor tras haberlo leído. **Corregido:** el test crea su propia organización y **afirma que el INSERT
  afectó a 1 fila** antes de asertar.
- **ALTO — dos huecos estructurales en la derivación.** Una columna GUID `private` declarada en un padre
  abstracto (la forma de un `#[ORM\MappedSuperclass]`) y una asociación to-one con `#[ORM\JoinColumn]`
  —que persiste una clave foránea sin declarar `#[ORM\Column]` alguno— eran **invisibles** a la barrida.
  Ambas son la vía idiomática de Doctrine para mintear un `user_id`. **Corregidas ampliando la derivación**;
  las que no se cierran (varias clases por fichero, nombre ≠ ruta) están ahora **enumeradas en la cabecera**.
- **ALTO — el registro afirmaba en seis sitios que estas dos referencias eran «las únicas sin FK física».**
  Medido contra el esquema vivo: la base de datos tiene **dos** claves foráneas y **ninguna** apunta a
  `identity_user`, así que *ninguna* referencia a persona cascadea. La frase enseñaba justo la lección
  equivocada («FK ⇒ seguro»). **Corregida en los seis.**
- **MEDIO — la mitad del atributo era vacua**, demostrado empíricamente: borrar los cuatro
  `#[PersonSubjectReference]` del árbol dejaba el build verde, porque los dos checks iteran las propias
  declaraciones. **Corregido** con una pata de anti-vacuidad sobre `declaredOwners()`.
- **MEDIO — el prompt de consentimiento del CLI entendía mal el alcance del acto irreversible**: enumeraba
  identidad, tokens, trail y sesiones, no la membresía ni las invitaciones. Para una operación cuyo diseño
  entero descansa en un consentimiento informado y auditado, eso es un defecto. **Corregido**, junto al
  `setHelp()`, el docblock de la clase y el del controlador.
- **MEDIO — `make php.lint.*` salía verde con un `--filter` que no casaba con nada** (`failOnEmptyTestSuite`
  no estaba puesto). Afectaba a los cuatro gates de lint, no solo al nuevo. **Corregido en la config de
  PHPUnit**, y verificado: un filtro con typo ahora sale distinto de cero.
- **MEDIO — los dobles in-memory comparaban UUID con distinción de mayúsculas** mientras las columnas son
  `uuid` de Postgres, que no la hace. Divergencia en la dirección «el test es más estricto que producción»:
  ningún test podía fallar por ella. **Corregidos con `strcasecmp`** y fijado con un test que borra pasando
  el id en mayúsculas.
- **BAJO, corregidos:** el check de cableado rechazaba el estilo fluido del propio repo; una clave sin `::`
  degeneraba el regex; un FQCN sin namespace perdía la primera letra; un atributo repetido abortaba con un
  `Error` que no nombraba la propiedad; los adaptadores reportaban 0 en silencio si el driver devolvía el
  conteo como string; tres ramales del parser no estaban falsificados; dos tests prometían más de lo que
  afirmaban; y el trait `Identifiable` no avisaba de la trampa de anotarlo.

**Lo que los tres atacaron y NO pudieron romper** (se declara para que el silencio no se lea como omisión):
el parser del registro (duplicados, clasificación no reconocida, `person` sin dueño, fichero ausente, comentarios
en línea — todos fallan cerrado); las guardas de ruta (`..`, directorio, fuera de `src/`); el acotamiento de
los dos DELETE al sujeto (fuerza bruta sobre los 510 ficheros de `src` aceptó solo los semánticamente
correctos); su participación en la transacción y su idempotencia; el orden de los eslabones nuevos dentro de
la cadena; la aritmética del canario de queries; la anti-vacuidad de los fixtures; y la clasificación
`User::$id => person`.

**Hallazgo aceptado y NO cerrado aquí, declarado en la PR y abierto en el checklist:** el eje no tiene control
**detective** ni backfill. El gate prueba que el borrado está *escrito*, nunca que la fila *se fue*, y los
sujetos borrados antes de esta entrega conservan sus filas huérfanas. El hermano ya resuelve esto con
`identity:gdpr:reconcile-subject-references`; el equivalente para estas dos columnas es trabajo aparte.

## Dev Notes

### Reuse map — lo que ya existe y NO se reinventa

| Necesidad | Ya existe | Ruta |
|-----------|-----------|------|
| Iterar los ficheros PHP de `api/src` | `ApiSourceFiles::phpFiles()` | `api/tests/Support/ApiSourceFiles.php:41` |
| Descubrir clases por PSR-4 + reflexión | `PersistentTransportPolicy::eventsInSource()` | `api/tests/Support/PersistentTransportPolicy.php:90-124` |
| Parsear un registro de la raíz de `api/` | `AllowlistFile::entries()` | `api/tests/Support/AllowlistFile.php:26` |
| Validar el **cableado** del dueño, no solo su existencia | `PersonResourceErasureGateTest` | `api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php:89-109` |
| Rechazar un directorio como ruta de excepción | `PersistentTransportPolicyGateTest` | `api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php:133-137` |
| Gate con preámbulo, anti-vacuidad y fixture sucio | `EventDispatchGateTest` | `api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php` |
| Conducir `PiiDiffSealer` con el clasificador real y fakes `AuditedEntity` | `PiiDiffSealerTest` | `api/tests/Unit/Shared/Audit/Infrastructure/Persistence/PiiDiffSealerTest.php:66,77,81` |
| Verificar las dos direcciones sin generar | `PersistentTransportPolicyGateTest` (`everyAggregateTypeInSourceIsClassified` + `theRegistryDeclaresNoAggregateTypeThatNothingEmits`) | `api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php` |
| Cabecera de registro escrita a mano, con sus puntos ciegos | `.persistent-transport-policy` | `api/.persistent-transport-policy:1-44` |
| Fixtures de gate con atributos/herencia | `Architecture/Fixture/` (7 ficheros de #613) | `api/tests/Unit/Shared/Architecture/Fixture/` |

### Anti-patrones concretos que esta historia invita a cometer

1. **Poner el dueño como `::class`.** Rompe `php.lint.bounded-context` y `php.deptrac`. Hecho (C).
2. **Copiar de G-4a el recorrido de padres con `IS_INSTANCEOF`.** No transfiere a `TARGET_PROPERTY`. Hecho (D).
3. **Escribir un AC que no puede fallar.** Pasó dos veces en la primera versión de este contrato (A-4, A-8).
4. **Declarar «universo cerrado» lo que sale de un filtro de nombre.** A-6.
5. **Elegir en D1 sin corregir la frase perdedora de la épica.** Deja a G-1b con un AC insatisfacible.
6. **Anotar `$id` en el trait `Identifiable`.** Se propaga a las 8 entidades.
7. **Validar la ruta del dueño con `assertFileExists()`.** Acepta directorios.
8. **Comentar el cambio en vez del código.** Nada de «antes esto no existía», ni `G-1a`, ni `FR3`, ni `SI-22` en
   comentarios de `src`. La trazabilidad vive en el PR.
9. **Construir un generador.** SI-22 dejó de exigirlo y aquí no compra nada: solo emitiría la clave, que las dos
   direcciones ya clavan, y no puede aportar el dueño. Hecho (E), D2.
10. **Un target que reescriba el registro.** Ni el gate ni ningún otro: el registro se escribe a mano, como los
    cinco existentes. AC7.

### Arquitectura y fronteras

- El atributo vive en `Shared/Privacy/Domain/`, auto-plegado en `Shared.Domain` por `deptrac.yaml:132-138`: sin
  registro nuevo en deptrac, sin entrada en `api/.bounded-context-allowlist`.
- Un gate bajo `api/tests/Unit/Shared/Architecture/` es un test, no un módulo: deptrac analiza `api/src`.
- **Esta historia no toca la cadena de erasure.** Si te ves editando `FulfilIdentityErasure`, para: eso es G-1b.

### Testing

- `api/tools/phpunit/phpunit.dist.xml` con `failOnDeprecation`/`failOnNotice`/`failOnWarning` en `true`; `<source>`
  apunta a `api/src`, que es **por qué** los gates llevan `#[CoversNothing]`.
- **Tests que no pueden quedar rojos:** los **9** de `FulfilIdentityErasureTest.php`, los 6 de
  `UserEraseFunctionalTest.php`, los 3 de `ReflectionPersonalDataClassifierTest.php`, y
  `BankAccountPersonalDataTest.php` (que solo cubre la forma **promovida** — la declarada en el cuerpo, que es la
  de las dos semillas, no la mide nadie).
- **El test más frágil del área** es el canary de 15 queries de `api/features/backoffice/users/erase.feature:44`.
  G-1a es estático y no debería tocarlo; si se mueve, algo se coló en la cadena.
- **Behat: esta historia no debería añadir ni un step.** Si crees que hace falta, lista antes el vocabulario
  (`make php.behat c='-dl'`) — `api/CLAUDE.md` lo exige.
- **Hueco de cobertura medido:** el borrado de `PasswordResetToken::$userId` **solo está pinneado con mocks**
  (`EraseIdentitySubjectTest.php:37`, `FulfilIdentityErasureTest.php:78`); `erase.feature` no consulta
  `identity_password_reset_token`. Es la semilla más débil, y el registro va a afirmar que tiene dueño.

### Seguridad (checklist de `CLAUDE.md` aplicada a este diff)

- **Superficie HTTP:** ninguna. **Inyección / authz / validación / RFC 9457 / mass assignment / CORS / Mercure /
  migraciones:** no aplican — decláralo en el PR, no lo omitas.
- **Datos personales:** la historia no mueve ni un dato personal; instala el control que declara dónde están. **No
  escribas que cierra una fuga** — no cierra ninguna: eso es G-1b, G-2 y G-5.
- **Secretos:** el registro lleva FQCN y rutas. Ningún valor de dato, ningún id real.
- **Frontend:** cero cambios en `pwa/`.

**UX: no aplica, declarado y no omitido.** No añade, modifica ni retira superficie de UI — la consola de borrado
GDPR ya se entregó en U-5 (`epics-users-admin.md`).

### Inteligencia de la historia anterior (G-4a, merged en #613)

- **El contrato no aguantó el pase adversarial: 7 hallazgos antes de escribir código, 17 después.** Este contrato
  tampoco aguantó: 20 incorporados, 3 rechazados por medición. Trátalo igual cuando escribas el código.
- **Lo que costó caro allí:** leer atributos como los lee el framework (I-2), leer **toda** la configuración
  (I-3), no confiar en un nombre sin leer lo que hay detrás (I-6), y no dejar dos aserciones mutuamente
  insatisfacibles (I-7).
- **La clave del registro fue el defecto ALTO (I-1):** `aggregateType` era **más gruesa que la propiedad que
  clasificaba**. Aquí la clave propuesta (`<Fqcn>::$prop`) es de la granularidad del hecho — es la lección
  aplicada — pero paga rename de clase, y por eso D3 es decisión.
- **I-16:** documentación que seguía prometiendo lo que la propia rama había medido como inexistente. Al escribir
  la cabecera y los docs, describe lo que el gate hace, no lo que la épica quería que hiciera.

### Git intelligence

`9310efeb` (cierre de G-4a), `0d2d45d2` (**#613 — implementación de G-4a**: registro
`api/.persistent-transport-policy`, dos clases de gate, `api/tests/Support/PersistentTransportPolicy.php`, target
`php.lint.persistent-transport` y 7 fixtures — 43 ficheros, +2945/−326), `009b0756` (#611, solo `pwa/`), `f4dbe4d1`
(#609, corte de la épica). **`api/` sí se movió desde el corte**, y lo que se movió es el precedente que esta
historia copia: no leas `.audit-resource-types` como único modelo — lee `.persistent-transport-policy`, que ya
lleva incorporadas las correcciones de dos pases adversariales, y **cuyo tri-estado es la opción ④ de D1**.

## References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` — FR2, FR3, FR4; NFR1/SI-21, NFR2/SI-22, NFR3/SI-23,
  NFR8, NFR10, NFR11; Story 1.2; y las tres frases que D1 pone en conflicto (`:91`, `:97`, `:488-490`).
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-21/22/23, fila **G-1a**, DAG (`:44`).
- `_bmad-output/implementation-artifacts/g-4a-fuga-passwordresetcompleted-transportes-messenger.md` — el precedente
  completo: contrato, decisión registrada y los dos pases adversariales.
- `docs/adr/audit-activity-log.md` — D4 · `docs/adr/regulatory-audit-trail.md` — D15 ·
  `docs/adr/external-dependencies-in-domain.md` — metadatos pasivos en `Domain/`.
- `CLAUDE.md` — *Security review* → **Process**; *Finishing substantial work* (*Gates first*); *Code comments*;
  *Keeping docs up to date*. `api/CLAUDE.md` — deptrac, gates, vocabulario Behat.

## Dev Agent Record

### Agent Model Used

Claude Opus 5 (1M context) — `claude-opus-5[1m]`.

### Debug Log References

Mediciones ejecutadas al crear y al revisar la historia (2026-07-30/31, stack de dev arriba, checkout primario en
`9310efeb`). **Read-only: no se ejecutó ningún gate ni se escribió nada en `api/`.**

- `bin/phpunit --filter=PersistentTransportPolicyGateTest --list-tests` → **7 tests**.
- `bin/phpunit --filter=PersistentTransportRoutingShapeGateTest --list-tests` → **13 tests**.
- `bin/phpunit --filter='PersistentTransport.*GateTest' --list-tests` → **20 tests**.
- `git grep -c 'Types::GUID' -- api/src` sumado → **16** propiedades.
- `git grep -l 'AsCommand' -- api/src | wc -l` → **14**. `ls api/tests/Unit/Shared/Architecture/*.php | wc -l` →
  **11**. `grep -c 'public function test' …/FulfilIdentityErasureTest.php` → **9**.
- `make bmad.status.audit` → **exit 0**.

### Completion Notes List

### File List
