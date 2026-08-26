---
title: 'El hook de la pasada adversarial resolvía su REPO_ROOT desde BASH_SOURCE, no desde el cwd real del tool call'
type: 'fix'
created: '2026-08-26'
baseline_commit: '3f1ad4fe7b05edbf9d9087474e1236d2b6e71fe1'
status: 'in-review'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** tres subagentes distintos, en tres ramas distintas, el mismo día, midieron el mismo falso rechazo: `gh pr create` denegado por "no adversarial-pass record" desde dentro de un worktree cuya rama SÍ llevaba el registro (`make bmad.adversarial.check` a mano, desde ese mismo worktree, pasaba en verde). `scripts/adversarial-pass-check.sh` resuelve su propio `REPO_ROOT` con `dirname "${BASH_SOURCE[0]}"` — correcto sólo si el cwd del PROCESO del hook coincide con el cwd de la llamada que gatea. No coincide: el hook `PreToolUse` que la sesión invoca corre con un cwd que no sigue al worktree, así que un `gh pr create` lanzado desde un worktree enlazado se juzga contra el estado de la rama del checkout PRIMARIO. Documentación oficial confirmada (`code.claude.com/docs/en/hooks.md`, sección "Common input fields" / "Worktrees are different"): el payload JSON de stdin lleva un campo `cwd` de nivel superior que "sigue a Claude" dentro de un worktree y tras un `cd`, mientras que `${CLAUDE_PROJECT_DIR}` se queda fijo en el checkout original — exactamente al revés de lo que este script necesitaba y no tenía.

Un segundo bug, encontrado mientras dos de los tres subagentes usaban la escotilla `ADVERSARIAL_PASS_ACK=` para rodear el primero: un prefijo `ADVERSARIAL_PASS_ACK=...` escrito en su propia línea física, envuelto con una barra invertida al final (la forma que `usage()` recomienda para un comando largo), no se reconocía — la barra invertida al final de línea hacía que `split_segments` cerrara el segmento ahí mismo, así que el prefijo con la escotilla y el `gh pr create` acababan en dos segmentos distintos y la escotilla se perdía en silencio.

**Approach:** el `REPO_ROOT` por defecto (resuelto vía `BASH_SOURCE`) se queda como estaba — sigue siendo la respuesta correcta para cualquier invocación directa (`make bmad.adversarial.check`, `--self-test`, el job de CI, que nunca usan `--hook`). Sólo el modo `--hook` lo corrige: lee `.cwd` del payload, y si nombra un directorio real que (a) es la raíz de algún work tree de git, (b) aparece literalmente como entrada `worktree <path>` en el `git worktree list --porcelain` de ESTE checkout, y (c) tiene contenido real en disco más allá de su propio `.git`, usa esa raíz como `REPO_ROOT`; en cualquier otro caso se queda con el valor por defecto — nunca confía ciegamente en una cadena de un payload no confiable. (b) y (c) sustituyen una primera versión que sólo comparaba el directorio COMÚN de git — una pasada adversarial la falsificó con un fichero `.git` de una línea; ver más abajo. El bug de la escotilla se corrige en `split_segments`: una barra invertida al final de una línea física, fuera de comillas simples, ahora elide la barra y el salto de línea que la sigue en vez de cerrar el segmento — exactamente la continuación de línea que la propia shell hace.

## Boundaries & Constraints

**Always:** el `REPO_ROOT` derivado del payload se valida contra el registro real de `git worktree list` de ESTE checkout, más contenido real en disco, antes de usarse — nunca se confía en el string crudo ni en una prueba de identidad más débil. Cualquier otro modo (`--self-test`, `--strict`, `--ack-from-body`) sigue usando exactamente la resolución `BASH_SOURCE` de siempre, sin tocar.

**Never:** tocar qué cuenta como registro válido (testigos de commit trailer / artefacto) — fuera de alcance. Debilitar el fallo-abierto existente.

