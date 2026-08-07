---
title: 'Cerrar las dos issues que #650 dejó abiertas en Sonar, con el test que las falsifica'
type: 'refactor'
created: '2026-08-07'
baseline_commit: 5f7d853f9bb13dc41bc1366fc4ab910b7acee54b
status: 'in-review'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** SonarCloud tiene **exactamente dos** issues abiertas en el proyecto, ambas acuñadas contra `main`
al mergear #650: `php:S1142` en `RequestUriRedaction::redactNestedUri` (5 `return`, más de 3) y
`typescript:S7765` en `isIdentityAxisKey` (`.some()` de igualdad donde toca `.includes()`). Los dos ficheros
son controles que impiden que un id de persona llegue a un sumidero de logs, y **ninguno de los dos puntos a
reescribir está fijado por un test**: `isIdentityAxisKey` no tiene prueba directa y la rama de query anidada
vacía tampoco. Hoy la reescritura sería invisible a la suite.

**Approach:** Fijar primero con tests lo que falta, y luego reescribir: colapsar las guardas de
`redactNestedUri` (la de query vacía ya está subsumida por la de «nada que redactar») y sustituir el
`.some()` de igualdad por `.includes()` sobre una copia `string[]` de `IDENTITY_AXES`, siguiendo el
precedente `NORMALIZED_DENYLIST` del mismo fichero.

## Boundaries & Constraints

**Always:**
- Los dos cambios que cierran las issues de Sonar preservan el comportamiento **byte a byte**; los cinco
  cierres de fuga lo cambian **solo en dirección de redactar más**, nunca menos. Sobre-redactar un log cuesta
  un diagnóstico; sub-redactarlo cuesta un identificador que sobrevive a su propio borrado.
- Cada cambio llega con el test que lo falsifica.
- Paridad de vocabulario API↔PWA: `IDENTITY_KEYS` y `IDENTITY_AXES` siguen siendo la misma lista, y ahora
  con un gate que lo comprueba en vez de un test que lo promete.

**Ask First:**
- Si algún test **existente** cambia de resultado → HALT (sería cambio de comportamiento, no un smell).
- Si `.includes()` obliga a un cast (`as readonly string[]` / `as string[]`) → HALT y proponer alternativa.

**Never:**
- `// NOSONAR`, `eslint-disable`, ni marcar la issue *won't fix* en SonarCloud.
- Tocar el `as const` de `IDENTITY_AXES` / `REDACTION_DENYLIST` (contrato de paridad con la API).
- Cambiar vocabulario redactado, sentinelas o topes (`MAX_DECODE_PASSES`, `MAX_NESTED_URI_DEPTH`,
  `MAX_DEPTH`, `MAX_NODES`).
- Extraer métodos nuevos en `RequestUriRedaction` solo para bajar el conteo de `return`.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Salida esperada |
|---|---|---|
| URI anidada con query vacía (PHP) | `/login?next=%2Fbackoffice%2Fbanks%3F` | Byte a byte, sin re-codificar |
| URI anidada sin nada que redactar (PHP) | `/login?next=%2Fbanks%3Fsort%3Dname` | Byte a byte |
| URI anidada con id (PHP) | `/login?next=%2Faudit%3FactorId%3D019f-abc` | `actorId` → `REDACTED`, re-codificado |
| Tope de profundidad (PHP) | valor anidado a 2 niveles | Nivel 3 no se sigue |
| Eje de identidad (TS) | `actorId`, `RESOURCEID`, `correlationId` | `true` (case-insensitive) |
| Gramática de búsqueda (TS) | `filters[0][value]`, `filters[][value][2]` | `true` |
| Clave que solo *contiene* un eje (TS) | `actorIdList`, `xcorrelationid` | `false` — exacto, no subcadena |
| Clave corriente (TS) | `level`, `limit` | `false` |

</frozen-after-approval>

## Code Map

- `api/src/Shared/ErrorContract/Application/RequestUriRedaction.php` -- `redactNestedUri()` (l. 153) es
  `php:S1142`; sus 5 `return` son 4 guardas + el resultado.
