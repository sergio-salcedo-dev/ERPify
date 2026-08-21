---
title: 'Los guardarraíles que no guardaban: shell sin linter, y el gate adversarial con dos imprecisiones y una superficie ciega — #818, #816, #815'
type: 'chore'
created: '2026-08-21'
baseline_commit: 'd4439b2dbefd67d774ef25ebf5841421dcfef248'
status: 'in-review'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema:** tres issues, un solo eje — *mecanismos declarados que no ejercen*. **#818**: `make/super-lint.mk` ofrece un validador de shell desde hace meses y `.github/workflows/` no lo nombra en ningún sitio, así que nada lintaba shell; el listón lo sostenía la mano, y se nota — shellcheck reportaba **nueve** hallazgos contra `scripts/adversarial-pass-check.sh` (el shell más grande del árbol y el más ejecutado: un hook `PreToolUse` lo corre en cada llamada `Bash`) mientras los tres `scripts/*.sh` antiguos reportaban cero. **#816**: dos imprecisiones del gate adversarial — el guardián de "la sección ha cambiado" no acota *cuánto*, y la ruta CLI juzga un `--repo other/thing` contra el estado de **este** checkout. **#815**: el gate es un hook `PreToolUse`, que sólo observa llamadas de herramienta hechas en esta sesión, así que una PR abierta por la web, otra máquina, un job de CI o `curl` contra `POST /pulls` no se ve en absoluto.

**Approach:** cerrar cada uno con el instrumento más pequeño que realmente ejerza, y dejar escrito qué prueba cada verde y qué no. Nada de baselines: el árbol está en cero, así que uno sólo conservaría la deriva futura.

## Boundaries & Constraints

**Always:** cada afirmación sobre un gate se sostiene en una mutación del código que la enrojece — las 101 filas del self-test se falsificaron así. El gate local sigue fallando **abierto**; el de servidor **no** hereda eso, porque allí nada puede atascar una rama.

**Never:** un baseline. Un pin de shellcheck que ningún carril de dependabot vigile. Leer un timestamp para decidir el orden. Mergear a `main`.

**Ask First:** convertir `shell.lint` en miembro de `ci.quality` (cambiaría el contrato de ese agregado para todo contribuidor).

## Decisiones tomadas

1. **#818 — shellcheck a pelo, job propio, criterio de entrada cero.** Descartado cablear `super-lint`: es docker, los contenedores de sesión remota no tienen docker (un contribuidor allí nunca podría autocomprobarse), y un linter amplio sobre un árbol existente llega con baseline. El sujeto es **shell rastreado**, no ficheros llamados `*.sh`: la unión de `*.sh` y todo fichero rastreado cuya primera línea es un shebang de sh/bash, que son cinco más. Seleccionar por extensión habría dejado esos cinco sin lintar mientras "el gate cubre shell" dejaba de ser cierto en silencio.
2. **#818 — versión la del runner, no un pin.** Un pin sería determinista, pero pip no es uno de los cuatro ecosistemas que `.github/dependabot.yaml` vigila, así que sería el único pin no vigilado del árbol — la forma exacta de #763. Coste aceptado y nombrado: un bump de imagen puede introducir un hallazgo que ayer no estaba. Sigue siendo un hallazgo real, y el job imprime `shellcheck --version` para que un rojo sorpresa se diagnostique desde el log.
3. **#816.1 — los pisos se mudan al delta.** La identidad respondía "¿cambió la sección?" y nada más; los pisos de sección ya los satisface el texto preexistente, así que añadir una palabra a la pasada de otro ponía el gate en verde. Medido. Con los pisos sobre lo que **añade** respecto de cualquier pasada ya en la base, el renombrado, la copia, el retoque de espacios y la extensión simbólica caen por la misma aritmética, y la identidad pasa a ser el caso delta-cero.
4. **#816.2 — la ruta CLI se planta ante otro repositorio,** como la MCP siempre hizo. Y el nombre de "este repositorio" sale del remoto, no del basename del checkout: `CLAUDE.md` exige worktree para toda rama de feature, y el basename de un worktree enlazado es su slug — leerlo hacía que toda llamada comparase desigual y el gate quedara mudo justo donde ocurre todo el trabajo.
5. **#815 — Action en `pull_request`, componiendo con el hook, no sustituyéndolo.** Cada uno es ciego donde el otro no: sólo el local puede atestiguar el **orden** (corre en el instante de creación y no lee fecha alguna), sólo el de servidor ve una PR que la sesión no abrió. El verde del servidor prueba **presencia en la cabeza, ahora** — nunca presencia *antes* de abrir la PR — y así queda escrito en el propio workflow. Descartado `pull_request_target`: leería la copia de la base, pero entrega un token con escritura a un workflow que razona sobre contenido no confiable de la cabeza; agujero mayor que el que cierra.

