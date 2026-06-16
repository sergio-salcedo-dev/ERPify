# ADR — Diseño de enums de dominio y separación de i18n

> **Estado:** propuesto (→ aceptado al aterrizar el contract swap) · **Fecha:** 2026-06-16 · **Ámbito:** todos los enums de `*/Domain/Enum/`; primera migración `api/src/Backoffice/BankAccount` + sync `pwa`.
>
> Contexto temporal: la aplicación **no está en producción**, así que el cambio de contrato de
> wire y de persistencia no arrastra compatibilidad hacia atrás. Aun así el flip es **atómico**:
> el `enumType` de Doctrine ata el backing del enum a los bytes de la columna (ver D6), no hay
> fase híbrida posible.

## Contexto

`BankAccountStatus` arrastra una infraestructura genérica de "enum legible":

```php
// api/src/Shared/Domain/Enum/Abstraction/
HumanReadableIntEnumInterface   // contrato getLabel()/getLabels()/fromLabel()
HumanReadableIntEnumTrait       // resolución de label por reflexión + atributo, cacheada
HumanReadableIntEnumValue       // #[…(label: 'active')] por case
```

La evidencia desmiente el nombre. El único consumidor de dominio es `BankAccount::getStatusLabel()`,
que serializa la "label" `'active'` bajo la clave `status`; y la PWA (`BankAccountsTable.tsx`)
**ya** reimplementa su presentación (`STATUS_VARIANT` + title-casing). O sea: la label del dominio
no llega al usuario como texto humano — llega como **código de wire** (`'active'`) que el front
re-mapea, y en un ERP español produce *"Active"*, no *"Activa"*. La pieza que justifica todo el
aparato `HumanReadable*` ni siquiera cumple su nombre. De los 7 métodos del trait solo se usan 2;
`fromLabel`/`fromLabelOrFail`/`getKeysFromValues`/`getValues`/`getValuesNotIn` son código muerto.
`Currency` (`enum Currency: string { case EUR = 'EUR'; }`) ya es el patrón anémico correcto que
este ADR generaliza.

El problema de raíz no es el trait ni la reflexión: es **presentación filtrándose al dominio**.

## Decisión

### D1 — El enum de dominio es identidad pura

Un enum de dominio representa identidad de negocio estable, no texto. Prohibido dentro del enum:
labels legibles, formateo de UI, strings de i18n, resolución de label por reflexión.

### D2 — El contrato de wire es `->value`

El `value` del enum **es** el contrato público, en `SCREAMING_SNAKE_CASE`:

```php
enum BankAccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case CLOSED = 'CLOSED';
}
```

Coincide con `->name`, elimina la ambigüedad mayúscula/minúscula y evita transformación implícita
en el front. El `value` es inmutable: cambiarlo es romper el contrato (igual que renumerar un int).
*Alternativa descartada:* `->name` directo — gratis, pero acopla la API pública a cómo se deletrea
el identificador PHP; un `value` explícito desacopla contrato de identificador.

### D3 — La API serializa `->value`, nunca labels

El serializer expone `$status->value` (Symfony emite `->value` para todo `BackedEnum`). Nunca
`getLabel()` ni getters derivados. Salida: `{ "status": "ACTIVE" }`.

### D4 — La presentación vive fuera del dominio (regla anti-regresión generalizada)

El texto legible es responsabilidad de la capa de presentación, **tecleado por `->value`**:

- **PWA** → diccionarios i18n (`t(\`bankAccountStatus.${status}\`)`) y mapeo de UI (badges, colores).
- **Backend que deba localizar** (PDF, email, exportaciones) → catálogo i18n en
  `Application`/`Infrastructure`, **nunca** de vuelta en el enum. La identidad es la clave; la
  traducción es un adaptador de presentación, exista en PWA o en un adaptador de la API.

Esta regla **no es solo de enums**: cierra el vector de recaída completo. Ningún tipo de `Domain/`
(enum, Value Object, entidad) ni ningún DTO/mapper de `Application/` puede contener texto de
display, formateo o localización "para simplificar el front". El día que un VO tenga `format()` o
un mapper arrastre una label, es el mismo error con otro nombre.

### D5 — Backing por agregado (no global)

Espejo de la estrategia de persistencia por agregado del repo
([`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)):

- **Default: string-backed**, `value == código de wire`. DB autodescriptiva, resiliente a añadir
  casos, sin maquinaria.
- **Excepción: int/`smallint`-backed** SOLO en agregados *hot-path / alta cardinalidad* con presión
  real de volumen, escritura e indexación (no roadmap abstracto). El roadmap nombra los candidatos
  —*stock movements*, *asientos automáticos* de la Finance Layer— pero ninguno está shipped ni a
  corto plazo, así que la excepción existe en el modelo sin dominar el default. Un enum `int`-backed
  expone su código de wire vía `value` string solo si necesita desacoplarlo del número.

### D6 — Reglas de negocio dentro del enum, presentación fuera

La línea divisoria no es "anémico vs rico", es **texto-de-display-FUERA vs reglas-de-negocio-DENTRO**.
Permitido en el enum: predicados e invariantes (`isTerminal()`, `canTransitionTo()`), transiciones
de estado. Prohibido: formateo, localización, labels.

## Consecuencias

**Positivas:** separación de responsabilidades alineada con DDD/hexagonal; única fuente de verdad de
i18n; dominio más simple; cero reflexión; contrato de API explícito y estable.

**Negativas:** cambio de contrato de API que exige sync coordinado del front en el mismo PR;
migración de datos `smallint → text`; se pierden helpers de conveniencia en backend (eran código
muerto).

## Estrategia de migración (flip atómico, no strangler)

El `enumType` de Doctrine ata el backing del enum a la columna: un enum string-backed sobre columna
`smallint` lanza `ValueError` en la primera hidratación. **No hay coexistencia de modelos**; el
swap es indivisible. Secuencia de commits dentro del PR único:

1. **Contract swap (un commit, indivisible):** enum → string-backed; `#[ORM\Column(type: Types::STRING)]`;
   serializer → `->value` (borrar `getStatusLabel()`); borrar `HumanReadableIntEnum{Interface,Trait}` +
   `HumanReadableIntEnumValue`; simplificar `EnumTypeValidator` (sin ramas `HumanReadable*`, formateo
   por `->value`); migración hand-written (no `make db.diff`):

   ```sql
   ALTER TABLE bank_account ALTER COLUMN status TYPE text
     USING CASE status WHEN 1 THEN 'ACTIVE' WHEN 2 THEN 'INACTIVE' WHEN 3 THEN 'CLOSED'
                       ELSE NULL END;
   ```

   `down()` inverso con `ELSE` que **falle ruidoso** (un valor inesperado no debe degradarse a `NULL`
   en silencio). Tipo de columna `text`, no `varchar(n)`: en PostgreSQL el storage es idéntico y el
   `n` solo añade un check y un riesgo de `ALTER` futuro — la única fuente de cardinalidad es el enum.

2. **Sync PWA (mismo PR):** claves de `STATUS_VARIANT` a `SCREAMING_SNAKE`; unión TS en mayúsculas;
   sustituir el title-casing por diccionario i18n.

3. **Guardrail:** arch-test que prohíba `getLabel`/atributos `HumanReadable*` en `*/Domain/Enum/*`;
   asserts contractuales API/PWA.

## Resultado

Enums de dominio = identidad pura. API = contrato explícito (`->value`). PWA = única fuente de i18n.
DB = almacén de códigos estables. Validator = simple chequeo de pertenencia. La categoría
"human readable enum" desaparece del sistema, y la regla D4 impide que reaparezca como VO o mapper.
