# ADR — Modelado Bank/BankAccount: referencia por ID y estrategia de persistencia por agregado

> **Estado:** aceptado · **Fecha:** 2026-06-11 · **Ámbito:** `api/src/Backoffice/{Bank,BankAccount}` + guía transversal para agregados futuros.
>
> Contexto temporal: la aplicación **no está en producción**, por lo que el cambio de mapping no
> arrastra requisitos de compatibilidad hacia atrás. Aun así, ninguna decisión de este ADR
> requiere migración de esquema (ver D2).

## Contexto

`BankAccount` (módulo embrionario: entidad + `BankAccountStatus` + puerto `countByBankId`) modela
su relación con `Bank` mediante una asociación navegable:

```php
// api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php
#[ORM\ManyToOne(targetEntity: Bank::class)]
#[ORM\JoinColumn(name: 'bank_id', referencedColumnName: 'id', nullable: false)]
private Bank $bank,
```

Esto crea un **grafo de objetos cross-módulo**: el dominio de `BankAccount` importa la entidad de
dominio de `Bank`, y el acoplamiento no es solo de lectura (`getBank()->getName()`) sino **de
construcción** — crear una cuenta exige un `Bank` hidratado. La regla de aislamiento del repo
([`architecture-api.md`](../architecture-api.md), [`bounded-contexts.md`](../bounded-contexts.md))
ya prescribe referencias por ID con 3 niveles de acoplamiento *entre contextos*; este ADR la
extiende explícitamente al nivel de **módulo** dentro de un contexto.

La dirección inversa ya usa el patrón correcto: `BankDeleter` (Bank/Application) consume la
*interfaz* `BankAccountRepository` para el check de integridad referencial, con doble capa
deliberada — `countByBankId` da el 409 limpio y la FK `NOT DEFERRABLE` cubre la ventana TOCTOU
entre count y flush.

## Decisiones

### D1 — Referencia por identidad, no por objeto

`BankAccount` referencia bancos como `private string $bankId` (UUID v7 plano, consistente con los
ids del repo; `Uuid::ensure()` como guarda en el borde — **sin** VO `BankId` nuevo). Se elimina la
asociación `#[ORM\ManyToOne]` y el accesor `getBank()`. La existencia del banco al crear cuentas
se valida en Application consultando el puerto publicado del módulo Bank (espejo del patrón
`countByBankId`).

Regla general que fija esta decisión: **ningún grafo de objetos cruza fronteras de módulo**. La
asociación deja de ser "metadato pasivo" en el momento en que tipa la propiedad con la entidad de
otro módulo y habilita navegación.

Descartado: crear contextos `MasterData`/`Finance` top-level hoy (falla Rule of Three; además
[`bounded-contexts.md`](../bounded-contexts.md) ya asigna `Bank` y `BankAccount` al futuro contexto
**Finance** — la migración módulo→contexto será mecánica porque las costuras quedan cortadas).
Descartado: mantener el `ManyToOne` "prohibiendo su uso" (acoplamiento de constructor, inviable
sin enforcement).

### D2 — FK real en Postgres, preservada vía schema listener

La referencia por ID en el ORM **no implica** perder la FK física: la columna `bank_id` y su
constraint ya existen y el esquema queda **idéntico** tras el refactor (migración: ninguna). Para
que `make db.diff` no intente dropear una FK que el ORM ya no conoce, se registra un listener de
`postGenerateSchema` en `BankAccount/Infrastructure/Persistence/Doctrine` que inyecta la FK en el
schema en memoria: Doctrine queda *ORM-unaware* (dominio limpio) pero *schema-aware* (diffs
limpios, sin migración manual recurrente).

El doble check de `BankDeleter` (count para el 409 limpio + catch de
`ForeignKeyConstraintViolationException` para la carrera TOCTOU) **se mantiene tal cual**: son dos
capas con propósitos distintos (UX del error vs corrección bajo concurrencia), no consistencia
duplicada.

### D3 — Composición de lectura por proyección, no por navegación

Las respuestas que combinan datos de cuenta y banco (IBAN + `bank.name`) se componen con un JOIN
explícito en DQL dentro del repositorio Doctrine de `BankAccount` (DQL admite joins arbitrarios
sin asociación mapeada) hacia un **DTO de proyección**. Read models alimentados por eventos quedan
diferidos hasta que existan contextos o almacenes separados de verdad.

