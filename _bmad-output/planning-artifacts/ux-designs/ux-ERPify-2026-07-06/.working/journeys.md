# Journeys como tests narrativos — experiencia de acceso ERPify

> **Método (Sergio):** un journey NO es documentación funcional, spec ni wireframe. Es una **prueba de que el
> modelo (E6/E6.1/E7) resiste el contacto con la realidad**. Cada uno responde a una sola pregunta: *¿puede
> esta persona recorrer el modelo sin contradicciones?* Si un journey obliga a un estado nuevo → el modelo
> tenía una carencia. Si todos fluyen sin excepciones → el modelo de identidad es estable.

## Personas (perfiles reales de una empresa de construcción)

| Persona | Contexto | Dispositivo |
|---|---|---|
| **Marta** — jefa de obra | En obra, desplazándose, cobertura irregular | Móvil |
| **Rubén** — administrativo | Oficina, uso intensivo del ERP | Desktop |
| **Elena** — gerente | Entre oficina y móvil | Ambos |
| **David** — encargado/subcontratista | Acceso ocasional | Móvil |

---

## J1 · «Entrar a trabajar por primera vez» (ancla) — Marta, móvil, en obra

Domingo por la noche, en la caravana de la obra, cobertura de 3G intermitente. Marta **no quiere "crear una
cuenta"**: quiere empezar a trabajar mañana. Abre el email, toca el enlace.

1. `Invitation=SENT` + token válido → **Accept invitation**. Su rol (**EDITOR**) ya está decidido; ella no lo
   ve ni lo elige. Diseño obliga a resolver: **completable con una sola mano** (campos grandes, un solo campo
   de contraseña visible con toggle, teclado no tapa el botón).
2. Define contraseña → submit. **Pierde cobertura justo después de enviar.** Feedback: el botón entra en estado
   *enviando* y **no** se queda colgado sin explicación; si la red cae, mensaje *"sin conexión, reintenta"* con
   el formulario **intacto** (no pierde lo tecleado).
3. Reintenta al recuperar señal. Dos ramas, **ambas fluyen**:
   - su submit anterior **sí llegó**: el token ya es `ACCEPTED` → reabrir enlace = **muro de enlace no válido**,
     pero ese muro ofrece **«Iniciar sesión»** → entra con la contraseña que puso → trabaja.
   - su submit **no llegó**: token sigue `SENT` → ve la pantalla de aceptar otra vez → define contraseña → entra.

**Clímax:** cae **dentro del ERP, ya operativa como EDITOR**, sin ningún paso administrativo intermedio.
**Veredicto:** ✅ el modelo resiste. **Descubrimiento D-a:** el muro de enlace no válido debe ofrecer siempre
una acción genérica **«Iniciar sesión»** (además de *solicitar nueva / contactar admin*) — rescata el caso
cobertura-perdida/reapertura **sin romper la opacidad** (la acción se ofrece siempre, no revela el estado).

## J2 · «Vuelta de vacaciones» — Rubén, desktop, oficina

No recuerda la contraseña. Falla **3 intentos**.

1. `Auth: Unlocked → LockedUntil(T)`. Muro **post-identidad**: *"demasiados intentos, prueba de nuevo en unos
   minutos o recupera tu acceso"*.
2. Forgot-password: escribe su email → **siempre** el mismo mensaje neutro (pre-identidad, E6.1). Recibe email.
3. Reset (token válido, un solo uso) → nueva contraseña. Al entrar, un momento de **confirmación no dramática**:
   > *"Tu contraseña se ha actualizado. Por seguridad, hemos cerrado las demás sesiones abiertas."*

**Clímax:** el muro de bloqueo temporal + la explicación de que se cerraron sus otras sesiones.
**Veredicto:** ✅ resiste. **Descubrimiento D-b:** un reset con éxito **limpia `LockedUntil`** (la recuperación
puentea el bloqueo de autenticación — el lock es sobre *intentos de contraseña*, no sobre la identidad).

## J3 · «El enlace muerto» — David, móvil

Abre la invitación tres semanas tarde. `Invitation=EXPIRED`.
→ **Muro de enlace no válido**, idéntico a cualquier otro token no elegible: *"Este enlace ya no es válido"* +
*solicitar nueva invitación*. Nunca dice *caducado*.
**Clímax:** la opacidad en acción. **Veredicto:** ✅ resiste; valida E6.1 tal cual. Sin cambios.

