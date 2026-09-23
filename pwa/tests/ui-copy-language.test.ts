import { readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

/**
 * The rendered surface speaks the language the root layout declares.
 *
 * `app/layout.tsx` fixes `lang="en"`, and no i18n module exists yet (it is roadmap module
 * 0.6), so English is not a preference here — it is the only locale the document claims.
 * A Spanish string on that surface is therefore a defect, not a placeholder: it is announced
 * to a screen reader under an `en` language context, which is what makes this an
 * accessibility bug and not a matter of taste.
 *
 * The gate exists because a sweep of this defect closed on the CHROME only — a page title
 * here, a metadata description there — while three documentation pages and the whole audit
 * surface stayed Spanish end to end, ~280 lines of it, with nothing red because nothing was
 * looking. A prose rule had already been written and had already drifted.
 *
 * **Three signals admit a string** ({@link spanishReason}), checked in this order:
 *
 * 1. **One Spanish content word** from {@link CONTENT_WORDS}. A single hit is enough, and this
 *    is the only signal that sees a short label — which is most of a UI.
 * 2. **A Spanish diacritic plus at least {@link DIACRITIC_MIN_WORDS} function word** from
 *    {@link FUNCTION_WORDS}.
 * 3. **{@link WORDS_ONLY_MIN} distinct function words**, no diacritic needed.
 *
 * Every parameter is a measurement, not a preference:
 *
 * - Diacritic ALONE is not enough. English copy in this tree legitimately quotes accented
 *   examples — `BankForm` explains that `"GLÉ"` is stored as `"GLE"` — and flagging it would
 *   put an exemption on a line that is already correct.
 * - The function-word list carries no single letters. `y`, `o` and `e` are Spanish words, and
 *   they are also Tailwind's `space-y-4`: measured, including them reported 17 `className`
 *   values and the `es-ES` locale, and not one real string.
 * - The words-only threshold is TWO, not three. At three, `"Progreso global de la obra"` — a
 *   realistic heading carrying no diacritic and exactly two function words — passed while
 *   being plainly Spanish; at two it is caught and `src/` still reports ZERO findings, so the
 *   threshold costs no exemption anywhere in the tree.
 * - Every word list is matched against the raw text between `\p{L}` boundaries, case-folded
 *   but NOT diacritic-folded: `página` and `pagina` are different words to it, so a list entry
 *   catches exactly the spellings it names.
 *
 * **What is read.** The tree is walked with the TypeScript compiler's AST rather than a regex,
 * so a Spanish word inside a COMMENT never competes with a rendered one — a comment is trivia
 * attached to a token, not a node the walk visits. That matters in both directions here: the
 * domain this product serves is Spanish construction, so `auditoría` and `jornada` appear in
 * prose explaining the model, and those are documentation rather than UI. The walk reads
 * string literals, no-substitution template literals, JSX text, and template literals WITH
 * substitutions, whose static parts are joined across the `${…}` holes ({@link staticTextOf})
 * so an interpolation cannot cut a Spanish sentence into pieces that each fall under every
 * threshold.
 *
 * **What is skipped** is decided by ATTRIBUTE, never by pattern: the whole value of a JSX
 * attribute named in {@link NON_COPY_ATTRIBUTES} — style hooks (`className`, `class`,
 * `variant`, `size`), QA addresses (`data-testid`, `testId`, `testIdPrefix`), URLs (`href`,
 * `src`) and machine identifiers (`id`, `key`, `name`, `type`). None of those is rendered
 * copy, and a class list read as a sentence is where the single-letter false positives came
 * from.
 *
 * **Blind spots, stated rather than implied.** A green proves that no string this walk reads
 * trips one of the three signals. It proves nothing about a string built by `+`
 * concatenation from parts that are individually clean; nothing about copy that arrives from
 * the API at runtime; nothing about text passed through an attribute on the skip list (a
 * Spanish `name` is never read); nothing about Spanish written only in words no list here
 * carries. The general shape of that last escape: a string whose only Spanish is one short
 * function word (`o`, `en` — a single letter is excluded for Tailwind's sake, and one listed
 * function word with no diacritic is under every threshold) or a word that is also English
 * (`tabla`, deliberately absent from the lexicon), carrying no diacritic, clears every signal.
 * `"Blue/green o rolling deploy"`, `"Extension hooks en domain events"`,
 * `"quality + tests en push/PR"` and `"tabla domain_event"` are strings of that shape. And a
 * green proves nothing about whether the English that replaced a string is any GOOD. Review
 * remains the only control on those directions.
 *
 * Text is normalised to NFC before it is matched, so an accent typed as a combining mark
 * (`i` + U+0301) reads as the precomposed letter the diacritic class and the accented entries
 * spell.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx"]);

/** Spanish diacritics and the inverted marks — none of them occur in ordinary English copy. */
const DIACRITIC = /[áéíóúñ¿¡ÁÉÍÓÚÑ]/;

/**
 * Spanish function words, all of them two letters or more. Single letters (`y`, `o`, `e`) are
 * excluded because they collide with Tailwind utility fragments; see the header.
 *
 * `ningún` and `aún` are listed beside their unaccented spellings because the match is not
 * diacritic-folded. Both unaccented forms stay on purpose: `aun` is correct Spanish in its own
 * right ("aun así", "even so"), and `ningun` is how `ningún` arrives from a keyboard with no
 * Spanish layout, as `módulos` does in `"El plan por fases y modulos"`.
 */
const FUNCTION_WORDS = [
  "que",
  "de",
  "la",
  "el",
  "los",
  "las",
  "para",
  "con",
  "por",
  "sin",
  "una",
  "un",
  "se",
  "del",
  "al",
  "es",
  "su",
  "sus",
  "como",
  "desde",
  "cada",
  "todo",
  "toda",
  "todos",
  "todas",
  "entre",
  "hasta",
  "cuando",
  "donde",
  "mi",
  "mis",
  "ya",
  "lo",
  "no",
  "sobre",
  "ningun",
  "ningún",
  "ninguna",
  "aun",
  "aún",
  "este",
  "esta",
  "estos",
  "estas",
];
const FUNCTION_WORD_RE = new RegExp(
  `(?<![\\p{L}])(?:${FUNCTION_WORDS.join("|")})(?![\\p{L}])`,
  "giu",
);

/**
 * Spanish CONTENT words — the signal that sees a short label.
 *
 * The two function-word signals are both about *sentences*: they need a diacritic or several
 * function words, so they see prose and are blind to the short label. That blindness is
 * measured, not theoretical — an independent review found eight Spanish strings the
 * function-word signals reported green over, every one of them too short to carry either
 * signal: `"Todo"`, `"Cambios"`, `"Cualquiera"`, `"Anterior"`, `"Siguiente"`, `"Sin metadata"`,
 * `"Ordenar por hora"`, and `"Cambios"` again as a section title. A UI is mostly short labels,
 * so that was most of the surface.
 *
 * Membership rule, and it is what keeps the list honest: a word belongs here only if it is
 * **not** also English and **not** a Tailwind fragment. That is why `media`, `total`,
 * `actor`, `metadata`, `final`, `global`, `error`, `material`, `local`, `real`, `tabla` and
 * `no` are deliberately absent despite being Spanish — each is also English (or occurs in
 * correct English copy in this tree), and a gate that reports those trains people to ignore it.
 *
 * `sin` is the one function word also listed here, for a measured reason: `"Sin metadata"`
 * pairs a Spanish preposition with an English noun, so it carries one function word, no
 * diacritic and no other content word, and escapes the other two signals. Listing it reports
 * zero additional findings across `src/`, so it costs no exemption. `con` is NOT listed:
 * English copy says "pros and cons".
 *
 * Accented words are listed accented, because the match is not diacritic-folded: an entry
 * spelled `pagina` can never match `página`. Where the unaccented spelling is kept beside the
 * accented one, it is for the keyboard that cannot type the accent, not as a substitute.
 *
 * This list is a floor on accidents, never a ceiling on Spanish: a word not in it still
 * escapes all three signals. Each entry is derived from a real miss or from the vocabulary
 * this product's own domain uses, and the right response to the next miss is another entry,
 * not a wider heuristic.
 */
const CONTENT_WORDS = [
  "todo",
  "todos",
  "toda",
  "todas",
  "cambio",
  "cambios",
  "cualquiera",
  "cualquier",
  "anterior",
  "siguiente",
  "ordenar",
  "buscar",
  "guardar",
  "cancelar",
  "eliminar",
  "borrar",
  "cerrar",
  "abrir",
  "copiar",
  "seguir",
  "mostrar",
  "ocultar",
  "enviar",
  "anadir",
  "añadir",
  "crear",
  "editar",
  "filtro",
  "filtros",
  "pagina",
  "página",
  "accion",
  "acción",
  "acciones",
  "recurso",
  "recursos",
  "nivel",
  "jornada",
  "auditoria",
  "auditoría",
  "fecha",
  "usuario",
  "usuarios",
  "nombre",
  "estado",
  "contrasena",
  "contraseña",
  "correo",
  "ninguno",
  "ninguna",
  "vacio",
  "vacío",
  "vacia",
  "vacía",
  "cifrado",
  "booleano",
  "pendiente",
  "pendientes",
  "hecho",
  "obra",
  // `"Mapa del flujo:"` and `"Ir al paso"` carry one function word each (`del`, `al`) against a
  // threshold of two, so reading their template literals finds nothing without these nouns.
  "mapa",
  "flujo",
  "paso",
  // `"Entre bastidores:"` is one function word against a threshold of two plus an unknown noun; a
  // hand sweep found it, not a signal — the floor-not-ceiling property, observed rather than argued.
  "bastidores",
  "obras",
  "proveedor",
  "proveedores",
  "cliente",
  "clientes",
  "empresa",
  "empresas",
  "empleado",
  "empleados",
  "factura",
  "facturas",
  "pedido",
  "pedidos",
  "horas",
  "tarea",
  "tareas",
  "proyecto",
  "proyectos",
  "transporte",
  "registro",
  "informes",
  "contratos",
  "pliegos",
  "mediciones",
  "rendimientos",
  "permisos",
  "entidades",
  "patrones",
  "adaptadores",
  "persistencia",
  "lanzar",
  "cobros",
  "pagos",
  "hora",
  "sin",
  "entrada",
  "entradas",
  "anonimizado",
  "identificable",
  // The roadmap's chrome and data: `"Necesidades que cubre"`, `"Prioridad:"`, `"Fase 0"`,
  // `"Fotos geolocalizadas e incidencias"`, `"alimenta 1.4"`, `"evento proposal.accepted"`. Each is
  // either a lone label or pairs one function word with English, so no other signal saw them.
  "necesidades",
  "cubre",
  "prioridad",
  "complejidad",
  "contexto",
  "depende",
  "fase",
  "progreso",
  "alimenta",
  "incidencias",
  "agregado",
  "versionado",
  "tipos",
  "estados",
  "geolocalizadas",
  "fotos",
  "evento",
  "operativo",
  "operaciones",
  "avanzadas",
  "inicialmente",
];
const CONTENT_WORD_RE = new RegExp(
  `(?<![\\p{L}])(?:${CONTENT_WORDS.join("|")})(?![\\p{L}])`,
  "giu",
);

/**
 * JSX attributes whose whole value is skipped: style hooks, QA addresses, URLs and machine
 * identifiers — never rendered copy. See the header for why this is by attribute, not pattern.
 */
const NON_COPY_ATTRIBUTES = new Set([
  "className",
  "class",
  "data-testid",
  "testId",
  "testIdPrefix",
  "href",
  "src",
  "id",
  "key",
  "name",
  "type",
  "variant",
  "size",
]);

const DIACRITIC_MIN_WORDS = 1;
const WORDS_ONLY_MIN = 2;

interface Finding {
  file: string;
  line: number;
  reason: string;
  text: string;
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) return sourceFiles(full);
    return SOURCE_EXTENSIONS.has(path.extname(full)) ? [full] : [];
  });
}

