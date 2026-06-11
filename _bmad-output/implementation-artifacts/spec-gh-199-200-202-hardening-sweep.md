---
title: 'Hardening diferido: guards FieldMapping/parseStrict, gate dual-marker, e2e cursor+sort'
type: 'feature'
created: '2026-06-11'
status: 'in-review'
baseline_commit: '56a2b8d'
context:
  - '{project-root}/docs/api-error-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tres issues de hardening diferido abiertos tras las reviews de stories 1.2/1.7/1.8: guards contra error de programador en `FieldMapping`/`parseStrict` (#199), default-type silencioso en excepciones dual-marker (#202) y falta de cobertura e2e del contrato PR3 — cursor válido + `sort` distinto → 422 `invalid-cursor` — más el pin del descarte de cursor en la PWA (#200).

**Approach:** Guards de construcción y round-trip con tests (api); contract-gate test que prohíba excepciones dual-marker sin `TYPE` explícito (api); escenario Behat de fingerprint-mismatch con un nuevo step de override de query-param; test Vitest del descarte de cursor (pwa). Una sola PR — **vertical slice alineada a la consistencia del pipeline de búsqueda** (config → parser → contrato de error → e2e → consumer), no un bundle de refactors inconexos — que cierra #199, #200 y #202.

## Boundaries & Constraints

**Always:**
- Guards nuevos: `LogicException` en construcción, mismo estilo que los guards `Contains` existentes (`FieldMapping.php:41-47`).
- Regla determinista #202: «excepción concreta con ≥2 markers ∧ `type()` vacío = declaración inválida». Enforcement **estático** vía gate CI: reflection **metadata-only**, definida operativamente como — FQCNs derivados del file-walk de `api/src`; por clase, solo `new ReflectionClass($fqcn)` + lectura de metadata (`isAbstract()`/`isInterface()`, `getInterfaceNames()`/`class_implements`, constante `TYPE` vía `getConstant()`); **prohibido** `newInstance*()`, `invoke*()` y cualquier llamada a métodos de la clase inspeccionada o al resolver. El autoload solo define la clase (nunca ejecuta constructores); el gate no depende del mapping marker→status. Scope del scan: `api/src` completo (las excepciones con markers viven en todos los contextos; vendor/tests excluidos por construcción), mismo patrón que `ErrorContractGateTest`.
- Validez de cursor = fingerprint sobre la cadena canónica AR3 `tenant|entity|filters|sort.field|sort.direction|limit` (`FingerprintCanonicalizer.php:66-76`). La canonicalización de `filters` es determinista por contrato shipped (orden total por `(field, operator, valor)` vía `strcmp`, listas `IN` ordenadas, encoding JSON injectivo — `FingerprintCanonicalizer.php:26-34,89-102`); el escenario Behat ejercita la dimensión `sort` como representativa del mismatch.
- El escenario Behat asserta el contrato PR3: 422 + `type: invalid-cursor`, nunca degradación silenciosa.
- El test PWA mockea `useBankRealtime` (flake conocido).
- `make php.stan` por PHP tocado; `make php.quality` y `make pwa.quality` al cierre.

**Ask First:**
- Si el round-trip de `parseStrict` rechaza algún caso hoy aceptado por la suite.
- Si el gate dual-marker encuentra una excepción de producción que ya viole la regla.

**Never:**
- No tipar el binding eq/in para datetime (se rechaza en construcción; YAGNI).
- No cambiar el mapping marker→status/type ni el pipeline RFC 9457; en particular, **no añadir validación runtime/pre-dispatch de dual-markers** (convertiría un error de programador bloqueado en CI en 500s a usuarios y añadiría reflexión por-request a un error path con presupuesto de rendimiento).
- No tocar envelope de paginación, endpoints, migraciones ni el valve de transición.
- No ampliar la invalidación de cursor de la PWA: el test pinea el descarte ya shipped en (filter, sort, pageSize), no añade dimensiones.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| datetime + Eq/In | `new FieldMapping(..., [Eq], requiresDateTimeValues: true)` | `LogicException` | N/A |
| uuid × datetime | ambos flags `true` | `LogicException` | N/A |
| parseStrict no canónico | parsea pero `format($format) !== $value` | `null` → 422 aguas arriba | N/A |
| parseStrict canónico | bounds temporales hoy aceptados | sin regresión | N/A |
| dual-marker sin TYPE | clase concreta con ≥2 markers y `type()` vacío | gate test falla, mensaje accionable | N/A |
| cursor + sort distinto (Behat) | seguir `links.next` con `sort` overrideado | 422, `type` = `invalid-cursor` | RFC 9457 |
| PWA: cambia sort con cursor | `activeLink !== null`, cambia `sort` | siguiente fetch sin `after`/`before` | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php:27-48` — constructor; añadir guards.
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php:247-271` — `parseStrict`.
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMappingTest.php` — estilo `test<X>Rejects<Y>`.
- `api/tests/Functional/Shared/Persistence/FilterApplierTemporalRangeTest.php` — round-trip vía applier (parseStrict es privado).
- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php:112-132,444-456` — maps y `firstMatchingMarker` (solo lectura).
- `api/tests/Unit/Shared/Application/Problem/MarkerStatusMapContractTest.php` — alojar el gate dual-marker (precedente de reflexión).
- `api/tests/Unit/Shared/Domain/Exception/DomainExceptionTest.php:42-56` — pin existente de orden de markers.
- `docs/api-error-contract.md:61` — sección de precedencia; documentar el guard.
- `api/tests/Behat/Context/HttpRequestContext.php:361-373` — step de seguir links; añadir variante con override.
- `api/features/backoffice/bank/search.feature:162-169` — escenario W5; el nuevo va al lado.
- `pwa/src/app/backoffice/banks/page.tsx:255-263` — descarte de cursor (solo lectura).
- `pwa/tests/app/backoffice/banks/banksPagination.test.tsx` — estilo de referencia.

