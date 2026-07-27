# Prompt — Predicados de dominio con doble verdad (raíz: patrón Specification)

> Pégalo tal cual en una sesión nueva de Claude Code, en la raíz del repo ERPify.
> Origen: revisión del artículo *"Stop Writing 40-Method Repositories: The Specification Pattern in Symfony"* (laurentmn, Medium)
> contrastado contra el código actual. **El patrón Specification genérico se descartó** — el hallazgo real es otro y está acotado abajo.

---

## Contexto que ya está verificado (no lo re-investigues desde cero, sí confírmalo)

La premisa del artículo (repositorios de 40 métodos, `findByXAndYAndZ` en cascada) **no aplica a ERPify**.
El propio artículo fija sus criterios de adopción en la sección de trade-offs, y ERPify no cumple ninguno:

| Criterio del artículo (literal) | ERPify |
|---|---|
| *"once a repository starts collecting near-duplicate methods"* | Cero near-duplicates. No existe el par `findActiveUsersOrderedByName` / `...OrderedByCreatedAt`: el orden lo resuelve `DoctrineSearchEngine`, no un método por combinación |
| *"once the same 3 filters keep getting combined in new ways across different features"* | Ya resuelto por el motor Criteria y el wire `filters[N][field\|operator\|value]` |
| *"Small projects with 3 or 4 queries per entity gain little from this pattern"* | Repositorio mayor: `DoctrineSessionRepository`, 7 métodos públicos. Media ~4 |

Además:

- Los puertos ya están segregados por ISP: `BankRepository` (lifecycle) vs `BankSearchRepository` (read-side)
  vs `BankAccountCounter` vs `BankExistenceChecker`.
- La composición dinámica de consultas ya existe como **patrón Criteria**, decidido y documentado en
  [`docs/adr/filters-search-criteria.md`](../../docs/adr/filters-search-criteria.md):
  `Shared/Search/Domain/{Filter,Filters,FilterOperator,SearchCriteria}` + `FilterApplier` + `SearchFieldMap`
  (allow-list obligatoria) + `DoctrineSearchEngine` como único query-shaper del read-path.
