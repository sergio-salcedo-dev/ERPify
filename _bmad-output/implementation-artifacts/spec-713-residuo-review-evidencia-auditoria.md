---
title: 'Residuo de la review de #713 — la aserción que falta y la guarda que no existe'
type: 'bugfix'
created: '2026-08-13'
status: 'in-review'
review_loop_iteration: 1
context:
  - '{project-root}/docs/rules/database.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** #713 cerró siete hallazgos de la review de #710 y mergeó sin cuatro. Los dos de fondo comparten
forma — prosa que afirma una garantía que el código no da. (a) El test con el que se cerró #405 promete
«ambos campos PII sellados en ambas cuentas», pero en el caso multi-entidad —el único que #405 existe para
cubrir— solo asierta `holderName`: una fuga de `iban` en claro bajo un flush de dos cuentas no enrojece nada.
(b) El docblock de `AuditRetentionThreshold` argumenta que ningún productor puede olvidar declarar su
exempción, y nada rechaza `[]` — ni el constructor ni PHPStan, porque el `@param` es `list<string>`.

**Approach:** Cerrar cada hallazgo donde vive, sin reabrir ningún diseño: la aserción que falta en el helper
del test multi-entidad; la guarda en el dominio, que deja muerta la rama que la rodeaba en el pruner; y las
tres frases sobre la vía de eliminación del `ip`/`user_agent` sobre-retenido, cualificadas.

## Boundaries & Constraints

**Always:**
- **Provocar el rojo** antes de dar por buena una aserción nueva: romperla, contar fallos, restaurar copiando
  bytes (nunca `git checkout --`), re-medir al final.
- Guarda de dominio con la convención del vecino: factoría estática sobre `DomainException` con `TYPE`,
  título que enuncia la regla, valores ofensores en `context`. Nunca `InvalidArgumentException` de SPL.
- Comparar `iban` contra el valor **canonicalizado**: `Iban::canonicalize()` pasa a mayúsculas y quita
  espacios/NBSP, así que un fixture no canónico haría que `assertStringNotContainsString` compare una cadena
  jamás almacenada y pase en vacío.
- Comentarios en presente, autoportantes, sin «antes/ahora».

**Ask First:**
- **Punto 11** (nada observa el revisit trigger triplicado): un issue de vigilancia choca con «un follow-up
  es PR propia, no un issue». En consulta con Winston y Amelia; pendiente de Sergio.
- Si el pase adversarial midiera que colapsar la rama del pruner cambia el SQL emitido.

**Decidido (Sergio):** la aserción de `iban` va **extendida** — además de no-claro y forma sellada, `iban`
entra en el bucle de descifrado `:133-140`, que hoy solo abre `holderName`. Eso prueba que el AAD liga por
**campo** también en el caso multi-flush, no solo en el de una entidad: cierra que dos campos sellados en un
mismo flush pudieran compartir AAD.

**Never:**
- No se reabre el diseño de la exempción (acción como dato en el threshold, predicado en el `SELECT`), ni el
  cuarto `AuditLevel` descartado, ni la sobre-retención de `ip`/`user_agent` (pesada y aceptada).
- No se toca `ORDER BY id … LIMIT :batch FOR UPDATE` ni su gate.
- No se generaliza el helper a «todos los campos PII»: `alias` es `#[PersonalData]` y queda `null` en ambos
  fixtures, así que `sealedCiphertext($decoded, 'alias')` fallaría en `assertIsArray`.
- No aparece `#405` ni ningún ID en comentarios de código.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Plan normal | `thresholdsAt()` con `AuditErasureEvidence::ACTIONS` | 3 thresholds con la misma exempción; el pruner enlaza siempre `:exemptActions` | N/A |
| Exempción vacía | `new AuditRetentionThreshold($l, $f, [])` | Lanza antes de que exista el objeto | `InvalidAuditRetentionPolicy` (marker-less → 5xx/Sentry) |
| Flush de dos cuentas | Dos `BankAccount`, un solo `flush()` | Ninguna fila lleva `holderName` ni `iban` en claro; `changes.iban.new` es objeto con `__enc__` en ambas | N/A |
| Campo caído del diff | `iban` ausente de `changes` | El test enrojece | `sealedCiphertext` falla en `assertIsArray` |

