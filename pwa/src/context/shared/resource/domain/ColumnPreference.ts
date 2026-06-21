const COLUMNS_SEPARATOR = ",";

/**
 * CSV (de)serialization + validation for a list's visible-column set, persisted
 * via `useStoredPreference` (string-only storage). Entity-agnostic: callers pass
 * their column-key catalog and pinned (always-visible) subset.
 */
export interface ColumnPreference<K extends string> {
  /** Serialize the visible set to a CSV string. */
  serialize(keys: readonly K[]): string;
  /** Parse the CSV back to keys; pinned columns are always present. */
  parse(raw: string): K[];
  /** A stored CSV value is valid when every token is a known column key. */
  isStoredValue(v: string): v is string;
}

export function createColumnPreference<K extends string>(opts: {
  allKeys: readonly K[];
  pinned: readonly K[];
}): ColumnPreference<K> {
  const { allKeys, pinned } = opts;
  const isKey = (p: string): p is K => (allKeys as readonly string[]).includes(p);

  return {
    serialize: (keys) => keys.join(COLUMNS_SEPARATOR),
    parse: (raw) => {
      const parts = raw.split(COLUMNS_SEPARATOR).filter(isKey);
      const missingPinned = pinned.filter((k) => !parts.includes(k));
      return [...missingPinned, ...parts];
    },
    isStoredValue: (v): v is string => v.length > 0 && v.split(COLUMNS_SEPARATOR).every(isKey),
  };
}
