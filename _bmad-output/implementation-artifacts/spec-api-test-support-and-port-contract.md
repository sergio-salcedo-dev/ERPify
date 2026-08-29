---
title: 'Test support extraído y dos contratos de puerto declarados'
branch: 'chore/api-test-support-and-port-contract-jind'
base: '202767ab'
status: 'in-review'
date: '2026-08-29'
---

## Intent

Cuatro decisiones ya tomadas y argumentadas (D1–D4), implementadas en una rama y una PR. Ninguna se
re-litiga aquí; lo que este documento añade es la **medición** de cada premisa contra el árbol y la
falsificación de cada control nuevo.

Las cuatro comparten una forma: en cada una había una afirmación que nadie podía romper — un parámetro
inutilizable pero declarado, un `preg_replace` que corrompe en silencio, un doble que promete más que su
puerto, y once copias byte-idénticas de un helper. En los cuatro casos el arreglo no es «documentarlo mejor»
sino hacer que equivocarse **lance**.

## D1 · `StrictRequestPayload` deja de declarar `$acceptFormat`

El estado inválido pasa a ser irrepresentable en vez de rechazado. El resolver de Symfony corre su
comprobación de formato sólo mientras el valor es **truthy** (`if ($attribute->acceptFormat && …)`), así que
un valor falsy no la afloja: la **salta**, y el endpoint acepta form-encoded y multipart. Una guarda que
rechaza ese valor sólo puede ser tan completa como su propio predicado — la forma que ya ha fallado dos veces
en este repositorio. Un parámetro que no existe no tiene predicado del que quedarse corto.

**Medido antes de tocar nada, sobre la base:** 13 sitios llevan `#[StrictRequestPayload]` **desnudo**,
`acceptFormat` no se pasa en ningún sitio fuera del propio test, y no hay ni una construcción posicional. El coste tiene guardián y no sólo prosa:
`serializationContext` es ahora el primer parámetro posicional, así que `new StrictRequestPayload(['json'])`
ya no lanza — alimenta el contexto del serializador, donde no cambia nada y no dice nada. Un docblock es
justo el control que el razonamiento de este tipo declara insuficiente, así que
`nothingConstructsItPositionally` barre `src` y `tests` y exige que toda construcción nombre sus argumentos.
El barrido lee a través de `PhpSource::withoutComments()`, porque los docblocks que explican el peligro
DELETREAN la llamada ofensora: sobre bytes crudos señalaba los dos ficheros que la documentan como los dos
que la cometen.

`itsAcceptFormatCannotBeSpelledAsNull` se **repunta** en vez de borrarse: sin un testigo de la ausencia, nada
impide reintroducir el parámetro dejando atrás la guarda. Es el único punto en el que la consulta externa se
descartó, y se descartó con razón.

**Deriva encontrada de paso, y corregida.** El docblock de `StrictRequestPayloadTest` afirmaba «the coupling
suppression is measured at 18». Medición real quitando la supresión y corriendo PHPMD: **22 en la base, 20 con
este cambio**. También decía «thirteen of the twenty imports are used exactly once»; el conteo real es **10 de
22 referenciadas por ningún código fuera de `mapPayload()`** (contado excluyendo prosa, que si no la propia
documentación cuenta como uso).

## D2 · Dos unidades neutras, y `read()` se queda local en las cinco

`RepositoryRoot` resuelve la raíz; `TypeScriptSource` quita comentarios. `read()` **no** se extrae: medido, los
cinco diagnósticos difieren y esa diferencia es la parte con valor.

**El probe de raíz NO es `.git`.** Medido en este árbol: `.git` es un **directorio** en el checkout primario y
un **fichero regular** en cada worktree enlazado. CLAUDE.md exige que el trabajo de feature ocurra en un
worktree, así que ese probe resolvería donde nadie trabaja y fallaría exactamente donde se trabaja. Los
marcadores son ficheros de raíz (`compose.yaml`, `Makefile`, `CLAUDE.md`), y `RepositoryRootTest` refuse `.git`
**por nombre**: en este checkout es un fichero, así que la comprobación de tipo por sí sola lo dejaría pasar.