- `api/tests/Unit/Shared/ErrorContract/Application/RequestUriRedactionTest.php` -- cubre anidado con id,
  gramática anidada, secreto anidado y «nada que redactar»; **no** la query anidada vacía.
- `pwa/src/context/shared/observability/domain/redaction.ts` -- `isIdentityAxisKey()` (l. 73) es
  `typescript:S7765`; `NORMALIZED_DENYLIST` (l. 32) es el precedente de copia ensanchada a `string[]`.
- `pwa/tests/context/shared/observability/domain/redaction.test.ts` -- prueba `isDenylistedKey` y
  `scrubDeep`; **no** menciona `IDENTITY_AXES` ni `isIdentityAxisKey`.
- `pwa/src/context/shared/observability/infrastructure/scrubSentryEvent.ts:143` -- único consumidor; su test
  ya cubre `actorId`/`resourceId` y la gramática posicional (escrita percent-encoded: `filters%5B0%5D…`).
  El único eje sin cubrir a nivel de integración es `correlationId`.

## Tasks & Acceptance

**Execution:**
- [x] `api/tests/Unit/Shared/ErrorContract/Application/RequestUriRedactionTest.php` -- añadir el caso «URI
  anidada con query vacía» a `provideItLeavesADiagnosticRequestUriByteIdenticalCases` -- fija la guarda que
  la reescritura elimina, antes de eliminarla.
- [x] `pwa/tests/context/shared/observability/domain/redaction.test.ts` -- nuevo `describe` de ejes de
  identidad: paridad de lista con la API, los tres ejes case-insensitive, la gramática posicional y los
  negativos de subcadena -- hoy la función no tiene ninguna prueba directa.
- [x] `api/src/Shared/ErrorContract/Application/RequestUriRedaction.php` -- colapsar `redactNestedUri` a 3
  `return`: quitar la guarda `'' === $query` (subsumida) y fundir el retorno final en un ternario.
- [x] `pwa/src/context/shared/observability/domain/redaction.ts` -- `IDENTITY_AXES.some(a => a === k)` →
  `.includes(k)` sobre una copia `string[]`, sin cast y sin tocar el `as const`.

**Acceptance Criteria:**
- Dado el árbol tras el cambio, cuando se corren los tests de ambos ficheros, entonces pasan **sin haber
  modificado ninguna aserción preexistente** (solo se añaden casos).
- Dado que se stubea `redactQueryAtDepth` a devolver `'x'` **solo cuando `'' === $query`**, cuando se corre
  el provider byte-a-byte, entonces cae **exactamente 1 de 7** y es el caso nuevo. Un stub incondicional no
  sirve: `redact()` llama a `redactQueryAtDepth` él mismo, así que reescribe el nivel superior de toda URI
  con query y tiñe de rojo 5 de 7 sin llegar nunca a `redactNestedUri`.
- Dado el análisis de SonarCloud sobre la PR, cuando termina, entonces ni `php:S1142` ni `typescript:S7765`
  aparecen y el *quality gate* pasa sin nuevas issues.

## Pase adversarial (2026-08-07, antes de abrir la PR)

Dos revisores independientes sin contexto previo, en paralelo, sobre el diff: **Blind Hunter**
(`bmad-review-adversarial-general`) y **Edge Case Hunter** (`bmad-review-edge-case-hunter`).

**Invariante de comportamiento: confirmado por medición, no por argumento.** Ambos cargaron la versión previa
y la nueva en el mismo proceso PHP 8.5 y las compararon diferencialmente: 160.124 pares `(valor, profundidad)`
uno, 300.000 entradas fuzzeadas el otro. **0 divergencias**, con 2.060 pares entrando de hecho en la rama
borrada. En TS, `.includes` (SameValueZero) y `.some(===)` solo difieren en `NaN` y en huecos de array;
ninguno es alcanzable (`toLowerCase()` siempre devuelve string, la tupla es densa). 22 entradas medidas, 0
divergencias.

**Hallazgos aplicados en esta rama:**

