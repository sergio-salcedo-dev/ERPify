# Spine Pair Review — ERPify (acceso/identidad)

> Rubric walker · run `ux-ERPify-2026-07-06` · `DESIGN.md` (delta) + `EXPERIENCE.md` sobre `pwa/DESIGN.md`.
> Pregunta rectora: *¿puede un consumidor (arquitectura, story-dev) extraer de origen sin ambigüedad, con cada
> referencia resuelta y cada decisión portante comprometida?* Veredicto por categoría: **strong / adequate / thin / broken**.
> Severidad = impacto downstream, no dificultad de arreglo.

## Overall verdict

Par **sólido y extraíble**. Orden canónico de secciones perfecto en `DESIGN.md`, todos los defaults de EXPERIENCE
presentes, los seis componentes con doble spec (visual + comportamiento), y **cada** referencia de token/composite/fuente
resuelve al sistema heredado o a un fichero real (verificado en disco). La disciplina de herencia es ejemplar: la
desviación de densidad («density is a feature» → auth cómodo `--text-md`) va **argumentada y marcada**, y la aplicación de
tokens semánticos es contrastante-correcta (AA) en los cinco componentes nuevos. No hay hallazgos críticos ni altos: el
modelo de identidad (cuatro máquinas + dos invariantes) resiste los 6 journeys + V1 sin estado nuevo, y la espina es
**honesta** sobre su límite — declara que el hogar durable del modelo es una extensión de ADR aún inexistente, no la
espina. Lo que queda son medios/bajos de *localización*: dónde renderiza el muro post-identidad (C1 sin ruta propia), B1
Login ensamblado desde secciones dispersas, y solapamientos menores de secciones inventadas.

## 1. Flow coverage — strong

Extraídos de decision-log (J1–J6 + V1, personas E9, proyección E7) y verificados contra § Key Flows.

- **J1 Marta** (protagonista ✓ · pasos numerados 1-3 ✓ · clímax ✓ · ruta de fallo: pérdida de cobertura + dos ramas ✓ · D-a).
- **J2 Rubén** (✓ · pasos 1-3 · clímax · lockout+reset · D-b). **J3 David** (✓ · clímax opacidad). **J4 Elena** (✓ · flujo
  code-block · clímax · D-c + tres momentos — el journey arquitectónicamente portante). **J5 Sergio** (contrato tabular
  Acción→Evento→Estado→Email→Superficie, sin clímax por diseño — es costura, no pantalla). **J6 Rubén** (✓ · confluencia 3
  máquinas). **V1** (validación de opacidad, sin protagonista — aceptable).
- Cada disparador de la tabla E7 aterriza en una superficie del inventario IA; cada superficie tiene un camino que la
  alcanza. La trazabilidad sources→journey→proyección está cerrada.

### Findings

- **low** J1 declara «3G intermitente / domingo por la noche / caravana» y color narrativo; es *carga útil del test*
  (persona+contexto+dispositivo, método E10 de Sergio), no editorializar de más — no es hallazgo, se anota para que un
  editor futuro no lo pode por «voz». *Fix:* ninguno; conservar.
- **low** `DEACTIVATED` y la expiración pura de sesión no tienen journey narrativo dedicado; se cubren por las tablas de
  proyección (E7) + microcopy (Voice and Tone) + § Modelo de sesión. Adecuado bajo el marco «journeys = tests, no
  exhaustivo», pero un consumidor que busque el recorrido `DEACTIVATED` lo arma de tablas. *Fix:* opcional, una línea en J4
  o V-scenario que toque `DEACTIVATED` cerraría el set.

## 2. Token completeness — strong

Extraídos: 6 bloques `components:` de frontmatter + toda referencia `{color.*}` / `{radius.*}` / `{font.*}` en prosa.
**Todas** resuelven al sistema heredado `pwa/DESIGN.md` (verificado token a token contra sus tablas de rampa): `bg-elevated`,
`text-subtle/muted/on-accent`, `text`, `border`, `brand`, `accent`, `warning(-strong)`, `success(-strong)`, `danger`,
`security`, `focus-ring`, `radius.lg/md`. Ninguna hue nueva; `{color.security}` `#7589ad` coincide exacto (claro=oscuro).
Los seis bloques de frontmatter están internamente completos (surface + tokens de rol + radius cada uno).

