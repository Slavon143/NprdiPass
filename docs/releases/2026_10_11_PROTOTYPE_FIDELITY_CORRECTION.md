# Prototype fidelity correction — 11 October 2026

Status: corrected locally, uncommitted. No push or deployment. This supersedes the visual interpretation in `2026_10_11_PROTOTYPE_DESIGN_AND_PUBLIC_DEMO.md`.

## Cause of the mismatch

The first implementation treated the prototype as broad visual inspiration. It replaced the hero's interface card with a product photograph, omitted its floating QR and value strip, reduced the header and pricing composition, and replaced the demo's application sidebar with horizontal navigation. That produced a different layout despite using similar colours.

The reference was inspected again in English and Swedish across home, dashboard, product, readiness, import and passport. Rendered HTML structure and CSS were captured from the user-provided prototype, then ported into ordinary Blade templates. No Sites JavaScript, remote scripts, account flow or backend logic was copied.

## Corrected presentation

- Home now preserves the three-line heading, original Arial typography and line spacing, original page widths, interface card with cube icon and progress, floating QR, value strip, workflow cards, integration section and two example plan cards. The product photograph has been removed from this example.
- The demo uses the original sidebar, company/user sample cards, top bar, dashboard metrics, attention table with progress, next actions and sample history. A separate catalogue view lists all examples.
- Product and readiness use the original workspace header, metadata strip and tabs. Readiness has the score column on the left, requirement cards on the right and the publication bar below.
- Import is a modal over the workspace, with source selection and Source/Preview/Complete steps. It uses fixed sample rows only; native dialog focus and Escape handling do not call a provider.
- Passport uses the original paper layout, masthead, section navigation, two-column information, side preview panel and desktop/mobile preview controls. It has no added product photograph.
- The real dashboard adopts the same metric/table/next-actions layout with current-company data. It retains the fresh-readiness and permission condition for publication. Real navigation retains existing permission checks, company selection, search, notifications and sidebar collapse. Shared typography and public passport typography follow the reference.

Reference styling is scoped to `.np-prototype` in `resources/css/showcase-reference.css`. Icons are rendered SVG geometry from the provided reference. Assets, dependencies and services were not purchased or provisioned. The captured stylesheet contains no remote URLs or imports.

## Deliberate differences from the private prototype

The public example is labelled as a demo rather than claiming that an openly accessible route is private. Its floating QR is a genuine link to the fictional passport, rather than a decorative QR icon. English and Swedish are supported; no German option is advertised without an implemented translation.

The plan cards are explicitly prototype examples, not subscription offers; their buttons open a local explanation. They do not start checkout, activate trials or create subscriptions. CSV/Fortnox examples use fixed browser data and never initiate OAuth, file upload or an account connection.

The detailed interactive product flow is for the featured Storage Case. Other illustrative product rows and workspace administration controls provide a demo explanation, not a false live account operation. Registration/sign-in links remain distinct choices for a real workspace.

The real dashboard uses actual product, document, published-passport and draft-passport counts instead of copying fictional readiness totals or demo history. Existing company roles, publication transactions, provider guards, billing configuration, public snapshot data and connections were not changed.

## Verification

- Final broad focused PHP run: **148 tests / 661 assertions, passed**. Marketing, CompanyUi, Navigation, readiness web, public passport page/language, Fortnox connection/subscription/display, registration and authentication.
- Follow-up after final passport presentation changes: **41 tests / 189 assertions, passed** across Marketing and public passport page/language.
- JavaScript: **16 tests, passed**, including fixed sample imports, blocked publication and independent/reset demo state.
- PHPStan/Larastan: **zero errors**. Pint applied to affected PHP and translation files. Scoped whitespace checks passed.
- Vite production build passed with the existing local Node 22 runtime. No dependencies were installed.
- Browser acceptance exercised readiness correction/publication, language-preserving navigation, reload reset, native modal import, fixed sample preview/apply and duplicate prevention. CSP remained enforced, with no script console errors. Mobile 390px views use the reference responsive rules and do not horizontally overflow the document.

The public routes still execute no tenant queries and set no session cookies. POST `/demo` remains 405. Real Fortnox authorization/refresh and subscription actions were not executed; PHP tests use fake or HTTP-faked providers and `nordipass_testing`.

Local comparison evidence is in `storage/framework/prototype-fidelity-20261011/`, including `home-reference-en.jpg`, `home-local-en.jpg`, `passport-mobile-sv.jpg` and the final demo screenshots. These runtime files are excluded from Git. The prior screenshot showing the rejected photo-based hero is not evidence for this correction.

The existing staging release archive does not contain this change. A new candidate and staging acceptance are still required before production publication. The original prototype remains unchanged.
