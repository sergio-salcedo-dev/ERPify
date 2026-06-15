# ADR — Dependencias externas en Domain/Application: PSR interface-only vs frameworks

> **Estado:** aceptado · **Fecha:** 2026-06-15 · **Ámbito:** `api/src/**/Domain`, `api/src/**/Application` — guía transversal para toda dependencia externa de las capas internas.
>
> Disparador: el PR #299 introdujo un puerto `Shared/Domain/Logging/Logger` (+ `LogLevel`, `NullLogger`, adaptador `PsrLogger`, wiring DI, test de contrato y docs) que envolvía `Psr\Log\LoggerInterface` 1:1. La pregunta de fondo no es el Logger, sino qué precedente sienta para `Clock`, `Cache`, `EventBus`, `MessageBus`, etc.

## Contexto

[`../rules/architecture.md`](../rules/architecture.md) prescribe `Domain/` framework-free con dependencias hacia dentro, y ya lista "PSR standards compliance" como **objetivo** arquitectónico (no como veto). Existe un precedente explícito: `symfony/uid` se admite en `Domain/` por ser *"a leaf component with no coupling to the framework runtime"*.

El vacío que cubre este ADR: nunca se decidió si un **contrato PSR interface-only** (`psr/log`, `psr/cache`, `psr/http-message`…) cuenta como dependencia prohibida o como contrato permitido. El PR #299 lo resolvía de facto — vía un wrapper — sin decidir la política. Eso arriesga una capa `Shared/` poblándose de envoltorios isomórficos (Clock, EventDispatcher, Cache, Collections…) cuyo coste de mantenimiento acumulado es real y cuyo beneficio es nulo cuando el estándar ya *es* el puerto.

## Decisiones

### D1 — Tres categorías de dependencia externa; el discriminante es runtime, no vendor

- **Cat-1 — Frameworks / implementaciones con runtime:** Symfony (salvo `uid`), Doctrine, Monolog, Messenger, API Platform, Inversify, clientes HTTP, `doctrine/collections`. **Prohibidos** fuera de `Infrastructure/`. Estricto.
- **Cat-2 — Contratos de interoperabilidad interface-only:** `psr/log`, `psr/cache`, `psr/http-message`, `psr/event-dispatcher`, `psr/clock`, `psr/container` (como contrato). **Permitidos** en `Domain/Application` con criterio (ver D2).
- **Cat-3 — Tipos de valor neutrales:** `symfony/uid` (precedente vigente, [`../rules/architecture.md`](../rules/architecture.md)). **Permitidos.**

La línea divisoria **no** es "¿es de un vendor?" sino **"¿el paquete trae runtime/comportamiento, o es solo un contrato (interfaces) sin implementación ni dependencias transitivas de framework?"**. Un paquete interface-only no introduce acoplamiento de runtime: es *más* puro que `symfony/uid`, que sí trae código.

Descartado: **"cero third-party en Domain"** — incoherente con el `uid` ya aceptado y con el objetivo PSR de la regla; convierte cada estándar estable en un wrapper a mantener para siempre.

### D2 — No envolver 1:1; crear puerto solo cuando hay reshape semántico

Un contrato Cat-2 se consume **directo** cuando: (a) no tiene runtime propio, (b) expresa exactamente el contrato que el dominio necesita, y (c) el dominio no necesita restringirlo ni remodelarlo. Si el dominio necesita **semántica propia, restricciones añadidas o un lenguaje ubicuo distinto**, entonces — y solo entonces — se crea un puerto de dominio.

Un puerto que es *pass-through* byte a byte de un estándar permitido es abstracción-sobre-abstracción: añade superficie (interfaz + null object + adaptador + DI + test de contrato + docs) sin ganancia semántica. Regla de Tres: se abstrae ante variación real, no ante una hipótesis ("cambiar PSR-3 algún día" nunca ocurre, porque PSR-3 *es* la capa que aísla del sink).

| Dependencia        | Decisión                          | Por qué                                                                              |
|--------------------|-----------------------------------|--------------------------------------------------------------------------------------|
| `psr/log`          | **Directo**                       | El dominio necesita exactamente el contrato PSR-3. Sin reshape.                       |
| `psr/cache`        | **Directo**                       | Contrato neutro suficiente.                                                          |
| `psr/http-message` | **Directo**                       | Contrato neutro suficiente.                                                          |
| Clock              | **Puerto propio válido**          | "Now" es concepto de dominio; se quiere seam de test + contrato estrecho (`SystemClock`). |
| EventBus           | **Puerto propio válido**          | Los domain events DDD quieren semántica propia, no PSR-14 crudo.                      |
| MessageBus         | **Puerto propio obligatorio**     | No hay PSR; Symfony Messenger es Cat-1.                                              |
| Monolog            | **Solo Infrastructure**           | Implementación con runtime.                                                          |
| Doctrine           | **Solo Infrastructure**           | Implementación con runtime.                                                          |

### D3 — Consecuencia para el PR #299: cerrar el wrapper, adoptar `psr/log` directo

El `Logger` de #299 era isomórfico a PSR-3 (mismos métodos, semántica, contexto y comportamiento). La única diferencia, `LogLevel`, no justificaba la cadena `Logger`/`NullLogger`/`PsrLogger`/alias/tests/docs: los call-sites usan `->info()/->warning()`, nunca `->log(LogLevel::…)`, así que el enum tampoco resolvía un problema existente. Se revierte el wrapper y los cuatro call-sites vuelven a `Psr\Log\LoggerInterface` directo (incluido el autowire de `monolog.logger.observability` en `SearchObservabilityListener`). El pin NFR5 de la ruta de error (`ProblemDetailsFactory`, `ExceptionResponder`, vía `LoggerInterfaceContractTest`) deja de ser excepción y pasa a ser la norma.

**Regla de tipos auxiliares:** los tipos asociados a un wrapper eliminado (aquí `LogLevel`) se eliminan también, salvo que tengan consumidores reales o expresen una semántica de dominio independiente — ninguno aplica. Si en el futuro aparece una necesidad real de niveles tipados, `LogLevel` es un enum de un fichero que se añade entonces.

### D4 — Enforcement

Allowlist explícita de namespaces permitidos en `Domain/Application` (patrón hermano de `api/.bounded-context-allowlist`), enforced por un gate de lint al estilo de `LoggerInterfaceContractTest` / `BannedDoctrineApisTest`: falla si una capa interna importa un vendor fuera de Cat-2/Cat-3. La prohibición de "puerto pass-through 1:1 sobre un PSR permitido" (D2) queda como revisión humana documentada aquí — difícil de automatizar de forma fiable. La excepción se añade en [`../rules/architecture.md`](../rules/architecture.md), hermana de la de `symfony/uid`.

## Implementación

Reversión del wrapper de #299 y este ADR aterrizan juntos en la rama del propio PR. El gate de lint de la allowlist (D4) queda como follow-up rastreado en el issue **#301** (prioridad baja-media, no bloqueante): hasta que exista, la regla se sostiene por revisión humana y por este ADR.
