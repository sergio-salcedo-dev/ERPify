---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories', 'step-04-final-validation']
inputDocuments:
  - _bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md
  - _bmad-output/planning-artifacts/architecture/gdpr-hardening/.memlog.md
  - docs/adr/regulatory-audit-trail.md
  - docs/adr/audit-activity-log.md
  - _bmad-output/planning-artifacts/arch-addendum-identity-invitation.md
  - _bmad-output/planning-artifacts/arch-addendum-users-admin.md
  - _bmad-output/planning-artifacts/epics-users-admin.md
  - docs/project-context.md
  - tmp/bmad-md/consult-gdpr-hardening-epic-axis-20260729-002631.md
---

# ERPify — GDPR hardening: garantías de borrado ejecutables — Desglose de épica

## Overview

Desglosa la épica **GDPR-hardening (G-1a…G-4b)** definida en el DAG de
[`arch-addendum-gdpr-hardening.md`](./arch-addendum-gdpr-hardening.md): convertir las garantías de borrado
de datos personales de **prosa** en **mecanismo con control capaz de fallar**, sobre `Iam/Identity`,
`Iam/Invitation`, `Organization/Membership`, `Shared/Audit` y los transportes Messenger persistidos.

La épica **no introduce un principio arquitectónico nuevo**: extiende al dominio del borrado el patrón que el
repo ya institucionalizó —**registro declarativo revisable + gate obligatorio**— con seis instancias medidas
(`php.lint.audit-resource`, `.bounded-context`, `.error-contract`, `.event-bus`, `.doctrine`, `php.deptrac`,
cableadas en `php.quality` y `php.quality.dry-run` de [`../../make/php-quality.mk`](../../make/php-quality.mk)).
**La unidad de planificación es el eje de garantía, no el issue**: cada historia instala un eje completo —
*declaración de propiedad · mecanismo de ejecución · control capaz de fallar*.

**No** construye superficie de cumplimiento (el mapa `dato personal → contexto → responsable → mecanismo →
evidencia` es un entregable posterior, cuando documente controles construidos en vez de intenciones), ni
tenancy, ni base jurídica, ni plazos de conservación fuera del suelo de 5 años ya vigente (D7).

> **Derivado de un contrato ya ratificado.** Este inventario **no** es independiente del diseño: las FR/NFR
> destilan decisiones ya cerradas en [`arch-addendum-gdpr-hardening.md`](./arch-addendum-gdpr-hardening.md)
> (SI-21/SI-22/SI-23 + localización por historia + DAG safe-first), cuyo histórico argumental vive en
> `tmp/bmad-md/consult-gdpr-hardening-epic-axis-20260729-002631.md` y cuyo registro de decisiones y mediciones
> vive en `architecture/gdpr-hardening/.memlog.md`. El objetivo aquí es **trazabilidad e implementabilidad**,
> no re-abrir el diseño.

> **Los cuerpos de issue NO son la fuente.** El addendum midió cada premisa contra `main` y encontró dos
> divergencias: #546 **no registra** la fuga viva real (`PasswordResetCompleted`), y #564 describe un mecanismo
> real pero **inalcanzable** en la topología actual. Los números de issue viajan aquí como **etiqueta de
> trazabilidad**; toda AC se escribe contra código medido, con `fichero:línea`.

**Estado de la medición.** Las cinco premisas vivas y los tres hechos de soporte se re-midieron contra `main`
(`471ae66f`) al cortar esta épica, y **las cinco resultaron vivas**. Los hechos concretos **no se enumeran aquí**:
viven en el bloque `Estado medido` de la historia que cada uno hace falsable, que es su fuente única.

## Requirements Inventory

### Functional Requirements

