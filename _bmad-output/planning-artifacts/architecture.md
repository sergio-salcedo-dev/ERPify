---
stepsCompleted: [1]
inputDocuments:
  - docs/api-error-contract.md
  - docs/project-context.md
  - api/src/Shared/Application/Problem/ProblemDetailsFactory.php
  - api/src/Backoffice/Bank/Application/BankFinder.php
  - api/src/Shared/Application/Http/Search/SearchQuery.php
workflowType: 'architecture'
adrScope: 'http-error-status-policy-400-422-404'
project_name: 'ERPify'
user_name: 'Sergio'
date: '2026-06-09'
---

# Architecture Decision Document

_Scope: ADR acotado sobre un sistema existente — política de códigos de error HTTP (400 vs 422 vs 404) en el pipeline RFC 9457 Problem Details de la API._

---

## ADR-0001 — Política de status HTTP: 400 vs 422 vs 404 para validación de input

- **Estado:** Propuesto (pendiente de implementación; no mergeado).
- **Fecha:** 2026-06-09.
- **Decisores:** Sergio (owner), Winston (facilitador de arquitectura).
- **Ámbito:** `api/` — pipeline de errores RFC 9457 Problem Details. Punto único de mapeo: `ProblemDetailsFactory`.
- **Relacionado:** `docs/api-error-contract.md` (contrato autoritativo, NFR26), RFC 9110 §15.5.1 / §15.5.21.

### Contexto

La API expone un contrato uniforme de errores RFC 9457 (Problem Details) con un único sitio de mapeo (`ProblemDetailsFactory`) y un único listener (`ExceptionResponder`). El estado actual mapea:

- Marker `InvariantViolation` → **422** `invariant-violation`.
- Marker `InvalidInput` → **400** `invalid-input`.
- `Symfony\...\ValidationFailedException` (validación de DTO vía `#[MapRequestPayload]` / `#[MapQueryString]`) → **400** `validation-failed` (+ `violations[]`).

Este último mapeo es una decisión deliberada: el factory **desenvuelve** el 422 nativo que Symfony emite en `RequestPayloadValueResolver` y lo **re-mapea a 400** (`ProblemDetailsFactory.php:246`; documentado en `api-error-contract.md` L72).

Tres problemas motivan revisar esto:

1. **Incoherencia semántica.** `validation-failed` (400) e `invariant-violation` (422) son el mismo tipo de fallo — petición bien formada, rechazada por reglas — pero devuelven status distintos.
2. **Desalineación con la convención REST / RFC 9110.** 422 (Unprocessable Content) ya está bendecido por el core de HTTP (dejó de ser exclusivo de WebDAV) y es el código idiomático para "entendí el cuerpo, pero los valores violan reglas".
3. **Drift PWA↔API ya existente.** La PWA ya asume 422 para validación: `error-gallery` documenta `validation-failed` como **422** (`pwa/src/app/dev-tools/error-gallery/page.tsx:142-147`) y `ProblemDisplay.tsx:56` ya maneja `hasViolations || status === 422`. La API responde 400 → hay deriva entre lo que la PWA documenta/espera y lo que el backend emite.

Además, un caso de path param (`GET /banks/<id>`) viaja hoy por el **mismo** bridge `validation-failed`: un UUID malformado se valida con `Validator::ensure($id, [NotBlank, Uuid])` en `BankFinder::find()` → `ValidationFailedException` → 400 `validation-failed`. El caso "UUID válido pero inexistente" ya está bien resuelto: `BankNotFoundException` → **404**.

### Decisión

Adoptar un modelo de tres ejes, sin solapamiento:

| Situación | Status | `type` | Cambia |
|---|---|---|---|
| Validación de DTO Symfony — **body** (`#[MapRequestPayload]`) | **422** | `validation-failed` (+ `violations[]`) | sí (400→422) |
| Path param **malformado** (id no-UUID) | **400** | `invalid-input` | sí (deja el bridge de validación) |
| Path param **válido pero inexistente** | **404** | `not-found` (p.ej. `bank-not-found`) | no (ya implementado) |

**Justificación (RFC 9110):**

- **422** (§15.5.21) aplica al *contenido* de la petición: "entiendo el `Content-Type` y la sintaxis del cuerpo es correcta, pero no puedo procesar las instrucciones que contiene" → validación semántica del **body**.
- **400** (§15.5.1) cubre "malformed request syntax". Un path param es parte del *request target* (URI), no del contenido; un id que no es UUID es sintaxis malformada del target → 400.
- **404** para recurso ausente: la petición es válida, pero el recurso no existe.

Modelo mental resultante:
- **400** = "no puedo ni parsear tu *target*/sintaxis".
- **422** = "parseé tu *body*, pero los valores violan reglas".
- **404** = "bien formado, pero el recurso no existe".

### Fuera de alcance (consecuencia documentada, no decisión)

**Query params** (`#[MapQueryString]`, p.ej. `page`, `limit`, `ids[]`). No se delibera su semántica en este ADR. Por construcción del contrato (**un `type` = un `status`**), los query params comparten la excepción `ValidationFailedException` y el `type: validation-failed` con el body; por tanto **acompañan al body a 422** automáticamente. Separarlos exigiría un `type`/marker nuevo — trabajo explícitamente diferido. Caso gris aceptado: `page=abc` (coerción de tipo) queda en 422 en vez de 400.

