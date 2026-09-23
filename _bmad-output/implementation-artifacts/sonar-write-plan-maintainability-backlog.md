---
title: 'Write Plan de SonarCloud — backlog de mantenibilidad'
type: 'chore'
created: '2026-09-20'
status: 'done'
spec: 'spec-sonar-maintainability-backlog.md'
---

# Write Plan de SonarCloud

> **Ejecutado el 2026-09-22.** 11 comentarios y 3 transiciones `FALSE_POSITIVE → ACCEPTED`, con preflight
> (0 comentarios en las 11, estados cuadrando) y read-back por issue. Estado final medido: **16 ACCEPTED +
> 7 FALSE_POSITIVE = 23**, once con exactamente un comentario, **ninguna duplicada**, y 0 OPEN/CONFIRMED en
> todo el proyecto. Las doce restantes siguen sin comentario a propósito: son las arregladas en código y las
> cierra SonarCloud al re-analizar.
>
> **Un detalle operativo que el plan no preveía**: la única transición disponible sobre una issue resuelta es
> `reopen`, así que `FALSE_POSITIVE → ACCEPTED` son dos pasos y entre ellos la issue queda OPEN. Se ejecutó
> con read-back intermedio y con el aborto armado para ese hueco; el comentario viaja en el `accept`, que
> admite `comment`, de modo que ninguna issue recibe dos escrituras de texto.

Companion de [`spec-sonar-maintainability-backlog.md`](spec-sonar-maintainability-backlog.md). Vive
aparte a propósito: **el refactor local y la mutación de un sistema externo no son el mismo trabajo**, no
fallan igual y no se aprueban juntos. Este fichero es la frontera, y ninguna de sus operaciones se
ejecuta sin OK explícito de Sergio.

SonarCloud es un **sistema externo y compartido**. Esta sección es la frontera entre el refactor local y
una mutación fuera del repo; nada de aquí se ejecuta sin OK explícito de Sergio.

**Alcance:** **11 comentarios** (filas 6 y 14–23) y **3 cambios de estado** `FALSE_POSITIVE → ACCEPTED`
(filas 6, 18 y 19). Son 11 issue keys en total — las filas 18 y 19 están DENTRO del rango 14–23, así que
contarlas aparte daba 12 y mandaba al operador a buscar dos keys que no existen.

**Preflight** (sólo lectura), para las 11 issues implicadas: estado actual y comentarios existentes.
La precondición está **medida a 2026-09-19: las 23 tienen cero comentarios**, así que un comentario
presente al llegar es una señal de que alguien intervino — se para y se pregunta, no se añade encima.

**Aprobación:** `tmp/sonar-justifications.md` (gitignored) lista, por issue: issue key · estado actual ·
estado deseado · **cuerpo exacto** del comentario · operación · motivo. Sin OK sobre ese fichero no hay
ningún `POST`.

**Idempotencia, sin marcador en el texto.** El marcador se descarta a propósito: ensucia para siempre un
texto que leen personas, y el problema que resolvería ya lo cierra la medición anterior. La garantía es:
no se escribe si la issue ya tiene comentario, y no se repite un estado ya aplicado. **Jamás se borra ni
se edita un comentario ajeno.**

**Read-back obligatorio:** tras cada write se vuelve a consultar la issue y se verifica el estado real.
Que la API acepte el `POST` no es prueba de nada. Un read-back que no confirme lo esperado **aborta el
resto de la secuencia** y se reporta; no se reintenta a ciegas.

**Fuente de verdad:** SonarCloud. El `.md` queda sólo como registro local de la intención aprobada.
