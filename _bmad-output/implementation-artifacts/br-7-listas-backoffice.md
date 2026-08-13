---
baseline_commit: 3be821298f2d6c3e11a83c91d851cb554b0f1171
---

# Story BR-7: Listas de backoffice — el toolkit de recursos sobre Bank/BankAccount

Status: review

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-7 · Issues #395 #422 #423 #424 #272 #273
> Rama: `fix/backoffice-resource-lists-br7-sfb3` · Worktree: `.claude/worktrees/backoffice-resource-lists-br7-sfb3` · Base: `main` @ `3be82129` (rebasada; la medición original es contra `781c75a2`, y ninguno de los tres commits intermedios toca un fichero de este lote)
> #425 se cerró con evidencia antes de este lote. **#426 sale a PR PROPIA** por decisión — ver *Trampa de la épica*.
> **PR: [#705](https://github.com/sergio-salcedo-dev/ERPify/pull/705)** · Cadena de decisión: re-medición → consulta externa → debate → Sergio. Íntegra en `tmp/bmad-md/br7-decisiones-cerradas-20260812.md`.

---

## Lo que la medición refutó

El lote entró descrito como «el más grande de la épica y el de menor riesgo regulatorio», con ocho issues.
**Ninguno de los seis vivos era el defecto que su título describe.**

| # | Afirmación heredada | Medición contra `781c75a2` |
|---|---|---|
| **M1** | #395: «el `update()` del módulo `Bank` se comporta igual» | **El método del agregado no existe:** `Bank` tiene `rename()` (`Bank.php:127`), no `update()`. Matiz que el pase adversarial corrigió: `BankUpdater::update()` **sí** existe (`BankUpdater.php:33`), así que el issue apunta al caso de uso, no al agregado. La conducta vive en los dos; la corrección se mantiene, su redacción original no. |
| **M2** | #395 es una decisión de diseño abierta | **Falso: el patrón ya está enviado.** `BankAccountStatusChanger.php:29` documenta *«A transition to the current status is a no-op (no event)»*, la guarda vive en el agregado (`BankAccount.php:173`) y el caso de uso abre igualmente la transacción. Medido inocuo: `SymfonyMessengerEventBus::publish()` es un `foreach` sobre el variádico (`:35-40`) → cero eventos no dispara nada; un `save()` sin changeset no produce UPDATE ni fila de auditoría. |
| **M3** | #422: «los `Search*` están cableados y sin consumidor» | **Su prosa acierta; su glob no.** El issue nombra exactamente los dos muertos y en eso es correcto — lo que sobra es su línea `Files: Search*.ts`, que barre también `SearchBankAccounts`, vivo en `banks/[id]/accounts/page.tsx:115`. Refutar la paráfrasis en vez del issue era mío. |
| **M4** | Los dos muertos son una asimetría de diseño | **Falso: son residuo de migración** — pero no del mismo tipo, y esto lo corrigió el pase adversarial. Los tres son el mismo forwarder puro línea por línea. `SearchBanks` **sí** quedó huérfano cuando su página migró (`7140a991`, #275). `SearchAllBankAccounts` **nació muerto**: `d8781552` (#421) creó la clase, ató el token y envió su página ya sobre el toolkit en el mismo commit. Y la página del tercero **no usa el toolkit**: `banks/[id]/accounts/page.tsx:66-83` está hecha a mano con `useState`/`useEffect`. |
| **M5** | #422: «los tests prueban lo que hay» | **Falso.** `_mocks.ts:53-68` **sintetiza** el adaptador `BackOfficeBankCrudRepository` a partir de un handler `BackOfficeSearchBanks`. Ocho specs afirman sobre un token que producción nunca resuelve: la suite codificó una arquitectura que el código no ejecuta. |
| **M6** | #423/#272: «un enum desconocido tumba la lista entera» | **Vivo pero inalcanzable.** `Shared/Kernel/Domain/Enum/Currency.php` tiene un único `case EUR`; `BankAccountStatus` tiene exactamente `ACTIVE/INACTIVE/CLOSED`. Idénticos a los `Set` del PWA (`ApiBankAccountRepository.ts:50-51`). El servidor **no puede** emitir un valor que la guarda rechace. |
| **M7** | #423 y #272 son dos issues | **Son el mismo, sobre el mismo fichero.** Guardas hermanas: `:53-64` aplicada en `:80`, y `:135-149` aplicada en `:168`. #272 es de 2026-06-14, #423 de 2026-07-02. El único argumento propio de #423 es el radio de daño mayor de la lista global — que sigue condicionado al mismo disparador. |
| **M8** | #424: «el realtime re-enmascara un IBAN revelado» | **Vivo, y NO es defecto.** El reset está documentado como política deliberada en el docblock de `useIbanReveal.ts` (*«always re-masks (IBAN is PII)»*), reforzado por el temporizador de auto-hide, y copiar sigue funcionando enmascarado. Es UX contra política, no un fallo. |
| **M9** | #273: «los grupos de serialización no incluyen `GROUP_ACCOUNT_COUNT`» | **La causa citada está muerta.** Los grupos ya no gobiernan el contrato wire — lo hacen los Resource DTO por vista. Y `BankUpdateResource` documenta la omisión como deliberada (*«Deliberately the narrowest bank view»*), decidido **después** de abrirse el issue. #273 pide revertir una decisión registrada. |
| **M10** | #426: el mapping `iban` del servidor es un resto olvidado | **Falso en las dos direcciones.** Es una capacidad **especificada y cubierta por aceptación**: `search_collection.feature:174` la ejerce sobre HTTP real y afirma la canonicalización, `:139` enumera deliberadamente los ejes filtrables, e `IbanFieldNormalizer` existe **sólo** para servirla. Y su premisa —fuga al access log— la cerró BR-2: los tres sumideros redactan `filters[N][value]` en 0..19, atado a `SearchQuery::MAX_FILTERS` y gateado por `CaddyfileAccessLogRedactionGateTest`. **Pero redactar sumideros no saca el PII de la URL.** |

---

## Decisiones

| # | Decisión | Argumento |
|---|---|---|
| **D1** · #395 | **Guarda no-op en el agregado, en `BankAccount::update()` y `Bank::rename()`.** | La igualdad semántica de la entidad es una regla del agregado, no del caso de uso: que `BankUpdater` leyera los cinco campos, comparara y decidiera si llamar sería Tell-Don't-Ask al revés, y dejaría a cualquier caller futuro olvidar la guarda. No es un patrón nuevo — es el que `changeStatus()` ya envía, aplicado a los dos métodos que se lo saltaron. |
| **D1b** | **La comparación es contra el estado PERSISTIBLE, jamás contra el input.** | `input → canonicalize/normalize → nuevo estado canónico → ¿== estado actual?`. `canonicalizeIban`, `canonicalizeBic`, `NormalizedText::from`. La implementación no depende de cómo llegó la petición. **Corregido tras el pase adversarial (A1/A2):** la equivalencia llega justo hasta donde llega el canonicalizador del agregado, que es **más estrecho que el validador que gatea el mismo campo** — `canonicalizeIban` quita `U+0020` y `Assert\Iban` acepta además `U+00A0` y `U+202F`. Un IBAN que difiere sólo en un espacio no-ASCII **no** es no-op: muta y persiste no canónico. La afirmación original («dos escrituras equivalentes del mismo IBAN no producen un cambio falso») era falsa para esas formas. Defecto preexistente, con consecuencia de integridad (el índice `unique` deja de ver el duplicado); sale a **PR propia**, que empieza midiendo producción. |
| **D2** · #422 | **Borrar `SearchBanks` y `SearchAllBankAccounts`; CONSERVAR `SearchBankAccounts`.** | No es elegir arquitectura: es terminar una migración ya decidida (M4). El tercero se conserva no por asimetría sino porque su página es anterior al toolkit; cae cuando esa página migre, y eso no es BR-7. «Los tests tendrían que cambiar» no es argumento para conservar producción — revela que la suite codificó otra arquitectura (M5). |
| **D3** · #423+#272 | **Cerrar ambos con evidencia Y añadir un gate de contrato.** | La evidencia justifica cerrar; el gate es lo que convierte una invariante **accidental** en una **verificable**, y permite cerrar con garantía permanente en vez de con una foto de `main` a 12-08-2026. Degradar por fila ahora es trabajo especulativo que el propio #272 prohíbe, y reduciría la capacidad de detectar drift de contrato. |
| **D3b** | **El gate compara CONJUNTOS semánticos, no ficheros.** | El objetivo es `valores(enum servidor) == valores(Set PWA)`, **no** `Currency.php == ApiBankAccountRepository.ts`. `CaddyfileAccessLogRedactionGateTest` sirve como **mecanismo** (test PHP que lee un fichero no-PHP y rompe la build ante divergencia), nunca como forma de comparación: el test extrae los valores de las dos fuentes y compara los conjuntos. |
| **D4** · #424 | **Cerrar con evidencia. El re-enmascarado se queda.** | Sin código. La alternativa —no recargar ante eventos irrelevantes— **no es viable con este contrato**: filtro, orden y cursor keyset viven en el servidor, así que el cliente no puede saber si un evento afecta a la página actual. Un `UPDATED` puede meter, sacar o mover una fila cuyo id no era visible. Implementarlo exigiría mover semántica de búsqueda al cliente o un protocolo nuevo de relevancia. |
| **D5** · #273 | **Cerrar con evidencia. Corregir el comentario rancio de `ApiBankRepository.ts:84`.** | Meter el enricher en el write path haría que una escritura consultase el read model para fabricar su respuesta. La inconsistencia transitoria se resuelve con refetch —que todo consumidor ya hace—, no contaminando la respuesta de escritura. |
| **D6** · #426 | **Transporte no-loggeante, en PR PROPIA.** | El problema ya no es el dominio funcional de la búsqueda sino el **canal de transporte de un identificador PII**: exige threat model y autorización propios. Ver *Trampa de la épica*. |

### D1 — la igualdad, fijada contra el código

La semántica de `null` vs `''` **no se decide aquí: ya está establecida**, y la guarda la hereda entera con sólo
comparar después de canonicalizar. Medido en `BankAccount.php`:

| Campo | Tipo | Canonicalización | `null` vs `''` |
|---|---|---|---|
| `holderName` | `string`, `#[Assert\NotBlank]` | ninguna | no aplica — `''` lo rechaza la validación |
| `iban` | `string`, `#[Assert\NotBlank]` | `canonicalizeIban()` `:240-243` — mayúsculas + quita espacios | no aplica |
| `bic` | `?string`, columna nullable | `canonicalizeBic()` `:250-257` — **colapsa `''` → `null`**, luego mayúsculas | **son el MISMO estado**, y está documentado en `:245-249` |
| `alias` | `?string`, columna nullable | **ninguna** — se asigna crudo (`:134`) | **son estados DISTINTOS** |
| `currency` | `Currency` enum | no aplica | no aplica — comparación por identidad |

`UpdateBankAccountCommand` no normaliza en el borde (`?string $alias = null` pasa crudo), así que la asimetría
`bic`/`alias` llega intacta al agregado.

**La guarda RESPETA esa asimetría, no la corrige.** Colapsar `alias` cambiaría lo que persiste un `PUT` con
`alias: ""` — una modificación de conducta ajena a este lote (ver *Fuera de alcance*).

`Bank::rename()` es más simple: dos `string` no nulables, canonicalizados por `NormalizedText::from($name)`
(display + normalized) y `NormalizedText::toAsciiUpper($shortName)`. La comparación va contra los tres campos
persistidos — `name`, `nameNormalized`, `shortName` —, no contra los dos argumentos.

---

## Alcance

**Tres modificaciones de código/documentación, un gate y cuatro cierres probatorios.** En este orden.

- [x] **T1 — D1 · #395: guarda no-op** (API)
  - [x] `BankAccount::update()` — guarda `alreadyStores()` sobre los cinco campos canonicalizados; si nada cambia, no muta, no toca `updatedAt`, no graba evento.
  - [x] `Bank::rename()` — ídem sobre `name`/`nameNormalized`/`shortName`, comparando tras `NormalizedText::from` / `toAsciiUpper`.
  - [x] Casos de uso intactos: `BankUpdater` y `BankAccountUpdater` abren la transacción y guardan igual — fijado con `$transactions->opened === 1` en sus tests, el mismo observable que `BankAccountStatusChangerTest` ya usaba.
- [x] **T2 — D2 · #422: borrar el residuo de migración** (PWA)
  - [x] Borrados `SearchBanks.ts` y `SearchAllBankAccounts.ts`.
  - [x] Quitados sus dos `import` y sus dos bindings de `Container.ts`.
  - [x] Borrado `SearchAllBankAccounts.test.ts`. **No existe homólogo de banks** —
    `pwa/tests/context/backoffice/bank/application/` sólo contiene `schemas/`.
  - [x] Reescritas las 8 specs contra los tokens que la página resuelve; síntesis de `_mocks.ts` eliminada
    entera y `_fixtures.ts::searchPage` devuelve ya la `ResourceSearchPage` genérica (`items`).
  - [x] `SearchBankAccounts` y su página, intactos.
- [x] **T3 — D3 · #423+#272: gate de contrato de enums**
  - [x] `api/tests/Unit/Shared/Architecture/EnumWireContractGateTest.php` — extrae los casos del enum servidor
    y los literales del `Set` del PWA y compara **conjuntos ordenados**, no texto.
  - [x] Mecanismo del hermano `CaddyfileAccessLogRedactionGateTest` (test PHP que lee un fichero no-PHP y
    resuelve la raíz del repo con fallo-no-skip); forma de comparación propia.
  - [x] Nueve rojos provocados, incluidos los cuatro de deriva y los cinco de degradación (ver abajo).
- [x] **T4 — D5 · #273: comentario rancio**
  - [x] `ApiBankRepository.ts` — reescrito contra la causa real: cada vista tiene su Resource DTO y los de
    create/update son deliberadamente la vista más estrecha (`BankUpdateResource` lo documenta), así que una
    respuesta de escritura sin `accountCount` es válida, no malformada. Se retiró la frase que justificaba el
    `?? 0` desde aquí: ese default es una falsificación semántica declarada fuera de alcance, y un comentario
    que lo bendice al describir la guarda mezcla dos cosas.
- [x] **T5 — verificación completa** (ver *Gates*)
- [ ] **T6 — pase adversarial**, registrado en esta historia **antes** de `gh pr create`
- [x] **T7 — cierres con evidencia**: #395, #422, #423, #272, #424, #273
  - [x] Los seis llevan su comentario de evidencia medida, cada afirmación con `fichero:línea`, redactados
    por tres agentes en sólo lectura y verificados contra el código antes de publicarse.
  - [x] **#424 cerrado** (`not_planned`): no necesitaba código y no se escribió ninguno.
  - [ ] **#395 #422 #423 #272 #273 cierran al mergear** — su resolución vive en esta rama, así que van como
    `Closes #…` en el cuerpo de la PR en vez de cerrarse a mano sobre código sin mergear.

---

## Falsificación — cada cláusula tiene su rojo

**Regla del lote: una aserción que pasa no prueba nada hasta que la has visto fallar.** Aplica a cada aserción
añadida, incluidas las que se añadan al corregir un hallazgo del pase adversarial.

El criterio del no-op **no es «no hace UPDATE»**. Es, en las dos direcciones:

```text
entrada equivalente  → sin mutación de estado · sin mutación de updatedAt · sin evento
estado distinto      → mutación · updatedAt cambia · evento con la semántica esperada
```

`updatedAt` se observa **explícitamente**: un no-op que lo mueve no es un no-op — altera el estado persistible
y arrastra efectos aguas abajo aunque no publique evento.

| Cláusula a neutralizar | Rojo que debe aparecer | Estado |
|---|---|---|
| Guarda de `BankAccount::update()` → eliminada | ≥1 | ✔ M1 · 5 rojos |
| Guarda de `Bank::rename()` → eliminada | ≥1 | ✔ M2 · 3 rojos |
| **Comparación contra el input, no contra el estado canónico** — IBAN que sólo difiere en espacios | ≥1 | ✔ M3 · 4 rojos |
| Ídem — IBAN que sólo difiere en mayúsculas | ≥1 | ✔ M3 |
| Ídem — BIC que sólo difiere en mayúsculas | ≥1 | ✔ M3 · M6 |
| **`bic: ''` frente a `bic: null`** debe ser no-op (colapsan al mismo estado) | ≥1 | ✔ M3 |
| **`alias: ''` frente a `alias: null`** debe MUTAR (son estados distintos) | ≥1 | ✔ M4 · A4 |
| Un único campo realmente cambiado → muta, mueve `updatedAt` y publica | ≥1 | ✔ M5 · 4 rojos · M9 · 4 rojos |
| `Bank`: comparación contra los argumentos en vez de contra `name`/`nameNormalized`/`shortName` | ≥1 | ✔ M7 · 3 rojos |
| Gate de enums: valor añadido **sólo en el servidor** | 1 | ✔ M1 |
| Gate de enums: valor añadido **sólo en el PWA** | 1 | ✔ M2 |
| Gate de enums: valor eliminado **del servidor** | 1 | ✔ M3 |
| Gate de enums: valor eliminado **del PWA** | 1 | ✔ M4 |
| **Gate de enums: el parser NO encuentra la fuente** → rojo, jamás conjunto vacío válido | 1 | ✔ M5 · M7 · M9 |
| **Gate de enums: la declaración esperada se renombra o se duplica** → rojo | 1 | ✔ M5 · M6 |
| Specs de banks reescritas: romper `search` del repositorio | ≥1 | ✔ 4 mutaciones, 4 rojas (abajo) |

Un gate verde cuya mutación no enrojece **no cubre esa dirección** — y el gate de enums es justamente lo que
permite cerrar #423/#272 con garantía en vez de con una foto. Restaurar tras falsificar **copiando los bytes**,
nunca con `git checkout --`.

Trampa medida del repo: un gate seleccionado por `--filter` debe verificarse con `--list-tests`; el agujero es
el filtro que casa un subconjunto.

### T1 — falsificación ejecutada

Tres olas, todas con restauración por **copia de bytes** desde `tmp/br7-t1/*.pristine` (md5 verificado) y una
re-medición verde al final. Los guiones quedan en `tmp/br7-t1/` (gitignored).

- **Ola 1 — 9 mutaciones de la guarda** (M1–M9, suite de 16 tests entonces; 17 tras la corrección A3): **9 rojas**, ninguna verde. Detalle en la
  tabla de arriba. M9 salió primero como `exit 137` (OOM del contenedor, no un verde): re-medida a mano da
  4 rojos. *Un 137 no es un verde — la ola lo habría contado como cláusula sin cubrir si no se re-mide.*
- **Ola 2 — la guarda quitada contra los casos de uso y los escenarios Behat**: roja en las cuatro
  direcciones (`BankUpdaterTest`, `BankAccountUpdaterTest`, y los dos escenarios nuevos; el primer paso que
  enrojece es `data.updatedAt`, que es exactamente el observable que la historia exigía).
- **Ola 3 — se elimina UNA columna de la comparación cada vez** (A1–A5 en `BankAccount`, B1–B3 en `Bank`).
  Siete rojas como se esperaba; **una verde declarada**:

| Columna suprimida de la comparación | Resultado | Lectura |
|---|---|---|
| `holderName` · `iban` · `bic` · `alias` | rojo | cada campo tiene su test de dirección positiva |
| `currency` | **verde — dirección NO cubierta** (y **no era la única**: ver A3 del pase adversarial) | `Currency` tiene un único caso (`EUR`), así que ninguna prueba puede diferir en él. La cláusula se **conserva**: quitarla haría que el día que entre una segunda divisa un cambio de divisa se lo trague la guarda en silencio. Es coste hoy, seguro mañana — y queda declarado en vez de aparentar cobertura. |
| `name` | rojo | **hallazgo de la ola 3**: la primera medición salió *verde*. `nameNormalized` se deriva de `name`, así que sólo un cambio de **capitalización** mueve uno sin el otro — y ése es un cambio visible en la UI que la guarda se habría tragado. Test añadido; la mutación pasa a roja. |
| `nameNormalized` | rojo | lo cubre el test de rehidratación (Doctrine escribe las columnas directamente, así que un gemelo rancio de una regla vieja se re-deriva) |
| `shortName` | rojo | test de dirección positiva |

`--list-tests` sobre el filtro de las dos clases nuevas devuelve exactamente los tests esperados —16 entonces,
17 tras añadir el de A3—: el filtro no casa un subconjunto.

### T2 — falsificación ejecutada

Los specs mockean el contenedor, así que romper el adaptador de producción no probaría nada sobre ellos: lo
falsable es la **costura**. Cuatro mutaciones sobre la suite de banks (47 ficheros, 207 tests), todas rojas:

| Mutación | Rojo |
|---|---|
| La página pide otra `repositoryKey` | 12 ficheros · 43 tests |
| La página pide otra `navigatorKey` | 2 ficheros · 4 tests |
| La fixture devuelve una página sin `items` | 11 ficheros · 42 tests |
| El kit vuelve a colgar el espía del token borrado `BackOfficeSearchBanks` | 4 ficheros · 14 tests |

La última es la que importa: **es la prueba de que los specs cuelgan ahora del token que producción resuelve** y
no del caso de uso muerto. Restauración por copia de bytes, baseline verde al final.

### T3 — falsificación ejecutada

Nueve mutaciones sobre el gate, **nueve rojas**, restauración por copia de bytes y baseline verde al final.
Las cuatro primeras son deriva de vocabulario; las cinco siguientes son las formas en que la extracción
podría degradarse en un pase sobre nada:

| Mutación | Rojo por |
|---|---|
| M1 valor añadido sólo en el servidor | conjuntos distintos |
| M2 valor añadido sólo en el PWA | conjuntos distintos |
| M3 valor eliminado del servidor | conjuntos distintos |
| M4 valor eliminado del PWA | conjuntos distintos |
| M5 la declaración esperada se renombra | *«se esperaba exactamente una, encontradas 0»* — nunca «admite nada» |
| M6 la declaración se duplica | *«encontradas 2»* — si no, un refactor deja el gate leyendo la vieja |
| M7 el `Set` queda vacío | *«no admite ningún valor»* |
| M8 el guard se declara pero no se consulta | *«declarado pero nunca consultado»* |
| M9 la fuente del PWA no es alcanzable | falla, **no** se salta |

**M8 salió verde en la primera pasada y la mutación era el problema, no la aserción**: el fichero consulta
`CURRENCIES.has(` en **dos** sitios (`:62` y `:147`), así que neutralizar uno dejaba el otro en pie. Medido y
repetido sobre todas las llamadas, enrojece. Eso mismo acota lo que la cláusula prueba —que el guard se
consulta *en alguna parte* del fichero, no que la llamada esté en la rama por la que pasa una respuesta— y
así queda escrito en el docblock del gate.

`--list-tests` **sin filtro** sobre la suite por defecto (2718 tests) lista los dos casos del gate: se ejecuta,
no sólo existe.

Blind spots declarados en el propio gate: los pares los enumera una mano (un enum nuevo no se auto-incorpora),
no prueba que el guard corra en la ruta real del payload, y no prueba que el servidor serialice esos valores
(eso lo gobierna el Resource DTO).

### T2 — alcanzabilidad, medida en tres planos

El criterio no era «no quedan imports», sino que el token no fuese alcanzable como servicio:

1. **Estático** — cero apariciones de `BackOfficeSearchBanks` / `BackOfficeSearchAllBankAccounts` en todo `pwa`
   (`.ts/.tsx/.js/.json`). Las dos vías de resolución dinámica se enumeraron: los `repositoryKey`/`navigatorKey`
   de `useResourceList`/`useResourceItem` (tres valores, todos `*CrudRepository`/`BackOfficeUserRepository`) y
   el array `MONITORED_COMPONENTS` de `health/page.tsx`. **Corregido tras el pase adversarial (C2):** los
   sitios `container.get(<identificador>)` son **once**, no dos — auth, sesiones, auditoría, debug-token y
   los formularios de contraseña resuelven cada uno desde su propia constante de módulo. Los verifiqué todos:
   cada argumento resuelve a un literal y ninguno a los tokens borrados, así que la conclusión aguanta; la
   enumeración que presenté como prueba, no. `useResourceMutations` tiene además **cero** consumidores: por el
   criterio D2 es el mismo residuo que este lote borra, y no se toca aquí.
2. **Tipos** — `tsc --noEmit` limpio dentro de `make pwa.quality`.
3. **Runtime** — sonda desechable contra el `Container` real (no un mock): resuelve
   `BackOfficeBankCrudRepository`, `BackOfficeBankResourceNavigator`, `BackOfficeBankAccountCrudRepository`,
   `BackOfficeSearchBankAccounts`, `BackOfficeDeleteBank` y `BackOfficeCountBanks`, y **lanza** en los dos
   borrados. Verde; el fichero se borró tras medir.

**Hallazgo que corrigió el cableado:** la síntesis mapeaba `find`/`delete` del repositorio desde los espías de
los casos de uso, y eso ocultaba que las dos rutas de borrado son distintas en producción — el bulk llama
`repo.find` + `repo.delete` (`useResourceList.ts:429,450`) mientras el botón de fila resuelve el caso de uso
`BackOfficeDeleteBank` (`DeleteBankButton.tsx:66`). Atar todo al repositorio dejó 8 tests rojos hasta separarlas.
Cada espía queda ahora atado a los papeles que de verdad ejerce.

---

## Pase adversarial

> **Requisito de proceso:** se ejecuta y se registra **aquí** **antes** de `gh pr create`. No se usan drafts.
> Una PR abierta es una PR que puede mergear.

**Ejecutado el 2026-08-12, antes de `gh pr create`.** Tres subagentes en sólo lectura, una lente cada uno,
sobre el worktree (no sobre el primario). Autor ≠ revisor. Doce hallazgos; los cinco marcados **verificados**
los reproduje yo contra el código antes de registrarlos, porque un hallazgo de subagente es una hipótesis.

### Hallazgos

| # | Sev | Dónde | Qué |
|---|---|---|---|
| **A1** | GRAVE | `BankAccount.php` `canonicalizeIban()` | **Verificado.** El canonicalizador quita `U+0020`; `Assert\Iban` acepta además `U+00A0` y `U+202F` (`IbanValidator.php:187`). Un `PUT` con NBSP pasa validación, **no** es no-op, y persiste un IBAN con el NBSP dentro: el índice `unique` y `#[UniqueEntity]` comparan la columna cruda, así que dos filas pueden representar el mismo IBAN real, e `IbanFieldNormalizer` (que replica el mismo `str_replace`) no vuelve a encontrar la fila. Defecto **preexistente** (`create()` lo tiene igual), pero D1b afirma lo contrario y la guarda lo convierte en la autoridad de igualdad. |
| **A2** | SERIO | `BankAccount.php` `canonicalizeBic()` | **Verificado.** `BicValidator.php:78` canonicaliza quitando espacios antes de validar longitud; el canonicalizador del agregado no quita ninguno. `bic: "DEUT DEFF"` valida y persiste con el espacio, y cada escritura posterior sin espacio se ve como cambio real. |
| **A3** | SERIO | `Bank.php` `rename()` | **Verificado por mutación, y es una regresión de cobertura que introduje yo.** Con las dos líneas de escritura cambiadas a los argumentos crudos, `php.unit --filter Bank` (205 tests) y `bank/update.feature` (3 escenarios) siguen **verdes**: la guarda absorbe por el early-return exactamente las tres entradas no canónicas que antes ejercitaban la canonicalización en el camino de escritura. `BankAccount` no tiene el agujero — `BankAccountWriteEventTest` cambia el IBAN en forma no canónica. **La historia decía que `currency` era la única dirección sin cubrir; era falso.** |
| **B1** | GRAVE | `EnumWireContractGateTest.php:151` | **Verificado.** El extractor lee texto sin distinguir código de comentario: un comentario dentro del literal (`"CLOSED", // "SUSPENDED" llega con…`) aporta `SUSPENDED` al conjunto extraído. **Falla en abierto**: verde mientras el guard rechaza el valor y tumba la lista entera. Y `:51` mide 91 columnas contra `printWidth: 100`, así que un estado nuevo fuerza el formato multilínea donde ese comentario es lo natural. |
| **B2** | SERIO | `EnumWireContractGateTest.php:124-138` | **Verificado.** Un docblock que cita la declaración vieja cuenta como **la única** declaración cuando la real pasa a `new Set(CONST)`. La cláusula anti-duplicado se vuelve en contra: cuenta 1 porque la viva no casa, y el gate lee el comentario. |
| **B3** | SERIO | `EnumWireContractGateTest.php:140` | El `.has(` sólo prueba consulta *en alguna parte*: hay **dos** predicados (`isBankAccountPrimitives` :62,:64 e `isBankAccountCollectionRow` :147,:149) y borrar las cláusulas de uno deja el gate verde. El radio de daño de la lista global es justamente el único argumento propio de #423. |
| **B4** | MENOR | ídem | `STATUSES.has(` casa dentro de `TERMINAL_STATUSES.has(`: falta frontera de identificador. |
| **B5** | MENOR | `BankAccountSchema.ts:29-31` | Tercer espejo del mismo vocabulario (`BANK_ACCOUNT_STATUSES`) que el gate no abre. Medido: añadir un estado deja ese array corto y **nada enrojece** (`.map()` sobre el array narrower typechequea), así que el estado nuevo no se puede seleccionar en la UI. El de monedas sí está cubierto transitivamente por `Record<BankAccountCurrency,…>`. |
| **B6** | MENOR | `EnumWireContractGateTest.php:111,160` | `sort()` + `assertSame` es sensible a multiplicidad; un `Set` no. Un literal duplicado da **rojo falso**. El docblock y D3b dicen «conjuntos». |
| **C1** | MENOR | `_mocks.ts:132-137` y 3 specs | `deleteRun` cuelga de los **dos** puertos de borrado, así que recuplar el bulk al caso de uso `BackOfficeDeleteBank` —la dirección que esta historia existe para deshacer— no lo detecta ningún test. La dirección contraria sí. Además, en 4 specs `BackOfficeDeleteBank` queda atado y nunca resuelto. Falsifica la frase «cada espía atado a los papeles que de verdad ejerce». |
| **C2** | MENOR | historia, plano estático | **Verificado.** Enumeré «dos vías dinámicas»; hay **11** sitios `container.get(<identificador>)`. Todos resuelven a literales y ninguno a los tokens borrados —la conclusión aguanta—, pero la enumeración presentada como prueba no es la que se hizo. Y `useResourceMutations` tiene **cero** consumidores: por el criterio D2 es el mismo residuo que borramos, ni borrado ni nombrado. |
| **C3** | NOTA | historia, plano runtime | La sonda se borró tras medir, y el repo ya tiene el patrón contrario (`DebugTokenObserverBinding.test.ts` resuelve del contenedor real y se queda). Hoy **ningún** test resuelve `BackOfficeBankCrudRepository`/`…ResourceNavigator`/`…CountBanks` del contenedor real: borrar uno de esos bindings deja `tsc` limpio y la suite verde. |
| **A4** | MENOR | los dos escenarios Behat | Behat aborta al primer paso rojo, y el rojo cae en `data.updatedAt`, **antes** de las seis aserciones de cola — incluida la única evidencia en todo el repo de «una escritura redundante no deja fila de auditoría». No se han visto rojas. |
| **A5** | MENOR | consecuencia no declarada | Tras el cambio, un `PUT` redundante no deja **ninguna** fila en `audit_log`: `AuditPolicy` sólo captura `GET` como actividad, así que la fila `change` era el único rastro de que la petición existió. Amplía un hueco que `changeStatus()` ya tenía; la historia no lo declaraba. |

Lo que las lentes **no** pudieron romper queda igual de registrado: la guarda no se traga ningún cambio dentro de su
propio modelo del estado; no hay mutación falsa; `updatedAt` viejo en la respuesta no rompe a ningún consumidor (no
hay ETag/If-Match ni `#[ORM\Version]` en el repo); la afirmación M2 —sin changeset no hay UPDATE ni fila de
auditoría— se verificó contra `AuditWriteCaptureListener::capture()` y `UnitOfWork`; las siembras Behat sí aterrizan
(un INSERT fallido haría 404 antes); los tests unitarios sí mueven el reloj; el gate **sí** corre en CI
(`ci.yml:122`, sin filtro de `paths:`); y ningún camino de payload de bank-account esquiva los dos predicados.

Lentes ejecutadas:

- **(A) Semántica del no-op** — ¿la guarda puede tragarse un cambio real? Recorrer los diez casos: mismo valor
  canónico · distinta capitalización · espacios equivalentes · IBAN canonicalizado · BIC canonicalizado ·
  `null` frente a `''` en los dos campos opcionales · alias equivalente · un solo campo cambiado · todo igual
  salvo `updatedAt` · cambio que debe publicar. Y qué ve el cliente cuando el PUT devuelve el `updatedAt` viejo.
- **(B) Falsabilidad del gate de enums** — la propiedad exigida es
  `fuente encontrada + estructura esperada encontrada + valores extraídos + comparación`. **«No encontré nada»
  debe ser rojo, nunca un conjunto vacío válido**, y encontrar más de una declaración candidata donde se
  esperaba una también: si no, una refactorización futura deja el gate leyendo una declaración vieja mientras
  la aplicación usa otra. Verificar además con `--list-tests` que el test está de verdad dentro del conjunto
  que el gate selecciona.
- **(C) Regresión del borrado** — el criterio **no es «no quedan imports»**, es que **el token borrado no sea
  alcanzable desde ningún composition root ni consumidor de runtime**: resolución dinámica de tokens,
  factories, arrays de configuración, strings con FQCN, alias del contenedor, imports indirectos y tests de
  integración. Y las ocho specs reescritas deben seguir ejercitando el repositorio real/mock correcto, no una
  reconstrucción del token eliminado.

---

## Correcciones del pase adversarial

Aplicadas en esta misma rama, cada una con su rojo medido:

| # | Corrección | Su rojo |
|---|---|---|
| **A3** | Test del camino de escritura de `rename()` (`testRenameWritesTheCanonicalFormsOfAChangedName`): cambia el valor **y** exige canonicalización, que es la única forma de alcanzar esas dos líneas con la guarda puesta | escribir los argumentos crudos pasa de verde a **rojo** (antes: 205 tests y 3 escenarios verdes) |
| **B1** | El gate blanquea comentarios antes de extraer nada | servidor gana `SUSPENDED` + el PWA sólo lo nombra en un comentario → **rojo** (antes verde) |
| **B2** | ídem | un docblock que cita la declaración vieja mientras la real deriva de una constante → **rojo** |
| **B3** | El contrato incluye **cuántos predicados** consultan cada guard (2 hoy), no sólo que alguno lo haga | neutralizar uno de los dos predicados → **rojo** |
| **B4** | Frontera de identificador en la consulta (`(?<![A-Za-z0-9_$])`) | incluida en B3 |
| **B6** | `array_unique` antes de comparar: semántica de conjunto de verdad, no de lista ordenada | un literal duplicado ya no da rojo falso |
| **C1** | Cada spec ata **sólo** el puerto que ejercita; el que ejercita los dos usa un espía por puerto y afirma cuál corrió | recuplar el bulk a `BackOfficeDeleteBank` → **rojo** por dos vías (token no atado + aserción por puerto) |
| **C3** | `BackofficeListBindings.test.ts` resuelve seis tokens del contenedor **real** — el plano de runtime deja de ser desechable | quitar un binding del composition root → **rojo** (antes: `tsc` limpio y suite verde) |

**A5 — consecuencia declarada, no corregida.** Tras este cambio un `PUT` redundante no deja **ninguna** fila
en `audit_log`: `AuditPolicy` sólo captura `GET` como actividad, así que la fila `change` era el único rastro
de que la petición existió. Amplía el hueco que `changeStatus()` ya tenía desde que se envió; se declara aquí
y en el cuerpo de la PR en vez de corregirse, porque cambiar la política de auditoría es una decisión con su
propio alcance.

**A4 — límite conocido de los dos escenarios Behat.** Behat aborta al primer paso rojo y ése cae en
`data.updatedAt`, antes de las seis aserciones de cola. No se han visto rojas; la afirmación «una escritura
redundante no deja fila de auditoría» está afirmada end-to-end pero no falsificada.

**B5 — resuelto reduciendo fuentes, no vigilando más.** La consulta externa recomendó atacar la raíz y
coincidí, con dos correcciones medidas contra el código: el PWA no escribía el vocabulario **dos** veces sino
**tres** (la unión de tipo en `domain/`, el array `as const` en `application/schemas/`, el `Set` en
`infrastructure/`), y la declaración única **no puede vivir en `application/`** como proponía — obligaría a
`domain/` a importar de su propio consumidor, que es exactamente la dirección que `pwa/CLAUDE.md` prohíbe.

La declaración única vive ahora en `domain/BankAccount.ts`: dos arrays `as const`, con el tipo derivado
(`(typeof …)[number]`), y el `Set` del guard y el `z.enum` del formulario **derivan** de ellos. Seis
declaraciones manuales quedan en **dos**.

Eso abre un agujero que no existía y el gate tenía que cerrar: si alguien vuelve a escribir un `Set` literal en
el adaptador, comparar el array de dominio contra el enum pasaría mientras el guard admite otra cosa. El gate
pasa por tanto a vigilar **dos propiedades**: que la declaración única coincida con el enum servidor, y que el
guard **derive de ella** (`new Set(BANK_ACCOUNT_STATUSES)`, exactamente una vez) y lo consulten los dos
predicados de payload.

**Falsificación: 14 mutaciones, 13 rojas.** Las dos nuevas —`M13` el guard reescribe el literal, `M14` el guard
deriva del vocabulario equivocado— son las que cierran ese agujero. `M10` sale verde y ése es el veredicto
correcto: sólo inyecta un comentario sin deriva real. El escenario de verdad (servidor gana `SUSPENDED`, el PWA
sólo lo nombra en un comentario) se midió aparte y es **rojo**.

**Incidente de método, y lo que lo salvó.** Un run de falsificación anterior murió a mitad de mutación y el
`finally` no restauró: `Currency.php` se quedó con un `case USD` inyectado, y el run siguiente fotografió esa
versión como «pristine». Lo cazó el chequeo de baseline del propio script, que **abortó en vez de reportar
rojos falsos**. Restaurado copiando bytes, nunca con `git checkout --`, y las copias pristine rehechas desde el
árbol ya correcto.

**A1/A2 — sigue congelado, y ahora con decisión: PR propia.** La consulta y yo coincidimos en que no es
documental sino de integridad (dos filas pueden representar el mismo IBAN y el índice `unique` no lo ve), y en
que el arreglo completo toca canonicalización, búsqueda, unicidad y **posibles datos históricos**, así que no
cabe en BR-7. En BR-7 sólo se corrige la afirmación falsa de D1b. **El primer paso de esa PR no es código: es
medir producción** — la BD de dev tiene cero cuentas y no prueba nada. Prompt de la consulta en
`tmp/bmad-md/consult-br7-canonicalization-and-enum-mirrors-20260812-210731.md`.

Descarté una opción intermedia que la consulta no evaluó: ampliar sólo la *comparación* de la guarda sin tocar
lo que se persiste. Impediría que el camino `update` introduzca la corrupción, pero crearía una **tercera**
interpretación de «IBAN canónico» dentro de la misma clase — cambiar «una responsabilidad con varias
autoridades» por «una con tres» no es progreso.

---

## Gates

Todos en corrida fresca desde el worktree, con su exit code impreso. **Nunca «verde» desde un log viejo.**

| Gate | Resultado |
|---|---|
| `make php.stan` | ✔ exit 0 |
| `make php.unit` | ✔ exit 0 — 2718 tests, 10868 aserciones |
| `make php.behat` | ✔ exit 0 — 433 escenarios, 4063 pasos |
| `make php.quality` | ✔ exit 0 (incluye gherkin, deptrac y los ocho `php.lint.*`) |
| `make pwa.quality` | ✔ exit 0 (ESLint + Prettier + `tsc --noEmit`) |
| `make pwa.test.unit` | ✔ exit 0 — 226 ficheros, 1234 tests |
| `--list-tests` sobre el gate de enums — prueba de que se ejecuta, no sólo de que existe | ✔ los 2 casos aparecen en la selección por defecto |

Los 2 *PHPUnit notices* y los 2 *skipped* de `php.unit` son ajenos a este lote: el árbol `tests/Unit/Backoffice`
completo (183 tests) corre sin ninguno.

---

## Fuera de alcance, declarado

Se nombra aquí y en el cuerpo de la PR. No se deja como pendiente tácito.

- **El `?? 0` de `ApiBankRepository.ts:162,171`.** Convierte «campo ausente» en «el banco tiene cero cuentas»:
  una falsificación semántica, no un default. Modelarlo bien **no es typing** — `Bank.ts` declara `accountCount`
  obligatorio y lo leen **10** ficheros de `pwa/src` (34 contando tests) — la cifra de veinte era mía y estaba mal. Es un cambio de modelo de dominio con su propia PR.
- **`SearchBankAccounts` y la migración de `banks/[id]/accounts/page.tsx` al toolkit.** El forwarder cae con su
  página, no antes.
- **La asimetría `bic`/`alias`.** Hallazgo medido al fijar la igualdad de D1: `canonicalizeBic()` colapsa
  `'' → null` y documenta por qué (`:245-249` — *«would otherwise persist as `''` and surface as `bic: ""`
  instead of `null`»*), pero `alias` —columna nullable igual, sin normalización en el DTO ni en el agregado—
  **no recibe ese trato**, así que un `PUT` con `alias: ""` persiste `''` y sale por el cable como `alias: ""`.
  Es exactamente el defecto que el docblock del hermano dice existir para evitar. **No se corrige aquí**:
  cambiaría lo que persiste una escritura, que es conducta, no limpieza. Decisión de Sergio si merece issue.
- **#426.** PR propia (ver abajo).

---

## Trampa de la épica, respetada

**BR-7 no resuelve #426 de rebote.** Restaurar el filtro `iban` en la UI dejaría el issue con aspecto de cerrado
—la capacidad vuelve, los tres sumideros redactan— mientras el problema real sigue intacto: **el IBAN íntegro
viaja en una URL**, y eso alcanza historial del navegador, marcadores, enlaces compartidos y `Referer` en
navegación cross-origin. Ninguna de esas superficies la toca la redacción de BR-2.

El mapping del servidor (`DoctrineBankAccountCollectionSearchRepository.php:97`) **no se toca en este lote**:
retirarlo desmonta un escenario Behat, un normalizador y un contrato especificado a propósito, y esa es
precisamente la decisión que #426 debe tomar con su propio threat model.