## Code Map

| Fichero | Qué cambia |
| --- | --- |
| `make/shell.mk` | nuevo — `shell.files` (la unión) y `shell.lint` (una sola invocación) |
| `.github/workflows/ci.yml` | job `shell-lint`, fila en `ci-summary`, y `ci-success` depende de él |
| `.github/workflows/adversarial-pass.yml` | nuevo — la mitad de servidor del gate |
| `scripts/adversarial-pass-check.sh` | delta, `segment_args`/`segment_target_repo`/`segment_base_ref`, `this_repo_name`, `--surface`, `--ack-from-body` |
| seis `.sh` | los seis hallazgos que impedían el criterio de entrada cero |
| `CLAUDE.md`, `docs/claude-code-quickref.md` | reglas y catálogo |

## Tasks & Acceptance

- `make shell.lint` → cero hallazgos sobre 20 scripts, y **rojo** con un hallazgo plantado (verificado en ambas direcciones).
- `make bmad.adversarial.self-test` → 101 filas verdes; cada mecanismo nuevo enrojece al mutarlo.
- El bloque `run` del workflow nuevo, ejecutado tal cual fuera de CI, en sus tres direcciones: sin registro → 1; con escotilla en el cuerpo → 0 y marcado UNCHECKED; base irresoluble → 1 con `::error::`.

</frozen-after-approval>

## Adversarial pass

Lectura hostil sobre el diff completo de esta rama, buscando activamente cómo romperlo. **La ejecutó el autor del cambio, no un contexto fresco ni un tercero** — la sesión tiene prohibido lanzar subagentes salvo petición explícita. `CLAUDE.md` dice que la autocertificación no cuenta como el gate, así que esto queda registrado como lo que es: una pasada real, con hallazgos reales y medidos, pero **pendiente de la lectura hostil por alguien distinto del autor**. Se declara aquí en lugar de dejar que el verde del gate insinúe lo contrario.

**GRAVE-1 — un flag leído del texto crudo del comando, y falla hacia el silencio.** La primera versión de `segment_target_repo` casaba `--repo` en cualquier punto del segmento. Medido, ejecutando el hook:

    gh pr create --title "chore: pass --repo other/thing to gh" --body x

no nombra repositorio alguno, pero la coincidencia de texto extraía `other/thing` del título y declaraba **toda la llamada no aplicable**: salida vacía, cero denegación, una PR sin registro abierta en silencio a partir de una cadena que el autor controla. Es exactamente el defecto que el propio fichero ya documenta para la escotilla (un `--body` que citaba el mensaje de denegación se auto-acreditaba), reintroducido por mí en la dirección peor. Corregido con `segment_args`, un escaneo que respeta comillas y produce argumentos como haría el shell; tres filas nuevas lo fijan y la mutación que desactiva el respeto a comillas enrojece cinco.

**GRAVE-2 — el mismo vector sobre `--base`, preexistente.** `BASE_FROM_PAYLOAD` salía de un `sed` que casaba `--base` en cualquier sitio, así que un título que lo mencionara elegía la base equivocada — y una base equivocada es un `merge-base` equivocado y por tanto un veredicto equivocado, en la dirección que toque. No lo introduje yo, pero el escáner de argumentos ya estaba escrito y aplicarlo cuesta tres líneas; queda fijado con siete filas, entre ellas "mención en el título, luego el flag de verdad" → gana el flag.

**GRAVE-3 — el gate estaba muerto en todo worktree.** `basename "${REPO_ROOT}"` era la respuesta entera a "qué repositorio soy". El raíz de un worktree enlazado es `.claude/worktrees/<slug>`, así que una llamada MCP nombrando **este mismo** repositorio comparaba desigual y devolvía no-aplicable, en silencio — y `CLAUDE.md` exige worktree para toda rama de feature, o sea que el gate estaba ciego precisamente donde ocurre todo el trabajo. Resuelto leyendo el remoto, con el directorio git **compartido** como respaldo (la única ruta en la que un worktree y su primario coinciden). La fila que lo fija es una ejecución real del hook desde un worktree del fixture; con el `basename` restaurado, enrojece.

