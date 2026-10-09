# Commercial capacity and cost worksheet

This worksheet uses the chosen NordiPass prices, not competitor prices or unverified hosting estimates. Prices exclude VAT. Annual revenue is spread over twelve months for the operational budget; operational allowances still reset monthly.

| Plan | Monthly revenue | Annual revenue / 12 | Managed models | Retained storage | Accepted imports / month | API requests / month |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Starter | EUR 49 | EUR 40.83 | 100 | 2 GB | 10 | None |
| Growth | EUR 149 | EUR 124.17 | 1000 | 20 GB | 100 | 50000 |
| Business | EUR 399 | EUR 332.50 | 10000 | 100 GB | 500 | 500000 |

An illustrative 80% contribution-margin target leaves monthly direct-cost budgets of EUR 9.80 / 29.80 / 79.80, or EUR 8.17 / 24.83 / 66.50 for annual purchases. These are budget ceilings derived from that assumption, not measured margins or a recommendation that 80% is achievable.

Measure direct cost as:

`payment fees + database/storage allocation + retained bytes + egress + worker CPU/time + external processing calls + attributable support time`.

Shared infrastructure allocation must include idle baseline capacity and backup/restore capacity. Public passports and QR reads do not consume the authenticated API allowance, but their egress, document downloads and abuse protection still cost resources. Immutable publication assets, audit history and retained import/export sources continue to consume storage after archival. Storage deduplication must use physical keys, not references.

An import allowance alone does not bound worker cost: record count, source size, retry behavior, mapped attributes, document processing and readiness invalidation matter. Track queue lag and per-job CPU/query counts alongside monthly accepted source jobs. Do not bill retries or continuation chunks as additional imports.

AI extraction, supplier portals, SSO/SAML, multiple Fortnox connections and custom domains are not priced as included implemented features. AI requires measured page/token processing cost and a separate allowance before launch. Priority support and SLA require staffing and target-environment measurements; a product limit is not an uptime or latency guarantee.

Available evidence covers local synthetic tests. The XLSX reader's 100000-row memory/time result is a reader result, not full import-pipeline throughput. Repeat the mapped 10000/100000-product pipeline benchmark after commercial admission is installed, without competing test workloads. Benchmark readiness and retained documents separately. No verified supplier-file corpus or staging runtime is available yet.

Actual supplier invoices, hosting rates, payment-method mix, support workload and public traffic distribution have not been supplied. Consequently the commercial margin and production cost per tenant remain unmeasured. Record those inputs before treating this worksheet as an approved profitability forecast.