FR1: **Cerrar la fuga viva de `PasswordResetCompleted` en transportes Messenger persistidos** (SI-21 · G-4a) —
el evento está enrutado a `async` en [`../../api/config/packages/messenger.yaml`](../../api/config/packages/messenger.yaml);
su agregado es [`User`](../../api/src/Iam/Identity/Domain/Entity/User.php) y su propio docblock declara que
*«the subject is the aggregate id alone»*, luego el `aggregateId` **es el id del usuario**. Se serializa a
`messenger_messages` (transporte Doctrine, **sin TTL ni poda**) y a la cola `failed` (**sumidero permanente**),
y [`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) **no toca ninguna
tabla de Messenger** — tras el erase, el id real del sujeto sobrevive ahí. El comentario de routing que autoriza
la cola («payload = solo el id del agregado») es **correcto para `Bank` y falso para `User`**. La corrección
incluye la **regla afilada** que lo generaliza: un payload «solo el id del agregado» no es seguro por
construcción; lo es **si y solo si el agregado no es una persona**.

FR2: **Declaración de referencia-a-persona — atributo hermano en `Shared/Privacy`** (SI-21, SI-22 · G-1a ①) —
un atributo pasivo **nuevo** (p. ej. `#[PersonSubjectReference]`) junto a
[`PersonalData`](../../api/src/Shared/Privacy/Domain/PersonalData.php), que marca *«esta propiedad guarda el
identificador de una persona»* **y su dueño de borrado**. **No** se reutiliza `#[PersonalData]`: su contrato es
de **tratamiento** (*«the property it decorates holds personal data… infrastructure only reads it to decide
encrypt-vs-clear per column»*), y el de este es de **referencia**.

*Corregido tras medir — el justificante anterior era falso en su último eslabón.* Decía que anotar una clave
foránea haría que [`PiiDiffSealer`](../../api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php)
*«la cifrara en el diff, rompiendo las búsquedas del reconciliador»*. **Nada de eso ocurre hoy:** el sellador
solo corre sobre entidades `AuditedEntity`, y de las cuatro que guardan una referencia a persona **ninguna lo
es**, así que anotarlas sería un no-op literal; el sellador nunca toca la columna de la entidad (escribe en
`audit_log.metadata`, y `bank_account.iban` está marcado `#[PersonalData]` y sigue siendo `unique` y buscable en
claro); y el reconciliador no lee ningún valor de diff. **La conclusión se sostiene, el argumento no.** Los dos
que sí se sostienen: ① reutilizarlo **asignaría el dueño de borrado equivocado**, porque el scope de cifrado es
el del **agregado propietario**, no el de la persona — un id de persona sellado bajo la DEK de otro agregado se
destruiría con el borrado de *ese* agregado y **sobreviviría al de la persona**, que es exactamente el fallo que
SI-21 persigue; ② el puerto `personalFieldsOf()` devuelve nombres de propiedad y **no tiene dónde llevar un
dueño**. Ambos son argumentos de **referencia**, no de tratamiento, y por tanto coherentes con el resto de FR2.

FR3: **El registro se verifica contra el código en las DOS direcciones** (SI-22 · G-1a ②) — el **universo** de
referencias es derivable por reflexión, así que el control comprueba (i) que **todo miembro del universo está
clasificado** y (ii) que **toda entrada del registro sigue correspondiendo a un miembro**. Con las dos
direcciones clavadas a la fuente, el contenido **no puede ser una afirmación libre**, que es la propiedad que
impide el ciclo de #563 — y se obtiene **sin generar el fichero**.
[`PersistentTransportPolicyGateTest`](../../api/tests/Unit/Shared/Architecture/PersistentTransportPolicyGateTest.php)
ya la consigue así (`everyAggregateTypeInSourceIsClassified` + `theRegistryDeclaresNoAggregateTypeThatNothingEmits`).
**Generar es implementación admisible, no exigida** — y aquí no compra nada: un generador solo puede emitir la
**clave**, que es justo la mitad que las dos direcciones ya clavan, y **no puede aportar el dueño de borrado**,
que es el único campo con significado y es juicio humano. Lo derivable se verifica, lo manual se atestigua.

FR4: **Gate `make php.lint.person-reference`** (SI-21, SI-23 · G-1a ③) — control que **rompe el build** cuando
(a) un miembro del universo derivable **no está clasificado**, (b) una entrada del registro **ya no corresponde
a ningún miembro**, o (c) una referencia persistida a persona **no declara dueño de borrado, o declara uno que
no la borra**. (a) y (b) son las dos direcciones de FR3; (c) es la mitad *«correctamente cableada»* de SI-21.
**El gate verifica y no escribe** — un control que reescribe lo que comprueba lee verde por construcción, que
es #563. Sigue el patrón medido de la casa: test bajo
[`api/tests/Unit/Shared/Architecture/`](../../api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php),
target `php.lint.*` en [`../../make/php-quality.mk`](../../make/php-quality.mk), cableado en `php.quality`
**y** `php.quality.dry-run` (CI). **Se estrena sobre comportamiento correcto**: las referencias que la cadena de
erasure **ya** borra hoy —[`PasswordResetToken::$userId`](../../api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php)
y [`Session::$userId`](../../api/src/Iam/Session/Domain/Entity/Session.php)— quedan **verdes**, demostrando que
el mecanismo funciona; y **el gate aterriza en rojo por `Membership.user_id` e `Invitation.invited_user_id`**,
que es el estado que FR5 y el segundo AC de la Story 1.3 exigen. *Corregido tras medir: la redacción anterior
decía «llega verde» a secas y era insatisfacible junto con FR5 — un control verde mientras el defecto está vivo
es prosa con pasos extra. El propósito de aquella frase (estrenar sobre comportamiento correcto) lo cumplen las
dos semillas.*

FR5: **Cerrar las referencias huérfanas — `Membership.user_id` (#545) e `Invitation.invited_user_id` (#561)**
(SI-21, SI-19 · G-1b) — con el gate en rojo, la cadena de erasure
(`eraseIdentitySubject → auditActorAnonymiser → auditResourceAnonymiser → purgeUserSessions → auditLogger`)
adquiere dueño para [`Membership::$userId`](../../api/src/Organization/Membership/Domain/Entity/Membership.php)
e [`Invitation::$invitedUserId`](../../api/src/Iam/Invitation/Domain/Entity/Invitation.php). **No son dos bugs
sino dos manifestaciones del generador**: la regla de arquitectura que obliga a referenciar por id entre
contextos produce una referencia nueva por cada contexto que toque a una persona. **Además cierra un invariante
de seguridad**: el guard `keepsAnActiveAdminWithout`
([`ActiveAdministratorDirectory`](../../api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php),
consumido por `ChangeUserStatus:85` y `ChangeUserRoles:128`) lee `identity_user`, **no** membership — una
membership fantasma con rol `ADMIN` deja «≥1 ADMIN activo» leyendo satisfecho. **Cerrar la referencia y cerrar
el invariante van juntos.**

FR6: **`audit_log.metadata` deja de albergar ids de persona** (SI-21, D4 · G-2, #560) —
el borrado del sujeto escribía su id real en el `metadata` de la fila `GDPR_SUBJECT_ERASED` y **ningún
anonimizador toca `metadata`**. *Encuadre corregido tras medir (2026-08-03):* lo que impide hoy el crosswalk
**no es un comentario** sino una guarda con 409 `self-erasure-forbidden` — prohibido el auto-borrado, el actor
de esa fila nunca es el sujeto. La premisa «D4 está sostenido por prosa» es más débil de lo que este corte
afirmaba. **La fuga era real igualmente, y por una razón mejor:** el id crudo vivía en `metadata` con
independencia de quién fuese el actor, y ninguna mutación del conjunto cerrado entra en el JSON, así que
sobrevivía al borrado que lo escribió. *Se cerró* sin redactor y sin gate (ver la historia 1.4): el sujeto pasa
a viajar como **recurso** de la entrada y `metadata` se queda con conteos, con lo que el anonimizador de recurso
que la cadena ya ejecutaba lo alcanza. Ninguna opción introduce tabla de mapeo. *Coordenadas en el `Estado
medido` de la historia 1.4.*

FR7: **Segundo testigo del registro `.audit-resource-types` (#563)** (SI-22, SI-23 · G-3 ①) — el check de
staleness **se satisface con su propia declaración**: el único literal del tipo persona en `api/src` vive en el
fichero que la propia entrada de [`../../api/.audit-resource-types`](../../api/.audit-resource-types) señala, así
que lo satisface el consumidor y no un escritor. La entrada es **manual** (el tipo no es derivable por
reflexión), luego SI-22 exige un **segundo testigo independiente de la declaración**. *Riesgo de
construibilidad registrado en Additional Requirements; coordenadas en el `Estado medido` de la historia 1.5.*

FR8: **Agendado, fallo observable y alertado de `ReconcileErasedSubjectReferences` (#566)** (SI-21 · G-3 ②) —
[el reconciliador](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php) existe y tiene
CLI, pero **no está agendado**, y el listener de acceso emite en `kernel.terminate`, **después** del commit del
erase → ventana de resurrección. SI-21 exige que, cuando la propiedad no puede decidirse estáticamente y se
admite un control agendado, **el agendado, el fallo observable y el alertado formen parte de la misma entrega**
— un control detective que nadie ejecuta no es un control. Queda además una **decisión de ubicación** abierta,
porque no hay precedente de schedule de mantenimiento dentro de un contexto de negocio. *Coordenadas y asimetría
del fork en el `Estado medido` de la historia 1.6.*

FR9: **Eje Messenger completo — dueño de borrado y gate sobre referencias nacidas en configuración**
*(SI-21, SI-22 · G-4b — **fuera de alcance, bloqueada**)* — el routing de Messenger materializa una referencia
persistida sin que ninguna propiedad de dominio la declare, así que el mecanismo de FR2–FR4 (reflexión sobre
propiedades) **no la alcanza**. Bloqueada por la pregunta abierta de *ownership de metadatos arquitectónicos*
(ver Additional Requirements). Se lista para trazabilidad: es **secuenciación diferida, no exclusión
permanente**. FR1 cierra la fuga concreta **sin** requerir esta respuesta.

FR10: **El `event_store` deja de conservar el identificador real de una persona a perpetuidad** (SI-21 · G-5) —
descubierto **al contextualizar G-4a**, midiendo contra `main`, y **no registrado en el addendum**:
[`PersistDomainEventMiddleware`](../../api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php)
escribe **todo** evento despachado en `event_store` **antes** de que Messenger decida transporte, así que ocurre
con `async`, con `sync` y sin enrutar — **el routing no lo cambia, y por tanto G-4a no lo toca**. Llevan el id
real como `aggregate_id` `PasswordResetCompleted`, `UserSuspended`, `UserDeactivated`, `UserRolesChanged`,
`UserLocked` ([`User`](../../api/src/Iam/Identity/Domain/Entity/User.php), líneas 176/191/206/224/264) y
`PasswordResetRequested` (grabado sobre el agregado del token pero construido con el id del **usuario**,
[`PasswordResetToken`](../../api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php) línea 70); y lo llevan en
el **payload** `SessionStarted` (`['userId' => …]`), **`SessionRevoked`** (ídem) y los seis `Invitation*`
(`invitedUserId`). **Ninguno está enrutado**, luego ninguno pasa por la cola: viven **solo** ahí, para siempre, y
[`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) no toca la tabla.
**La fuga que persigue G-4a es la más pequeña y la más transitoria de las dos.**

**El eje de payload no es accesorio, y de él depende que el test falsable de la historia signifique algo.** Una
historia que solo reescriba `aggregate_id` deja `SELECT aggregate_id FROM event_store` en verde mientras el id
sigue vivo en el JSONB de la fila de al lado — una declaración cuya única evidencia es la mitad que eligió
mirar, que es NFR3 fallando dentro de la historia que existe para instalarlo. **Los dos ejes se cierran en la
misma entrega.**

### NonFunctional Requirements

NFR1 (Invariante · **SI-21**): **Toda referencia persistida a una persona física tiene un dueño de borrado
identificado.** Dos obligaciones distintas y ninguna implica la otra: que **exista un dueño** (realidad) y que
**exista su declaración** (mecanismo documental). El dueño se declara en un artefacto **revisable** y un
mecanismo automático verifica que la declaración **existe y está correctamente cableada**. El mecanismo por
defecto **rompe el build**; un control agendado solo se admite cuando la propiedad **no puede decidirse
estáticamente**, y entonces agendado + fallo observable + alertado son **la misma entrega**. División heredada
de [`../../api/.audit-resource-types`](../../api/.audit-resource-types): **el humano clasifica, la automatización
verifica** — el gate nunca juzga la clasificación, solo que exista y esté conectada; la clasificación no queda
fuera de control, **cambia de mecanismo de validación** (revisión de arquitectura). *Alcance de «referencia»:*
incluye el `aggregateId` de un evento persistido cuyo agregado **es** una persona. *SI-21 no prescribe el
mecanismo de declaración* — la forma depende del **origen** de la referencia.

NFR2 (Invariante · **SI-22**): **Lo derivable se verifica contra el código; lo manual se justifica y se
atestigua.** Toda declaración cuyo **universo sea derivable** se verifica **en las dos direcciones contra la
fuente**: todo miembro del universo está clasificado, y toda entrada sigue correspondiendo a un miembro. Con
ambas comprobadas, la entrada **no puede satisfacerse a sí misma**. **Generar el registro es una implementación
admisible de esa propiedad, no la exigida.** Toda declaración **manual** se justifica por no ser derivable y
exige un **segundo testigo** independiente de la propia declaración.

NFR3 (Invariante · **SI-23**): **Una declaración nunca es su propia evidencia.** Un control cuya evidencia es la
declaración que debería verificar **lee verde por construcción** y no es un control. *Binds:* todo registro,
gate o control del repo, no solo los de esta épica. Es el principio que SI-21 y SI-22 instancian. **Candidato a
ADR solo si reaparece fuera de este dominio** — hoy nace de una sola familia de problemas.

NFR4 (Heredado · **D4**, [`../../docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md)):
**prohibida toda tabla crosswalk `id real → pseudónimo`**, en columna o en JSONB. Ninguna historia de la épica
la introduce; FR6 existe precisamente para que la prohibición deje de depender de un comentario.

NFR5 (Heredado · **D15**, [`../../docs/adr/regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md)):
`erase-actor` y `erase-subject` son **operaciones legales distintas** y no se fusionan nunca. **Matiz medido:**
D15 descarta *extender* `erase-actor` para que además haga trabajo de subject; **no prohíbe que se niegue a
correr** — una guarda no fusiona las dos operaciones.

NFR6 (Heredado · **SI-19**): el erase des-identifica **identidad + rastro como una unidad**; un erase que solo
borre la identidad es **incompleto**. Es el criterio que convierte FR5 en obligación y no en mejora.

NFR7 (Heredado · **aislamiento de contextos**): `Shared/Audit` **no aprende semántica ajena**. Posee la *forma*
`(type, id)` de `AuditResource`; **no el vocabulario** — cada contexto mintea su propio string de tipo. El
Shared Kernel evita dependencias de **compilación**, no de **significado**. Gates: `make php.lint.bounded-context`
+ `make php.deptrac`.

NFR8 (Heredado · **contrato de `#[PersonalData]`**): su contrato es de **tratamiento**, no de referencia, y **no
se reutiliza** para marcar referencias a persona (razón medida en FR2, no estética).

NFR9 (Heredado · **alcance del crypto-shredding**): cubre **solo `audit_log`** (`PiiDiffSealer` + destrucción de
DEK por `EncryptionScopeId`). **No cubre payloads de Messenger** ni ninguna otra tabla — por eso FR1 no se
resuelve solo.

NFR10 (Proceso · [`../../CLAUDE.md`](../../CLAUDE.md)): **ninguna historia de esta épica llega a `done` sin pase
adversarial registrado.** La autocertificación no cuenta: el gate es una lectura hostil por alguien distinto del
autor, y **dónde quedó registrada** debe declararse al dar la historia por hecha. La regla muerde aquí por
precedente medido: el clúster #560–#567 salió de **una sola** pasada adversarial sobre #558, y la historia GDPR
previa se mergeó autocertificada. **La convergencia entre análisis no es verificación.**

NFR11 (Patrón de la casa · **cableado del gate**): todo gate que la épica cree entra en `php.quality` **y** en
`php.quality.dry-run` de [`../../make/php-quality.mk`](../../make/php-quality.mk) — CI corre el *dry-run*, así
que un gate que solo entre en `php.quality` es un gate que CI no ejecuta.

### Additional Requirements

Requisitos técnicos medidos que condicionan el corte, la secuencia o la implementación:

- **Orden safe-first del DAG:** `G-4a → (G-1a · G-1b) → G-2 → G-3`. G-4a primero porque es **la única
  manifestación viva medida del eje** y no depende de nada. **G-1a y G-1b se entregan juntas, en una sola PR**
  (decisión de 2026-08-01): el gate de G-1a **aterriza en rojo** por las dos referencias que G-1b cierra —estado
  que FR5 y el segundo AC de la Story 1.3 exigen, y que por tanto tiene que existir y quedar visible en la
  secuencia de commits— y **sale verde dentro de la misma entrega, porque la cadena las ejecuta**. Entregarlas
  por separado dejaría `main` rojo para todo PR no relacionado, porque el target entra en `php.quality.dry-run`,
  que es lo que CI corre siempre; unirlas **disuelve** ese coste en vez de negociarlo. G-2 y G-3 son
  independientes y pueden paralelizarse. **G-2 tampoco depende de G-1a/G-1b:** esa arista era condicional al
  fork de G-2 y quedó resuelta el 2026-08-03 al cerrarse DA-1 hacia el redactor. La flecha `→ G-2 →` expresa el
  orden de merge elegido, no una dependencia — el addendum la dibujaba como incondicional y se corrigió.
- **Plantilla exacta del mecanismo (FR2–FR4):** `Shared/Privacy` ya es una capability de tres piezas —atributo
  en `Domain/`, puerto `PersonalDataClassifier` en `Application/`, adaptador por reflexión en
  `Infrastructure/`—. **El hermano replica solo el atributo**: va en `Domain/` como metadata pasiva. *Corregido
  tras medir: las tres piezas existen porque `PiiDiffSealer` consume el puerto en producción, y este registro
  **solo lo lee el gate**. Un puerto sin nadie a quien inyectarlo es superficie de producción a cambio de nada
  (YAGNI). El motor de descubrimiento vive en `api/tests/Support/`, espejo de `PersistentTransportPolicy`, que
  es el precedente medido de esta forma exacta: registro consumido solo por un gate. El día que aparezca un
  consumidor de producción —la cadena de erasure leyendo el registro para dirigir el borrado— promoverlo a
  `Infrastructure/` es un movimiento, no una reescritura.*
- **Plantilla exacta del gate:** los seis gates existentes son **tests PHPUnit** bajo
  `api/tests/Unit/Shared/Architecture/` invocados por un target `php.lint.*`; el registro
  `.audit-resource-types` vive en la **raíz de `api/`, fuera de todo contexto** — es un artefacto de revisión,
  no un módulo. El nuevo registro sigue esa colocación.
- **Sin precedente de schedule en contexto de negocio** (FR8): decisión de ubicación a tomar en el corte fino,
  no heredable.
- **Riesgo de construibilidad del segundo testigo (FR7) — RESUELTO al medir, y la premisa era falsa por partida
  doble.** Sigue siendo cierto que **nada en `api/src` escribe hoy una fila con `resource_type = 'User'`**. Lo
  que no era cierto es lo que se dedujo de ahí. **(a) El testigo ya está en el árbol, commiteado:**
  [`erase.feature`](../../api/features/backoffice/users/erase.feature) siembra una fila real con ese
  `resource_type`, ejecuta un borrado real por HTTP y comprueba que **ninguna** fila sigue nombrando a la
  persona en el eje de recurso — más tres tests funcionales con el mismo tipo contra Postgres real; y hay
  precedente de leer `api/features` desde un gate unitario, así que el escenario se lee como **corpus de texto**,
  nunca se ejecuta: evidencia de grado aceptación al precio de un gate unitario. **(b) La opción del «escritor de
  producción» tampoco estaba bloqueada por falta de planificación**, que era la razón registrada: hacer que
  `User` implemente `AuditedEntity` son dos métodos. Lo que la deja fuera de alcance es **la cola** — cada
  `flush` de usuario pasaría por `PiiDiffSealer` estrenando superficie de crypto-shredding y retención, cuatro
  canarios de presupuesto de queries se moverían, y `ChangeUserRoles` necesitaría un `AuditLogger` que hoy no
  inyecta. *Decisión y encuadre del testigo: ver el artefacto de la Story 1.5.*
- **Pregunta abierta que bloquea FR9 — ownership de referencias nacidas en configuración.** *¿Quién posee una
  referencia que no nace en el modelo sino en configuración?* **No es una pregunta de GDPR sino de ownership de
  metadatos arquitectónicos**, y su respuesta afectará también a scheduler, rutas, integraciones y eventos
  externos. Merece **historia de diseño propia**; el addendum la deja explícitamente abierta.
- **Decisión pendiente del usuario — #567 (`audit:gdpr:erase`).** La pregunta no es *«cómo arreglamos el CLI»*
  sino *«¿debe existir un CLI que solo se usa con seguridad porque el operador leyó el `--help`?»*. **Medido:**
  `EraseActorAuditTrailCommand` **no tiene invocadores** en `api/src` (las únicas menciones son referencias
  cruzadas en docblocks). D15 no bloquea una guarda; el obstáculo real es que la clasificación «`User` denota
  persona» es vocabulario del **contrato de auditoría**, no del modelo de dominio, así que moverla a
  `Shared/Privacy` sería inversión en la dirección contraria. **YAGNI empuja a eliminar el comando antes que a
  hacerlo más listo.**
- **Fuera de la épica, con razón registrada:** **#562** (N+1 del reconciliador) y **#565** (deadlock entre
  erasures concurrentes) — ninguno cambia si una garantía *puede fallar*, y #565 además falla seguro y es
  reintentable: seguimiento operativo. **#564** — fuera **por alcanzabilidad, no por categoría**: el mecanismo
  está confirmado y su radio es peor que el del issue (tres niveles; el regulatorio sale de `flush()` y tumba la
  escritura de negocio auditada), pero el servicio `php` **no declara `replicas:`** en `compose.prod.yaml`, así
  que la ventana es **latente, no viva**. *Tripwire de reapertura:* cualquier topología con réplicas de `php` o
  despliegue rolling → deja de ser item de épica GDPR y pasa a **defecto de disponibilidad urgente**.
- **Dos propuestas ya muertas por medición — no re-proponer:** reutilizar `#[PersonalData]` para marcar
  referencias (rompe `PiiDiffSealer`), y que `Shared/Privacy` desbloquee #567 (inversión de conocimiento).

### UX Design Requirements

**No aplica — declarado, no omitido.** Todas las historias son **sustrato de build**: un atributo pasivo, un
generador por reflexión, un gate de CI, el traslado del sujeto al eje de recurso de `audit_log` (G-2; esta línea
decía «un redactor de `metadata`» dando por resuelto un fork que entonces seguía abierto, y la forma que ganó no
construye redactor), un schedule y una corrección de routing de
Messenger. **Ninguna añade, modifica ni retira superficie de UI**, así que ningún UX-DR es derivable de los runs
UX existentes (`ux-ERPify-2026-06-26`, `ux-ERPify-2026-07-06`) y ninguno se inventa.

La superficie de consola del borrado GDPR **ya se entregó** en la épica `users-admin` (U-5:
acción «Borrado GDPR (irreversible)», ADMIN-only, `type-to-confirm`) — ver
[`epics-users-admin.md`](./epics-users-admin.md). Esta épica endurece lo que ese botón **garantiza**, no lo que
muestra. *Consecuencia operativa a verificar en el corte fino:* si FR8 elige el camino de control agendado,
su **fallo observable** necesita destino de alerta, y eso es observabilidad, no UI.

### FR Coverage Map

| FR | Épica | Historia | Qué cubre |
|----|-------|----------|-----------|
| FR1 | Épica 1 | **1.1** (G-4a) | Fuga viva de `PasswordResetCompleted` en `messenger_messages` + `failed` |
| FR2 | Épica 1 | **1.2** (G-1a) | Atributo hermano de declaración en `Shared/Privacy` |
| FR3 | Épica 1 | **1.2** (G-1a) | Verificación bidireccional del registro contra la fuente |
| FR4 | Épica 1 | **1.2** (G-1a) | Gate `php.lint.person-reference`; semillas verdes, rojo por las dos sin dueño |
| FR5 | Épica 1 | **1.3** (G-1b) | `Membership.user_id` + `Invitation.invited_user_id` + invariante ≥1 ADMIN |
| FR6 | Épica 1 | **1.4** (G-2) | Ids de persona fuera de `audit_log.metadata` |
| FR7 | Épica 1 | **1.5** (G-3 ①) | Segundo testigo de `.audit-resource-types` |
| FR8 | Épica 1 | **1.6** (G-3 ②) | Agendado + fallo observable + alertado del reconciliador |
| FR9 | Épica 1 — **diferida** | — (G-4b) | Eje Messenger completo. **No se corta**: bloqueada por la pregunta abierta de *ownership de referencias nacidas en configuración*. Listada para que la ausencia sea explícita, no silenciosa. |
| FR10 | Épica 1 | **1.7** (G-5) | Ids de persona fuera del `event_store` — **abre con decisión de estrategia de persistencia, que es del usuario** |

**G-3 se corta en dos historias** (1.5 y 1.6) porque el addendum las declara *«dos subproblemas
independientes: cada uno se cierra sin responder al otro»* — mantenerlas juntas ataría el segundo testigo
(bloqueado por una decisión de definición) al agendado (bloqueado por una decisión de ubicación), y ninguna de
las dos decisiones desbloquea la otra.

**NFR5 (D15) no lo consume ninguna historia, y es deliberado.** Su única consecuencia viva —#567, la guarda
o eliminación de `audit:gdpr:erase`— quedó fuera del corte por decisión explícita. Se conserva en el inventario
como **restricción heredada que ninguna historia puede contradecir**, no como requisito sin cubrir: si una
historia futura tocara `erase-actor`, D15 vuelve a morder. Los demás NFR se consumen desde las historias por su
nombre de invariante (`SI-`/`D`) con el número `NFR` como alias, para que la trazabilidad funcione en ambas
direcciones. **NFR10 vive una sola vez, en la definición de hecho de la épica**, en vez de repetirse como AC en
cada historia.

Cobertura: **8 de 9 FR cortados**, 1 diferido con razón registrada. Ningún FR queda sin épica.

## Epic List

### Epic 1: Garantías de borrado ejecutables (GDPR hardening)

Cuando una persona ejerce su derecho de supresión, **ninguna referencia suya sobrevive en ninguna tabla
persistida**; y el repositorio pierde la capacidad de crear una referencia nueva **sin dueño de borrado
declarado y verificado**. Las garantías dejan de vivir en prosa y docblock y pasan a tener un control que
**puede ponerse rojo**.

**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR6, FR7, FR8 (**FR9 diferida** — bloqueada, no cortada).

**Espina de historias (DAG safe-first):** `G-4a → (G-1a · G-1b) → G-2 → G-3`. G-1a y G-1b **se entregan juntas**:
el gate aterriza en rojo y sale verde dentro de la misma PR.

**Valor por tramo — cada uno se sostiene solo:**

- **G-4a** — el id real de un sujeto borrado deja de sobrevivir en los transportes Messenger persistidos. Única
  fuga **viva medida**, sin dependencias: entrega valor legal aunque el resto no llegue.
- **G-1a** — el eje existe: las dos referencias que la cadena ya borra quedan **verdes** y demuestran el
  mecanismo, y los huecos vivos pasan a ser **gate rojo con dueño asignado** en vez de prosa.
- **G-1b · G-2** — cierran tres huecos, pero **no los tres «que el gate destapa»**: el gate de G-1a aterriza en
  rojo por **dos** referencias (`Membership.user_id`, `Invitation.invited_user_id`), que son las de G-1b. El
  hueco de G-2 vive en `audit_log.metadata`, una tabla **sin entidad Doctrine**, y el gate deriva su universo
  por reflexión sobre entidades: es estructuralmente ciego a él, así que nunca lo destapó. Los dos huecos que
  destapa y el que no comparten el eje, no el mecanismo que los encuentra. *(G-1b tampoco tapa el invariante
  **≥1 ADMIN activo**: medido el 2026-08-01, `keepsAnActiveAdminWithout` lee `identity_user` y solo esa tabla,
  así que una membership fantasma no puede satisfacerlo — la consecuencia era latente, no viva.)*
- **G-3** — las dos garantías que hoy *aparentan* funcionar (un check que se autosatisface, un reconciliador que
  nadie ejecuta) pasan a fallar de verdad cuando fallan.

**Por qué una épica y no dos.** Los ocho FR comparten superficie núcleo —`Shared/Privacy`, el registro en la
raíz de `api/`, [`../../make/php-quality.mk`](../../make/php-quality.mk) y la cadena `FulfilIdentityErasure`—,
que es el caso canónico de consolidar en una épica con historias ordenadas. El único acoplamiento direccional
(si el mecanismo de G-1a sale mal, G-1b y G-2 cambian de rumbo) es **intra-épica** y lo resuelve el orden, no
una frontera. *Alternativa descartada:* partir en «reparar lo roto» (G-4a + G-3) e «institucionalizar»
(G-1a → G-1b · G-2) — frontera **narrativa, no de riesgo**, que además separaría G-4a de G-1a justo después de
que el pase de precisión post-#606 estableciera que **G-4a no es una excepción al eje sino su primera
instancia**.

**Fuera del corte por decisión explícita:** **#567** (¿debe sobrevivir el CLI `audit:gdpr:erase`?) queda como
decisión abierta del usuario, con su propio hilo. **Ninguna historia lo presupone ni lo resuelve
implícitamente.**

## Epic 1: Garantías de borrado ejecutables (GDPR hardening)

Cuando una persona ejerce su derecho de supresión, **ninguna referencia suya sobrevive en ninguna tabla
persistida**; y el repositorio pierde la capacidad de crear una referencia nueva **sin dueño de borrado
declarado y verificado**. Cada historia instala un **eje de garantía completo** —declaración de propiedad,
mecanismo de ejecución, control capaz de fallar— y ninguna depende de una historia posterior.

**Definición de hecho de la épica — aplica a TODA historia de esta épica, incluidas las que se abran después,
y por eso no se repite en cada una** (enumerar un número dejaba fuera de la cláusula a la historia añadida más
tarde, que es justo la que menos ojos ha tenido)**:** ninguna
llega a `done` sin **pase adversarial registrado** por alguien distinto del autor, declarando **dónde** quedó
el registro (PR, hilo de review o artefacto de historia). Un pase que no encuentra nada cuenta, y también se
declara (NFR10).

**Precondición de toda historia marcada con «Decisión abierta».** Su **primera tarea obligatoria** es
seleccionar y justificar la alternativa. **Ninguna implementación puede comenzar antes de que esa decisión quede
registrada** en el PR o en el artefacto de la historia. La regla no es ceremonia: una decisión no registrada es
una decisión **implícita**, y una garantía que descansa en una decisión implícita es precisamente lo que esta
épica existe para eliminar. Cuando el documento acompaña la decisión de una recomendación, la primera tarea es
**confirmarla o refutarla por escrito** — no darla por buena en silencio.

**Procedencia de la evidencia.** Cada historia lleva un bloque **`Estado medido`** con los hechos que la hacen
falsable, medidos contra `main` (`471ae66f`) y no contra cuerpos de issue. Ese bloque es la **fuente única**: el
`Overview` no los reenumera y ningún AC los repite.

### Story 1.1 (G-4a): Cerrar la fuga de `PasswordResetCompleted` en los transportes Messenger persistidos

Como **sujeto de datos que ha ejercido su derecho de supresión**,
quiero que ninguna copia de mi identificador sobreviva en las tablas de Messenger,
para que el borrado que la aplicación me confirma sea cierto también fuera de las tablas de negocio.

**Eje que instala:** declaración (la regla afilada, donde vive la decisión de routing) · mecanismo (la fuga
cerrada) · control (un test que falla si el evento vuelve a un transporte persistido).
**Invariantes que consume:** SI-21/NFR1, NFR9.
**Invariantes que establece:** ninguno nuevo — es la **primera instancia** de SI-21, no una excepción a él.
**Dependencias:** ninguna. Primera del DAG por ser la única fuga viva medida.

**Estado medido (`main` @ `471ae66f`):**
[`messenger.yaml`](../../api/config/packages/messenger.yaml) enruta `PasswordResetCompleted` a `async`; el
docblock del evento declara *«the subject is the aggregate id alone»* y su agregado es `User`, luego el
`aggregateId` **es** el id del usuario; `async` persiste en `messenger_messages` (sin TTL ni poda) y `failed` es
sumidero permanente; [`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php)
no toca ninguna tabla de Messenger.

**Decisión abierta (precondición — ver la definición de hecho de la épica).** El addendum la deja abierta a
propósito (*«cerrar la fuga es barato y urgente»*, sin fijar el cómo): ① **desenrutar a `sync`** — elimina la copia
persistida de raíz; coste: el envío del correo pasa a la petición, que es donde el propio comentario de routing
ya deja los correos de seguridad con token. ② mantener `async` y purgar `messenger_messages` + `failed` en la
cadena de erasure — más caro, frágil (cuerpos serializados) y `failed` no se vacía nunca. ③ transporte con TTL
— no borra PII, la envejece. **Recomendada ①**: única que hace la garantía **estructural** en vez de
compensatoria.

**Acceptance Criteria:**

**Given** un usuario que completó un restablecimiento de contraseña,
**When** se ejerce su borrado,
**Then** ninguna fila de `messenger_messages` ni de `failed` contiene su id (FR1).

**Given** el flujo de restablecimiento,
**When** un usuario lo completa,
**Then** la notificación por correo se sigue enviando — la garantía no se compra dejando de notificar.

**Given** un cambio futuro que devuelva `PasswordResetCompleted` a un transporte persistido,
**When** corre la suite,
**Then** un control **falla**, y su mensaje enuncia la regla —*un payload «solo el id del agregado» es seguro si
y solo si el agregado no es una persona*— no solo el nombre del evento.

**Given** el comentario de routing que hoy autoriza la cola con ese razonamiento aplicado a `User`,
**When** se corrige el routing,
**Then** el comentario enuncia la regla sobre el código **actual**, sin narrar el cambio ni citar el defecto
anterior.

**Given** que ese control cubre un solo evento,
**When** se cierra la historia,
**Then** consta que generalizarlo a *«todo evento cuyo agregado sea una persona»* **es** FR9/G-4b y sigue fuera
del alcance.

### Story 1.2 (G-1a): El eje de declaración — atributo hermano, registro verificado contra el código y gate que rompe el build

Como **desarrollador que añade un contexto nuevo que toca a una persona**,
quiero que el repositorio me pare si introduzco una referencia persistida a una persona sin dueño de borrado,
para que la obligación no dependa de que alguien se acuerde.

**Eje que instala:** los tres pasos, **sin mezclarlos** — atributo hermano en `Shared/Privacy`, universo derivado
por reflexión y verificado en las dos direcciones, gate `make php.lint.person-reference`.
**Invariantes que consume:** el patrón de la casa (registro revisable + gate obligatorio), NFR8.
**Invariantes que establece:** SI-21/NFR1, SI-22/NFR2, SI-23/NFR3.
**Dependencias:** ninguna. Habilita 1.3 y 1.4 sin necesitarlas, y **aterriza con el gate en rojo por las dos
referencias que 1.3 cierra** — el estado que su segundo AC exige.

**Estado medido:** `Shared/Privacy` es una capability de tres piezas —
[atributo](../../api/src/Shared/Privacy/Domain/PersonalData.php) en `Domain/`, puerto en `Application/`,
[adaptador por reflexión](../../api/src/Shared/Privacy/Infrastructure/ReflectionPersonalDataClassifier.php) en
`Infrastructure/`— y el contrato de `#[PersonalData]` es de **tratamiento**:
[`PiiDiffSealer`](../../api/src/Shared/Audit/Infrastructure/Persistence/PiiDiffSealer.php) consume
`personalFieldsOf()` para decidir cifrado-vs-claro por columna, de donde sale NFR8. La generación total por
reflexión es **imposible**: un tipo puede nacer de un `defaults:` de ruta, de una constante de clase o de
routing YAML.

**Acceptance Criteria:**

**Given** una propiedad persistida que guarda el identificador de una persona sin dueño de borrado declarado,
**When** corre `make php.lint.person-reference`,
**Then** el gate **falla**, nombrando la propiedad y lo que falta (FR4).

**Given** un registro commiteado que ha divergido del código,
**When** corre el gate,
**Then** **falla**, derivando la comparación **del código fuente** y nunca del propio registro — validarse
contra el registro **es** el defecto de #563 (FR3, SI-23).

**Given** una entidad con una referencia a persona declarada,
**When** se sella el diff de auditoría de esa entidad,
**Then** la clave foránea sigue viajando **en claro** y las búsquedas del reconciliador de erasure siguen
funcionando — declarar una referencia no la convierte en dato cifrado (FR2, NFR8).

**Given** el registro sembrado con las referencias que la cadena de erasure ya borra hoy
([`PasswordResetToken::$userId`](../../api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php),
[`Session::$userId`](../../api/src/Iam/Session/Domain/Entity/Session.php)),
**When** corre el gate,
**Then** esas dos líneas salen **verdes** — el mecanismo se estrena sobre comportamiento correcto (FR4).
El rojo del gate proviene **solo** de las referencias sin dueño, y es el estado que FR5 y el segundo AC de la
Story 1.3 exigen.

**Given** el registro nuevo,
**When** se lee su cabecera,
**Then** declara qué **no** detecta —no juzga la clasificación, no alcanza referencias nacidas en
configuración—, siguiendo el precedente de [`.audit-resource-types`](../../api/.audit-resource-types) (FR3).

**Given** el gate,
**When** se inspecciona su cableado,
**Then** está en `php.quality` **y** en `php.quality.dry-run`, porque CI corre el *dry-run* (NFR11).

### Story 1.3 (G-1b): Cerrar las referencias huérfanas de `Membership` e `Invitation` — y el invariante ≥1 ADMIN que ocultan

Como **sujeto de datos borrado**,
quiero que mi id desaparezca también de la membresía y de la invitación que me trajeron a la organización,
para que el borrado sea completo y no deje una identidad fantasma con permisos de administrador.

**Eje que instala:** las dos referencias adquieren dueño (el gate de 1.2 pasa de rojo a verde) y la cadena de
erasure las **ejecuta**.
**Invariantes que consume:** SI-21/NFR1 (vía 1.2), SI-19/NFR6, NFR7.
**Invariantes que establece:** ninguno nuevo — pero **corrige un invariante de seguridad existente**.
**Dependencias:** Story 1.2. Ninguna posterior.

**Estado medido:** [`Membership::$userId`](../../api/src/Organization/Membership/Domain/Entity/Membership.php)
(línea 31) e [`Invitation::$invitedUserId`](../../api/src/Iam/Invitation/Domain/Entity/Invitation.php) (línea 50)
existen, y la cadena `eraseIdentitySubject → auditActorAnonymiser → auditResourceAnonymiser → purgeUserSessions`
de [`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) no las toca.
`keepsAnActiveAdminWithout`
([`ActiveAdministratorDirectory`](../../api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php),
consumido en `ChangeUserStatus:85` y `ChangeUserRoles:128`) lee `identity_user`, **no** membership. Ambas
referencias las produce la regla que obliga a cruzar contextos **por id**: no son dos bugs sino dos
manifestaciones del mismo generador, y cada contexto nuevo que toque a una persona producirá otra.

**Acceptance Criteria:**

**Given** un sujeto con membresía e invitación,
**When** se ejecuta su borrado,
**Then** ninguna de las dos conserva su id real (FR5, SI-19).

**Given** el gate de 1.2 en rojo por esas dos referencias,
**When** se cierra la historia,
**Then** pasa a verde **porque la cadena las ejecuta**, no porque se hayan declarado (SI-23).

**Given** una membresía con rol `ADMIN` cuyo usuario ya no es una identidad viva,
**When** se comprueba el invariante «≥1 ADMIN activo»,
**Then** **deja de leer satisfecho** — hoy lo lee, y esa es la consecuencia de seguridad que la referencia
huérfana oculta (FR5).

**Given** que el borrado lo orquesta un contexto distinto del que posee cada referencia,
**When** corren `make php.lint.bounded-context` y `make php.deptrac`,
**Then** siguen verdes: se cruza por identidad y puerto publicado, sin importar el `Domain/` ajeno (NFR7).

### Story 1.4-bis (G-1c): El control detective alcanza las dos referencias cross-context

Como **responsable de cumplimiento**,
quiero que un borrado incompleto de la membresía o de la invitación se **detecte** y no solo se prevenga,
para que la garantía no dependa de que todo escritor futuro pase por la cadena de erasure.

**Eje que instala:** el control **detective** sobre el eje de referencias a persona — el que dice que la fila
**se fue**, frente al gate de G-1a, que solo prueba que el borrado está **escrito**.
**Invariantes que consume:** SI-21/NFR1. **Invariantes que establece:** ninguno nuevo.
**Dependencias:** G-1b (en `main`, #616). **La decisión ③ de abajo —la que podía vaciar esta historia— está
RESUELTA a favor del código: no hay FK, la regla era lo que estaba mal.** Con ella cerrada, el alcance son las
cuatro columnas y la forma es ①; nada queda como precondición.

**CORREGIDO tras el debate Winston/Amelia + consulta externa (2026-08-01) — el corte de esta historia estaba
mal por dos, y lo hallaron los dos revisores por separado.** Decía «las dos referencias cross-context». Son
**cuatro**, y las cuatro comparten exactamente la misma clase de defecto: `membership.user_id`,
`iam_invitation.invited_user_id`, `iam_session.user_id` y `identity_password_reset_token.user_id`. Verificado
en `pg_constraint`: **ninguna tiene FK** — la base entera tiene dos, `bank_account→bank` y
`membership→organization`. Yo conté los seams que **añadió G-1b** en vez de la clase de defecto, que es
justamente el error que esta épica existe para no cometer.

Y las dos que faltaban **no se auto-sanan**: `iam_session` no tiene reaper de ningún tipo (la expiración es un
predicado de lectura, no una transición persistida — `SessionRepository:13-17`), así que la fila expirada
conserva `user_id`/`ip`/`device` para siempre; y `PruneExpiredPasswordResetTokensCommand` existe pero **no
está agendado en ninguna parte**, solo es CLI. Un control que cubra 2 de 4 y se lea como «el eje está
cerrado» es la familia SI-23 otra vez.

**Estado medido (2026-08-01, pase adversarial de #618).** El gate de G-1a es **estático**: prueba que existe
una llamada de borrado en el dueño declarado, nunca que la fila desapareciera. La obligación sigue siendo
distribuida, así que una vía de escritura futura que cree una fila con `user_id` sin pasar por
`FulfilIdentityErasure` reintroduce el residuo **en silencio**. El hermano del eje de recurso ya responde a
esto — [`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
— y su docblock enuncia el patrón: *«divergence surfaced beats divergence assumed away»*, con el veredicto
como **diferencia de dos hechos que cada lado posee**.

**Lo que la medición SÍ descarta:** el backfill. No existe entorno de producción (`.env.prod.local` ausente),
así que no hay sujetos borrados reales cuyas filas huérfanas haya que rescatar. **Esta historia es
prospectiva; no repara datos.** Si alguna vez se despliega antes de cerrarla, el backfill vuelve a la mesa.

**AVISO DE ORDEN, y por qué ① lo vuelve innecesario.** G-3b agenda `ReconcileErasedSubjectReferences` y le
pone alarma; si entra primero agenda un reconciliador que solo ve el eje de recurso. **Pero la compuerta
estaba escrita solo en el lado bloqueado:** la cabecera de G-3b (`:690`) declara *«Dependencias: ninguna —
independiente de 1.1–1.5, paralelizable»*, así que quien la coja por su fichero la mergeará primero. Una
compuerta que solo existe en un lado no es una compuerta — corregida ahora en ambos. **Bajo ① deja de
importar:** si G-3b entra antes, G-1c añade sus listers a la colección y el schedule existente los recoge sin
tocar el handler. Bajo ② el orden sí es irreversible barato. Es un argumento más a favor de ①: **hace la
compuerta innecesaria en vez de exigir que se respete.**

**Decisión ③ — TOMADA (Sergio, 2026-08-01): NO hay FK. El aislamiento por id se mantiene, y esta historia conserva sus cuatro columnas.**
El repo se contradice a sí mismo y nadie lo ha litigado. [`docs/rules/database.md`](../../docs/rules/database.md)
(`:149-152` y `:197-199`) dice, con esas palabras, que `User` es **shared kernel** y que *«An FK or a
`ManyToOne` toward it is Level 3 (allowed)»*. El docblock de
[`MembershipOrganizationForeignKeySchemaListener`](../../api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/MembershipOrganizationForeignKeySchemaListener.php)
(`:19`) afirma lo contrario: que `membership.user_id` *«deliberately has NO physical FK: it crosses a bounded
context»*. **Uno de los dos está mal.** Si gana la regla, un `ON DELETE CASCADE` en las cuatro columnas vuelve
el huérfano **inexpresable** — control preventivo estrictamente más fuerte que cualquier detective, que además
rechaza un `INSERT` con un id que nunca existió, cosa que ningún reconciliador hace. Coste: una migración,
cuatro listeners calcados del que ya existe, y reordenar las purgas **antes** del borrado de la identidad
(o los contadores del resultado se van a 0 y cambia el escenario de `erase.feature`). **Tiene fecha de
caducidad:** no hay producción y las cuatro tablas están a cero, así que era gratis hoy y caro con datos.

**Resuelta a favor del código:** el acoplamiento a nivel de esquema cruzando frontera de contexto compra
integridad referencial al precio del aislamiento sobre el que descansa el monolito modular, y la cadena de
erasure ya posee la garantía de forma explícita. **La que estaba mal era la regla**, y queda corregida en
[`docs/rules/database.md`](../../docs/rules/database.md): `User` **no** es shared kernel en este código —
es el agregado de `Iam/Identity`, así que referenciarlo es Level 2 (columna de id, sin FK), y el documento
decía lo contrario desde antes de esta épica. Con ③ cerrada, el alcance de G-1c son **las cuatro columnas** y
la opción ① con sus AC1–AC5.

**Decisión ①/② — RESUELTA en ①** (③ se resolvió en «no FK»). Dónde vive la detección: ① **ampliar el reconciliador
existente** con un puerto listador por contexto dueño (espejo de `PersonResourceReferences`), un comando y un
schedule para todos los ejes; ② **un reconciliador por contexto dueño**, tres comandos, tres schedules y una
lectura cross-context en la dirección contraria. **Winston, Amelia y una consulta externa convergieron
independientemente en ①**, y los tres por razones distintas: la dirección de dependencia ya está publicada y
② abre un ciclo `Iam/Identity ↔ Organization/Membership`; G-3b ya midió que **nada comprueba que un
`#[AsSchedule]` esté consumido**, así que ② triplica un fallo silencioso sin gate; y bajo ② el conjunto de
ejes no es enumerable contra nada, mientras que bajo ① se puede atar al registro.

**Bajo ①, dos trampas que hay que cerrar en el diseño, no descubrir después:**
- **El retorno debe llevar atribución por eje** (`array<string, list<string>>` o un VO), no una lista plana
  fusionada. Con lista plana, un test que siembre solo el eje de audit y asierte no-vacío pasa **aunque el
  lister de membership no se haya cableado nunca** — SI-23 reintroducido dentro del control que instala SI-21.
- **El puerto es un predicado por lotes, no una lista completa.** Hoy `ReconcileErasedSubjectReferences:45`
  llama `findById()` por id, que hidrata un `User` entero (con `email` y `password_hash`) por cada uno.
  `existingIds(list<string>): list<string>` se escribe una vez y los ejes lo heredan.

**Forma del puerto dentro de ① — CERRADA (Sergio, 2026-08-03), con tres enmiendas que el corte no preveía.**
La épica dejaba abierta la forma; la decide el contexto de la historia
([`g-1c-…-cross-context.md`](../implementation-artifacts/g-1c-control-detective-referencias-cross-context.md)),
que es donde vive el argumento completo. **(a)** un contrato en `Shared/Privacy/Application` recogido por
iterador etiquetado, con una implementación por contexto dueño; descartada **(b)** cuatro puertos distintos.
Lo decide un hecho medido que el corte no registra: **bajo (a) no hay ningún import cross-context**, luego son
**cero** entradas de allowlist y de deptrac frente a tres y tres bajo (b). Las enmiendas:
- **El eje de `audit_log` queda FUERA de la colección.** `Shared/Audit` no declara entidad Doctrine alguna, así
  que `AuditLog::$resourceId` **no puede ser** una clave del registro —la cabecera de
  [`.person-reference-policy`](../../api/.person-reference-policy) ya lo declara punto ciego estructural— y un
  contrato que la exigiera pondría el propio gate rojo por construcción. `PersonResourceReferences` sigue
  siendo colaborador propio. **Esto acota el «espejo de `PersonResourceReferences`» de arriba:** el patrón se
  copia, el eje no se absorbe.
- **El veredicto es un VO con clave interna `string`.** `array<PersonReferenceKey, list<string>>` no es
  expresable: las claves de array en PHP son `int|string`, y PHPStan en `level: max` rechaza el `@var`. Esto
  **estrecha la disyunción del primer bullet y de AC3**, que admitían array o VO.
- **Una sola identidad por eje:** el lister declara la clave del registro que cubre y el gate compara en las dos
  direcciones. Nunca emparejamiento por similitud de nombres, y la etiqueta del CLI se **deriva** de la clave.

**Acceptance Criteria:**

**AC1 — Detecta el huérfano, con el veredicto repartido como el precedente.**
**Given** una fila de cualquiera de las **cuatro** columnas del alcance cuyo id de persona no resuelve a una
identidad viva,
**When** corre el control detective,
**Then** la **reporta**, y el veredicto sale de la diferencia de dos hechos —el contexto dueño lista sus
referencias, `Iam/Identity` resuelve cuáles ya no son sujetos vivos—, sin que ninguno lea la tabla del otro.

**AC2 — El conjunto de fuentes es DEMOSTRABLEMENTE completo (la recomendación de Winston, y la condición
bajo la que ① merece la pena).**
**Given** el registro `api/.person-reference-policy`, que ya enumera toda referencia a persona con su dueño,
**When** corre la suite,
**Then** un gate falla si **alguna línea `person ::` salvo `User::$id` carece de un lister cableado** en el
control detective.
*La exclusión es `User::$id` y nada más*, porque esa columna **es** la identidad contra la que se resuelve el
veredicto: no es una referencia que alguien deba listar, es el lado que responde. Excluir por *fichero dueño*
—«cuyo dueño no sea `EraseIdentitySubject`»— parece lo mismo y no lo es: ese fichero es también el dueño de
`PasswordResetToken::$userId`, que **sí** está en las cuatro columnas del alcance, de modo que la redacción
por dueño sacaba del gate a una cuarta parte de su propio alcance y volvía a dejar compile-clean justo el
olvido que el AC existe para impedir. Contra el registro de hoy, la exclusión correcta deja exactamente las
cuatro.
*Por qué es criterio de aceptación y no mejora:* sin él, olvidar el quinto contexto es **compile-clean**, y
habríamos construido un control detective que necesita su propio control detective. El parser ya existe
(`api/tests/Support/PersonReferences.php`), así que el gate es barato. **La prueba de que hace falta es esta
misma historia: su corte enumeró a mano y se dejó dos de cuatro.**

**AC3 — El veredicto lleva atribución por eje (la trampa que ① introduce).**
**Given** el control cubriendo varios ejes,
**When** devuelve su resultado,
**Then** es por eje (`array<string, list<string>>` o un VO — **fijado en el VO** por la decisión de forma de
arriba), **no una lista plana fusionada**, y los tests asertan la clave.
*Por qué:* con lista plana, un test que siembre solo el eje de `audit_log` y asierta no-vacío **pasa aunque el
lister de membership no se haya cableado nunca** — SI-23 reintroducido dentro del control que instala SI-21.

**AC4 — No reporta un borrado correcto, y el test no puede pasar en vacío.**
**Given** un borrado completo,
**When** corre el control,
**Then** no reporta nada — y un test siembra la divergencia **de verdad** (afirmando que la fila sembrada
existe) antes de asertar, porque el precedente inmediato de este eje fue un test que no la creaba y pasaba.

**AC5 — Solo lee, y no hidrata.**
**Given** el control,
**When** se inspecciona,
**Then** el puerto de existencia es un **predicado por lotes** (`existingIds(list<string>): list<string>`), no
el `findById()` por id de hoy, que hidrata un `User` entero con `email` y `password_hash` por cada uno; y
**Then** **solo lee**: reparar es un acto deliberado de operador a través del caso de uso de erasure, no algo
que un chequeo agendado le haga por su cuenta a una tabla de cumplimiento.

### Story 1.4 (G-2): Ids de persona fuera de `audit_log.metadata`

Como **responsable de cumplimiento**,
quiero que la prohibición de crosswalk deje de depender de un comentario,
para poder afirmar ante un regulador que ninguna fila de auditoría re-liga un pseudónimo con la persona.

**Eje que instala:** la prohibición D4 pasa de **prosa** a **mecanismo**.
**Invariantes que consume:** D4/NFR4, SI-21/NFR1. *(FR6 y la fila del addendum nombran los mismos dos ejes,
D4 y SI-21; el addendum omitía D4 y se corrigió el 2026-08-03 — omitirlo en la historia que existe para
convertir D4 en mecanismo era el error.)*
**Dependencias: ninguna, ni previa ni posterior.** La dependencia de la Story 1.2 era condicional a elegir la
vía del gate; cerrada DA-1 a favor del redactor, no existe. El DAG del addendum la dibujaba como incondicional
y se corrigió con esta historia.

**Estado medido (del corte; resuelto):**
[`EraseIdentitySubject.php:56`](../../api/src/Iam/Identity/Application/EraseIdentitySubject.php) escribía
`'subject_user_id' => $userId` en el `metadata` de la fila `GDPR_SUBJECT_ERASED`, y **ningún anonimizador toca
`metadata`**. *Encuadre corregido:* lo que impedía el crosswalk no era un comentario sino la guarda 409
`self-erasure-forbidden`; la fuga era real de todos modos, porque el id crudo estaba ahí con independencia del
actor y ninguna mutación del conjunto cerrado entra en el JSON.

**Decisión TOMADA (Sergio, 2026-08-03), ya no es precondición abierta.** El fork del addendum —redactor **o**
gate de claves— se resolvió a favor del **redactor**, y al medir la cadena su forma concreta resultó ser **A2**:
el sujeto viaja como **recurso** de la entrada y `metadata` se queda con conteos, de modo que el
`AuditResourceAnonymiser` que `FulfilIdentityErasure` ya ejecutaba reescribe `resource_id` en la misma
transacción. Sin redactor, sin statement nuevo, sin cuarta política de mutación y sin tabla de mapeo. El gate
declarativo queda como **alternativa descartada** con condición de reapertura escrita: un **segundo** camino de
escritura que meta un id de persona en `metadata`. Argumento completo en el artefacto de la historia.

**Acceptance Criteria:**

**Given** un sujeto borrado,
**When** se inspecciona `audit_log`,
**Then** ninguna fila conserva su id real en `metadata` (FR6, NFR4).

**Given** ese mismo borrado,
**When** se consulta la fila `GDPR_SUBJECT_ERASED`,
**Then** sigue siendo evidencia útil de que el borrado ocurrió — la garantía no se compra destruyendo la
evidencia.

**Given** una escritura futura que vuelva a poner un id de persona en `metadata`,
**When** corre la suite,
**Then** un control **falla**; y si ese control es un gate, está en `php.quality` y en `php.quality.dry-run`
(NFR11).

### Story 1.5 (G-3 ①): Segundo testigo del registro — que el check de vigencia deje de autosatisfacerse

Como **desarrollador que confía en un gate verde**,
quiero que el check de vigencia del registro no pueda satisfacerse con la propia declaración que verifica,
para que un verde signifique algo.

**Eje que instala:** el **segundo testigo** que SI-22 exige sobre la única entrada manual del registro.
**Invariantes que consume:** SI-22/NFR2, SI-23/NFR3.
**Dependencias:** ninguna — independiente de 1.1–1.4, paralelizable.

**Estado medido:** `theRegistryDeclaresNoTypeThatNothingWrites()`
([`PersonResourceErasureGateTest`](../../api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php))
acepta el literal `'User'` **en cualquier punto de `src`**; ese literal aparece **una sola vez**, en
`FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE` — el fichero que la propia entrada del registro señala, de modo
que el check lo satisface el consumidor y no un escritor. Además **nada en `api/src` escribe hoy una fila con
`resource_type = 'User'`**: el gate está deliberadamente por delante de su productor.

**Decisión abierta (precondición — ver la definición de hecho de la épica):** qué constituye el segundo testigo.
Si se define como «existe un escritor de producción», la historia nace bloqueada por funcionalidad inexistente;
la alternativa a evaluar es un testigo de **fixture o escenario Behat**, distinto de la declaración pero sin
exigir productor. **El análisis arquitectónico no la resuelve por deducción, y por eso no se toma en este
documento:** la resuelve la historia, por escrito, antes de escribir código.

**Acceptance Criteria:**

**Given** el check de vigencia tal como está hoy,
**When** se ejecuta sobre el tipo `User`,
**Then** una prueba demuestra que **lee verde por construcción**, y esa demostración **precede** a la corrección
(FR7, NFR3).

**Given** la definición de testigo elegida,
**When** corre el check,
**Then** queda satisfecho por algo que **no** es el fichero de erasure que el registro nombra (FR7, SI-22).

**Given** que ese testigo desaparece,
**When** corre el gate,
**Then** **falla**.

**Given** la definición elegida,
**When** se documenta,
**Then** consta por qué es evidencia legítima aquí, qué sigue sin cubrir, y que no deja la historia bloqueada
tras funcionalidad inexistente.

### Story 1.6 (G-3 ②): El control detective del eje de recursos se ejecuta, falla de forma observable y alerta

Como **responsable de cumplimiento**,
quiero que el control que detecta un borrado incompleto se ejecute solo y avise cuando encuentre divergencia,
para que un borrado omitido no espere a que alguien recuerde lanzar un comando.

**Eje que instala:** **agendado + fallo observable + alertado en la misma entrega** — lo que SI-21 exige cuando
se admite un control agendado en lugar de un gate de build.
**Invariantes que consume:** SI-21/NFR1, NFR7.
**Dependencias:** ninguna técnica, pero **NO mergees antes de G-1c si ésta elige la opción ②**: G-3b agenda
`ReconcileErasedSubjectReferences`, y bajo ② agendaría uno que solo ve el eje de recurso sin que nada obligue
a revisarlo. Bajo ① el orden es indiferente. Ver el aviso de orden en G-1c.

**Estado medido:**
[`ReconcileErasedSubjectReferences`](../../api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php)
solo tiene CLI y no aparece en ningún schedule; el listener de acceso emite en `kernel.terminate`, **después**
del commit del erase. Los dos schedules del repo viven bajo `Shared/*`
([`audit_maintenance`](../../api/src/Shared/Audit/Infrastructure/Messenger/Maintenance/AuditLogMaintenanceSchedule.php),
[`maintenance`](../../api/src/Shared/Event/Infrastructure/Messenger/Maintenance/HandledDomainEventMaintenanceSchedule.php))
y sus transportes se consumen en `compose.yaml:129` y `compose.prod.yaml:220`. El hermano **ya agendado**
—`ReconcileSubjectErasuresHandler`, que reconcilia el **crypto-shredding**, un control distinto— vive en
`Shared` porque su puerto `SubjectErasureReconciler::unreconciledScopes()` no necesita conocimiento de dominio,
mientras que el nuestro **inyecta `UserRepository`**.

**Decisión abierta (precondición — ver la definición de hecho de la épica):** dónde vive el schedule. Mudar el
control a `Shared/Audit` haría que `Shared` aprendiera semántica de `Iam`, lo que `php.lint.bounded-context` y
`php.deptrac` deben rechazar; que `Iam` estrene el suyo cuesta un nombre de transporte en esas dos líneas de
Compose. **El análisis arquitectónico no la resuelve por deducción, y por eso no se toma en este documento:** la
resuelve la historia, por escrito, antes de escribir código.

**Acceptance Criteria:**

**Given** un borrado que dejó una referencia sin des-identificar,
**When** transcurre el intervalo del control,
**Then** se ejecuta **sin intervención humana** y la divergencia queda registrada (FR8).

**Given** una divergencia detectada,
**When** se consulta la monitorización,
**Then** es visible ahí, no solo en la salida de un comando (FR8).

**Given** la entrega de la historia,
**When** se revisa su alcance,
**Then** agendado, fallo observable y alertado llegan **juntos**, y consta por qué esta propiedad no es
estáticamente decidible (SI-21/NFR1).

**Given** la ubicación elegida para el schedule,
**When** corren `make php.lint.bounded-context` y `make php.deptrac`,
**Then** siguen verdes (NFR7).

### Story 1.7 (G-5): Ids de persona fuera del `event_store` — la fuga permanente que G-4a no alcanza

Como **sujeto de datos borrado**,
quiero que mi identificador tampoco sobreviva en el log de eventos de negocio,
para que el borrado deje de ser cierto solo en las tablas que alguien se acordó de mirar.

**Eje que instala:** declaración (qué eventos denotan persona) · mecanismo (**a decidir**) · control.
**Invariantes que consume:** SI-21/NFR1, D4/NFR4 (prohibición de crosswalk).
**Dependencias:** ninguna técnica. **Bloqueada por una decisión del usuario**, no por código.

**Estado medido:** ver FR10. El inventario está ahí y no se reenumera.

**Decisión de rango ADR — de estrategia de persistencia, luego del usuario
([`../../CLAUDE.md`](../../CLAUDE.md) → *Per-aggregate persistence strategy*): TOMADA (2026-08-01) y registrada
en D12 de [`../../docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md).**
El log pasa a ser *append-only con un conjunto cerrado de mutaciones sancionadas* —hoy una: el borrado GDPR—,
igual que su hermano `audit_log`: un **único `UPDATE`** reescribe el identificador del sujeto con un UUID
aleatorio nuevo acuñado en el borrado, **en la columna y en las claves de `payload`**, dentro de la transacción
que `FulfilIdentityErasure` ya posee. **Los dos ejes se cierran en la misma entrega.**

*Corregido tras medir: este bloque afirmaba que «la única vía viable identificada» era que el `aggregate_id`
naciera como **sustituto derivado** por sujeto. Esa vía está **vetada por el mismo D4** que veta el crosswalk —
prohíbe la tabla de mapeo **y la derivación determinista**, con esas palabras. Y dos premisas más cedieron al
medirlas: la `UNIQUE` de stream **no restringe nada hoy** (`tenant_id` se escribe siempre `NULL` y Postgres usa
`NULLS DISTINCT`), y **ninguna consulta de `src/` lee `aggregate_id` de vuelta** — el índice de agregado no sirve
a ningún consumidor de producción. La columna era mucho menos intocable de lo que el corte supuso.*

**Encuadre correcto del problema, porque «el crypto-shredding no aplica» se malinterpreta:** el crypto-shredding
aplica a **secretos** y no aplica a una **clave indexada**, así que esto **no es un problema criptográfico sino
de modelado de identidad**. La pregunta real no es *«cómo ciframos el `aggregate_id`»* sino *«¿debe el UUID real
de una persona ser la identidad permanente de su stream de eventos?»*. Formulada así se ve por qué es material
de ADR y no una tarea: la respuesta cambia el **modelo**, no la infraestructura.

**Dato que acota el debate:** aquí **no hay Event Sourcing** — ningún agregado se rehidrata de eventos (`User`
es entidad Doctrine); `event_store` es log de negocio + fuente de proyecciones. Sin replay de agregados **no hay
coartada** para conservar el UUID real; pero **sí** hay replay de proyecciones, así que el id no puede anularse
sin más. *Alternativa a evaluar y hoy no descartada:* borrado/anulación selectiva con reproyección, si se acepta
que el log deje de ser estrictamente append-only para esta clase de fila.

**Por qué no entró en G-4a:** G-4a cierra una fuga que vive **segundos** en una cola de trabajo (y en la cola
muerta a la que esa cola desemboca); esta vive **para siempre** en un log permanente. Bloquear el arreglo barato tras el caro
habría sido un error, y meterlos en la misma PR convertiría una corrección auditable en una refactorización del
backbone de eventos.

**Consecuencia de gobierno — no negociable:** mientras esta historia no cierre, **la épica GDPR-hardening no
puede declararse completada**, y ninguna historia puede afirmar *«ninguna copia del identificador sobrevive»*.
La afirmación admisible es *«el transporte persistido ya no retiene el identificador del sujeto»*. La diferencia
es falsable con un `SELECT aggregate_id FROM event_store` después de una erasure.