/** Returns why the text reads as Spanish, or `null` when it does not. */
function spanishReason(raw: string): string | null {
  const text = raw.normalize("NFC");
  const content = new Set((text.match(CONTENT_WORD_RE) ?? []).map((word) => word.toLowerCase()));
  if (content.size > 0) {
    return `the Spanish word(s) ${[...content].join(", ")}`;
  }
  const matches = text.match(FUNCTION_WORD_RE) ?? [];
  const distinct = new Set(matches.map((word) => word.toLowerCase()));
  if (DIACRITIC.test(text) && distinct.size >= DIACRITIC_MIN_WORDS) {
    return `a Spanish diacritic plus ${[...distinct].join(", ")}`;
  }
  if (distinct.size >= WORDS_ONLY_MIN) {
    return `the Spanish function words ${[...distinct].join(", ")}`;
  }
  return null;
}

/**
 * The static parts of a template literal that has substitutions, joined by a space per hole.
 *
 * Joined rather than read one chunk at a time, and that is the whole point: `${...}` cuts a sentence
 * into pieces that individually fall under every threshold here. `` `Ir al paso ${n}: ${title}` `` is
 * three chunks carrying one function word between them, so a per-chunk reader reports nothing on copy
 * that is plainly Spanish.
 *
 * A `TemplateExpression` is neither a `StringLiteral` nor a `NoSubstitutionTemplateLiteral`, so a walk
 * that visits only those two consults no signal at all for one — a structural blindness, not a gap in
 * the signals. The expressions inside the holes are ordinary child nodes and are visited on their own.
 */
