# R3.4.1 Release Candidate Hardening

**Status:** CODE COMPLETE / ENVIRONMENT PROMOTION PENDING  
**Date:** 2026-08-20

## Outcome

R3.4.1 closes the readiness-editor audit and hardens the dependency and deployment gates required before R3.5 starts. It does not implement R3.5 Taxonomy Governance.

## Included

- Readiness profile v2 with explicit applicability for empty repeaters and Yes/No declaration gates.
- Canonical default-language and structured-field evaluation across editor and readiness rules.
- Actionable rule messages and exact editor-field navigation for materials, recycling, environmental, repair, support, warranty, manufacturer, and safety results.
- Help text and accessible labels for ambiguous Passport fields.
- Draft-only migration from `nordipass-pilot` v1 to v2; published v1 snapshots remain unchanged.
- Composer security updates for Guzzle and CommonMark plus transitive packages.
- Frontend tooling updates for PostCSS and Puppeteer.
- Deployment workflow pins artifact download to the selected release run and now fails when `/up` or `/ready` health checks fail.
- Browser acceptance can reuse an installed system Chrome/Edge or an explicit `PUPPETEER_EXECUTABLE_PATH`.

## Release evidence

| Gate | Result |
| --- | --- |
| `php artisan migrate:fresh --env=testing --force` | Passed; 39 migrations |
| `php artisan test --compact` | Passed; 2,156 tests, 2,155 passed, 1 skipped, 8,685 assertions |
| `vendor\\bin\\pint --test` | Passed |
| `vendor\\bin\\phpstan analyse` | Passed, 0 errors |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed |
| `composer audit --locked --format=json` | Passed; 0 advisories |
| `npm audit --json` | Passed; 0 vulnerabilities |
| `npm run build` | Passed |
| `npm run acceptance:r3_4` | Passed; 18 pages, 11 workflows, accessibility and security smoke |
| deployment shell syntax | Passed with Git Bash |

Browser evidence is stored in `docs/testing/evidence/r3_4_browser_acceptance.json` and its screenshot directory. The detailed readiness audit is `docs/testing/R3_4_1_READINESS_EDITOR_AUDIT.md`.

## Promotion conditions

The code baseline is ready to hand off to R3.5. Production release is not yet declared because the following environment-owned gates cannot be proven by the repository alone:

- run CI on the final reviewed commit;
- deploy the selected artifact to staging and verify `/up`, `/ready`, queue, mail, storage, and public Passport behavior;
- create a production-like database backup and complete a restore drill;
- review and commit the combined worktree, then create the release tag through the normal approval process.

No production deployment, tag, or remote push was performed by this audit.
