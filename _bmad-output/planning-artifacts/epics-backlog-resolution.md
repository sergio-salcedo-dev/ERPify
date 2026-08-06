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

**Issues:** #435 #436 #505 #602 **+ el residual S1** registrado en `PRODUCTION_SECURITY_CHECKLIST.md` §7
**Toca:** `Iam/Identity`, `Iam/Invitation`
**Concepto común:** **#602 y el residual S1 son el mismo hueco visto dos veces** — un atacante con sesión
puede cortar las aristas de recuperación. S1 se midió el 2026-08-05: la sesión revela el email (`GET /me`),
cinco peticiones agotan `password_recovery_per_email` (5/hora) y su agotamiento es **silencioso por
contrato**, así que el dueño ve un 202 y ningún correo. Cerrarlo de verdad exige una vía de recuperación que
el poseedor de la sesión no pueda gastar. #435/#436 son la ruta caliente de auth (datos corruptos → 500;
canonicalización de email sin NFC).

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
2. **BR-4** — absorbe el residual S1, que hoy no tiene dueño en ningún issue.
3. **BR-6** — un gate que no corre invalida la confianza en todos los demás; barato y sin producción.
4. **BR-1**, **BR-3**, **BR-5**, luego **BR-7** y **BR-8**.

## Criterios de cierre de la épica

- Cada lote entra como **un PR**, con su rama `fix/…`/`chore/…` y su worktree.
- Un issue solo se cierra con **evidencia medida contra el código** en el comentario de cierre — nunca por
  leer su título. Es lo que produjo los 11 cierres del barrido inicial y las 4 correcciones.
- Los tres grupos reclasificados **no cuentan** para el cierre de esta épica.
- Al cerrar: barrer el backlog otra vez contra `main`. Si la lección de arriba es cierta, esta misma épica
  habrá dejado issues obsoletos.
