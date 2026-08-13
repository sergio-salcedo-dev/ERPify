---
baseline_commit: 781c75a2
---

# Story BR-6: Gates de arquitectura y CI

Status: review

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · BR-6 de 8
> Issues: #250 #305 #356 #438 #589
> Rama: `fix/api-architecture-gates-br6-9b8z` · Worktree: `.claude/worktrees/api-architecture-gates-br6-9b8z`
> Base: `origin/main` @ `781c75a2` · Medido el 2026-08-12 por cinco lectores independientes

## El lote no es lo que su título dice

**Tres de los cinco issues no son trabajo de código.** El lote entra al backlog con la etiqueta «gates de
arquitectura y CI» y con un issue designado como el que muerde; medido contra `781c75a2`, ese issue está
resuelto y otros dos son obsoletos o falsos. Lo que queda es un gate nuevo pequeño, un pago parcial de
baseline, y siete correcciones de prosa que hoy generan trabajo fantasma.

> **Corrección a la épica — la línea que ordenó este lote es falsa.**
> [`epics-backlog-resolution.md:179-181`](../planning-artifacts/epics-backlog-resolution.md) afirma que
> «**#589 es el que muerde**: la mitad git-aware del gate NFR26 nunca corre porque `/app` no es un repositorio
> git dentro del contenedor, así que el contrato de error se apoya en revisión humana donde creíamos tener un
> gate». Y `:204` justifica con eso la posición de BR-6 en el orden recomendado: «un gate que no corre invalida
> la confianza en todos los demás».
> **Medido: el gate corre.** `ErrorContractGateTest.php` tiene hoy **9 tests y cero `markTestSkipped`**; las
> cadenas `resolveGitBase`, `ERROR_CONTRACT_GATE_BASE` y `merge-base` tienen **0 ocurrencias en el fichero**
> (verificado a mano, no sólo por lectura). El argumento que puso a BR-6 el tercero en el orden se quedó sin
> sujeto. La afirmación se conserva marcada en el fichero de épica, no se borra: el error es reproducible.

Y se repite el patrón que gobierna la épica, sobre el mismo arco de PRs que ya lo produjo en BR-1: **#589 lo
cerró el arco #596–#601 del 28 de julio de 2026** (`ab796333`, `7503390d`, `389f59e3`) — el mismo que arregló
el código de BR-1 y no cerró sus issues. Dos lotes distintos, el mismo arco, la misma causa: *el backlog se
pudre por lo que se entrega, no por el paso del tiempo*.

El arreglo real de #589 merece quedar escrito porque **contradice las dos opciones que el issue proponía**, y
la que parecía barata era una trampa:

- No se pasó el merge-base por `ERROR_CONTRACT_GATE_BASE`. Se **eliminó la dependencia de git**: el invariante
  se enuncia sobre el contenido actual del directorio. `ErrorContractGateTest.php:30-34` — *«Stated over the
  directory's current contents rather than over a diff: the invariant then needs no VCS context, holds in any
  checkout at any clone depth, and offers nothing to skip»*.
- Bind-montar `.git` **habría roto el flujo de desarrollo que el repo manda usar**. `CLAUDE.md` obliga a
  trabajar en worktrees enlazados bajo `.claude/worktrees/`, y ahí `.git` es un **fichero** de 81 bytes con un
  `gitdir:` que apunta fuera de cualquier mount de `./`. Verde en el checkout primario, repositorio roto en
  todos los demás.

## Decisiones

Ninguna de estas la puede tomar quien implementa. **D1–D3 fijan el alcance del lote** (qué entra y qué se
cierra sin código); **D4 es un fork de diseño** sobre 10 de los 21 pares del baseline y es el único que
arrastra un cambio de contrato.

**Las cuatro quedaron ratificadas por Sergio el 2026-08-12.** D1 no era un fork real —es el criterio de cierre
de la propia épica— y se aplica. D3 y D4 en el sentido recomendado. **D2 se remitió a consulta con Winston
(arquitectura) y Amelia (implementación) antes de fijarse**, y volvió con dos correcciones a este artefacto:
el alcance de reglas creció de 4 a 6 y la excepción que pedía el AC1 original desapareció. Ver §La consulta
de D2.

| # | Fork | Recomendación | Argumento |
|---|---|---|---|
| **D1** | #589, #250 y #438: ¿se cierran **en este PR** con comentario de evidencia medida, o se abre issue aparte? | ⬜ **Cerrar aquí, los tres** | Es el criterio de cierre de la propia épica (`epics-backlog-resolution.md:213`): *un issue solo se cierra con evidencia medida contra el código*. La evidencia ya está en este artefacto; moverla a otro sitio la desconecta de quien la midió |
| **D2** | #356: ¿entra con alcance **«imponer»** (instalar + configurar + cablear `dependency-cruiser`), o se queda en «evaluar» como dice su título? | ✅ **RATIFICADA — imponer, pero con spike y criterio de parada primero** | La evaluación de escritorio ya está hecha (§#356), pero **nadie ha ejecutado la herramienta**: las dos consultas fueron de sólo lectura. El spike de T0 convierte esa suposición en medida antes de comprometer medio día. Si cualquiera de sus tres cortes se cumple, el resultado legítimo es cerrar #356 como *evaluado, no compensa* — más barato que un gate que aparenta |
| **D3** | #305: ¿entra el **pago parcial** (4 de 21 pares) o el baseline se deja entero fuera del lote? | ✅ **RATIFICADA — entra el parcial, los 4 pares** | Los 4 pares son bendiciones/movimientos sin cambio de comportamiento, y uno de ellos (`EnumType`) es la **única violación literal de una regla escrita** en `docs/rules/architecture.md:57`. Bajan el baseline 21 → 17 |
| **D4** | Los **10 pares restantes** (`ProblemDetailsFactory` ×7, `Validator` ×6 y los 4 `ValidationFailedException` de Application): ¿se abordan ahora, se registran, o se declaran bendecidos? | ✅ **RATIFICADA — fuera de este PR, y como PR propia, no como issue** | Es un solo problema: **la excepción de Symfony *es* hoy el contrato 422 de la app**. Arreglarlo bien significa mover `Validator` a Infrastructure tras un puerto que lance una excepción de dominio, lo que reescribe el contrato de error, los `@throws` de todos los casos de uso y probablemente pasos Behat. Es un cambio de diseño del pipeline de errores, no un trinquete. **Requiere decisión de Sergio antes de tocarlo** |

## La consulta de D2 — lo que cambió del plan

Winston y Amelia respondieron por separado, sobre los mismos hechos y con preguntas distintas. **Coinciden en
imponer**, en mantener los bloques de ESLint y en no admitir baseline. Coinciden también, por caminos
independientes, en la distinción con #250: allí el segundo espejo era *aditivo* y su único residual —señal
inline— no era cobrable; **aquí el cruiser expresa lo que `no-restricted-imports` es estructuralmente incapaz
de expresar, y ESLint aporta la señal inline que el cruiser no da**. Son hermanos con roles distintos.

Que converjan no es una revisión: **ninguno ejecutó la herramienta**. De ahí T0.

Tres cosas del plan original cambiaron, y las tres a peor para el plan:

1. **La excepción del AC1 desaparece.** El artefacto pedía exceptuar explícitamente `erpify → context/shared`
   (17 aristas). Amelia: cero líneas de excepción — se enuncia en **negativo**, prohibiendo `backoffice` y
   `frontoffice`, que es como ya lo enuncia ESLint. Una lista blanca de 17 es precisamente lo que alguien
   ensancha. Winston proponía escribir la excepción; **gana el argumento de Amelia**.
2. **`tsPreCompilationDeps` viene en `false`** y **7 de las 17 aristas son `import type`**. Sin ponerlo a
   `true`, el gate nuevo sería **más débil que el ESLint que dice reforzar** — los dos bloques actuales no
   llevan `allowTypeImports`, así que hoy sí los ven.
3. **El coste real es medio día (4–6 h)**, no «un fichero de config y dos líneas». La parte pequeña es el
   config.