function staticTextOf(node: ts.TemplateExpression): string {
  return [node.head.text, ...node.templateSpans.map((span) => span.literal.text)].join(" ").trim();
}

function isNonCopyAttribute(node: ts.Node): boolean {
  return (
    ts.isJsxAttribute(node) && ts.isIdentifier(node.name) && NON_COPY_ATTRIBUTES.has(node.name.text)
  );
}

function findingsIn(file: string): Finding[] {
  const source = ts.createSourceFile(
    file,
    readFileSync(file, "utf8"),
    ts.ScriptTarget.Latest,
    true,
  );
  const found: Finding[] = [];

  const visit = (node: ts.Node, underNonCopy: boolean): void => {
    if (!underNonCopy) {
      let text: string | null = null;
      if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) text = node.text;
      else if (ts.isJsxText(node)) text = node.text.trim();
      else if (ts.isTemplateExpression(node)) text = staticTextOf(node);

      if (text) {
        const reason = spanishReason(text);
        if (reason) {
          const { line } = source.getLineAndCharacterOfPosition(node.getStart());
          found.push({
            file: path.relative(PWA_ROOT, file),
            line: line + 1,
            reason,
            text: text.slice(0, 80),
          });
        }
      }
    }
    const skipChildren = underNonCopy || isNonCopyAttribute(node);
    ts.forEachChild(node, (child) => visit(child, skipChildren));
  };

  visit(source, false);
  return found;
}

