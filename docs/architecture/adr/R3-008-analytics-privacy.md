# ADR-R3-008 — Analytics Privacy and Retention

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new capability)

---

## Context

R3.9 introduces QR scan analytics and public passport view tracking. Analytics must respect GDPR and ePrivacy requirements. No consent is required for anonymous aggregate analytics, but PII must not be collected.

## Decision

### Event Contract

```json
{
    "event_type": "qr_scan | passport_view",
    "company_id": "internal FK",
    "product_id": "internal FK",
    "passport_public_id": "public UUID",
    "published_version_id": "internal FK",
    "country_code": "XX (ISO 3166-1 alpha-2, from IP geolocation)",
    "device_category": "mobile | tablet | desktop | other",
    "referrer_category": "direct | search | social | qr | external | internal",
    "bot_flag": "boolean",
    "timestamp": "ISO 8601"
}
```

### What is NOT Stored

| Data Point | Reason |
|-----------|--------|
| Raw IP address | PII, not needed for analytics |
| Full User-Agent | Contains unnecessary fingerprinting data |
| Precise GPS location | Never collected |
| Full referrer URL | Unbounded data, privacy risk |
| Personal identifiers | Not collected for public visitors |
| Cookies | Not set on public passport pages |

### Privacy and Lawful Basis
- Analytics are anonymous aggregate only
- No user profiles built from public visitor data
- Lawful basis: legitimate interest (product analytics for passport owners)
- Opt-out: not applicable (no PII collected)
- Data Processor: NordiPass self-hosted (no third-party analytics)

### Aggregation Strategy
- Raw events stored for 90 days
- Daily aggregation into `analytics_daily` table
- After 90 days, raw events deleted, aggregates retained
- Country resolution: city → country (store only country, discard city)

### Duplicate Scan Handling
- Duplicate: same `passport_public_id` + same `country_code` + same `device_category` within 60 seconds
- Counted as one scan in aggregates
- Raw events may contain duplicates (deduplicated at aggregation time)

### Bot Filtering
- Known bot user agents filtered (based on maintained list)
- Unusually high scan rates from single country+device within short window flagged
- Bot traffic stored with `bot_flag = true`, excluded from aggregates

### Retention
| Data | Retention |
|------|-----------|
| Raw scan/view events | 90 days |
| Daily aggregates | 3 years |
| Monthly aggregates | Indefinite (for business reporting) |
| Bot-flagged events | 30 days |

### Performance
- Write path: async via queue (fire-and-forget from public passport controller)
- Read path: aggregated tables with indexes on company_id + date
- Public passport response time must not be affected by analytics ingestion

## Alternatives Considered

1. **Third-party analytics (Google Analytics)**: Rejected — violates GDPR without consent, data leaves EU, cannot integrate with internal product data.
2. **Full raw event retention**: Rejected — unnecessary storage cost, privacy risk, no business requirement for raw events beyond 90 days.

## Consequences

- New tables: `analytics_events`, `analytics_daily`, `analytics_monthly`
- Public passport controller dispatches `PublicPassportViewed` event (async)
- QR controller dispatches `QrScanned` event (async)
- No cookies, no consent banners, no PII on public pages
