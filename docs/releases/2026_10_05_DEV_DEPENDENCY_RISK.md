# Development dependency risk assessment — 2026-10-05

Status: residual build/development risk **accepted by the user for this release on 2026-10-05**. The explicit response was: «Принять описанный dev-риск для этого релиза». This closes the risk-decision gate for the current release; it does not remove the vulnerability, suppress audit findings, authorize deployment or accept this risk indefinitely.

## Verified current state

The [reviewed braces advisory GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) lists affected versions through 3.0.3 and no patched version as checked on 2026-10-05. Deeply nested patterns can exhaust the JavaScript call stack.

`npm ls braces micromatch --all` resolves:

- Tailwind CSS 3.4.19 → chokidar 3.6.0 → braces 3.0.3.
- Tailwind CSS → micromatch 4.0.8 → braces 3.0.3, also consumed through fast-glob 3.3.3.

Current full npm audit reports five high package findings attributable to this advisory chain. Its suggested removal path is a major Tailwind upgrade to 4.3.3, not a patched braces release. That is not applied as an automatic audit fix: compatibility of CSS generation, forms and scanning needs its own migration and visual acceptance. This assessment does not claim that all future versions of that replacement chain are safe.

Composer audit reports zero advisories/abandoned dependencies. `npm audit --omit=dev` reports zero vulnerabilities. These are separate scopes; a clean production audit does not erase the full audit findings.

## Actual repository exposure

`package.json` declares the frontend toolchain in devDependencies. `tailwind.config.js` supplies fixed repository-owned content globs for framework pagination views, compiled Blade views and source Blade views. It does not accept a tenant-provided glob or pattern through HTTP/import/document endpoints. Build and development tooling still contain the vulnerable dependency.

`deploy/scripts/release_artifact.py` allowlists application directories, PHP vendor, compiled public assets and manifest/lock files. Top-level node_modules is outside its executable archive allowlist. This is code inspection of packaging policy, not proof that an unidentified target server has no Node tooling installed. Compiled Blade content may contain tenant text; scanning file content is not evidence that that text becomes an untrusted braces glob argument. No remote production exploitation path is confirmed by this audit.

## Reviewable decision

The current options are:

1. Migrate the frontend to a compatible dependency chain without braces, with CSS/visual and build acceptance before release.
2. Explicitly accept the bounded build/development risk for this release, retaining controlled repository patterns, isolated/time-bounded CI, and the existing Node-free application archive policy; revisit when a verified patch or accepted migration becomes available.

The user selected option 2 for the current release. Option 1 remains a post-release remediation task, with reassessment of verified upstream patches as an alternative. Full audit findings remain recorded. Vendoring an ad hoc parser patch or hiding findings through audit configuration would create separate maintenance obligations and would not constitute upstream advisory closure. No `npm audit fix --force`, advisory suppression, artificial patched version or unverified override was applied.

## Evidence

The existing local commercial acceptance artifact directory contains:

- `release-20261005-npm-audit.json`: initial sandbox network/cache failure, not audit evidence.
- `release-20261005-npm-audit-final.json`: completed full audit.
- `release-20261005-npm-production-audit.json`: completed production-scope audit.
- `release-20261005-composer-audit.json`: completed Composer audit.
- `release-20261005-quality.log`: independent current quality run; completion is tracked in the current release plan.
