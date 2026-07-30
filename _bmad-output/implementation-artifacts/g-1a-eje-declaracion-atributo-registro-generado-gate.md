---
baseline_commit: 9310efeb
---

# Story 1.2 (G-1a): El eje de declaración — atributo hermano, registro generado y gate que rompe el build

Status: ready-for-dev

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **PARA ANTES DE TOCAR CÓDIGO.** El corte de épica describe esta historia como *aditiva y verde al llegar*, sin
> decisiones abiertas. **La medición contra `main` encontró tres divergencias** que la convierten en una historia
> **con precondición de decisión** (misma categoría que 1.4/1.5/1.6). Están en *Estado medido* → hechos (A), (B) y
> (C), y los forks que abren, en *Decisiones abiertas*. **No empieces por el atributo.** La que más cambia el
> resultado es (B): *«llega verde»* y *«cada hueco posterior es un gate en rojo»* **no son simultáneamente ciertas**
> con el mecanismo que la épica describe, y elegir sin verlo produce un gate que **calla** exactamente donde debía
> hablar.

## Story

Como **desarrollador que añade un contexto nuevo que toca a una persona**,
quiero que el repositorio me pare si introduzco una referencia persistida a una persona sin dueño de borrado,
para que la obligación no dependa de que alguien se acuerde.

**Eje que instala:** los tres pasos, **sin mezclarlos** — ① atributo hermano en `Shared/Privacy`, ② generador por
reflexión que produce el registro, ③ gate `make php.lint.person-reference`.
**Invariantes que consume:** el patrón de la casa (registro revisable + gate obligatorio), NFR8 (el contrato de
`#[PersonalData]` es de tratamiento y no se reutiliza).
**Invariantes que establece:** SI-21/NFR1, SI-22/NFR2, SI-23/NFR3.
**Dependencias:** ninguna. Habilita 1.3 (G-1b) y 1.4 (G-2) sin necesitarlas.

## Estado medido (`main` @ `9310efeb`)

`api/` **sí** se ha movido desde el corte de épica (`471ae66f`): el PR #613 (G-4a) aterrizó en `0d2d45d2` y trajo el
precedente más fresco de registro + gate. Todo lo de abajo se midió contra `9310efeb`, no contra los cuerpos de
issue ni contra el corte.

### La capability que se replica — tres ficheros, medidos

`api/src/Shared/Privacy/` tiene **exactamente tres ficheros** y ninguna otra pieza (ni CLI, ni registro, ni config):

1. **`api/src/Shared/Privacy/Domain/PersonalData.php:16-19`** — `#[Attribute(Attribute::TARGET_PROPERTY)] final class
   PersonalData {}`. **Cero parámetros, cuerpo vacío, no repetible.** Su docblock (`:9-15`) es literalmente el
   argumento de FR2: *«infrastructure that handles personal data … only reads it to decide encrypt-vs-clear per
   column»* — contrato de **tratamiento**.
2. **`api/src/Shared/Privacy/Application/PersonalDataClassifier.php:11-19`** — un método:
   `personalFieldsOf(object|string $entityOrClass): array`, `list<string>` *«sorted for determinism»*.
3. **`api/src/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifier.php`** —
   `#[AsAlias(PersonalDataClassifier::class)]` (`:12`), caché en memoria por FQCN (`:15,:22`),
   `getProperties()` sin filtro (`:34`), `getAttributes(PersonalData::class)` (`:35`), `sort()` (`:40`).

**Consumidor de producción: uno solo.** `api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php:49`.
Anotaciones `#[PersonalData]` vivas: **tres, todas en `BankAccount`**
(`api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php:50,65,72`).

**Cableado DI:** el `#[AsAlias]` basta — `api/config/services.yaml:23-27` autoregistra `../src/` excluyendo
`Domain/Entity/`. **Un adaptador nuevo en `Shared/Privacy/Infrastructure/` no requiere tocar YAML.**

**Deptrac:** `api/tools/deptrac/deptrac.yaml:132-138` usa colectores `src/Shared/(.*/)?Domain/.*`, que **auto-pliegan**
los módulos anidados de `Shared/`. Un atributo nuevo en `Shared/Privacy/Domain/` **no necesita registro propio**, y
la regla `&domain` (`:192-205`) admite `Shared.Domain` desde los nueve layers `*.Domain`. Es como `BankAccount`
importa `PersonalData` hoy (`BankAccount.php:23`).

### Las dos semillas — quién las borra hoy, medido propiedad a propiedad

| Propiedad | Declaración | Columna | ¿La cadena la borra? | Dueño (el acto) |
|-----------|-------------|---------|----------------------|-----------------|
| `PasswordResetToken::$userId` | `api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php:39-40`, `private string`, `Types::GUID`, **no promovida** | `identity_password_reset_token.user_id` | **SÍ** — borra la **fila entera** | `api/src/Iam/Identity/Application/EraseIdentitySubject.php:43` → DQL `DELETE` en `DoctrinePasswordResetTokenRepository.php:66-77` |
| `Session::$userId` | `api/src/Iam/Session/Domain/Entity/Session.php:37-38`, `private string`, `Types::GUID`, **no promovida** (sus vecinas sí lo son, `:50-57`) | `iam_session.user_id` | **SÍ** — borra la **fila entera** | `api/src/Iam/Session/Application/PurgeUserSessions.php:29`, invocado desde `api/src/Iam/Identity/Application/FulfilIdentityErasure.php:112` → DQL `DELETE` en `DoctrineSessionRepository.php:105-116` |

Ninguna de las dos lleva `#[PersonalData]` hoy. **Las dos formas de propiedad —promovida y declarada en el cuerpo—
conviven en la misma entidad**, así que el generador tiene que cubrir ambas (`getProperties()` ya lo hace, medido en
`api/tests/Unit/Backoffice/BankAccount/Domain/Entity/BankAccountPersonalDataTest.php:24`).

