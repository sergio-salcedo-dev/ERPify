# Retrospectiva transversal — el arco auth/RBAC/identidad (AF → E3 → RM → II)

- **Fecha:** 2026-07-20
- **Alcance:** 4 épicas encadenadas, 22 stories, 2026-07-02 → 2026-07-14
  - **AF** — auth-foundation (4 stories) · `docs/adr/auth-rbac-subsystem.md` D1–D7 · SI-1…SI-5
  - **E3** — acceso restringido + auto-auditado + atribución real (3 stories)
  - **RM** — modelo RBAC transversal `Permission=(resource,action)` (6 stories) · SI-6…SI-9
  - **II** — identity/invitation lifecycle (9 stories) · D1–D12
- **Estado:** las 4 épicas `done` · 22/22 stories `done`
- **Facilitación:** solo-dev + IA, estilo de la retro de E1 (lentes analíticas reales, no party-mode ficticio). Se retrospectivan juntas **porque son un solo arco arquitectónico**: cada una extiende a la anterior sin revocarla, y las lecciones que importan son las que cruzan sus fronteras.

## Resumen de entrega

| Épica | Stories | PRs | Ventana |
|---|---|---|---|
| AF | 4 | #419, #429, #433, #434 | 07-02 → 07-03 |
| E3 | 3 | #439, #442, #450, #452, #453 | 07-04 → 07-06 |
| RM | 6 | #454 (ADR), #456, #457, #459, #463, #464, #465 | 07-06 → 07-09 |
| II | 9 | #458, #460, #466, #467, #469, #475, #490, #491, #493, #494, #496 | 07-07 → 07-14 |