**MEDIA-1 — el criterio "cero" no se sostenía sobre el árbol real.** #818 medía cero sobre tres scripts; el árbol tiene 15 `.sh` (20 contando shebangs) y reportaba **seis** hallazgos. Un gate con criterio de entrada cero no podía aterrizar sin arreglarlos: dos `SC1007` (prefijos de asignación vacíos deliberados, ahora `VAR=''`), dos `SC2016` (comillas simples cargadas de sentido — `$POSTGRES_USER` es el entorno del *contenedor*; directiva con su razón), un `SC2148` (fragmento sourced, `# shellcheck shell=bash`, que es el mecanismo del propio shellcheck y no una supresión) y un `SC2015` (`A && B || C` reescrito a un `if`, que es lo que la intención decía).

**MEDIA-2 — la invocación por fichero inventa hallazgos.** shellcheck sólo sigue un `source` hacia un fichero que también reciba como entrada. Medido: un bucle por fichero reporta tres hallazgos que la invocación en lote no — `SC1091` en cada `. lib/common.sh` y un `SC2034` por una variable que `common.sh` sí usa. Son artefactos de la llamada, no propiedades del código, y un gate que los reporta enseña a ignorarlo. De ahí una sola invocación; su límite (`xargs` parte por `ARG_MAX` y cada lote es una invocación aparte) queda escrito en el módulo.

**MEDIA-3 — la denegación en CI nombraba la escotilla equivocada.** El texto de denegación se ramifica por `SURFACE`, que sólo el hook rellena; ejecutado desde el workflow decía "escribe `ADVERSARIAL_PASS_ACK=...` delante de tu comando" a un lector que no está ejecutando comando alguno. Añadido `--surface`, y el workflow pasa `mcp` porque comparte esa escotilla (una línea en el cuerpo de la PR).

**MEDIA-4 — el fixture "un artefacto que esta rama crea" era una copia.** Su contenido era el `LONG_BODY` de la base más una frase, así que con los pisos sobre el delta habría enrojecido — la fila decía "esta rama crea un artefacto" y en realidad medía "esta rama copia uno". Reescrita con contenido genuinamente distinto, y añadidas dos filas que sí cubren lo que la vieja insinuaba: una palabra añadida a la sección, y una copia bajo otro nombre más una frase. Ambas → `missing`.

**RECHAZADO-1 — "el `NR==FNR` de `delta_clears_floor` se rompe si el primer fichero está vacío".** Clásico, y aquí no aplica: la entrada es `printf '%s\n' "$1"`, que emite al menos un salto, así que el primer flujo nunca tiene cero registros. Verificado con base vacía → todo el candidato cuenta como nuevo.

**RESIDUAL-1 — el workflow ejecuta la copia del script que trae la cabeza,** así que una PR puede debilitar su propio check dentro de su propio diff. La alternativa (`pull_request_target`) es peor. El diff es el control, y queda dicho en la cabecera del workflow en esos términos.

**RESIDUAL-2 — la escotilla del servidor la escribe cualquiera que pueda abrir la PR.** Registra *por qué* una PR va sin comprobar; no restringe *quién* puede decirlo. Escrito, no insinuado.

**RESIDUAL-3 — `shell.lint` no ve un script sin rastrear.** La lista sale de `git ls-files`, así que un fichero nuevo se linta desde el commit que lo añade, nunca antes.

**RESIDUAL-4 — `extract_ack_body` casa `ADVERSARIAL_PASS_ACK=` a principio de línea aunque esa línea esté dentro de un bloque de código** en el cuerpo de la PR. Preexistente, y la escotilla es declarativa (registra una razón), así que no se toca aquí.

## Verification

| Comprobación | Resultado |
| --- | --- |
| `make shell.lint` | `✓ no findings across 20 tracked shell scripts`, exit 0 |
| `make shell.lint` con un hallazgo plantado | `Error 123`, exit ≠ 0 |
| `make bmad.adversarial.self-test` | 101 filas, exit 0 |
| mutación: pisos del delta a 1/1 | 4 filas rojas |
| mutación: `this_repo_name` → `basename REPO_ROOT` | 1 fila roja (worktree) |
| mutación: sin guarda de repositorio en CLI | 5 filas rojas |
| mutación: `segment_args` deja de respetar comillas | 5 filas rojas |
| mutación: `--base` desde texto crudo | 2 filas rojas |
| `shellcheck` sobre los 20 scripts | 0 |
| bloque `run` del workflow, tres direcciones | 1 / 0 (UNCHECKED) / 1 con `::error::` |
| `ci.yml` y `adversarial-pass.yml` | parsean; `ci-success` depende de `shell-lint` |
