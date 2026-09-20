---
description: Independent read-only review of the current implementation or diff
agent: reviewer
---

Review the current NordiPass implementation or diff for: $ARGUMENTS

Do not modify files. Inspect actual repository evidence. Lead with actionable findings ordered by severity and include file/line references. Focus on correctness, regressions, missing tests, tenant isolation, authorization, validation, transaction/locking issues, concurrency, N+1 or unbounded work, API contract drift, sensitive data exposure, backwards compatibility, and misleading verification.

If there are no actionable findings, say so clearly and list only concrete residual test gaps.