**El universo completo de referencias a persona es de CUATRO propiedades, no nueve.** Medido sobre las 8 entidades
`#[ORM\Entity]` de `api/src`:

| # | Propiedad | Fichero:línea | ¿Erasure? |
|---|-----------|---------------|-----------|
| 1 | `PasswordResetToken::$userId` | `PasswordResetToken.php:39` | **SÍ** (semilla) |
| 2 | `Session::$userId` | `Session.php:37` | **SÍ** (semilla) |
| 3 | `Membership::$userId` | `api/src/Organization/Membership/Domain/Entity/Membership.php:30-31` | **NO** — `MembershipRepository::remove()` existe (`MembershipRepository.php:21`) y **no tiene ningún llamante en `src/`** |
| 4 | `Invitation::$invitedUserId` | `api/src/Iam/Invitation/Domain/Entity/Invitation.php:49-50` | **NO**, y peor: `InvitationRepository` **no expone remove/delete** (solo `save`/`findById`/`findByIdForUpdate`, `:19,:21,:29`) — cerrarlo exige método de puerto nuevo |

`User::$id` (trait `Identifiable`, `api/src/Shared/Kernel/Domain/Entity/Identifiable.php:19-22`) es **la persona
misma, no una referencia**: si entra en el registro es decisión de diseño, y **anotarla en el trait la propagaría a
todas las entidades** (los traits `Identifiable`/`Timestamped` son `protected` y `getProperties()` los ve en toda
hija de `AggregateRoot`).

### La plantilla exacta del gate y del registro — patrón de la casa, ya medido

- **Colocación del registro:** raíz de `api/`, sin extensión, tracked. Hay **cinco** precedentes:
  `.audit-resource-types`, `.bounded-context-allowlist`, `.error-contract-allowlist`, `.event-dispatch-allowlist`,
  `.persistent-transport-policy`.
- **Vocabulario del dueño, literal** (`api/.audit-resource-types:8-10,24`):
  `<Clave> => non-person` · `<Clave> => person :: <ruta relativa a api/>`. La única entrada `person` viva es
  `User => person :: src/Iam/Identity/Application/FulfilIdentityErasure.php`. **El dueño es una RUTA DE FICHERO**,
  no un FQCN ni un método — y por el hecho (C) eso no es estilo, es obligatorio.
- **Cómo se valida un dueño** (`api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php:82-105`):
  `assertFileExists` sobre la ruta, luego, **sobre la fuente sin comentarios** (`codeWithoutComments()`, `:249-262`),
  exige propiedad del tipo colaborador (`:91`), **la llamada** (`:98`) y el literal del tipo (`:105`). El comentario
  del método dice por qué se descartan los comentarios: *un docblock que nombra al colaborador es intención, y una
  intención no sustituye a la llamada*.
- **Parser de líneas:** `api/tests/Support/AllowlistFile.php:26` (`entries()`) — es lo que usa el gate de #613;
  `PersonResourceErasureGateTest` aún parsea a mano (`:145`) y **rechaza duplicados** (`:170-173`) y toda
  clasificación no reconocida (`:181-191`), nunca la degrada a `non-person`.
- **Forma del test:** `#[CoversNothing]` a nivel de clase (los 12 gates de `api/tests/Unit/Shared/Architecture/` lo
  llevan; ninguno `#[CoversClass]`), `public const string FAILURE_PREAMBLE` para que CI la greppee
  (`EventDispatchGateTest.php:33-40`), camino verde con `addToAssertionCount(1)` (`:50-68`), test anti-vacuidad
  (`testGateScansAtLeastOneApplicationFile`, `:70-82`) y fixture sucio + su gemelo limpio contra falsos positivos
  (`:84-112`).
- **Motor fuera del test** cuando hay lógica: `api/tests/Support/PersistentTransportPolicy.php:17-22` explica por qué
  (*«so the rules are exercisable independently of the assertions»*). Su `eventsInSource()` (`:90-124`) es **el molde
  literal del generador de FR3**: recorre `ApiSourceFiles::phpFiles()`, reconstruye el FQCN por PSR-4, `class_exists`,
  descarta abstractas, filtra por herencia y llama al método — *«Reflection rather than a regex»*.
- **Cableado del target** (`make/php-quality.mk`): bloque de sección + comentario que enuncia qué falla y por qué +
  una línea `@$(PHP_TEST) bin/phpunit --filter=<Clase>` (`:110-111`, `:120-121`), y **tres** inserciones: `:158`
  (`php.quality`), `:176` (`php.quality.dry-run`, el que corre CI vía `.github/workflows/ci.yml:115`) y `:178-187`
  (`.PHONY`).
- **Cabecera del registro, cinco bloques** (`api/.persistent-transport-policy:1-44` es la versión madura): ① qué es y
  cuál es la clave; ② la regla en una frase con su porqué material; ③ `Format, one per line:`; ④ la clase del gate
  **y** el target `make`; ⑤ bloque delimitado por regla de guiones *"What it deliberately does NOT do"*, con la frase
  que es el contrato con el lector: *«a green build proves what is below the line, and nothing above it»*.

### Hechos medidos que el corte de épica NO registra — léelos antes de decidir

**(A) El justificante de FR2 es FALSO en su último eslabón — y el correcto es más fuerte.**

La épica (`epics-gdpr-hardening.md:70-74`) y el addendum (`arch-addendum-gdpr-hardening.md:25`) afirman que anotar
una clave foránea con `#[PersonalData]` haría que `PiiDiffSealer` *«la cifrara en el diff, rompiendo las búsquedas
que el propio reconciliador de erasure necesita»*. Medido eslabón a eslabón:

- **`PiiDiffSealer` solo corre sobre `AuditedEntity`.** Su único invocador es
  `api/src/Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php:86`, que filtra en `:77-79`. Implementan
  `AuditedEntity` **solo** `Bank` (`Bank.php:29`) y `BankAccount` (`BankAccount.php:39`). `User` (`User.php:37`),
  `Session` (`Session.php:29`) e `Invitation` (`Invitation.php:38`) declaran explícitamente que **no** lo son, y
  `PasswordResetToken` tampoco. → **Anotar cualquiera de las cuatro referencias sería hoy un no-op literal.**
- **El sellador nunca toca la columna de la entidad.** Escribe en `audit_log.metadata` (JSONB) y
  `encryption_scope_id` (`AuditWriteCaptureListener.php:93-100`, `DbalAuditLogWriter.php:37-44`). Prueba viva:
  `bank_account.iban` **está** marcado `#[PersonalData]` (`BankAccount.php:64-65`) y sigue siendo `unique` y
  buscable en claro.
- **El reconciliador no lee ningún valor de diff.** `ReconcileErasedSubjectReferences.php:41-52` lee exactamente dos
  cosas: `SELECT DISTINCT resource_id FROM audit_log WHERE resource_type = :t AND resource_id IS NOT NULL AND
  resource_erased = FALSE` (`DbalPersonResourceReferences.php:36-41`) y `identity_user.id` por PK
  (`DoctrineUserRepository.php:42-45`).

**No re-propongas reutilizar `#[PersonalData]`: la conclusión de la épica es correcta, su argumento no.** Los dos que
sí se sostienen, medidos:

1. **Asigna el dueño de borrado EQUIVOCADO.** El scope de cifrado es `<ResourceType>:<id>` del **agregado
   propietario**, no de la persona (`PiiDiffSealer.php:55-56`). Un id de persona sellado en el diff de otro agregado
   queda bajo la DEK de *ese* agregado: se destruiría con el borrado del propietario y **sobreviviría al borrado de
   la persona**. Es exactamente el fallo que SI-21 persigue — y es un argumento de **referencia**, no de tratamiento,
   coherente con el resto de FR2.
2. **El puerto no tiene dónde llevar un dueño.** `personalFieldsOf()` devuelve `list<string>` de nombres de propiedad
   (`PersonalDataClassifier.php:18`). Reutilizarlo obligaría a cambiar la firma del puerto, con `PiiDiffSealer` como
   único consumidor afectado.

**Consecuencia directa sobre el AC3 del corte:** *«se sella el diff de auditoría de esa entidad → la clave foránea
sigue viajando en claro»* **no es ejecutable sobre las semillas** — no producen diff de auditoría. Reformulado en
AC3 abajo.

**(B) «Llega verde» y «cada hueco posterior es un gate rojo» no son simultáneamente ciertas. Es el fork principal.**

La épica promete las dos (`epics-gdpr-hardening.md:85-94` y `:314`). Con los dos checks que describe —(a) generado ≠
commiteado, (b) referencia **declarada** sin dueño— `Membership::$userId` e `Invitation::$invitedUserId` **no
producen rojo: producen silencio**. Sin anotación no hay línea generada, luego (a) casa y (b) no tiene nada que
evaluar. Para que la segunda promesa sea cierta el gate necesita un **tercer check cuya fuente sea independiente de
la anotación** — que es exactamente lo que hace `everyAggregateTypeInSourceIsClassified` en el gate de #613
(`PersistentTransportPolicyGateTest`): la completitud es lo que fuerza la decisión a entrar en un diff.

Dato que hace viable ese tercer check: **el universo es de 4 propiedades sobre 8 entidades**, cerrado y medido, con
forma reconocible (`Types::GUID` + nombre `*user*_id`). No es una heurística sobre un espacio abierto.

**(C) El dueño de borrado NO puede ser una referencia de clase (`::class`). Rompe dos gates a la vez.**

- `Session` vive en `Iam/Session/Domain`; su dueño es `Erpify\Iam\Identity\Application\FulfilIdentityErasure` → un
  `use` cross-context desde `Domain/` es fallo **Nivel 1** de `BoundedContextGateTest` y violación deptrac
  (`Iam.Session.Domain` solo admite `Shared.Domain`, `Vendor.Psr`, `Vendor.SymfonyUid`, `Vendor.PassiveMetadata` —
  `deptrac.yaml:192-205`).
- Incluso dentro del mismo contexto: `PasswordResetToken` (`Iam/Identity/Domain`) → `FulfilIdentityErasure`
  (`Iam/Identity/Application`) es **Domain → Application**, prohibido por la misma regla.
- El precedente que sí funciona es la ruta-en-string de `.audit-resource-types:24`, validada por lectura de fichero.

**(D) El clasificador actual NO descubre clases, y su lector de atributos tiene el punto ciego que G-4a ya pagó.**

- `ReflectionPersonalDataClassifier` **recibe** la clase del llamante (`:18-23`); `PiiDiffSealer` ya tiene la entidad
  en la mano (`PiiDiffSealer.php:49`). En `Shared/Privacy/` **no hay ninguna enumeración de clases**. *«Análogo a
  `ReflectionPersonalDataClassifier`»* describe solo la mitad barata.
- Su lectura es `getAttributes(PersonalData::class)` **sin `IS_INSTANCEOF` y sin recorrer padres** (`:35`). Es
  literalmente el defecto I-2 del pase adversarial de G-4a, que costó cuatro fixtures nuevos: `SendersLocator`
  recorre `[$clase] + class_parents() + class_implements()` con `IS_INSTANCEOF`. **No copies ese lector tal cual.**
