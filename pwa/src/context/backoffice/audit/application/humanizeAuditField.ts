/**
 * Humanises an audit diff `field` key into a readable Spanish label. The raw key is forensic evidence
 * and is rendered verbatim **alongside** this label by the diff UI — this function only produces the
 * friendlier line and must never replace the key.
 *
 * Two tiers (mirror of {@link humanizeAuditAction}): a curated dictionary for the live, audited fields,
 * and a deterministic title-case fallback (camelCase / snake_case → spaced words) for everything else,
 * so an unmapped or future field still reads cleanly without a code change.
 */
const CURATED_LABELS: Readonly<Record<string, string>> = {
  name: "Nombre",
  shortName: "Código",
};

export function humanizeAuditField(field: string): string {
  const curated = CURATED_LABELS[field];
  if (curated) return curated;
  return titleCaseField(field);
}

/** `shortName` → `Short Name`, `legal_name` → `Legal Name`; splits camelCase and snake_case. */
function titleCaseField(field: string): string {
  return field
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .split(/[\s_]+/)
    .filter((word) => word.length > 0)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(" ");
}