| # | Hallazgo | Aplicado |
|---|---|---|
| 1 | La falsificación registrada era vacua: stub incondicional de `redactQueryAtDepth` tiñe 5/7 sin llegar a `redactNestedUri` | Stub aislante (`'' === $query`) → cae 1/7, el caso nuevo. AC #2 corregido |
| 2 | El docblock de la clase decía `filters[0..8]`; el Caddyfile enumera **0..19** desde el propio #650 | Corregido rango y cifra de coste |
| 3 | `'search value axis at an index beyond what the edge enumerates'` usaba índice 12, hoy enumerado por Caddy | Índice → 23, comentario alineado |
| 4 | El test nuevo prometía «every spelling»; el predicado solo ve claves ya decodificadas por `URLSearchParams` | Renombrado a lo que prueba, con el porqué |
| 5 | Copia `string[]` innecesaria: los ejes ya son minúsculas (a diferencia de `NORMALIZED_DENYLIST`, que sí mapea) | `readonly string[]` sin copia, anotación no cast |
| 6 | `'mirrors the API axis set'` no puede detectar deriva del lado API | Renombrado; el comentario dice que ningún gate compara las listas |
| 7 | «stays a statement of its own» es fraseo relativo al cambio | Reescrito sin dependencia del diff |

**Fugas reales medidas, TODAS preexistentes en `main` y ninguna causada por este cambio** — ver la decisión de
alcance en la PR:

- **Clave de eje con byte de control sufijado**, en **ambos** deployables: `?actorId%00=<uuid>` no casa
  (`actorid\0` ≠ `actorid`); igual con `%0A`, `%20`, `actor+Id`. La petición responde 4xx, y un 4xx es justo lo
  que `fingers_crossed` almacena y vuelca al siguiente 5xx.
- **TS no decodifica en profundidad**: `filters%255B0%255D%255Bvalue%255D` llega como
  `filters%5B0%5D%5Bvalue%5D` y escapa. PHP lo redacta vía `MAX_DECODE_PASSES`.
- **TS sigue una URI anidada un nivel; PHP sigue dos.**
- **PHP decodifica un *valor* anidado una vez, pero una *clave* hasta seis.** Asimetría del mismo signo.
- **Cero gates de paridad** entre los tres sitios que sostienen este vocabulario (`Caddyfile`,
  `RequestUriRedaction.php`, `redaction.ts`). El hallazgo #2 es lo que produce esa ausencia con el tiempo.

## Segundo pase adversarial (2026-08-07, sobre los cierres de fuga, antes de abrir la PR)

El primer pase revisó un refactor; los cinco cierres son cambio de comportamiento sobre un control GDPR y
llevan pase propio. Devolvió **dos GRAVE, ambos contra afirmaciones escritas en la documentación de este
mismo diff** — lo peor que puede producir este trabajo: prosa que asegura una protección inexistente.

| # | Hallazgo | Estado |
|---|---|---|
| G2 | `scrubNestedUri` decodificaba el valor una vez: `next=%252Fa%253FactorId%253D<id>` —la entrada exacta que fija el test nuevo de PHP— llegaba **intacta a Sentry**. El test de profundidad no lo veía: apila niveles de codificación **simple** | **Cerrado**: `decodeUntilQuerySurfaces` compartido + test que cae al revertirlo (1 de 80) |
| M2 | `\s` de PCRE es ASCII: `%C2%A0`, `%E2%80%80` y el BOM **fugaban en PHP** y se redactaban en TS, mientras el docblock afirmaba «mirrors the API» | **Cerrado**: clase Unicode con respaldo ASCII para bytes no-UTF8; 3 casos |
| M3 | PHP reducía solo para comparar, TS reasignaba: `%250%0A0actorId` **fugaba en PHP** | **Cerrado**: `reduce()` alimenta la decodificación siguiente; 1 caso |
| M4 | El gate prometía «los tres» y comparaba dos: un cuarto eje en **ambos** deployables dejaba a Caddy con tres y el gate verde | **Cerrado**: tercera aserción derivada de `IDENTITY_KEYS`; docblock reescrito a lo que prueba |
| m1–m3 | «seis decodificaciones» (son cinco llamadas / seis formas), «creció de 0..8 a 0..19» (narrativa de rama, no verificable), «un vigésimo eje rompe el build» (son veinte; rompe el vigesimoprimero) | **Corregidos** |
| m4 | Escape malformado final (`?actorId%=`) no redactado en ningún sumidero | **Declarado** en `docs/api-error-contract.md`, no cerrado: sanar escapes arbitrarios es adivinar, y adivinar mal redacta parámetros reales |

