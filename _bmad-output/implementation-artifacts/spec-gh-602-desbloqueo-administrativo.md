---
story: br-4d-602-desbloqueo-administrativo
title: "#602 — la palanca de recuperación: desbloqueo administrativo autorizado"
status: done
---

# #602 — tercera arista: `users.unlock`

> Rama: `feat/iam-admin-unlock-m5zp` · Worktree: `.claude/worktrees/iam-admin-unlock-m5zp` · Base: `origin/main`
> Mitad de una sesión de diseño de dos PRs (IA externa + Winston + Amelia). La otra mitad — detección/notificación
> (`NotifyLockedIdentities`) — corre en paralelo en `fix/iam-lockout-observability-6w1p`, otro worktree.

## El hueco

#602 documentó que el lockout persistido por identidad (`User::recordFailedAttempt`, diez intentos, `PT15M`,
`api/src/Iam/Identity/Domain/Entity/User.php`) tiene exactamente dos aristas de recuperación — login correcto,
reset de contraseña completado — y ambas son gastables por un atacante que sólo conoce el email del objetivo: le
basta con seguir fallando el login para mantener la cuenta bloqueada indefinidamente. No existía ninguna palanca
que un administrador pudiera accionar en su lugar.

## Lo construido

- **Permiso `users.unlock`** (`PermissionCatalog`, `StaticAuthorizationPolicy::EXPLICIT_GRANTS`), ADMIN-only —
  `users` ya está en `TIER_OPT_OUT`, así que ningún tier lo concede por accidente.
- **`UnlockUserAccount`** (`Application/`) envuelve `User::clearLockout()` — ya existente, idempotente, ya
  reportando si mutó. La guarda de auto-desbloqueo (`refuseSelfUnlock`) copia literalmente el patrón de
  `FulfilIdentityErasure::refuseSelfErasure()`: lee el actor de confianza (`ActorContextFactory`, nunca el
  cuerpo de la petición), compara `strcasecmp` (RFC 4122 es case-insensitive), y refusa **antes** de que se abra
  la transacción — ningún registro se toca ni se audita.
- **Auditoría siempre escrita**, mute o no: a diferencia de `ChangeUserRoles` (que omite su fila en un no-op
  porque no cambió nada que valga la pena reafirmar), aquí la fila registra que la palanca **se invocó** — por
  quién, contra quién, y si la cuenta estaba realmente bloqueada — y ese hecho no depende del resultado. Acción
  `ACCOUNT_UNLOCKED_BY_ADMIN`, `security`, clasificada `ordinary` en `.audit-evidence-actions`.
- **`POST /backoffice/users/{id}/unlock`** (`UserUnlockController`), 200 con `AccountUnlockResource{id, email,
  unlocked}` — `unlocked` es el propio booleano de `clearLockout()`, nunca una afirmación fija de éxito.
- **Behat**: `api/features/backoffice/users/unlock.feature` — genuino desbloqueo, no-op reportado sin mutación,
  401/403 por rol, 409 auto-desbloqueo (dos casos: mismo caso y case-flip), 400 id malformado, 404 id
  desconocido.
- **PHPUnit**: `UnlockUserAccountTest` — incluye el caso explícito que este documento exige: `actorId ===
  targetId` se rechaza, con su propia prueba (`testAnActorCannotUnlockTheirOwnIdentity`,
  `testSelfUnlockIsRefusedEvenUnderADifferentUuidCasing`), más el actor `system` fuera de petición nunca lo
  dispara.

## Fuera de alcance, deliberadamente

- **El residual de administrador único.** Una instalación con un solo ADMIN no tiene a quién invocar esta
  palanca si ese administrador es el que queda bloqueado. #602 sigue abierto en parte por esto — no se intenta
  ningún mecanismo de auto-recuperación de emergencia aquí.
- **La mitad de detección/notificación** (`NotifyLockedIdentities`) — PR hermana, otro worktree.
- **La condición de carrera del `LoginAttemptRegistrar::recordFailure()`** — conocida, diferida aparte.

## Gates

Todos en corrida fresca desde el worktree, con su exit code impreso:

| Gate | Resultado |
|---|---|
| `make php.stan` | 0 — No errors |
| `make php.unit` (suite completa) | 0 — 3172 tests, 14033 assertions, 2 skipped (preexistentes) |
| `make php.behat c='features/backoffice/users/'` | 0 — 83 escenarios, 679 pasos |
| `make php.behat c='features/backoffice/identity/'` | 0 — 106 escenarios, 1110 pasos (sin regresión) |
| `make php.quality` | 0 — deptrac, PHPMD, audit-evidence, audit-resource, bounded-context, event-bus, todos limpios |

