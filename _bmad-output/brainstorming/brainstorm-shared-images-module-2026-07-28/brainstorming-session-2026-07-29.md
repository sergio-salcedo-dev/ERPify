---
session_topic: 'Módulo de imágenes en Shared — dónde está la frontera entre imagen pequeña y documento grande, de modo que la llegada del contexto Documents no obligue a rediseñar lo construido.'
session_goal: 'Fijar la frontera arquitectónica AHORA, antes de escribir código, sabiendo que ERPify está orientado a construcción y acumulará un volumen enorme de documentación técnica.'
mode: 'Creative Partner'
techniques_used: ['Morphological Analysis', 'Assumption Reversal', 'TRIZ Contradiction', 'Time Horizon Ladder']
convergence: 'MoSCoW'
facilitator: 'Claude'
participant: 'Sergio'
date: '2026-07-29'
status: 'complete'
durable_output: 'docs/adr/images-vs-documents-conservation-contract.md'
---

# Sesión de brainstorming — módulo de imágenes en Shared

**Registro canónico:** [`.memlog.md`](./.memlog.md) · **Salida durable:** [`../../../docs/adr/images-vs-documents-conservation-contract.md`](../../../docs/adr/images-vs-documents-conservation-contract.md)

## Encuadre

El encargo era el módulo de imágenes compartido — logos de banco, logos de empresa, avatares, imágenes de producto, miniaturas — que comparten un mismo ciclo: se validan, se decodifican, se normalizan, se rehacen, se hashean, se almacenan. La pregunta real debajo era otra: **qué se puede construir hoy sin hipotecar el día que llegue `Documents`** (proyectos, memorias técnicas, planos, DWG, PDFs escaneados, RAW, vídeo, con antivirus, streaming, versionado, metadatos y OCR).

La hipótesis de partida era razonable y resultó ser la equivocada: *imágenes pequeñas → VO simple; documentos grandes → pipeline con streaming desde el día uno*. Estaba partida por **tamaño y formato**.

## Recorrido por técnicas

### 1 · Morphological Analysis — los ejes del problema

Diez ejes independientes de "manejar un binario subido": transferencia, postura ante el byte, confianza del contenido, identidad, backend, ciclo de vida, momento del procesado, derivados, pertenencia, metadatos.

El eje que resultó decisivo fue el segundo (**transformar vs preservar**), y con él una observación que reorganiza el resto: *re-encodear ES la desinfección de una imagen; el antivirus es la factura de haber renunciado a transformar el byte*. El antivirus no es una feature de documentos, es una consecuencia.

**El contraejemplo que rompió la partición por MIME.** La foto de obra: JPEG de 8 MB con GPS, adjunta a una certificación mensual, que acaba sustentando una factura. Por formato es una imagen y el pipeline le hace `Strip metadata`, destruyendo exactamente lo que la hacía prueba. El mismo diseño ponía `gps` y `camera` en los metadatos documentales: dos decisiones correctas, incompatibles sobre el mismo byte.

### 2 · Assumption Reversal — dar la vuelta a lo ya cocido

Supuestos identificados: la fungibilidad es estable y se decide en el upload · el original de una evidencia es inmutable y eterno · el escaneo ocurre después de guardar el original · los derivados son desechables · el módulo compartido puede llevar una regla de negocio.

- **Voltear el primero** produjo la reformulación que sostiene todo lo demás: no clasificamos un JPEG, **el usuario acepta un contrato de conservación**. Y no hay promoción entre contratos, porque el problema de promocionar no es que falte el original sino que *no se puede reconstruir la historia* — promocionar no crea evidencia, crea evidencia desde hoy.
- **Voltear el segundo** rompió "inmutable y eterno": las retenciones vencen, y el día que vencen hay que suprimir lo que se juró no tocar. De ahí el borrado por destrucción de clave, con el digest sobreviviendo a su propio contenido.
- **El tercero se rompió solo:** si el original ya está guardado e inmutable cuando opina el antivirus, el escáner no puede limpiar nada, sólo clasificar. El estado vive en el agregado.
- **El quinto se corrigió en dirección contraria a la propuesta del facilitador:** la regla del módulo no es jurídica ("el original no tiene valor jurídico") sino técnica — *gestiona representaciones visuales canónicas, no preservación de originales*.

### 3 · TRIZ Contradiction — lo irreversible contra lo aplazable

La contradicción: que la primera imagen cueste poco **y** que el port compartido ya sirva para 3 GB dentro de dos años. Construir `Shared/Storage` entero hoy viola el YAGNI del propio repo (un solo consumidor); construir el mínimo garantiza rediseño.