**Verificación de contraste (no pedida, pero es donde vive el riesgo real de un delta de color):** la elección de tokens es
AA-correcta — `warning-strong` sobre `bg-subtle` (`#e9eaec` claro está en la lista AA-verificada del sistema; en oscuro
`-strong`=base sobre navy pasa), `success-strong` sobre `bg-elevated` (`#fff` en la lista AA), `text-muted` como cuerpo de
`AccessWall`. `security` se usa solo como acento, «nunca canal único», nunca texto — sin violación AA. Fortaleza, no hallazgo.

### Findings

- **low** `security-email` fija hex literales (`#ffffff`, `surface-dark #11151f`, `text #08090a`, `text-dark #e7eaf3`).
  **Justificado y correcto:** el comentario inline explica que los clientes de correo no resuelven variables CSS, y los
  cuatro literales *espejan exactamente* la rampa heredada (bg-elevated claro, canvas oscuro, text claro/oscuro) — no
  inventan color, lo pinman como valor email-safe con paridad de rama. Es exactamente el caso en que la espina *debe* poseer
  el hex. *Fix:* ninguno; el `link/button-bg/button-text/border` siguen siendo `{token}` heredados, coherente.
- **low** `security-email.surface-dark #11151f` es el canvas (`--color-bg`), no el `bg-elevated` oscuro (`#242e42`) — elección
  deliberada «el email es plano». Coherente con Elevation & Depth. Anotar para que nadie lo «corrija» a bg-elevated.

## 3. Component coverage — strong

Los seis (`AccessWall`, `TokenActionScreen`, `ConnectivityButton`, `OfflineNotice`, `SecuritySignal`, `SecurityEmail`)
tienen **spec visual en `DESIGN.md`.Components** (anatomía, uso de color, estados, variantes — reglas reales, no de una
palabra) **y spec de comportamiento en `EXPERIENCE.md`**. Los cinco primeros bajo § Component Patterns; `SecurityEmail`
bajo su propia § Contrato de emails (idioma, remitente no-`no-reply`, contenido bancario, enlace prominente, origen del
token). Cobertura completa.

### Findings

- **low** El spec de comportamiento de `SecurityEmail` **no** vive bajo § Component Patterns (donde están los otros cinco)
  sino en § Contrato de emails. Está presente y es hallable, pero un consumidor que barra «Component Patterns» buscando los
  seis encuentra cinco. *Fix:* una línea puntero en Component Patterns («`{SecurityEmail}` → ver § Contrato de emails»)
  cierra la simetría sin duplicar.
- **low** Split de forma en el nombre: frontmatter usa clave kebab (`access-wall`), cuerpo de `DESIGN.md` y todo
  `EXPERIENCE.md` usan PascalCase (`AccessWall` / `{AccessWall}`). Es la convención del design-md-spec (claves de componente
  en kebab) + contrato de nombres Pascal — no está roto y la línea de contrato lo declara «se nombran igual». Pero un
  extractor de máquina que lea `components.access-wall.*` y un consumidor que resuelva `{AccessWall}` necesitan saber que es
  el mismo objeto. *Fix:* nota de mapeo (kebab frontmatter ↔ Pascal prosa) — ver Mechanical notes.

## 4. State coverage — adequate (strong en superficies token)

Recorridas A1, B1, B2, B3, B4, C1 contra {idle, loading, error, offline, empty, permission/wall, success}.

- **B3 / B4** (`TokenActionScreen`): **strong** — `DESIGN.md` enumera idle · enviando · sin conexión · error de validación
  (violations→FormField, foco al primer inválido) · error técnico (`ProblemDisplay panel`) · éxito→`SecuritySignal` · +
  token ausente/no elegible → `AccessWall`. Set completo.
- **C1** (`AccessWall`): **strong** — variantes (invalid-link/suspended/locked/session-expired/deactivated), estático (sin
  carga), dark parity. `empty` N/A (no es lista).
- **A1** (entrada landing): **adequate por diseño** — solo idle (CTA); loading/error N/A; explícitamente en lenguaje
  marketing, «no se redefine aquí».
- **B1 / B2**: **adequate** — sus estados existen pero **distribuidos** (credencial-fallo neutro en Voice and Tone; lockout/
  suspended/deactivated/session-expired en tablas de muro; submit vía `ConnectivityButton`; offline vía `OfflineNotice`;
  validación vía FormField/Zod). No hay una enumeración de estados *por superficie* como sí la tiene `TokenActionScreen`.

### Findings

