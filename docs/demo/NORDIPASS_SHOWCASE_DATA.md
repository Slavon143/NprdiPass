# NordiPass Showcase Demo Data

## Quick Start

```bash
# Seed demo data
php artisan nordipass:demo:seed

# Reset demo data
php artisan nordipass:demo:seed --reset
```

## Safety

- Works only in `local` and `testing` environments
- Refuses to run in `production`
- Does not delete normal company data
- Idempotent — running twice does not create duplicates
- Not part of `DatabaseSeeder` or test bootstrap

## Demo Users

| Email | Role | Password |
|-------|------|----------|
| demo.owner@nordipass.test | Owner | Configurable via `NORDIPASS_DEMO_PASSWORD=` |
| demo.admin@nordipass.test | Admin | Same |
| demo.editor@nordipass.test | Editor | Same |
| demo.viewer@nordipass.test | Viewer | Same |

If no password is set, a random one is generated and displayed in console output.

## Demo Company

NordiPass Demo AB

## Demo Categories

```
Industrial Equipment
├── Lighting
├── Fire Safety
├── Power Tools
└── Personal Protective Equipment
    ├── Protective Clothing
    └── Protective Gloves
```

## Demo Products

| # | Product | Passport State | Demonstrates |
|---|---------|---------------|-------------|
| 1 | Industrial LED Work Lamp 40 W | Published V2 | Full DPP, all sections, V1→V2, QR active |
| 2 | Fire Extinguisher 6 kg | Published V1 | Ready with warnings, QR active |
| 3 | Reflective Safety Vest | Draft | Not ready, blockers, QR target inactive |
| 4 | ProGrip Protective Work Gloves | Unpublished | Withdrawn, QR target inactive |
| 5 | NordiTool Cordless Drill 18 V | Archived | Archived, QR target inactive |
| 6 | Industrial Storage Case | No Passport | Create Passport action |

## Verification Checklist

1. Log in as demo.owner@nordipass.test
2. Switch to NordiPass Demo AB
3. Open Products → verify all 6 scenarios
4. Open LED Work Lamp → View Version History → V1 and V2
5. Open QR → scan with phone → download SVG/PNG
6. Open Fire Extinguisher → verify warnings
7. Open Safety Vest → verify blockers
8. Open Work Gloves → verify unpublished
9. Open Cordless Drill → verify archived
10. Open Storage Case → verify Create Passport button
