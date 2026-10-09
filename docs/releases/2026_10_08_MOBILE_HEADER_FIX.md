# Mobile Dashboard header — 2026-10-08

The user reports horizontal scrolling into blank space on the authenticated Dashboard. Source inspection identifies an unbounded header budget: both the single-company badge (up to 224px) and the multi-company switch (up to 256px) remain visible on phones alongside the logo/navigation and fixed user controls. Their combined width can exceed a narrow viewport. This is a confirmed layout constraint in the source; live scroll-width reproduction was unavailable.

The company badge/switch is now shown in the header only at the desktop sidebar breakpoint (1024px). Mobile company-switch forms remain in the existing drawer. An explicit current-company label was added to its footer, including for users with one company. Long unbroken user names now wrap in the drawer/profile menu. No global overflow clipping was added, and tenant authorization/company switching behavior is unchanged.

Local verification: two navigation tests pass with 68 assertions, including long company/user names and preservation of current tenant identity, POST company-switch destination and CSRF protection. All 12 JS tests, scoped Pint, production build and scoped whitespace checks pass. These tests do not measure browser layout. Initial sandbox PHP could not open the test bootstrap; the authorized retry ran on the test schema. The new test initially expected a trailing-space company name that DisplayText trims; its fixture was corrected and the final run passed. Existing duplicate-extension PHP warnings are unrelated.

All CSS/JS asset names/hashes still match the control build recorded in CSS dependency acceptance. The change therefore needs only the two Blade templates and compiled-view cache refresh on staging.

The concrete package/hashes and local result are retained in `storage/framework/acceptance-mobile-header-20261008/package.json` and `result.json`.

**Target installation and real viewport acceptance remain blocked.** Computer-use inventory returned no available browsers, and opening the in-app browser failed. The previously configured, host-key-pinned SSH connection failed public-key authentication; no stored staging-named key or active Pageant was found in the checked locations. No server file/configuration was changed, new access granted, subscription purchased or deployment falsely certified. Restore existing target access before installation, then inspect document/client width and header/menu usability at phone widths, with one/multiple companies and long names.
