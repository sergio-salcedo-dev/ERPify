# Accessibility Review — ERPify (acceso/identidad)

> Revisor: accesibilidad (WCAG 2.2 AA + comportamiento real de tecnología asistiva). Alcance: par de
> espinas UX de acceso/identidad (`EXPERIENCE.md` + `DESIGN.md`), superficie pública, sensible a
> seguridad, móvil-en-obra de primera clase. Base heredada: `pwa/DESIGN.md` + `pwa/CLAUDE.md`.

## Overall verdict

La espina tiene una base de accesibilidad **fuerte y deliberada**: el color nunca es canal único (los
muros comunican el estado con **texto**, no con tinte), el estado de carga no depende de animación
(`prefers-reduced-motion` cubierto), los targets táctiles se elevan a ≥44 px, y la resiliencia de red no
pierde datos. La decisión de **un solo campo de contraseña con toggle** es la correcta y debe mantenerse
(es *más* accesible que un campo de confirmación para baja visión / motricidad / lector de pantalla), pero
necesita endurecerse contra el «mistype → lockout». Las dos debilidades reales son de **gestión de foco**:
la palabra «trampa de foco» sobre pantallas que **no son modales** (riesgo de keyboard-trap, WCAG 2.1.2
nivel A) y la **ausencia de gestión de foco en las transiciones de éxito/post-login** (SPA), donde un
usuario de lector de pantalla pierde el sitio y se pierde la confirmación de seguridad. Ninguna es
bloqueante hoy porque la implementación no existe, pero ambas se materializarían como fallo si se codifica
la espina literalmente.

## Findings

- **[high]** «Trampa de foco correcta dentro de la tarjeta» aplicada a superficies **no modales**
  (`EXPERIENCE.md` § Accessibility Floor, línea ~279; también § Interaction Primitives). `TokenActionScreen`
  y `AccessWall` son **documentos primarios de página** (rutas `/reset-password`, `/accept-invitation`,
  render de muro) dentro de `AuthLayout` (`min-h-dvh`), **no** diálogos modales. Un focus-trap literal
  (Tab desde el último control vuelve al primero y nunca alcanza el `{Logo}`, el `{ThemeToggle}` ni el
  chrome del navegador) es un **keyboard trap — WCAG 2.1.2, nivel A**, que puede dejar varado a un usuario
  de teclado/lector de pantalla. *Fix:* eliminar «trampa de foco» para estas superficies; el foco se
  *gestiona* (autofocus inicial + retorno al campo inválido), **no se atrapa**. Reservar el focus-trap solo
  para lo que sea realmente modal (`<RecordSheet>`/`Dialog`, que ya lo hacen bien con focus-restore).

- **[high]** Foco **no gestionado en las transiciones SPA de éxito y post-login**
  (`EXPERIENCE.md` § Component Patterns `{TokenActionScreen}` «Al éxito emite `{SecuritySignal}` y navega»;
  § Component Patterns `{AccessWall}` «un login exitoso-pero-no-admitido navega a él»). Tras un submit con
  éxito (invitación aceptada / contraseña fijada) o un login que aterriza en un muro, la ruta cambia pero
  **la espina no dice adónde va el foco**. En un cambio de ruta cliente el foco cae a `<body>`: el usuario
  de lector de pantalla pierde su posición y **no oye** la confirmación *«Invitación aceptada. Ya puedes
  empezar a trabajar.»* — justo la señal E14 que reduce incertidumbre. *Fix:* especificar que al montar la
  nueva superficie el foco pasa al `<h1>` de la tarjeta / al encabezado del `{SecuritySignal}` (o se anuncia
  por `aria-live`), en las tres transiciones: éxito de token, login→muro, sesión expirada→re-login.

- **[medium]** **Decisión de un solo campo de contraseña — mantener, pero endurecer contra
  «mistype → lockout»** (`EXPERIENCE.md` § `{TokenActionScreen}` línea ~166; `DESIGN.md` § `TokenActionScreen`).
  Sin campo de confirmación, un error de tecleo se persiste como la contraseña real; en B4 el token es de un
  solo uso, así que un fallo obliga a re-pedir invitación. El riesgo cae sobre motricidad reducida y baja
  visión en móvil, una mano, 3G. *Fix (tres mitigaciones, no volver al doble campo):* (1) que el toggle
  **arranque en «visible/revelado»** en las pantallas por token — es una contraseña que se *crea*, no un
  secreto existente que proteger, y el jefe de obra está solo en la caravana; esto permite verificar antes
  de enviar; (2) el toggle debe ser target táctil grande y anunciar su estado (`aria-pressed`); (3)
  documentar y exponer la **ruta de recuperación**: tras aceptar invitación la identidad es `ACTIVE`
  directo, así que un password mal tecleado en B4 se recupera con **forgot-password** (no re-invitación) —
  la espina no conecta este salvavidas.

