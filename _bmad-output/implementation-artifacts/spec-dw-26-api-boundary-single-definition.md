---
title: 'DW-26: el límite /api del gate de sesión y del firewall salen de una sola definición'
type: 'bugfix'
created: '2026-09-28'
status: 'done'
baseline_revision: '095ab12dc783cfd8bc81a863fad93fa8b2a1dbd2'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: []
deferred:
  - summary: >-
      Registrar en docs/rules/security.md (y, si aplica, PRODUCTION_SECURITY_CHECKLIST.md) el patrón «un límite por path se evalúa como el router: PathRequestMatcher sobre el path decodificado, nunca str_starts_with(getPathInfo())».
    evidence: |-
      Blind Hunter: el bypass /%61pi/v1/me existía porque un listener comparaba el path crudo mientras firewall y router decodifican. Hoy no queda ningún '/api/' literal en api/src (git grep), pero ninguna regla escrita ni gate impide reintroducir un chequeo crudo. Diferido porque el arreglo edita ficheros de reglas para agentes (docs/rules).
    location: >-
      docs/rules/security.md
    severity: low
---

<intent-contract>

## Intent

**Problem:** `SessionAdmissionGate` (y otros seis listeners) deciden "¿es petición API?" con `ApiRequestMatcher` = `str_starts_with($pathInfo, '/api/')`, mientras el catch-all de `access_control` exige `IS_AUTHENTICATED_FULLY` sobre la regex `^/api` evaluada por `PathRequestMatcher` de Symfony, que además aplica `rawurldecode()` al path. Todo lo que el firewall autentica y el matcher no ve (`/api`, `/apiX`, y — medible hoy — `/%61pi/v1/me`, que el router también decodifica y sirve) queda autenticado pero SIN gate de sesión: una sesión revocada con cookie válida pasa.

**Approach:** Declarar el límite una sola vez: una constante pública `ApiRequestMatcher::PATH_PATTERN = '^/api'`, evaluada con el mismo `PathRequestMatcher` que usa el firewall (misma regex, misma decodificación); `security.yaml` referencia esa constante con `!php/const` en el catch-all, y el gate de public-access la lee de ahí. Un test de acuerdo compara, sobre sondas, el `security.access_map` del contenedor con el matcher.

## Boundaries & Constraints

**Always:** el matcher reutiliza `Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher` (no reimplementa la semántica); el catch-all sigue siendo la ÚLTIMA regla y exige `IS_AUTHENTICATED_FULLY`; `make php.lint.public-access` y `lint:yaml --parse-tags` siguen verdes.

**Never:** estrechar el catch-all a `^/api/` (volvería anónimo `/api`/`/apiX`); tocar ni ensanchar ninguna exención `PUBLIC_ACCESS` ni `.public-access-exemptions`; cambiar el orden/prioridad de listeners.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| API normal | `/api/v1/me` | matcher true; access_map exige auth | — |
| Raíz exacta | `/api` | matcher true (antes false) | — |
| Prefijo pegado | `/apiX`, `/api-docs` | matcher true (antes false), igual que el firewall | — |
| Percent-encoded | `/%61pi/v1/me` | matcher true (antes false); gate corre | con store caído → 503, no 200 |
| Fuera de la API | `/`, `/ap`, `/.well-known/mercure`, `/_wdt/x`, `/x/api` | matcher false; sin regla de access_control | — |

</intent-contract>

## Code Map