| Métrica del arco | Valor |
|---|---|
| PHPUnit | 1461 (pre-AF) → **~1850** (fin II) |
| Behat | 197 escenarios → **286** |
| PWA unit | ~950 → **1049** |
| Reverts | **0** en todo el arco |
| Migraciones en E3 | **0** (`db.diff` vacío — la costura de actor no tocó esquema) |
| Issues abiertos heredados | #435, #436, #437, #438, #462, #468, #470, #474, #495, #505 |
| Incidente en producción | **1** — `/me` tumbó el back-office 2 días (#488) |

## Lección cabecera

**El arco empezó documentando controles y terminó cableándolos. Ese giro es su mayor logro, y llegó tarde por accidente, no por diseño.**

En la primera mitad del arco, tres "controles" resultaron ser prosa:

- **SI-3 «Gate de producción» nunca existió.** E3.3 (#453) fue **docs-only**: 8 ficheros, todos documentación salvo un docblock. El propio spec lo admite — *«el "gate" siempre fue la reserva del ADR/checklist + un comentario stale»*. El gating real lo aportaron AF-1.3 (default-deny → 401) y E3.1 (`#[IsGranted]` → 403).
- **La protección CSRF del login se documentó siendo falsa.** Durante ~3 horas, el ADR D1, `PRODUCTION_SECURITY_CHECKLIST.md` y `docs/rules/security.md` afirmaron que el requisito `application/json` de `json_login` impedía un POST cross-site. Es falso: `json_login` atiende por el default `_format: json` **de la ruta**, no por `Content-Type`, así que un `<form enctype="text/plain">` llega como *simple request* sin preflight. AF-1.4 lo demostró y reinstauró el `Origin` check. D1 quedó enmendado dos veces en tres horas. Del cuerpo del PR: *«el contrato de seguridad estaba documentado como protegiendo algo que no protegía»*.
- **SI-2 descansa sobre un gate inexistente.** El addendum afirma que Symfony Security vive solo en `Infrastructure/` *«porque lo impone deptrac»*. No lo impone: `Vendor.Symfony` es un colector único. El invariante se cumplió por disciplina, no por el mecanismo declarado (#438, abierto).

En la segunda mitad, el patrón se invierte y aparecen **controles ejecutables**: los tripwires de RM-6 (`token_get_all` sobre el fuente del voter, reflexión sobre la firma del puerto, property test name-agnostic), `ConstantTimeAuthBranchingContractTest`, `CaddyfileAccessLogRedactionGateTest`, `SessionAdmissionGateRegistrationTest`, `MarkerStatusMapContractTest`.

**Y hay prueba de que la diferencia importa.** RM-6 estaba calificada de "opcional" en el plan. Diez días después, U-1 (#503) necesitaba que `/me` **enumerase** permisos — presión directa sobre un puerto que por diseño es una pregunta cerrada. El puerto congelado forzó la salida correcta: **composición, no modificación** (`PermissionCatalog` + `PermissionResolver`). Verificado con `git log --follow`: `StaticAuthorizationPolicy` cambió en cuatro PRs posteriores y **cada diff es filas de datos, cero algoritmo**.

Un documento no habría resistido esa presión. Un test sí. **Calificar RM-6 de "opcional" fue la evaluación menos acertada de toda la planificación del arco.**

## Qué fue bien

- **La costura de actor (SI-1) es la pieza más rentable del proyecto.** Sobrevivió a cuatro épicas, a un cambio de mecanismo de atribución (anónimo → auth real) y a una promoción de bounded context completa, **con cero migraciones**. E3 la reubicó de `Shared/Audit` a Identity manteniendo puerto, firma y único consumidor intactos. Hoy vive en `Iam/Identity/Infrastructure/Security/SecurityActorContextFactory.php` con la misma forma.
- **El contrato de error RFC 9457 (SI-4) aguantó sin una sola excepción en 22 stories.** 401 de login, 401 de default-deny, 403 cross-site, 403 de autorización, 400 de token inválido, 503 de store caído — todos por el pipeline. `ProblemDetailsFactory`, `ExceptionResponder` y `docs/api-error-contract.md` intactos en todo AF y E3.
- **El orden de merge safe-first funcionó tres veces seguidas, con cero reverts.** RM lo ejecutó exactamente como se planificó y RM-5 (swap semánticamente equivalente en audit, antes de abrir superficie nueva) destapó una **sobre-concesión** — el RED falló por partida doble: `AUDIT_READER` denegado *y* `VIEWER` **concedido**, es decir un viewer genérico leyendo el trail regulatorio. Encontrarlo en un swap equivalente costó una fracción de lo que habría costado en un tightening.
- **Meter el habilitador transversal temprano se pagó solo.** II añadió a propósito una dependencia que el DAG del addendum no tenía (II-4 → II-7) para que el accept acuñara la primera `Session` **sobre el agregado**, no una sesión nativa desechable. Resultado: II-4 reutilizó `Security::login` + `SessionMintingSuccessListener` ya existentes, y pudo asertar *«exactamente 1 fila `Session`»* — un conteo exacto que caza el doble-minting silencioso.
- **La reversión del plan en la Decisión H de II-8 fue la mejor decisión de seguridad del arco.** El épico mandaba emails de seguridad **async** vía Messenger. Se invirtió a **síncrono best-effort** porque rutear `SendEmailMessage` serializa el objeto `Email` entero —con el token en claro dentro del enlace— en la tabla del transporte Doctrine **y en la cola `failed`**, en reposo, sin TTL y sin single-use. Eso anulaba el «hasheado en reposo» de D6/SI-13. La razón original para quererlo async (que un fallo del mailer no rompa la respuesta uniforme) ya la cubre el `try/catch`; el transporte solo añadía la fuga.
- **PHPMD como fuerza de diseño, sin supresiones.** El techo `CouplingBetweenObjects` extrajo `RevokeSessionsBestEffort`, ubicó el rate-limit en los controllers, consolidó tests de frontera TTL. Y en II-8 la duplicación de Sonar (5.1% > 3%) forzó extraer `BulletproofEmailChrome`. Un linter empujando hacia mejor diseño en vez de hacia `@SuppressWarnings`.

## Qué costó

- **El review adversarial es donde ocurre la seguridad real de este proyecto — y la story más sensible se envió sin él.** El patrón es inequívoco:
  - AF-1.4 entero (el `Origin` check reinstaurado) nació de una **segunda** pasada adversarial sobre AF-1.2.
  - II-1 necesitó **tres** pasadas. La segunda cazó un consumidor muerto enmascarado por un `|| echo`; la tercera descubrió que el usuario E2E había sido **escalado silenciosamente de `AUDIT_READER` a `ADMIN`** — lo que habría puesto en verde cualquier E2E futuro de autorización negativa.
  - II-6 pasada 1 encontró un **DoS de lockout construido por el propio lockout**; la pasada 2 encontró un **oráculo de enumeración por código de estado** (bajo fallo de BD: email existe → 500, desconocido → 401) que un suelo constant-time **no cierra**.
  - RM-6: el Blind Hunter demostró **empíricamente** que el gate `subject:` era evadible renombrando el parámetro en el override.
  - **II-8 no tiene ninguna sección «Review Findings».** Es la story más grande y más sensible del arco: 7 workstreams, el primer lock pesimista del repo, erasure GDPR, rate limits, suelo de timing. Su «8/8 ACs, todas las casillas `[x]`» es **autocertificado**, con solo el gate de SonarCloud como testigo externo. Es además la única implementada por un modelo distinto.
- **"Safe-first" ordena por acoplamiento, no por riesgo de integración — y eso costó el único incidente de producción.** II-7 se colocó en el lote «aditivo de bajo riesgo» siendo la story con **más superficie PWA nueva** (`/me`, hidratación, interceptor 401). El bug: `/me` responde con el envelope `{data:{…}}` estándar y el cliente validaba el objeto plano → todo `/me` rechazado → `AuthProvider` sin sesión → `RequireAuth` redirigiendo **el back-office entero** a `/login`, **dos días**. Detrás había un segundo fallo latente: la suite E2E compartía **una** sesión entre specs y II-7 las hizo revocables individualmente, así que un solo sign-out envenenaba el resto.
- **Los mecanismos internos de Symfony estuvieron a punto de hacer implementaciones silenciosamente incorrectas, cuatro veces.** `AuthenticatorManager` re-envuelve toda excepción no-`CustomUserMessageAccountStatusException` en `BadCredentialsException` (explotado a propósito para INVITED, evitado para los muros); `LoginFailureEvent` **nunca dispara en este firewall** porque el authenticator llama al failure handler antes de despacharlo (la Tarea 5 de II-6 especificaba un listener imposible); `access_control` default-deny da **403, no 401**, porque `ExceptionResponder` mapea antes que el firewall; `json_login` no valida CSRF. **Ninguno se dedujo: los cuatro se descubrieron verificando en vivo.**
- **La autoridad de las decisiones se erosionó en los bordes.** La Decisión D de II-8 estaba marcada «CERRADA» por consenso unánime de tres lentes y el implementador **la reabrió** en dev. Las desviaciones resultaron ser *mejores* que lo decidido (DQL + `HINT_REFRESH` en vez de `findByEmailForUpdate`, verificado contra Postgres real) — pero el proceso dice que una decisión cerrada no se reabre en dev. Caso hermano: en II-5 se rechazó el lock pesimista argumentando *«sería el PRIMER pessimistic-lock del repo y NO verificable por el harness»* cuando **II-4 había mergeado con `FOR UPDATE` cuatro horas antes**, y al día siguiente II-8 lo verificó contra `pg_locks`. La premisa era falsa al escribirse.
- **La topología de ramas condicionó el modelo de dominio.** II-5 eligió un agregado propio `PasswordResetToken` en vez de columnas en `User` **explícitamente para minimizar el roce del rebase con II-4**, y descartó la búsqueda por hash porque habría exigido editar `Shared/Token` en paralelo. Defendible —el rebase salió limpio y #493 mergeó 11 h después de #491— pero merece nombrarse: es una decisión de dominio tomada por razones de VCS.

## Insights

1. **Un control que no puede fallar en CI no es un control.** RM-6 validó sus gates con RED provocado (inyectar `$ignored = $subject;` → falla en el token 169; añadir `explain()` al puerto → falla la aserción de tamaño), ambos revertidos limpiamente. *Un gate que nunca has visto fallar no es un gate.*
2. **En seguridad, verifica el framework; no razones sobre él.** Los cuatro casi-fallos de Symfony y la afirmación CSRF falsa comparten causa: se dedujo comportamiento en vez de observarlo.
3. **El nombre de una abstracción puede prometer de más.** `SingleUseToken` **no fuerza single-use**: `verify()` devuelve `true` repetidamente hasta el TTL; el single-use es lifecycle del consumidor. Se difirió con un contrato explícito que II-4 e II-5 honraron cada uno a su manera (lock pesimista vs DELETE condicional). Salió bien — pero el nombre sigue mintiendo.
4. **El transporte async es superficie de almacenamiento, no solo de entrega.** La Decisión H trata la cola como lo que es: una tabla con datos en reposo, sin TTL, con una DLQ que nadie purga.
5. **Ordenar por acoplamiento ≠ ordenar por riesgo.** El lote "aditivo" necesita una segunda lente: *¿qué story cruza deployables?*
6. **La especificidad de un error es una decisión de confianza, no de UX.** `AccountSuspended` → 403 específico; `AccountDeactivated` → 403 genérico **por semántica**, no solo por anti-enumeración (D12).

## Lo que ninguna retro había registrado

- **#437 sigue abierto por un backtick.** El fix está en `main` y verificado desde #457; el cuerpo del PR escribió `` `Closes #437` `` **dentro de un code span**, así que GitHub no lo parseó y el auto-close nunca disparó — ni quedó enlace de referencia. Resultado: un issue etiquetado `bug` de *privilege bypass* figura abierto describiendo código que ya no existe. **#388** («hard release gate»: gatear `/audit/timeline` antes de prod) está igual: resuelto por RM-5, nunca cerrado.
- **El desfase de marcadores es sistémico, no anecdótico.** RM-6 figuró en `review` **11 días** después de mergear, y la épica entera "en curso"; RM-3 y RM-4 igual, reconciliadas en un commit suelto. El paso «post-merge, mueve el marcador» no está cableado en ningún sitio, y se repitió tres veces solo en RM.
- **Los artefactos de planificación se pudren y nadie los barre.** Ambos ADR y el addendum siguen etiquetados `not yet implemented` / `frozen-ready · diseño` con las épicas cerradas. `epics-identity-invitation-lifecycle.md` sigue describiendo un modelo de `Session` que **no existe** (`Active|Revoked|Expired` con `lastSeenAt`; se envió con dos estados, expiración como predicado temporal y `lastSeenAt` borrado) — el ADR se corrigió dos veces, el épico ninguna. El épico también sigue prometiendo emails async, lo contrario de lo que se envió por seguridad. Y ADR, addendum y épica de RM siguen citando `Backoffice/Identity/...`, ruta muerta desde #458.
- **La poda de specs se aplicó al revés de la regla.** `CLAUDE.md` dice borrar el spec cuando su `status:` sea `done`. Se borraron ii-6, ii-7 e ii-8 estando en `Status: review`; **siguen en el árbol ii-4 e ii-5, que sí dicen `done`** — exactamente las que la regla ataca. Y AF-1.4 **nunca tuvo fichero de spec ni figura en el desglose de la épica**: existe solo como una línea de `sprint-status.yaml` y el PR #433.
- **II-5 se marcó `done` con un tercio pendiente.** Su fichero se creó en #491 y **nunca se tocó en #493**: el título sigue diciendo «slice backend» y las ACs D1–D3 siguen sin marcar, aunque #493 las entregó.
- **La deuda solo se rastrea si tiene número.** Los ~13 ítems diferidos de II-6 e II-7 nunca se convirtieron en issues; viven en un `.md` que nadie abre al planificar. Dos bullets ya resueltos siguen ahí sin borrar (la notificación «tu contraseña ha cambiado», enviada en II-8; el copy `INVALID_RESET_LINK`, resuelto en #494), violando la regla «al resolver, borra el bullet».

## Huecos de verificación que siguen abiertos

Declarados con su razón — no son descuidos, pero conviene tenerlos a la vista juntos:

- **Ningún E2E vivo de ningún muro** (suspended, deactivated, locked, invalid-link, reset). Razón repetida en cuatro stories: *«no hay tooling para poner un usuario real en ese estado en la stack viva»*. #462 lo habilitaría.
- **Ninguna spec de Playwright cubre las pantallas de token.** El `no-referrer` se asserta contra el objeto de config, no contra una respuesta real.
- **`expose_security_errors: None` es load-bearing** para el colapso INVITED→401 y **no está fijado en `security.yaml`** — funciona porque es el default de Symfony. Una edición futura lo rompe en silencio.
- **Sin test negativo de CSRF:** con `check_header` off, el CSRF stateless se satisface por same-origin, que `AcceptInvitationOriginListener` ya impone antes. No existe input que CSRF rechace y Origin no, así que el test no puede fallar tal como está cableado.
- **«Byte-idéntico» asertado débilmente:** las ACs de indistinguibilidad verifican `status + type` en escenarios separados, sin comparar los bodies cara a cara. El código es correcto; los asserts no cierran el invariante.
- **Sin concurrencia real** para `FOR UPDATE` (se asserta la forma del lock vía `pg_locks` y el comportamiento secuencial) ni **benchmark de latencia** para el suelo constant-time — aunque el épico y su riesgo R2 siguen prometiendo el benchmark.
- **POST/PUT sin positivo EDITOR** (`write→2xx`) en `access_control.feature`: la dirección crítica está, falta la inversa, así que una regresión que **sobre-restrinja** create/update no se cazaría.
- **La PWA no está verificada ante el 403 de `*/realtime/authorize`.** `bank` y `bankAccount` pasaron de «cualquier autenticado» a exigir `*.read`; un autenticado sin tier de negocio recibe 403 en vez de `204 + cookie` → `EventSource` nunca autoriza y el realtime se rompe **en silencio**. Enmascarado hoy porque solo existe el `ADMIN` wildcard. **Trigger explícito: al provisionar el primer usuario no-ADMIN.**

## Decisiones que cedieron (y estuvo bien)

- **D3/D4 (enum `Role` + `RoleVoter` nativo) duraron ~4 días.** El YAGNI de «no crear un Voter a medida porque sería `return in_array(...)`» era correcto **en su momento**; RM-1 lo superó con `PermissionVoter` + puerto `AuthorizationPolicy` en cuanto hubo un segundo eje. `AUDIT_READER` sobrevive como el rol que concede `auditTrail.read`. Es un YAGNI bien ejecutado, no un error de juicio.
- **D2 (ubicación de `Identity` bajo `Backoffice`) cedió por diseño, no por sorpresa.** El ADR anticipó el gatillo textualmente («capacidades propias de IAM — MFA, password reset, sessions…») y advirtió que no obligaba a mantenerla ahí. II-0 la promovió a `Iam/`.
- **D8 (estado persistido `Expired`) se enmendó formalmente en II-7**, sustituido por un predicado temporal, con la condición de reversión escrita en el ADR: *«Reintroduce `Expired` only if a real transition ever materializes it»*. Así es como debe morir una decisión.

**Contraste:** las que cedieron *bien* dejaron rastro en el ADR. Las que cedieron *mal* (D5 reinterpretada en II-4 sin enmienda, D6 con su contradicción interna intacta, FR10 prometiendo async) no lo dejaron.

## Deuda arquitectónica declarada

El **core RBAC sigue en su «parking»** de `Iam/Identity/Infrastructure/Security`. II-0 lo dejó escrito sin ambigüedad: *«la ubicación es parking… no "RBAC pertenece a Identity" (es su plano ortogonal)»*. La extracción a `Access/` o `Kernel/Authorization` **no tiene issue**. Es la mayor deuda de arquitectura viva del arco y hoy solo existe como una frase en un artefacto transitorio.

## Action items vivos

Solo lo abierto. Ordenados por coste/beneficio, no por severidad:

**Higiene de tracker (minutos, cierra ruido de seguridad falso):**
- Cerrar **#437** y **#388** — resueltos y verificados, huérfanos por accidente.
- Corregir el comentario stale de `sprint-status.yaml:75-78`: lista como «endurecimiento futuro sin issue» el timing dummy-hash y `login_throttling`, **ambos implementados en #429**.

**Higiene de artefactos (barrido único):**
- Actualizar las cabeceras `Status` de `docs/adr/auth-rbac-subsystem.md` (+ su línea `Scope`, que sigue diciendo `Backoffice/Identity`), `docs/adr/identity-invitation-lifecycle.md` y los addenda.
- Reconciliar `epics-identity-invitation-lifecycle.md` con lo enviado (modelo de `Session`, emails síncronos, `UserLocked` vs `AccountLocked`) o marcarlo explícitamente como histórico.
- Corregir las rutas `Backoffice/Identity/...` en el ADR, addendum y épica de RM.
- Aplicar la regla de poda a ii-4 e ii-5 (`Status: done`) y actualizar el fichero de ii-5 para reflejar #493.

**Deuda con issue (los 10 abiertos):** #435, #436, #438, #462, #468, #470, #474, #495, #505 — más **#376**, que arrastra desde la retro de E1 y hoy es el gate duro de U-5a.

**Deuda sin issue que debería tenerlo:**
- **Extracción del core RBAC** fuera del parking de `Iam/Identity`.
- Los ~13 diferidos de II-6/II-7 que nunca se promovieron.
- El **DoS de lockout por email** contra identidades privilegiadas (mitigado por break-glass CLI; el bullet ya descarta explícitamente filtrar `locked_until` en el directorio de administradores — no re-proponer).
- El **matcher del gate de sesión** (`/api/` literal) es más estrecho que el firewall (`^/api` regex): una ruta futura montada en `/api` exacto quedaría firewall-autenticada **sin gate de sesión**. Ambos límites deberían derivar de una definición única.
- **`MembershipNotFound` mapeado a `SessionStoreUnavailable`** (503 «store-unreachable»): el marker miente y floodea Sentry ante un gap de datos permanente que un reintento no resuelve.

**Proceso (la propuesta que este arco justifica):**
- **Ninguna story de superficie de seguridad se marca `done` sin una pasada adversarial registrada.** II-8 es el contraejemplo y la evidencia de cuánto encuentran esas pasadas cuando se hacen (AF-1.4 entero, la escalada silenciosa a ADMIN de II-1, el oráculo por código de estado de II-6, la evasión del gate de RM-6).
- **Cablear el paso «post-merge, mueve el marcador»** — falló tres veces solo en RM. Es el candidato natural a hook.

## Readiness

- **Funcionalmente:** las 4 épicas entregaron y sostienen la épica de usuarios en curso. El plano de autorización está cerrado bajo modificación y lo demostró bajo presión real.
- **Postura de seguridad:** sólida en lo cableado (indistinguibilidad pre-identidad con puerto + adapter, opacidad del token en 5 caminos de muerte, redacción en logs y en Caddy, rate-limit que pliega al mismo muro en vez de a un 429 que confirmaría el selector). Débil en lo **verificado end-to-end**: ningún E2E vivo de ningún muro, ninguna spec de token.
- **Riesgo latente con trigger conocido:** el 403 de `realtime/authorize` al provisionar el primer usuario no-ADMIN. Es el único que puede morder sin aviso.
- **Veredicto:** el arco es técnicamente el trabajo más fuerte del proyecto y su documentación es la más desalineada. El código dice la verdad; los artefactos de planificación, a estas alturas, no. Antes de abrir la siguiente épica grande conviene el barrido de artefactos — no por pulcritud, sino porque **la próxima decisión se tomará leyéndolos**.
