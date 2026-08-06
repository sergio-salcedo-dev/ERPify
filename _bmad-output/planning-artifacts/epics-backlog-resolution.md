# ERPify — Resolución del backlog de issues — Desglose de épica

> Estado: planificado · Fecha: 2026-08-06 · Alcance: los 54 issues abiertos tras el barrido del 2026-08-06

## Por qué existe esta épica

El objetivo declarado es **cerrar el backlog de issues antes de abrir más épicas de producto**. Eso exige
primero un hecho incómodo: el backlog no describía la realidad. Un barrido midiendo cada issue **contra el
código, no contra su título** cerró **11 de 65** y corrigió otros 4 — y los 11 no eran issues viejos
olvidados, sino recientes, obsoletos por trabajo que sí se hizo.

**La lección que gobierna esta épica:** el backlog se pudre por lo que se entrega, no por el paso del tiempo.
El momento de barrer es el cierre de cada épica, no un año después. Los issues cerrados el 2026-08-06 se
concentran en dos entregas: la épica gdpr-hardening (#560, #566, #546, #563, #470, #471) y los gates RBAC
(#194, #240), más el PWA de users-admin (#340, #271) y la retirada de la superficie de imágenes (#259).

## Reclasificación previa — sin esto, la épica no cierra nunca

Tres grupos **no son trabajo de esta épica** y contarlos hace el objetivo inalcanzable por construcción:

| Grupo | Issues | Qué son en realidad |
|---|---|---|
| **Épicas disfrazadas** | #268, #266, #267, #462, #549, #373 | Cada una es una épica de producto. «Resolver los issues antes de más épicas» es contradictorio si seis de ellos *son* épicas. Reetiquetar `epic` y sacar del conteo |
| **Decisiones, no código** | #222, #295, #263, #567 | No avanzan sin una decisión del dueño del producto. Se resuelven en una conversación de media hora, no en un PR |
| **Vigilancias automatizadas** | #593, #196, #420 | Disparadores, no tareas. #593 ya tiene `make composer.check.mercure-pin` en el cron semanal; #420 es un tripwire («al tercer VO string») |

Aceptadas las tres reclasificaciones: **54 → ~38 issues en 8 lotes**.

## Los ocho lotes

Cada lote es **un PR**. Agrupan por fichero y por concepto compartido, no por etiqueta: el criterio es que un
revisor pueda leer el PR entero con un solo modelo mental en la cabeza.

### BR-1 · Vocabulario y falsabilidad de Behat

**Issues:** #313 #319 #320 #430 #590 #591 #592
**Toca:** `api/tests/Behat/Context/*`, `api/behat.dist.php`
**Concepto común:** aserciones que pasan **vacuamente**. Un paso que no puede fallar no es cobertura.
`#590` (los asserts a cero pasan cuando la cola no es un transporte en memoria) y `#591` (nada fija el orden
403-antes-de-422 — el test de prioridad que existe fija el orden de **CORS**, medido) son los dos con
consecuencia real; el resto es higiene del vocabulario.
**Riesgo:** bajo. Cero producción.

### BR-2 · Residuos del eje de referencias a persona

**Issues:** #389 #562 #565 #564
**Toca:** `api/frankenphp/Caddyfile`, `Shared/Audit/Infrastructure/Persistence`, `Iam/Identity/Application`
**Concepto común:** el id de una persona sobreviviendo a su propio borrado, por cuatro vías que la épica
gdpr-hardening no cubrió. **Es el único lote con consecuencia legal.**

- **#389** — medido: `auditUrlState.ts:95-103` escribe `actorId`/`resourceId`/`correlationId` en la URL, y el
  Caddyfile (`:22-27`) redacta **solo** `authorization` y `token`, así que el id entra en el log de acceso, un
  sumidero con retención propia y sin dueño de borrado. El docblock del propio fichero (`:46`) llama PII a ese
  dato tres líneas antes de ponerlo en la barra de direcciones. **Corrección al título del issue: el eje `ip`
  no existe**, son dos ejes, no tres. Arreglo más barato: añadirlos a la redacción del Caddyfile.
- **#565** — medido y **alcanzable**: ABBA entre el pase de actor (`WHERE actor_id = :s`) y el de recurso
  (`WHERE resource_type = :t AND resource_id = :s`), ambos en la misma transacción y en ese orden. Dos
  administradores que se hayan suspendido mutuamente producen las dos filas recíprocas. Severidad **menor de
  lo que suena**: PostgreSQL lo detecta (`40P01`), aborta uno y la cadena entera revierte — fallo ruidoso y
  reintentable, no corrupción. Arreglo: una sola sentencia con `OR`, que además funde dos barridos en uno.
- **#562** — `existingIdsAmong($ids)` sin trocear, contra un techo de 65535 parámetros ligados.
- **#564** — ventana de despliegue rodante en una migración.

### BR-3 · Auditoría y crypto — cierres del eje 3

**Issues:** #405 #409 #413 #418 #372
**Toca:** `Shared/Crypto`, `Shared/Audit`
**Concepto común:** el keystore y la DEK destruida. **#372 está a medias**: su mitad de *advisory lock* ya
está hecha (`DbalAuditLogPruner` recibe `PostgresAdvisoryLock`), así que su título debe reescribirse a lo que
queda — la retención GDPR-proof.

### BR-4 · Endurecimiento de identidad — el grafo de recuperación

**Issues:** #435 #436 #505 #602
**Toca:** `Iam/Identity`, `Iam/Invitation`, y **NO** `Iam/Session` (ver la trampa, abajo)

**Concepto común:** la ruta caliente de auth (#435 datos corruptos → 500; #436 canonicalización de email sin
NFC) y el grafo de recuperación (#505 delegación de rol; #602 sus dos aristas).

**Lo que este lote NO es, medido y corregido en #645.** Una versión anterior de este plan decía que un
atacante con sesión robada puede «estancar la recuperación self-service» y que cerrarlo exige una vía que el
poseedor de la sesión no pueda gastar. **Eso medía el verbo equivocado.** La rotación de credencial no es el
objetivo de seguridad; la **expulsión** lo es — y la expulsión ya tiene una vía que ningún presupuesto gatea:

- `POST /sessions/revoke-others` **no lleva limitador de ningún tipo** — todos los throttles del repo viven en
  `Iam/Identity` o `Iam/Invitation`, ninguno en `Iam/Session` —, solo exige una sesión viva, y ya se entrega
  en el PWA como *Active sessions*.
- La ruta del dueño a una sesión viva está intacta: la credencial sigue funcionando, una sesión robada **no
  alimenta el lockout** (`LoginAttemptRegistrar` se alcanza solo desde el handler de fallo de login, nunca
  desde el cambio de contraseña), y el lock persistido lo aplica `UserChecker::checkPostAuth`, nunca
  `SessionAdmissionGate`.
- La secuencia real es **entrar → `GET /sessions` (la fila del intruso aparece, con su etiqueta de
  dispositivo) → `revoke-others`**. La rotación retrasada es higiene *después*, contra alguien que ya no tiene
  nada.
- La carrera **no es simétrica**: los dos pueden disparar `revoke-others`, pero el dueño vuelve con la
  credencial y una cookie revocada está muerta sin forma de re-acuñarse. Cada asalto le cuesta un login.

**Lo que sí sobrevive es una composición, y es exactamente #602:** un atacante que *además* dispara el lockout
por email (10 fallos → `PT15M`, con ≥2 direcciones origen para esquivar el throttle por IP) le niega al dueño
la sesión que la expulsión necesita. Hasta que #602 cierre, lo que el producto debe es **guía de orden —
expulsa primero, rota después** — en el copy de la UI y en el correo de contraseña cambiada.

> **TRAMPA — léela antes de tocar nada.** Que `revoke-others` no lleve limitador es **deliberado y
> load-bearing**: es la única arista que un adversario no puede gastar. Ponerle un throttle «por coherencia»
> destruiría el remedio del dueño y convertiría #602 en irreparable. **No lo endurezcas.** Está anotado en
> `PRODUCTION_SECURITY_CHECKLIST.md` §7 y en el propio `RevokeOtherSessionsController`.

**Registro de la corrección:** el residual se midió mal la primera vez (2026-08-05) y lo corrigió #645 el
2026-08-06 — midiendo *qué remedio existe* en vez de *qué presupuesto se agota*. El error no fue de dato sino
de pregunta: medí el presupuesto que el atacante puede drenar sin comprobar antes si el remedio dependía de
él. Vale la pena conservarlo porque es reproducible: un residual redactado desde el mecanismo, y no desde el
objetivo de seguridad, exagera la exposición.

### BR-5 · Ciclo de vida de `iam_session`

**Issues:** #468 #474
**Concepto común:** dos ficheros, una tabla. #470 y #471 ya cerraron — la purga en el borrado la hace
`FulfilIdentityErasure` dentro de su transacción (G-1b), así que **no hace falta ningún reactor** y el issue
paraguas se quedó sin segundo hijo.

### BR-6 · Gates de arquitectura y CI

**Issues:** #250 #305 #438 #356 #589
**Concepto común:** tooling de fronteras. **#589 es el que muerde**: la mitad git-aware del gate NFR26 nunca
corre porque `/app` no es un repositorio git dentro del contenedor, así que el contrato de error se apoya en
revisión humana donde creíamos tener un gate.

### BR-7 · Listas de backoffice

**Issues:** #395 #422 #423 #424 #425 #426 #272 #273
**Concepto común:** el toolkit de recursos sobre Bank/BankAccount — filtros, guarda no-op en `update()`,
enums que fallan cerrado, realtime que re-enmascara un IBAN revelado. El lote más grande y el de menor riesgo
regulatorio.

### BR-8 · Operabilidad

**Issues:** #255 #256 #261 #525 #526 #612
**Concepto común:** lo que se nota en producción y no en los tests — backup, retención del transporte
`failed`, alarmas de escritura de auditoría, y la cota de socket del mailer (#612: un SMTP colgado bloquea una
petición 60 s).

## Orden recomendado y su argumento

1. **BR-2** — el único con consecuencia legal, los cuatro medidos y confirmados, y su arreglo más barato
   (redacción en el Caddyfile) cierra un sumidero duradero sin tocar UX.
2. **BR-4** — porque #602 es lo único que queda del residual de sesión robada tras la corrección de #645, y hasta que cierre lo que el producto debe es **guía de orden** en el copy, no código.
3. **BR-6** — un gate que no corre invalida la confianza en todos los demás; barato y sin producción.
4. **BR-1**, **BR-3**, **BR-5**, luego **BR-7** y **BR-8**.

## Criterios de cierre de la épica

- Cada lote entra como **un PR**, con su rama `fix/…`/`chore/…` y su worktree.
- Un issue solo se cierra con **evidencia medida contra el código** en el comentario de cierre — nunca por
  leer su título. Es lo que produjo los 11 cierres del barrido inicial y las 4 correcciones.
- Los tres grupos reclasificados **no cuentan** para el cierre de esta épica.
- Al cerrar: barrer el backlog otra vez contra `main`. Si la lección de arriba es cierta, esta misma épica
  habrá dejado issues obsoletos.
