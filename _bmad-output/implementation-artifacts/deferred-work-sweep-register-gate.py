#!/usr/bin/env python3
"""Register-integrity gate for the deferred-work sweep.

Answers one question mechanically: did this branch delete exactly the bullets it
claims, add none, and leave every surviving bullet's text untouched bar one?

It compares the working tree's `deferred-work.md` against the same file at the
branch's base commit, matching bullets by NORMALISED CONTENT rather than by an
ITEM number. That choice is the point: the ITEM numbers exist only in this
sweep's scratch index, so a check that greps for them would pass over a survivor
somebody silently rewrote. Identity here is the bullet's own text.

The base is `f86b2662`, the merge of PR #866. An earlier draft of this gate was
written against `c988198b` and carried a list of #866's 22 bullets to protect
byte-for-byte, plus a 73/51 split between "verifiable here" and "after #866
merges". #866 merged while this branch was still empty, so all of that is gone:
its bullets are no longer IN the base to protect, and the projected count is now
the measured one. What remains is a single number this branch verifies alone.

Usage:  python3 <this file> [--base <commit>] [--json]
Exit:   0 all invariants hold · 1 a violation · 2 could not run the comparison
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

REGISTER = "_bmad-output/implementation-artifacts/deferred-work.md"
BASE_COMMIT = "f86b2662"

EXPECTED_HEAD_COUNT = 53
EXPECTED_REMOVED = 45
EXPECTED_ADDED = 0

# The one survivor whose text this sweep is allowed to change: its "revisit
# trigger" already fired (four free-metadata consumers, not one), so leaving the
# wording untouched would preserve a statement the sweep measured to be false.
ALLOWED_MUTATED_SURVIVOR = "audit-metadata-keys"



def run(*args: str) -> str:
    result = subprocess.run(args, capture_output=True, text=True)
    if result.returncode != 0:
        raise RuntimeError(f"{' '.join(args)} -> exit {result.returncode}: {result.stderr.strip()}")
    return result.stdout


def bullets(text: str) -> list[str]:
    """Split the register into top-level bullets, keeping continuation lines.

    A bullet starts at a line beginning with '- ' in column 0 and absorbs every
    indented continuation and wrapped line until the next bullet or heading.
    """
    out: list[str] = []
    current: list[str] | None = None
    for line in text.splitlines():
        if line.startswith("- "):
            if current is not None:
                out.append("\n".join(current))
            current = [line]
        elif line.startswith("#"):
            if current is not None:
                out.append("\n".join(current))
                current = None
        elif current is not None:
            if line.strip() == "":
                out.append("\n".join(current))
                current = None
            else:
                current.append(line)
    if current is not None:
        out.append("\n".join(current))
    return out


def normalise(bullet: str) -> str:
    """Collapse whitespace so a re-wrap is not mistaken for a rewrite."""
    return re.sub(r"\s+", " ", bullet).strip()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default=BASE_COMMIT)
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    root = Path(run("git", "rev-parse", "--show-toplevel").strip())
    head_path = root / REGISTER
    if not head_path.is_file():
        print(f"FATAL: {REGISTER} not found under {root}", file=sys.stderr)
        return 2
    try:
        base_text = run("git", "show", f"{args.base}:{REGISTER}")
    except RuntimeError as error:
        print(f"FATAL: cannot read the register at {args.base}: {error}", file=sys.stderr)
        return 2

    base = [normalise(b) for b in bullets(base_text)]
    head = [normalise(b) for b in bullets(head_path.read_text(encoding="utf-8"))]
    base_set, head_set = set(base), set(head)

    removed = [b for b in base if b not in head_set]
    added = [b for b in head if b not in base_set]
    survivors = [b for b in base if b in head_set]

    failures: list[str] = []

    # An "added" bullet is either a genuinely new deferral (forbidden outright)
    # or the rewritten half of a survivor somebody edited. Both are refused; the
    # allowed ITEM 41 edit is the single carve-out, matched on its own subject.
    unexplained_added = [b for b in added if ALLOWED_MUTATED_SURVIVOR not in b]
    allowed_edits = [b for b in added if ALLOWED_MUTATED_SURVIVOR in b]

    # An edited survivor shows up on BOTH sides — its old text is absent from
    # head, its new text absent from base — so a raw `removed` count would read
    # 46 for 45 deletions plus the one sanctioned edit, and the gate would stay
    # red over correct work. Deletions are `removed` minus the edits' old halves.
    deleted = len(removed) - len(allowed_edits)

    if len(head) != EXPECTED_HEAD_COUNT:
        failures.append(f"head holds {len(head)} bullets, expected {EXPECTED_HEAD_COUNT}")
    if deleted != EXPECTED_REMOVED:
        failures.append(f"{deleted} bullets deleted, expected {EXPECTED_REMOVED}")
    if unexplained_added:
        failures.append(
            f"{len(unexplained_added)} bullet(s) added or rewritten beyond the one allowed edit "
            f"(expected {EXPECTED_ADDED} new): " + " | ".join(b[:110] for b in unexplained_added)
        )

    if len(allowed_edits) > 1:
        failures.append(f"the allowed survivor edit appears {len(allowed_edits)} times, expected at most 1")

    report = {
        "base_commit": args.base,
        "base_count": len(base),
        "head_count": len(head),
        "deleted": deleted,
        "removed_raw": len(removed),
        "added": len(added),
        "survivors": len(survivors),
        "allowed_survivor_edits": len(allowed_edits),
        "expected_head_count": EXPECTED_HEAD_COUNT,
        "failures": failures,
    }

    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        print(f"register        : {REGISTER}")
        print(f"base            : {args.base} -> {len(base)} bullets")
        print(f"head            : {len(head)} bullets (expected {EXPECTED_HEAD_COUNT})")
        print(f"deleted         : {deleted} (expected {EXPECTED_REMOVED})")
        print(f"added/rewritten : {len(added)} ({len(allowed_edits)} allowed, {len(unexplained_added)} not)")
        print()
        if failures:
            for failure in failures:
                print(f"  FAIL  {failure}")
        else:
            print("  OK    every register invariant holds")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