Y aparece un riesgo que ningún issue registra: **`pwa/tsconfig.json` declara `paths` sin `baseUrl`**. Si la
cadena `tsconfig-paths-webpack-plugin` → `enhanced-resolve` lo exige, cada `@/…` cae a irresoluble, ninguna
regla sobre `@/context/**` casa, y **el gate sale verde y ciego**. Añadir `baseUrl` no es el arreglo: cambia
la resolución de Next, `tsc` y Vitest para toda la app. La alternativa —escribir el alias a mano en
`enhancedResolveOptions`— sería una **tercera** declaración de `@/` (ya vive en `pwa/tsconfig.json` y en
`pwa/vitest.config.ts`) que deriva en silencio de las otras dos. Por eso `no-unresolvable` es obligatoria: es
lo único que convierte ese fallo silencioso en uno ruidoso.

## Realidad medida, issue por issue

### #589 · La mitad git-aware del gate NFR26 nunca corre — **YA RESUELTO**

| Afirmación del issue | Veredicto |
|---|---|
| `ErrorContractGateTest.php:179` tiene `testNewMarkerExceptionWithoutDocsUpdateIsRejected` | **Falso hoy** — el test no existe; fue borrado en `ab796333` |
| `resolveGitBase()` shellea `git merge-base HEAD origin/main` | **Falso hoy** — 0 ocurrencias de `resolveGitBase`, `merge-base` y `ERROR_CONTRACT_GATE_BASE` en el fichero |
| Alcanza `markTestSkipped` incondicionalmente | **Falso hoy** — 0 `markTestSkipped` en el fichero; 9 tests, ninguno saltable |
| `/app` no es un repositorio git en el contenedor | **Cierto y sigue siéndolo** (`api/.dockerignore` excluye `**/.git/`) — simplemente ha dejado de importar |
| Añadir un marcador sin tocar el doc pasa en verde | **Falso hoy** — `:234-251` `testEveryMarkerIsCitedInTheContractDoc` recorre todo el directorio de marcadores y exige cita backtickeada en `docs/api-error-contract.md` |

Cómo llega el doc al contenedor: `compose.dev.yaml:34-38` y `:185-189` bind-montean `./docs → /app/docs`
read-only con `create_host_path: false`. **Ambas propiedades son load-bearing** y están en §Regresiones.

Un residual real pero menor, y que **no se arregla bajo la bandera de #589**: un marcador nuevo que no se
registre en `MARKER_STATUS_MAP` sólo necesita una mención backtickeada en cualquier parte de la prosa para
pasar `testEveryMarkerIsCitedInTheContractDoc`; la fila de tabla sólo se exige a los marcadores canónicos.
Está documentado como decisión deliberada en `:37-43`, y `MarkerStatusMapContractTest.php:154` cierra
parcialmente el lazo.

### #250 · Regla PHPStan como hogar nativo del Nivel 1 — **OBSOLETO POR DEPTRAC**

El beneficio que vendía —análisis AST del Nivel 1— lo entrega `make php.deptrac` desde #304.
`deptrac.yaml:8-14` se declara a sí mismo *«the AST-aware sibling of BoundedContextGateTest»*.

**Los dos gates no son subconjunto uno del otro** — es el dato que impide retirar ninguno:

| Caso | `BoundedContextGateTest` | deptrac |
|---|---|---|
| `use` cross-context normal | ✅ | ✅ |
| FQCN inline sin `use` | ❌ | ✅ |
| `use` importado pero no usado | ✅ | ❌ |
| Módulo nuevo sin registrar en `deptrac.yaml` | ✅ auto-descubre por ruta | ❌ |
| Nivel 2 (FK Doctrine cross-context, WARNING) | ✅ único | ❌ |
| Diagnóstico inline en el editor | ❌ | ❌ |

El único residual es la **señal inline en el IDE**, y hoy **no es cobrable**: PHPStan corre dentro del
contenedor (`make/config.mk:112`) y no hay `.idea/` versionado. El coste sería montar el andamiaje entero
(`api/tools/phpstan/` son 64 líneas sin un solo `services:`/`rules:`; cero `PHPStan\Rules` en el árbol) **más
un tercer espejo permanente de los 26 seams** — hoy ya viven duplicados en `.bounded-context-allowlist` y en
`skip_violations`. Si algún día se quiere la señal inline, la vía barata es una inspección de PhpStorm o un
plugin de deptrac, no una regla propia.

### #438 · `Vendor.Symfony` no aísla los imports de Security — **PREMISA FALSA**

El hecho técnico es cierto: `deptrac.yaml:171-177` sigue siendo un colector único sin subdividir. **La
consecuencia que el issue deduce, no.**

Censo real: **47 ficheros importan `Symfony\Component\Security\*` en `api/src/`**. Cero en `Domain/`. Uno en
`Application/` (`ProblemDetailsFactory`, ya en baseline). De los 29 que viven fuera de un directorio
`Infrastructure/Security/`, **27 importan sólo atributos declarativos** (`IsGranted`, `CurrentUser`,
`IsCsrfTokenValid`) o `AccessDeniedException` — es decir, **el mecanismo de autorización que manda el ADR**.
Sólo 2 importan un servicio de runtime fuera de un directorio `Security/`, y son el escape hatch deliberado
para chequeos condicionales (`UserPatchRolesController.php:15`, `CreateInvitationController.php:12`).

El gate propuesto **rechazaría 29 usos correctos** y, como el baseline se genera y su regen sólo despoja seams
cross-context, los grandfatherearía automáticamente: **~29 entradas de deuda falsa** en el fichero cuya
cabecera dice que pagarlo es lo que rastrea #305. Un trinquete apuntando en dirección contraria.

Dos afirmaciones del issue son además falsas y conviene no heredarlas:

- **«la nota C1 de la spec de AF-1.2»** — el artefacto se borró en `e84c08a0`. Recuperado de git, la cadena
  `C1` no aparece en él; la constraint real pedía confinamiento **a nivel de capa**, que es exactamente lo que
  el gate hace hoy. Autoridad muerta y mal citada.
- **«rastreado en `deferred-work.md`»** — cero menciones a #438 en las 311 líneas. La única mención de
  `Vendor.Symfony` allí (`:97`) es **a favor** del gate actual.

Si aun así se quisiera señal preventiva, el instrumento correcto no es deptrac sino un gate de texto en
`api/tests/Unit/Shared/Architecture/` que permita la lista blanca de atributos y exija el resto en `Security/`
— eso sí distinguiría los 2 casos de runtime de los 27 declarativos. **No entra en este lote.**

### #356 · dependency-cruiser para las fronteras de la PWA — **PREVENTIVO, Y ENTRA**

Cero violaciones vivas: `components/ui/**` y la raíz de `components/` tienen **0 aristas** hacia `context/` o
`app/`, también en cierre transitivo (el único salto intermedio es `cn.ts`, que sólo importa `clsx` y
`tailwind-merge`). La remediación de #349 sigue viva en `main`.

**El canal de regresión sí está abierto.** `pwa/eslint.config.mjs:105-130` y `:132-156` cubren `ui/` y
`erpify/`; **no existe ningún bloque para `src/components/*.ts`**. La regla 3 del issue —la forma estructural
del invariante de fundación— no está expresada en ninguna parte: `cn.ts` está limpio por disciplina, no por
gate. Y tres cosas son estructuralmente inexpresables en `no-restricted-imports`:

1. **Fachadas / transitividad** — `ui → @/components/cn → @/context/**` daría verde en los tres bloques. Es
   exactamente el escenario que obligó a #349 a mover `cn` en vez de dejar una fachada.
2. **Rutas relativas** — los `group` son alias `@/context/**`; un `../../context/shared/x` no casa nada.
   (Medido: hoy hay cero.)
3. **Re-exports de barril** — `components/erpify/index.ts` es invisible al grafo. `pwa/CLAUDE.md:121` prohíbe
   explícitamente re-exportar la UI de error desde `@/components/erpify`; hoy nada lo verifica.

Las **17 aristas `erpify → context/shared`** medidas son legítimas (`pwa/CLAUDE.md:43` las autoriza) y deben
quedar exceptuadas con precisión — una de ellas (`DeleteResourceButton.tsx:7`) cruza a `infrastructure/`, no a
`domain/`.

### #305 · Reducir el baseline de deptrac — **VIVO A MEDIAS, Y EL ISSUE ESTÁ PODRIDO**

El issue describe un baseline que ya no existe. **Hoy hay 21 pares** (verificado a mano) sobre 10 clases.
**#688 lo bajó de 31 → 21 hace horas** y mató dos bullets enteros del issue: la outbox transaccional y el
`ForeignKeyConstraintViolationException` — exactamente la opción «envolver tras un puerto» que el issue dejaba
abierta (`Shared/Persistence/Application/TransactionManager.php` + `DoctrineTransactionManager`, con el FK
traducido a `ReferentialIntegrityViolation`). **No queda ni un target Doctrine en el baseline.**

