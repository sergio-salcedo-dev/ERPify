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
 * The gate exists because the previous sweep of this defect was closed on the CHROME only —
 * a page title here, a metadata description there — while three documentation pages and the
 * whole audit surface stayed Spanish end to end, ~280 lines of it. Nothing went red, because
 * nothing was looking. A prose rule had already been written and had already drifted.
 *
 * **Detection is deliberately conservative, and every widening was measured.** Two signals
 * admit a string: a Spanish diacritic PLUS at least one Spanish function word, or three
 * distinct function words with no diacritic at all. Both halves are load-bearing:
 *
 * - Diacritic ALONE is not enough. English copy in this tree legitimately quotes accented
 *   examples — `BankForm` explains that `"GLÉ"` is stored as `"GLE"` — and flagging it would
 *   put an exemption on a line that is already correct.
 * - The function-word list carries no single letters. `y`, `o` and `e` are Spanish words, and
 *   they are also Tailwind's `space-y-4`: measured, including them reported 17 `className`
 *   values and the `es-ES` locale, and not one real string.
 * - The words-only threshold is TWO, not three, and that was measured rather than chosen.
 *   At three, `"Progreso global de la obra"` — a realistic heading carrying no diacritic and
 *   exactly two function words — passed the gate while being plainly Spanish. Lowering it to
 *   two catches that string and still reports ZERO findings across `src/`, so the tightening
 *   costs no exemption anywhere in the tree.
 * - Style hooks are skipped by ATTRIBUTE, not by pattern (`className`, `data-testid`,
 *   `testId`, `href`, …). A class list is never copy, and the same rule that forbids a
 *   `className` becoming a `data-testid` applies here: it is a style hook, not a sentence.
 *
 * The tree is read with the TypeScript compiler's AST rather than a regex, so a Spanish word
 * inside a COMMENT never competes with a rendered one — a comment is trivia attached to a
 * token, not a node the walk visits. That matters in both directions here: the domain this
 * product serves is Spanish construction, so `auditoría` and `jornada` appear in prose
 * explaining the model, and those are documentation rather than UI.
 *
 * **Blind spots, stated rather than implied.** A green proves that no string literal or JSX
 * text node reachable by this walk reads as Spanish. It proves nothing about a string built
 * by concatenation or interpolation from parts that are individually clean; nothing about
 * copy that arrives from the API at runtime; nothing about a Spanish string short enough to
 * carry neither a diacritic nor three function words (`"Guardar"` alone passes); and nothing
 * about whether the English that replaced it is any GOOD. Review remains the only control on
 * that last direction.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx"]);

/** Spanish diacritics and the inverted marks — none of them occur in ordinary English copy. */
const DIACRITIC = /[áéíóúñ¿¡ÁÉÍÓÚÑ]/;

/**
 * Spanish function words, all of them two letters or more. Single letters (`y`, `o`, `e`) are
 * excluded because they collide with Tailwind utility fragments; see the header.
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
  "ninguna",
  "aun",
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
 * Spanish CONTENT words — the third signal, and the one this gate was missing.
 *
 * The two signals above are both about *sentences*: they need a diacritic or several
 * function words, so they see prose and are blind to the short label. That blindness was
 * not theoretical — an independent review found eight surviving Spanish strings that this
 * gate reported green over, every one of them too short to carry either signal: `"Todo"`,
 * `"Cambios"`, `"Cualquiera"`, `"Anterior"`, `"Siguiente"`, `"Sin metadata"`,
 * `"Ordenar por hora"`, `"Cambios"` again as a section title. A UI is mostly short labels,
 * so that was most of the surface.
 *
 * Membership rule, and it is what keeps the list honest: a word belongs here only if it is
 * **not** also English and **not** a Tailwind fragment. That is why `media`, `total`,
 * `actor`, `metadata`, `final`, `global`, `error`, `material`, `local`, `real` and `no` are
 * deliberately absent despite being Spanish — each of them occurs in correct English copy in
 * this tree, and a gate that reports those trains people to ignore it.
 *
 * `sin` is the one function word promoted into this list, and it was promoted for a
 * measured reason: `"Sin metadata"` pairs a Spanish preposition with an English noun, so it
 * carries one function word, no diacritic, and no content word — it escaped all three
 * signals even after this list existed. Promoting it reports zero additional findings across
 * `src/`, so the tightening costs no exemption. `con` was NOT promoted alongside it: English
 * copy says "pros and cons".
 *
 * This list is a floor on accidents, never a ceiling on Spanish: a word not in it still
 * escapes all three signals. It is derived from a real miss rather than invented, and the
 * right response to the next miss is another entry, not a wider heuristic.
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
  "crear",
  "editar",
  "filtro",
  "filtros",
  "pagina",
  "accion",
  "acciones",
  "recurso",
  "recursos",
  "nivel",
  "jornada",
  "auditoria",
  "fecha",
  "usuario",
  "usuarios",
  "nombre",
  "estado",
  "contrasena",
  "correo",
  "ninguno",
  "ninguna",
  "vacio",
  "vacia",
  "cifrado",
  "booleano",
  "pendiente",
  "pendientes",
  "hecho",
  "obra",
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
];
const CONTENT_WORD_RE = new RegExp(
  `(?<![\\p{L}])(?:${CONTENT_WORDS.join("|")})(?![\\p{L}])`,
  "giu",
);

/** JSX attributes whose value is a machine address or a style hook — never rendered copy. */
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
function spanishReason(text: string): string | null {
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

  it("recognises the three shapes the previous sweep missed", () => {
    // A data string with no diacritic at all — the shape that hid ~280 lines in `_lib` files.
    expect(spanishReason("El plan por fases y modulos, con su estado")).not.toBeNull();
    // Copy carrying a diacritic, the shape a chrome-only sweep does catch.
    expect(spanishReason("Para quién es")).not.toBeNull();
    // An accessible name, which never appears on screen and so is never proof-read.
    expect(spanishReason("Ninguna entrada coincide con estos filtros")).not.toBeNull();
    // Two function words and no diacritic — the shape that survived the first threshold.
    expect(spanishReason("Progreso global de la obra")).not.toBeNull();
  });

  it("recognises the short labels an independent review found it green over", () => {
    // Every one of these shipped in the same PR that added this gate, and every one of them
    // passed it: too short for a diacritic, too short for two function words. They are kept
    // verbatim so the third signal can never quietly stop covering the case that earned it.
    const missed = [
      "Todo",
      "Cambios",
      "Cualquiera",
      "Anterior",
      "Siguiente",
      "Sin metadata",
      "Ordenar por hora",
      "Recibir y enviar eventos a sistemas externos (webhooks).",
    ];

    expect(missed.filter((text) => spanishReason(text) === null)).toEqual([]);
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
