# Retrospectiva — Épica *GDPR-hardening: garantías de borrado ejecutables*

- **Fecha:** 2026-08-04
- **Alcance:** 1 épica, 8 historias (G-4a, G-1a, G-1b, G-1c, G-2, G-3a, G-3b, G-5), ventana 2026-07-29 → 2026-08-04
- **Estado:** 8/8 historias `done`; el marcador de épica se cierra en el mismo PR que esta retro
- **Facilitación:** solo-dev + IA, lentes analíticas reales — el estilo fijado en la retro de users-admin, no party-mode ficticio
- **Principio rector:** la épica existía para convertir garantías de borrado de **prosa** en **mecanismo con control capaz de fallar**. Esta retro se mide con la misma vara: qué control quedó, qué punto ciego quedó declarado, y dónde el propio equipo escribió una garantía que no daba.

## Resumen de entrega

| Historia | PR(s) | Qué entregó |
|---|---|---|
| G-4a fuga en transportes Messenger | #613 | Evento desenrutado + notificación post-commit best-effort tras la revocación; registro `api/.persistent-transport-policy` + gate `php.lint.persistent-transport` cubriendo las **seis** formas que `SendersLocator` resuelve |
| G-1a eje de declaración · G-1b referencias huérfanas | #616 · #618 · #620 | `#[PersonSubjectReference]` + `api/.person-reference-policy` + gate `php.lint.person-reference` (universo = **toda** columna `Types::GUID`, sin filtro de nombre); `Membership::$userId` e `Invitation::$invitedUserId` adquieren dueño y el gate pasa a verde **porque la cadena las ejecuta** |
| G-3a segundo testigo del registro de recursos | #621 · #622 | `AuditResourceTypeRegistry` + `AuditWitnessScenario`: un check de staleness verde pasa a significar algo |
| G-1c control detective cross-context | #631 · #634 | Predicado de existencia por lotes + contrato compartido + cuatro listers: el reconciliador alcanza las cuatro columnas |
| G-3b agendado observable | #635 | `IdentityMaintenanceSchedule` + alarma que cuenta y falla; gate `php.lint.schedule-consumption` en **ambas** direcciones |
| G-2 ids fuera de `audit_log.metadata` | #636 · #639 | El sujeto viaja como **recurso** de la entrada y `metadata` se queda con conteos — sin redactor, sin statement nuevo |
| G-5 ids fuera del `event_store` | #640 | `EventStoreSubjectAnonymiser`: reescritura **por valor** y en **ambos ejes** (`aggregate_id` y `payload`), acotada al sujeto |

Adyacente en la ventana: #606/#607 (addendum + afinado de SI-21), #609 (corte de la épica), #614 (cierre de las seis decisiones abiertas), #615 (claves sprint↔fichero), #617 (hook de colisión de worktree), #632/#633 (`/deps-update`).

| Métrica | Baseline (fin users-admin) | Fin épica | Δ |
|---|---|---|---|
| PHPUnit | 2058 | 2212 *(medido en G-3b, 2026-08-04; G-5 añadió más y no se re-midió aquí)* | ~+154 |
| Behat (escenarios) | 362 | 383 | +21 |
| Gates `php.lint.*` | 6 | **9** | +3 |
| Registros declarativos en `api/` | 3 | **5** | +2 |
| Migraciones de esquema | — | **0** | ninguna historia necesitó una |
| Reverts | — | 0 | — |
| Incidentes en producción | — | 0 | (no existe entorno de producción) |

## Qué fue bien

