---
baseline_commit: f2e80a9d9b5e2db4ac7a2fe5b03145bf3c5641d0
---

# Story BR-4: Endurecimiento de identidad — el grafo de recuperación

Status: done

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-4 · Issues #435 #436
> Rama: `fix/iam-identity-hardening-br4-215m` · Worktree: `.claude/worktrees/iam-identity-hardening-br4-215m` · Base: `main` @ `f2e80a9d`
> #505 está CERRADO. #602 queda **fuera** de este lote por decisión, en su propia rama y con su propia autorización.

---

## Lo que la medición refutó

**El síntoma que abría la tarea era falso, y la hipótesis que lo explicaba también.** El encargo describía «una
segunda fuente de 500» en la ruta de roles corruptos y proponía como causa la copia de `SecurityUser`
deserializada de la sesión, sin guarda alguna. Medido:

| # | Afirmación heredada | Medición |
|---|---|---|
| **M1** | Con `roles` fuera del enum, `GET /me` responde 500 pese al filtro | **Falso en aislamiento.** Responde **401 `unauthenticated`**. El 500 solo aparece encadenado tras otro escenario. Ambas medidas eran honestas: cambiaba el escenario anterior. |
| **M2** | Hay una segunda fuente de 500 en la ruta de **roles** | **Falso.** `User::roles()` con `Role::tryFrom` cerró el 500 entero. Con el conjunto de roles preservado y el valor fantasma añadido, `/me` responde **200** y descarta el fantasma. |
| **M3** | El 401 lo causa el valor desconocido | **Falso.** `ARRAY['ADMIN']` a secas —sin ningún valor fuera del enum— produce **el mismo 401**. Lo causa el **cambio de conjunto de roles**: `ContextListener::hasUserChanged()` (`api/vendor/symfony/security-http/Firewall/ContextListener.php:329-337`) compara los roles refrescados contra los que el token guarda y descarta el token si difieren. **El escenario medía la expulsión de Symfony, no el filtro.** |
| **M4** | La copia de sesión sirve roles sin guarda | **Falso, y demostrable.** `:227` refresca contra el provider **antes** de comparar, y lo que se instala en el token (`:238`) es siempre la copia refrescada. La deserializada nunca se sirve: solo es operando de comparación. |
| **M5** | La copia de sesión es inocua | **Falso — y aquí sí estaba el 500.** `:310` llama a `$originalUser->getPassword()` **sobre la copia deserializada**. `SecurityUser::getPassword()` → `HashedPassword::fromHash('')` lanza `InvalidHashedPassword`, marker-less, **500 en cada petición que lleve la cookie**. El fail-closed de `UserProvider` guarda la fila que carga, no ese operando. |
| **M6** | La columna `roles` es `jsonb` | **Falso.** Es `json`. `jsonb_array_elements_text` habría fallado; el control usa `json_array_elements_text`. |
| **M7** | El actor de la fila de auditoría será `anonymous` | **Falso para la vía elegida.** Fuera de una petición, `SecurityActorContextFactory::current()` devuelve `ActorContext::system()` (`:45`). `anonymous` solo aplicaba a auditar dentro de la petición, que es justo lo que se descartó. |
| **M8** | #555 sigue abierto, no auditar recursos-persona | **Falso.** #555 cerró el 2026-07-27 y `AuditResourceAnonymiser` / `AuditSubjectTrailErasure` reescriben `resource_id`. Aun así la fila que se escribe es **resource-less**, por el motivo de D3. |

---

## Decisiones

| # | Decisión | Argumento |
|---|---|---|
| **D1** | **Roles: filtrar el valor desconocido, nunca vaciar la lista.** | `StaticAuthorizationPolicy::permits()` es una disyunción pura de concesiones — no hay rama de denegación —, así que un valor irreconocible no concede nada y descartarlo solo puede estrechar. Rechazar la identidad convertiría un valor que no concede nada en pérdida total de autenticación, y para la instalación cuyo administrador cargue el caso retirado, en un lockout sin desbloqueo administrativo. |
| **D2** | **Credencial: fallar cerrado, en LOS DOS sitios de rehidratación.** | La asimetría con los roles es la decisión, no una incoherencia: una credencial ilegible significa que la identidad no puede probar nada. `UserProvider` cubría la fila; `SecurityUser::isEqualTo` (`EquatableInterface`) cubre la copia de sesión. Único consumidor de esa interfaz en todo el stack: `ContextListener:301` — el seam es exacto. |
| **D3** | **La fila de auditoría se escribe UNA VEZ POR CORRIDA del control, nunca en el punto de la negativa.** | Ambas negativas se reevalúan en **cada** petición autenticada: una fila ahí sería un amplificador de escritura acotado solo por el tráfico de una cuenta rota — el mismo motivo por el que `InvalidCurrentPasswordAuditListener` solo es defendible tras un throttle, salvo que aquí no hay presupuesto que anteponer. Y la ruta caliente **no puede reconocer una transición**: distinguir «acaba de corromperse» de «lleva una semana» exige recordar el estado anterior, y el único almacén disponible sería la propia traza. Resource-less y por conteo: las identidades a la deriva son exactamente los ids de persona que ese eje debería después borrar, y el hallazgo es accionable sin ninguno. |
| **D4** | **El fail-closed va en `Infrastructure/Security`, jamás en el repositorio.** | `findByEmail` lo consume también `RequestPasswordReset`, que es la vía que **repara** una fila corrupta. Verificado contra el código: ni `RequestPasswordReset::request()` ni `CompletePasswordReset` leen `passwordHash()`, así que una fila corrupta no bloquea su propia reparación. Fallar en el repositorio convertiría una corrupción recuperable en un lockout permanente. |
| **D5** | **La cláusula de identificador se conserva pese a ser inalcanzable.** | Medido: quitarla no pone rojo ningún escenario, porque `refreshUser` resuelve la recarga **por** ese identificador. Se conserva porque la igualdad es un contrato público y una que lo ignorase llamaría «la misma persona» a dos distintas en cuanto alguien resolviera la recarga por otra cosa. Queda falsificada por test unitario, no por escenario. |