## Adversarial pass

Ejecutado por un subagente fresco (sin contexto de esta sesión, sólo el diff y el árbol), en solo lectura, contra
nueve preguntas hostiles concretas: orden real del guard de auto-desbloqueo, bypass del permiso vía otra ruta,
suplantación del actor auditado o de la comparación de UUID, veracidad del `unlocked` idempotente bajo fallo de
transacción, atomicidad e integridad de la fila de auditoría, interacción con `IdentityStatus`/el invariante
≥1-admin, y calidad de las pruebas Behat/PHPUnit. Corrió `make php.stan`, `make php.unit --filter
UnlockUserAccountTest` y `make php.behat` sobre `unlock.feature` en fresco como línea base (todo verde) antes de
leer el resto.

**Sin hallazgos sustantivos en ninguna de las nueve preguntas.** Lo verificado, punto por punto:

1. **Orden del guard.** `Uuid::ensure($userId)` → `refuseSelfUnlock($userId)` → recién entonces
   `transactionManager->transactional(...)`. La ruta de auto-desbloqueo nunca llega a `findByIdForUpdate`.
2. **Bypass de permiso.** Un solo punto de cableado (`#[IsGranted('users.unlock')]` a nivel de clase); `users`
   sigue en `TIER_OPT_OUT`, así que el comodín `ADMIN => ['*']` de la tier nunca alcanza `users.unlock` — sólo
   la fila explícita lo concede.
3. **Suplantación del actor / de la comparación de UUID.** `SecurityActorContextFactory` sólo lee el token de
   seguridad, nunca la petición. Verificado contra el propio `Uuid::isValid()` de `symfony/uid`: sólo admite la
   forma canónica de 36 caracteres (insensible a mayúsculas), así que la única variación posible entre dos
   grafías del mismo UUID es el case — que `strcasecmp` cubre. Mismo perfil de riesgo que
   `FulfilIdentityErasure::refuseSelfErasure()`, no uno nuevo.
4. **Veracidad de `unlocked`.** `TransactionManager::transactional()` construye el resultado dentro del cierre,
   pero sólo lo entrega tras el `flush`+`commit`: cualquier excepción (incluida la de `save()` o la del `INSERT`
   de auditoría) revierte toda la transacción y el resultado nunca escapa al controlador.
5. **Auditoría.** Se escribe síncronamente en la misma conexión que envuelve el `EntityManager`, dentro de la
   transacción todavía abierta — mutación y fila de auditoría comparten atomicidad. `resource_type` reutiliza
   `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, el patrón ya establecido. `metadata` sólo lleva `{unlocked:
   bool}` — nada de PII.
6. **Estado / invariante de administrador.** `clearLockout()` sólo toca `failedAttempts`/`lockedUntil`, nunca
   `IdentityStatus` ni roles — no hay interacción con el invariante ≥1-ADMIN.
7-8. **Calidad Behat/PHPUnit.** Cada escenario siembra su propia precondición por SQL explícito; el escenario de
   auto-desbloqueo re-consulta tras el 409 para probar que el estado sembrado sobrevive intacto — no sólo el
   código de estado. Las pruebas unitarias cubren el camino feliz, la idempotencia con `save()` omitido, la
   auditoría en ambos casos, el rechazo de auto-desbloqueo con aserciones de cero-toque antes de la transacción,
   el bypass por case-flip, el éxito contra una identidad distinta, el actor `system` fuera de petición, el
   "no encontrado" y el id malformado.

**Un hallazgo MENOR, corregido en esta misma rama:** `api/.audit-evidence-actions:66` — la nueva línea llevaba un
espacio de más antes de `=>` (columna 32 en vez de 31, rompiendo la alineación visual del resto del fichero). El
propio parser del gate (`AuditEvidenceActions::classification()`, `trim` + `explode`) es insensible a ese
espaciado, así que era puramente cosmético — corregido igualando la columna al resto de filas y verificado con
`make php.lint.audit-evidence` (11 aserciones, verde).

**Clases del checklist de seguridad que no aplican**, declaradas en vez de omitidas en silencio: no hay
inyección (Doctrine parametrizado en todo el recorrido), no hay subida de ficheros, no hay CORS/CSRF/Mercure
tocado, no hay migración (columnas ya existentes), no hay handler de Messenger (`clearLockout()` no emite
evento de dominio). Autenticación/autorización, validación de entrada (`Uuid::ensure` antes de cualquier
lectura), asignación masiva (`AccountUnlockResource` es de sólo lectura, sin setters expuestos) y secretos
(ningún dato sensible en la respuesta ni en la fila de auditoría) sí aplican y están cubiertos arriba.
