# Security / Anti-enumeration Review — ERPify (acceso/identidad)

> Revisión adversarial de la pareja de espinas UX (`EXPERIENCE.md` + `DESIGN.md`, run `ux-ERPify-2026-07-06`)
> contra sus dos invariantes declarados: **(1) indistinguibilidad pre-identidad** y **(2) opacidad total del
> token**. Método: intentar *romper* los invariantes y buscar fugas. Se distingue entre (a) huecos genuinos
> que la espina debe cerrar y (b) concerns de backend correctamente diferidos al ADR de Identity/Invitation —
> pero incluso estos últimos se listan cuando la espina, al haber **elevado** el tema a invariante, debe
> *enunciar* el contrato para que el invariante sea honesto.

## Overall verdict

La espina es **fuerte en la capa que controla directamente** (copy neutro uniforme, muros opacos, muros
post-identidad, `?next=` validado con `safeInternalPath`+`safeHref` — verificado contra `LoginForm.tsx`, es
correcto). Pero **reduce un invariante transversal a una regla de microcopy**: declara que «todas las
respuestas deben ser indistinguibles para un atacante» y luego solo iguala **las cadenas de texto**, dejando
sin nombrar los canales que un atacante realmente mide — **timing, código de estado y forma de respuesta** —
y sin enunciar el contrato de **higiene del token en la URL** (Referer same-origin, historial, logs de
acceso). El resultado es un invariante que *suena* cerrado pero es enumerable por canales laterales. Nada de
esto invalida el modelo de dominio (las cuatro máquinas resisten), pero la espina debe **enunciar** estos
contratos —aunque su implementación caiga en el ADR/backend— o el invariante es deshonesto.

## Findings

- **[high] La indistinguibilidad se define solo como paridad de *copy*; timing, código de estado y forma de
  respuesta quedan fuera de alcance** (`EXPERIENCE.md` § Invariante de seguridad; § Voice and Tone tabla
  «Mensajes pre-identidad»; `.decision-log.md` E6.1). *Attack:* un atacante no lee el texto — mide latencia y
  status. (1) **Timing:** un email inexistente corta-circuita (no hashea contraseña) y responde rápido; un
  email existente con contraseña errónea ejecuta argon2/bcrypt y responde lento → enumeración de cuentas
  válidas pese al mensaje idéntico «Correo o contraseña incorrectos». Idéntico en forgot-password (¿hace
  trabajo de DB/envío solo si la cuenta existe?) y en la validación de `?token=` (token consumido → lookup +
  flag «usado»; token malformado → rechazo en parseo → tiempos distintos). (2) **Status/forma:** si login
  devuelve `401` para contraseña errónea pero `200`+redirect para la ruta SUSPENDED, o forgot-password
  devuelve `200` vs `204` según exista la cuenta, la enumeración es trivial con el copy intacto. *Fix:* la
  espina debe declarar explícitamente que la indistinguibilidad **incluye timing, código de estado HTTP y
  forma/tamaño de respuesta**, no solo el mensaje, y handoff al ADR de un requisito concreto: **ruta de tiempo
  constante** (hashear siempre una contraseña dummy aunque el usuario no exista; recorrer el mismo camino),
  **status uniforme** (p. ej. forgot-password siempre `200`/`204` invariable) y **forma de respuesta
  idéntica** en los tres casos pre-identidad {inexistente, contraseña errónea, identidad no elegible}.