---

## Falsificación — cada cláusula tiene su rojo

Medido primero el estado heredado: **con la comparación entera anulada a `return true`, solo 1 de 91 escenarios
de identidad se ponía rojo.** La cláusula de la credencial no la sostenía nada — y `CompletePasswordReset.php:42`
**se traga un revoke fallido** justificándose en que «the credential change already de-authenticates the old
sessions natively», que es exactamente esa comparación. Al implementar `EquatableInterface` pasamos a ser sus
dueños; omitir la cláusula habría sido invisible.

| Cláusula neutralizada | Rojos |
|---|---|
| Comparación entera → `true` | 2 (`session.feature` credencial + roles) |
| Sin cláusula de credencial | 1 |
| Sin cláusula de roles | 1 |
| Sin guarda de credencial ilegible | 1 |
| Sin cláusula de identificador | **0** en Behat · **1** en `SecurityUserEqualityTest` |
| Sonda de roles del control cegada | 1 |
| Sonda de credenciales del control cegada | 1 |
| Fila de auditoría no escrita | 1 |
| Una fila por hallazgo en vez de por corrida | 1 |

---

## Pase adversarial

> **Requisito de proceso:** se ejecuta y se registra **antes** de `gh pr create`. No se usan drafts.

Ejecutado el 2026-08-09 sobre `08317c5f`, en tres contextos frescos e independientes del autor, con tres lentes
ortogonales y en solo lectura: (A) ruta de autenticación y semántica de `isEqualTo` frente al comportamiento por
defecto de Symfony; (B) el control detectivesco — falso limpio, corrección SQL, fuga de datos de persona,
semántica de la fila de auditoría; (C) falsabilidad de los tests — aserciones vacuas, contaminación entre
escenarios y trampas conocidas de Behat.

**Los tres pases encontraron defectos reales, y cuatro de ellos los había introducido este mismo trabajo.**
Ninguno era visible para la checklist de seguridad ni para ningún gate. Cada uno se verificó contra el código
antes de actuar — dos afirmaciones de los pases resultaron equivocadas y se descartan más abajo.

### Corregidos en esta PR

