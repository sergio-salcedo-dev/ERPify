/**
 * Reads the total number of banks from the backend projection read model — a
 * single non-negative integer, NOT a `COUNT(*)` and NOT the page envelope
 * `count` (which is null under the list's LIGHT pagination). Kept a separate
 * narrow port from {@link BankRepository} so the widely-mocked search/CRUD
 * contract stays unchanged; the only consumer is the list-header total.
 */
export interface BankCountReader {
  read(): Promise<number>;
}