- **medium** **B1 Login** — superficie más usada, hoy mock — no tiene ni enumeración de estados propia ni journey dedicado
  (se ejercita dentro de J2/J4). Un story-dev debe ensamblar su máquina de estados de ≥4 secciones (Voice and Tone,
  tablas de muro, Interaction Primitives `?next=`, Resiliencia). *Fix:* un bloque de estados de B1 análogo al de
  `TokenActionScreen` (idle · enviando · credencial-fallo neutro · lockout→muro · offline · éxito→ERP · `?next=` validado),
  aunque sea 6 bullets.
- **medium** **C1 sin ruta propia + render post-identidad sin localizar.** IA dice «render de `{AccessWall}`, sin ruta
  dedicada» y Component Patterns dice «un login exitoso-pero-no-admitido navega a él» — pero **no fija dónde** renderiza el
  muro `SUSPENDED`/`LockedUntil`/`DEACTIVATED` (¿inline en `/login`? ¿variante con query? ¿ruta de estado?). Para
  token-inválido sí está claro (en lugar del `TokenActionScreen` en la propia ruta `?token=`). Es una pregunta que un
  story-dev golpea el día 1. Podría delegarse a la arquitectura (el muro es proyección de un chequeo de admisión
  server-side), pero la espina no lo dice. *Fix:* una frase en IA/C1 fijando el destino de render de los muros
  post-identidad (o declarándolo explícitamente diferido al ADR de identidad).

## 5. Visual reference coverage — strong (nada esperado)

No se esperan mocks en este run (key-screen mocks = paso Finalize diferido). Confirmado: **cero referencias huérfanas** —
sin `![img]`, sin enlaces a `mockups/`/`wireframes/`, sin `.png/.svg`. Las dos menciones de «mock»/«wireframe» son
conceptuales («gana la espina sobre cualquier mock»; «journeys no son wireframes»). Existe un `imports/` de scaffold
**vacío** que nada referencia — inocuo.

## 6. Bloat & overspecification — adequate

Sin sobre-especificación de píxeles donde el token cubre: los pocos valores concretos son load-bearing y sin token
equivalente (`≥ 44 px` táctil = suelo a11y; `~600 px` ancho email; `--text-md` 16 px paso cómodo). Tablas usadas donde
tocan (microcopy, proyección, inventario IA, frontera del run). La voz narrativa de los journeys es método, no adorno.

### Findings

- **low** § State Patterns reproduce el modelo E6 del decision-log **casi verbatim** (las cuatro máquinas con toda su
  razón: sin `PENDING`, roles-antes-de-aceptar, `SUSPENDED`↔`DEACTIVATED`, etc.). Justificable *hoy* (es la forma escrita
  más completa del modelo; el ADR de extensión aún no existe), pero cuando ese ADR aterrice habrá duplicación DRY. *Fix:*
  al levantar el ADR de Identity/Invitation, adelgazar § State Patterns a la proyección (pantallas/estados visibles) y
  apuntar al ADR para el modelo — la propia espina ya se declara «no fuente de verdad».
- **low** § «Señales de seguridad visibles» solapa con el patrón `{SecuritySignal}` + la tabla de Voice and Tone; §
  «Anti-patrones» solapa con Do's/Don'ts de `DESIGN.md` y con los invariantes. Redundancia leve; un «no hacer» consolidado
  tiene valor para story-dev, pero repite. *Fix:* opcional, colapsar «Señales de seguridad visibles» en una remisión a
  `{SecuritySignal}` + Voice and Tone.

## 7. Inheritance discipline — strong

- `sources` (3), `inherits`, `design_ref`, `system_ref`: **todos resuelven a ficheros reales** (verificado en disco:
  `arch-addendum-auth-rbac.md`, `docs/adr/auth-rbac-subsystem.md`, `docs/adr/rbac-authorization-model.md`, `pwa/DESIGN.md`,
  `./DESIGN.md`). Las descripciones del frontmatter son fieles: SI-1..SI-5 existen en el addendum; D1 firewall-sesión / D2
  identidad / D3 roles existen en el ADR.
- **Aterrizaje honesto del modelo:** el ADR heredado mantiene `Backoffice/Identity` deliberadamente mínimo (User +
  HashedPassword + enum Role) y **descartó** promover a IAM top-level «hasta que emerjan MFA/password-reset/lockout/
  sessions/invitations». La espina proyecta *exactamente* esas capacidades y § Encaje del modelo lo reconoce: pide una
  **extensión de ADR** (`bmad-create-architecture`) como hogar durable — no se arroga ser la fuente de verdad ni contradice
  el ADR mínimo. Es el hand-off correcto.