- Tampoco hay test de herencia ni de traits para el clasificador
  (`api/tests/Unit/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifierTest.php` cubre clase, instancia y
  vacío). Si el generador nuevo depende de esa semántica, el hueco es suyo.

**(E) NO existe precedente de generador por reflexión que escriba un artefacto commiteado, y el camino obvio está
vetado por una garantía escrita.**

Medido: los únicos targets que reescriben un artefacto son `php.stan.baseline` (`php-quality.mk:11-12`, cuyo fichero
**no está tracked**) y `php.deptrac.baseline` (`:147-148`), que es un **script shell**
(`api/tools/deptrac/regen-baseline.sh`), no reflexión. Ninguno de los 19 comandos Symfony de `api/src` escribe un
fichero del árbol. `docs/rules/` no tiene ninguna regla sobre artefactos generados.

Y el camino «el propio test con `--update`» **está vetado por una garantía explícita**: `make/php-quality.mk:165-166`
declara *«Parallel-safe — every prerequisite here is read-only (no src/ writes), so CI can fan them out with `make -j`
without racing»*. Un gate que escribe rompe esa promesa para toda la lista.

Lo que sí aporta `regen-baseline.sh` como plantilla: **cabecera en fichero aparte** re-antepuesta por el generador
(`api/tools/deptrac/deptrac.baseline.header.txt`, con la fórmula `# GENERATED — regenerate with 'make …' (do not
hand-edit …)`), y la **escritura segura** de `:39-45` — construir a un temporal, `test -s`, y `cat "$out" > destino`
en vez de `mv`, porque el contenedor corre como root y un `mv` desde `/tmp` dejaría el fichero root-owned en el bind
mount del host. Ese detalle ya nos ha mordido.

**(F) El gate de G-4a deja 13 de sus 20 tests fuera de CI. Es el error que esta historia va a repetir si no mira.**

`make/php-quality.mk:121` es `--filter=PersistentTransportPolicyGateTest`. La segunda clase que #613 creó se llama
`PersistentTransportRoutingShapeGateTest` y **no casa con ese regex**. Medido con ejecución fresca contra el stack
(`bin/phpunit --filter=… --list-tests`): el filtro actual selecciona **7 tests**; la clase de formas aporta **13** y
no la selecciona ninguno; `--filter='PersistentTransport.*GateTest'` selecciona los **20**. O sea, la mitad
*«cubre las cinco vías de routing»* del gate solo corre en `make php.unit`, nunca en `php.quality` ni en
`php.quality.dry-run`. **Si G-1a parte su gate en dos clases, el filtro tiene que ser un prefijo regex común.**

## Decisiones abiertas (PRECONDICIÓN — ver la definición de hecho de la épica)

El corte no marcó esta historia con decisión abierta porque las tres nacen de mediciones que el corte no hizo.
**Ninguna implementación empieza antes de que las tres queden registradas por escrito** (en el PR o en este
artefacto). Cada una lleva recomendación: **la primera tarea es confirmarla o refutarla, no darla por buena en
silencio.**

### D1 — ¿El gate detecta huecos, o solo verifica lo declarado? (nace del hecho (B))

| Opción | Qué hace | Coste medido |
|--------|----------|--------------|
| **①** *(recomendada)* **Completitud estructural + dueño declarado.** El universo lo deriva el gate del **código** (propiedades de entidad Doctrine con `Types::GUID` y forma de referencia a persona); el atributo aporta **el dueño**. Toda propiedad del universo sin línea → **rojo** | Hace ciertas las dos promesas de la épica. Espeja `everyAggregateTypeInSourceIsClassified` de #613, que es el check que *«fuerza la decisión a entrar en un diff»* | G-1a **NO llega verde**: `Membership` e `Invitation` salen en rojo el día 1. Habría que sancionarlas explícitamente (`person :: <artefacto real>`) o aceptar rojo hasta G-1b. **Reabre FR4** |
| **②** **Solo lo declarado** (los dos checks del corte, literal) | Llega verde tal como promete FR4 | La segunda promesa (`:314`, *«cada hueco posterior es un gate en rojo»*) **es falsa** y hay que borrarla de la épica. G-1b cierra sus dos referencias sin que nada las hubiera señalado: el eje deja de ser detección y pasa a ser documentación verificada |
| **③** **Híbrido:** completitud estructural + sanción explícita de las dos referencias conocidas nombrando la historia que las cierra | Verde al llegar **y** detección viva para la referencia *siguiente* | Una entrada que se satisface a sí misma es justo el defecto de #563 (SI-23), salvo que apunte a un artefacto **real y verificable**. `arch-addendum-gdpr-hardening.md` y `epics-gdpr-hardening.md` existen y son ficheros; `assertFileExists` sobre ellos es mecánicamente válido. **Pero** son artefactos `_bmad-output/` (transitorios por regla del repo) — atar un gate de build a un fichero que la higiene BMAD puede borrar es deuda con fecha |

**Recomendada ①**, y con ella **la afirmación «llega verde» de FR4 se corrige en la épica en el mismo PR** en vez de
implementarse una promesa que la medición ya rompió. Si Sergio prefiere no romper la secuencia del DAG, ③ es
defendible con un ADR real como ancla (no un artefacto BMAD).

### D2 — ¿Dónde vive el generador y cómo escribe? (nace de los hechos (D) y (E))