El ciclo de vida de estas *projection policies* (cuándo un enricher de lectura deja de ser policy y
debe reubicarse en un read-model explícito o materializado) se rige por los cinco invariantes de
[`../rules/read-side-projections.md`](../rules/read-side-projections.md).

### D4 — Las entidades siguen en `Domain/` con metadato pasivo

Se confirma la excepción documentada de
[`rules/architecture.md`](../rules/architecture.md): entidades en `Domain/Entity` con metadato
**pasivo de persistencia/validación** (`#[ORM]`/`#[Assert]`); prohibición absoluta de framework
*conductual* en `Domain/`. Se corrigió la contradicción que [`project-context.md`](../project-context.md)
mantenía con esa regla (decía "entities live in Infrastructure, domain objects are POPOs"). El metadato
de serializador `#[Groups]` **ya no** vive en las entidades: el contrato HTTP lo poseen DTOs de recurso
por vista (`Application/Resource/`), mapeados desde la entidad en `Infrastructure/Http/`; la entidad no
se serializa (ADR [`api-resource-dtos.md`](api-resource-dtos.md)).

Descartado hoy: modelo dual POPO+entidad (peaje de mappers/UnitOfWork sin beneficio actual) y
mapping XML/PHP externo (drift de mapping, pérdida de co-localización de invariantes).
**Triggers de revisita:** (a) segundo backend de persistencia o event sourcing en algún agregado,
(b) agregado cuyo modelo de dominio diverja del relacional (VOs compuestos multi-tabla,
jerarquías), (c) escala de equipo que justifique gate automático de pureza.

## Guía — estrategia de persistencia por agregado

**El default de ERPify es orientado a estado** (entidad Doctrine, la tabla guarda la foto actual).
**Event sourcing es opt-in por agregado, nunca global**: la fuente de verdad pasa a ser la
secuencia append-only de eventos y el estado se reconstruye rejugándolos (`apply()` como única vía
de mutación, snapshots a partir de cierto volumen, proyecciones obligatorias para toda lectura).

| Criterio                               | Orientado a estado (default)                     | Event sourcing (candidato)                                                                               |
|----------------------------------------|--------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| Qué importa al negocio                 | La foto actual                                   | La historia *es* el negocio                                                                              |
| Ejemplos                               | Catálogos, referenciales, configuración (`Bank`) | Movimientos bancarios, stock, contabilidad                                                               |
| Auditoría                              | Tabla de auditoría aparte (`StoredDomainEvent`)  | Nativa e infalsificable: el log *es* el modelo                                                           |
| Consultas temporales ("saldo a 31/03") | Reconstrucción manual / no disponible            | Gratis: rejugar hasta la fecha                                                                           |
| Correcciones                           | UPDATE sobreescribe                              | Evento compensatorio (como contabilidad real)                                                            |
| Coste                                  | Bajo (Doctrine estándar)                         | Event store, versionado/upcasting de eventos eternos, snapshots, CQRS obligatorio, consistencia eventual |

**El mismo concepto puede merecer estrategias distintas según el contexto.** Ejemplo canónico —
"cuenta bancaria":

- En el contexto **facturación** (`Invoice`), la cuenta bancaria es **dato de referencia**: muestra
  dónde transferir/pagar (IBAN, titular, banco). Solo importa la foto actual → orientado a estado
  + proyección. Es el `BankAccount` actual de `Backoffice/`.
- En un contexto **finanzas/tesorería** (balances, movimientos, conciliación), la cuenta es un
  **ledger**: el saldo se deriva de los movimientos y la auditoría es requisito de negocio → ese
  agregado concreto sería candidato a event sourcing, conviviendo con el resto del sistema sin
  cambiarlo.

**Proceso (vinculante, ver `CLAUDE.md`):** al modelar un agregado nuevo — o al extender uno hacia
un significado de negocio nuevo — se presenta esta decisión al usuario con el caso concreto
(¿hacia dónde va el agregado? ¿la historia es el negocio o solo la foto?) y las opciones con sus
costes, antes de escribir la entidad. La decisión se anota en el PR (y aquí, si crea precedente).

## Implementación

Seguimiento en el issue de GitHub correspondiente (refactor de la entidad, listener
`postGenerateSchema`, validación de existencia en Application, DTO de proyección, tests). El
worktree en vuelo `backoffice-bank-associated-accounts-dr9j` debe revisarse antes de tocar el
mapping para evitar conflictos.