**El probe anterior (`is_dir($candidate . '/pwa/src')`) era un proxy imperfecto y además redundante.**
Proxy: `ProjectContextVersionGateTest` lee `docs/project-context.md` y los manifiestos — entre ellos
`pwa/package.json`, al que **quince** líneas de `api/.project-context-versions` vinculan un claim (medido) —
pero nunca `pwa/src`, así que comprobaba la presencia de algo adyacente a lo que necesita. Redundante: los
cuatro gates que sí leen `pwa/src` hacen `assertFileExists` en su propio `read()`. Por eso `path()` devuelve
`?string` — la resolución se comparte, el diagnóstico se queda con quien sabe qué le falta, y un mensaje
compartido sólo puede ser correcto para los llamantes para los que se escribió.

**El stripper es UN SOLO recorrido, y esa es la decisión entera.** Un validador que responde «¿corrompería
esto el strip?» aparte del strip mismo son dos lecturas de un texto, y dos lecturas discrepan. Medido, con la
clase real corriendo en el contenedor: un `/*` **dentro** de un `//` es invisible para un escáner que se salta
los comentarios de línea enteros, mientras que el `preg_replace` de bloque sí lo ve y se come hasta el
siguiente `*/` — con la entrada `// see /* here\nconst KEEP = 1;\n/* real */\nconst B = 2;\n` la salida era
`\nconst B = 2;\n`: **`const KEEP = 1;` desaparecía en silencio, aceptado, sin excepción**. Eso es exactamente
la corrupción que esta unidad existe para acabar. Ahora la eliminación ocurre en el mismo recorrido que sigue
los literales, así que no pueden tener opiniones distintas sobre dónde empieza un comentario.

**Lo que el recorrido no modela, lo RECHAZA.** No «lo parsea mejor»: un lexer de TypeScript escrito a mano es
más difícil de acertar que lo que sustituye, y uno sutilmente equivocado falla en silencio. Tres rechazos,
cada uno cerrando una corrupción medida:

- Un literal `'` o `"` que llega al fin de su línea sin cerrar. JavaScript no los continúa a través de un salto
  de línea sin escape, así que uno abierto significa que el recorrido perdió el sitio. **La forma viva es un
  apóstrofo en texto JSX** — `RedactedValue.tsx` es JSX y el repo obliga a copy en inglés, cuyas contracciones
  lo llevan. Medido: `<p>Don't</p>\nconst URL = 'https://x';` se aceptaba y salía como `const URL = 'https:`.
- Un template literal o un comentario de bloque abiertos al fin de fichero.
- Un `/` en posición donde un literal de regex sería legal. No se modelan en absoluto, y medido `/\/\//` se
  leía como un comentario de línea.

Medido sobre los cinco corpus reales: **los cinco aceptados, cero rechazos**. Cada apóstrofo que contienen está
dentro de un comentario de bloque, que el recorrido consume entero — el corpus está verde por el diseño y no a
pesar de él. Y `'https://x'` pasa de rechazo (falso positivo del diseño anterior) a conservarse intacto.

Queda dicho lo que no cubre: `RedactionVocabularyParityTest` es el quinto gate y lee TypeScript **sin** pasar
por aquí — compara `IDENTITY_AXES` sobre el fichero crudo — así que el strip es portante en los tres que lo
usan, no en los cinco.

## D3 · El contrato débil de `deleteAllForUser()`, declarado

El borrado es sobre **estado persistido**; la lectura-tras-borrado dentro de una misma unidad de trabajo es
indefinida. El adaptador Doctrine hace `$em->find()`, que consulta el identity map, y un `DELETE` masivo de DQL
no lo desaloja.

**Medido: una aserción dependía de la evicción del doble en el camino de `deleteAllForUser`** —
`RequestPasswordResetTest:140`. Se retira, con un comentario que dice por qué no se afirma. La de `:148` corre
en la dirección segura y cualquier implementación la satisface; el **ORDEN**, que es la garantía real, se
afirma aparte a través del hook `onSave` del store.