## Tasks & Acceptance

**Execution:**
- [x] `FieldMapping.php` — guard datetime ∧ (`Eq`|`In`) + guard exclusión mutua uuid ∧ datetime (#199 ítems 1-2).
- [x] `FieldMappingTest.php` — rojo/verde de ambos guards + caso permitido (range sobre datetime).
- [x] `FilterApplier.php` — `parseStrict`: rechazar si `$dateTime->format($format) !== $value` (#199 ítem 3).
- [x] `FilterApplierTemporalRangeTest.php` — no canónico rechazado + canónicos intactos.
- [x] `MarkerStatusMapContractTest.php` — gate: ninguna excepción concreta de `api/src` implementa ≥2 markers sin `type()` explícito (#202).
- [x] `DomainExceptionTest.php` — pin con anónima dual-marker: default-type sigue orden de `implements`.
- [x] `docs/api-error-contract.md` — una línea sobre el gate.
- [x] `HttpRequestContext.php` — step que sigue un link sustituyendo solo un query-param (cursor verbatim).
- [x] `search.feature` — página 1 `sort=name`, seguir `links.next` con `sort=createdAt` → 422 `invalid-cursor` (#200 ítem 1).
- [x] `pwa/tests/app/backoffice/banks/banksCursorReset.test.tsx` — paginar, cambiar sort → fetch sin cursor (#200 ítem 2).

**Acceptance Criteria:**
- Given la suite completa, when corren los comandos de Verification, then todo verde sin baselines nuevas.
- Given la PR final, when se redacta, then footer `Closes #199, #200, #202` + self-review de seguridad del CLAUDE.md.

## Spec Change Log

- 2026-06-12 (implementación, trigger Ask-First del round-trip): la suite aceptaba `2026-03-15T08:30:00.000Z` (forma canónica JS `toISOString()`), que el round-trip byte-a-byte rechazaba — PHP parsea `Z` bajo `P` pero `format('P')` emite `+00:00`. Resolución: el round-trip acepta las dos grafías canónicas del mismo offset (`P` y su gemelo `p`), manteniendo el rechazo de toda forma no canónica. KEEP: el caso `non-canonical single-digit month` pinea que el gate sigue estricto.

## Design Notes

- **Ownership de invariantes (#199):** `FieldMapping` posee los invariantes de **configuración** (wiring de desarrollador; construcción; `LogicException` = error de programador). `FilterApplier` posee las restricciones de **forma del input** (valores del request; 422). Sin validación duplicada: garantizado datetime⇒solo-range en construcción, el applier no añade checks; `parseStrict` **no re-evalúa invariantes de `FieldMapping`** — valida exclusivamente la conformidad del valor de entrada con el formato declarado. Los guards nuevos son if-throws inline simétricos a los existentes — sin predicado compartido (4 checks de una línea; un helper con flag booleano tropezaría con PHPMD).
- **Gate dual-marker (#202):** hoy `InvalidInput`→400 e `InvalidSearchCriteria`→422 difieren también en *status* (el issue asumía 400/400) — el orden de `implements` decidiría el status. La dualidad es propiedad estática de la declaración de clase: el gate CI la rechaza antes de que exista dispatch alguno, sin tocar el resolver.
- **Step Behat (#200):** el step existente navega verbatim (W11); el nuevo preserva el método GET y los headers del request de links, muta **exactamente un** query-param (el nombrado) y byte-preserva el resto — `after` incluido — para que el 422 provenga del fingerprint, no de un cursor corrupto ni de otro param alterado.

## Verification

**Commands:**
- `make php.stan` — expected: 0 errores.
- `make php.unit c='--filter "FieldMapping|FilterApplier|MarkerStatusMap|DomainException"'` — expected: verde.
- `make php.behat c='features/backoffice/bank/search.feature'` — expected: verde con el escenario nuevo.
- `make pwa.test.unit c='tests/app/backoffice/banks/banksCursorReset.test.tsx'` — expected: verde.
- `make php.quality` y `make pwa.quality` — expected: sin findings.
