"use client";

import { useEffect, useRef, useState, type KeyboardEvent } from "react";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";

interface UseRowKeyboardNavigationConfig<T extends { id: string }> {
  items: readonly T[];
  selectedIds?: ReadonlySet<string>;
  /** Enables Shift+↑/↓ range selection (Explorer semantics) when provided. */
  onSelectionChange?: (ids: Set<string>) => void;
  onToggleSelect?: (id: string) => void;
  /** Fired when `o` is pressed on a focused row — opens the record peek. */
  onPeek?: (id: string) => void;
}

/**
 * Roving-tabstop keyboard contract for a stacked list of selectable rows,
 * shared by every entity's `<EntityStackedList>`. Each row is a single tab
 * stop; ↑/↓ move focus (Shift extends an Explorer-style selection range), `o`
 * peeks the focused record, Space toggles its selection.
 *
 * Range selection mirrors the DataTable: `anchor` pivots the range, `baseline`
 * snapshots the selection at the first Shift+Arrow press, each press recomputes
 * `baseline ∪ range`. The range resets on any non-shift navigation/toggle and
 * on any external selection change (Clear, checkbox, remote pruning), and is
 * re-armed lazily on the next Shift+Arrow.
 *
 * Returns the focus state, the row-ref array (assign each row element into
 * `rowRefs.current[index]`), and the `onKeyDown(event, index)` handler.
 */
export function useRowKeyboardNavigation<
  T extends { id: string },
  E extends HTMLElement = HTMLAnchorElement,
>(config: UseRowKeyboardNavigationConfig<T>) {
  const { items, selectedIds, onSelectionChange, onToggleSelect, onPeek } = config;
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<E | null>>([]);
  const rangeRef = useRef<{ anchor: number; baseline: ReadonlySet<string> } | null>(null);
  const rangeEmittedRef = useRef<ReadonlySet<string> | null>(null);

  // The cached anchor indexes into `items`: any external mutation of the row
  // slice (pagination, filtering, realtime deletes) invalidates it.
  useEffect(() => {
    rangeRef.current = null;
  }, [items]);

  // An external selection change (bulk-bar Clear, checkbox click, remote
  // pruning) also ends the range; only the set extendRange itself emitted keeps
  // the anchor alive.
  useEffect(() => {
    if (selectedIds !== rangeEmittedRef.current) {
      rangeRef.current = null;
    }
  }, [selectedIds]);

  const extendRange = (fromIndex: number, toIndex: number): void => {
    if (!onSelectionChange) return;
    if (rangeRef.current === null) {
      rangeRef.current = { anchor: fromIndex, baseline: new Set(selectedIds ?? []) };
    }
    const { anchor, baseline } = rangeRef.current;
    const lo = Math.min(anchor, toIndex);
    const hi = Math.max(anchor, toIndex);
    const next = new Set(baseline);
    for (let i = lo; i <= hi; i++) {
      // Guard the ref-captured range against an array that shrank under it
      // (e.g. an optimistic delete between anchor capture and extension).
      const row = items[i];
      if (row) next.add(row.id);
    }
    rangeEmittedRef.current = next;
    onSelectionChange(next);
  };

  const handleKeyDown = (event: KeyboardEvent<E>, index: number): void => {
    if (event.key === KeyboardKey.ARROW_DOWN) {
      event.preventDefault();
      const next = Math.min(index + 1, items.length - 1);
      if (event.shiftKey && onSelectionChange) {
        extendRange(index, next);
      } else {
        rangeRef.current = null;
      }
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    if (event.key === KeyboardKey.ARROW_UP) {
      event.preventDefault();
      const next = Math.max(index - 1, 0);
      if (event.shiftKey && onSelectionChange) {
        extendRange(index, next);
      } else {
        rangeRef.current = null;
      }
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    const item = items[index];
    if (!item) return;
    // Case-insensitive so CapsLock can't disable the peek; Shift stays reserved
    // for range selection.
    if (event.key.toLowerCase() === KeyboardKey.O && !event.shiftKey && onPeek) {
      event.preventDefault();
      rangeRef.current = null;
      onPeek(item.id);
      return;
    }
    if (event.key === KeyboardKey.SPACE && onToggleSelect) {
      event.preventDefault();
      rangeRef.current = null;
      onToggleSelect(item.id);
    }
  };

  return { focusedRow, setFocusedRow, rowRefs, handleKeyDown };
}