| Opción | Coste medido |
|--------|--------------|
| **① Script + comando** — la lógica de descubrimiento en `api/tests/Support/PersonReferences.php` (espejo de `PersistentTransportPolicy`), y un target `make php.lint.person-reference.regen` que la invoque | El descubrimiento vive donde ya viven los seis gates, sin arrancar el kernel. **Pero** `tests/Support` no es invocable desde un target que no sea PHPUnit sin un envoltorio |
| **② Comando Symfony** bajo `Shared/Privacy/Infrastructure/Cli/` | 19 comandos de precedente de **ubicación y estilo**, cero de escritura de artefacto. Obliga a arrancar el kernel para alimentar un gate que hoy es puro `TestCase`. **Y sería código de producción sin consumidor de producción** — el registro solo lo lee el gate. YAGNI (`docs/project-context.md` → *"Don't abstract for hypothetical futures"*) |
| **③ El propio test con `--update`** | **Vetado por medición:** rompe el `read-only / parallel-safe` que `php-quality.mk:165-166` declara para toda la lista |

**Recomendada ①.** Y la consecuencia que hay que decir en voz alta: **el atributo ① sí es producción** (vive en
`Domain/`, lo llevan las entidades), pero **el generador ② no tiene por qué serlo**. Que el corte diga *«clasificador
análogo a `ReflectionPersonalDataClassifier`»* no obliga a ponerlo en `src/`: aquel está en `src/` porque
`PiiDiffSealer` lo consume en producción, y aquí no hay tal consumidor. **Si se decide ponerlo en `src/`, hace falta
el argumento, no la analogía.**

### D3 — Forma de la clave del registro

Recomendado: **`<Fqcn>::$<propiedad> => non-person | person :: <ruta relativa a api/>`**. La clave es derivable por
reflexión (a diferencia del `'User'` de `.audit-resource-types`, que es manual y por eso necesita el segundo testigo
de 1.5), y el dueño va como **ruta**, obligatorio por el hecho (C). Confírmalo o refútalo por escrito; si eliges una
clave más gruesa, mide antes qué pasa cuando una clase se renombra — es la trampa que
`.persistent-transport-policy:26-30` documenta para su propia clave.

## Acceptance Criteria

**AC1 — El gate falla cuando una referencia persistida a persona no declara dueño de borrado (FR4).**
**Given** una propiedad persistida que guarda el identificador de una persona sin dueño declarado,
**When** corre `make php.lint.person-reference`,
**Then** el gate **falla**, nombrando la propiedad (`<Fqcn>::$prop`) y lo que falta.
*Qué cuenta como «sin dueño» lo fija D1.* Con ① es toda propiedad del universo estructural sin línea; con ② es una
línea `person` cuya ruta no existe o cuyo fichero no ejecuta el borrado.

**AC2 — El registro se valida contra el CÓDIGO, nunca contra sí mismo (FR3, SI-23).**
**Given** un registro commiteado que ha divergido del código,
**When** corre el gate,
**Then** **falla**, derivando la comparación de la fuente. Un registro que se valide contra el registro **es** el
defecto de #563.
*Cómo se pinna:* además del check de divergencia, un test que **demuestre** que la comparación no puede leerse verde
por construcción — fixture con registro sucio → rojo; fixture con código cambiado y registro intacto → rojo.

**AC3 — Declarar una referencia no la arrastra al crypto-shredding (FR2, NFR8). REFORMULADO — ver hecho (A).**
**Given** el atributo nuevo sobre una propiedad,
**When** se inspecciona el contrato de `#[PersonalData]`,
**Then** el atributo nuevo **no** se lee por `personalFieldsOf()` y **no** llega a `PiiDiffSealer`, de modo que la
clave foránea nunca entra en el ciclo de cifrado.
*Por qué no se escribe el AC del corte:* *«se sella el diff de esa entidad»* **no es ejecutable** — ninguna de las
cuatro entidades con referencia a persona implementa `AuditedEntity`, así que nunca se produce diff. Y la razón de
fondo no es que rompiera búsquedas (falso, medido), sino que **asignaría el dueño de borrado equivocado**: el scope
es del agregado propietario (`PiiDiffSealer.php:55-56`), no de la persona.
*Cómo se pinna:* un test de que `personalFieldsOf()` **ignora** el atributo nuevo — la separación de los dos
contratos, que es lo que NFR8 protege, y lo único que aquí es falsable de verdad.

**AC4 — El estreno del mecanismo (FR4).**
**Given** el registro sembrado con `PasswordResetToken::$userId` y `Session::$userId`, cuyo borrado está medido,
**When** corre `make php.quality`,
**Then** el resultado es el que D1 haya decidido — **y ese resultado consta por escrito**.
*Redacción deliberada:* con ① el build **no** llega verde y eso es correcto; escribir *«pasa sin rojo»* como AC
absoluto obligaría a elegir ② por la puerta de atrás. **No lo re-endurezcas al escribir el PR.**

**AC5 — La cabecera del registro declara qué NO detecta (FR3).**
**Given** el registro nuevo,
**When** se lee su cabecera,
**Then** enumera sus puntos ciegos siguiendo `api/.persistent-transport-policy:19-44`, incluyendo **como mínimo** los
cuatro medidos: no juzga la clasificación; no alcanza referencias nacidas en configuración (FR9/G-4b); **no alcanza
las tablas que no tienen entidad Doctrine** — `audit_log.actor_id`/`resource_id`/`metadata` y
`event_store.aggregate_id` existen porque las inyectan listeners `postGenerateSchema`
(`api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php:44,60`,
`api/src/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListener.php:42`) y se escriben por SQL crudo, así
que **ninguna propiedad de dominio las declara y ninguna reflexión sobre propiedades las ve**, sea cual sea el
mecanismo de descubrimiento; `event_store.aggregate_id` es además **la fuga permanente de 1.7/G-5**. Y si D2 eligiera
`getAllMetadata()`, súmale un punto ciego propio: `api/config/packages/doctrine.yaml:12-31` fija
`auto_mapping: false` con solo tres mappings (`Backoffice`, `Iam`, `Organization`), luego `src/Shared/` queda fuera.

