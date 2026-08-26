---
story: gh-305
title: Ratchet del baseline de deptrac — el seam de validación
status: done
---

# #305 — bajar el baseline de deptrac: la parte que no depende de una decisión abierta

## Estado del baseline al empezar

El cuerpo de #305 está desactualizado: describe una lista que ya no existe. `BankAccountSearcher → Messenger`,
`Domain → DateTimeNormalizer`, `Domain → ExecutionContextInterface`, `StoredObjectOrphanCleaner → AutowireIterator`,
`BankDeleter → ForeignKeyConstraintViolationException` y la categoría `EntityManager en Application` que #277
introdujo **ya no están en el fichero**. Lo que quedaba eran **18 entradas en 7 clases**, en tres grupos:

| Grupo | Entradas | Naturaleza |
|---|---|---|
| `ProblemDetailsFactory` → HttpFoundation / HttpKernel / Security / Validator / DI | 7 | adaptador HTTP alojado en `Application/` |
| `Shared\Validation\Application\Validator` → runtime del Validator de Symfony | 6 | el seam de validación |
| 4 servicios de `Application` → `ValidationFailedException` | 4 | **imports muertos** |
| `BankAccount` (Domain) → `Shared\Validation\Infrastructure\EnumType` | 1 | `Constraint` alojado en `Infrastructure/` |

## Lo que entra en este cambio

Sólo el tercer grupo: **4 entradas de 18**, y son las únicas que se pagan sin decidir nada.

`BankAccountCreator`, `BankAccountStatusChanger`, `BankAccountUpdater` y `Bank\Application\BankUpdater` importaban
`Symfony\Component\Validator\Exception\ValidationFailedException` **exclusivamente para un `@throws`**. Ninguno la
captura, ninguno la nombra en posición de tipo — verificado con una búsqueda de `catch (`, `instanceof` y posición de
tipo en los cuatro ficheros: cero coincidencias.

El dato que convierte esto de «pérdida de documentación» en «cierre de una deriva»: de las **ocho** llamadas a
`Validator::ensure()` que hay en `Application/`, sólo esas cuatro declaraban el `@throws`. `BankCreator`, `CreateUser`,
`InviteUser` y `ProvisionOrganization` viven sin él desde siempre. Un 50/50 no es una convención que se rompa aquí:
es una costumbre que nunca se cerró, y se cierra hacia el lado que no miente sobre las capas.

El baseline pasa de **18 entradas en 7 clases** a **14 en 3**.

## Lo que NO entra, y por qué

**El bless del seam de validación (6 entradas) queda fuera, y no por prudencia: el mecanismo propuesto no funciona.**

La forma obvia — mover las 6 entradas del baseline a `skip_violations` en `tools/deptrac/deptrac.yaml` — pondría
**`DeptracSeamSyncGateTest` en rojo**. Ese gate hace `assertSame()` entre los seams de `api/.bounded-context-allowlist`
y **todas** las entradas de `skip_violations` de `deptrac.yaml`, sin filtrar por target: su `seamsFromDeptracConfig()`
itera el mapa entero y compone `importer => target`. Una entrada vendor ahí no tiene contrapartida posible en el
allowlist, que es un fichero de seams cross-context entre módulos de negocio. El bless necesita un tercer sitio o una
extensión consciente de ese gate — diseño, no un movimiento de configuración.

`ProblemDetailsFactory` (7) y `EnumType` (1) tampoco entran: son movimientos de fichero con cambio de namespace y
rewiring de servicios, y este entorno no puede ejecutar ningún gate que lo verifique (ver más abajo).

## Verificación — y su límite, declarado

Este contenedor remoto **no tiene daemon de Docker ni árbol `api/vendor/`**, y `composer` no puede reconstruirlo
(`Could not authenticate against github.com`). Por tanto **no se ha ejecutado ni un solo gate del repo**:

```
make php.deptrac → failed to connect to the docker API at unix:///var/run/docker.sock
                   make: *** [make/php-quality.mk:435] Error 1
```

Lo que sí se verificó, y con qué instrumento:

- **Sintaxis**: `php -l` sobre los cuatro ficheros (PHP 8.4 CLI local) — sin errores.
- **Inercia de los imports**: búsqueda de `catch (`, `instanceof` y posición de tipo — cero coincidencias.
- **YAML del baseline**: parseado con `yaml.safe_load`; 3 clases, 14 entradas.
- **Alineación de los `@throws`**: recalculada por aritmética (ancho = nombre de tag más largo del bloque + 1),
  no a ojo, porque `@PhpCsFixer` + `@Symfony` alinean verticalmente y `php.cs-fixer.dry-run` es un gate de CI.
  Dos bloques cambiaron de columna (`BankAccountCreator`, `BankUpdater`) y dos no (`BankAccountStatusChanger`,
  `BankAccountUpdater`, cuyo ancho lo sigue fijando `BankAccountNotFoundException`).