La salida no fue un punto medio sino un criterio: **irreversible es sólo lo persistido o lo que se convierte en contrato público.** Aplicado con rigor, la lista se desploma hasta una única entrada — *el dominio nunca conoce una clave física de almacenamiento* — y arrastra consigo la deduplicación, que sale del MVP por traer demasiado modelo (identidad del blob, ciclo propio, `delete()` no trivial, refcount, GC, RGPD, ownership, concurrencia) a cambio de un ahorro de almacenamiento irrelevante en imágenes pequeñas.

La dedup que sí importa no es de contenido sino **de dominio**: no `Cuenta → Blob(hash)` sino `Bank → Logo → N cuentas`.

### 4 · Time Horizon Ladder — 1, 3 y 10 años

- **Año 1** — el MVP aguanta entero. Lo primero que llega son variantes y cacheo, y ahí el hash canónico gana su primer consumidor real y se vuelve contrato público al entrar en la URL. La decisión se toma cuando hay caso, no antes.
- **Año 3** — nace `Documents`, y aparece un defecto que nadie había nombrado: **el preview de un documento suprimido sobrevive a su propia supresión.** Un derivado de evidencia no es fungible, es dependiente. La supresión criptográfica sería impecable y la fuga entraría por la puerta de al lado.
- **Año 10** — sobreviven los dos contratos, el dominio que ignora el almacenamiento y el módulo como procesador canónico. Cambia el nombre (de imágenes a *renditions*) y cambia el agregado importante: una obra no será `Proyecto.pdf` sino un **expediente**, con el documento como nodo dentro de él, y con un tercer contrato futuro (`CaseFile`/`ProjectRecord`) capaz de congelar, exportar, transferir custodia y aplicar retención única.

## Convergencia (MoSCoW)

| | Alcance |
|---|---|
| **Must** — primera rebanada | `UploadImage` · `UploadedImage` · procesador de imágenes · port `ImageStorage` que no promete nada sobre la persistencia · pipeline determinista · almacenamiento por identificador opaco · `delete(ImageId)` fiable · diseño contra el resolver de Symfony 8.1 desde el principio |
| **Should** — al primer caso real | hash canónico en la URL para cacheo inmutable de variantes · variantes/derivados |
| **Could** — cuando exista `Documents` | `Shared/Storage` stream-first · hermano stream-oriented del `EnvelopeEncryptor` · backends S3/Dropbox · multipart |
| **Won't** — fuera de esta ronda | deduplicación · blob compartido con identidad propia · refcount · GC · antivirus · OCR · versionado · retención · WORM |

## Backlog técnico registrado

1. **Materialización de derivados desde otro bounded context** — `Documents` genera previews y miniaturas que residen en el módulo de imágenes, sin HTTP y sin usuario interactivo, preservando el ownership y evitando la fuga RGPD del preview que sobrevive al borrado de su documento. Es la costura detectada en el peldaño de los tres años y no existe hoy.
2. **Revisión adversarial obligatoria** antes de cerrar cualquier historia que toque privacidad o borrado — hostil, hecha por alguien distinto del autor, y con constancia de dónde quedó registrada. La autocertificación no cuenta.
3. **Encaje con la épica #268** (`Documents`) y sus enablers #266 (`writeStream`) y #267 (backends): siguen siendo válidos, pero heredan una frontera distinta — *fungible vs evidencia*, no *imagen vs no-imagen*. Un JPEG puede pertenecer a `Documents`.
4. **Disparador del renombrado** a *renditions*: la aparición del segundo productor de derivados. Refactor mecánico, sin migración de datos.
5. **Reutilización de `Shared/Crypto`** — `EnvelopeEncryptor`, `Keystore`, `EncryptionScopeId` y `destroyScope()` ya implementan el crypto-shredding; lo que falta es la variante orientada a streams, porque `encrypt(scope, string, aad): string` no puede sellar una subida de varios gigabytes.

## Lo que este ejercicio produjo

Un MVP **más pequeño** que el que entró. Cada técnica terminó descartando infraestructura en vez de justificarla, y todas convergieron en la misma frase:

> `Shared` almacena bytes. Los bounded contexts son los únicos propietarios del significado, de la identidad y del ciclo de vida de esos bytes.

Si esa frase sigue siendo cierta dentro de diez años, todo lo demás — imágenes, renditions, S3, deduplicación, previews o expedientes — es decisión de implementación, no de arquitectura.