**AC6 — Cableado del gate (NFR11).**
**Given** el gate,
**When** se inspecciona su cableado,
**Then** está en `php.quality` **y** en `php.quality.dry-run` **y** en `.PHONY`, porque CI corre el *dry-run*
(`.github/workflows/ci.yml:115`).
**Y** si el gate se parte en dos clases, el `--filter` es un **prefijo regex común** que las selecciona todas —
verificado listando los tests que el target selecciona, no razonándolo (hecho (F)).

**AC7 — Sin regresión + gates verdes.**
`make php.quality` (incluye `php.deptrac`, `php.lint.error-contract`, `.bounded-context`, `.event-bus`,
`.audit-resource`, `.persistent-transport`), `make php.unit`, `make php.behat`. Cada uno desde una **ejecución
fresca**, con el exit code impreso — nunca «verde» leído de un log anterior. Con D1=① el rojo del gate nuevo es
esperado y **se declara**; el resto tiene que estar verde.

## Tasks / Subtasks

- [ ] **Tarea 1 — Registrar las tres decisiones (PRECONDICIÓN, AC4).** D1, D2, D3 por escrito, confirmando o
      refutando cada recomendación. **Ninguna otra tarea empieza antes.** Si D1 sale ①, este mismo PR corrige
      `epics-gdpr-hardening.md:85-94,314` — no se deja la promesa rota en el corte.
- [ ] **Tarea 2 — El atributo hermano (AC3)**
  - [ ] `api/src/Shared/Privacy/Domain/<Nombre>.php`, `#[Attribute(Attribute::TARGET_PROPERTY)]`, pasivo, con el
        parámetro del dueño como **string** (hecho (C)). Docblock que enuncie el contrato de **referencia** y lo
        distinga del de tratamiento, **sin narrar el cambio ni citar la historia**.
  - [ ] Test de que `personalFieldsOf()` lo ignora — la separación de contratos es lo falsable de AC3.
  - [ ] Sembrar `PasswordResetToken::$userId` y `Session::$userId`. **Nunca en el trait `Identifiable`**: se
        propagaría a las 8 entidades.
- [ ] **Tarea 3 — El generador (AC2)**
  - [ ] Descubrimiento por `ApiSourceFiles::phpFiles()` + reflexión, molde
        `api/tests/Support/PersistentTransportPolicy.php:90-124`. **No** `getAllMetadata()`: `auto_mapping: false` y
        `src/Shared/` fuera de los mappings lo dejarían ciego a `event_store` (`doctrine.yaml:12-31`).
  - [ ] Lector de atributos con `IS_INSTANCEOF` y recorriendo padres — **no** el de
        `ReflectionPersonalDataClassifier.php:35`, que es el defecto I-2 de G-4a (hecho (D)).
  - [ ] Escritura segura del registro: temporal → `test -s` → `cat > destino`, **nunca `mv`**
        (`api/tools/deptrac/regen-baseline.sh:39-45`, con su razón: root-owned en el bind mount).
  - [ ] Cabecera del generado en fichero aparte y re-antepuesta (precedente
        `api/tools/deptrac/deptrac.baseline.header.txt`), con la fórmula `# GENERATED — regenerate with …`.
- [ ] **Tarea 4 — El gate (AC1, AC2, AC5, AC6)**
  - [ ] Test bajo `api/tests/Unit/Shared/Architecture/`, `#[CoversNothing]`, `public const string FAILURE_PREAMBLE`,
        camino verde con `addToAssertionCount(1)`.
  - [ ] Rechazar duplicados y clasificación no reconocida, **nunca degradarla a `non-person`**
        (`PersonResourceErasureGateTest.php:170-173,181-191`).
  - [ ] Validar la ruta del dueño con `is_file()` **y no** `assertFileExists()`: acepta **directorios**, y por eso
        `person :: docs` silenciaría la política — corregido ya en
        `api/tests/Support/PersistentTransportPolicy.php:134-137`.
  - [ ] Anti-vacuidad (`theGateScansAtLeast…`) y fixture sucio + gemelo limpio.
  - [ ] Evitar el deadlock entre aserciones que #613 pagó (I-7): no exijas a la vez *«existe una persona sin
        excepción»* y *«todo lo declarado sigue vivo»*.
  - [ ] Target en `make/php-quality.mk` + las tres inserciones (`:158`, `:176`, `.PHONY`). **Verifica el `--filter`
        listando los tests que selecciona.**
- [ ] **Tarea 5 — Boy-scout propuesto, decide y dilo: cerrar el hueco del filtro de #613 (hecho (F)).**
      `make/php-quality.mk:121` → prefijo regex común, para que las 13 aserciones de
      `PersistentTransportRoutingShapeGateTest` entren en CI. Un token en un fichero que esta historia toca de todas
      formas. **Si no se hace aquí, va a `deferred-work.md` con la medición** — no se calla.
- [ ] **Tarea 6 — Docs (regla de `CLAUDE.md` → *Keeping docs up to date*).** Los sitios que tocó #613, medidos:
      `CLAUDE.md` (*Required checks*), `docs/claude-code-quickref.md`, `docs/rules/security.md`,
      `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/architecture-api.md`. Evalúa `api/CLAUDE.md`, que hoy **no menciona**
      `Shared/Privacy` en *Rules that bite*.
- [ ] **Tarea 7 — Gates y pase adversarial (AC7 + definición de hecho de la épica)**
  - [ ] `make php.quality`, `make php.unit`, `make php.behat` — frescos, con exit code.
  - [ ] Checklist de seguridad de `CLAUDE.md` sobre el diff (ver *Seguridad*).
  - [ ] **Pase adversarial por alguien distinto del autor, REGISTRADO**, declarando dónde quedó. Sin él la historia
        no llega a `done` (NFR10). Un pase que no encuentra nada cuenta — y también se declara.