describe("rendered copy speaks the language the document declares", () => {
  it("carries no Spanish string or JSX text anywhere in src/", () => {
    const findings = sourceFiles(SRC_ROOT).flatMap(findingsIn);

    expect(
      findings.map((f) => `${f.file}:${f.line} reads as Spanish (${f.reason}): ${f.text}`),
    ).toEqual([]);
  });

  it("recognises the three shapes a chrome-only sweep misses", () => {
    // A data string with no diacritic at all — the shape that hid ~280 lines in `_lib` files.
    expect(spanishReason("El plan por fases y modulos, con su estado")).not.toBeNull();
    // Copy carrying a diacritic, the shape a chrome-only sweep does catch.
    expect(spanishReason("Para quién es")).not.toBeNull();
    // An accessible name, which never appears on screen and so is never proof-read.
    expect(spanishReason("Ninguna entrada coincide con estos filtros")).not.toBeNull();
    // Two function words and no diacritic — the shape a words-only threshold of three lets through.
    expect(spanishReason("Progreso global de la obra")).not.toBeNull();
  });

  it("recognises the short labels an independent review found it green over", () => {
    // Each is too short for a diacritic and too short for two function words, so only the
    // content-word signal sees it. Kept verbatim so that signal can never quietly stop covering
    // this shape.
    const missed = [
      "Todo",
      "Cambios",
      "Cualquiera",
      "Anterior",
      "Siguiente",
      "Sin metadata",
      "Ordenar por hora",
      "Recibir y enviar eventos a sistemas externos (webhooks).",
      // Detector fixtures rather than observed copy: neither word has ever been rendered in
      // `pwa/src`. They pin the content-word signal against words of exactly this shape — one
      // word, no diacritic — which is the shape the function-word signals cannot see.
      "entrada",
      "entradas",
      // Audit-surface vocabulary no other signal sees. Pinned here AND listed as lexicon members,
      // so the fixture proves the member is live rather than decorative.
      "anonimizado",
      "identificable",
      // Rendered from a template literal with substitutions — a node type a walk over plain
      // literals never reads. Pinned as the static text they carry so the lexicon half stays live
      // even if reading that node type is ever narrowed.
      "Mapa del flujo:",
      "Ir al paso",
      // Found by hand rather than by any signal: one function word and an unknown noun clear
      // neither threshold. Pinned so its lexicon entry cannot quietly go away.
      "Entre bastidores:",
    ];

    expect(missed.filter((text) => spanishReason(text) === null)).toEqual([]);
  });

  it("reads a template literal that has substitutions, joined across its holes", () => {
    // The joined static text of two templates of this shape. Each is reported only when both
    // halves are live: the node type has to be read AND the words have to be listed.
    expect(spanishReason("Mapa del flujo:")).not.toBeNull();
    expect(spanishReason("Ir al paso :")).not.toBeNull();
    // An English template of the same shape stays silent, which is what keeps reading template
    // literals from reporting every interpolated label in the tree.
    expect(spanishReason("Go to step :")).toBeNull();
  });

  it("matches an accented entry as Spanish actually spells it", () => {
    // The match is not diacritic-folded, so an entry written without its accent never matches
    // the accented word. Each sentence is real Spanish using the word, and the assertion reads
    // the REASON, so it proves that entry matched rather than some other word in the sentence.
    const accented: ReadonlyArray<readonly [string, string]> = [
      ["añadir", "Añadir cuenta bancaria"],
      ["página", "Volver a la página principal"],
      ["acción", "Esta acción no se puede deshacer"],
      ["auditoría", "Registro de auditoría"],
      ["contraseña", "Cambiar contraseña"],
      ["vacío", "El campo está vacío"],
      ["vacía", "La lista está vacía"],
      // Function words: a lone diacritic needs one of them, and neither sentence carries another.
      ["ningún", "Ningún banco"],
      ["aún", "Aún así"],
    ];

    expect(accented.filter(([word, text]) => !(spanishReason(text) ?? "").includes(word))).toEqual(
      [],
    );
  });

  it("recognises roadmap copy that only the lexicon sees", () => {
    // The page's chrome and the data behind it, verbatim, template literals as their joined static
    // text. Each is a lone label or one function word beside English, so only the lexicon sees it.
    const roadmap = [
      "Necesidades que cubre",
      "Prioridad:",
      "Complejidad:",
      "Contexto:",
      "Depende de:",
      "Fase",
      "Progreso fase :",
      "Core ERP operativo",
      "Operaciones avanzadas",
      "F3 · Document System (agregado Document, versionado, tipos, estados)",
      "evento proposal.accepted",
      "Incidents (incidencias)",
      "alimenta 1.4",
      "Fotos geolocalizadas e incidencias",
      "alimenta 1.3 y 2.3",
      "Forecasting engine (rule-based inicialmente)",
    ];

    expect(roadmap.filter((text) => spanishReason(text) === null)).toEqual([]);
    // The English that replaced them stays silent, so these entries cost no exemption.
    expect(
      [
        "Needs it covers",
        "Priority:",
        "Complexity:",
        "Context:",
        "Depends on:",
        "Phase 0",
        "Phase  progress:",
        "Operational ERP core",
        "Advanced operations",
        "F3 · Document System (Document aggregate, versioning, types, states)",
        "proposal.accepted event",
        "Geolocated photos and incidents",
        "feeds 1.3 and 2.3",
        "Forecasting engine (rule-based at first)",
      ].filter((text) => spanishReason(text) !== null),
    ).toEqual([]);
  });

  it("reads a decomposed accent as the precomposed letter", () => {
    // NFD spellings: the accent is a combining U+0301 after the base letter.
    const lexicon = "Registro de auditori\u0301a";
    const diacritic = "Para quie\u0301n es";
    expect(lexicon).not.toBe(lexicon.normalize("NFC"));
    expect(spanishReason(lexicon)).toContain("auditoría");
    expect(spanishReason(diacritic)).toContain("diacritic");
  });

  it("does not claim the English it would otherwise report", () => {
    // Accented EXAMPLES inside English copy: real, and correct, in `BankForm`.
    expect(
      spanishReason('Saved in upper-case ASCII without accents — e.g. "GLÉ" → "GLE".'),
    ).toBeNull();
    // Tailwind utilities, which is why no single-letter function word is listed.
    expect(
      spanishReason("banks-list mx-auto w-full max-w-[90rem] space-y-4 sm:space-y-6"),
    ).toBeNull();
    expect(spanishReason("es-ES")).toBeNull();
    // Ordinary English prose, including words that are also Spanish ("no", "la", "sin").
    expect(spanishReason("No changes recorded")).toBeNull();
  });
});