</frozen-after-approval>

## Code Map

- `api/tests/Functional/Shared/Audit/BankAccountAuditCryptoShreddingFunctionalTest.php` — test 2 (`:108`) es
  el caso multi-mint; `sealedHolderOf` (`:164-184`) asierta solo `holderName` (`:173`) y devuelve
  `sealedCiphertext($decoded, 'holderName')` (`:183`); el bucle `:133-140` consume `$sealed['ciphertext']`;
  fixtures en `:120` y `:123` (el segundo IBAN es literal inline).
- `api/src/Shared/Audit/Domain/AuditRetentionThreshold.php` — VO sin cuerpo de constructor (`:31-36`).
- `api/src/Shared/Audit/Domain/Exception/InvalidAuditRetentionPolicy.php` — hogar de la factoría nueva.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php` — composición condicional
  (`:100-108`), interpolada en el `SELECT` en `:143`.
- `api/tests/Unit/Shared/Audit/Domain/AuditRetentionPolicyTest.php` — ya lleva
  `#[CoversClass(AuditRetentionThreshold::class)]` (`:21`); no existe `AuditRetentionThresholdTest`.
- Las **cuatro** sedes de la frase sobre la vía de eliminación: `PRODUCTION_SECURITY_CHECKLIST.md:221-223`
  («clearable only if that administrator is themself erased»), `AuditErasureEvidence.php:50`
  («administrators are few, the removal path exists through their own erasure»),
  `docs/rules/database.md:64-67` («only if that administrator is themself erased») y
  `docs/adr/audit-activity-log.md:~242,~255` (las dos, en castellano).

## Tasks & Acceptance

**Execution:**
- [ ] `…/BankAccountAuditCryptoShreddingFunctionalTest.php` — renombrar `sealedHolderOf` → `sealedPiiOf`,
  pasarle el IBAN, añadir la aserción de no-claro y `sealedCiphertext($decoded, 'iban')`, nombrar ambos
  IBAN, devolver los dos cifrados con clave de campo (`holderName`/`iban`) y extender el bucle `:133-140`
  para descifrar ambos variando el campo del AAD; `:148` pasa a `$first['holderName']`. El nombre actual
  mentiría, y `assertStringNotContainsString` sola queda verde si el campo se cae del diff.
- [ ] `…/Exception/InvalidAuditRetentionPolicy.php` — factoría `exemptActionsMustNotBeEmpty()`; ampliar el
  docblock a las líneas del plan, no solo a sus ventanas.
- [ ] `…/Domain/AuditRetentionThreshold.php` — rechazar `[]` y estrechar el `@param` a
  `non-empty-list<string>`: el docblock ya argumenta el invariante, esto lo hace exigible.
- [ ] `…/Persistence/DbalAuditLogPruner.php` — enlazar `:exemptActions` siempre y borrar la rama condicional,
  ya inalcanzable por construcción.