## J4 · «La cuenta en pausa» — Elena, gerente (el más importante en lo arquitectónico)

El admin la ha suspendido (`Identity=SUSPENDED`). Intenta entrar con credenciales **correctas**.

```
Login  →  respuesta NEUTRA (pre-identidad, indistinguible)
       →  credenciales válidas → identidad PROBADA
       →  consulta Identity Lifecycle → SUSPENDED
       →  NO se crea sesión → Muro "acceso suspendido · contacta con tu administrador" (post-identidad)
```

**Clímax:** autentica con éxito y *entonces* recibe el muro específico — un atacante no autenticado no distingue
nada. **Veredicto:** ✅ resiste, y demuestra que la anti-enumeración es **transversal**, no una regla del login.
**Descubrimiento D-c:** `Session.Active` se crea **solo** para `Identity=ACTIVE`. Un `SUSPENDED`/`DEACTIVATED`
puede *probar identidad* (credenciales válidas) pero **no recibe sesión**: solo el muro post-identidad. Afina la
frontera *autenticado* (demostró quién es) ≠ *admitido* (se le concede sesión).

## J5 · «Sergio invita y revoca» (lado-contrato, sin UI de backoffice)

Contrato, no pantalla. Solo la costura `Acción → Evento → Cambio de estado → Email → Superficie pública`:

| Acción admin | Evento | Estado | Email | Superficie pública que recibe |
|---|---|---|---|---|
| Invitar a Marta (rol EDITOR) | InvitationCreated/Sent | `Invitation: CREATED→SENT` | *"Te han invitado a ERPify"* | Accept invitation |
| Reenviar | InvitationResent | `SENT` (nuevo token, invalida anterior) | reenvío | Accept invitation (token nuevo) / muro (token viejo) |
| Revocar invitación no aceptada | InvitationRevoked | `SENT→REVOKED` | — (opcional aviso) | Muro de enlace no válido |
| Suspender a Elena | UserSuspended | `Identity: ACTIVE→SUSPENDED` (invalida sesiones) | opcional | Muro "suspendido" post-identidad |

**Veredicto:** ✅ suficiente como contrato; el backoffice se diseña en su propio slice.

## J6 · «Cambiar contraseña desde Mi cuenta» (extension point — confluencia de 3 máquinas)

Rubén, ya dentro, cambia su contraseña desde *Mi cuenta > Seguridad*.

```
Session=Active (actual)  →  password changed
   →  Authentication: credencial actualizada
   →  Session: invalida las DEMÁS sesiones, la ACTUAL sobrevive
   →  Identity: sin cambios
   →  email de notificación "tu contraseña ha cambiado"
```

**Clímax:** confluyen Session + Authentication + Identity sin colisión; la sesión actual no se autoexpulsa.
**Veredicto:** ✅ resiste. Se **diseña el modelo mental** ahora (E7), pantalla plena = extension point.

## V1 · Escenario de validación (no journey completo) — reabrir invitación ya aceptada

`Invitation=ACCEPTED` + reabrir el mismo enlace → **exactamente** el muro de cualquier token no válido:
*"Este enlace ya no es válido"* (+ «Iniciar sesión», D-a). Nunca revela *ya usado / caducado / revocado*.
**Veredicto:** ✅ prueba la opacidad de tokens.

---

## Veredicto global de la prueba narrativa

**El modelo de identidad (cuatro máquinas + dos invariantes) RESISTE los 6 journeys + V1 sin introducir ningún
estado nuevo ni excepción.** La prueba solo aflora **tres reglas de proyección** (comportamiento, no estados):

- **D-a** — el muro de enlace no válido ofrece siempre «Iniciar sesión» (rescata reapertura/cobertura perdida
  sin romper opacidad).
- **D-b** — un reset con éxito limpia `LockedUntil`.
- **D-c** — `Session.Active` solo se crea para `Identity=ACTIVE`; SUSPENDED/DEACTIVATED prueban identidad pero
  no reciben sesión.

Modelo de identidad **estable** sobre el que construir el resto de ERPify.
