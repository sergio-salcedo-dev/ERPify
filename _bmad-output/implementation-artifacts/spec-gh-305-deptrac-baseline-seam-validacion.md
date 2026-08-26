---
story: gh-305
title: Ratchet del baseline de deptrac — el seam de validación
status: in-review
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

## Lo que queda abierto en #305

14 entradas en 3 clases, en dos decisiones separadas:

1. **El seam de validación** (6) — bless con un mecanismo que no rompa `DeptracSeamSyncGateTest`, o puerto (descartado
   por las dos lecturas de arriba).
2. **`ProblemDetailsFactory`** (7) — mover a `Infrastructure/Http/`. Sólo lo consume `Infrastructure/`
   (`ExceptionResponder`), así que es un movimiento sin ripple hacia dentro, pero necesita gates para confirmarlo.
3. **`EnumType`** (1) — el `Constraint` a `Validation/Domain/`, dejando `EnumTypeValidator` en `Infrastructure/`.