- [ ] `…/Domain/AuditRetentionPolicyTest.php` — pinchar la guarda nueva.
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md`, `AuditErasureEvidence.php`, `docs/rules/database.md` — cualificar
  las tres frases con la precondición de demotion.
- [ ] `_bmad-output/implementation-artifacts/sprint-status.yaml` — `br-3-auditoria-crypto-cierres-eje-3` a
  `done` al cerrar la review completa.

**Acceptance Criteria:**
- Dado el test multi-entidad, cuando se borra la aserción de `iban`, entonces la suite enrojece — medido.
- Dado `new AuditRetentionThreshold($l, $f, [])`, cuando se construye, entonces lanza y ningún threshold
  vacío alcanza el pruner.
- Dado el plan de producción, cuando el pruner emite su sentencia, entonces es byte-idéntica a la de `main`.
- Dadas las tres frases, cuando un lector busca cómo se limpia el `ip` de un administrador, entonces
  encuentra nombrada la precondición: la erasure se **rechaza** mientras lleve `ADMIN`
  (`administrator-erasure-requires-demotion`), hace falta un segundo admin que lo degrade, y en una
  instalación de un solo administrador no hay vía.

## Spec Change Log

**2026-08-13 — revisión del spec por Sergio.** Dos criterios de aceptación eran falsificables solo en
apariencia y se endurecieron antes de implementar:

1. «Borrar la aserción de `iban` → la suite enrojece» no era demostrable sin decir *cuál* aserción se borra:
   con dos aserciones cubriendo el campo, quitar una deja a la otra tapando la regresión. Sustituido por dos
   mutaciones aisladas con observación propia cada una. **Medido:** `iban` fuera del diff → enrojece solo
   `assertIsArray` de `sealedCiphertext` («Failed asserting that null is of type array»); `iban` envuelto en
   `__enc__` sin cifrar → enrojece solo `assertStringNotContainsString` («no iban survives in clear»).
2. «La sentencia es byte-idéntica a la de `main`» pasó de aceptación a **prueba obligatoria**, comparando
   captura contra captura bajo el mismo escenario (texto, placeholders, orden y nombres de parámetros, tipos
   y valores). **Medido:** `diff` exit 0 sobre las tres sentencias del plan.

**Desvío durante la implementación.** El par `non-empty-list` + guarda en runtime que el spec pedía no
compila bajo PHPStan: el propio test que prueba la guarda se vuelve estáticamente imposible (`argument.type`,
`identical.alwaysFalse`, `new.resultUnused`). Resuelto con dos `@phpstan-ignore` acotados que nombran la
razón, siguiendo el precedente ya establecido en el repo para esta misma forma
(`Shared/Validation/Infrastructure/EnumTypeValidator.php:23` y `EnumTypeValidatorTest.php:85`).

**Alcance corregido contra el handoff, medido y no supuesto.** El punto 8 no estaba cerrado por #713 —esa PR
tocó otra frase del checklist— y vive en **cuatro** ficheros, no en tres: el ADR repite ambas frases en
castellano.

## Pase adversarial (antes de abrir la PR)

Lectura hostil por un contexto distinto del autor, sobre el cambio **ya hecho** — no sobre el estado del que
partió, que es el fallo que originó esta cadena. Devolvió **2 GRAVE, 1 SERIOUS y 4 MINOR**, y los dos GRAVE
estaban en las ediciones de documentación de esta misma rama: prosa afirmando una garantía que el código no
da, exactamente la clase de defecto que la rama existe para cerrar.

- **G1 — «la degradación exige un segundo administrador que la ejecute» era falso.** *Verificado*:
  `ChangeUserRoles::guardActiveAdministratorsSurvive()` solo consulta
  `keepsAnActiveAdminWithout($userId)`, que pregunta si **existe** otro admin activo; no hay ninguna
  comparación actor-contra-objetivo en la clase, así que la auto-degradación no está guardada. El invariante
  es sobre quién **sobrevive**, no sobre quién actúa — y `docs/adr/authorization-model-boundaries.md:163-166`
  ya lo decía. Corregido en las cuatro sedes.
- **G2 — «en una instalación de un solo administrador no hay vía de eliminación» era falso.** *Verificado*:
  `EraseActorAuditTrailCommand` (`audit:gdpr:erase`) acepta cualquier UUID, valida **solo** la forma, no
  inyecta nada que pueda consultar un rol, y su `UPDATE` redacta `ip`/`user_agent` casando por `actor_id`. El
  409 citado pertenece a **otra** puerta de entrada (`FulfilIdentityErasure`, la ruta de identidad). Peor: los
  mismos dos ficheros describen ese comando pocas líneas más abajo, así que la frase se contradecía sola. La
  corrección cambiaba una imprecisión por un **absoluto falso**, que es peor que la frase que sustituía.
  Corregido en las cuatro sedes distinguiendo ruta de identidad de ruta de operador.
- **S1 — el docblock decía «ambos campos personales»; el agregado tiene tres.** `alias` es `#[PersonalData]`,
  queda `null` en ambos fixtures y **no lo asierta nada en todo el repo**. Reformulado para nombrar lo que
  cubre y declarar `alias` como no cubierto, en vez de sobre-prometer.