- `api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php` -- dueño único del límite; hoy `API_PATH_PREFIX='/api/'` + `str_starts_with`. Consumidores (sin cambios de firma): `SessionAdmissionGate`, `UnauthenticatedAccessListener`, `InvalidCurrentPasswordAuditListener`, `AccessDeniedAuditListener`, `AccessLogAuditListener`, `ExceptionResponder`, `RateLimitListener`; sus tests unitarios hacen `new ApiRequestMatcher()` (mantener ctor sin argumentos).
- `api/config/packages/security.yaml:85` -- catch-all `{ path: '^/api', roles: IS_AUTHENTICATED_FULLY }`; el `YamlFileLoader` del DI parsea con `PARSE_CONSTANT | PARSE_CUSTOM_TAGS`, así que `!php/const` resuelve. Prosa de las líneas 73-77 describe el alcance del catch-all.
- `api/vendor/symfony/security-bundle/DependencyInjection/SecurityExtension.php:990` -- el firewall construye `PathRequestMatcher($path)`; `PathRequestMatcher::matches` = `preg_match('{'.$regexp.'}s', rawurldecode($pathInfo))`.
- `api/tests/Support/PublicAccessExemptions.php:43,80,86` -- `Yaml::parseFile` sin flags: con `!php/const` lanzaría; necesita `Yaml::PARSE_CONSTANT` (al menos en `securityConfig()`).
- `api/tests/Support/PublicAccessExemptionRules.php:24` -- `CATCH_ALL_PATH = '^/api'` duplicado → derivarlo de `ApiRequestMatcher::PATH_PATTERN`.
- `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php:26` -- docblock ya habla del "`^/api` matcher"; queda exacto.
- `api/tests/Functional/Iam/Session/SessionStoreUnavailableAdmissionTest.php` + `Fixtures/UnavailableSessionRepository` -- patrón para la prueba HTTP (gate corre ⇒ 503).
- `api/frankenphp/Caddyfile:116` -- el matcher `@pwa` excluye `/api*`, así que `/api`, `/apiX` llegan a Symfony.

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php` -- sustituir el prefijo por `public const string PATH_PATTERN = '^/api'` y delegar en `PathRequestMatcher(self::PATH_PATTERN)`; docblock: por qué la constante es la del firewall y por qué la decodificación importa.
- `api/config/packages/security.yaml` -- catch-all `path: !php/const Erpify\Shared\Http\Infrastructure\ApiRequestMatcher::PATH_PATTERN`; ajustar la prosa del comentario.
- `api/tests/Support/PublicAccessExemptions.php` / `PublicAccessExemptionRules.php` -- parsear `security.yaml` con `Yaml::PARSE_CONSTANT`; `CATCH_ALL_PATH = ApiRequestMatcher::PATH_PATTERN`.
- `api/tests/Unit/Shared/Http/Infrastructure/ApiRequestMatcherTest.php` (nuevo) -- tabla de la I/O Matrix contra el matcher.
- `api/tests/Functional/Shared/Http/ApiBoundaryAgreementTest.php` (nuevo, `KernelTestCase`) -- para cada sonda: `security.access_map->getPatterns($request)[0]` no nulo ⇔ `ApiRequestMatcher::matches` true, y toda sonda con `IS_AUTHENTICATED_FULLY` es matcheada; incluye sondas percent-encoded.
- `api/tests/Functional/Iam/Session/…` (nuevo o ampliando el existente) -- `/%61pi/v1/me` autenticado con store caído → 503 `service-unavailable`.

**Acceptance Criteria:**
- Given un cliente autenticado y el almacén de sesiones caído, when pide `GET /%61pi/v1/me`, then la respuesta es 503 `service-unavailable` (el gate corrió), no 200.
- Given el contenedor de test, when se evalúa cada sonda, then toda ruta a la que `access_control` aplica alguna regla es una ruta que `ApiRequestMatcher` reconoce y viceversa.
- Given `security.yaml`, when corre `make php.lint.public-access`, then pasa y ninguna exención cambia.

## Spec Change Log

## Review Triage Log

### 2026-09-28 — Review pass
- verdicts: 15 findings — high 0, medium 2, low 11, false 2, maybe-false 0
- findings:
  - `[low]` `[patch]` (BH) CORS de Nelmio (`nelmio_cors.php:38`, `^/api/` sobre path crudo) contradice el «dueño único» del docblock — verificado en `vendor/nelmio/cors-bundle/Options/ConfigProvider.php:50-52`; falla cerrado (sin cabeceras CORS). Parche: docblock acotado a los listeners propios + frase sobre CORS.
  - `[medium]` `[patch]` (BH) `CATCH_ALL_PATH = ApiRequestMatcher::PATH_PATTERN` vuelve tautológico el gate default-deny — parche: literal `'^/api'` restaurado con comentario; medido: con `PATH_PATTERN='^/api/v1'` `make php.lint.public-access` sale 2, restaurado sale 0.
  - `[low]` `[reject]` (BH) los otros seis listeners amplían su alcance sin tests propios — intencionado (Design Notes); comparten el matcher probado unitaria y funcionalmente; tests por listener serían complejidad añadida sin defecto demostrado.
  - `[low]` `[patch]` (BH) docs obsoletas: `docs/adr/audit-activity-log.md:735` («frontera `/api/`», «ErrorContract mantiene su copia») y cabecera de `ApiClientErrorBufferCouplingGateTest` — reescritas.
  - `[low]` `[defer]` (BH) el patrón no queda en `docs/rules/security.md`/checklist — edita ficheros de reglas para agentes; diferido en frontmatter.
  - `[low]` `[reject]` (BH) no hay test de sesión revocada sobre `/%61pi` — el test funcional 503 prueba en la superficie HTTP que el gate corre en ese path; la rama revocada→401 es el mismo código ya cubierto.
  - `[low]` `[patch]` (BH) aserción de contención redundante tras `assertSame($covered,$matched)` — eliminada.
  - `[low]` `[patch]` (BH) sondas ausentes (`/API/v1/me`, `/%2561pi`, `/api/` en unit) — añadidas; la parte «decodificar abre acceso anónimo en `/%61pi/v1/health`» es falsa: el firewall ya decodificaba antes del cambio y ninguna regla PUBLIC_ACCESS cambió.
  - `[low]` `[patch]` (BH) el test de acuerdo construía un matcher nuevo en vez del cableado — ahora lo toma del contenedor.
  - `[medium]` `[patch]` (VG) mismo defecto que la tautología del gate (pre-verificado) — mismo parche y medición.
  - `[low]` `[patch]` (VG-otro) aserción redundante — duplicado del anterior; eliminada.
  - `[low]` `[patch]` (VG-otro) CORS limita la afirmación de «una definición» — duplicado; docblock corregido.
  - `[false]` `[reject]` (IA) el diff amplía siete consumidores y cubre paths codificados más allá del ledger — el intent exige que nada autenticado por el firewall escape al gate, y los paths decodificados sí los autentica; no es desviación.
  - `[low]` `[reject]` (IA) `/api` y `/apiX` sólo se fijan a nivel de matcher, no del gate — inalcanzables por HTTP: el router (prioridad 32) da 404 antes del firewall (8) y del gate (7); el gate consulta el matcher, cuyo acuerdo está fijado.
  - `[false]` `[reject]` (IA) no se tomó R4 (gate leyendo el access_map); una regla futura fuera de `/api` divergiría — el test de acuerdo exige igualdad, así que cualquier regla de `access_control` fuera del matcher lo pone rojo.

## Design Notes

Estrechar el firewall a `^/api/` habría "alineado" los límites abriendo `/api` y `/apiX` al anónimo, que es justo lo que el intent prohíbe; por eso se ensancha el matcher. Ensancharlo afecta también a los otros seis listeners (error contract, rate limit, auditoría), que pasan a cubrir lo mismo que el firewall — incluido el path percent-encoded, que hoy esquiva además el rate limit. Reutilizar `PathRequestMatcher` hace la equivalencia por construcción en vez de por reimplementación.

## Verification

**Commands:**
- `make php.unit c='--filter "ApiRequestMatcherTest|ApiBoundaryAgreementTest|SessionStoreUnavailable|SessionAdmissionGateTest|UnauthenticatedAccessListenerTest|RateLimitListener|ExceptionResponderTest|AccessLogAuditListenerTest|AccessDeniedAuditListenerTest|InvalidCurrentPasswordAuditListenerTest"'` -- expected: exit 0
- `make php.lint.public-access` -- expected: exit 0
- `make php.stan` y `make php.quality` -- expected: exit 0

## Auto Run Result

Status: done

**Resumen:** el límite `/api` tiene una sola definición, `ApiRequestMatcher::PATH_PATTERN = '^/api'`, evaluada con el mismo `PathRequestMatcher` que construye el firewall (misma regex, path decodificado). `security.yaml` la nombra con `!php/const` en el catch-all. Además de `/api` y `/apiX`, cierra un bypass real: `GET /%61pi/v1/me` lo autenticaba el firewall y lo servía el router, pero se saltaba el gate de sesión (y el rate limit y la auditoría). Ninguna exención PUBLIC_ACCESS cambia.

**Ficheros:**
- `api/src/Shared/Http/Infrastructure/ApiRequestMatcher.php` — constante pública + delegación en `PathRequestMatcher`; docblock.
- `api/config/packages/security.yaml` — catch-all vía `!php/const`; comentario actualizado.
- `api/tests/Support/PublicAccessExemptions.php` — parse con `Yaml::PARSE_CONSTANT`.
- `api/tests/Support/PublicAccessExemptionRules.php` — literal propio documentado (no sigue a la constante).
- `api/tests/Unit/Shared/Http/Infrastructure/ApiRequestMatcherTest.php` — nuevo, tabla de la matriz.
- `api/tests/Functional/Shared/Http/ApiBoundaryAgreementTest.php` — nuevo, `security.access_map` ⇔ matcher cableado sobre 21 sondas.
- `api/tests/Functional/Iam/Session/SessionStoreUnavailableAdmissionTest.php` — caso `/%61pi/v1/me` → 503.
- `api/tests/Functional/Shared/Monitoring/ApiClientErrorBufferCouplingGateTest.php`, `docs/adr/audit-activity-log.md` — prosa alineada con la frontera.

**Revisión:** 15 hallazgos; parches aplicados en 2 entradas medium (una agrupada) y 6 low; 1 diferido (regla en `docs/rules/security.md`); rechazados: 3 low (alcance de listeners intencionado, test revocado redundante con el 503, `/api`/`/apiX` inalcanzables por HTTP) y 2 false (ver triage). Edge Case Hunter: sin hallazgos.

**Follow-up review:** false — parcheadas 0 high, 1 entrada medium, 6 low.

**Verificación (ejecuciones frescas, exit impreso):** filtro unitario/funcional de la spec + `ApiClientErrorBufferCouplingGateTest` 0 (131 tests); `make php.lint.public-access` 0; `make php.stan` 0; `make php.quality` 0. A/B del implementador: con el `str_starts_with` antiguo los tests nuevos dan 12 fallos. Falsación del gate: `PATH_PATTERN='^/api/v1'` → public-access 2; restaurado por copia de bytes → 0. `make php.unit` completo (implementador, antes de los parches de revisión): 0.

**Riesgos residuales:** Behat no ejecutado; Nelmio CORS mantiene su propio `^/api/` sobre el path crudo (falla cerrado); que Caddy reenvíe `/%61pi/...` a PHP no se midió contra el stack vivo.
