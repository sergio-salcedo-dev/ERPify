---
title: 'Campos nunca poblados en el diff de auditoría, y retirada de una cabecera que afirmaba una dirección inferida (#413)'
type: 'audit-surface'
created: '2026-08-24'
status: 'in-review'
review_loop_iteration: 0
context: []
baseline_commit: '14ee68d'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Dos defectos en un componente, y el que el issue no pedía es el peor.**

- **#413 — pérdida de evidencia.** `AuditChangeDiff` descartaba todo campo cuyo `old` y `new` fuesen ambos
  `null` y después etiquetaba a los supervivientes «Initial state» / «Final state before deletion». El
  backend almacena ese par fielmente — un DELETE fotografía **todos** los campos mapeados, y el changeset de
  inserción de Doctrine incluye los nulos — así que «este campo opcional estaba vacío en este instante» se
  registra y se ocultaba. No es recuperable por ninguna otra vía en la UI: `nonDiffMetadata` le quita
  `changes` al bloque Metadata antes de renderizarlo. `BankAccount.alias` es nullable y `#[PersonalData]`, con
  lo que el caso es real y no teórico.
- **Defecto mayor, fuera de las tres opciones del issue — aserción falsa.** La dirección se infería de las
  filas, así que un UPDATE que solo rellena campos previamente nulos clasifica como todo-`added` y se
  etiquetaba **«Initial state»**, sobre una tarjeta que imprime `BANK_UPDATED` unos píxeles más arriba. Dos
  afirmaciones contradictorias en la misma fila de una traza regulatoria. Ocultar un campo es una omisión;
  esto es una aserción equivocada, y es estrictamente peor.

**Decisión del product owner.** Renderizar el estado vacío de forma **incondicional** y **retirar la
cabecera** en vez de sourcearla mal. El evento no transporta su operación y `audit_log` no la persiste
(esquema verificado: 15 columnas, ninguna de operación), así que la cabecera se retira y restituirla desde una
operación real es trabajo de seguimiento.

</frozen-after-approval>

## Adversarial pass

**Dos lentes independientes, en paralelo, con contexto fresco y antes de que la PR existiera.** Ninguna
escribió una línea de este código. Lente A (arquitectura) atacó **dónde debe vivir la clasificación** y si la
retirada de la cabecera estaba bien fundada; lente B (implementación) atacó **el plan, los casos límite y los
tests**. Se les dieron los hechos y se les pidió explícitamente desacuerdo.

La pasada **no confirmó** el plan del autor: lo cambió tres veces, y en dos de ellas el autor estaba
equivocado.

### Lo que derribó, y era un defecto real

- **Regresión de colapso, invisible a todo test y gate existente.** El plan original renderizaba las filas
  vacías en el orden del wire. Medido: `COLLAPSE_THRESHOLD = 8`, `COLLAPSE_VISIBLE = 6`; `BankAccount` tiene
  10 campos mapeados (7 `#[ORM\Column]` + `id`/`createdAt`/`updatedAt`) con `bic` y `alias` declarados en 4ª y
  5ª posición. Borrar una cuenta sin BIC ni alias da **8 filas hoy** (`8 > 8` es falso → sin toggle, todo
  visible) y **10 con el cambio** → colapsa → `slice(0, 6)` → las dos vacías caen dentro de las seis primeras
  y **cuatro campos poblados desaparecen** tras un toggle que antes no existía. En la pantalla exacta que el
  cambio pretendía mejorar. Corregido ordenando las filas vacías al final.
- **Fall-through silencioso en `ChangeValue`.** Era `if/if/return`, no un `switch` exhaustivo, así que un
  campo `Empty` caía en la rama `Changed` y renderizaba `— (empty) → — (empty)`: una modificación que no
  ocurrió. **TypeScript no lo detectaba.** Corregido con `switch` exhaustivo sobre `ChangeKind`.
- **`typeHintFor` devolvía `—`**, que es *también* el centinela de valor ausente dos líneas más abajo — el
  mismo glifo significando «tipo desconocido» y «sin valor» a 40 px de distancia, y sin equivalente textual
  pese a que el docblock afirma canal texto+glifo. Corregido devolviendo `null` y omitiendo el segmento.