- Nombres de composites heredados referenciados por `{nombre}` — `{FormField}`, `{ProblemDisplay}`, `{MutationError}`,
  `{AsyncBoundary}`, `{EmptyState}` (`variant="permission-denied"` real), `{StatusBadge}`, `{CorrelationIdChip}`,
  `{CopyButton}`, `{Logo}`, `{ThemeToggle}`, `{Spinner}`, variantes de botón Brand/Ghost/Subtle/Icon/Pill/Destructive —
  **todos existen** en el sistema heredado.
- Glosario consistente (espina/muro/opacidad/pre-post-identidad/tres-momentos/D-a·b·c) a través de decision-log, journeys,
  ambas espinas.

### Findings

- **low** `{AuthLayout}` se referencia como heredado, pero **no** figura en la lista de composites de `pwa/DESIGN.md`: es el
  shell del route-group `app/(auth)/` existente (decision-log E1 lo confirma en código). El contexto lo desambigua («shell
  `AuthLayout` **actual**», «heredado»), así que resuelve — pero un consumidor que lo busque en `pwa/DESIGN.md` no lo
  encuentra. *Fix:* nota de una línea señalando que `AuthLayout` vive en `app/(auth)/`, no en el design-system doc.

## 8. Shape fit — strong

- **`DESIGN.md`: orden canónico perfecto** — Brand & Style → Colors → Typography → Layout & Spacing → Elevation & Depth →
  Shapes → Components → Do's and Don'ts. Los ocho, en orden bloqueado, todos como *delta*.
- **`EXPERIENCE.md`: todos los defaults presentes** — Foundation, Information Architecture, Voice and Tone, Component
  Patterns, State Patterns, Interaction Primitives, Accessibility Floor, Key Flows, Anti-patrones. Secciones inventadas
  (Invariante de seguridad, Resiliencia de conectividad, Modelo de sesión, Contrato de emails, Frontera del run, Encaje del
  modelo) **ganan su sitio**: son las decisiones portantes transversales y el contrato de scope/hand-off para downstream.
- Desviación de densidad (auth cómodo vs back-office denso) **argumentada y marcada** como «la única excepción
  deliberada» — justamente la «flexibilidad justificada» que el repo pide, flagueada, no silenciosa.

### Findings

- **low** No hay sección dedicada **«Responsive & Platform»** (el default del ejemplo shadcn la tiene como tabla por
  breakpoint). Aquí se pliega en Foundation (móvil-en-obra de primera clase, una-mano) + Layout de `DESIGN.md` (ergonomía
  de pulgar, teclado que no tapa el botón). Para superficies auth (tarjeta única centrada `max-w-sm`) una tabla por
  breakpoint sería casi vacía, así que la distribución es razonable. *Fix:* opcional; si se quiere paridad de forma, 3-4
  bullets de comportamiento responsive en Foundation.

## Mechanical notes

- **Consistencia de nombres:** seis componentes idénticos en PascalCase entre cuerpo de `DESIGN.md` y `EXPERIENCE.md`
  (`AccessWall`, `TokenActionScreen`, `ConnectivityButton`, `OfflineNotice`, `SecuritySignal`, `SecurityEmail`); claves de
  frontmatter en kebab (`access-wall`, …) por convención del design-md-spec. Mapeo kebab↔Pascal implícito — conviene una
  nota de puente (ver Finding 3-low).
- **Cross-refs:** todos los enlaces markdown de ambas espinas resuelven a ficheros reales (`pwa/DESIGN.md`, `./DESIGN.md`,
  `docs/adr/auth-rbac-subsystem.md`, `docs/adr/rbac-authorization-model.md`). Ningún enlace roto, ninguna referencia a mock
  inexistente.
- **Frontmatter:** `DESIGN.md` (name/status/updated/inherits/scope/note/components) y `EXPERIENCE.md`
  (name/status/updated/design_ref/system_ref/sources/scope/note) completos y bien formados; `status: final` en ambos.
- **Dependencia downstream portante (no es hallazgo contra la espina):** el backend de B2/B3/B4/C1 **no existe** (agregado
  `Invitation`, `User.status`, lockout, store de sesiones). La espina lo declara (Foundation «estado real del terreno» +
  Encaje del modelo). Arquitectura debe levantar la extensión de ADR de Identity/Invitation **antes** de que estas
  historias sean dev-ables — es la primera pieza del hand-off.

## Conteo de hallazgos

- **critical:** 0 · **high:** 0 · **medium:** 2 (B1 sin enumeración/journey propio; C1 render post-identidad sin
  localizar) · **low:** 9.
</content>
</invoke>