- **[high] El `?token=` viaja en la URL sin contrato de higiene: fuga por Referer same-origin, historial y
  logs de acceso** (`EXPERIENCE.md` § Interaction Primitives «Token de un solo uso»; § Voice and Tone; `DESIGN.md`
  § Typography «el token del enlace nunca se renderiza»; rutas `/reset-password?token=`, `/accept-invitation?token=`).
  *Attack:* la espina protege bien el *render* (el token nunca se pinta en el DOM) pero el token sigue en
  `window.location`. El `Referrer-Policy` global es `strict-origin-when-cross-origin` (verificado en
  `next.config.ts`): bloquea la fuga **cross-origin** del path, pero **envía el path+query completos en
  peticiones same-origin** → el token cabalga en el header `Referer` hacia el túnel same-origin `/monitoring`
  (Sentry), hacia `/api/*` y hacia prefetches. Además queda en el **historial del navegador** y en los **logs
  de acceso** de Caddy/FrankenPHP (`GET /reset-password?token=SECRET`). Un token vivo (single-use pero no
  consumido, dentro de la ventana de expiración) filtrado por cualquiera de estos canales = **toma de cuenta**;
  D-b lo amplifica (quien obtiene el token no solo fija contraseña sino que **limpia el LockedUntil**). *Fix:*
  la espina debe enunciar como parte de la opacidad del token: (1) las pantallas por token fijan
  `Referrer-Policy: no-referrer`; (2) el cliente **borra el token de la URL** tras leerlo
  (`history.replaceState`) para sacarlo de historial y de subpeticiones; (3) requisito de **redacción del
  token en logs de acceso** (handoff al ADR/infra); idealmente (4) preferir token en fragmento/POST sobre
  query si el ADR lo permite.

- **[medium] Anti-doble-envío es client-side; no hay contrato de rate-limit / anti-fuerza-bruta en los
  endpoints** (`EXPERIENCE.md` § Component Patterns `{ConnectivityButton}`; § Resiliencia). *Attack:* el
  `{ConnectivityButton}` previene el *doble-clic accidental*, no a un atacante que scripta el endpoint.
  Sin rate-limit: (1) mailbombing de la víctima vía forgot-password; (2) medición de timing a escala (potencia
  el finding de timing); (3) fuerza bruta del `?token=` si su entropía no es alta. *Fix:* la espina debe
  enunciar (handoff al ADR) que forgot-password, reset y accept-invitation están **rate-limited por
  IP/cuenta**, que los tokens son de **alta entropía** (no adivinables) y que el rate-limit **no rompe la
  neutralidad** (mismo status/copy al saturar — no un «demasiadas solicitudes» que delate).

- **[medium] Semántica de revocación de sesiones confundida entre *reset* (J2) y *cambio desde Mi cuenta*
  (J6): «hemos cerrado las demás sesiones» en un flujo de reset desde no-autenticado** (`EXPERIENCE.md` § Voice
  and Tone `{SecuritySignal}`; J2; § Modelo de sesión; State Patterns máquina 4). *Attack:* J2 es recuperación
  ante **credencial comprometida** y arranca **sin sesión actual** (B3 → navega a B1). El copy «Por seguridad,
  hemos cerrado **las demás** sesiones abiertas» implica que sobrevive una — pero en reset **no hay sesión
  actual que preservar**, y si la implementación interpreta literalmente «las demás» podría **dejar viva la
  sesión del atacante**. J6 (cambio autenticado) sí debe preservar la actual. La espina **conflaciona** ambos.
  *Fix:* declarar el invariante explícito: **reset ⇒ revoca TODAS las sesiones** (incluida cualquiera del
  atacante); **cambio desde Mi cuenta ⇒ revoca todas menos la actual**. Ajustar el copy de J2 en consecuencia
  (no «las demás»).

- **[medium] Entrega del muro SUSPENDED/DEACTIVATED sin declarar que es *stateless* (sin sesión/cookie/token
  reanudable parcial)** (`EXPERIENCE.md` D-c; regla de los tres momentos; J4; Anti-patrones). *Attack:* D-c
  dice «no se crea sesión» pero no dice **cómo** llega el muro a un cliente no autenticado. Si la
  implementación deja una cookie o token intermedio «estás suspendido» para transportar el estado a la página
  del muro, o redirige a `/access-wall?state=suspended`, aparece un artefacto de auth parcial
  inspeccionable/replayable y/o el estado de la cuenta se filtra a los logs (`?state=suspended`). *Fix:* la
  espina debe enunciar que el muro post-identidad se renderiza **inline desde la propia respuesta del POST de
  login**, sin establecer ninguna sesión, cookie ni token reanudable, y sin poner el estado de cuenta en la
  URL.

