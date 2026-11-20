# Agents never self-block

The job of an agent is that the feature **works, at the highest quality, in the least time, at the lowest cost and with the best performance**. A missing paper, contract, dataset or pending human action is not a reason to stop the code. Declaring "blocked" over something that can be simulated is a failure of the objective, and a parked subagent costs more than any mock.

## What is a real block

Only these wait for a person:

- a write to production or to a live third-party system (deploys, `restore.prod`, production data);
- real money or charges to customers;
- anything irreversible (a destructive migration, rewritten history, a force-push, a merge into `main` — which needs explicit per-PR permission, see root `CLAUDE.md` → *Protected `main`*);
- legal or contractual text published to real users;
- a decision only the owner can take: the persistence strategy of a new aggregate and aggregate boundaries (root `CLAUDE.md` → *Architecture*), or any product/design fork.

Everything else keeps being built.

## When something external is missing

1. **Contract + fake adapter.** Define the port in `Domain/` or `Application/` and implement it against a fake in `Infrastructure/` (the Documents scanner's fake `clamd` binding is the worked example).
2. **Test data.** Missing customers, orders, documents: create fixtures and Mothers and test the cases. "Not loaded in this environment" is not a block.
3. **Provisional values behind a flag**, outside production.
4. **Hard limit:** nothing provisional is reachable by a production user (the `documents.ingest_enabled` gate is the model: production opens only with a valid record), and what the process reserves for a person still waits for them.
5. **The mock is for building, not for deciding.** If the mock's shape is a real design fork (the contract of an API that does not exist yet, a data model with two reasonable readings), ask **when it appears**; meanwhile continue with the reversible option and mark the chosen shape provisional.
6. **A contract, permit or signature governs the step to production, never the code.**
7. **Before writing "blocked"** in a report, story or handoff, answer in writing: *which part can I build now with a mock?* If the answer is not "none", only that part is blocked.

## Commits and PRs

- Small, frequent commits; push as soon as there are commits. No need to ask.
- A PR opens when there is a reviewable unit, as root `CLAUDE.md` → *Pull requests: as few as the work needs* already requires (every PR is a full CI run). One PR per intent; a finding is a commit on the open PR. This repo opens PRs ready, never `--draft`.
- Do not push piecemeal onto a PR that is already ready if each push restarts a costly CI run.

## Review stays proportional to risk — and here that means three layers

The playbook this rule derives from lets an agent use fewer review layers with a written reason. **ERPify does not adopt that relaxation:** root `CLAUDE.md` → *Code review* already makes the three layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor) owed per story and per change touching security, GDPR or audit surface, and a lower-ranking document must not loosen a higher-ranking rule. Added here:

- **Round cap: three per PR.** If a fourth round would still find things, the implementation process failed, not the review: stop and tell the user instead of opening another round.
- Critical zone (three layers, always): money, user data, authentication and permissions, migrations, secrets, deployed functions, production flags, CI/deploy pipelines.