**Claims que sobrevivieron al ataque** (medidos por el revisor, no argumentados): la compatibilidad de
`decodeUntilQuerySurfaces` — **0 contraejemplos en 400.000 entradas fuzzeadas**; ninguna sobre-redacción
alcanza un cuerpo de respuesta (las seis call sites son sumideros; el cuerpo va por `RedactionDenylist`);
ninguna clave que un productor real emita se sobre-redacta; ambos bucles terminan y no hay coste cuadrático.

**Dos abiertos, que no cierro unilateralmente** — ver el cuerpo de la PR:

- **G1 — Caddy no puede recibir estos arreglos.** Su `format filter` casa el nombre del parámetro
  literalmente: sin comodín, sin normalización, sin decodificación. Medido sobre el stack vivo, misma
  petición, misma línea: `?actorId%00=`, `?actorId%20=`, `?actor+Id=` y el doblemente codificado se redactan
  en la línea de error y en Sentry, y **llegan en claro al access log**. Cerrarlo en el borde significa sacar
  el query string del access log entero. Es una decisión con coste propio, no un arreglo.
- **M5 — coste ~2,5× sobre entrada hostil.** Medido: 36,8 KB de pares `k=%253Fx%253D1` pasan de 1,06 ms a
  2,62 ms; escala lineal (~72 µs/KB), así que una URI de 82 KB gasta 5,97 ms **solo en redacción** contra un
  presupuesto documentado de p99 ≤ 5 ms en 4xx. `ExceptionResponderBenchmarkTest` ataca una ruta sin query
  string, así que no mide nada de esto.

## Design Notes

`redactNestedUri` baja de 5 a 3 `return` porque la guarda de query vacía es redundante: `redactQueryAtDepth('')`
recorre `explode('&', '') === ['']`, `redactPair('')` no encuentra `=` y devuelve el par intacto, luego el
resultado es `''` y el contraste `$redactedQuery === $query` ya responde `null`.

```php
$query = \substr($decoded, $separator + 1);
$redactedQuery = self::redactQueryAtDepth($query, $depth + 1);

return $redactedQuery === $query
    ? null
    : \rawurlencode(\substr($decoded, 0, $separator + 1) . $redactedQuery);
```

La guarda `false === $separator` **no** se funde con esa expresión: PHPStan en `level: max` rechaza
`$separator + 1` mientras el tipo sea `int|false`, y una asignación dentro de la condición dispararía `S1121`.
Por eso quedan 3 y no 2.

En TS, `IDENTITY_AXES` es una tupla de literales por el `as const`, así que `.includes(lowerKey: string)` no
compila contra ella. La copia ensanchada (`const IDENTITY_AXIS_KEYS: string[] = [...IDENTITY_AXES]`) es el
patrón que el fichero ya usa para el denylist y evita el cast que **Ask First** veta.

## Verification

**Commands:**
- `make php.unit c='--filter RequestUriRedactionTest'` -- exit 0 y el conteo de tests **sube** respecto a la
  corrida previa (si no sube, el caso nuevo no se ejecuta).
- `make php.stan` -- exit 0. `make php.quality` -- exit 0 (barrido completo).
- `make pwa.test.unit c='tests/context/shared/observability/domain/redaction.test.ts'` -- exit 0.
- `make pwa.test.unit c='tests/context/shared/observability/infrastructure/scrubSentryEvent.test.ts'` -- exit 0.
- `make pwa.quality` -- exit 0.

**Manual checks:**
- Falsificación: stubear `redactQueryAtDepth` a `return 'x';` y confirmar que el caso nuevo se pone rojo.
  Restaurar **copiando los bytes**, nunca con `git checkout --`.
- Tras el merge, confirmar en SonarCloud que el proyecto queda con **0** issues abiertas.