| # | Severidad | Hallazgo | Corrección |
|---|---|---|---|
| A-1 | **GRAVE** (2 pases + medición propia) | Un elemento JSON `null` dentro de `roles` se reportaba **limpio**: `json_array_elements_text` lo expande a NULL de SQL y `NULL NOT IN (…)` es NULL, no TRUE, así que la fila caía por el mismo predicado escrito para atraparla. | `WHERE stored.value IS NULL OR …` + `COALESCE`. |
| A-2 | SERIA | Un `roles` que no es array **abortaba la lectura entera** → `INVALID`, es decir el comando decía «NO repares nada» sobre la única fila que lo necesitaba — y además cegaba la sonda de credenciales, que se lee después. | `CASE` + `CROSS JOIN LATERAL`, y `identitiesWithMalformedRoles()` como hallazgo propio. |
| A-3 | SERIA | Una identidad `ACTIVE` con `password_hash IS NULL` queda fuera **sin que nada lance**: el firewall lee null como «no puede autenticar» y da el mismo 401 uniforme que una contraseña mal escrita. El control la reportaba limpia. | `admittedIdentitiesWithoutACredential()`. |
| A-4 | SERIA | `Role::tryFrom()` lanza `TypeError` sobre un array anidado, que escapa a `isEqualTo` → **500 en la ruta de auth**: la misma clase de defecto que esta rama cierra, con una forma abierta. Medido en PHP 8.5. | `User::roles()` descarta no-strings antes del lookup. |
| A-5 | SERIA | Dos docblocks en `src/` afirmaban que `SecurityUser` **no** es `EquatableInterface` — barrí `docs/` y no `src/`. Uno de ellos está en el listener que mantiene el registro en paso con el firewall. | Ambos corregidos. |
| A-6 | SERIA | Tras cubrir la credencial en `isEqualTo`, el fail-closed de `UserProvider` se quedó con **0 tests que lo falsificaran**: toda ruta con sesión llega antes a la comparación, que también rechaza. | Escenario `@anonymous` de login — la única ruta sin copia de sesión. Medido: **0 → 1 rojo**. |
| A-7 | SERIA | El `catch` de `ChangeMyPassword` (commit heredado) **no lo cubría nada**: borrarlo dejaba la suite verde. | Test unitario con el hash plantado por reflexión. Medido: **0 → 1 rojo**. |
| A-8 | SERIA | El escenario de credencial pasaba por la vía `INVALID`: `should fail` es `assertNotSame(SUCCESS)` y el mensaje de «sin veredicto» contiene el nombre de la columna que afirmaba. | Discriminadores (`not contain "NOT a finding"` + la línea de conteo). |
| A-9 | MENOR | `array_filter(is_string(...))` en la sonda de roles degradaba en silencio lo que no supo leer — justo lo que su hermana se niega a hacer. | Lanza `uncountableResultFrom`. |
| A-10 | MENOR | Dos escenarios dejaban `roles` corrupto para el `BeforeScenario` del siguiente; uno dependía en silencio del código que el otro prueba. | Recargas finales. |
| A-11 | MENOR | El conteo total de filas de auditoría no estaba fijado (dos consultas de 1 fila admiten dos filas distintas). | Aserción del total. |
| A-12 | MENOR | Valores almacenados impresos sin `OutputFormatter::escape()`: un `<info>` guardado se imprimiría distinto de lo que hay en la fila que el operador va a reparar. | Escapados. |
| A-13 | MENOR | Cuatro comentarios y la checklist afirmaban un **scheduler que no existe**. | Dicen ahora que no lo corre nada, y por qué eso importa. |
| A-14 | MENOR | `UserProvider` se llamaba «the one boundary»: `ReauthenticateDevice` acuña token vía `Security::login()` sin pasar por él. | Corregido. |
| A-15 | MENOR | La cabecera de `api/.person-reference-policy` decía «nueve call sites» con **doce medidos ya en `main`**, y esta rama añade el primero cuyo `metadata` lleva texto leído de una tabla. | Recontado midiendo, y declarado el caso nuevo. |
| A-16 | MENOR | La constante de compatibilidad `crc32c` de Symfony no estaba documentada: adoptarla sin enseñárselo a `isEqualTo` expulsaría **todas** las sesiones, incluida la recién acuñada. | Documentada en el docblock. |

### Descartados tras medirlos

- **«El escenario de la copia de sesión prueba poco»** — falso: contra `origin/main` da 500 y aquí 401; el orden
  corromper-antes / reparar-después es load-bearing y el `SELECT` sobre la fila `ACTIVE` separa el firewall de la
  puerta de admisión.
- **«`isEqualTo` dejó de comparar `$token->getRoleNames()`»** — cierto como delta semántica, pero el pase intentó
  divergirlo y no pudo: ambas vías de acuñado siembran los nombres desde el usuario, y la copia de sesión solo se
  reescribe en respuestas donde la igualdad ya se cumplió. Queda anotado como invariante **no fijado por ningún test**.
- **`' '` y `'a-different-hash'` no se cuentan** — correcto: `HashedPassword` los acepta, así que no son credenciales
  «rechazadas». El límite está declarado en el propio comando.
- **`:known` vacío falla abierto** — inalcanzable: `Role` tiene cinco casos.

### Hallazgo lateral, reportado y NO corregido aquí

`make php.lint.schedule-consumption` casa el atributo **dentro de comentarios**: un docblock que nombraba la forma
sin argumento hizo que el gate exigiera un transporte `scheduler_default` que nadie declara. Es la misma clase de
fallo que su propia cabecera documenta para los ficheros compose. Anotado en `PRODUCTION_SECURITY_CHECKLIST.md`;
arreglar el gate es otra tarea.

---

## Gates

Todos en corrida fresca desde el worktree, con su exit code impreso:

| Gate | Resultado |
|---|---|
| `make php.stan` | 0 — No errors |
| `make php.unit` | 0 — 2474 tests |
| `make php.behat` | 0 — 425 escenarios |
| `make php.quality` | 0 — deptrac 0 violaciones |

---

## Trampa de la épica, respetada

`revoke-others` **no lleva limitador y eso es deliberado**: es la única arista que un adversario no puede gastar.
No se ha tocado, ni se ha añadido lock a `LoginAttemptRegistrar.php:51`. Nada de este lote entra en `Iam/Session`.
