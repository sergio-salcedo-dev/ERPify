---
title: 'El gate de la pasada adversarial: mecanismo, instrumento y la contradicción de reglas — #783, #799, #800'
type: 'chore'
created: '2026-08-20'
baseline_commit: '02e5debbce2a60822d4944d837b4f4cda1a59d62'
status: 'in-review'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** tres issues, un solo eje. `CLAUDE.md` exige que la pasada adversarial corra **y que sus hallazgos queden escritos** antes de que la PR exista; sólo prosa lo sostenía y falló tres veces (#616 y #620 la aterrizaron en una PR *aparte*; #770 en la misma PR, nueve minutos tarde, con el cuerpo afirmando lo contrario) — **#783**. El instrumento obvio para comprobarlo a posteriori no funciona: `%cI` lo reescribe cualquier rebase y `%aI` es asignable — **#799**. Y el registro que la regla exige vivía en un fichero que otra regla mandaba borrar al pasar a `done` — **#800**.

**Approach:** cerrar el eje entero, no tres parches. El registro se vuelve durable **conservando el artefacto** (`spec-*.md` pasa a registro vivo; `/prune-done-specs` se retira, porque `spec-*.md` era todo su alcance). El instrumento deja de reconstruir el orden a posteriori y lo **observa en el instante de la creación**, con lo que ninguna fecha entra en la decisión. Y el mecanismo es un hook `PreToolUse` que deniega, con escotilla registrada y fallo abierto ante todo lo que no pueda determinar.

## Boundaries & Constraints

**Always:** el gate falla **abierto** ante cualquier imposibilidad de determinar — nunca deja una rama atascada. Toda afirmación sobre el gate se sostiene en una mutación del código que la enrojece. El matcher cubre las **dos** superficies que abren PRs aquí (CLI y servidor MCP), o está muerto justo donde nació el fallo de #770.

**Never:** leer un timestamp para decidir. Ampliar el alcance a las otras familias de issues abiertos. Mergear a `main`.

**Ask First:** cambiar dónde vive el registro durable. Convertir el gate en bloqueante sin escotilla.

## Decisiones tomadas (humano)

1. **#800 — el registro vive en el artefacto, y el artefacto se conserva.** Descartadas: mover el registro al mensaje del commit (sobrevive al rebase y a la poda, pero desplaza la evidencia fuera del sitio donde la regla la pide) y destilarlo a `docs/` (choca con la regla de densidad: la mayoría de hallazgos son locales a la historia). Coste aceptado — crecimiento del árbol — **medido y menor de lo anunciado**: los artefactos `br-*`/`g-*` que nadie podó nunca son ~0.8 MB de ese directorio (el directorio entero son ~1.1 MB), y todo el carril `spec-*.md` son 35 KB.
2. **#783 — hook bloqueante con escotilla.** Descartadas: sólo advertir (es la prosa-con-más-pasos que ya falló tres veces) y sólo un comando `/open-pr` (opt-in, y abrir la PR por otra vía lo saltea, que es exactamente el fallo).

</frozen-after-approval>

## Code Map

| Fichero | Qué hace |
|---|---|
| `scripts/adversarial-pass-check.sh` | El instrumento. Clasificador de registro, aplicabilidad del hook, escotilla, veredicto, y `--self-test` con 75 fixtures. |
| `.claude/settings.json` | Hook `PreToolUse`, matcher `Bash\|.*create_pull_request`. |
| `make/bmad.mk` | `bmad.adversarial.check` y `bmad.adversarial.self-test`. |
| `.claude/commands/prune-done-specs.md` | **Borrado.** `spec-*.md` era todo su alcance. |
| `CLAUDE.md` | Tres reglas: el artefacto es durable, el gate existe (con sus puntos ciegos), y una bala en *Required checks*. |
| `docs/claude-code-quickref.md` | Sección *Adversarial-pass gate*. |
| `…/spec-gh-771-772-773-*.md` | Corregidas dos afirmaciones que enunciaban como resolución el instrumento que #799 refuta. |

## Tasks & Acceptance

- Dada una rama sin registro, cuando algo intenta abrir una PR, entonces el hook **deniega** nombrando las dos formas de registrarlo. Medido en las dos superficies.
- Dada una rama que toca un artefacto cuya sección de pasada **es anterior a la rama**, entonces el veredicto es `missing`. (Era un falso verde: todo artefacto de historia en este árbol lleva su propia sección.)
- Dado `ADVERSARIAL_PASS_ACK="<razón>"` en el comando, entonces procede **y** emite la razón. Un valor vacío o sólo-espacios **no** pasa.
- Dado cualquier estado indeterminable, entonces exit 0 y nada bloquea.
- Dado `make bmad.adversarial.self-test`, entonces 75 fixtures verdes; y mutar el código enrojece la fila correspondiente, incluidas las mutaciones que reinstauran las implementaciones originales.

## Adversarial pass

**Independiente, dos capas en paralelo, contexto fresco y sólo-lectura, antes de que exista la PR.** Ninguna participó en escribir el código. Capa A atacó las *afirmaciones* del cambio (bypass, falso verde, falso rojo, prosa contra código); capa B fue cazadora de casos límite sobre el shell (nombres de fichero, estados de git, entorno, el propio `--self-test`). Entre las dos, **cuatro GRAVE y once MEDIUM**, con reproducción medida en cada uno.

**No confirmó el cambio: lo reescribió.** El instrumento pasó de 708 a ~890 líneas y de 34 a **75** fixtures.

### Lo que derribó, y era explotable en un comando

1. **Un registro sin commitear ponía el gate en verde.** Un fichero que existe sólo en el working tree —nunca añadido, nunca commiteado— satisfacía el testigo del artefacto. Es **#770 reproducido exactamente**: el head desde el que se abre la PR no contiene el registro. Mi comentario defendiéndolo («un registro sin commitear sigue siendo un registro que el humano puede commitear antes de la PR») se autorrefuta en el único instante en que este script corre, que es *en* la creación. Y dispara en el orden **natural** —escribir el artefacto, abrir la PR, commitear— no en el de un adversario. Ahora los candidatos salen sólo de `git diff <merge-base>...HEAD`; un registro sin commitear produce una denegación que **lo nombra** y dice que lo commitees.
2. **El testigo del trailer no tenía suelo.** `git commit --allow-empty -m "Adversarial-pass:"` ponía todo el gate en verde. El testigo del artefacto exigía 3 líneas y 200 caracteres precisamente porque «un encabezado solo no es un registro», y el otro aceptaba dos puntos sin nada detrás — una asimetría es un agujero, porque el testigo sin suelo es siempre el más barato de falsificar. Además `--grep='^Adversarial-pass:'` casaba un commit de documentación que **citaba** la clave a mitad del cuerpo. Ahora el valor se lee con el parser de trailers de git (así una cita a mitad de mensaje no es un trailer) y exige 40 caracteres.
3. **Un espacio en blanco derrotaba la guarda de «la sección ha cambiado».** Las dos funciones awk gemelas coincidían exactamente en *dónde* empieza la sección —capa B lo falsó con 4000 documentos aleatorios: 0 discrepancias— pero no en *qué* contaba cada una: una comparaba líneas crudas y la otra contaba contenido normalizado. Un espacio final, una línea en blanco o un sub-encabezado vacío leían como cambio, y eso lo hace cualquier formateador de Markdown sin que nadie lo pida. Ahora hay **un solo** programa awk que emite las líneas normalizadas, y las dos preguntas se responden con él.
4. **Mi propio `--self-test` daba verdes vacuos sobre un repositorio con cero commits.** Bajo un `commit.gpgsign=true` global —o un `core.hooksPath` que bloquee— cada `git commit` de la fixture fallaba en silencio, el repo acababa sin commits, el script respondía correctamente `undetermined`, y mi ayudante de veredicto leía el código de salida 0 como `record`: **tres filas en verde sobre un repositorio vacío**. Es exactamente la clase de defecto que `CLAUDE.md` cita como el GRAVE de #618 —«un test afirmando un invariante sobre una semilla que insertó cero filas»— reproducida dentro del arnés de falsación del gate escrito para impedirla. Ahora la fixture fuerza `commit.gpgsign=false` y `core.hooksPath=/dev/null`, **afirma que sus commits existen**, y el veredicto se lee del token impreso, no del código de salida.

### Lo demás que cerró

- **Una plantilla pasaba por registro:** un `## Adversarial pass` dentro de un bloque cercado ```` ``` ```` contaba. Es la misma clase que ya había arreglado en la aplicabilidad, con el signo cambiado: *mostrar* la forma la ejecutaba. Los dos programas awk ahora siguen el estado de la cerca.
- **Un rename o una copia** de la sección de otra historia contaba como evidencia nueva. Ahora se compara la huella normalizada contra **todas** las secciones presentes en la base, lo que cubre rename, copia y movimiento parcial con una sola regla.
- **La escotilla se emparejaba en cualquier parte del comando.** Un `--body` que citaba el mensaje de denegación se auto-acknowledgeaba, registrando `"<reason>"` como razón — así que el rastro en el que el diseño se apoya no registraba nada. Ahora sólo se lee como prefijo de asignación **en el segmento que abre la PR**, y en la superficie MCP sólo a principio de línea del cuerpo.
- **Falsos rojos que refusaban el acto de registrar.** El splitter partía en cualquier separador y en cualquier newline, así que `git commit -m "a; <invocación>"`, un mensaje de commit multipárrafo y un heredoc que documenta el gate quedaban **denegados**. El splitter ahora respeta comillas y salta cuerpos de heredoc.
- **Bypasses en posición de comando** que la prosa no admitía: `gh api …/pulls` (la ruta REST documentada), ruta absoluta, `sudo`, `command`, `exec`, `nohup`, `time`, `\gh`, subshell y grupo de llaves. Añadidos. La prosa de `CLAUDE.md` pasó de enumerar un bypass —leyéndose como exhaustiva— a decir que es una **lista nombrada de deletreos**: suelo para accidentes, nunca techo para intenciones.
- **Tres formas de matar el gate en silencio:** un `CDPATH` puesto rompía `SCRIPT_DIR` (y las dos invocaciones cableadas usan la forma vulnerable); `GIT_DIR`/`GIT_WORK_TREE` heredados vencían a `git -C` y emitían un veredicto sobre **otro repositorio**, en ambas direcciones; y `--base-ref` sin valor giraba al 100 % de CPU para siempre, mientras `--base-ref --strict` se tragaba el flag y desactivaba la estrictez sin decirlo.
- **Rutas con comillas o UTF-8** salían de `git diff --name-only` entrecomilladas y no casaban `*.md`, así que una rama con registro real era denegada. Todo lo que lee rutas usa ahora `-z`.
- **Números míos mal medidos:** «29 fixtures» (eran 34, ahora 75) y «~1.1 MB de `br-*`/`g-*`» (eso es el directorio entero; ese carril son ~0.8 MB). Corregidos en los dos ficheros más leídos del repo.

### Diferido, con issue

- **El punto ciego que ninguna de las dos capas pudo cerrar aquí:** una PR abierta fuera de esta sesión —UI web, otra máquina, un job de CI— no la ve nadie, porque un hook `PreToolUse` sólo observa llamadas de herramienta hechas aquí. Cerrarlo pide un control del lado del servidor, que no puede correr en el instante de la creación y por tanto es estrictamente más débil — **#815**.
- **Precisión residual:** la guarda de sección cambiada no tiene suelo sobre el *delta* (una palabra nueva basta), y `--repo otro/dueño` en la ruta CLI se juzga contra este checkout (la ruta MCP ya lo trata como no-aplicable) — **#816**.

### Rechazado

- Bajar la aplicabilidad a un parser de shell completo. El coste es un tokenizador que mantener, y el beneficio es cero contra un adversario —que siempre puede usar `curl`— mientras que el suelo contra accidentes ya lo dan las comillas y los heredocs. La honestidad de la prosa hace el trabajo que la precisión no puede.

### Coste conocido

El gate comprueba la **forma**, nunca la sustancia. No juzga si los hallazgos son reales, si la lectura fue hostil, ni si intervino alguien distinto del autor. La revisión sigue siendo el único control en esa dirección — y esta sección existe porque, en la primera versión de este mismo artefacto, la pasada era del autor y lo decía.

## Verification

```
make bmad.adversarial.self-test   → 75 fixtures verdes
                                     (clasificador 16, huella 5, aplicabilidad 21+12, escotilla 13, veredicto 10)
make bmad.adversarial.check       → verde por el trailer del commit, que es el testigo comprobado primero
bash -n scripts/adversarial-pass-check.sh
python3 -c "json.load(open('.claude/settings.json'))"
make -n bmad.adversarial.check
```

Mutación: 15 mutaciones aplicadas al código y revertidas, incluidas tres que reinstauran las implementaciones **originales** (el barrido de la escotilla por subcadena, el splitter ciego a comillas, la unión con el working tree) — las tres enrojecen. El `--self-test` corre además limpio bajo `commit.gpgsign=true`, `core.hooksPath` bloqueante, `init.defaultBranch=trunk` y sin identidad global; con `TMPDIR` inservible salta el bloque de veredicto y **la línea de resumen dice que no lo cubre** en vez de afirmarlo.

`shellcheck` y `make super-lint.*` **no** corridos: requieren docker, no disponible en este contenedor. Quedan para CI.