- **[medium]** **Legibilidad en modo oscuro de los enlaces/botón del email**
  (`DESIGN.md` front-matter `security-email`: `link: '{color.accent}'`, `button-bg: '{color.brand}'`). El
  propio spec advierte «email clients no resuelven variables CSS» y da paridad de rama literal **solo** a
  `surface`/`text` (`surface-dark`/`text-dark`), pero deja `link`/`button` como tokens `{color.*}`. Un
  `{color.accent}` inlineado a su valor claro `#2f5cd9` sobre superficie oscura `#11151f` ≈ 2.5:1 →
  **falla AA** para un usuario que abre el email a las 6:30 en oscuro. *Fix:* dar a `link` (y verificar
  `button`) literales con paridad de rama como `surface`/`text` — rama oscura `#6c9bff` para el enlace;
  texto de enlace legible en ambas ramas aunque el cliente ignore `prefers-color-scheme`.

- **[medium]** **Rationale `aria-live="polite"` confunde «assertive» con «robar foco»**
  (`DESIGN.md` § `OfflineNotice` «`aria-live="polite"` … no arrastrar el foco»; `EXPERIENCE.md` § Accessibility
  Floor «se anuncia por `aria-live` sin robar el foco de forma destructiva»). Ni `polite` ni `assertive`
  mueven el foco — «assertive» solo interrumpe la locución; la justificación es técnicamente errónea y
  puede llevar a infrautilizar `assertive` donde procede. El contrato del sistema (`pwa/DESIGN.md` §
  Accessibility) dice **«assertive para errores de acción»**; un submit que el usuario está esperando y
  **falla** (500, red dura vía `<ProblemDisplay>`) es un error de acción. *Fix:* `OfflineNotice` puede
  quedarse `polite` (condición ambiental recuperable), pero el error real de mutación en submit se anuncia
  **`assertive`** — sin mover foco en ningún caso. Corregir el porqué en la espina.

- **[medium]** **Semántica de encabezados sin fijar (token visual vs elemento)**
  (`DESIGN.md` § `AccessWall` «título (Heading 2)», § `TokenActionScreen` «título (Heading 2)»,
  § `SecuritySignal` «título (Heading 3)»). «Heading 2/3» nombra el **token de escala** (24/20 px), no el
  nivel semántico. Si cada superficie renderiza su título como token sin fijar el elemento, se arriesga una
  página **sin `<h1>`** o un `{SecuritySignal}` en `<h3>` que **salta niveles** — rompe la navegación por
  encabezados del lector (WCAG 1.3.1 / 2.4.6). *Fix:* pinar exactamente **un `<h1>`** por superficie de auth
  (el título de la tarjeta, estilado con el token Heading 2) y garantizar que `{SecuritySignal}` no salta
  nivel; separar explícitamente «token de tamaño» de «nivel de elemento» como ya hace `<EmptyState>`.

- **[medium]** **Autofocus al campo salta el contexto para el usuario de lector de pantalla que llega
  del email** (`EXPERIENCE.md` § Interaction Primitives «`autofocus` en el primer campo al montar»). En
  B3/B4 el primer campo es la contraseña: al aterrizar desde el enlace, el foco salta **por encima** del
  título (Heading 2) y del copy de contexto, que el usuario de lector nunca oye. *Fix:* garantizar que el
  `<FormField>` de contraseña lleva `aria-describedby` con **las reglas de contraseña y el propósito de la
  pantalla** (no solo las reglas), o enfocar un contenedor que anuncie el título+contexto; así el usuario
  que cae desde el email sabe *dónde está* y *qué se le pide* sin tabular hacia atrás.

