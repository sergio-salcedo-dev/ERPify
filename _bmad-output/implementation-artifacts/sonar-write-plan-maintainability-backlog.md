---
title: 'Write Plan de SonarCloud — backlog de mantenibilidad'
type: 'chore'
created: '2026-09-20'
status: 'draft'
spec: 'spec-sonar-maintainability-backlog.md'
---

# Write Plan de SonarCloud

Companion de [`spec-sonar-maintainability-backlog.md`](spec-sonar-maintainability-backlog.md). Vive
aparte a propósito: **el refactor local y la mutación de un sistema externo no son el mismo trabajo**, no
fallan igual y no se aprueban juntos. Este fichero es la frontera, y ninguna de sus operaciones se
ejecuta sin OK explícito de Sergio.

SonarCloud es un **sistema externo y compartido**. Esta sección es la frontera entre el refactor local y
una mutación fuera del repo; nada de aquí se ejecuta sin OK explícito de Sergio.

**Alcance:** 10 comentarios (filas 14–23) y 2 cambios de estado (filas 18–19). Nada más.

**Preflight** (sólo lectura), para las 12 issues implicadas: estado actual y comentarios existentes.
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
