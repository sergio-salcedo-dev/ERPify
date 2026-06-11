---
artifact: pr3-execution-contract
scope: PR3 = Story 1.3 (API flip) + Story 1.4 (PWA + Behat + observabilidad) — un único PR
baseline_commit: 8b1d72899ee4484b9b75273ff49ce60ca46d3f1c
status: active
created: 2026-06-11
authority: subordinado al ADR `architecture-keyset-pagination.md` (IMPLEMENTATION LOCKED); operacionaliza, no redefine
---

# PR3 Execution Contract — el switch observable cursor-only

Contrato operativo **sin ambigüedad** para ejecutar PR3 (Stories 1.3 + 1.4) como un único PR API↔PWA. No reabre decisiones del ADR; fija la ejecución y cierra las ambigüedades que podían bifurcar el sistema. **El riesgo nº1 de PR3 es la inconsistencia de wire entre API y PWA** — todo lo demás está subordinado a cerrarlo.

---

## 1. Reglas selladas (no re-litigar)

- **R1 — Autoridad del read-path.** En PR3 el `DoctrineSearchEngine` deja de ser "consumido indirectamente" (modelo PR2 off-wire) y pasa a ser **autoridad del read-path HTTP de Bank**. Queda invalidado cualquier modelo de "engine dual (wire + test)": hay **un solo** ejecutor del read-path en runtime. El `Paginator` legacy deja de servir Bank/BankAccount (su borrado total es PR4).
- **R2 — Separación temporal estricta.** PR2 = *core correctness only* (verificado off-wire). PR3 = *adapter layer + migration + observabilidad*. No hay solape: PR3 no toca la corrección del kernel/engine, solo lo conecta y lo observa.
- **R3 — Gate conjunto, no secuencial.** 1.3 sin 1.4 = sistema incompleto (API rota sin test de aceptación: los Behat page-based caen con el flip). 1.4 sin 1.3 = tests contra un contrato que no existe. **El gate real de PR3 es conjunto:** Behat verde + `pwa.quality`/Vitest verde + `php.quality` verde, todo en el mismo PR. No se mergea media mitad.
- **R4 — 1.4 es el verificador de semántica, no "UI consumidora".** El valor de 1.4 no es pintar botones: es **probar que la semántica del sistema cambió correctamente** (simetría next/prev, 422 observable, página vacía). Si Behat pasa aquí, PR4 es puramente sustractivo.

---

## 2. Decisiones selladas (Sergio, 2026-06-11)

| ID | Decisión | Enforcement | Dónde vive |
|---|---|---|---|
| **D-A11y** | Control prev/next **deshabilitado + visible + `aria-disabled`**, nunca oculto. ADR > `pwa/CLAUDE.md`. | UI render `disabled={!has*}` (no condicional). **Patch obligatorio** a `pwa/CLAUDE.md:411–413` con la excepción. Test Vitest: link `null` → `toBeDisabled()`, no ausente. | Story 1.4 Task 6 + Task 11 |
| **D-Obs** | Métricas = **log estructurado JSON** (Monolog), cero infra nueva. NO OTel/Prometheus en PR3. | **Schema de eventos fijo** (no logs libres): `event` como discriminador estable. Ver §4. | Story 1.4 Task 10 |
| **D-Cap** | **La UI jamás emite `limit > WirePaginationPolicy.MAX_LIMIT (100)`.** | Hard en **ambos** lados: backend `#[Assert\LessThanOrEqual(100)]` → 422 (1.3); UI recorta opciones a `[25,50,100]` + clamp contra **constante única** de techo (1.4). | 1.3 DTO + 1.4 Task 4/Task 6 |

---

## 3. Invariantes de wire-consistency (el riesgo real del release)

Propiedades que **deben** sostenerse a la vez en API y PWA. Cada una es criterio de review del PR; romper una invalida el contrato.