- **[medium] Endpoints nuevos (accept-invitation, reset) sin contrato de CSRF ni de regeneración de sesión
  (fixation)** (`EXPERIENCE.md` `{TokenActionScreen}`; B3/B4; `arch-addendum-auth-rbac.md` PR-0 cubre CSRF solo
  en `security.yaml` del login). *Attack:* accept-invitation y reset son **POST net-nuevos** fuera del
  firewall de PR-0; hacen la transición `INVITED→ACTIVE` y **acuñan la primera sesión**. Sin regeneración del
  id de sesión en ese salto de privilegio → **session-fixation** (un id pre-fijado por el atacante sobrevive
  al login). Sin token CSRF en esos POST → CSRF. *Fix:* la espina (dueña de estas superficies nuevas) debe
  enunciar: ambos POST requieren **protección CSRF**, y una aceptación/reset con éxito **regenera el id de
  sesión** al crear la sesión ACTIVE.

- **[medium] «Reply-by-inertia» sobre emails de seguridad expone el token vivo al sistema de tickets**
  (`EXPERIENCE.md` § Contrato de emails; `DESIGN.md` `SecurityEmail`; `.decision-log.md` E13). *Attack:* la
  espina elige `soporte@`/`seguridad@` sobre `no-reply@` **precisamente para que respondan** — pero un usuario
  que responde «por inercia» a un email de reset/invitación **cita el enlace con el token** en la respuesta,
  que aterriza en un helpdesk/ticketing (a menudo sin cifrado E2E, legible por agentes o por quien phishee la
  cola de soporte) mientras el token siga sin consumir. La decisión pesó la UX pero no la exposición del
  token. *Fix:* enunciar la mitigación: los emails de acción-token (reset/invitación) llevan aviso «no
  compartas este enlace», y/o el flujo de respuesta no debe dejar un token **usable** en el hilo de tickets
  (p. ej. remitente distinto para acción-token vs notificación, o expiración corta reforzada). Como mínimo,
  reconocer el riesgo reply-cita-token junto a la decisión de remitente.

- **[medium] Comportamiento de forgot-password para identidades no-ACTIVE sin especificar** (`EXPERIENCE.md`
  § Voice and Tone «Forgot password — respuesta única»; proyección E7 «cualquiera → Forgot password»; D-b).
  *Attack:* ¿una cuenta SUSPENDED/DEACTIVATED que pide reset recibe email o no? Si el envío ocurre solo para
  ACTIVE, el *si-llega-o-no-el-email* es un canal de enumeración de estado por fuera del copy. Además, D-b
  («reset limpia LockedUntil») no dice si un SUSPENDED puede siquiera resetear ni qué pasa entonces. *Fix:*
  declarar que forgot-password es **uniforme para todo estado de identidad** (misma respuesta, mismo trabajo
  observable) y que un reset por una cuenta no-ACTIVE, aun teniendo éxito técnico, **desemboca en el muro
  post-identidad** (D-c) sin conceder nada.

- **[medium] La retirada de `/register` es de papel: el mock de self-signup sigue montado y activo**
  (`EXPERIENCE.md` § IA «Reemplazo register → accept-invitation»; verificado: `pwa/src/app/(auth)/register/`
  y `RegisterForm.tsx` existen). *Attack:* la espina retira el alta libre (invitation-first) pero el
  `/register` actual es un mock que **siembra identidad** sin invitación — una superficie de creación de
  cuenta no autenticada que contradice el invariante de negocio; si llega a un build junto a la nueva UX, es
  una puerta abierta de abuso/enumeración. *Fix:* enunciar que la retirada es **efectiva** (ruta eliminada o
  bloqueada), no solo deprecada en el contrato; el mock no puede coexistir con las superficies nuevas.