- **El pase adversarial fue la definición de hecho de la épica, y la cobró ocho de ocho veces.** Ninguno volvió vacío: G-4a **7 hallazgos sobre el contrato + 17 sobre la implementación**; G-1a **20 incorporados y 3 rechazados por medición**; G-1b 15 sobre el contrato; el pase de implementación de G-1a/G-1b sacó **1 GRAVE, 2 ALTOS y varios MEDIOS**; G-1c **1 blocker + 8 should-fix + 8 nits**; G-3b cuatro decisiones, una de ellas que **el schedule no podía tickear nunca**. El item 8 de la retro anterior —«ninguna historia de seguridad/GDPR/audit llega a `done` sin pase adversarial registrado»— es la acción que más rentó de todas las que se han cerrado.
- **El patrón de la casa escaló sin estrenar principio.** Registro declarativo + gate obligatorio pasó de 6 a 9 instancias. Cada registro nuevo llegó con su **cabecera de puntos ciegos**, con la frase que es el contrato con el lector: *un build verde prueba lo que hay bajo la línea, y nada de lo que hay encima*. Ese hábito es el entregable transferible de la épica, más que cualquiera de los gates.
- **Decisión antes que código, sin excepción.** Las seis decisiones abiertas del corte se cerraron y registraron **antes** de escribir (#614): ①b en G-4a, D1/D2/D3 en G-1a, DA-1→A2 en G-2. El hallazgo A-17 de G-1a —marcar `ready-for-dev` con decisiones abiertas entrega la historia a un implementador que decidirá por accidente— se aplicó como regla en el resto de la épica.
- **Falsificación como disciplina de primera clase.** Ningún gate se dio por bueno sin verlo rojo: G-3b enumera **siete sondas**, cada una con su rojo y su conteo; G-4a falsificó la reintroducción de la línea de routing por dos vías; G-2 provocó **un rojo por cada una de las tres aserciones** del testigo. Y los bytes se restauraron **copiando**, nunca con `git checkout --`, que se llevaría las ediciones sin commitear.
- **La medición ganó al consenso, repetidamente.** G-5: tres voces (ChatGPT + Winston + Amelia) repitieron que D9 reserva `metadata` para «actor» — falso, reserva `correlation_id`/`causation_id`, y el propio docblock del autor repetía la premisa falsa. El `UNIQUE` de stream que dos docblocks invocaban como garantía **no impone nada** (`tenant_id` siempre `NULL`, `NULLS DISTINCT`), verificado contra `pg_indexes` y no contra el fichero de migración.
- **Disciplina de alcance, con criterio pre-declarado.** La Tarea 6b de G-4a (`NULLS NOT DISTINCT`) salió del PR por el criterio que A-7 había fijado **por adelantado**: *enumera los caminos afectados; si aparece más de uno, sácalo*. Aparecieron 16 de 20. Y ④ (poda de `failed`) salió porque su propio proponente la retiró tras medirla.
- **Derivar en vez de enumerar.** El inventario de eventos con id de persona se equivocó **dos veces** (6→8 eventos). G-5 cerró el asunto con un `WHERE` por **valor**, que alcanza todo evento presente y futuro sin que ningún productor tenga que acordarse de nada.

## Qué costó

- **El test vacuo es el modo de fallo titular de esta épica, y recurrió.** G-1b: un `INSERT … SELECT … FROM organization LIMIT 1` insertaba **cero filas** (la BD de test se migra y nunca se provisiona), así que la membresía fantasma no existía y las dos aserciones ya eran ciertas sin ella — verde, sin probar nada. G-5: el `UPDATE` corría **sobre cero filas**, AC1/AC2 no estaban probados y AC3 era infalsificable; y el canario `17 → 18` se presentó como confirmación cuando **+1 es también lo que cuesta un `UPDATE` que no casa nada**. Lo grave no es el defecto: es que **G-1a había escrito el anti-patrón** («un AC que no puede fallar») y su propio autor lo cometió después de leerlo. La contramedida que sí funcionó: **afirmar que la siembra afectó a N filas antes de asertar la ausencia**.
- **Dos entregas mergearon antes de que su pase adversarial existiera en forma empujada.** #616 mergeó y los hallazgos llegaron en #618; el mismo patrón se repitió con #620. El registro del pase tuvo que apuntar al PR de seguimiento — decir lo contrario habría hecho que el propio registro señalara un sitio donde nunca estuvo. Es honesto, y es también la prueba de que el gate de proceso llegaba tarde.
- **Los hechos del propio artefacto se pudrieron dentro de la misma épica.** G-4a afirmaba que `CompletePasswordResetTest` «no existe — hueco medido»: existía desde #491. «15 de 18 publicadores» era erróneo (16 de 20). El registro afirmaba **en seis sitios** que dos referencias eran «las únicas sin FK física»; medido contra el esquema vivo, la BD tiene **dos** FK y **ninguna** apunta a `identity_user` — la frase enseñaba justo la lección equivocada (*«FK ⇒ seguro»*). Es la cabecera de la retro anterior recurriendo: **las notas de planificación se pudren más rápido que el código**.
- **El `Dev Agent Record` de G-1a y G-1b está vacío.** `Completion Notes List` y `File List` son encabezados sin contenido. Son justo las dos historias que se entregaron juntas y las que más pases adversariales acumularon: el registro que faltó es el de la entrega más compleja.
- **`make bmad.status.audit` no es un control, y esta épica lo midió.** G-2 mergeó con su marcador en `in-progress` y el audit dijo *«matches origin/main»*. El marcador de épica que esta retro cierra es exactamente el que el comando nunca movió — y la tercera vez que se documenta el mismo falso positivo.
- **Un `#[AsSchedule]` puede compilar, registrarse y desplegarse muerto.** La review de G-3b encontró que el schedule diario **no podía dispararse jamás**: sin `->stateful()`, `from` vive en memoria del proceso, y los consumidores mueren cada hora con `--time-limit=3600`, así que el próximo tick queda siempre ~23 h más allá de un proceso que vive 1 h. **Preexistente en forma** para los tres schedules, pero ésta era la entrega que lo convertía en una afirmación de cumplimiento. El gate nuevo prueba que el *nombre* está cableado y **declara explícitamente que no prueba entrega**.
- **Impuesto de la máquina, no del código:** OOM (137) en `php.quality`, `php.behat`, `phpmd` y `rector` con varios stacks vivos a la vez. Un 137 no es un hallazgo; costó ciclos aprenderlo dos veces.

## Insights

1. **Un control verde prueba lo que dice su cabecera, y nada por encima de la línea.** Institucionalizar el bloque de puntos ciegos —y que el gate falle, no que se salte, cuando su fuente no está— es lo que impide que «build verde» se lea como una garantía que nadie escribió.
2. **En este dominio el test vacuo domina, porque casi todo control afirma una AUSENCIA.** Un test que asierta «no queda ninguna fila» pasa perfectamente cuando el setup no sembró nada. La única defensa barata es afirmar la siembra antes de afirmar la ausencia — y vale la pena convertirlo en costumbre, no en anécdota.
3. **La convergencia no es verificación.** Tres analistas de acuerdo es una señal; el pase adversarial es el control. Se cumplió en G-4a, G-1a y G-5 — y en G-5 los tres compartían una premisa falsa que sólo cayó midiendo.
4. **Preventivo y detective son dos controles, no dos redacciones del mismo.** El gate prueba que el borrado está **escrito**; sólo un reconciliador prueba que la fila **se fue**. Cuatro de las ocho historias existen por esa distinción, y el hueco que queda (`event_store`) está declarado con su razón medida, no olvidado.
5. **Derivar gana a enumerar siempre que el universo pueda crecer.** El filtro por nombre (`*user*_id`) habría dejado fuera 12 de 16 columnas; la enumeración de eventos falló dos veces. El universo completo es más caro de escribir una vez y deja de tener punto ciego.

## Continuidad con la retro previa (users-admin, 2026-07-21)

- **Item 8 → CERRADO y rentabilizado.** La regla quedó en `CLAUDE.md` → *Security review* → **Process**, y esta épica la cumplió en las ocho historias. Más aún: **la épica existe porque ese pase encontró lo que la convergencia no** — #545 y #546 nacieron del pase retro-ajustado a U-5b y son G-1b y G-4a.
- **#545 (membership huérfana) → CERRADO** por G-1b. **#546 (nada ejecuta «ningún payload persistido lleva PII») → es precisamente lo que el gate de G-4a ejecuta ahora.**
- **El registro `action_items` quedó en `[]`** y siguió vacío: los items de users-admin se resolvieron o se reclasificaron midiendo contra `main`, ninguno se acumuló. El principio «resolver, no acumular» aguantó una épica entera.
- **La lección de la «deuda fantasma» se aplicó:** la Tarea 8 de G-5 describía un trabajo ya resuelto en el merge-base, y se detectó verificando contra `main` en vez de contra la foto del `baseline_commit`.
- **Drift de estado de artefactos: mejoró pero no desapareció.** El artefacto de G-5 llegó a la review diciendo «implementación pendiente» con cinco tareas en `[x]` y `Status: review`.

## Readiness

- **Funcional:** los cuatro ejes de referencia a persona (identidad, rastro de auditoría, `audit_log.metadata`, `event_store`) tienen borrado escrito y control capaz de fallar. 0 migraciones, 0 reverts, 0 incidentes.
- **Seguridad:** ocho pases adversariales registrados, cada uno declarando su alcance y **lo que no cubre**. Lo que los revisores atacaron y no pudieron romper también quedó escrito, para que el silencio no se lea como omisión.
- **Huecos declarados, no descubiertos por accidente** (ya en `deferred-work.md` — no re-descubrir): el eje `event_store` **no tiene control detective**, con su razón medida (sin flag `resource_erased` el reconciliador reportaría cada borrado correcto como divergencia, para siempre) y su trigger; el `UPDATE` del borrado hace **scan secuencial dentro de la transacción del erase** (gratis hoy, nota de tiempo de lock a escala); la firma `anonymise(string, string)` permutable; y el «conjunto cerrado de mutaciones» de D12 es **prosa sin gate**, simétrico con `audit_log`.
- **Fuera por decisión:** G-4b (eje Messenger completo), bloqueada por el ownership de referencias nacidas en configuración — metadatos arquitectónicos, no GDPR.
- **Veredicto:** la épica hizo lo que decía. El riesgo residual no está en lo que dejó abierto —está nombrado y con trigger— sino en el modo de fallo que se repitió dos veces dentro de ella: **un control que no se ha visto rojo no es un control**, y dos veces se creyó uno que no podía serlo.

## Épica siguiente

**No hay ninguna definida** — misma posición que al cerrar users-admin. Lo que está en vuelo fuera de épica: #642 (vista de cuenta y contraseña propia del usuario autenticado), mergeado el 2026-08-04 sin clave en `sprint-status`.

## Action items

> Triaje en *resoluble ya* vs *decisión propia*, con el principio de la retro anterior: **resolver, no acumular**. Lo que ya está en `deferred-work.md` con su trigger **no** se duplica aquí.

### Resoluble ya

1. **Afirmar la siembra antes de afirmar la ausencia** — convertir en costumbre revisable lo que arregló los dos tests vacuos (G-1b, G-5): todo testigo que asierta «no queda ninguna fila» afirma primero que su `INSERT` afectó a N filas. Candidato a viñeta en `docs/rules/testing.md`. — *API / proceso*
2. **Rellenar el `Dev Agent Record` de G-1a y G-1b** o declarar explícitamente que el registro vive en los cuerpos de #616/#618/#620. Hoy son encabezados vacíos en la entrega más compleja de la épica. — *artefactos*
3. **`->stateful()` para los tres schedules, o declarar por qué no.** G-3b lo resolvió para el suyo; `scheduler_maintenance` y `scheduler_audit_maintenance` siguen con la misma forma (`1 day` contra procesos de 1 h). Es preexistente, está medido, y ahora se sabe leer. — *API*

### Decisión propia

4. **El gate de proceso llegó tarde dos veces (#616→#618, y su repetición en #620).** Decidir qué lo mueve antes del merge: ¿el pase adversarial es requisito de *abrir* el PR, o basta con que su registro apunte al PR que lo aplica? Hoy la regla dice «registrado y declarado dónde», y eso admite el orden que se dio.
5. **`bmad.status.audit` da falsos positivos por tercera vez** (casa claves contra asuntos de commit; no vio G-2 mergeada en `in-progress`, ni esta épica entera con 8/8 en `done`). Decidir: medir contra el código/PR en vez de contra el asunto, o retirar el comando y dejar de leerlo como control.
6. **El «conjunto cerrado de mutaciones sancionadas» de D12 y su gemelo de `audit_log`.** Está diferido con trigger (*la primera propuesta de una segunda mutación*), pero el ADR sigue prometiendo «cerrado» mientras `git grep` es el único control. Decidir si la promesa se ablanda o el conjunto se cierra de verdad.