- El artículo cablea las specifications al `matching()` de `Doctrine\Common\Collections\Criteria`. Ese camino
  ya se evaluó y se descartó en el ADR (*"`codelytv/criteria-to-doctrine` — apunta a `Collections\Criteria`
  (API equivocada)"*): `matching()` no soporta la paginación keyset con cursores opacos ni la allow-list
  obligatoria por repositorio, que son las dos garantías centrales de `Shared/Search`.
- El artículo reconoce que el patrón **no mejora el rendimiento** (*"Performance stays identical... The pattern
  changes how you organize code, not what SQL Doctrine sends"*), y que los combinators `Or`/`Not` son código
  real a mantener y testear. En ERPify ese coste se evita hoy con AND fijo y `FilterOperator` como punto de
  extensión.

**Por tanto: NO introduzcas un framework `Specification` genérico** (`isSatisfiedBy()` + `and()/or()/not()` +
traductor a QueryBuilder, ni `happyr/doctrine-specification`, ni un `matching(Specification $spec)` en los
puertos). Sería un segundo vocabulario de composición de consultas conviviendo con el Criteria ya decidido —
exactamente la deriva que el ADR blindó — y no supera el gate Rule-of-Three/YAGNI de `CLAUDE.md`. Si al leer el
código crees que sí procede, **párate y arguméntalo al usuario antes de escribir nada**.

## El hallazgo que sí es real: doble verdad del predicado de validez temporal

La misma regla de negocio está escrita **dos veces en dos lenguajes distintos**, sin nada que las ate:

1. **En PHP**, `api/src/Iam/Session/Domain/Entity/Session.php`:
   - `isExpired(DateTimeImmutable $now): bool` → `$this->expiresAt <= $now`
   - `isActive(DateTimeImmutable $now): bool` → `SessionStatus::ACTIVE === $this->status && !$this->isExpired($now)`
2. **En DQL**, `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php`:
   el par `s.status = :active` + `s.expiresAt > :now` aparece **3 veces** (`findActiveById()`,
   `findByUserId()`, `bulkRevokeActive()`), copiado a mano en cada una.

Verifica ambos puntos antes de tocar nada (`grep -rn "expiresAt > :now" api/src` debe dar 3 aciertos).

Agravantes que hacen que la deriva sea **invisible a los tests**:

- `Session::isActive()` **no tiene ningún consumidor** fuera de la propia entidad (`grep -rn "->isActive(" api/src api/tests`
  solo devuelve usos de `User::isActive()`, que es otra cosa). La regla que de verdad se ejecuta en producción
  es la de DQL; la de PHP es una segunda declaración sin ejercitar.
- El mismo esquema se repite en Identity: `PasswordResetToken::isExpiredAt()` (PHP) solo lo consume el doble
  de test `tests/Unit/Iam/Identity/Application/InMemoryPasswordResetTokenRepository.php`, mientras el adapter
  real usa `t.expiresAt < :now`. Los dobles in-memory reimplementan en PHP los predicados que el adapter
  escribe en DQL: si uno cambia, los tests unitarios siguen verdes.

**Principio en juego:** DRY sobre una *regla de negocio* (no sobre código incidental) + SSOT. Hoy el criterio de
admisibilidad de una sesión — el que decide si un usuario sigue autenticado — no tiene un único sitio donde
leerse ni donde cambiarse.
**Objetivo que compra:** mantenibilidad y seguridad. Cambiar la regla (añadir `SUSPENDED`, un periodo de gracia,
o mover a expiración por inactividad) hoy exige acertar en 4 sitios; fallar uno abre un agujero de auth silencioso.
**Coste y alternativa descartada:** el coste es un helper y un test de equivalencia. La alternativa "dejarlo así"
pierde porque la divergencia PHP↔DQL no la detecta ninguna suite actual.

---

## Lo que tienes que hacer

### Paso 0 — Gate de rama (obligatorio, `CLAUDE.md`)

No crees rama ni worktree por tu cuenta. **Propón al usuario** el plan de rama
(sugerencia: `refactor/iam-session-validity-predicate` sobre `main`, en worktree vía
`make worktree.create BRANCH=...`) y **espera su OK**. Si te autoriza, trabaja dentro del worktree.

### Paso 1 — Deduplicar el predicado DQL (regla del boy-scout, bajo riesgo)

Dentro de `DoctrineSessionRepository`, extrae el par `status = ACTIVE AND expiresAt > :now` a **un único punto
nombrado** (un método privado que reciba el `QueryBuilder` y aplique condición + binds, en la línea de la
constante `USER_ID_FILTER` que ya existe ahí). Sin clase nueva, sin abstracción nueva, sin tocar los puertos.

Restricciones:
- **No cambies el contrato observable** de `SessionRepository` ni el comportamiento SQL resultante.
- Respeta la conversión `DbalException → SessionStoreUnavailable` de `findActiveById()`.
- `bulkRevokeActive()` es un UPDATE dirigido: el helper debe servir igual para SELECT y UPDATE, o si no encaja
  limpio, **no lo fuerces** — dedupica solo donde queda natural y dilo en el resumen.

### Paso 2 — Cerrar la doble verdad (la decisión de diseño; presenta opciones si dudas)

Elige **una** y justifícala en el commit/PR:

- **(a) Borrar la declaración muerta.** Si `Session::isActive()`/`isExpired()` no tienen consumidor y no lo
  van a tener, la doble verdad se cierra eliminando la copia PHP. Es la opción mínima y la más honesta con
  "minimum code / nothing speculative". Verifica antes que de verdad no las usa nadie (src, tests, Behat).
- **(b) Hacer del predicado PHP la fuente y atarlo con un test.** Si prefieres conservar la regla en el dominio
  (útil para futuros dobles in-memory), deja `Session::isActive()` como la definición canónica y añade un test
  de **equivalencia** que ejercite el mismo conjunto de sesiones por los dos caminos —predicado PHP e
  `findActiveById()`/`findByUserId()` reales contra la base— y falle si divergen. Casos que debe cubrir:
  ACTIVE no expirada, ACTIVE justo expirada (borde `expiresAt == now`, ojo `<=` en PHP vs `>` en DQL),
  ACTIVE expirada, REVOKED vigente, REVOKED expirada.

**No inventes una tercera vía con un objeto `SessionValidity` compartido que Domain traduzca a DQL**: Domain no
puede conocer fragmentos DQL (`make php.deptrac` + `docs/adr/external-dependencies-in-domain.md` lo prohíben).

### Paso 3 — Guardarraíl documental (propón, no lo des por hecho)

`CLAUDE.md` dice "cada vez que algo se rompe → añade un guardarraíl". Como este análisis se va a repetir cada vez
que alguien lea un artículo sobre Specification, **propón al usuario** añadir 3–5 líneas a
`docs/adr/filters-search-criteria.md` en la tabla de "Alternativas descartadas": *patrón Specification genérico
(`isSatisfiedBy` + and/or/not + `matching()` sobre `Collections\Criteria`) — descartado: duplica el vocabulario
Criteria ya decidido, y `matching()` no soporta cursores keyset opacos ni la allow-list obligatoria por
repositorio; los filtros componen con AND y el punto de extensión para OR/NOT es `FilterOperator`, no una
segunda jerarquía de objetos*. **No lo escribas sin su OK** (regla de densidad de `docs/`).

---

## Fuera de alcance (proponer, nunca hacer sin permiso)

- Extender `FilterOperator` con `or`/`not`. Hoy `Filters` compone solo con AND por decisión del ADR; sin
  consumidor real, añadirlo es especulativo.
- Tocar `Shared/Search/**`, el `DoctrineSearchEngine`, los cursores keyset o cualquier field map.
- Barridos por otros bounded contexts (Backoffice, Organization). El alcance es Iam/Session, y como mucho la
  nota de Identity del Paso 2.
- Unificar los dobles in-memory de tests con los adapters reales. Es deuda real que encontraste de paso:
  si lo ves, **abre un issue de seguimiento**, no infles el PR.

## Checks obligatorios antes de declarar hecho

```bash
make php.stan          # en cada fichero PHP tocado
make php.unit          # y los tests de Iam/Session en concreto
make php.behat         # el gate de auth/sesión es de contrato observable
make php.quality       # sweep final (incluye deptrac + bounded-context + error-contract)
```

Además, antes del commit final:
- Pasada de seguridad del checklist de `CLAUDE.md` sobre el diff: esto toca el **gate de autenticación**, así
  que la clase "Authentication / Authorization" aplica sí o sí — deja constancia explícita de la revisión
  adversarial en la descripción del PR (auto-certificarse no cuenta como pasada).
- Barre tus propios comentarios: nada de comentarios relativos al cambio ("antes esto estaba duplicado…") ni
  IDs de historia. El comentario debe explicar el *porqué* del código actual y sostenerse sin el diff.
- Commit en Conventional Commits, p. ej. `refactor(iam): unify session temporal-validity predicate`.

## Cómo cerrar

En el resumen final di explícitamente:
1. Qué opción del Paso 2 elegiste y por qué.
2. Si el helper del Paso 1 cubrió las 3 apariciones o solo 2, y por qué.
3. Qué propusiste (guardarraíl del Paso 3, issues de seguimiento) y qué queda pendiente de decisión del usuario.
