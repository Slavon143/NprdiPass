# CSS dependency remediation — 2026-10-08

Local remediation of the two newly reported moderate npm findings is complete. No paid service, subscription, provider-account operation, staging installation, commit or push was performed.

## Change and reason

The [reviewed advisory GHSA-rj75-hqrm-r3gf](https://github.com/advisories/GHSA-rj75-hqrm-r3gf) affects postcss-selector-parser versions below 7.1.6. Its flat-selector parsing can have quadratic CPU cost. The installed 6.1.4 parser was consumed by Tailwind CSS 3.4.19 and postcss-nested 6.2.0; the latter's moderate finding was inherited from that parser.

An exact npm override selects the published upstream postcss-selector-parser 7.1.6 for both consumers. The generated lock changes only that package entry (version, registry URL, published integrity and license metadata). Tailwind remains 3.4.19 and postcss-nested remains 6.2.0. This is a deliberate major-parser override outside the consumers' declared 6.x ranges, not an automatic Tailwind 4 migration; compatibility was checked below. The [upstream release](https://github.com/postcss/postcss-selector-parser/releases/tag/7.1.6) explicitly fixes this advisory. The dependency remains pinned and needs reassessment when its consumers adopt a supported corrected range.

## Verification

- Control production build with the original parser passed before dependency replacement.
- Four new CSS integration regressions passed on both original and replacement parsers: responsive/escaped widths; group, peer and arbitrary descendant selectors; form reset/focus styles; complete nested comma-selector expansion.
- Clean `npm ci --ignore-scripts --no-fund --no-audit` passed against the final manifest/lock. No dependency install scripts were executed.
- All `tests/Js/*.test.mjs` passed: 12 tests, zero failures/skips. CI now runs this set before its production build.
- Final `npm run build` passed. Every final asset name and SHA-256 matches the control build, including CSS and JavaScript. The installed application bytes are unchanged; this does not certify unused future selector patterns.
- Full npm audit: zero critical, zero moderate, five high findings (braces/chokidar/fast-glob/micromatch/tailwindcss). Its exit 1 is retained because the accepted braces chain remains visible.
- Production npm audit: zero vulnerabilities, exit 0.
- `npm ls postcss-selector-parser --all` resolves both consumers to overridden 7.1.6. Scoped `git diff --check` passes.

Local checks used Node 24.15.0; CI selects the repository's Node version through `.nvmrc`. This pass does not claim a separate observed Node 22 execution or a new full PHP quality run. No PHP application source or schema was changed in this pass. Earlier PHP quality results remain dated evidence, particularly after the later staging/support changes.

## Failed attempts retained

Initial sandbox npm registry lookup failed with ENOTFOUND. The waiting install was stopped before retrying with approved network access. That retry installed the parser but failed to write the source lock with a Windows UNKNOWN/open error. npm generated the lock in an isolated local directory; only its verified package-entry change was transferred through the file editing tool. Subsequent clean npm ci passed. Neither failed attempt is counted as successful installation evidence.

## Evidence and remaining work

Evidence is under `storage/framework/acceptance-css-security-20261008/`: baseline/patched build logs, baseline/final JS logs, install/ci logs, both final audit JSON files and baseline asset hashes. These are local artifacts; no invoice, credential, account token or production payload was included.

The previous braces acceptance covers only that documented chain. No new moderate-risk acceptance was inferred. Current target staging dependency metadata was not updated by this pass; the unchanged compiled assets do not require a visual redesign. Final source review, archive identity, target deployment/rollback and any required final comprehensive gate remain open in [the current release plan](2026_10_05_RELEASE_PLAN.md). Hosting's supported persistent-worker/Redis decision and authenticated supplier/profile acceptance remain independent gates. Browser purchase flows must remain deferred under the user's prohibition on subscriptions and purchases.