**Ask First:** cambiar el mecanismo del hatch (`ADVERSARIAL_PASS_ACK`) más allá de arreglar el parseo de la continuación de línea.

</frozen-after-approval>

## Code Map

| Fichero | Qué hace |
|---|---|
| `scripts/adversarial-pass-check.sh` | `REPO_ROOT` deja de ser `readonly`; `hook_repo_root_from_payload()` nueva (valida vía `git worktree list --porcelain` + contenido real, no vía directorio común); el caso `hook)` re-resuelve `REPO_ROOT` antes de `hook_applies`/`decide`; `split_segments` trata una barra invertida final como continuación de línea; 24 fixtures nuevas en `--self-test` (101 → 124 filas contadas, incluida la línea de resumen). |

## Tasks & Acceptance

1. **Investigar el canal real** — confirmado independientemente por un subagente `claude-code-guide` (leyó `code.claude.com/docs/en/hooks.md`) y por lectura directa de los strings embebidos en el binario instalado (`/home/dev/.local/share/claude/versions/2.1.246`): el payload de un hook `PreToolUse` lleva `cwd` de nivel superior, y ese campo sigue al tool call dentro de un worktree y tras un `cd`. No hay variable de entorno equivalente.
2. **Arreglar `REPO_ROOT`** — hecho, sólo en el modo `--hook`, con validación por membresía real en `git worktree list` más contenido en disco (endurecido tras la pasada adversarial — ver abajo).
3. **Arreglar el parseo de `ADVERSARIAL_PASS_ACK`** — hecho en `split_segments`.
4. **Probar rojo-antes / verde-después** — medido con el script ORIGINAL (`git show HEAD:scripts/...`) contra una fixture con un worktree que sí lleva un trailer válido: el script viejo deniega (falso rechazo reproducido exactamente); el script arreglado, con el mismo payload, se calla (registro encontrado a través del `cwd` que el payload nombra).
5. **`make bmad.adversarial.self-test` completo** — 124 filas, 0 fallos (101 → 124).
6. **`make shell.lint`** — 0 hallazgos sobre 20 scripts rastreados (`shellcheck 0.11.0`, descargado del release oficial porque el host no lo traía instalado).
7. **`make bmad.adversarial.check` desde este mismo worktree** — resuelve correctamente contra ESTE checkout (denegado hasta que esta rama lleve el registro; se vuelve verde en cuanto este spec se commitea con esta sección).

## Adversarial pass

Subagente independiente, contexto fresco, sólo lectura, lanzado antes de abrir la PR — pregunta concreta: ¿puede spoofearse la nueva resolución de `cwd`, o el fallback admite un directorio que no es realmente este repositorio? No participó en escribir el código.

**GRAVE encontrado, y cerrado en dos de tres vectores.** La primera versión de `hook_repo_root_from_payload()` confiaba en "comparte el directorio COMÚN de git" (`git rev-parse --git-common-dir`) como prueba de "es un worktree genuino de este repositorio" — y esa prueba es falsificable con un solo fichero, medido en dos formas independientes:

1. **Fichero `.git` fabricado.** Un directorio vacío con un `.git` de una línea (`gitdir: <repo>/.git/worktrees/<nombre>`, la ruta interna que git ya usa y que cualquier checkout puede leer) reporta el MISMO directorio común que el worktree real al que apunta, y `git status` ahí muestra la rama y el HEAD de ese worktree — sin haber pasado nunca por `git worktree add`. Medido: aceptado como `REPO_ROOT` por la versión anterior de la función.
2. **Worktree `--no-checkout`.** Un worktree genuino, registrado por git, pero sin nada en disco salvo su propio `.git` — `decide()` lee el directorio de artefactos directamente del filesystem, así que uno vacío responde "undetermined" (el fallo-abierto que este propio gate diseña a propósito), lo que convertía "un worktree real sin nada checked-out" en un pase libre.