**El baseline se editó a mano, y su cabecera lo prohíbe** (`GENERATED — do not hand-edit`). No había alternativa:
`make php.deptrac.baseline` necesita el contenedor. El contenido es idéntico al que produciría la regeneración —
las cuatro entradas desaparecen porque los imports que las causaban ya no existen — y eso es falsable en un comando:
`make php.deptrac.baseline` no debe producir diff. **Si lo produce, este cambio está mal y hay que rehacerlo.**

## Adversarial pass

Dos lecturas hostiles independientes sobre la decisión de fondo (¿el `Validator` compartido sigue hablando la
excepción nativa de Symfony, o se convierte en puerto de dominio?), ejecutadas por dos agentes distintos con el
código delante y sin ver la respuesta del otro. Ambas concluyeron **descartar el puerto**, por caminos que no se
solapan, y ambas fallaron en el mismo punto ciego.

**Hallazgo 1 (arquitectura).** El puerto de dominio no elimina un tipo, **añade uno**. Las reglas de validación son
atributos `#[Assert\…]` sobre las entidades de `Domain/`, admitidos por `Vendor.PassiveMetadata` en el propio
`deptrac.yaml:165`. Abstraer la *invocación* dejando el *vocabulario* en Symfony paga la indirección sin cobrar la
portabilidad. Además la nativa es inevitable: 10 ficheros usan `#[MapRequestPayload]`/`#[MapQueryString]`, donde el
`RequestPayloadValueResolver` la lanza antes de que corra código nuestro, y `UnknownPayloadMemberListener.php:61` la
construye a mano.

**Hallazgo 2 (coste medido).** El refactor toca 25–35 ficheros: 11 ficheros de test asertan `validation-failed` y
**97 escenarios Behat en 17 features** tocan un 422. Obliga a duplicar el brazo del `match` en
`ProblemDetailsFactory` con dos `findInChain` emitiendo el **mismo** `type: 'validation-failed'` — exactamente la
ambigüedad de dos acuñadores sin dueño que CLAUDE.md prohíbe en *Minting a `ProblemDetails.type`* — y a tocar
`docs/api-error-contract.md` bajo NFR26 para documentar que un tipo del contrato tiene dos productores, que es
documentar un defecto. Y `ProblemDetailsFactory` conserva sus 7 entradas de baseline igual: el refactor no le quita
ninguna.

**Hallazgo 3 — GRAVE, y es el que ninguna de las dos lecturas encontró.** Ambas recomendaron mover las 6 entradas del
`Validator` a `skip_violations` en `deptrac.yaml` como paso siguiente. **Eso habría abierto una PR roja**, porque
`DeptracSeamSyncGateTest` exige igualdad exacta entre ese mapa y `.bounded-context-allowlist`, y ninguna de las dos
leyó ese fichero. El hallazgo se encontró leyendo `tests/Unit/Gate/` antes de tocar la configuración, y es la razón
por la que el bless queda fuera de este cambio en vez de dentro. Coste de no haberlo buscado: una PR roja y un
diagnóstico a ciegas, en un entorno donde no se puede ejecutar el gate que la habría delatado.

**Hallazgo 4 (menor, no accionado).** `Validator::rebindEmptyPropertyPath()` no es política de validación sino
reparación del formato de wire — existe para que `violations[].field` no salga vacío — y es responsable de 3 de los 6
imports de framework del fichero (`ConstraintViolation`, `ConstraintViolationList`, `ConstraintViolationListInterface`).
Moverla al borde HTTP bajaría el seam de 6 a 3 imports antes de decidir nada sobre el bless. Es una propuesta
separada, no un requisito de #305, y no se ha tocado.

**Clases del checklist de seguridad que no aplican**, y se dice en vez de saltárselas en silencio: el cambio no toca
inyección, autenticación, autorización, validación de entrada (borra documentación de una excepción, no una
comprobación), asignación masiva, codificación de salida, secretos, CORS/CSRF/Mercure, migraciones ni handlers de
Messenger. No hay superficie `pwa/`. El comportamiento en tiempo de ejecución es idéntico: los cuatro `use`
eliminados no se resolvían en ninguna ruta de ejecución.

### Tercera lectura — confirmación de D5 (rama `chore/shared-validator-port-s1al`)

Tercera lectura hostil, independiente de las dos anteriores (agente fresco, sin ver el razonamiento de arriba ni el
de la ADR), centrada específicamente en las afirmaciones de D5. Método: releyó `Validator.php`, `deptrac.yaml`,
`deptrac.baseline.yaml`, `.bounded-context-allowlist`, `DeptracSeamSyncGateTest.php`,
`UnknownPayloadMemberListener.php`, `ProblemDetailsFactory.php`, `BankFinder.php`, `ValidatorTest.php`; corrió
`grep` sobre todos los call sites, un diff contra `origin/main`, y `make php.deptrac`.

