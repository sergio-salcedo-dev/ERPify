---
title: 'GH #760 + #420 — asentar la navegación dura y declinar el trait StringValue'
type: 'chore'
created: '2026-08-18'
status: 'in-progress'
baseline_commit: '85c1687cd3dd836d2692eda2d93e7bc693c8ed8b'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** dos decisiones vencidas. `@next/next/no-location-assign-relative-destination` avisa en `FetchHttpClient.ts:63`, donde sus dos remedios (`redirect()`, `useRouter()`) son inaplicables: adaptador de infraestructura, sin render ni hook. El segundo sitio con la misma llamada, `BackOfficeLayoutClient.tsx:71`, **no** se marca —la regla resuelve el argumento estáticamente y no ve la constante—, así que un lint verde no prueba ausencia del patrón. Y #420 fijó como disparador el **tercer** VO de string único — criterio que la medición refuta por mecánico: los tres candidatos reales existen, pero comparten solo comportamiento trivial, no un contrato de construcción.

**Approach:** asentar ambas de forma que sobreviva a los gates. #760: suprimir el sitio A con el argumento, y en el B **ampliar el comentario que ya existe** nombrando la regla — sin directiva, porque está medido que ahí suma un warning `Unused eslint-disable directive` y que `make pwa.quality` la borra. #420: **declinar** la abstracción con la evidencia medida y un disparador nuevo falsable; cero código.

## Boundaries & Constraints

**Always:** el argumento viaja con el código, no con el issue. La regla se nombra en **A y en B**, un fichero cada uno. `make pwa.quality` queda verde y **no modifica** lo escrito. Comentarios en inglés.

**Ask First:** publicar cualquier comentario o cierre en GitHub (mostrar el texto antes). Tocar el seam de navegación (puerto `Navigator`), renombrar `toString()`, o editar algo bajo `api/src`.

**Never:** directiva `eslint-disable` en el sitio B. Desactivar la regla en `pwa/eslint.config.mjs`. Crear el trait `StringValue`, añadir `__toString()` o tocar los tres VO. Tocar lo reservado por `erpify-09` y `erpify-4d`: `Iam/Identity/{Application,Infrastructure/Mail}`, `Iam/Invitation/**`, `SecurityLinkMailer.php`, `api/CLAUDE.md`, `PRODUCTION_SECURITY_CHECKLIST.md`, `make/php-quality.mk`, `monolog.yaml`, `services.yaml`.

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` — `redirectToLoginOnSessionExpiry()`, línea 63: único sitio que la regla marca. Adaptador Inversify, sin React.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — `handleNavigation()`, líneas 60-71: ya justifica la navegación dura (carrera con `RequireAuth`, revoke fallido); le falta el nombre de la regla.
- `pwa/eslint.config.mjs` — sin `linterOptions`, luego rige el default de ESLint 10: `reportUnusedDisableDirectives: "warn"`.
- `make/pwa.mk:66,69` — `pwa.lint.dry-run` comprueba; `pwa.lint` es `eslint --fix` y es el que corre `pwa.quality`.
- `api/src/Iam/Identity/Domain/{Email,HashedPassword}.php`, `api/src/Iam/Session/Domain/SessionId.php` — los tres VO. **No se modifican.**

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` — `// eslint-disable-next-line @next/next/no-location-assign-relative-destination` sobre la línea 63, precedida del porqué: adaptador de infraestructura, sin render ni hook, luego los remedios de la regla no aplican.
- [x] `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — ampliar el comentario con el nombre de la regla y por qué **no** se suprime: hoy no dispara, una directiva sería `unused` y `--fix` la borraría, y la navegación dura es deliberada.
- [ ] GitHub #420 — cerrar con la evidencia medida y el disparador nuevo.
- [ ] Cuerpo de la PR — revisión de seguridad por clase; declarar que #420 cierra sin código.

**Acceptance Criteria:**
- Dado el árbol tras el cambio, cuando corro `grep -Rn "no-location-assign-relative-destination" pwa/src`, entonces hay exactamente **2** coincidencias, **una en `FetchHttpClient.ts` y otra en `BackOfficeLayoutClient.tsx`** — dos menciones en el mismo fichero no satisfacen el criterio.
- Dado ese resultado, cuando lo leo, entonces A lleva **directiva + argumento** y B lleva **argumento sin directiva**. Esa asimetría es el estado a preservar, no el número de coincidencias.
- Dado el diff guardado antes de `make pwa.quality`, cuando el gate termina, entonces el de los dos ficheros es **byte a byte el mismo**: el gate es idempotente respecto al cambio. Contar ficheros modificados no lo prueba — `--fix` los deja modificados igual tras borrar la directiva.
- Dado `api/src`, cuando termina la tarea, entonces `git diff --stat -- api/` está vacío.

## Design Notes

Medido en este worktree (ESLint 10.8 / plugin 16.3.1 / PHP 8.5.9):

1. `make pwa.lint.dry-run` → exit 0, `1 problem (0 errors, 1 warning)` en `FetchHttpClient.ts:63`. El sitio B no aparece.
2. Con una directiva plantada en B: `2 problems` — el nuevo es `Unused eslint-disable directive`.
3. `make pwa.lint` (`--fix`) la **borra**: 1 directiva antes, 0 después.

Para #420: solo **3** de las 7 clases con `toString(): string` son VO de string único (las otras 4 son compuestas), comparten ~12 líneas mecánicas y difieren en factoría, invariante y excepción. El barrido de `final readonly class` con propiedad string privada y sin `toString()` da **cero** candidatos ocultos. Y `readonly` cierra la forma obvia del trait: `Readonly class X cannot use trait with a non-readonly property` es fatal.

Disparador nuevo, literal para el cierre de #420 (inglés, como el issue):

> Reopen only when a fourth **single-string value object** appears whose state, accessor/equality semantics **and construction contract** are materially compatible with the existing three. A fourth string-backed VO with a distinct invariant or construction contract does not reopen this. Note that counting `toString(): string` over-counts: 4 of the 7 matches are composite VOs.

## Verification

**Commands:**
- `make pwa.lint.dry-run` — exit 0 y **0 warnings**. La medición previa al cambio es 1 warning (Design Notes), así que la transición a demostrar es `1 → 0`, no «sale verde»: eso descarta que la supresión esté tapando un segundo problema.
- **Idempotencia** — `git diff -- pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts pwa/src/app/backoffice/BackOfficeLayoutClient.tsx > tmp/antes.diff`, luego `make pwa.quality` (exit 0), luego el mismo `git diff` contra `tmp/antes.diff` — esperado: idénticos.
- `grep -Rn "no-location-assign-relative-destination" pwa/src` — 2 coincidencias, una por fichero nombrado.
- `git diff --stat -- api/` — vacío.