- **[medium]** **Target táctil del toggle mostrar/ocultar sin especificar**
  (`DESIGN.md` § `TokenActionScreen` «toggle de visibilidad (botón Icon)»). El botón Icon vive **dentro**
  del campo; su altura puede heredar la del campo (≥44 px) pero su **anchura** (icono `Eye`) tiende a
  quedarse estrecha. En móvil, una mano, es un fallo típico de 2.5.8 (AA, ≥24×24) / 2.5.5. *Fix:* fijar el
  área de toque del toggle ≥44×44 px (o mínimo AA 24×24 con separación), con `aria-pressed` para el estado.

- **[low]** **`{color.security}` `#7589ad` no es AA como texto** (`DESIGN.md` § Colors, fila «Eje de
  seguridad»). ≈3.9:1 sobre blanco: vale como acento **gráfico** (≥3:1, 1.4.11) pero **falla 1.4.3** si
  alguna vez colorea texto legible. El spec dice «nunca como único canal» pero no **prohíbe** su uso como
  texto. *Fix:* restringir `{color.security}` a borde/icono/acento gráfico; si porta semántica en texto,
  usar un tono `-strong` AA.

- **[low]** **Contrato de accesibilidad del email incompleto** (`DESIGN.md` § `SecurityEmail`;
  `EXPERIENCE.md` § Contrato de emails). No se especifica: texto de enlace **descriptivo** («Aceptar
  invitación» / «Restablecer contraseña», nunca «haz clic aquí») para linealización del lector; `lang="es"`
  en el documento del correo; `alt` del wordmark si es imagen; y una **URL de reserva en texto plano** bajo
  el botón bulletproof (algunos clientes lo eliminan). *Fix:* añadirlos al contrato de plantilla.

- **[low]** **Referente «tu administrador» abstracto para algunas personas** (`EXPERIENCE.md` § Voice and
  Tone / muros de token). *«Solicita una nueva invitación a tu administrador»* asume que la persona sabe
  quién/cómo — dudoso para David (subcontratista, acceso ocasional). La opacidad del token es correcta e
  innegociable, y el muro **sí** compensa con siguiente-paso + botón (buena carga cognitiva en general);
  solo es un matiz. *Fix (si el dato existe):* concretar el canal de contacto cuando se conozca.

- **[low]** **El punto de entrada A1 hereda accesibilidad de la superficie marketing sin migrar**
  (`EXPERIENCE.md` § IA, A1; `pwa/DESIGN.md` adoption table: landing `un-migrated`, raw slate/blue). El CTA
  «Iniciar sesión» del `Navbar` es la puerta de todo el flujo pero su a11y (contraste sobre paleta cruda,
  focus-visible, tamaño de target) queda delegada al lenguaje marketing, fuera de esta auditoría. *Fix:*
  verificar contraste/foco/target del CTA en la landing antes de tratarlo como entrada de confianza.

- **[low]** **Cambio de etiqueta del `{ConnectivityButton}` no auto-anunciado** (`DESIGN.md` §
  `ConnectivityButton` «conserva el foco entre estados»). Al pasar idle → «Enviando…» → «Reintentar», el
  botón mantiene el foco; un cambio de *label* en un elemento ya enfocado **no** lo re-anuncian todos los
  lectores. *Fix:* emparejar el estado con `aria-live`/actualización de `aria-describedby`, además de
  `aria-busy` (que ya cubre el estado ocupado).

## Notes

- **Bien resuelto (no tocar):** color nunca como canal único en `AccessWall`/`SecuritySignal`/`OfflineNotice`
  (estado en texto); `-strong` para semántico-como-texto con AA declarado en `pwa/DESIGN.md`;
  `prefers-reduced-motion` a nivel de token + carga con **etiqueta textual** («Enviando…»), no solo spinner;
  targets ≥44 px; anti doble-envío y formularios que no pierden datos; retorno de foco al **primer campo
  inválido** (correcto y alineado con `<FormField>`); paridad de dark mode con literales de rama en email;
  `Esc` no envía el formulario.
- **La opacidad de token y la neutralidad pre-identidad son requisitos de seguridad**, no defectos de
  carga cognitiva: el spec los compensa correctamente con «siguiente paso» + acción siempre presentes
  (Voice and Tone, regla 4). *«Este enlace ya no es válido»* es terso a propósito y aceptable **porque**
  el cuerpo y los botones dan salida.
- Las dos HIGH y varias MEDIUM son de **especificación de gestión de foco/anuncio**: baratas de cerrar en
  la espina ahora (una frase por hallazgo) y caras de descubrir en QA con lector de pantalla después.