### Alternativas consideradas y descartadas

1. **Status quo (todo a 400).** Descartada: perpetúa la incoherencia con `invariant-violation` y el drift con la PWA.
2. **Split consciente del origen para query (query→400, body→422).** Descartada: requiere un `type`/marker nuevo y ramificar el bridge por origen → más código, más superficie de test/doc, sin demanda real de consumidor (Regla de Tres).
3. **404 también para el path malformado.** Descartada: mezcla dos diagnósticos y da un error menos accionable que un 400 con `title` claro ("id is not a valid UUID"). Se acepta solo el 404 para el caso "válido pero ausente".

### Consecuencias

**Positivas:**
- Contrato coherente: validación de input semántica = 422 en todos los casos (body + query ride-along), alineado con `invariant-violation`.
- Alineación con RFC 9110 y la convención REST dominante.
- **Elimina el drift PWA↔API existente** (el `error-gallery` pasa de incorrecto a correcto). 0 cambios de código en PWA.

**Negativas / costes:**
- El path-id malformado **pierde `violations[]`** al pasar a `invalid-input` (aceptable: un id suelto no necesita el detalle por campo).
- Cambio de contrato de cara al exterior (status 400→422 para validación). Mitigación: la PWA enruta por `body.type` (FR44), no por status, así que está aislada; pero cualquier consumidor externo que ramifique por status debe avisarse.
- El query param queda en 422 sin deliberación específica (compromiso gris aceptado arriba).

### Plan de migración (change set)

1. **Prod — factory:** `api/src/Shared/Application/Problem/ProblemDetailsFactory.php:246` → `Response::HTTP_BAD_REQUEST` ⇒ `Response::HTTP_UNPROCESSABLE_ENTITY`. El literal `type: 'validation-failed'` (L244) no cambia.
2. **Prod — BankFinder:** `api/src/Backoffice/Bank/Application/BankFinder.php` → para el UUID malformado lanzar un `InvalidInput` (p.ej. `InvalidBankIdException extends DomainException implements InvalidInput`) en vez de `Validator::ensure($id, [Uuid])`. El paso `findById() ?? throw BankNotFoundException` (404) se mantiene.
3. **Doc (NFR26 — gate CI):** `docs/api-error-contract.md` → fila de la tabla L66 (`ValidationFailedException` 400→422) e invertir la prosa de L72 (hoy justifica el re-map a 400; pasa a justificar el 422 + por qué el path-id sale por `invalid-input`).
4. **Tests PHP:**
   - `ProblemDetailsFactoryTest` — ~3 aserciones de status 400→422 (L1018, L1451, L2249) + renombrar `testValidationFailedExceptionMapsTo400...`. (Las de `InvalidInput`/`HTTP_STATUS_TYPE_MAP` en L150/579/754/900 **no cambian**.)
   - `ExceptionResponderTest:490` → 422.
   - `ExceptionResponderFunctionalTest:315,322` → 422 / `HTTP_UNPROCESSABLE_ENTITY` (el nivel de log sigue `warning`: 422 es 4xx).
   - `ProblemDetailsResponderTest` (L61/132) → alinear status a 422 (cosmético).
5. **Behat:**
   - `features/shared/error_contract/validation_violations.feature` + `features/backoffice/bank/create.feature` → 400→**422** (siguen `validation-failed`).
   - `features/backoffice/bank/search.feature` (incl. `ids[]=invalid`, que es query) → 400→**422**.
   - `features/backoffice/bank/get.feature` (path-id) → **se queda en 400** pero `type` pasa `validation-failed`→`invalid-input` y se eliminan las aserciones de `violations`.
6. **PWA:** 0 cambios.

### Verificación

- `make php.unit` y `make php.behat` verdes tras actualizar aserciones.
- `make php.stan` + `make php.quality` sobre los archivos PHP tocados.
- Invariantes intactos: `MarkerStatusMapContractTest` (`InvariantViolation→422`, `InvalidInput→400`) no se toca; `ProblemDetailsApiSchemaSweepTest` es agnóstico al status (solo exige no-2xx + conforme RFC 9457).
- Smoke manual: `curl -i /api/backoffice/banks/abc` → 400 `invalid-input`; `curl -i /api/backoffice/banks/<uuid-inexistente>` → 404; `POST /api/backoffice/banks` inválido → 422 `validation-failed` con `violations[]`.

### Riesgos y rollback

- **Rollback de prod trivial:** revertir la línea 246 (y el cambio de `BankFinder`). El grueso del diff es contrato/doc/tests, bajo riesgo de runtime.
- **Riesgo externo:** consumidores no-PWA que ramifiquen por status (no por `type`). Auditar antes de mergear si existen integraciones externas.

### Notas de implementación

- Implementar en un worktree (`.claude/worktrees/`), rama `feat/api-error-status-422`.
- Cambio de contrato público → requiere la actualización de `docs/api-error-contract.md` en el **mismo PR** (NFR26, el gate de CI lo exige).
- `main` está protegida: preparar PR y parar; el merge lo decide Sergio.
