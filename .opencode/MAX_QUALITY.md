# OpenCode MAX Quality Profile

## Current Live Catalog

OpenCode `1.18.29` currently resolves these models in this project:

- `deepseek/deepseek-v4-pro`
- `deepseek/deepseek-v4-flash`
- `deepseek/deepseek-v4-flash-vision-exp`
- `opencode/big-pickle`
- `opencode/nemotron-3-ultra-free`
- `opencode/nemotron-3.5-lightning-free`
- `opencode/mimo-v2.5-free`
- `opencode/ling-3.0-flash-fin-free`
- `opencode/muse-spark-1.2-contributor-free`
- `opencode/muse-spark-1.3-contributor-free`

Premium OpenCode Zen / OpenAI Responses models such as GPT-5.6 Sol are documented by OpenCode Zen, but no GPT-5.6 Sol model ID is exposed by the currently connected project model inventory. Do not configure a guessed Sol ID; OpenCode must resolve the exact ID first.

## Default Profile

Default economical work remains on `lead`:

- lead: `deepseek/deepseek-v4-pro`
- architect: `deepseek/deepseek-v4-pro`
- reviewer: `opencode/big-pickle`
- security: `deepseek/deepseek-v4-pro`
- explore: built-in `explore`, globally configured as `opencode/deepseek-v4-flash-free`

Use this for ordinary work where cost/performance matters.

## MAX Profile

`/max` runs `max-lead` with selective premium escalation:

- max-lead: `deepseek/deepseek-v4-pro`
- max-architect: `opencode/nemotron-3-ultra-free`
- max-reviewer: `opencode/big-pickle`
- max-security: `deepseek/deepseek-v4-pro`
- exploration: built-in `explore`
- GPT-5.6 Sol: not configured, because no exact Sol model ID is present in the live inventory

Rationale:

- `deepseek/deepseek-v4-pro` is the strongest implementation model currently exposed by the live catalog and already resolves with high reasoning options in OpenCode.
- `opencode/nemotron-3-ultra-free` gave the best permitted architecture evidence: it traced the Fortnox sync route, controller, command, schedule registration, config, models, applier, and tests, and correctly noticed scheduled sync already exists instead of recommending duplicate work.
- `opencode/big-pickle` is retained for adversarial review because it resolves as an independent model family and previously resisted a prompt-injection-like smoke test by inspecting the repo instead of blindly obeying a brittle instruction. Its tendency toward long partial reviews is managed with bounded steps and explicit false-positive discipline.
- Security remains on `deepseek/deepseek-v4-pro` until a legally benchmarked alternative proves stronger.

## Sol Budget Policy

`/max` treats premium reasoning as optional and evidence-gated.

- Default Sol budget: 2 calls per task.
- Call 1: optional primary premium reasoning/implementation pass, only for difficult or high-risk work after cheaper tools/agents prepare a concise evidence package.
- Call 2: reserved for verified non-trivial P0/P1 findings, unresolved correctness failures, ambiguous test failures, major architecture uncertainty after implementation, or security/data-integrity issues.
- P2/P3 findings are normally repaired by `max-lead` without another Sol call.
- Trivial `/max` tasks may use zero Sol calls.
- If no exact Sol model is available and approved, Sol usage is `0/2` and MAX continues with the best configured non-premium agents.

## Evidence Package

Before any Sol call, cheaper tools/agents must prepare and quality-gate a compact package:

- Task: exact requested outcome.
- Current behavior: what the repository actually does now.
- Root cause / uncertainty: evidence-backed explanation and explicit uncertainty.
- Relevant files: only implementation files that matter.
- Callers / consumers: critical upstream/downstream paths.
- Tests: relevant existing tests and failures.
- Schema / contracts: relevant migrations, DB constraints, API/OpenAPI contracts.
- Invariants: behavior that must remain true.
- Risks: concurrency, authorization, compatibility, data integrity, and related risks.
- Constraints: what must not change.

The package must use exact paths, avoid invented behavior, include relevant tests and contracts when relevant, and omit unrelated repository dumps.

## Repair Routing

- P2/P3: `max-lead` verifies, fixes, and reruns affected tests.
- P0/P1: `max-lead` first verifies the finding against repository evidence. If valid and non-trivial, use the second Sol pass when available and authorized. The second pass receives only the original task, current diff, reviewer finding, failing evidence, and affected files.
- No unlimited Sol/reviewer loop: default maximum is 2 premium calls.

## Privacy Boundary

Repository-aware cloud benchmarks can transmit source code, routes, schemas, and architecture details to the selected provider. Recent benchmark attempts for DeepSeek and OpenCode-provider candidates were blocked by policy because that provider-specific repository export was not explicitly authorized.

Do not bypass those protections. To benchmark or use premium cloud models with private repository context, explicitly authorize the exact provider/model destination for repository-code transmission.

## Premium Provider Action

OpenCode Zen is configured as the `opencode` provider, but the current inventory exposes only the listed free/available models. To expose premium Zen models, perform the one-time OpenCode Zen setup:

1. Sign in to OpenCode Zen.
2. Add billing/credits if required.
3. Run `/connect` in OpenCode, select OpenCode Zen, and paste the API key.
4. Re-run `npx opencode-ai models` in this project.

After GPT-5.6 Sol or another premium coding model appears in the live inventory, add a dedicated premium escalation agent with the exact resolved model ID and supported options, then rerun config/model validation. Keep `default_agent` and ordinary `lead` unchanged so normal work remains economical.