- **M1** comentario agramatical en el pruner, reescrito. **M3** `ACTIONS` declaraba `list<string>` mientras el
  llamador ya exige `non-empty-list<string>`; estrechado. **M4** la factoría nueva no tenía test propio y la
  atribución de cobertura la habría contado como línea nueva sin cubrir; añadido.
- **M2 declinado con argumento**: usar `Iban::canonicalize()` en el fixture funde el valor esperado y el
  almacenado en una sola implementación. Cierto, pero el sujeto de este test es `PiiDiffSealer`, no el
  canonicalizador —que tiene sus propios tests—, y protege contra el riesgo real de un fixture futuro no
  canónico, que haría la búsqueda vacua.

**Verificado limpio por el pase**, tras intentarlo: el caso vacío del threshold es realmente inalcanzable
(un solo sitio de construcción, sin transporte Messenger, sin reflexión, sin hidratación —
`newInstanceWithoutConstructor|ReflectionProperty|unserialize(` da cero coincidencias en `api/src`); el SQL
byte-idéntico, re-derivado leyendo y no fiándose de la sonda; los dos `@phpstan-ignore` son portantes y no
tapan nada (PHPStan `max`, sin baseline, reporta los ignores que no casan); el bucle sí prueba la ligadura
del AAD **por campo**; y ninguna aserción es vacua. Sin hallazgos en inyección, autorización, secretos,
migraciones, RFC 9457 ni Messenger — el diff no los toca.

## Design Notes

**Guarda-y-colapso, no un test para la rama.** La rama cubre un estado que el dominio declara inválido;
pincharla sería asertar que el pruner maneja bien algo que no debe poder ocurrir. El repo ya resolvió esta
pregunta al revés y en el mismo `Shared/`: `FilterApplier.php:150-157` rechaza un `IN` vacío con el
razonamiento escrito en la guarda, siendo igual de inalcanzable desde fuera. Medido: `new
AuditRetentionThreshold(` aparece **una sola vez** en el repo (`AuditRetentionPolicy.php:62-66`, constante de
2 miembros), cero productores en tests, y ningún test recorre la rama falsa — borrarla hoy es invisible.

**Qué compra.** Sin guarda, `[]` no falla ruidosamente: DBAL 4.4.4 sustituye el placeholder por el token
literal `NULL` (`ExpandArrayParameters.php:99-103`, leído), así que `action NOT IN (NULL)` es `UNKNOWN` para
toda fila y la purga es un no-op silencioso — cerrada en borrado, **abierta en el deber de retención**.
Estrechar el `@param` la mata además en estático, antes de ejecutar nada.

**Alternativa descartada.** Una `InvalidAuditRetentionThreshold` propia costaría otro `TYPE` y otra entrada
en el contrato de error para una guarda con un solo llamador; pierde contra ampliar la vecina, cuyo
razonamiento marker-less («fallo de configuración del servidor, no entrada de cliente») aplica idéntico.

## Verification

**Commands:**
- `make php.unit c='--filter BankAccountAuditCryptoShreddingFunctionalTest'` — verde con las aserciones de
  `iban`, y **rojo** con la aserción stubeada.
- `make php.unit c='--filter AuditRetentionPolicyTest'` — la guarda pasa (`--list-tests` antes, para
  confirmar que el filtro no casa un subconjunto).
- `make php.unit c='--filter AuditPrunePlanFunctionalTest'` — la sentencia capturada explica igual.
- `make php.stan` sobre cada fichero tocado; `make php.quality` y `make php.test` al final, cada uno con su
  exit code impreso.