## Dev Notes

### Reuse map — lo que ya existe y NO se reinventa

| Necesidad | Ya existe | Ruta |
|-----------|-----------|------|
| Recorrer `api/src` como generador de ficheros PHP | `ApiSourceFiles::phpFiles()` | `api/tests/Support/ApiSourceFiles.php:41` |
| Descubrir clases por PSR-4 + reflexión y filtrar por herencia | `PersistentTransportPolicy::eventsInSource()` | `api/tests/Support/PersistentTransportPolicy.php:90-124` |
| Parsear un registro de la raíz de `api/` | `AllowlistFile::entries()` | `api/tests/Support/AllowlistFile.php:26` |
| Gate que lee un registro y valida el dueño por fichero | `PersonResourceErasureGateTest` | `api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php` |
| Gate con preámbulo, anti-vacuidad y fixture sucio | `EventDispatchGateTest` | `api/tests/Unit/Shared/Architecture/EventDispatchGateTest.php` |
| Escribir un artefacto commiteado sin dejarlo root-owned | `regen-baseline.sh` | `api/tools/deptrac/regen-baseline.sh:39-45` |
| Cabecera de artefacto generado | `deptrac.baseline.header.txt` | `api/tools/deptrac/deptrac.baseline.header.txt` |
| Leer atributos como los lee Symfony (`IS_INSTANCEOF` + padres) | `PersistentTransportPolicy::attributeTransportsFor()` | `api/tests/Support/PersistentTransportPolicy.php` |
| Fixtures de gate cuando hay que reflejar atributos/herencia | `Architecture/Fixture/` (7 ficheros de #613) | `api/tests/Unit/Shared/Architecture/Fixture/` |

### Anti-patrones concretos que esta historia invita a cometer

1. **Poner el dueño como `::class`.** Rompe `php.lint.bounded-context` y `php.deptrac` a la vez. Hecho (C).
2. **Copiar el lector de atributos de `ReflectionPersonalDataClassifier.php:35`.** Sin `IS_INSTANCEOF` ni padres: es
   el defecto I-2 que el pase adversarial de G-4a destapó. Hecho (D).
3. **Escribir el AC del corte sobre el sellado del diff.** No es ejecutable: ninguna entidad con referencia a persona
   es `AuditedEntity`. Hecho (A).
4. **Asumir que el registro «llega verde» sin resolver D1.** Con completitud estructural no llega, y eso puede ser lo
   correcto. Hecho (B).
5. **Un `--filter` que solo caza una de las clases del gate.** Ya pasó en #613 y nadie lo vio. Hecho (F).
6. **Anotar `$id` en el trait `Identifiable`.** Se propaga a las 8 entidades.
7. **Validar la ruta del dueño con `assertFileExists()`.** Acepta directorios.
8. **Comentar el cambio en vez del código.** Nada de «antes esto no existía», ni `G-1a`, ni `FR3`, ni `SI-22` en
   comentarios de `src`. La trazabilidad vive en el PR.
9. **Meter el generador en `src/` por analogía.** El clasificador está ahí porque tiene consumidor de producción; el
   generador no lo tiene. Si va a `src/`, que sea con argumento. D2.

### Arquitectura y fronteras

- El atributo vive en `Shared/Privacy/Domain/`, que `deptrac.yaml:132-138` auto-pliega en `Shared.Domain`: **sin
  registro nuevo en deptrac, sin entrada en `api/.bounded-context-allowlist`** (`Erpify\Shared\…` es siempre
  importable, `:7`).
- Un gate bajo `api/tests/Unit/Shared/Architecture/` es un test, no un módulo: deptrac analiza `api/src`.
- El atributo **cae dentro** del autoregistro de `services.yaml:23-27` (el `exclude` solo saca `Domain/Entity/`). Se
  poda en compilación por no tener consumidor; inofensivo, pero conviene saberlo antes de sorprenderse.
- **Esta historia no toca la cadena de erasure.** Si te ves editando `FulfilIdentityErasure`, para: eso es G-1b.

### Testing

- Suite unitaria: `api/tools/phpunit/phpunit.dist.xml`, con `failOnDeprecation`/`failOnNotice`/`failOnWarning` en
  `true`; `<source>` apunta a `api/src`, que es **por qué** los gates llevan `#[CoversNothing]`: reflexionar sobre
  `src` carga clases y les atribuiría cobertura falsa en el clover.
- **Tests que no pueden quedar rojos:** los 10 de
  `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php`, los 6 de
  `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserEraseFunctionalTest.php`, los 3 de
  `api/tests/Unit/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifierTest.php` y el de
  `api/tests/Unit/Backoffice/BankAccount/Domain/Entity/BankAccountPersonalDataTest.php`.
- **El test más frágil del área es el canary de presupuesto de 15 queries** de
  `api/features/backoffice/users/erase.feature:44` (*«un desplazamiento significa un round-trip añadido: re-mide, no
  subas el número»*). G-1a es estático y **no debería tocarlo**; si se mueve, algo se ha colado en la cadena.
- **Behat: esta historia no debería añadir ni un step.** Es sustrato de build, no comportamiento observable por la
  API. Si crees que hace falta uno, **lista antes el vocabulario** (`make php.behat c='-dl'`,
  `make php.behat c="-d '<texto>'"`) — `api/CLAUDE.md` lo exige, y más de la mitad de los 205 steps está ocioso.
- **Hueco de cobertura medido, por si lo quieres cerrar de paso:** el borrado de
  `PasswordResetToken::$userId` **solo está pinneado con mocks** (`EraseIdentitySubjectTest.php:37`,
  `FulfilIdentityErasureTest.php:78`); `erase.feature` no consulta `identity_password_reset_token`. Es la semilla más
  débil de las dos, y el registro va a afirmar que tiene dueño.

### Seguridad (checklist de `CLAUDE.md` aplicada a este diff)

- **Superficie HTTP:** ninguna. **Inyección / authz / validación / RFC 9457:** no aplican — decláralo en el PR, no lo
  omitas en silencio.
- **Datos personales:** la historia no mueve ni un dato personal; instala el control que declara dónde están. **No
  escribas en el PR que cierra una fuga** — no cierra ninguna: eso es G-1b, G-2 y G-5.
- **Secretos:** el registro es un artefacto de revisión con FQCN y rutas. **Ningún valor de dato, ningún id real.**
- **Migraciones:** ninguna. Si crees que sí, para y explica por qué.
- **Handlers de Messenger:** no se tocan.
- **Frontend:** cero cambios en `pwa/`.

**UX: no aplica, declarado y no omitido.** La historia no añade, modifica ni retira superficie de UI — la consola de
borrado GDPR ya se entregó en U-5 (`epics-users-admin.md`). Ningún UX-DR es derivable de los runs existentes y
ninguno se inventa.

### Docs a actualizar

Los cinco sitios que tocó #613 y su razón: `CLAUDE.md` (*Required checks* — una viñeta con la regla, el registro, el
gate y «sus puntos ciegos están en la cabecera del registro»), `docs/claude-code-quickref.md` (*Individual linters*),
`docs/rules/security.md` (checklist pre-commit), `PRODUCTION_SECURITY_CHECKLIST.md` (un ítem cerrado **y uno abierto
por lo que el gate NO cierra**) y `docs/architecture-api.md`. Evalúa además `api/CLAUDE.md` → *Rules that bite*, que
hoy no menciona `Shared/Privacy`.