**Verificación 1 (conteo).** Confirmado: 6 entradas exactas en el baseline, `GroupSequence` nunca contó.

**Verificación 2 (call sites).** Confirmado exhaustivamente: los 9 hits de `grep -rn "validator->ensure(" api/src`
coinciden uno a uno con la lista de D5. Ningún otro punto de inyección de `Validator` en el árbol.

**Verificación 3 — MODERADO, accionado.** El agente encontró que el ejemplo del propio docblock de `Validator.php`
(`BankFinder::find($id, …, propertyPath: 'id')`) era **ficticio**: `BankFinder::find()` no toma `propertyPath` y ni
siquiera llama a `Validator::ensure()` — valida vía `Uuid::ensure($id)`. Cero llamadas de producción usan
`propertyPath:` hoy. Esto no invalida la decisión de no mover `rebindEmptyPropertyPath()` en este cambio (el propio
hallazgo la califica de "defendible bajo la regla de higiene de alcance de este repo"), pero sí es un defecto real y
verificable en el fichero que arrastraba el propio D5 al citarlo como ejemplo. **Corregido en esta misma rama**: el
docblock ya no cita el ejemplo inexistente, describe el mecanismo sin nombrar un caller concreto. `make php.stan` y
`ValidatorTest` (37/37) en verde tras el cambio.

**Verificación 4 (`DeptracSeamSyncGateTest`).** Confirmado, y mejor argumentado de lo escrito: la cabecera del propio
`.bounded-context-allowlist` restringe sus entradas a seams de módulo de negocio — mover ahí las 6 líneas de
`Validator` no es solo "el test fallaría", es un error de categoría contra el propósito declarado del fichero, no un
accidente de mecanismo.

**Verificación 5 (`ValidationFailedException` ya nativa vía `UnknownPayloadMemberListener.php:61`).** Confirmado,
cita exacta verificada.

**Verificación 6 (mecanismo y consistencia).** `make php.deptrac`: `Violations 0, Uncovered 0, Errors 0`. El diff
contra `origin/main` era, en el momento de esta pasada, puramente de documentación (más el fix de la verificación 3
después). ADR D5 y el bullet de `api/CLAUDE.md` coinciden en cada cifra y cita, sin deriva entre ellos.

**Veredicto global (agente fresco):** la conclusión de D5 sobrevive la lectura hostil. Cada afirmación con peso
factual se sostiene contra el código y la puerta está verde. El único fallo real (verificación 3) queda corregido en
la misma rama.

## Actualización — el seam de validación, resuelto (rama `chore/shared-validator-port-s1al`)

El primer punto de la lista de abajo está cerrado. El bless se hizo con el mecanismo que **sí** funciona (baseline
generado, nunca `skip_violations` — exactamente lo que el Hallazgo 3 exigía) y quedó documentado como
`docs/adr/external-dependencies-in-domain.md` D5, más un puntero desde `api/CLAUDE.md`. Correcciones sobre lo
escrito arriba: el conteo real del baseline es **6** símbolos para `Validator`, no 7 — `GroupSequence` nunca fue
violación (ya la cubre `Vendor.PassiveMetadata`); comprobado leyendo `tools/deptrac/deptrac.baseline.yaml`
directamente, no de memoria. El Hallazgo 4 (mover `rebindEmptyPropertyPath()` al borde HTTP, 3 de los 6 símbolos)
sigue sin tocarse, por la misma razón que entonces: propuesta separada, no requisito de esta decisión.

También corrige el ítem 3 de más abajo: no es un ítem abierto. `docs/adr/external-dependencies-in-domain.md` ya
documentaba, antes de esta rama, que mover `EnumType` a `Domain/` se probó y se descartó — `Constraint::validatedBy()`
ata constraint y validador por nombre, y separar el par revienta en runtime con todos los gates en verde
(`ConstraintValidatorResolutionGateTest` cierra sólo una de las tres salidas). `EnumType` se queda donde está de
forma permanente, no es trabajo pendiente de #305.

## Lo que queda abierto en #305

14 entradas en 3 clases:

1. ~~**El seam de validación** (6)~~ — resuelto arriba: bless documentado, se queda en el baseline.
2. **`ProblemDetailsFactory`** (7) — mover a `Infrastructure/Http/`. Sólo lo consume `Infrastructure/`
   (`ExceptionResponder`), así que es un movimiento sin ripple hacia dentro, pero necesita gates para confirmarlo.
3. ~~**`EnumType`** (1)~~ — no es un ítem abierto; ver la corrección arriba.