**Cierre, verificado independientemente sobre las dos fixtures del hallazgo:** la validación ya no compara el directorio común — exige que el candidato aparezca, literal, como una entrada `worktree <path>` en el `git worktree list --porcelain` de ESTE checkout (el registro que git mantiene bajo el directorio común, al que un fichero `.git` suelto nunca se une), y además que tenga contenido real más allá del puntero (`find -mindepth 1 -maxdepth 1 ! -name .git`). Ambos PoC, reconstruidos contra la función corregida, caen ahora al `REPO_ROOT` por defecto (deniegan, porque este propio checkout aún no lleva registro). Pinnado en `--self-test`: "a hand-written .git file aliasing the worktree is refused as REPO_ROOT" y "a registered --no-checkout worktree with nothing on disk is refused as REPO_ROOT".

**Un tercer vector, medido y dejado abierto a propósito, con la medición que lo acota.** `cwd` nombra el directorio de ARRANQUE del comando, no dónde acaba tras su propia ejecución — un comando que embebe su propio `cd <otro-worktree> && gh pr create` cambia de directorio DESPUÉS de que este hook ya respondió, y ningún hook `PreToolUse` puede simular eso. Cerrarlo rechazando el `cwd` del payload cuando un segmento anterior cambia de directorio se consideró y se descartó: la forma ORDINARIA del bug que este mecanismo existe para arreglar es exactamente `cd <worktree-path> && gh pr create` en una sola línea — la manera natural de asegurarse de estar en el directorio correcto antes de abrir la PR, e incluye cómo es probable que esta misma PR se abra — así que rechazarlo ahí reinstauraría el bug en el caso común para defender uno más estrecho y deliberado. Medido para acotar el residuo, no para cerrarlo: el código VIEJO (sólo `BASH_SOURCE`) ya leía el estado del PRIMARIO para cualquier llamada originada en un worktree, con o sin `cd` alguno, y un primario limpio sobre su propia rama base (el caso ordinario) ya respondía "undetermined — nothing to review" y se callaba, independientemente del registro real del destino — reproducido con el script pre-diff sobre una fixture con el primario limpio en `main` y un worktree sin registro: el hook se calla igual (el "allow" silencioso YA existía antes de este diff). Este mecanismo estrecha ese hueco para el caso que coincide con cómo el bug se presentó de verdad; no cierra el caso general, que pertenece a la misma categoría que este propio fichero ya acepta en su cabecera ("an invocation this splitter cannot see... is not matched. The enumeration is a floor on accidents, never a ceiling on intent").

**Aparte, sin hallazgos explotables:** el fix de continuación de línea en `split_segments` (barra invertida al final de una línea física, fuera de comillas simples) se verificó contra la semántica real de bash en los casos relevantes (barra simple, doble, triple, dentro de comillas simples vs dobles) y no abre ninguna vía de "smuggling" en ninguna dirección — un caso que el código VIEJO manejaba mal en la dirección seguro-por-accidente (`gh \`+salto de línea+`pr create` partido en dos segmentos, evadiendo la detección) ahora se detecta correctamente. La resolución de `REPO_ROOT` fuera del modo `--hook` (report, `--strict`, `--base-ref`, `--self-test`, `--ack-from-body`) es bit-idéntica a la versión anterior — ningún otro punto del fichero escribe `REPO_ROOT` salvo el único sitio gateado por `[[ -n "${hook_cwd_root}" ]]` dentro del caso `hook)`.

## Verification

```
bash -n scripts/adversarial-pass-check.sh
make shell.lint                    → 0 hallazgos, 20 scripts
make bmad.adversarial.self-test    → 124 filas, 0 fallos
make bmad.adversarial.check        → correcto contra ESTE worktree (bootstrapping probado)
```

Reproducción rojo/verde con el script original vs el arreglado, sobre una fixture con worktree real: ver Tasks & Acceptance #4. Los dos PoC de la pasada adversarial (fichero `.git` fabricado, worktree `--no-checkout`) reproducidos a mano contra el script original y contra el arreglado, y pinnados como fixtures del `--self-test` — ver la sección Adversarial pass.