### Inteligencia de la historia anterior (G-4a, merged en #613)

- **El contrato no aguantó el pase adversarial: 7 hallazgos antes de escribir código, 17 después.** El eje del gate
  (registro + política) sobrevivió; los detalles de lectura no. Trata este artefacto igual.
- **Lo que costó caro allí y aplica igual aquí:** leer atributos como los lee el framework (I-2), leer **toda** la
  configuración y no un fichero (I-3), no confiar en un nombre sin leer lo que hay detrás (I-6), y no dejar dos
  aserciones del gate mutuamente insatisfacibles (I-7).
- **La clave del registro fue el defecto ALTO (I-1):** `aggregateType` resultó **más gruesa que la propiedad que
  clasificaba**, y una clasificación errónea dio luz verde a la misma fuga. Aquí la clave propuesta
  (`<Fqcn>::$prop`) es exactamente de la granularidad del hecho — **es la lección aplicada, dilo en el PR** — pero
  paga rename de clase, y por eso D3 es una decisión y no un detalle.
- **La honestidad del alcance también fue un hallazgo (I-16):** documentación que seguía prometiendo lo que la propia
  rama había medido como inexistente. Al escribir la cabecera y los docs, describe lo que el gate hace, no lo que la
  épica quería que hiciera.

### Git intelligence

`9310efeb` (cierre de G-4a, chore documental), `0d2d45d2` (**#613 — la implementación de G-4a**: registro
`api/.persistent-transport-policy`, `PersistentTransportPolicyGateTest`, `PersistentTransportRoutingShapeGateTest`,
`api/tests/Support/PersistentTransportPolicy.php`, target `php.lint.persistent-transport` y 7 fixtures — 43 ficheros,
+2945/−326), `009b0756` (#611, solo `pwa/`), `f4dbe4d1` (#609, corte de la épica). **`api/` SÍ se movió desde el corte
de épica**, y lo que se movió es justo el precedente que esta historia copia: no leas `.audit-resource-types` como
único modelo, lee `.persistent-transport-policy`, que ya lleva incorporadas las correcciones de dos pases
adversariales.

## References

- `_bmad-output/planning-artifacts/epics-gdpr-hardening.md` — FR2, FR3, FR4; NFR1/SI-21, NFR2/SI-22, NFR3/SI-23,
  NFR8, NFR10, NFR11; Story 1.2.
- `_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md` — SI-21/SI-22/SI-23, fila **G-1a** de la tabla de
  localización, DAG safe-first.
- `_bmad-output/implementation-artifacts/g-4a-fuga-passwordresetcompleted-transportes-messenger.md` — el precedente
  completo: contrato, decisión registrada y los dos pases adversariales.
- `docs/adr/audit-activity-log.md` — D4 (prohibición de crosswalk).
- `docs/adr/regulatory-audit-trail.md` — D15 y la separación `event_store` (negocio) vs rastro regulatorio.
- `docs/adr/external-dependencies-in-domain.md` — la excepción de metadatos pasivos en `Domain/`.
- `CLAUDE.md` — *Security review on every change* → **Process** (pase adversarial registrado); *Code comments*;
  *Keeping docs up to date*; *Required checks*.
- `api/CLAUDE.md` — deptrac, gates, y el vocabulario Behat como activo a gastar.

## Dev Agent Record

### Agent Model Used

Claude Opus 5 (1M context) — `claude-opus-5[1m]`.

### Debug Log References

Mediciones de contexto ejecutadas al crear la historia (2026-07-30, stack de dev arriba, checkout primario en
`9310efeb`):

- `bin/phpunit --filter=PersistentTransportPolicyGateTest --list-tests` → **7 tests**, todos de esa clase.
- `bin/phpunit --filter=PersistentTransportRoutingShapeGateTest --list-tests` → **13 tests**.
- `bin/phpunit --filter='PersistentTransport.*GateTest' --list-tests` → **20 tests**.
  → sustenta el hecho (F). No se ejecutó ningún gate ni se escribió nada en `api/`.

### Completion Notes List

### File List