- **`PiiDiffSealer:69` reconstruye el array con una sola clave.** `seal()` tiene dos salidas: `:52` propaga el
  array entero, `:69` devuelve `['changes' => $sealedChanges]`. Una clave hermana nueva **sobrevive** para
  agregados sin PII (`Bank`) y **se pierde** para los que la llevan (`BankAccount`). Y la suite no lo vería:
  `PiiDiffSealerTest:92` fija la rama sin PII por identidad de array completo, mientras las cuatro pruebas de
  la rama con PII afirman hojas y scope, nunca el array entero. **Este hallazgo mató la mitad backend de este
  PR** — era el agujero de la opción «llevar la operación en el metadata JSONB».
- **El inventario de la opción «columna nueva» estaba incompleto.** `AuditLogSchemaListener:16-17` se declara
  *fuente de verdad de la forma de la tabla* («the migration is generated from it»), así que sin tocarlo
  `make db.diff` generaría un DROP de la columna. Eso, sumado a que el entorno no puede ejecutar `db.diff`,
  es lo que sacó la mitad backend de este PR.
- **Rama muerta.** Con render incondicional, `changes` no vacío ⇒ `rows` no vacío, así que la copia «Record
  with no populated fields» se vuelve inalcanzable. Es un cambio de conducta, no un daño colateral: nombrado
  en el commit y aquí.

### Donde las dos lentes discreparon, y cómo se resolvió

Lente A quiso render **incondicional**, alegando que acotar a CREATE/DELETE necesita un oráculo de dirección
que el propio cambio rompe (circularidad). Lente B **disolvió** esa circularidad: una fila both-null es
direccionalmente *neutra*, así que la dirección se calcula sobre `rows.filter(kind !== Empty)`, que es
exactamente el conjunto de hoy. El autor falló aquí: descartó la objeción de A por la premisa refutada, sin
ver que **disolver la circularidad no vuelve verdadero al oráculo**. Al retirar la cabecera la cuestión
desaparece — no hay dirección que acotar — y el render incondicional pasa de preferencia a única opción
coherente. A tenía razón en el fondo.

### Verificación de los hallazgos, no solo su registro

Cada afirmación de las lentes se comprobó contra el árbol antes de aceptarla (`PiiDiffSealer:51-69`,
`PiiDiffSealerTest:92`, `AuditLogSchemaListener:14-17`, el esquema de `audit_log` en
`Version20260623164321:19`, `AuditEventDetailResource:29-42`, las constantes de colapso, los campos de
`BankAccount`). Y **los tests nuevos se falsificaron por mutación**, no se dieron por buenos en verde:

- Sustituyendo el orden por el del wire → rojo **solo** «keeps never-populated fields out of the collapsed
  window», nombrando la regresión.
- Haciendo que `Empty` caiga en la rama `Changed` → **dos** rojos, y el segundo reproduce literalmente el
  `— (empty) → — (empty)` predicho.

Código restaurado y verde tras ambas mutaciones.

## Gates

Ejecutados individualmente porque este contenedor no tiene demonio Docker (`make pwa.quality` no puede
orquestarlos). Cada uno con su código de salida, de una ejecución fresca:

| Gate | Resultado |
|---|---|
| `tsc --noEmit` | exit 0 |
| `eslint .` | exit 0 |
| `prettier --check` | exit 0 |
| `dependency-cruiser` | exit 0 — 507 módulos, 1974 dependencias |
| `vitest run` (suite completa) | exit 0 — 247 ficheros, 1540 tests |

`api/` no se toca en este PR, y no podría verificarse aquí aunque se tocara: `composer install` falla con
«Could not authenticate against github.com», así que `api/vendor/` está vacío.

## Seguimiento

- **Restituir la cabecera desde una operación real.** Requiere que el trail transporte la operación de
  escritura. Dos formas sobre la mesa (clave en el `metadata` JSONB vs columna nueva en `audit_log`), con
  las lentes discrepando; la columna cuesta ~18-20 ficheros, migración y `AuditLogSchemaListener`, y ninguna
  de las dos rellena las filas históricas. Decisión pendiente, PR aparte, desde una sesión con stack.
- **`nonDiffMetadata`** (`AuditEntryDrawer:228-234`) excluye solo `"changes"`. Si la operación acaba viajando
  en `metadata`, aparecerá como una fila más en la sección Metadata salvo que se excluya explícitamente.