**Y había una segunda, que el primer barrido no vio.** `PruneExpiredPasswordResetTokensCommandTest:45` hacía
lo mismo sobre `deleteExpired()`, que en el adaptador es **el mismo `DELETE` masivo de DQL** con la misma
propiedad — prueba de que es real: el test funcional necesita `$this->entityManager->clear()` antes de leer.
El aviso que se escribe en el doble es general, así que su propio árbol lo violaba. `deleteExpired()` recibe
la misma declaración y esa aserción se retira: con dos filas sembradas, el conteo reportado más la
supervivencia de la viva ya dicen cuál se fue.

Ni se relaja el doble ni se toca el adaptador: prometer lectura-tras-borrado obligaría a cada futuro adaptador
Doctrine a gimnasia con el identity map por una garantía que un `DELETE` masivo no da por diseño.

El doble lleva ahora escrito que su evicción es **más fuerte** que lo que el puerto promete y que nadie puede
construir un testigo sobre ella — sin eso el siguiente test recrea este mismo agujero. Y lo que el puerto **sí**
promete gana su caso positivo: `InMemoryPasswordResetTokenRepositoryContractTest` pincha el conteo devuelto y
que el borrado no alcanza filas de otro usuario, que es lo que la erasure necesita.

## D4 · `ResolvesContainerServices`, y las once migradas

Medido sobre la base: **once cuerpos byte-idénticos** en seis contextos acotados. (El md5 depende de la
ventana de bytes que se hashee, así que la cifra en sí no es re-medible sin decirla; lo re-medible es la
propiedad: once iguales entre sí, el duodécimo distinto.)
Migradas las once, no un subconjunto: una migración parcial deja a un lector sin saber si las copias que
quedan son deliberadas o pendientes.

La duodécima, `api/tests/Behat/Support/Messenger/MessengerTransports.php:105`, se queda fuera **por
semántica y no por alcance**: su cuerpo difiere, devuelve `?object` y no asserta nada. Unificarla
metería dos contratos en uno.

Casa: `api/tests/Functional/`, junto a los tres traits que ya viven ahí. Lleva
`@phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase`, que esos tres no llevan.

## Verificación

Ejecución fresca sobre el árbol final —el rebasado sobre `202767ab`, no el de antes del rebase—, código de
salida impreso:

| Gate | Exit | Medido |
|---|---:|---|
| `make php.stan` | 0 | sin errores |
| `make php.quality` | 0 | 0 violaciones PHPMD |
| `make php.quality.dry-run` | 0 | la variante que corre CI |
| `make php.unit` | 0 | 3418 tests, 17746 aserciones, 2 skipped |
| `make php.behat` | 0 | 472 escenarios, 4378 pasos |
| `make php.lint.gate-placement` | 0 | con las **dos** líneas nuevas del registro |

El total de tests **no se compara contra una cifra base**, porque esa cifra no se midió sobre un árbol limpio y
un número no verificado en un documento cuyo propósito es medir es el defecto que este documento denuncia. Lo
que sí es derivable del diff: `RepositoryRootTest` +2, `TypeScriptSourceTest` +2 métodos con 4 y 10 casos de
provider, `InMemoryPasswordResetTokenRepositoryContractTest` +3, `StrictRequestPayloadTest` −3 casos de
provider y +1 método, `PruneExpiredPasswordResetTokensCommandTest` sin cambio de métodos.

`php.quality` y `php.quality.dry-run` **no son intercambiables**, y esta rama volvió a medirlo: el modo apply
pasaba mientras `php.cs.dry-run` daba `Error 2` por cuatro líneas de más de 120 caracteres en los mensajes
generados. Se reflujeron; no se suprimió nada.

### Matriz de mutación — cada falsador provocado en rojo por separado

Restauración siempre por **copia de bytes** desde una copia pristina, nunca `git checkout --`:

| Mutación | Enrojece |
|---|---|
| D1-F1 · el parámetro `$acceptFormat` vuelve al constructor | `noCallSiteCanNameTheFormatItAccepts` (sola) |
| D1-F2 · `ACCEPTED_FORMATS` se vacía | `itRefusesAFormEncodedBodyWithoutBeingAskedTo` (la constante es portante) |
| D1-F3 · un call site pasa a posicional | `nothingConstructsItPositionally` (sola) |
| D2-F1 · `MARKERS = ['.git']` | `everyMarkerItDeclaresIsAFileAndNotADirectory` |
| D2-F2 · ningún marcador resuelve | `RepositoryRootTest` **y los cinco gates** |
| D2-F3 · se quita el candidato `/repo` | los cinco gates (el candidato del contenedor está vivo) |
| D2-F4 · vuelta a los dos `preg_replace` independientes | **7 casos**, entre ellos el de la regresión medida: `const KEEP = 1;` desaparece |
| D2-F5 · un literal de línea puede pasar de su línea | el caso del apóstrofo JSX (sola) |
| D2-F6 · los literales de regex dejan de rechazarse | el caso del literal de regex (sola) |
| D3-F1 · el conteo se topa en 1 | `testItCountsEveryRowItRemovedForThatUser` |
| D3-F2 · se cae el predicado de alcance | los dos casos de aislamiento por usuario |
| D4-F1 · el trait no resuelve nada | **las once**, 22 casos — el trait está vivo en todas |

**Lo que un verde aquí NO prueba.**

1. El recorrido de `TypeScriptSource` no modela literales de regex ni texto JSX; lo que hace es **rechazarlos**,
   así que un fichero que crezca uno de los dos enrojece con número de línea en vez de corromperse. Es un
   rechazo, no una comprensión: la clase no sabe leer esos constructos, sólo sabe que no sabe.
2. `RedactionVocabularyParityTest` no usa el strip. El strip es portante en tres gates, no en cinco.
3. `RepositoryRootTest` no puede ejercitar la resolución contra un árbol sintético: los candidatos salen de
   `__DIR__` y no hay costura. Medido en el contenedor, el discriminante entre los dos candidatos es
   `compose.dev.yaml` — `api/composer.json` y `docs/` existen bajo ambos, así que asertar sobre ellos habría
   pasado con la raíz equivocada resuelta.
4. D3 declara el contrato y lo pincha en el **doble**. Que el adaptador tampoco prometa lectura-tras-borrado se
   sostiene por lectura de `find()` más el `clear()` que su test funcional necesita, no por una medición propia.
5. `nothingConstructsItPositionally` es un barrido de texto sobre `src` y `tests`: no ve una construcción
   alcanzada por un class-string calculado, ni una fuera de esos dos árboles.
6. D4 es equivalencia: `php.unit` y `php.behat` verdes prueban que no cambió comportamiento y la mutación
   prueba que el trait se alcanza; ninguna dice que `assertInstanceOf` sea el diagnóstico correcto para un id
   que no es class-string — eso lo empuja `@param class-string<T>` al call site.
7. `pwa.test.e2e` no se ejecutó: esta rama no toca `pwa/`.

## Adversarial pass

Lectura hostil en contexto fresco e independiente sobre el diff completo, **antes de commitear y antes de
abrir la PR**. **Cambió el resultado, no lo confirmó**: 2 GRAVE, 6 SERIO y 8 MENOR. Los dos GRAVE los midió
ejecutando la clase nueva dentro del contenedor, y ambos se reprodujeron aquí antes de tocar nada.

**GRAVE · G-1 — el strip BORRABA código y lo daba por bueno.** Un `/*` dentro de un `//` es invisible para un
escáner que se salta los comentarios de línea enteros, y visible para el `preg_replace` de bloque, que se come
hasta el siguiente `*/`. Medido: `// see /* here\nconst KEEP = 1;\n/* real */\nconst B = 2;\n` salía como
`\nconst B = 2;\n`, **aceptado, sin excepción**. La causa raíz es que el validador y el strip eran dos lecturas
del mismo texto. **Corregido fusionándolos en un solo recorrido**, y pinchado como regresión: volver a los dos
`preg_replace` enrojece siete casos, uno de ellos exactamente esta entrada.