- **[low] Especificidad post-identidad enumerable por cualquier poseedor de credenciales (incl. credential
  stuffing)** (`EXPERIENCE.md` § Invariante «post-identidad, específico»; muros SUSPENDED vs DEACTIVATED vs
  locked). *Attack:* la neutralidad protege al atacante **sin** credenciales; pero quien tiene una contraseña
  filtrada válida (credential stuffing, phishing) distingue por el muro ACTIVE-locked / SUSPENDED /
  DEACTIVATED-genérico el **estado exacto** de la cuenta. Es aceptable por diseño (post-identidad ≠
  pre-identidad), pero el modelo asume que el poseedor de la credencial *es* el usuario legítimo, cosa que el
  credential stuffing viola. *Fix:* reconocer explícitamente el límite del invariante (solo cubre al atacante
  no autenticado) y considerar que DEACTIVATED ya es genérico por esto — evaluar si SUSPENDED debería serlo
  también.

- **[low] Contrato de email incompleto: enlace TLS-only y escape de contenido dinámico no enunciados**
  (`DESIGN.md` `SecurityEmail`; § Contrato de emails). *Attack:* (1) si el enlace del token pudiera emitirse
  como `http://`, el token viaja en claro; (2) si el nombre del invitado u otro campo controlado por el
  usuario se refleja en el HTML del email sin escapar → inyección HTML/CSS en cliente de correo. *Fix:*
  enunciar: enlace **siempre HTTPS**; todo contenido dinámico del email **escapado**.

- **[low] La pantalla de aceptación puede revelar el email del invitado** (`EXPERIENCE.md` B4 / J1 — el rol no
  se muestra; el email no se menciona). *Attack:* si B4 pre-rellena/saluda «Bienvenida, marta@acme.com», el
  token queda correlacionado a un email concreto; combinado con una fuga de token (finding Referer/logs), el
  atacante obtiene par (email, cuenta). *Fix:* declarar que B4 no revela el email destinatario (coherente con
  «el token nunca se renderiza»), o asumir el riesgo explícitamente.

- **[low · carry-over] El mock actual de reset **filtra** el motivo, rompiendo la opacidad del token**
  (verificado: `ResetPasswordForm.tsx` L40 «This password reset link is invalid or **has expired**»).
  *Attack:* el copy actual dice «inválido **o ha caducado**», justo lo que el invariante prohíbe («nunca
  «caducado»»). Es un mock y «gana la espina», pero si se cablea sin corregir, el invariante nace roto. *Fix:*
  al integrar, colapsar a la copia neutra única «Este enlace ya no es válido» (sin motivo), como manda la
  espina.

## Notes

- **Bien resuelto (sin finding):** el flujo `?next=` — la espina cita `safeInternalPath(next, BACKOFFICE)` +
  `safeHref` y el `LoginForm.tsx` real lo implementa exactamente (rechaza off-origin, `//host`, `/\host`,
  esquemas peligrosos); `safeInternalPath.ts` confirma el guard. La prohibición de JWT/PII/secretos en
  `localStorage`/`sessionStorage` (sesión httpOnly) es consistente en toda la espina y no la contradice
  ningún almacenamiento cliente actual (solo prefs de tema/densidad, no sensibles). La opacidad del *render*
  del token (nunca se pinta) y la unicidad del muro opaco (todas las muertes de token colapsan a «Este enlace
  ya no es válido») están correctamente especificadas — el problema no es lo que se pinta sino los canales
  laterales (timing/status/Referer/logs) que la espina no nombra.
- **Distinción (a) vs (b):** casi todos los findings son concerns cuya **implementación** es correctamente
  diferida al ADR de Identity/Invitation / backend — pero la espina **elevó** indistinguibilidad y opacidad a
  *invariantes transversales*, así que debe **enunciar el contrato** (timing/status/forma; higiene del token
  en URL; rate-limit; CSRF+fixation; stateless-wall; revoca-todo-en-reset). El invariante solo es honesto si
  la espina dice qué debe cumplir el backend, no solo qué texto mostrar.
- **Handoff recomendado:** llevar los findings high/medium como **entradas explícitas** al
  `bmad-create-architecture` del ADR de Identity/Invitation (E8 ya lo prevé), en particular la ruta de tiempo
  constante y la política de token-en-URL, que son los dos que más socavan los invariantes declarados.