- **W1 — Shape constante del envelope.** `{hasNext: bool, hasPrev: bool, count: int|null, links: {next: string|null, prev: string|null}}`. `links.next`/`links.prev` **siempre presentes**, `null` cuando no aplican. Prohibido `skip_null_values` (API) y opcionalidad de `links` (PWA `string | null`, no `?`). El guard `isBankSearchResponse` de la PWA **rechaza** el envelope viejo.
- **W2 — Una sola serialización de params.** La construcción del query string de navegación vive en **un solo sitio** (el servidor, vía `links` — ver §4/OQ-4). El cliente no reconstruye params para navegar → cero divergencia API/PWA en cómo se serializan `filters[]`/`sort`/`direction`/`limit`.
- **W3 — `MAX_LIMIT` único.** El techo 100 tiene una fuente de verdad por lado (`WirePaginationPolicy.MAX_LIMIT` en API; constante espejo en PWA) y ningún path puede emitir/aceptar por encima (D-Cap). Selector PWA derivado de esa constante.
- **W4 — Autoridad semántica del wire.** La dirección la decide **exclusivamente** el param (`after`/`before`). El `dir` del payload del cursor es *integrity binding* (discrepancia → 422 `invalid-cursor`), jamás fallback de navegación. El cliente **nunca decodifica ni fabrica** cursores: reenvía `links` verbatim.
- **W5 — Toda invalidez de cursor es 422 observable.** Las 4 causas (signature/version/payload/fingerprint) → mismo `type: invalid-cursor`, indistinguibles para el cliente, vía pipeline RFC 9457. Cero degradación silenciosa, cero `JsonResponse` manual. Cada causa emite su evento de observabilidad (§4).
- **W6 — `after` XOR `before`.** Ambos presentes → 422 `validation-failed` en mapping (API). La PWA garantiza por construcción que nunca envía los dos (`buildSearchParams` asserta).
- **W7 — Página vacía ≠ error.** Hueco lógico / fin → 200 `items: []` con flags coherentes. **CORRECCIÓN sellada en dev 1.3 (AC#5/W7):** una `before` vacía da **`hasNext=true, hasPrev=false`** (la página de la que vienes está *adelante*, recuperable vía `links.next`); una `after` vacía da el espejo. La prosa original "`before` vacía → `hasPrev=true`" era un **mislabel** — el comportamiento real, pineado por `BankSearchCursorFunctionalTest`, deriva de la misma fórmula direccional que una página poblada (no es un estado bidireccional simétrico). Espejo exacto entre el fix #3 del engine (1.3) y el escenario Behat (1.4).
- **W8 — Cursores descartados al cambiar la query.** Cambio de `sort`/`direction`/`filters`/`limit` → la PWA descarta ambos cursores (defensa en profundidad sobre el fingerprint; la UX no depende del 422).
- **W9 — Frontera engine/wire: ownership del envelope de navegación (OQ-4).** El engine produce `Page<T>` + cursores **opacos**; **jamás** conoce URLs/rutas/query strings y el `Page` no transporta links. `SearchResponder` es el **único compositor** de `links.next`/`links.prev` (materialización de URL solo en la frontera HTTP). El cliente solo consume `links` verbatim; ni cliente ni engine reconstruyen. _Criterio de review: cero símbolos de URL/ruta en el engine y en `Page`._
- **W10 — Linkability (engine-side, sellado en dev 1.3).** `hasNext ⇒ nextCursor != null` y `hasPrev ⇒ prevCursor != null` (**no** el converso — una última página puede portar un cursor frontera cuyo link sigue siendo `null`). El responder gatea cada link por el **flag**; W10 garantiza que cuando el flag es `true` el cursor correspondiente existe, así que un affordance real nunca produce un link `null`. Para el caso vacío el engine acuña un **recovery cursor** (re-firma los valores frontera del cursor entrante bajo la dirección opuesta), de modo que el flag verdadero siempre lleva un link usable. Elimina por construcción `{hasNext:true, links.next:null}`.
- **W11 — Single-composer binding del consumidor (no-reconstruction client-side) — el invariante que 1.4 cierra.** Corolario obligatorio de W9 en el lado cliente: la PWA **NO reconstruye, deriva ni recompone** `links.next`/`links.prev` en **ningún flujo funcional normal**. Navega reenviando los `links` del envelope **verbatim** (tras `safeHref` + validación same-origin/relativa). `buildSearchParams` con `after`/`before` es **exclusivamente** un fallback de navegación inicial/recuperación (primera página sin cursor; seam "ir a fecha" — hoy diferido), **nunca** el camino de navegación primario ni una reconstrucción del envelope. Cualquier uso activo de reconstrucción en flujo normal = violación de W9/W2, **aunque sea "defensivo"** (es un segundo compositor silencioso). _Enforcement (1.4): criterio de review del PR + aserción de test — la navegación next/prev normal usa SOLO `links.*` (round-trip verbatim, verificado en Behat); el path de `buildSearchParams` queda cubierto por unit test SOLO en su rol de primera-página, sin `after`/`before` en flujo normal._ **Realización (Modelo A2, sellado 2026-06-11 — refina A): navigator en app-layer, puerto de dominio link-free.** El cliente **nunca parsea el link — ni el dominio ni el adaptador**; se reenvía **verbatim**. (Parsear el link en el adaptador del cliente para extraer cursor/filtros y reconstruir un command sería decompose+recompose = reconstrucción que viola W2/W11; ese patrón command/parse es del **servidor** —`SearchQuery`→`SearchCriteria`→engine, ya en 1.3—, no del cliente, que no tiene engine.) Dos seams: **(1)** puerto de dominio `BankRepository.search(criteria)` **link-free** (`BankSearchCriteria` = filters/sort/limit, sin cursor) para primera página / cambio de query; **(2)** puerto de **application** `BankSearchNavigator.follow(link)` (impl en infraestructura `ApiBankSearchNavigator`) para next/prev — hace **same-origin/relativo check + `safeHref` + `httpClient.get(link)` verbatim**, sin abrir el link. El `string` de transporte **jamás toca el puerto de dominio** (vive en application, no en domain). La UI: primera página → `SearchBanks.run(criteria)`; next/prev → `BankSearchNavigator.follow(envelope.links.next!)`. `buildSearchParams` queda **filters-only**. W11 = garantía **estructural**: un cursor solo entra a una request vía el link del servidor reenviado verbatim, nunca reconstruido. _Nota seguridad: `safeHref` bloquea esquemas peligrosos pero NO origen externo → el navigator añade un check explícito relativo/mismo-origen (rechaza `http(s)://host-externo`)._

---

## 4. Resolución de las open questions (impacto wire-consistency)

- **OQ-4 — Contrato link↔param → RESUELTO como OWNERSHIP, no como formato (Modelo C, híbrido canónico).** La pregunta correcta no es "qué formato tiene el link" sino **"quién compone el envelope de navegación"**. Decisión:
  - **Engine (1.3): link-agnóstico.** `DoctrineSearchEngine` produce `Page<T>` + **cursores opacos** (`nextCursor`/`prevCursor`, base64url+HMAC+fingerprint). El engine **jamás conoce URLs, rutas ni query strings** — generación de links no es lógica de dominio. El `Page` no transporta links.
  - **`SearchResponder` (1.3): único compositor de links.** Es el **único** lugar donde un cursor opaco se materializa en `links.next`/`links.prev` (URL relativa completa del mismo endpoint, `after`/`before` sustituido, `limit`/`sort`/`direction`/`filters[]`/`paginationMode` preservados). La materialización de URL ocurre **solo en la frontera HTTP**, no en el dominio ni en el engine.
  - **Cursor = único state primitive de navegación.** No hay otra fuente de estado de navegación.
  - **Cliente (1.4): solo consume.** Reenvía `links.*` **verbatim** (tras `safeHref` + validación same-origin/relativa). **Ni el cliente ni el engine reconstruyen** la URL — cero doble serialización (W2).
  _Por qué Modelo C y no "server-owned envelope" a secas: mantener la materialización de URL confinada a `SearchResponder` preserva la separación engine/wire, no filtra routing al dominio, mantiene los tests puros de equivalencia engine→Page (sobre datos, no sobre URLs) y conserva el determinismo Behat. El formato (URL relativa completa) es consecuencia de la decisión de ownership, no la decisión en sí._ Consecuencia: `buildSearchParams` con `after`/`before` (AC #2 de 1.4) es **path secundario/defensivo** (primera página, seam "ir a fecha"), no el camino de navegación primario. **Criterio de review:** ningún símbolo de URL/ruta/link aparece en el engine ni en `Page`; `SearchResponder` es el único compositor.
- **OQ-5/OQ-6 — Locus de emisión happy-path → RESUELTO: API-side, en el responder de búsqueda.** `cursor_version` y `keyset_navigation` se emiten donde el request portador de cursor se sirve con éxito (responder/handler de `BankSearchController`), no en `ExceptionResponder` (que solo ve errores). `invalid_cursor` sí cuelga de `ExceptionResponder::buildLogContext`. Patrón del repo: observabilidad API-side por log estructurado, no telemetría client happy-path.
- **Schema de eventos (D-Obs, vinculante):**
  - `{ event: "invalid_cursor", cause: "signature|version|payload|fingerprint", route, cursor_v?: int }` — **nunca el cursor crudo** (NFR1).
  - `{ event: "cursor_version", v: int, route }`.
  - `{ event: "keyset_navigation", direction: "next|prev", route }`.
  - `event` es el discriminador estable que habilita agregación por parsing. Documentado en el runbook.
- **Runbook → ubicación `docs/runbooks/cursor-pagination.md`** (convención nueva). Requisito de operabilidad, **no opcional**. Mínimo: detectar `invalid_cursor`; interpretar `invalid_cursor_count{cause}` (pico `version|fingerprint` post-deploy = bug de encoding **o bump esperado de `v`** → verificar el bump, **no rotar secretos**); **diferenciar legacy-fallback activo vs keyset-path activo** (la válvula `pagination_mode` env-gated).

---

## 5. Checklist operativo de cierre (ordenado)

> Un único worktree `feat/api-keyset-pagination-<suffix>` compartido por 1.3 y 1.4. Nunca en `main`.

1. [ ] **API primero (1.3):** flip del envelope + repos por composición + DTO `after`/`before` + 422 `invalid-cursor` + válvula. Gate estático: `php.stan` por archivo, `php.quality` verde (incluye `php.lint.error-contract` — fila `invalid-cursor` añadida).
2. [ ] **Observabilidad (1.4 Task 10):** `cursor_cause` en `ExceptionResponder::buildLogContext` + eventos `cursor_version`/`keyset_navigation` en el responder, **con schema §4**. Documentar el per-error log line en `docs/api-error-contract.md` (NFR26) — edición única coordinada con la fila de 1.3.
3. [ ] **PWA (1.4):** tipos `PageEnvelope`/`PaginationLinks` → puerto Bank → adaptador (guard rechaza envelope viejo) → `buildSearchParams` (`after`/`before` excl. + clamp `MAX_LIMIT`) → estado direccional (descarta cursores en cambio de query) → control `disabled`-not-hidden → selector `[25,50,100]`. `pwa.quality` + Vitest verdes; `tsc` estricto limpio.
4. [ ] **Behat (1.4 Task 9):** 8 escenarios page-based migrados al envelope nuevo + 3 nuevos (simetría next×3/prev×3 con empates masivos, 422 `invalid-cursor`, página vacía → 200 incl. `before` vacía `hasPrev=true`). `make php.behat` verde — **gate del PR combinado (R3)**.
5. [ ] **e2e Playwright (CI):** `banks.spec.ts` pagination `toBeHidden`→`toBeDisabled`; mock cursor-only. No corre en local (browsers ubuntu26.04) — verde en CI.
6. [ ] **Docs (AR18):** `docs/architecture-api.md`, `api/docs/adding-endpoints.md`, `docs/api-error-contract.md`, `docs/architecture-pwa.md`, `pwa/docs/`, **`docs/runbooks/cursor-pagination.md`** (nuevo), patch `pwa/CLAUDE.md` (excepción D-A11y).
7. [ ] **Invariantes §3 (W1–W11):** verificar cada uno como criterio de review. W10 (Linkability) ya cerrado en 1.3; **W11 (no-reconstruction client-side) es responsabilidad de 1.4** — aserción de test obligatoria, no solo prosa.
8. [ ] **Revertibilidad (R-Rollback):** revertir el merge de PR3 restaura el wire legacy **sin tocar PR1/PR2**; el kernel y el engine siguen compilando y pasando sus suites con PR3 revertido. Ningún cambio de PR3 reescribe piezas de PR1/PR2.
9. [ ] **Self-review de seguridad** (frontend + backend, checklist CLAUDE.md): open-redirect/XSS en `links` reenviados (`safeHref`, same-origin), cursor crudo nunca en logs, RFC 9457 sin leaks, binds parametrizados.
10. [ ] **Protección de `main`:** PR3 se prepara y se detiene. **El merge lo decide Sergio**, por-merge. Nunca force-push ni merge sin permiso explícito.

---

## 6. Definición de "PR3 hecho"

PR3 está listo para que Sergio decida el merge cuando: **(a)** los invariantes W1–W11 se sostienen (W11 verificado por aserción de test de 1.4, no solo prosa); **(b)** el gate conjunto (R3) está verde; **(c)** las 3 decisiones selladas (§2) están implementadas y documentadas; **(d)** el runbook existe y diferencia legacy-fallback vs keyset-path; **(e)** la revertibilidad (paso 8) está verificada como criterio de review. Sin los cinco, hay deuda arquitectónica oculta y no se mergea.

---

## 7. API Contract Freeze — Story 1.3 / PR3 (lado API) · SELLADO 2026-06-11 (Sergio)

**Declaración de estado:** el contrato wire del lado API de PR3 está **funcionalmente completo y CONGELADO**. El feature development en 1.3 **se detiene aquí**. El core (engine → `Page` → `SearchResponder` → `links` → tests → revertibilidad) está cerrado, verificado contra Postgres real y revert-probado (Task 13, empírico). A partir de este punto, cualquier cambio en 1.3 no aumenta capacidad — solo añade superficie de riesgo a un contrato ya verificado.

### 7.1 Superficie congelada (inmutable sin reabrir el ADR `architecture-keyset-pagination.md`, IMPLEMENTATION LOCKED)

Un diff de PR que toque **cualquiera** de estos símbolos/decisiones **reabre diseño** y es un **review-stop**: debe re-justificarse contra el ADR antes del merge.

| Frozen surface | Qué queda sellado |
|---|---|
| `Erpify\Shared\Domain\Search\Page` | shape (`items, hasNext, hasPrev, count?, nextCursor?, prevCursor?`), cursores **opacos**, link-agnosticismo (W9), VO de dominio sin imports de framework (NFR5) |
| `…\Doctrine\Search\DoctrineSearchEngine` | pipeline de 8 pasos, firma `paginate(...)`, recovery cursor del caso vacío (W10), +1 trick, resolución de `OrderByColumns` interna |
| `…\Http\Responder\SearchResponder` | **único compositor** de `links`; materialización de URL solo en la frontera HTTP (W9); rebuild desde el DTO validado (W2) |
| `…\Http\Responder\PaginationMeta` | envelope v2 `{hasNext,hasPrev,count,links:{next,prev}}`, shape constante, **sin `skip_null_values`** (AR20) |
| Semántica de cursor | codec base64url+HMAC-32+fingerprint; 4 causas → 422 `invalid-cursor` indistinguible; `dir` = integrity binding, jamás fallback de navegación (AR21) |
| DTO/criteria | `after` XOR `before`, `limit` 25/100, **cero `page`** |
| Decisiones selladas | OQ-1 (puerto = `Page`), OQ-4/W9 (ownership Modelo C), W2 (una sola serialización de params), W10 (Linkability `hasNext⇒nextCursor!=null`), **AC#5/W7 corregido** (`before` vacía ⇒ `hasNext=true, hasPrev=false`) |

### 7.2 Definition of Done — lado API (1.3)

**Cumplido (sellado):**
- [x] Gate estático verde — `make php.quality` EXIT=0, 804 unit, sin baselines nuevas (solo el FP-DI estructural).
- [x] Proof-of-wire verde vs Postgres real — `BankSearchCursorFunctionalTest` (8 tests / 453 asserts).
- [x] Revertibilidad probada estructural + empírica — Task 13 (revert restaura el wire legacy sin tocar PR1/PR2).
- [x] Docs sellados — Task 14 (`architecture-api.md`, `adding-endpoints.md`, `api-error-contract.md`, `source-tree-analysis.md`) + FR14 documentado.

**Pendiente — NO es API feature work; no reabre el contrato §7.1:**
- [ ] Behat verde en el envelope nuevo → **Story 1.4** (gate conjunto R3; sin esto no se mergea PR3).
- [ ] Perf gate de staging → **Task 12** (observabilidad de rendimiento; condicional al entorno, no lógica de dominio).
- [ ] Self-review de seguridad final + limpieza de `deferred-work.md` (#2/#3 ya resueltos por PR3) → **Task 15** (auditoría/higiene de cierre, no construcción).

### 7.3 Direcciones permitidas a partir de aquí (solo dos)

- **A. Observability / tooling** — Task 12 (perf/staging), métricas/logs/runbook. No toca el wire contract.
- **B. Consumer adaptation** — Story 1.4 (PWA: tipos + puerto + adaptador + estado direccional + UI; Behat migration). Consume el contrato **verbatim**; **no reabre** diseño del API. **Debe sellar W11** (§3): no-reconstruction client-side de los `links`, con `buildSearchParams(after/before)` confinado a fallback de primera-página/seam — enforced por aserción de test, no solo prosa. 1.4 es el único test de invariantes del wire que queda; si su Behat pasa, PR4 es puramente sustractivo (R4).

### 7.4 Diferido fuera de PR3

- **Task 10 — seam "ir a fecha" server-side (AC#6 / FR5):** diferido de PR3. Es el **único** cambio de comportamiento real pendiente y toca navegación temporal del cursor → riesgo de reabrir W9/W10. Sin spec UX en alcance. Se retoma como **trabajo aislado post-freeze**, nunca dentro del flip. Registrado en `deferred-work.md`.