De los 9 bullets del issue: **4 vivos** (18 pares), **5 muertos**, y **3 pares nuevos** que el issue no
menciona (los `ValidationFailedException` de `BankAccount*`, del módulo que nació en #393).

Lo que cabe en este PR — **4 pares, cero cambio de comportamiento**:

- **`BankAccount → EnumType`**: `EnumType` es sólo el atributo-constraint (`extends …Validator\Constraint`);
  el runtime vive en `EnumTypeValidator`. Se mueve a `src/Shared/Validation/Domain/EnumType.php` dejando el
  validador en Infrastructure, y se admite `Symfony\Component\Validator\Constraint` +
  `Validator\Attribute\HasNamedArguments` en `Vendor.PassiveMetadata`. Mismo estatuto que `#[Assert\…]`, pero
  para una constraint propia. **Es la única violación literal de `docs/rules/architecture.md:57`**, que dice
  que la excepción cubre sólo `symfony/uid` — por eso no se puede bendecir *in situ*: hay que mover la clase.
- **`ExecutionContextInterface` ×3** (`Bank`, `FilterQuery`, `SearchQuery`): bendición limpia por colector.
  `#[Assert\Callback]` ya está bendecido y la firma `(ExecutionContextInterface $context)` es la que el
  atributo obliga a escribir. No entra runtime: es el parámetro del callback.

**Ambas por la vía del colector de capa, nunca por `skip_violations`** — ver la trampa en §Regresiones.

## Acceptance Criteria

0. **El spike de T0 ha corrido y su medida está registrada**, con su veredicto explícito: seguir a T1, o
   cerrar #356 como *evaluado, no compensa*. Los tres cortes están en T0; ninguno se decide a ojo.
1. **#356 entregado como gate, no como evaluación**: `dependency-cruiser` instalado como devDep de `pwa/`,
   configurado en `pwa/.dependency-cruiser.cjs` con **seis reglas** (§T1), alias `@/` resuelto vía
   `options.tsConfig`, `tsPreCompilationDeps: true`, y alcance limitado a `src`.
2. **El gate está cableado y CI lo corre**: script en `pwa/package.json`, target en `make/pwa.mk`, enganchado a
   `pwa.quality` **y** a `pwa.quality.dry-run`. Verificado que `.github/workflows/ci.yml:275` lo alcanza.
3. **El gate nace verde, sin baseline y sin una sola línea de excepción.** Las 17 aristas
   `erpify → context/shared` pasan porque la regla se enuncia **en negativo** (prohibir `backoffice` y
   `frontoffice`), no porque se las exceptúe. Si hiciera falta una allowlist, la regla está mal escrita.
4. **Cada una de las seis reglas se ha visto fallar**: provocar su rojo con un cambio temporal, capturar la
   salida y restaurar **copiando los bytes de vuelta** (nunca `git checkout --`). Una regla cuyo rojo nadie ha
   visto no está entregada. **El rojo decisivo es el transitivo** — si no se consigue, el gate no compra nada
   sobre ESLint y el veredicto vuelve a cerrar #356.
5. **Baseline de deptrac 21 → 17**: `EnumType` movido, `ExecutionContextInterface` bendecido por colector,
   `make php.deptrac.baseline` regenerado, y el diff del baseline muestra exactamente esos 4 pares fuera.
6. **Las dos bendiciones están documentadas** con su sección «Documented exception» en
   `docs/rules/architecture.md` y el §Implementación del ADR actualizado — es lo que exige
   `docs/adr/external-dependencies-in-domain.md` (D4: el colector y la lista se mueven juntos).
7. **#589, #250 y #438 cerrados con evidencia medida** en el comentario de cierre, citando `fichero:línea` y
   los commits que los resolvieron. Nunca por leer el título.
8. **Las siete correcciones de prosa aplicadas** (ver §Prosa podrida). Cada una es hoy una fuente activa de
   trabajo fantasma: dos de ellas ya produjeron una recomendación equivocada durante esta misma medición.
9. `make pwa.quality` y `make php.quality` verdes desde una corrida fresca, con el exit code impreso.

## Tasks / Subtasks

- [x] **T0 — #356: spike de 90 min con criterio de parada** (AC: 0) · **bloquea T1–T3**
  - [x] Instalar la devDep y cruzar `pwa/src` sin escribir ninguna regla todavía
  - [x] **Corte 1 — resolución del alias.** Expectativa medida a mano: **465 módulos, ~1540 aristas internas,
        0 irresolubles, 0 ciclos**. `465 módulos con ~200 aristas es fallo silencioso` → parar. Si `@/…` sólo
        resuelve añadiendo `baseUrl` al tsconfig, o escribiendo el alias a mano en `enhancedResolveOptions`
        (tercera declaración) → **parar y cerrar #356 con el informe**
  - [x] **Corte 2 — instalación.** Si `make pwa.install` (`npm ci` pelado, `make/pwa.mk:31`) se pone rojo por
        peers con TS 6 → parar; rompería `ci.yml:274` antes de llegar al gate
  - [x] **Corte 3 — el rojo transitivo.** Meter un import a `@/context/shared/…` en `cn.ts` y comprobar que
        una regla `to: { reachable: true }` pone rojos los 10 de 11 ficheros de `ui/**` que importan
        `@/components/cn`, **mientras la regla literal sobre `ui/**` sigue verde**. Si ese rojo no sale, el
        cruiser es ESLint con pasos de más → **cerrar #356 como evaluado**
  - [x] Registrar el veredicto en §Rojos provocados **antes** de tocar T1
- [x] **T1 — #356: configurar las seis reglas** (AC: 1) · sólo si T0 dice seguir
  - [x] `ui-is-foundational` — `src/components/ui/**` no **alcanza** `context/**`, `app/**`, `components/erpify/**`
  - [x] `components-root-is-foundational` — `src/components/*.ts` sin aristas hacia arriba (**el hueco real**)
  - [x] `erpify-not-bounded-context` — enunciada **en negativo**: prohibir `context/backoffice/**`,
        `context/frontoffice/**`, `app/**`. **Cero excepciones**
  - [x] `no-circular` · `no-unresolvable` (obligatoria: hace ruidoso el fallo silencioso del alias)
  - [x] `erpify/index.ts ⇏ context/shared/error/infrastructure/ui` — sólo si cabe en ~6 líneas
        (`pwa/CLAUDE.md:121` lo prohíbe por escrito y hoy nada lo verifica)
  - [x] `tsPreCompilationDeps: true` (7 de 17 aristas son `import type`), `doNotFollow: node_modules`,
        alcance `src` — **no** cruzar `pwa/tests/`
  - [x] Cabecera del `.cjs` declarando el reparto de roles con ESLint, como `deptrac.yaml:8-14`
- [x] **T2 — #356: cablear** (AC: 2, 3)
  - [x] Script en `pwa/package.json`; target en `make/pwa.mk`; engancharlo a `pwa.quality` y `pwa.quality.dry-run`
  - [x] Confirmar verde **sin baseline, sin `--cache` y sin una sola línea de excepción**
- [x] **T3 — #356: provocar los seis rojos** (AC: 4)
  - [x] Los tres literales son de una línea. `no-unresolvable` es trivial. `no-circular` es **el difícil**:
        exige dos ediciones coordinadas y dos restauraciones exactas, y mientras vive puede romper `tsc` y Vitest
  - [x] El rojo transitivo y el de la raíz de `components/` **vienen acoplados**: la evidencia vale si la salida
        capturada nombra **los dos rule ids**, no si se afirma haberlos provocado por separado
- [x] **T4 — #305: mover `EnumType` a `Shared/Validation/Domain/`** — **INTENTADA Y REVERTIDA** (AC: 5, 6)
  - [x] Mover sólo la constraint; el validador se queda en Infrastructure — **rompe la validación en runtime**;
        el par se ata por nombre vía `validatedBy()`. Ver §T4 REVERTIDA
  - [x] Admitir `Validator\Constraint` + `Validator\Attribute\HasNamedArguments` en `Vendor.PassiveMetadata` —
        retirados con la reversión: ya no hacen falta, `Infrastructure` admite `Vendor.Symfony`
  - [x] **Guardrail en su lugar**: `ConstraintValidatorResolutionGateTest`, con su rojo provocado
- [x] **T5 — #305: bendecir `ExecutionContextInterface` por colector** (AC: 5, 6)
- [x] **T6 — #305: regenerar baseline y documentar** (AC: 5, 6)
  - [x] `make php.deptrac.baseline`; verificar 21 → 17 en el diff — **medido 21 → 18** (los tres
        `ExecutionContextInterface`); T4 no paga su par. Ver §Baseline
  - [x] «Documented exception» en `docs/rules/architecture.md` + §Implementación del ADR, ambas reescritas
        para registrar por qué el par de `EnumType` **no** es pagable con un movimiento
- [x] **T7 — Prosa podrida: las siete correcciones** (AC: 8)
- [x] **T8 — Cerrar #589, #250 y #438 con evidencia** (AC: 7) — los tres **CLOSED** con su comentario de
      evidencia medida. Ver §Cierre de issues
- [x] **T9 — Gates y pase adversarial** (AC: 9) — el pase precede a `gh pr create`, no a `done`. Gates verdes
      con exit code impreso; el pase se ejecutó **antes** de abrir la PR y encontró un GRAVE y un SERIO, ambos
      corregidos. Su segunda ronda es **autorevisión** por muerte repetida del subagente — limitación declarada
      en §Pase adversarial

## Prosa podrida — las siete correcciones

Ninguna es cosmética: **dos de ellas produjeron una recomendación equivocada durante esta misma medición**, lo
que las califica como fuente activa de trabajo fantasma y no como deuda de estilo.

| # | Fichero | Dice | Mide |
|---|---|---|---|
| P1 | `epics-backlog-resolution.md:179-181` | «#589 es el que muerde: el gate NFR26 nunca corre» | Corre: 9 tests, 0 skips |
| P2 | `deferred-work.md:54` | Registra el hueco git-aware de NFR26 como pendiente | Resuelto en #596. **Es la bala que hizo recomendar #589 como prioritario a un lector de esta misma medición** |
| P3 | `deptrac.yaml:12-13` | «The three published seams» | **26** seams en `.bounded-context-allowlist` |
| P4 | `epic-auth-arc-retro-2026-07-20.md:39` (SI-2) | «El addendum afirma que Symfony Security vive sólo en Infrastructure porque lo impone deptrac. No lo impone» | Sí lo impone **a nivel de capa** (0 imports en Domain). Lo que no puede es sub-particionar Infrastructure. **Es la premisa exagerada que #438 heredó** |
| P5 | `docs/architecture-pwa.md:86` | Sitúa `cn` en `context/shared/styling/` | Ese directorio **no existe** desde #349; `cn.ts` vive en la raíz de `components/` |
| P6 | `pwa/CLAUDE.md:84` | Los barriles `@/context/shared/<capability>` son frontera de API pública «enforced in CI» | **Falso en dos niveles**: no hay enforcement **y los barriles no existen** — cero `context/shared/*/index.ts`, y **567 imports profundos** cross-capability. Gatearlo exigiría 24 ficheros nuevos y baseline de 567: imposible por AC3. El barril de `@/components/erpify` **sí** es real (0 imports profundos desde fuera) |
| P7 | `pwa/CLAUDE.md:43` | Describe el invariante de fronteras nombrando sólo el gate ESLint | Con dos gates hay que nombrar **los dos** y qué ve cada uno, o el espejo de prosa nace desalineado el día uno. Sólo aplica si T0 dice seguir |

`deferred-work.md` es registro **sólo de pendientes**: P2 se resuelve **borrando la bala**, no anotándola.

## Dev Notes

### Ficheros a tocar

| Fichero | Issue | Nota |
|---|---|---|
| `pwa/.dependency-cruiser.cjs` | #356 | **Nuevo** |
| `pwa/CLAUDE.md` | P6 · P7 | `:84` los barriles que no existen · `:43` nombrar los dos gates |
| `pwa/package.json` · `pwa/package-lock.json` | #356 | devDep + script; leer la versión de vuelta del lock |
| `make/pwa.mk` | #356 | Target nuevo; engancharlo a `pwa.quality` **y** `.dry-run` |
| `api/src/Shared/Validation/Domain/EnumType.php` | #305 | **Movido** desde `Shared/Validation/Infrastructure/` |
| `api/tools/deptrac/deptrac.yaml` | #305 · P3 | `Vendor.PassiveMetadata` (T4, T5) y el comentario `:12-13` |
| `api/tools/deptrac/deptrac.baseline.yaml` | #305 | **Generado** — nunca a mano |
| `docs/rules/architecture.md` | #305 | Dos «Documented exception»; `:57` es la regla que hoy se viola |
| `docs/adr/external-dependencies-in-domain.md` | #305 | §Implementación |
| `docs/architecture-pwa.md` | P5 | `:86` |
| `_bmad-output/planning-artifacts/epics-backlog-resolution.md` | P1 | `:179-181`, marcada, no borrada |
| `_bmad-output/implementation-artifacts/deferred-work.md` | P2 | **Borrar la bala** `:54` |
| `_bmad-output/implementation-artifacts/epic-auth-arc-retro-2026-07-20.md` | P4 | `:39` |

### Orden obligado

**T0 bloquea T1–T3.** El spike no es una formalidad: sus tres cortes pueden terminar el issue sin escribir una
regla. Empezar por el config y descubrir la trampa del alias a mitad convierte 90 minutos de hallazgo negativo
en medio día de coste hundido — y en la tentación de parchearlo con una tercera declaración de `@/`.

**T4 antes que T6.** Mover `EnumType` cambia el FQCN que el baseline nombra; regenerar antes deja una entrada
apuntando a una clase que ya no existe. **T4–T6 son independientes de T0–T3** (lados distintos del monorepo) y
pueden avanzar aunque T0 termine en cierre: `pwa.mk` y `php-quality.mk` son ficheros distintos, así que no
colisionan. **P7 depende del veredicto de T0**; las otras seis correcciones de prosa, no.

### Rutas que no son adivinables

- Los gates estáticos viven en `api/tests/Unit/Shared/Architecture/` — **30 clases**. Pero **el gate NFR26 no
  está ahí**: vive en `api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php`. Buscarlo por
  el directorio de gates no lo encuentra.
- `api/tools/phpstan/` contiene **sólo** `phpstan.neon` — no hay infraestructura de reglas propias.
- El baseline de deptrac se **genera**: `make php.deptrac.baseline` → `tools/deptrac/regen-baseline.sh`.
- `pwa/` no tiene `no-restricted-paths` ni plugin `import`: el gate de fronteras son dos bloques
  `no-restricted-imports` en `pwa/eslint.config.mjs`.

### Patrones a REUTILIZAR

- **El molde de gate que necesita ver el árbol raíz** es `ScheduleConsumptionGateTest::composeDirectory()`
  (`:165-180`): probar `[dirname($apiRoot), dirname($apiRoot).'/repo']` y **fallar**, nunca saltar, si nada
  resuelve. Mismo patrón en `RedactionVocabularyParityTest.php:136-152` y
  `CaddyfileAccessLogRedactionGateTest.php:258-270`. **No inventar una variable de entorno nueva.**
- **El molde de invariante no-saltable** es el propio `ErrorContractGateTest`: estado del directorio en vez de
  diff, y `fail()` —no skip— cuando el insumo no está (`:503`, `:518`, `:539-551`).
- La autoprotección contra vacuidad (`assertNotEmpty` sobre el conjunto recorrido) es obligatoria en cualquier
  gate nuevo: un recorrido de cero ficheros pasa vacuamente.

### Regresiones que NO se pueden romper

1. **No revivir la mitad git-aware del gate NFR26.** Está borrada a propósito (`ErrorContractGateTest.php:30-34`).
   Reintroducirla reabre el skip incondicional bajo otro nombre.
2. **El mount de `docs/` es de directorio, no de fichero** (`compose.dev.yaml:34-38`). Convertirlo en un bind
   de fichero fija un inodo, y `git checkout` / la escritura segura del IDE sustituyen por rename: el
   contenedor serviría bytes pre-edición y el gate validaría un doc obsoleto.
3. **`create_host_path: false` es load-bearing**: sin él Docker inventa un directorio root-owned cuando el
   source falta, y el gate leería un `docs/` vacío pasando vacuamente.
4. **Nunca bendecir un par vendor por `skip_violations` en `deptrac.yaml`.** `regen-baseline.sh` sólo despoja
   targets `Erpify\*` de módulo hermano; un par vendor puesto ahí a mano **es re-volcado al baseline en la
   siguiente regeneración** y vuelve a contar como deuda. La vía correcta es el colector de capa.
5. **El baseline no se edita a mano** — su cabecera lo dice. Se regenera.
6. **No borrar ninguno de los dos gates de bounded-context.** No son subconjunto uno del otro (ver la tabla de
   §#250): el de texto ve el `use` no usado, el Nivel 2 y los módulos nuevos sin registrar; deptrac ve el FQCN
   inline. Retirar cualquiera pierde casos.
7. **Las 17 aristas `erpify → context/shared` son legítimas** (`pwa/CLAUDE.md:43`). Un gate que las marque está
   mal configurado; no «arreglarlo» moviendo código.
8. **`ProblemDetailsFactory` recibe `ValidationFailedException` desde el framework** (`#[MapRequestPayload]` la
   lanza). En los cuatro servicios de Application la dependencia es `use` + `@throws`, **sin `catch`** —
   tentador de «arreglar» borrando el import, y eso sería **falsear el gate, no pagarlo**.
9. **No añadir `baseUrl` a `pwa/tsconfig.json`** para que resuelva el alias. Cambia la resolución de Next,
   `tsc` y Vitest para toda la app: no es un arreglo local del gate. Si el alias no resuelve sin él, el corte 1
   de T0 se cumple y se para.
10. **No retirar los dos bloques `no-restricted-imports`.** Son la señal inline en el editor, que el cruiser no
    da — y con `tsPreCompilationDeps` mal puesto cubren **más** que él. Los dos gates son hermanos con roles
    distintos, igual que `BoundedContextGateTest` y deptrac en el API.
11. **Nada de `--cache`, baseline ni el preset `recommended`.** Los dos primeros son superficie de verde
    silencioso; el tercero arrastra reglas que nadie acordó y cuyos rojos nadie va a provocar — y una regla sin
    su rojo no está entregada (AC4).
12. **No cruzar `pwa/tests/`** (274 ficheros) ni activar `no-orphans`: los 77 entrypoints del App Router
    (`page`/`layout`/`error`/`not-found`/`route`/`loading`/`template`) son huérfanos por construcción.
13. **No gatear el layering DDD dentro de `context/**`.** Es la tentación obvia y es scope creep: 24
    capacidades × 3 capas, sin medir, y casi con seguridad nace rojo. Decisión propia con su propia medición.

### Cobertura existente y huecos

- El gate NFR26 cubre hoy: marcadores citados en el doc, tabla marcador→status en ambas direcciones, y
  `MarkerStatusMapContractTest.php:154` los nueve canónicos. Hueco residual conocido y deliberado en `:37-43`.
- `BoundedContextGateTest` lee imports con `token_get_all` (`:581`), no con regex — group-use y alias no lo
  evaden. El Nivel 2 nunca bloquea (`:137`, `:151`, a STDERR).
- La PWA no tiene hoy **ninguna** verificación de grafo. Todo su enforcement de fronteras es por cadena
  literal, por fichero.

### Project Structure Notes

Cero producción en el lado API: el diff toca `api/tools/`, un movimiento de clase sin cambio de comportamiento,
y documentación. En el lado PWA es tooling de build, sin código de aplicación. No hay migración, no hay cambio
de contrato HTTP, no hay superficie nueva de datos. La revisión de seguridad del checklist aplica mayormente
por «no aplica» — **declararlo explícitamente en el PR**, nunca saltarlo en silencio.

### References

- [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) — §BR-6 (`:176-182`), §Orden (`:197-208`), §Criterios de cierre (`:210-217`)
- [`br-1-behat-vocabulario-falsabilidad.md`](br-1-behat-vocabulario-falsabilidad.md) — precedente de formato, y el mismo arco #596–#601 que aquí cierra #589
- [`docs/adr/external-dependencies-in-domain.md`](../../docs/adr/external-dependencies-in-domain.md) — D1/D2/D4, qué exige bendecir
- [`docs/rules/architecture.md`](../../docs/rules/architecture.md) — `:57`, la regla que `EnumType` viola hoy
- [`pwa/CLAUDE.md`](../../pwa/CLAUDE.md) — `:20` hermanos fundacionales · `:43` el invariante del gate · `:84` barriles · `:121` la fachada prohibida
- Consulta de D2 (2026-08-12): Winston (arquitectura) y Amelia (implementación), por separado y sobre los
  mismos hechos. De ella salen el alcance de seis reglas, la enunciación en negativo sin excepciones,
  `tsPreCompilationDeps: true`, los cortes de T0 y las correcciones P6/P7
- Arco que resolvió #589: `ab796333` (#596) · `7503390d` (#597) · `389f59e3` (#600)
- Commit que mató dos bullets de #305: `aa5db518` (#688)

## Dev Agent Record

### Agent Model Used

Claude Opus 5 (1M context) — `bmad-create-story`.

### Debug Log References

Medición del 2026-08-12 contra `781c75a2`, cinco lectores independientes de sólo-lectura, uno por issue. Dos
hechos de mayor consecuencia re-verificados a mano por el orquestador antes de escribir: `ErrorContractGateTest`
(0 ocurrencias de `markTestSkipped`/`resolveGitBase`/`ERROR_CONTRACT_GATE_BASE`/`merge-base`; 9 tests) y el
conteo del baseline (21 pares).

### Completion Notes List

- **T0 dijo SEGUIR, y el spike se pagó solo.** Los tres cortes pasaron, pero el spike encontró lo que ninguna
  de las dos consultas de escritorio podía encontrar: `exportsFields: []`. Sin ese hallazgo, `no-unresolvable`
  nacía roja con 56 violaciones y la salida barata habría sido exceptuarlas o retirar la regla — las dos rompen
  el AC3. Es exactamente el fallo que T0 existía para atrapar, y llegó por ejecutar la herramienta.
- **La medida que zanja D2 es un verde, no un rojo.** ESLint sale **exit 0, cero hallazgos** sobre un árbol
  donde `ui/**` alcanza `@/context/**` por la fachada de `cn`. El cruiser no duplica a ESLint.
- **`no-circular` resultó más barato de provocar de lo previsto.** El artefacto anticipaba «dos ediciones
  coordinadas y dos restauraciones exactas»; basta **una**, porque `ui/button.tsx → cn.ts` ya existe y añadir
  `import "@/components/ui/button"` en `cn.ts` cierra el lazo. Ni `tsc` ni Vitest llegaron a verse afectados.
- **El rojo del barril salió más caro que su regla**, y a favor: re-exportar las pantallas de error no sólo
  cruza la frontera, **fabrica tres ciclos reales** porque esas pantallas importan `CopyButton`/`Logo` de ese
  mismo barril. Verificado que todos pasan por la arista añadida y que el árbol limpio vuelve a 1906
  dependencias — no había ciclo preexistente enmascarado.
- **El baseline baja a 18, no a 17.** T4 no paga su par: mover `EnumType` rompe la validación en runtime
  (§T4 REVERTIDA), así que `BankAccount → EnumType` se queda como deuda **documentada y ahora gateada**. Los
  tres pares que sí caen son los `ExecutionContextInterface` de T5. D4 queda intacto.
- **El pase adversarial se pagó solo, y llegó tarde por poco.** Encontró el GRAVE *después* del push pero
  *antes* de abrir la PR — que es precisamente el margen que la regla «abrir la PR al final» compra.
- **T8 cerrado**: #589, #250 y #438 CLOSED con evidencia medida contra el código.
- **Dos trampas de medición documentadas** en §Cierre de issues (escapado zsh, y `grep` mandando *binary file
  matches* a stderr). Las dos devolvían el número cómodo.

### File List

**Nuevo**

- `pwa/.dependency-cruiser.cjs`
- `api/tests/Unit/Shared/Architecture/ConstraintValidatorResolutionGateTest.php`
- `api/tests/Unit/Shared/Architecture/Fixture/ConstraintResolution/ResolvableFixtureConstraint.php`
- `api/tests/Unit/Shared/Architecture/Fixture/ConstraintResolution/ResolvableFixtureConstraintValidator.php`
- `api/tests/Unit/Shared/Architecture/Fixture/ConstraintResolution/StrandedFixtureConstraint.php`
- `pwa/tests/dependency-cruiser-rules-are-not-vacuous.test.ts`

**Modificado**

- `CLAUDE.md` · `api/CLAUDE.md`
- `make/pwa.mk`
- `pwa/package.json` · `pwa/package-lock.json`
- `pwa/CLAUDE.md`
- `api/tools/deptrac/deptrac.yaml` · `api/tools/deptrac/deptrac.baseline.yaml` (**generado**)
- `docs/rules/architecture.md`
- `docs/adr/external-dependencies-in-domain.md`
- `docs/architecture-pwa.md`
- `docs/claude-code-quickref.md`
- `docs/development-guide-pwa.md`
- `_bmad-output/planning-artifacts/epics-backlog-resolution.md`
- `_bmad-output/implementation-artifacts/deferred-work.md`
- `_bmad-output/implementation-artifacts/epic-auth-arc-retro-2026-07-20.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`
- `_bmad-output/implementation-artifacts/br-6-gates-arquitectura-ci.md`

### Gates

Todos desde corrida fresca, con el exit code impreso. Los logs quedan en `tmp/br6/` (gitignored).

| Gate | Exit | Detalle |
|---|---|---|
| `make pwa.quality` | **0** | Incluye `lint:graph` — «no dependency violations found (494 modules, 1906 dependencies cruised)» |
| `make pwa.lint.graph` | **0** | Y alcanzado por los dos agregados: `make -n pwa.quality` y `make -n pwa.quality.dry-run` nombran `lint:graph` |
| `make php.quality` | **0** | deptrac: 0 violaciones, 0 uncovered, 3270 permitidas, 75 skipped |
| `make php.unit` | **0** | Suite completa: **2721 tests, 10835 assertions** |
| `make php.unit c='--filter ConstraintValidatorResolutionGateTest'` | **0** | OK (3 tests, 4 assertions) — y **exit 2** con el movimiento rehecho |
| `make php.unit c='--filter ErrorContractGateTest'` | **0** | OK (9 tests, 15 assertions) — evidencia de #589 |
| `make pwa.test.unit` | **0** | 228 ficheros, **1241 tests** |
| `make pwa.test.unit c='…rules-are-not-vacuous…'` | **0** | 6 tests — y **exit 2** con la ruta del barril tipografiada |
| `make pwa.install` (`npm ci`) | **0** | Con la devDep dentro: 908 paquetes, 0 vulnerabilidades |

El lado PWA no se ha tocado desde su corrida verde: la reversión de T4 y el guardrail son `api/` y `docs/`.

**Un falso rojo descartado por diagnóstico, no por repetición.** Una corrida intermedia de `php.quality` murió
con `php.stan Terminated` (exit 2). No era un hallazgo: había **cinco stacks de ERPify** levantados a la vez
(20 de 30 GiB en uso) y PHPStan cayó por presión de memoria. Con el stack confirmado sano, exit 0. Nota
lateral: `docker compose ps` a pelo desde un worktree **no lista nada** porque no resuelve su
`COMPOSE_PROJECT_NAME` — hay que usar `make docker.ps`, y creer al primero es concluir que el stack ha
desaparecido cuando está corriendo.

### Rojos provocados

#### T0 · spike de #356 — **veredicto: SEGUIR a T1**

Medido el 2026-08-12 sobre la rama rebasada a `3be82129`, con `dependency-cruiser@18.2.0` instalado como
devDep de `pwa/`. Configs del spike bajo `tmp/br6/` (gitignored), nunca en el árbol.

| Corte | Criterio de parada | Medido | |
|---|---|---|---|
| **1 · alias** | «465 módulos con ~200 aristas» = fallo silencioso; o el alias sólo resuelve con `baseUrl` / una tercera declaración a mano | **466 módulos internos, 1536 aristas internas, 0 irresolubles, 0 ciclos**, con `options.tsConfig` como **única** fuente del alias | ✅ pasa |
| **2 · instalación** | `npm ci` rojo por peers con TS 6 | `make pwa.install` **exit 0**, 908 paquetes auditados, **0 vulnerabilidades**. Cero install-scripts nuevos: los 4 bloqueados por npm 12 son los preexistentes | ✅ pasa |
| **3 · rojo transitivo** | Si el rojo transitivo no sale, el cruiser es ESLint con pasos de más | **10 violaciones** de `ui-reachable-transitive` (los 10 de 11 ficheros de `ui/**` que importan `@/components/cn`, `via 2`) **mientras `ui-literal-direct` se queda en CERO** | ✅ pasa |

**La medida que zanja D2** no es el rojo del cruiser sino el verde de al lado: con el import falseado dentro de
`cn.ts`, **`npx eslint src/components/ui src/components/cn.ts` sale exit 0 y no imprime un solo hallazgo**. El
gate que hoy tenemos es ciego al escenario exacto que obligó a #349 a *mover* `cn` en vez de dejar una fachada,
y nada verificaría su reintroducción. El cruiser no duplica a ESLint: ve lo que ESLint no puede enunciar.

Control previo obligatorio: las tres reglas sobre el árbol **sin tocar** dan `{}` violaciones. Restauración de
`cn.ts` **copiando los bytes** desde `tmp/br6/cn.ts.orig`; verificado `cmp` contra `origin/main:pwa/src/components/cn.ts`
→ idéntico.

#### Hallazgo del spike que corrige a este artefacto — `exportsFields` es obligatorio

La expectativa escrita («0 irresolubles») **no se cumple con `options.tsConfig` a secas: salen 56**. No son
fallos de alias — son dos paquetes reales, `inversify` ×54 y `uuid` ×2. Ambos son ESM-only, publican `exports`
y **no publican `main`**, y dependency-cruiser trae `exportsFields: []` por defecto: una decisión deliberada de
retrocompatibilidad con enhanced-resolve 4 (`src/main/resolve-options/normalize.mjs:35`, issue upstream #338).

Consecuencia para el AC3: sin `enhancedResolveOptions.exportsFields: ["exports"]`, la regla `no-unresolvable`
—la que el §La consulta de D2 declara obligatoria precisamente para hacer ruidoso el fallo silencioso del
alias— **nace roja con 56 violaciones**, y la salida barata sería exceptuarlas o retirarla. Las dos rompen el
lote. Con la opción puesta: **0 irresolubles**.

No es una tercera declaración del alias `@/` y no toca `pwa/tsconfig.json`: es una capacidad del resolver sobre
mapas `exports` de npm. La regresión que el artefacto enuncia (no añadir `baseUrl`) queda intacta.

Ni la consulta a Winston ni la de Amelia lo predijeron, por la razón que el propio artefacto ya daba: **ninguno
de los dos ejecutó la herramienta**. Es el hallazgo que justifica T0 por sí solo.

#### T3 · los seis rojos de `pwa/.dependency-cruiser.cjs`

Gate verde de nacimiento: `make pwa.lint.graph` → **exit 0**, «no dependency violations found (494 modules,
1906 dependencies cruised)», **sin baseline, sin `--cache`, sin preset y sin una sola línea de excepción**
(AC1/AC3). Cinco falsificaciones, una edición cada vez, restaurando siempre **copiando bytes** desde
`tmp/br6/*.orig`. Script: `tmp/br6/provoke-reds.sh`; salidas en `tmp/br6/red-*.log`.

| Rojo | Falsificación | Salida | Regla(s) en rojo |
|---|---|---|---|
| **A** | `import "@/context/shared/environment/domain/NodeEnv";` en `cn.ts` | exit **11** | `ui-is-foundational` ×10 **+** `components-root-is-foundational` ×1 |
| **B** | `import "@/context/backoffice/health/application/CheckHealth";` en `AppShell.tsx` | exit **6** | `erpify-not-bounded-context` ×6 |
| **C** | `export * from "@/context/shared/error/infrastructure/ui";` en `erpify/index.ts` | exit **8** | `erpify-barrel-excludes-error-screens` **+** `no-circular` |
| **D** | `import "@/components/ui/button";` en `cn.ts` | exit **1** | `no-circular` ×1 |
| **E** | `import "@/this/module/does/not/exist";` en `cn.ts` | exit **1** | `no-unresolvable` ×1 |

**El rojo A satisface el acoplamiento que T3 exige**: una sola salida capturada nombra los **dos** rule ids,
no dos afirmaciones separadas. Los 10 son exactamente los 10 de 11 ficheros de `ui/**` que importan
`@/components/cn`; el 11.º no lo importa.

**El rojo B demuestra el alcance transitivo del enunciado en negativo**: la arista se mete en `AppShell.tsx` y
el gate marca **seis** — los tres módulos alcanzados desde `AppShell.tsx` y los mismos tres desde
`erpify/index.ts`, que llega por el barril. Una regla literal habría visto una.

**El rojo C sale más caro de lo previsto, y a favor de la regla.** Re-exportar las pantallas de error desde el
barril no sólo cruza la frontera que `pwa/CLAUDE.md:121` prohíbe en prosa: **fabrica ciclos de import reales**,
porque `SegmentErrorBoundary.tsx:5` y `ErrorScreen.tsx:4` importan `CopyButton`/`Logo` de ese mismo barril.
Verificado en el log crudo que **los tres ciclos pasan por `src/components/erpify/index.ts`** — la arista que
añadió la falsificación — y que el árbol limpio vuelve a dar exit 0 con 1906 dependencias. No hay ciclo
preexistente enmascarado. La prosa protegía de un coste concreto que ahora está medido.

**Restauración verificada, no afirmada**: `cn.ts`, `AppShell.tsx` y `erpify/index.ts` comparados con `cmp`
contra `origin/main` → los tres idénticos.

`make pwa.quality` → **exit 0** con el gate dentro (línea `lint:graph` en el log). Ambos agregados lo alcanzan:
`make -n pwa.quality` y `make -n pwa.quality.dry-run` nombran `lint:graph` una vez cada uno, y CI corre el
segundo en `ci.yml:253` (AC2).

#### ⚠️ T4 REVERTIDA — mover `EnumType` rompía la validación en runtime

**Hallazgo GRAVE, encontrado durante el pase adversarial, sobre trabajo ya commiteado y empujado.** El primer
intento de T4 movió `EnumType` a `Shared/Validation/Domain/` dejando `EnumTypeValidator` en `Infrastructure/`,
tal y como la historia lo diseñó. Es incorrecto, y **ningún gate del repo podía verlo**.

Symfony ata constraint y validador **por nombre**: `Constraint::validatedBy()` devuelve
`static::class . 'Validator'` (`api/vendor/symfony/validator/Constraint.php:161-164`) y `EnumType` no lo
sobreescribe. Tras el movimiento, el nombre derivado era `Erpify\Shared\Validation\Domain\EnumTypeValidator`,
que no existe. Reproducido contra el validador real, no deducido:

```
validatedBy() resolves to : Erpify\Shared\Validation\Domain\EnumTypeValidator
class_exists()            : >>> NO — MISSING <<<
Validation::createValidator()->validate(Currency::EUR, $constraint)
  THREW Error: Class "Erpify\Shared\Validation\Domain\EnumTypeValidator" not found
```

`#[EnumType]` está sobre `BankAccount::$currency` y `::$status` (`:75`, `:78`), así que **toda validación de
un BankAccount reventaba**. Y sin embargo: `make php.stan` **0**, `make php.deptrac` **0 violaciones**,
`make php.unit` **suite completa verde**, `make php.quality` **0**. Los cuatro.

**Por qué la suite era ciega:** `EnumTypeValidatorTest` extiende `ConstraintValidatorTestCase`, que instancia
el validador **directamente** vía `createValidator()`. Nunca pasa por `validatedBy()`. El test de la constraint
es exactamente el que no puede ver este fallo.

**El validador no puede acompañarla** a `Domain/`: extiende `ConstraintValidator`, que es runtime de framework.
Y las tres formas de partir el par son peores que el par de deuda:

| Salida | Por qué no |
|---|---|
| `validatedBy()` → `EnumTypeValidator::class` | Reubica la misma arista Domain→Infrastructure; deptrac la marca y vuelve al baseline. Cero ganancia, más churn |
| `validatedBy()` → FQCN como *string literal* | La esconde de deptrac. Es **falsear el gate, no pagarlo** |
| Registrar el validador con un service id | Inventa cableado de DI para una clase y sigue acoplando por cadena mágica |

**T4 queda revertida.** `EnumType` vuelve a `Infrastructure/` junto a su validador —el mismo patrón que ya
siguen `PasswordPolicy`/`PasswordPolicyValidator`— y `BankAccount → EnumType` vuelve al baseline como deuda
documentada. Los cuatro ficheros verificados byte-idénticos a `origin/main` con `cmp`.

**Guardrail añadido** (regla de la casa: *«every time something breaks → you add a guardrail»*):
`api/tests/Unit/Shared/Architecture/ConstraintValidatorResolutionGateTest.php` resuelve `validatedBy()` —
llamando al método real, no re-derivando la convención, para juzgar también a quien la sobreescriba— sobre
toda subclase concreta de `Constraint` bajo `src`, y falla si el destino no existe. **Su rojo está provocado**:
rehecho el movimiento, el gate sale exit 2 nombrando
`Erpify\Shared\Validation\Domain\EnumType -> …\Domain\EnumTypeValidator (missing)`. Trae autoprotección contra
vacuidad y dos fixtures que falsifican el predicado en ambos sentidos.

#### Baseline de deptrac — 21 → **18**

El AC5 predijo cuatro pares fuera. Salen **cinco**, y el quinto no es un desliz de alcance sino la consecuencia
directa de arreglar el colector: `Vendor.PassiveMetadata` bendecía el **namespace** `Validator\Constraints\*`
(las constraints concretas) y por tanto dejaba fuera **su propia clase base**. Al anclar
`^Symfony\\Component\\Validator\\Constraint$` desaparece también
`Shared\Validation\Application\Validator → Constraint`, porque `Vendor.PassiveMetadata` es admisible en
`Domain` **y** en `Application`.

| Par | Fuera por |
|---|---|
| `BankAccount → EnumType` | T4 — la clase se movió a `Shared/Validation/Domain/` |
| `Bank → ExecutionContextInterface` | T5 — colector |
| `FilterQuery → ExecutionContextInterface` | T5 — colector |
| `SearchQuery → ExecutionContextInterface` | T5 — colector |
| **`Validator → Constraint`** | **efecto del anclaje; no previsto** |

**Conservar el 17 se midió y sale peor.** Aislar `Constraint` en una capa propia (`Vendor.SymfonyConstraintBase`,
admitida sólo en el ancla `*domain`, al estilo de `Vendor.SymfonyUid`) obliga a sacarlo de `Vendor.Symfony` por
`must_not` — y entonces **`Infrastructure` pierde el acceso**: `EnumTypeValidator`, `PasswordPolicy` y
`PasswordPolicyValidator`, que lo usan legítimamente, caen al baseline. Medido: **20 pares**, es decir la
opción «precisa» *sube* la deuda en vez de bajarla, y dejarla verde exigiría replicar la capa en cada bloque
de ruleset de Infrastructure. Descartada por medición, no por gusto.

**Esto no invade D4.** Lo que D4 reserva a Sergio es el rediseño —mover `Validator` a Infrastructure tras un
puerto con excepción de dominio, lo que reescribe el contrato 422—, y sigue intacto: los otros cinco pares del
`Validator` (`ValidatorInterface`, `ConstraintViolation`, `…List`, `…ListInterface`,
`ValidationFailedException`) siguen en el baseline, y los siete de `ProblemDetailsFactory` también. El anclaje
es load-bearing en la otra dirección: sin `$`, `…\Validator\Constraint` se tragaría `ConstraintViolation` y sus
hermanos —resultados de runtime— y sí habría vaciado media D4. Verificado que `ConstraintViolationInterface`
sobrevive en el par de `ProblemDetailsFactory`.

`make php.deptrac` → **exit 0**, 0 violaciones, 0 uncovered.

#### Correcciones menores a números citados

- `ci.yml` ha derivado: `make pwa.install` está en **`:252`** y `make pwa.quality.dry-run` en **`:253`** (el
  artefacto cita `:274`/`:275`). Y `pwa.install` alimenta **tres** jobs, no uno — también `:310` (E2E) y
  `:457`. Un `npm ci` roto cuesta tres jobs.
- `pwa/tsconfig.json` declara hoy `target: ES2025` y `moduleResolution: bundler` (no lo que dice
  `docs/project-context.md:49`). Fuera del alcance de este lote; no se toca.
- El árbol trae **466** módulos internos, no 465. Sin consecuencia.

### Cierre de issues — evidencia medida

Los tres se midieron de nuevo **contra el árbol, no contra la historia**, y quedan **CLOSED** con su comentario
de evidencia (`tmp/br6/close-{589,250,438}.md`).

| Issue | Veredicto | Evidencia fresca |
|---|---|---|
| **#589** | ya resuelto | `ErrorContractGateTest.php`: **0** ocurrencias de `markTestSkipped`, `resolveGitBase`, `merge-base` y `ERROR_CONTRACT_GATE_BASE`; `testEveryMarkerIsCitedInTheContractDoc` en `:234`. Corrida fresca: **OK (9 tests, 15 assertions)**. Commits: `ab796333` (#596), `7503390d` (#597), `389f59e3` (#600) — verificado que los tres tocan el fichero |
| **#250** | obsoleto por deptrac | `api/tools/phpstan/` contiene **sólo** `phpstan.neon` (64 líneas, **0** secciones `services:`/`rules:`); **0** implementaciones de `PHPStan\Rules` en el árbol. `deptrac.yaml:10` se declara el hermano AST-aware |
| **#438** | premisa falsa | **46** ficheros importan `Symfony\Component\Security\*` en `api/src`; **0** en `Domain/`; **1** en `Application/` (`ProblemDetailsFactory:23-24`, y son dos clases de **excepción**, ya en baseline); **16** dentro de `Infrastructure/Security/`; de los **30** restantes, **28** sólo declarativos y **2** con servicio de runtime — `UserPatchRolesController.php:15` y `CreateInvitationController.php:12`, ambos `AuthorizationCheckerInterface` |

**Correcciones a los números del artefacto** (medidos hoy, el árbol se movió): 46 ficheros, no 47; 30 fuera de
`Infrastructure/Security/`, no 29; y el reparto es 28/2, no 29/2. La conclusión no cambia: el gate propuesto
rechazaría **28** usos correctos de `#[IsGranted]`.

**Trampa de medición que casi falsea esto.** El primer censo dio **0 ficheros** — artefacto de escapado de
backslashes en zsh, no un hecho. Con `git grep -lF` (cadena fija) salen 46. Y `ProblemDetailsFactory.php` es
UTF-8 con guiones largos en comentarios, así que `grep` lo declara *binary file matches* y manda ese aviso a
**stderr** dejando stdout vacío: un `grep -qv` encadenado lo clasificó como declarativo en silencio. Ambos
fallos son del tipo que devuelve el número que uno quiere ver. Script final: `tmp/br6/classify-438.sh`.

### Pase adversarial

**Realizado antes de `gh pr create`**, como manda la regla — y encontró un **GRAVE** sobre trabajo ya
commiteado y empujado (`87c50f32`), que es exactamente la ventana que abrir la PR al final elimina.

El subagente hostil se lanzó con autorización explícita de Sergio y **murió por límite de sesión antes de
devolver informe**. Su prompt sí llegó a fijar la hipótesis de mayor valor —*«¿sigue `EnumType` atado a su
validador tras el movimiento? Symfony deriva el nombre en `validatedBy()`; el test de la constraint instancia
el validador directamente y nunca pasa por ahí»*— y esa hipótesis se verificó a mano contra el validador real:
**cierta, y el defecto era mío**. Ver §T4 REVERTIDA.

Lo que el pase costó y lo que devolvió:

- **Coste:** una reversión, un guardrail nuevo con su rojo provocado, y dos correcciones de documentación.
- **Devolución:** un fatal de runtime en toda validación de `BankAccount` que **cuatro gates daban por verde**
  (`php.stan`, `php.deptrac`, `php.unit`, `php.quality`), y que habría llegado a `main` si la PR se hubiera
  abierto sobre «los gates están en verde».

#### Segunda ronda — AUTOREVISIÓN, con su limitación declarada

El subagente hostil se intentó **tres veces** y murió las tres sin devolver informe (una por límite de sesión,
dos por corte de proceso). La segunda ronda la ejecutó el propio autor. **Eso vale menos que una lectura
hostil ajena y así queda registrado**: la regla de la casa pide un contexto distinto precisamente porque el
autor no ve lo que dio por supuesto. Sergio decide si esto basta o si el pase se repite antes de mergear.

Cubierto, con medida y comando:

| Superficie | Resultado |
|---|---|
| **Vacuidad de las seis reglas** | Ninguna casa cero: `ui-is-foundational` 11 módulos, `components-root-is-foundational` **1**, `erpify-not-bounded-context` 31, `erpify-barrel-excludes-error-screens` **1** |
| **`to.path` como array bajo `reachable`** | Se OR-ea en **todas** las entradas. Probado aislando: un array cuya 1.ª entrada no casa nada y la 2.ª es `^src/app/` sale rojo (1 violación); ídem con la 3.ª (31) |
| **Anclaje del colector `classLike` de deptrac** | El `$` **restringe**, probado: con `…\Constraint$` bendecido, los cuatro `ConstraintViolation*` **sobrevivieron** en el baseline; un prefijo sin anclar se los habría tragado |
| **Alcance del cruiser** | 0 módulos de `tests/`, 0 fuera de `src/` |
| **`conditionNames`** | `uuid` resuelve a `dist/index.d.ts` y `inversify` a `lib/index.js`; con `doNotFollow: node_modules` no se entra en ellos, así que no hay riesgo de grafo browser-vs-node |
| **Las siete cifras de prosa** | Reproducen **exactas**: 17 aristas / 7 `type-only`, 24 capacidades, 0 barriles, 567 imports profundos, 0 profundos a `erpify`, 10 de 11 ficheros de `ui`, 26 seams |
| **Cifras de #438** | Reproducen: 46 / 0 Domain / 1 Application / 16 en `Infrastructure/Security/` / 28 declarativos + 2 runtime |
| **Enlaces markdown añadidos** | Los 8 resuelven a fichero concreto |
| **Comentarios prohibidos** | Cero story IDs y cero comentarios relativos al cambio en el código añadido |

**Hallazgo — SERIO, corregido.** Dos reglas se anclan hoy en **un solo fichero** (`src/components/cn.ts` y
`src/components/erpify/index.ts`). Si cualquiera se renombra o se disuelve, su regla pasa a casar cero y
**aprueba en silencio para siempre** — indistinguible de una regla que corrió y no encontró nada. Es el mismo
patrón que todo este lote combate, y el config no traía la autoprotección que sí lleva cada gate de
`api/tests/Unit/Shared/Architecture/`. Añadido
`pwa/tests/dependency-cruiser-rules-are-not-vacuous.test.ts`: lee el config, extrae cada `from.path` y exige
que seleccione ≥1 módulo. **Su rojo está provocado** — con la ruta del barril tipografiada, exit 2 nombrando
exactamente la regla vacua y las otras tres en verde.

**Falsa alarma propia, y merece registro porque es del tipo que engaña.** Al probar el array leí las primeras
seis líneas de la salida y concluí que la 2.ª entrada no disparaba. No era cierto: la 3.ª generaba 31
violaciones que ordenaban primero y tapaban la única de la 2.ª. Contar por regla en vez de leer `head` lo
deshizo. Un `head` sobre una salida ordenada es una medida sesgada.

**Superficies que NO recibieron lectura hostil**: si `to:` de cada regla es el correcto (sólo se comprobó
`from:`), y el cuerpo del `.cjs` frente a una config de dependency-cruiser idiomática.

### PR

### Review Findings

### Change Log

- **2026-08-12** — Rama rebasada sobre `origin/main` @ `3be82129` (tres commits: #691, #690, #704) antes de
  editar nada; sin conflictos. `baseline_commit` se conserva en `781c75a2`, que es contra lo que se midió la
  historia.
- **2026-08-12** — T0 (spike, veredicto SEGUIR), T1–T3 (#356: gate de grafo, cableado y seis rojos provocados),
  T4–T6 (#305: `EnumType` a `Domain/`, tres clases bendecidas por colector, baseline 21 → 16, dos «Documented
  exception» + ADR), T7 (siete correcciones de prosa). T8 bloqueado por permisos. T9: gates verdes.
- **2026-08-12** — Documentación del target nuevo `pwa.lint.graph` en `CLAUDE.md`,
  `docs/claude-code-quickref.md` y `docs/development-guide-pwa.md`, según la regla «New Make targets».