**GRAVE · G-2 — desincronización que se re-sincroniza, sin que la comprobación de fin de fichero la vea.** Un
apóstrofo en texto JSX (`<p>Don't</p>`) abre un literal que no existe; la comilla del `'https://x'` siguiente
lo cierra; a partir de ahí el `//` de la URL se lee como comentario. Medido: salía `const URL = 'https:`. El
docblock atribuía toda la desincronización a los literales de regex y decía que la comprobación de EOF la
atrapaba — falso en este caso y también para `/\/\//`, medido igual. **Corregido** acotando los literales `'` y
`"` a su línea (JavaScript no los continúa sin escape) y rechazando el `/` en posición de regex. Ambos
falsados.

**SERIO · S-1 — docblock duplicado**, el rancio primero, en `EnumWireContractGateTest`: mi barrido de limpieza
no contemplaba esa variante del texto. Y `php-cs-fixer --dry-run` sobre ese fichero da exit 0, así que ningún
gate lo veía. **Borrado**, y comprobado que los cinco quedan con uno.

**SERIO · S-2 — afirmación falsa mía**: dije que `ProjectContextVersionGateTest` no lee «ni un byte» de `pwa/`.
Lo lee: quince líneas de `api/.project-context-versions` vinculan claims a `pwa/package.json`. El probe viejo
era un **proxy imperfecto** (`pwa/src` no es lo que ese gate necesita), no una mentira. **Corregido** aquí y en
el docblock de `RepositoryRoot`.

**SERIO · S-3 y S-4 — dos cifras mías que no cuadraban**: el delta de tests («base: 3386» daba una aritmética
imposible) y «tres líneas nuevas del registro» cuando `git diff --stat` da dos. **Corregidas**: la primera
retirando una cifra que nunca se midió, la segunda con el número real.

**SERIO · S-5 — la aserción retirada no era la única.**
`PruneExpiredPasswordResetTokensCommandTest:45` hacía exactamente lo que el aviso que yo acababa de escribir en
el doble prohíbe, sobre `deleteExpired()`, que es el mismo `DELETE` masivo y no había recibido declaración
alguna. **Corregido en las dos mitades.**

**SERIO · S-6 — «cinco copias de un `preg_replace`»**: eran **tres** (las cinco eran las de `repoRoot()`).
**Corregido.**

**MENORES atendidos**: el discriminante de `RepositoryRootTest` existía bajo los dos candidatos y por tanto no
discriminaba (pasa a `compose.dev.yaml`, medido); el coste posicional de D1 quedaba sólo en prosa y ahora tiene
barrido propio, que además lee a través de `PhpSource::withoutComments()` porque sobre bytes crudos señalaba a
los dos ficheros que documentan el peligro; el md5 de D4 no era re-medible sin decir su ventana, así que se
enuncia la propiedad en vez de la cifra; dos comentarios relativos al cambio borrados; y el docblock del
escáner ya no sobre-declara su cobertura ni describe mal el offset que devuelve.

**MENOR registrado y NO arreglado, con su motivo**: `DoctrinePasswordResetTokenRepository:76` hace
`return \is_int($affected) ? $affected : 0;` sobre un conteo que D3 acaba de elevar a «la única promesa», y ese
consumidor es evidencia GDPR. `Query::execute()` de un DELETE DQL devuelve `int`, así que la rama es
inalcanzable — es una incoherencia de forma, no un defecto vivo, y arreglarla dentro de esta rama mezclaría un
cambio de comportamiento del adaptador con un trabajo que es de contrato y de test.

**Lo que el pase NO pudo verificar**: no ejecutó ninguna de las puertas de la tabla (los exit 0 son de esta
sesión), no reprodujo la matriz de mutación, y escaneó cuatro de los cinco corpus con heurísticos en vez de con
la propia clase. También creó por accidente un `api/.php-cs-fixer.dist.php` root-owned al invocar el fixer sin
`--config`; se ha borrado y no entra en el commit.
