"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import { CornerDownLeft, Search } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { filterDestinations, type GoToDestination } from "@/app/backoffice/_lib/goToDestinations";

interface GoToPaletteProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  destinations: readonly GoToDestination[];
  /** Called with the chosen page once the palette has started closing. */
  onSelect: (path: string) => void;
  /**
   * Where the focus goes when the palette closes on a choice. Left to the dialog it would return
   * to the trigger, which survives the navigation and strands the reader in the chrome.
   */
  focusAfterSelect: () => HTMLElement | null;
}

/**
 * What the polite status region says after each keystroke. The combobox announces the active
 * option, but nothing announces how many there are or that the list went empty — a screen-reader
 * user would otherwise hear silence after typing a term that matches nothing. Empty on an empty
 * query, so opening the palette says nothing beyond its own name.
 */
function resultSummary(query: string, count: number): string {
  if (query.trim() === "") return "";
  if (count === 0) return `No page matches “${query.trim()}”.`;
  return count === 1 ? "1 page found." : `${count} pages found.`;
}

function optionId(listId: string, path: string): string {
  return `${listId}-${path.replaceAll("/", "-")}`;
}

/**
 * "Go to" — the keyboard path to every page the sidebar offers, opened with Ctrl/Cmd+K.
 *
 * A combobox over a listbox (WAI-ARIA APG pattern): the focus never leaves the text field, the
 * active option is conveyed through `aria-activedescendant`, Up/Down move it (wrapping), Enter
 * opens it and Escape closes the dialog. Planned modules stay findable — a search for "invoices"
 * should say the module is coming rather than that it does not exist — but rank after live ones and
 * carry the same "Soon" marker the sidebar shows.
 */
export function GoToPalette({
  open,
  onOpenChange,
  destinations,
  onSelect,
  focusAfterSelect,
}: Readonly<GoToPaletteProps>) {
  const [query, setQuery] = useState("");
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const selectedRef = useRef(false);
  const listId = useId();

  const results = useMemo(() => filterDestinations(query, destinations), [query, destinations]);
  const active = results[Math.min(activeIndex, results.length - 1)];
  const activeId = active ? optionId(listId, active.path) : undefined;

  useEffect(() => {
    if (activeId === undefined) return;
    globalThis.document.getElementById(activeId)?.scrollIntoView?.({ block: "nearest" });
  }, [activeId]);

  const handleOpenChange = (next: boolean) => {
    if (next) {
      setQuery("");
      setActiveIndex(0);
      selectedRef.current = false;
    }
    onOpenChange(next);
  };

  const choose = (destination: GoToDestination) => {
    selectedRef.current = true;
    onOpenChange(false);
    onSelect(destination.path);
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (results.length === 0) return;
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActiveIndex((index) => (Math.min(index, results.length - 1) + 1) % results.length);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActiveIndex(
        (index) => (Math.min(index, results.length - 1) - 1 + results.length) % results.length,
      );
    } else if (event.key === "Enter" && active) {
      event.preventDefault();
      choose(active);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton={false}
        initialFocus={inputRef}
        finalFocus={() => (selectedRef.current ? (focusAfterSelect() ?? true) : true)}
        className="go-to-palette top-[12vh] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg"
        data-testid="go-to-palette"
      >
        <DialogTitle className="sr-only">Go to</DialogTitle>
        <DialogDescription className="sr-only">
          Type to filter the pages, use the arrow keys to choose one and press Enter to open it.
        </DialogDescription>
        <div className="go-to-palette__field border-border flex items-center gap-2 border-b px-3">
          <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
          <input
            ref={inputRef}
            type="text"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-activedescendant={activeId}
            aria-autocomplete="list"
            aria-label="Go to page"
            placeholder="Go to a page…"
            autoComplete="off"
            spellCheck={false}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setActiveIndex(0);
            }}
            onKeyDown={handleKeyDown}
            className="go-to-palette__input text-foreground placeholder:text-muted-foreground h-11 min-w-0 flex-1 bg-transparent text-sm outline-none"
            data-testid="go-to-palette__input"
          />
          <kbd className="go-to-palette__kbd border-border text-muted-foreground hidden rounded border px-1.5 font-mono text-2xs sm:inline">
            Esc
          </kbd>
        </div>

        <ul
          id={listId}
          role="listbox"
          aria-label="Pages"
          className="go-to-palette__list max-h-80 overflow-y-auto p-1"
          data-testid="go-to-palette__list"
        >
          {results.map((destination) => {
            const isActive = destination === active;
            return (
              // The option is reached through the combobox's arrow keys, never by Tab: the focus
              // stays in the text field (aria-activedescendant), so the click handler is the
              // pointer path only and needs no key handler of its own.
              // eslint-disable-next-line jsx-a11y/click-events-have-key-events
              <li
                key={destination.path}
                id={optionId(listId, destination.path)}
                role="option"
                aria-selected={isActive}
                onMouseMove={() => setActiveIndex(results.indexOf(destination))}
                onClick={() => choose(destination)}
                className={`go-to-palette__option flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 text-sm ${
                  isActive ? "bg-accent text-foreground" : "text-foreground"
                }`}
                data-testid={`go-to-palette__option-${destination.path}`}
              >
                <span className="go-to-palette__option-text flex min-w-0 flex-1 flex-col">
                  <span className="truncate font-medium">
                    {destination.name}
                    {destination.isPlanned ? <span className="sr-only">, coming soon</span> : null}
                  </span>
                  <span className="text-muted-foreground truncate text-xs">
                    {destination.section}
                  </span>
                </span>
                {destination.isPlanned ? (
                  <span
                    className="go-to-palette__soon border-border text-muted-foreground shrink-0 rounded border px-1 text-2xs leading-4 font-medium"
                    aria-hidden="true"
                  >
                    Soon
                  </span>
                ) : null}
                {isActive ? (
                  <CornerDownLeft
                    className="text-muted-foreground size-3.5 shrink-0"
                    aria-hidden="true"
                  />
                ) : null}
              </li>
            );
          })}
        </ul>
        <output
          aria-live="polite"
          aria-atomic="true"
          className="go-to-palette__status sr-only"
          data-testid="go-to-palette__status"
        >
          {resultSummary(query, results.length)}
        </output>
        {results.length === 0 ? (
          <p
            aria-hidden="true"
            className="go-to-palette__empty text-muted-foreground px-4 pb-6 pt-5 text-center text-sm"
            data-testid="go-to-palette__empty"
          >
            No page matches “{query.trim()}”.
          </p>
        ) : null}
        <p className="go-to-palette__hint border-border text-muted-foreground hidden border-t px-3 py-2 text-2xs sm:block">
          ↑ ↓ to move · Enter to open · Esc to close
        </p>
      </DialogContent>
    </Dialog>
  );
}
