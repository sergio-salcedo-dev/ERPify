/**
 * Derive up to two uppercase initials from a display name, for monogram
 * avatars. Multi-word names use the first letter of the first two words
 * ("Santander Bank" → "SB"); single-word names use the first two letters
 * ("BBVA" → "BB"). Returns "" for empty / whitespace-only input so callers
 * can decide on a fallback.
 */
export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "";
  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }
  return (words[0][0] + words[1][0]).toUpperCase();
}
