---
description: Maximum-quality workflow with budgeted premium-model escalation
agent: max-lead
subtask: false
---

Handle this NordiPass task in MAX quality mode: $ARGUMENTS

MAX means maximum engineering quality, not mandatory premium-model spend. Preserve privacy/provider boundaries and safe permissions.

Classify the task before implementation:

FAST: trivial text, styling, obvious isolated mappings, simple low-risk fixes. Use max-lead directly, run focused verification when behavior changed, inspect the diff, and do not escalate to a premium model.

WORK: normal production development. Use max-lead to investigate, root-cause, implement, test, inspect the diff, and use max-reviewer when independent review is useful. Do not escalate to a premium model by default.

MAX: high-risk or ambiguous work where a stronger premium model such as GPT-5.6 Sol would materially improve correctness: concurrency, locking, tenant isolation, authorization, migrations, data integrity, Fortnox/external sync, destructive data behavior, complex debugging, public API compatibility, or broad cross-module refactoring.

Premium usage policy:

- Default premium budget is 2 calls per /max task.
- Call 1 is optional and requires a complete evidence package.
- Call 2 is reserved for a verified non-trivial P0/P1 finding, unresolved correctness failure, ambiguous test failure, major architecture uncertainty after implementation, or security/data-integrity issue.
- P2/P3 findings are normally fixed by max-lead without another premium call.
- Never enter a premium -> reviewer -> premium loop beyond the 2-call budget without explicit user request.
- If GPT-5.6 Sol or another premium target is not present in the live OpenCode model inventory, premium calls used are 0/2 and the workflow continues with the best configured non-premium agents.

Before any premium escalation, prepare and quality-check this concise evidence package using max-lead, explore, and read-only agents:

## Task
Exact requested outcome.

## Current behavior
What the repository actually does now.

## Root cause / uncertainty
Evidence-backed explanation and what remains uncertain.

## Relevant files
Only relevant implementation files.

## Callers / consumers
Critical upstream/downstream paths.

## Tests
Relevant existing tests and failures.

## Schema / contracts
Relevant migrations, DB constraints, API/OpenAPI contracts.

## Invariants
Critical behavior that must remain true.

## Risks
Concurrency, authorization, compatibility, data integrity, etc.

## Constraints
What must not change.

Evidence gate: the package must be grounded in actual files, identify exact paths, include relevant tests and DB/API contracts when relevant, identify uncertainty explicitly, and omit irrelevant repository dumps. If incomplete, investigate further with cheaper tools/agents before escalating.

Post-implementation: run focused regression tests, run relevant broader verification, inspect the final diff, run independent review for non-trivial changes, verify legitimate findings, route P2/P3 to max-lead, route valid non-trivial P0/P1 to the second premium pass only when available and authorized, then rerun affected tests.

Final response must include Changed, Root cause when applicable, Verification, remaining risks, and Premium usage with calls used out of 2 and the purpose of each premium pass. Do not expose private chain-of-thought.
