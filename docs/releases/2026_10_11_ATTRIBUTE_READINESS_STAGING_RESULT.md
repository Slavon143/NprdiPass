# Attribute Readiness staging delivery — 11 October 2026

The attribute warning and empty editor correction is active at https://staging.nordipass.nu.codecrafters.nu/ as `staging-20261011-fe85adb955de`. Deployment completed at **12:53:43 Stockholm time (10:53:43 UTC)**. Staging writers resumed successfully. Canonical acceptance passed at 12:54:04 and real-view acceptance at 12:56:03 Stockholm time.

## Version and recovery

- User approved one local commit of the 12 files in `storage/framework/attribute-readiness-20261011/proposed-attribute-commit.json`, then staging without push.
- Commit `fe85adb955deee7cf18786063a9ecf302df0b99e`; parent `cb76517010bdae5ce7da7b292311f57d3f71e1fe`. Exact scope and normalized committed file hashes matched the reviewed list. No push or production deployment occurred.
- Verified executable: `nordipass-20261011-fe85adb955de.tar.gz`, 10,095,632 bytes, SHA256 `5114c7ddabbc81677f7c9e364cb41797419163f7d8a6f2ab304fe9b9fca099ec`. Source comes from committed blobs; vendor files come from the verified preceding archive. Dependency locks are unchanged. Frontend was rebuilt in isolation with Node 22.23.3; build PHP is 8.4.21 and server PHP is 8.4.26.
- The retained v10 tooling and unchanged Fortnox safety fingerprints passed. The preceding executable remains retained for recovery. No new rollback rehearsal was performed for this update.
- Fresh backup UUID `da9892e4-8e55-4b9c-83ed-6442087f1561`, pause generation `1791715896746856088`. Restore acceptance used only the previously approved disposable `codecraf_np_boundary_20261009`. Both staging backup files were downloaded into the approved `release-20261011/offhost-backups`, verified by size/SHA256 and returned to the same staging server. Actual returned bytes and the matching receipt passed the activation gate. Private backups are excluded from Git and this report.

## Acceptance

- Previously completed local verification: **102 tests / 681 assertions**, PHPStan zero errors, affected Pint and whitespace checks, Node 22 build. Coverage includes required-profile enforcement, historical evaluations, tenant isolation, query-count bounds and the reported empty editor.
- `/up`, `/ready`, home, login, registration and inspected demo endpoints returned 200. Delivered manifest, CSS and JS hashes match the isolated build; login references current assets. All five companies have valid initialized capacity counters, with no missing or drifting rows.
- Additional CLI acceptance rendered Product Workspace, Readiness and the attribute editor for an existing staging product and its existing owner. SQL remained in a read-only transaction, with an ephemeral session, no persistent login and no exported product HTML/data. The application and role caches were moved to in-memory stores only inside this short-lived probe, because initial probes were blocked by attempted shared SQL-cache warming. No server configuration or real permissions changed.
- Real-view checks confirmed actual product data and score rendering, no Rule details inside requirement cards, a closed Technical details container, correct handling of optional/required attribute checks and an editor matching the tenant's field configuration. Tests also verify that missing field definitions cannot bypass an explicitly required profile requirement.
- Stored historical readiness and published snapshots were not rewritten. Fresh evaluation can produce a higher score or fewer warnings because an inapplicable optional check no longer contributes to the denominator.
- Fortnox connection metadata and OAuth-state count match the fresh baseline. Production subscription 1853390, unknown and unrelated accounts remain blocked; the pure guard accepts test 1854146, while new authorization is disabled. No live billing key is configured. No provider request, OAuth, checkout or paid action was initiated by this delivery.

Safe JSON evidence was downloaded and verified as `storage/framework/attribute-readiness-20261011/safe-delivery-evidence.zip`, SHA256 `09a9990d4fb8fb4b1cc04878b0f1252f0758bd7abd1c983748984951820416f1`. It contains preflight, deployment, canonical acceptance and real-view acceptance results, without secrets or product content. This post-deployment report was created after the approved commit and remains uncommitted.
