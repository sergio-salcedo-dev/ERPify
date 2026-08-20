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

1. **#800 — el registro vive en el artefacto, y el artefacto se conserva.** Descartadas: mover el registro al mensaje del commit (sobrevive al rebase y a la poda, pero desplaza la evidencia fuera del sitio donde la regla la pide) y destilarlo a `docs/` (choca con la regla de densidad: la mayoría de hallazgos son locales a la historia). Coste aceptado — crecimiento del árbol — **medido y menor de lo anunciado**: los artefactos `br-*`/`g-*` que nadie podó nunca son ~1.1 MB de ese directorio, y todo el carril `spec-*.md` son 35 KB.
2. **#783 — hook bloqueante con escotilla.** Descartadas: sólo advertir (es la prosa-con-más-pasos que ya falló tres veces) y sólo un comando `/open-pr` (opt-in, y abrir la PR por otra vía lo saltea, que es exactamente el fallo).

</frozen-after-approval>

## Code Map

| Fichero | Qué hace |
|---|---|
| `scripts/adversarial-pass-check.sh` | El instrumento. Clasificador de registro, aplicabilidad del hook, escotilla, veredicto, y `--self-test` con 34 fixtures. |
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
- Dado `make bmad.adversarial.self-test`, entonces 34 fixtures verdes; y mutar el código enrojece la fila correspondiente.

## Adversarial pass

Corrida **por el autor**, sobre el código, antes de que exista la PR. **Esto no es la lectura independiente que `CLAUDE.md` exige**: la regla pide un lector distinto del autor (contexto fresco, otro modelo o una persona), y en esta sesión tengo instrucción explícita de no lanzar subagentes sin petición del usuario. Queda declarado como lo que es —una pasada del autor, dirigida por mutación— y la lectura independiente está **pendiente**; el gate que este cambio instala comprueba la *forma* del registro, nunca su sustancia, así que un registro honesto sobre su propia limitación es exactamente lo que debe leerse aquí.

**Método:** mutar el código y exigir que una fila del `--self-test` enrojezca. Diez mutaciones intentadas, diez cazadas — **dos sólo después de arreglar el test que las dejaba pasar**.

**Defectos encontrados y corregidos (7), todos vivos en el código que ya funcionaba:**

1. **El modo hook no leía su propio payload.** Habría denegado *cualquier* `Bash`, no sólo el que abre una PR. Encontrado antes de cablear el hook.
2. **La escotilla no era alcanzable en el camino CLI.** Un proceso de hook **no hereda** el entorno del comando que vigila, así que `ADVERSARIAL_PASS_ACK=… <comando>` la anunciaba en el mensaje de denegación y nada la implementaba. Ahora el valor se extrae del **texto del comando**.
3. **El emparejamiento textual sobre-disparaba, y me bloqueó a mí.** Con la frase emparejada en cualquier posición, todo comando cuyo texto *documenta* el gate quedaba denegado — incluidos los que lo estaban escribiendo. Anclado a **posición de comando**. Coste deliberado en la otra dirección: una invocación envuelta en comillas, alias o variable pasa; eso es un bypass elegido, no un accidente.
4. **`while read` descartaba el último segmento** de una línea sin newline final — el caso común. Deshabilitó silenciosamente todo el camino `Bash` mientras el camino MCP seguía verde, que es la peor forma posible de este fallo.
5. **La rama sin comillas de `strip_assignments` tiraba el resto del segmento**, así que `FOO=1 <invocación>` no disparaba.
6. **Un ack vacío pasaba**, registrando los dos caracteres de comilla como su razón.
7. **Falso verde por sección preexistente.** Todo artefacto de historia lleva un `## Adversarial pass` de su propia historia, así que tocar uno por una errata ponía el gate en verde sin haber revisado nada. Ahora, para un fichero que ya existía en el merge base, la sección tiene que **haber cambiado**.

**Defectos del propio test (2), que es la mitad que suele no mirarse:**

- **Once filas verdes eran vacuas.** El bloque de aplicabilidad corría *antes* de que existieran las funciones que invoca: `command not found` → 127 → «no aplica», y seis filas que esperaban «no aplica» pasaban por la razón equivocada.
- **Los dos suelos de contenido no estaban fijados por separado.** Son un AND, así que toda fixture que viola uno viola el otro: bajarlos a cero uno a uno dejaba **todas** las filas verdes. Dos fixtures nuevas los aíslan.

**Punto ciego aceptado y no cerrado:** una PR abierta fuera de esta sesión —la UI web de GitHub, otra máquina, un job de CI— no la ve nadie, porque un hook `PreToolUse` sólo observa llamadas de herramienta hechas aquí. Cerrarlo pide un control del lado del servidor (una GitHub Action sobre `pull_request.opened`), que es otro cambio y otra decisión.

**Coste conocido:** el gate comprueba la **forma**, nunca la sustancia. No juzga si los hallazgos son reales, si la lectura fue hostil, ni si intervino alguien distinto del autor — y esta misma sección es la prueba de que esa distinción importa. La revisión sigue siendo el único control en esa dirección.

## Verification

```
make bmad.adversarial.self-test   → 34 fixtures verdes (clasificador 12, aplicabilidad 11, escotilla 6, veredicto 5)
make bmad.adversarial.check       → verde en esta rama por este artefacto
bash -n scripts/adversarial-pass-check.sh
python3 -c "json.load(open('.claude/settings.json'))"
make -n bmad.adversarial.check
```

`shellcheck` y `make super-lint.*` **no** corridos: requieren docker, no disponible en este contenedor. Quedan para CI.
