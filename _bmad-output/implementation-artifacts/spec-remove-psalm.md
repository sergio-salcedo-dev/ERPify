---
title: 'Retirar vimeo/psalm y desbloquear PHPUnit 13.2.x'
type: 'chore'
created: '2026-07-22'
status: 'in-review'
baseline_commit: '848e5c95'
review_loop_iteration: 1
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `vimeo/psalm 6.16.1` es el único paquete que topa `sebastian/diff` en `^8.0`, y `phpunit 13.2.4` exige `^9.0`. Un analizador estaba gobernando la versión del *test runner* de la aplicación, y `composer update` declinaba moverlo en silencio.

**Approach:** Retirar psalm por completo — paquetes, config `psalm-taint.xml`, target `php.psalm.taint` y job `api-taint` de CI — y subir PHPUnit a 13.2.4. Se evaluó aislarlo en un árbol Composer propio (patrón `api/tools/behat/`) y se descartó: ver Design Notes.

## Boundaries & Constraints

**Always:**
- La retirada es total: ningún residuo de config, target, job de CI, entrada de gitignore ni mención en docs.

**Never:**
- Reintroducir `vimeo/psalm` ni ningún `psalm/*` en `api/composer.json`.
- Perseguir el OOM intermitente de `php.unit` (preexistente, worktree propio `api-php-unit-oom-8z66`).
- Mergear a `main` ni forzar push.

</frozen-after-approval>

## Tasks & Acceptance

**Execution:**
- [x] `api/composer.json` -- quitar `vimeo/psalm` y `psalm/plugin-symfony` de `require-dev`; subir `phpunit/phpunit` a `^13.2.4`
- [x] `api/tools/psalm/` -- eliminar el directorio (`psalm-taint.xml`)
- [x] `make/php-quality.mk` -- eliminar `PSALM_*`, `php.psalm.taint`, su `.PHONY` y los comentarios que lo justificaban
- [x] `.github/workflows/ci.yml` -- eliminar el job `api-taint` completo y sus referencias en `needs` de `ci-summary` / `ci-success` y en el render del resumen
- [x] `api/.gitignore` + `.gitignore` raíz -- quitar el bloque `vimeo/psalm` y el residuo `api/.psalm.cache/`
- [x] Docs -- `api/CLAUDE.md`, `CLAUDE.md`, `GEMINI.md`, `api/GEMINI.md`, `api/docs/make-targets.md`, `docs/claude-code-quickref.md`, `docs/development-guide-api.md`, `docs/project-context.md`, `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `make/CONVENTIONS.md`
- [x] Issue de seguimiento para evaluar Semgrep como sustituto del análisis de taint

**Acceptance Criteria:**
- Dado el repo, cuando se ejecuta `git grep -i psalm`, entonces solo quedan menciones históricas deliberadas: la regla en `api/CLAUDE.md` y las dos líneas de docs que constatan la retirada.
- Dado `api/composer.json`, cuando se ejecuta `composer validate --check-lock`, entonces es válido y `phpunit/phpunit` está en 13.2.4.
- Dado `.github/workflows/ci.yml`, cuando se parsea como YAML, entonces `api-taint` no existe y ningún `needs` lo referencia.
- Dado el stack, cuando se ejecutan `make php.quality` y `make php.behat`, entonces ambos salen 0.

## Spec Change Log

- **Pivote de aislar → retirar (iteración 1).** La spec original aislaba psalm en `api/tools/psalm/`. La revisión adversarial (Blind Hunter + Edge Case Hunter) demostró que el aislamiento reconstruía el acoplamiento en forma peor: los autoloaders de Composer se registran con `prepend=true`, así que el vendor de la app ensombrecía las dependencias propias de psalm (`sebastian/diff` 9.0.0 frente al `^8.0` que psalm declara; Symfony 8.0 frente al 8.1 con el que se resolvió `psalm/plugin-symfony`). El arreglo correcto era wrapper `run.php` + 16 pins de Symfony, más cobertura de seguridad para 68 paquetes, `**/vendor/` en `.dockerignore` y ~20 casos límite. Ante ese coste, Sergio pidió evaluar si psalm merecía la pena. Medición decisiva: **164 PRs mergeados desde que existe el modo taint-only, sin un solo hallazgo**, y el repo ya había retirado el análisis general de psalm por fricción (`6ff5db70`). Decisión: retirar, y evaluar Semgrep aparte. **KEEP:** la regla generalizable de aislamiento de tooling sobrevive al pivote, en `api/CLAUDE.md`. El test-gate que la hacía ejecutable se descartó: vigilaba un acto explícito y visible en diff, no había reincidencia que lo justificara, y mantenerlo habría aplicado a código propio un listón más blando que el usado para retirar psalm.

## Design Notes

**Por qué retirar y no aislar.** El aislamiento no era gratis: el árbol paralelo tiene semántica de ensombrecimiento de versiones que hay que entender para mantenerlo, obliga a fijar `symfony/*` en paralelo a la app para siempre, saca 68 paquetes de la cobertura de `security-checker` y Dependabot, y añade una instalación de red sin caché a un job *gating*. Contra eso, el valor medido del análisis de taint en este repo es cero hallazgos en 164 PRs — probablemente estructural, no casual: la API es JSON-only (`CLAUDE.md` prohíbe HTML de servidor), y el SQL va por Doctrine parametrizado, así que los sinks que psalm busca en su mayoría no son alcanzables.

**Lo que se pierde, explícitamente.** No queda analizador de taint / dataflow de seguridad. CodeQL no soporta PHP, así que no hay sustituto directo; Semgrep sí soporta PHP y modo taint, y queda en issue de seguimiento (Sergio tiene cuenta). Hasta entonces el control es el checklist de seguridad del `CLAUDE.md` raíz, aplicado en revisión. También termina el histórico de code-scanning de la categoría `psalm-taint`.

**Efecto colateral verificado.** Quitar psalm saca `symfony/expression-language` del vendor de la app (llegaba solo vía `psalm/plugin-symfony`), lo que regenera `api/config/reference.php`. Comprobado que la app no lo usa: sin `ExpressionLanguage` en `src/`/`config/`, sin `expression:` en security, todos los `#[IsGranted]` con permisos planos, y la suite Behat completa en verde.

## Verification

**Commands:**
- `make php.quality` -- exit 0 ✅
- `make php.behat` -- exit 0, 370/370 escenarios, 3358/3358 pasos ✅ (370 y no 368: el rebase incorporó #535, que añadió los suyos)
- `make composer c='validate --check-lock --no-check-publish'` -- válido, phpunit 13.2.4 ✅
- `ci.yml` parseado como YAML -- 10 jobs, sin `api-taint`, `needs` reajustados ✅
- `make php.unit` -- exit 0 ✅ 2070 tests / 9064 asserts sobre PHPUnit 13.2.4. El OOM que aparecía antes del merge era preexistente (aislado con experimento controlado: mismo fallo con phpunit 13.1.14, víctima variable por corrida) y lo arregló `f68ceb1f` en `main`, no este PR
