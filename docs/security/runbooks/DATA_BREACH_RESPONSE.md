# Runbook: Data Breach Response (GDPR)

Status: R3.16 deliverable. Applies to breaches of personal data (user accounts, e-mail addresses, membership records, invitation e-mails).

## Detection and Assessment

1. Record the incident (Incident Response runbook) with severity `critical`.
2. Determine scope: which tenants and which data categories were exposed. Use audit logs (`log_name = tenant` / `platform`), API token usage, and Fortnox/Stripe provider activity.
3. Assess GDPR notifiability: risk to rights and freedoms of data subjects. If in doubt, treat as notifiable.

## Containment (within 24h)

1. Revoke affected tokens and disconnect integrations (Token Rotation runbook).
2. Suspend affected users/companies.
3. Block the attack vector: rotate secrets, patch, or disable the affected feature flag.

## Notification

- **Supervisory authority**: notify within 72 hours of becoming aware, per GDPR Art. 33.
- **Data subjects**: notify without undue delay when the breach is likely to result in high risk (Art. 34). Communication must describe the nature of the breach, likely consequences, and measures taken.
- Prepare a facts summary: categories of data, number of subjects, contact point, and remediation timeline.

## Documentation

- Store the full timeline in `platform_incidents` (title, description, severity, resolved_at).
- Keep the breach record in the immutable platform audit log — do not delete.

## Post-Breach

1. Run the cross-tenant security suite (`php artisan test tests/Feature/Security`) to confirm isolation holds.
2. Review the permission matrix (`docs/architecture/R3_PERMISSION_MATRIX.md`) for the exploited path.
3. Update runbooks if the response revealed gaps.
