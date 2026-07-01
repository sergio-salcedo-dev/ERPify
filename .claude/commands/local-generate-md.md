---
description: Generate a self-contained .md prompt (in tmp/bmad-md/) that hands a BMAD decision to an external AI (ChatGPT/Qwen/DeepSeek/Gemini/…) for a principled recommendation, then brings its answer back so the BMAD agent debates and hardens it. Grounded in Clean Architecture, Clean Code, SOLID, Tell-Don't-Ask, Law of Demeter, DRY/KISS/YAGNI.
---

Use this when a BMAD agent has asked you to make a decision (architecture, design, trade-off)
and you want a principled second opinion from an external general-purpose AI **before**
committing. This command packages the decision into a portable prompt you paste into any
external AI, then hands that AI's answer back into this session so the BMAD agent **critiques
and debates it** to converge on a better decision.

The round trip:

1. `/local-generate-md [optional: the decision to consult]` → writes a prompt file to `tmp/bmad-md/`.
2. You copy **PART 1** into ChatGPT / Qwen / DeepSeek / Gemini / … and copy back its answer.
3. You paste that answer back here using the wrapper in **PART 2** → the BMAD agent critiques it
   against the same rubric and the repo's real constraints, then proposes the final decision.

## Fixed conventions

- Output language: **English** (matches `.claude/commands/` and this repo's other artifacts).
- Output location: **`tmp/bmad-md/`** at the repo root (git-ignored temp dir — never `/tmp`).
- The external AI has **zero access to this repo**, so PART 1 must be fully self-contained.

## Procedure

### 1. Identify the decision

- If `$ARGUMENTS` is non-empty, treat it as the decision focus.
- Otherwise, infer the open decision from the current conversation — the exact question the BMAD
  agent put to the user, plus the options already on the table and their surfaced pros/cons.
- If the decision is genuinely ambiguous, ask **one** clarifying question before generating. Do not
  guess a decision the user never raised.

### 2. Gather portable context

Collect only what is needed to decide, and inline it (the external AI can't read the repo):

- The decision statement (one sentence) and the concrete options being weighed.
- The minimum relevant context: stack facts, the actual code/design snippet under debate, and hard
  constraints (e.g. production-safety, existing patterns to preserve). **Cite real file paths.**
- The engineering rubric (below). Keep it tight — no dumping the whole codebase.

### 3. Write the prompt file

```bash
mkdir -p "$(git rev-parse --show-toplevel)/tmp/bmad-md"
date +%Y%m%d-%H%M%S   # use this as <timestamp>
```

Create `tmp/bmad-md/consult-<slug>-<timestamp>.md` (`<slug>` = short kebab-case of the decision).
The file has **two clearly separated parts**:

**PART 1 — copy this into the external AI.** A self-contained prompt that:

- Opens with a role line: *"You are a senior software architect. Give a principled recommendation
  on the decision below."*
- States the decision, the options, and the inlined context/constraints.
- Gives the rubric and asks the AI to justify against each principle **that applies** (and to say
  which don't and why): **Clean Architecture, Clean Code, SOLID (especially SRP and DIP),
  Tell-Don't-Ask, Law of Demeter, DRY, KISS, YAGNI.**
- Requires this output format so the answer is debatable, not authoritative:
  1. Recommended option (one line).
  2. Reasoning per relevant principle.
  3. Trade-offs and risks.
  4. *"Where this could be wrong / what I'd need to verify"* — an explicit self-doubt section.
- Asks for concise, citable reasoning — no filler.

**PART 2 — bring the answer back.** A ready-to-paste wrapper for the user, verbatim:

```
I consulted an external AI about: <decision>. Its answer:
<<<
[paste the external AI's answer here]
>>>
Critique and debate this against Clean Architecture, SOLID, Tell-Don't-Ask, Law of Demeter,
DRY/KISS/YAGNI and this repo's real constraints. Say where it's right, where it's wrong, and
propose the final decision with your reasoning.
```

### 4. Report

Print the generated file path and a one-line summary of the decision captured. Do **not** invoke
any external AI yourself — this command only produces the `.md`.
