import type { Filter } from "@/context/shared/domain/Search";
import type {
  CrudRepository,
  ResourceSearchCriteria,
  ResourceSearchPage,
} from "../domain/CrudRepository";
import { SortDirection } from "@/context/shared/domain/types/sorting";
import { encodeCursorLink } from "./cursorLink";

/** Per-entity hooks the generic base needs (the only entity-specific logic). */
export interface InMemoryEntityConfig<T extends { id: string }, TInput> {
  /** True if `row` satisfies one filter. Called once per active filter (AND). */
  matchesFilter: (row: T, filter: Filter) => boolean;
  /** Comparator factory for a sort field (ascending order). */
  compare: (field: string) => (a: T, b: T) => number;
  /** Build a new entity from input (id + timestamps supplied). */
  fromInput: (input: TInput, id: string, nowIso: string) => T;
  /** Apply input to an existing entity (preserve id/createdAt; bump updatedAt outside). */
  applyInput: (existing: T, input: TInput, nowIso: string) => T;
}

/**
 * Generic in-memory CRUD adapter. Holds rows in memory, applies filter→sort→limit,
 * and emits the cursor-only PageEnvelope with opaque offset-encoded links. This is
 * the mock that fills the real domain port; an Api*Repository later replaces it
 * with zero consumer change.
 */
export class InMemoryCrudRepository<T extends { id: string }, TInput> implements CrudRepository<
  T,
  TInput
> {
  constructor(
    // `protected` so an entity repository can enforce an aggregate invariant
    // (e.g. a unique key) over the live rows before delegating to super.
    protected rows: T[],
    private readonly config: InMemoryEntityConfig<T, TInput>,
    private readonly nextId: () => string,
    private readonly nowIso: () => string,
    /** Simulated latency (ms) so loading states are visible; default 0 in tests. */
    private readonly latencyMs = 0,
  ) {}

  async search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<T>> {
    await this.delay();
    return this.slice(criteria, 0);
  }

  /** Used by the navigator to continue from an opaque link. */
  async searchAt(criteria: ResourceSearchCriteria, offset: number): Promise<ResourceSearchPage<T>> {
    await this.delay();
    return this.slice(criteria, offset);
  }

  async find(id: string): Promise<T> {
    await this.delay();
    const row = this.rows.find((r) => r.id === id);
    if (!row) throw new Error(`Not found: ${id}`);
    // Hand back a copy: the store owns its rows, and a real HTTP repository
    // returns freshly deserialized objects every call. A consumer mutating the
    // result must never reach back into the in-memory source of truth.
    return structuredClone(row);
  }

  async create(input: TInput): Promise<T> {
    await this.delay();
    const row = this.config.fromInput(input, this.nextId(), this.nowIso());
    this.rows = [row, ...this.rows];
    return structuredClone(row);
  }

  async update(id: string, input: TInput): Promise<T> {
    await this.delay();
    const index = this.rows.findIndex((r) => r.id === id);
    if (index === -1) throw new Error(`Not found: ${id}`);
    const updated = this.config.applyInput(this.rows[index], input, this.nowIso());
    this.rows = this.rows.map((r, i) => (i === index ? updated : r));
    return structuredClone(updated);
  }

  async delete(id: string): Promise<void> {
    await this.delay();
    const exists = this.rows.some((r) => r.id === id);
    if (!exists) throw new Error(`Not found: ${id}`);
    this.rows = this.rows.filter((r) => r.id !== id);
  }

  private slice(criteria: ResourceSearchCriteria, offset: number): ResourceSearchPage<T> {
    const filtered = this.rows.filter((row) =>
      criteria.filters.every((f) => this.config.matchesFilter(row, f)),
    );
    const sorted = this.sortRows(filtered, criteria);
    const limit = Math.max(1, criteria.limit);
    // Clamp a cursor that points past the end (rows deleted under it) back to the
    // start of the last page that still has items, so a follow lands on real rows
    // in one hop instead of an empty tail page. A real keyset API resolves this
    // server-side; the mock mirrors that contract.
    const lastPageOffset =
      sorted.length === 0 ? 0 : Math.floor((sorted.length - 1) / limit) * limit;
    const safeOffset = Math.min(offset, lastPageOffset);
    const items = sorted.slice(safeOffset, safeOffset + limit).map((row) => structuredClone(row));
    const hasNext = safeOffset + limit < sorted.length;
    const hasPrev = safeOffset > 0;
    return {
      items,
      hasNext,
      hasPrev,
      // LIGHT pagination: no total is computed or shown.
      count: null,
      // Links carry the full query plus the target offset, so a follow needs only
      // the link — the navigator stays stateless.
      links: {
        next: hasNext ? encodeCursorLink(criteria, safeOffset + limit) : null,
        prev: hasPrev ? encodeCursorLink(criteria, Math.max(0, safeOffset - limit)) : null,
      },
    };
  }

  private sortRows(rows: T[], criteria: ResourceSearchCriteria): T[] {
    if (!criteria.sort) return rows;
    const cmp = this.config.compare(criteria.sort.field);
    const dir = criteria.sort.direction === SortDirection.DESC ? -1 : 1;
    return [...rows].sort((a, b) => cmp(a, b) * dir);
  }

  private async delay(): Promise<void> {
    if (this.latencyMs > 0) await new Promise((res) => setTimeout(res, this.latencyMs));
  }
}
